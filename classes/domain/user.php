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
 * A user object relates to a real Moodle user; it acts as a container
 * for multiple courses which in turn contain multiple groups.
 * Initialising a course object will automatically load each autogroup
 * group which could be relevant for a user into memory.
 *
 * A user is also a group member; a membership register is also maintained
 */

namespace local_autogroup\domain;

defined('MOODLE_INTERNAL') || die();

use stdClass;
use moodle_database;
use local_autogroup\exception;

class user {
    protected $id;
    protected $object;
    protected $membership = [];
    protected $courses = [];

    /**
     * @param object|int $user
     * @param moodle_database $db
     * @param int $onlyload
     */
    public function __construct($user, moodle_database $db, $onlyload = 0) {
        // Get the data for this user.
        $this->parse_user_data($user, $db);

        // Register which autogroup groups this user is a member of currently.
        $this->get_group_membership($db);

        // If applicable, load courses this user is on and their autogroup groups.
        $this->get_courses($db, $onlyload);
    }

    /**
     * @param object|int $user
     * @param moodle_database $db
     * @return bool
     * @throws exception\invalid_user_argument
     */
    private function parse_user_data($user, moodle_database $db) {
        if (is_int($user) && $user > 0) {
            $this->id = $user;
            $this->object = $db->get_record('user', array('id' => $user));
            // Carrega campos customizados no formato profile_field[shortname]
            $fieldsInfo = $db->get_records('user_info_field', []);
            $customfields = $db->get_records('user_info_data', array('userid' => $user));
            $this->object->profile_field = [];
            foreach ($customfields as $field) {
                if (isset($fieldsInfo[$field->fieldid])) {
                    $shortname = $fieldsInfo[$field->fieldid]->shortname;
                    $this->object->profile_field[$shortname] = $field->data;
                }
            }
            return true;
        } else if (is_object($user) && isset($user->id) && $user->id > 0) {
            $this->id = $user->id;
            $this->object = $user;
            // Garante que campos customizados estejam presentes
            $fieldsInfo = $db->get_records('user_info_field', []);
            $customfields = $db->get_records('user_info_data', array('userid' => $user->id));
            $this->object->profile_field = [];
            foreach ($customfields as $field) {
                if (isset($fieldsInfo[$field->fieldid])) {
                    $shortname = $fieldsInfo[$field->fieldid]->shortname;
                    $this->object->profile_field[$shortname] = $field->data;
                }
            }
            return true;
        }

        throw new exception\invalid_user_argument($user);
    }

    /**
     * @param moodle_database $db
     */
    private function get_group_membership(moodle_database $db) {
        $sql = "SELECT g.id, g.courseid" . PHP_EOL
            . "FROM {groups} g" . PHP_EOL
            . "LEFT JOIN {groups_members} gm" . PHP_EOL
            . "ON gm.groupid = g.id" . PHP_EOL
            . "WHERE gm.userid = :userid" . PHP_EOL
            . "AND " . $db->sql_like('g.idnumber', ':autogrouptag');
        $param = array(
            'userid' => $this->id,
            'autogrouptag' => 'autogroup|%'
        );

        $this->membership = $db->get_records_sql_menu($sql, $param);
    }

    /**
     * Get courses for this user where an autogroup set has been added
     *
     * @param moodle_database $db
     */
    private function get_courses(moodle_database $db, $onlyload = 0) {
        if ($onlyload < 1) {
            $sql = "SELECT e.courseid" . PHP_EOL
                . "FROM {enrol} e" . PHP_EOL
                . "LEFT JOIN {user_enrolments} ue" . PHP_EOL
                . "ON ue.enrolid = e.id" . PHP_EOL
                . "LEFT JOIN {local_autogroup_set} gs" . PHP_EOL
                . "ON gs.courseid = e.courseid" . PHP_EOL
                . "WHERE ue.userid = :userid" . PHP_EOL
                . "AND gs.id IS NOT NULL";
            $param = array('userid' => $this->id);

            $this->courses = $db->get_fieldset_sql($sql, $param);
        } else {
            $this->courses[] = $onlyload;
        }

        foreach ($this->courses as $k => $courseid) {
            try {
                $courseid = (int)$courseid;
                $this->courses[$k] = new course($courseid, $db);
            } catch (exception\invalid_course_argument $e) {
                unset($this->courses[$k]);
            }
        }
    }

    /**
     * @param moodle_database $db
     * @return bool
     */
    public function verify_user_group_membership(moodle_database $db) {
        $result = true;
        foreach ($this->courses as $course) {
            $result &= $course->verify_user_group_membership($this->object, $db);
        }

        $this->get_group_membership($db);

        return $result;
    }
}
