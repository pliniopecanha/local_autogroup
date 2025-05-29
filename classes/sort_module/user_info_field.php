<?php
namespace local_autogroup\sort_module;

use local_autogroup\sort_module;
use stdClass;

class user_info_field extends sort_module {
    private $field = '';

    public function __construct($config, $courseid) {
        if ($this->config_is_valid($config)) {
            $this->field = $config->field;
        }
        $this->courseid = (int)$courseid;
    }

    public function config_is_valid(stdClass $config) {
        return isset($config->field) && !empty($config->field);
    }

    public function get_config_options() {
        global $DB;
        $options = [];
        $infofields = $DB->get_records('user_info_field');
        foreach ($infofields as $field) {
            $options[$field->shortname] = format_string($field->name);
        }
        return $options;
    }

    public function eligible_groups_for_user(stdClass $user) {
        $field = $this->field;
        // First, try from user object (recommended in Moodle).
        if (!empty($user->profile_field) && isset($user->profile_field[$field])) {
            $value = $user->profile_field[$field];
            if ($value !== '') {
                return [$value];
            }
        }
        // Fallback: query DB directly if not present in user (legacy/robustness).
        global $DB;
        $fieldobj = $DB->get_record('user_info_field', ['shortname' => $field]);
        if ($fieldobj) {
            $data = $DB->get_record('user_info_data', ['fieldid' => $fieldobj->id, 'userid' => $user->id]);
            if ($data && !empty($data->data)) {
                return [$data->data];
            }
        }
        return [];
    }

    public function grouping_by() {
        return empty($this->field) ? false : $this->field;
    }

    public function grouping_by_text() {
        return $this->grouping_by();
    }
}
