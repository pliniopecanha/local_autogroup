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
 * autogroup local plugin
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\form;

use local_autogroup\domain;
use local_autogroup\form;

class autogroup_set_settings extends form {
    /**
     * @type domain\autogroup_set
     */
    protected $_customdata;

    /** @var \local_autogroup\local\autogroup_set|null */
    protected $autogroup_set;

    /**
     * Evita deprecated property do PHP 8.2+
     * @var mixed
     */
    protected $groupsetdata;

    public function definition() {
        $this->autogroup_set = $this->get_submitted_data();

        $this->add_group_by_options();
        $this->add_groupby_filtervalue_option();
        $this->add_field_delimiter_options();
        $this->add_role_options();
        $this->add_custom_groupname_option();
        $this->add_action_buttons();
    }

    /**
     * @throws \coding_exception
     */
    private function add_group_by_options() {
        $mform = &$this->_form;
        $options = $this->_customdata->get_group_by_options();
        $mform->addElement('select', 'groupby', get_string('set_groupby', 'local_autogroup'), $options);
        $mform->setDefault('groupby', $this->_customdata->grouping_by());

        if ($this->_customdata->exists()) {
            $mform->addElement('selectyesno', 'cleanupold', get_string('cleanupold', 'local_autogroup'));
            $mform->setDefault('cleanupold', 1);
        }
    }

    /**
     * Campo de filtro para o campo Group by.
     */
    private function add_groupby_filtervalue_option() {
        $mform = &$this->_form;
        $mform->addElement('text', 'groupby_filtervalue', get_string('groupby_filtervalue', 'local_autogroup'));
        $mform->setType('groupby_filtervalue', PARAM_TEXT);
        $mform->addHelpButton('groupby_filtervalue', 'groupby_filtervalue', 'local_autogroup');

        $filtervalue = '';
        if (!empty($this->_customdata->sortconfig) && isset($this->_customdata->sortconfig->filtervalue)) {
            $filtervalue = $this->_customdata->sortconfig->filtervalue;
        }
        $mform->setDefault('groupby_filtervalue', $filtervalue);
    }

    protected function add_field_delimiter_options() {
        $delimiteroptions = $this->_customdata->get_delimited_by_options();
        if ($delimiteroptions) {
            $mform = &$this->_form;
            $mform->addElement('select', 'delimitedby', get_string('set_delimitedby', 'local_autogroup'), $delimiteroptions);
            $mform->setDefault('delimitedby', $this->_customdata->delimited_by());
        }
    }

    /**
     * @throws \coding_exception
     */
    private function add_role_options() {
        $mform = &$this->_form;
        $currentroles = $this->_customdata->get_eligible_roles();

        $mform->addElement('header', 'roles', get_string('set_roles', 'local_autogroup'));

        if ($roles = \get_all_roles()) {
            $roles = \role_fix_names($roles, null, ROLENAME_ORIGINAL);
            $assignableroles = \get_roles_for_contextlevels(CONTEXT_COURSE);
            foreach ($roles as $role) {
                $mform->addElement('checkbox', 'role_' . $role->id, $role->localname);
                if (in_array($role->id, $currentroles)) {
                    $mform->setDefault('role_' . $role->id, 1);
                }
            }
        }
    }

    /**
     * Campo para nome personalizado de grupo a nível de curso.
     */
    private function add_custom_groupname_option() {
        $mform = &$this->_form;
        $mform->addElement('text', 'customgroupname', get_string('customgroupname_course', 'local_autogroup'));
        $mform->setType('customgroupname', PARAM_TEXT);
        $mform->addHelpButton('customgroupname', 'customgroupname_course', 'local_autogroup');

        if (isset($this->_customdata->customgroupname)) {
            $mform->setDefault('customgroupname', $this->_customdata->customgroupname);
        }
    }
}
