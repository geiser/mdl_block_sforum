<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Scripting-forum block caps.
 *
 * @package    block_sforum
 * @copyright  Geiser Chalco <geiser@usp.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/sforum/lib.php');

class block_sforum extends block_base {

    function init() {
        global $DB, $USER;
        $this->title = get_string('blocktitle_default', 'block_sforum');
        // start some importan fields
        if (user_has_role_assignment($USER->id, 5)) {
            $d = optional_param('d', false, PARAM_INT);
            if (!$d) {
                $postid = optional_param('reply', false, PARAM_INT);
                $d = $DB->get_field('sforum_posts', 'discussion', array('id'=>$postid));
            }
            if ($d) {
                $f = $DB->get_field('sforum_discussions', 'forum', array('id'=>$d));
                $this->discussion = $DB->get_record('sforum_discussions', array('id'=>$d));

                $transition_ids = $DB->get_fieldset_select('sforum_transitions',
                    'id', 'forum = :forum', array('forum'=>$f));

                $this->transitions = next_transitions_as_steps($transition_ids);
                $this->members = groups_get_members($this->discussion->groupid);
                $this->clroles = $DB->get_records_sql_menu('SELECT g.id, g.name
FROM {groups} g
INNER JOIN {groupings_groups} r ON r.groupid = g.id
INNER JOIN {sforum_clroles} c ON c.grouping = r.groupingid 
WHERE c.forum = :forum', array('forum'=>$f));
                $this->clroleid = $DB->get_field_sql('SELECT m.groupid
FROM {groups_members} m
WHERE m.groupid IN ('.implode(',',array_keys($this->clroles)).')
AND m.userid = :userid', array('userid'=>$USER->id));
            }
        }
    }

    function print_step($member, $transition, $sid) {
        global $DB, $USER;
        $lbl = html_writer::tag('i', $transition->to[$sid]->label);

        if ($member->id == $USER->id) {
            $postid = $this->discussion->firstpost;
            if ($transition->from) {

                $postid = $DB->get_field_sql('SELECT h.post
FROM {sforum_performed_transitions} h
INNER JOIN {sforum_posts} p ON p.id = h.post 
WHERE p.discussion = :discussion AND h.toid = :toid
ORDER BY h.id DESC', array('discussion'=>$this->discussion->id,
                           'toid'=>$transition->from->id), IGNORE_MULTIPLE);
            }

            $url = '/mod/sforum/post.php';
            
            $lbl = html_writer::link(new moodle_url($url,
                array('reply'=>$postid, 'transition'=>$transition->id, 'to'=>$sid)), $lbl);
        }
        return $lbl;
    }

    function print_transitions($member_or_id, $transitions, $clroleid, $is_selected=false) {
        global $DB;
        $member = (is_object($member_or_id) ? $member_or_id : $DB->get_record('user', array('id'=>$member_or_id)));
        $this->content->text .= html_writer::tag('button', $member->firstname.' '.$member->lastname,
            array('class'=>'accordion', 'group'=>'content-steps-for-'.$member->id));
        
        $printed_steps = array();
        $ids = get_enabled_transition_ids($this->discussion->id, $member->id);
        $enabled_transitions = next_transitions_as_steps($ids);

        $attrs = array('class'=>'panel', 'id'=>'content-steps-for-'.$member->id);
        if ($is_selected) $attrs['style'] = 'display: block;';
        $this->content->text .= html_writer::start_tag('div', $attrs);
        
        // print first steps
        $this->content->text .= html_writer::start_tag('ul', array('role'=>'group'));
        foreach ($enabled_transitions as $tid=>$transition) {
            foreach ($transition->to as $sid=>$step) {
                if (in_array($sid, $printed_steps)) continue;
                $this->content->text .= html_writer::start_tag('li', array('role'=>'treeitem'));
                $this->content->text .= $this->print_step($member, $transition, $sid);
                $this->content->text .= html_writer::end_tag('li');
                $printed_steps[] = $sid;
            }
            unset($transitions[$tid]);
        }

        // print rest steps
        foreach ($transitions as $tid=>$transition) {
            if ($transition->forid != $clroleid) continue;
            foreach ($transition->to as $sid=>$step) {
                if (in_array($sid, $printed_steps)) continue;
                $this->content->text .= html_writer::start_tag('li', array('role'=>'treeitem'));
                $this->content->text .= $step->label;
                $this->content->text .= html_writer::end_tag('li');
                $printed_steps[] = $sid;
            }
        }
        $this->content->text .= html_writer::end_tag('ul');
        
        $this->content->text .= html_writer::end_tag('div');
    }

    /**
     * Print navigation showing firt the current user and current role
     */
    function print_transitions_as_navigation($members, $clroles) {
        global $DB, $USER;

        $html_tabs = array();

        // print the first clrole and first member
        $member = $members[$USER->id];
        $clrole_lbl =  $clroles[$this->clroleid];
        $clrole_lbl = substr($clrole_lbl, 0, strpos($clrole_lbl, ' '));
        $html_tabs[] = html_writer::tag('div', '&nbsp;'.$clrole_lbl.'&nbsp;',
            array('class'=>'tablinks active', 'group'=>'tabcontent-'.$this->clroleid));

        $this->content->text .= html_writer::start_tag('div',
            array('id'=>'tabcontent-'.$this->clroleid, 'class'=>'tabcontent', 'style'=>'display:block;'));
        $this->print_transitions($member, $this->transitions, $this->clroleid, true);

        unset($clroles[$this->clroleid]);
        unset($members[$USER->id]);

        foreach ($members as $userid=>$member) {
            if (!$DB->record_exists('groups_members',
                array('userid'=>$userid, 'groupid'=>$this->clroleid))) continue;
            $this->print_transitions($member, $this->transitions, $this->clroleid);
        }
        $this->content->text .= html_writer::end_tag('div');

        $html_tabs[] = '|';
        // print the rest of clroles and members
        foreach ($clroles as $clroleid=>$clrole_lbl) {
            $clrole_lbl = substr($clrole_lbl, 0, strpos($clrole_lbl, ' '));
            $html_tabs[] = html_writer::tag('div', '&nbsp;'.$clrole_lbl.'&nbsp;',
                array('class'=>'tablinks', 'group'=>'tabcontent-'.$clroleid));
            $this->content->text .= html_writer::start_tag('div',
                array('id'=>'tabcontent-'.$clroleid, 'class'=>'tabcontent'));
            foreach ($members as $userid=>$member) {
                if (!$DB->record_exists('groups_members',
                    array('userid'=>$userid, 'groupid'=>$clroleid))) continue;
                $this->print_transitions($member, $this->transitions, $clroleid);
            }
            $this->content->text .= html_writer::end_tag('div');
        }

        // print tabs and content
        $html_tabs = html_writer::alist($html_tabs, array('class'=>'tab'));
        $this->content->text = $html_tabs.$this->content->text;
    }

	public function specialization() {
        $this->title = get_string('blocktitle_default', 'block_sforum');
        if(isset($this->config) && !empty($this->config->title)) {
            $this->title = $this->config->title;
		}
	}
	
    function get_content() {
        global $CFG, $DB, $OUTPUT, $USER, $PAGE;

        if ($this->content !== null) {
            return $this->content;
        }

        if (empty($this->instance)) {
            $this->content = '';
            return $this->content;
        }

        $this->content = new stdClass();

        // fill content in the block
        if (!empty($this->transitions) && 
            has_capability('mod/sforum:viewdiscussion', $this->page->context)) {
            $this->content->text = '';    
            $this->print_transitions_as_navigation($this->members, $this->clroles);
          
            $PAGE->requires->js_call_amd('block_sforum/navblock', 'init', array('id'=>$this->instance->id));
        }

        return $this->content;
    }

    // my moodle can only have SITEID and it's redundant here, so take it away
    public function applicable_formats() {
        return array('all' => false,
                     'site' => false,
                     'site-index' => false,
                     'course-view' => false, 
                     'course-view-social' => false,
                     'mod' => false,
                     'mod-sforum' => true);
    }

    public function instance_allow_multiple() {
        return false;
    }

    function has_config() { return true; }

}

