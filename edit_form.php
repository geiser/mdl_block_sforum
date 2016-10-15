<?php

class block_sforum_edit_form extends block_edit_form {

    protected function specific_definition($mform) {

        // Section header title according to language file.
        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // A sample string variable with a default value.
        $mform->addElement('text', 'config_title', get_string('blocktitle', 'block_sforum'));
        $mform->setDefault('config_title', get_string('blocktitle_default', 'block_sforum'));
        $mform->setType('config_title', PARAM_TEXT);        

    }
}

