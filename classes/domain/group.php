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
 * A group object relates to a Moodle group and is generally the end
 * point for most usecases.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

defined('MOODLE_INTERNAL') || die();

use local_autogroup\domain;
use local_autogroup\exception;
use stdClass;
use moodle_database;

require_once(__DIR__ . "/../../../../group/lib.php");

class group extends domain {
    protected $attributes = array(
        'id', 'courseid', 'idnumber', 'name', 'description', 'descriptionformat',
        'enrolmentkey', 'picture', 'timecreated', 'timemodified', 'visibility', 'participation'
    );
    protected $courseid = 0;
    protected $idnumber = '';
    protected $name = '';
    protected $description = '';
    protected $descriptionformat = 1;
    protected $enrolmentkey = '';
    protected $picture = 0;
    protected $timecreated = 0;
    protected $timemodified = 0;
    protected $visibility = 0;
    protected $participation = 1;

    private $members;

    /**
     * @param int|stdClass $group
     * @param \moodle_database $db
     * @throws exception\invalid_group_argument
     */
    public function __construct($group, \moodle_database $db) {
        if (is_int($group) && $group > 0) {
            $this->load_from_database($group, $db);
        } else if ($this->validate_object($group)) {
            $this->load_from_object($group);
        } else {
            throw new exception\invalid_group_argument($group);
        }

        $this->get_members($db);
    }

    private function load_from_database($groupid, \moodle_database $db) {
        $group = $db->get_record('groups', array('id' => $groupid));
        if ($this->validate_object($group)) {
            $this->load_from_object($group);
        }
    }

    private function validate_object($group) {
        if (!is_object($group)) {
            return false;
        }
        if (!isset($group->timecreated)) {
            $group->timecreated = time();
        }
        if (!isset($group->timemodified)) {
            $group->timemodified = 0;
        }
        return isset($group->id)
            && $group->id >= 0
            && strlen($group->name) > 0
            && strstr($group->idnumber, 'autogroup|');
    }

    private function load_from_object(\stdclass $group) {
        foreach ($this->attributes as $attribute) {
            if (property_exists($group, $attribute)) {
                $this->$attribute = $group->$attribute;
            }
        }
    }

    private function get_members(\moodle_database $db) {
        $this->members = $db->get_records_menu('groups_members', array('groupid' => $this->id), 'id', 'id,userid');
    }

    /**
     * Check that an user is member and add it if necessary.
     * @param int $userid
     * @return bool true if user has just been added as member, false otherwise.
     */
    public function ensure_user_is_member($userid) {
        foreach ($this->members as $member) {
            if ($member === $userid) {
                return false;
            }
        }
        \groups_add_member($this->as_object()->id, $userid, 'local_autogroup');
        // Atualiza membros após inclusão
        global $DB;
        $this->get_members($DB);
        return true;
    }

    /**
     * @return \stdclass $group
     */
    private function as_object() {
        $group = new \stdclass();
        foreach ($this->attributes as $attribute) {
            $group->$attribute = $this->$attribute;
        }
        return $group;
    }

    /**
     * Check that an user is NOT member and remove it if necessary.
     * @param int $userid
     * @return bool true if user has just been removed, false otherwise.
     */
    public function ensure_user_is_not_member($userid) {
        $pluginconfig = get_config('local_autogroup');
        if ($pluginconfig->preservemanual) {
            global $DB;
            if ($DB->record_exists('local_autogroup_manual', array('userid' => $userid, 'groupid' => $this->id))) {
                return;
            }
        }

        foreach ($this->members as $member) {
            if ($member === $userid) {
                \groups_remove_member($this->as_object()->id, $userid);
                // Atualiza membros após remoção
                global $DB;
                $this->get_members($DB);
                return true;
            }
        }
        return false;
    }

    public function membership_count() {
        return count($this->members);
    }

    /**
     * Adds this group to the application if it hasn't been created already
     */
    public function create() {
        if ($this->id == 0) {
            // O nome do grupo já deve estar corretamente em $this->name!
            $groupobj = $this->as_object();
            $this->id = (int)\groups_create_group($groupobj);
        }
    }

    /**
     * @param moodle_database $db
     * @return bool   whether this group is an autogroup or not
     */
    public function is_valid_autogroup(\moodle_database $db) {
        if (!$this->is_autogroup()) {
            return false;
        }

        $idparts = explode('|', $this->idnumber);
        if (!isset($idparts[1])) {
            return false;
        }

        $groupsetid = (int)$idparts[1];
        if ($groupsetid < 1) {
            return false;
        }

        return $db->record_exists('local_autogroup_set', array('id' => $groupsetid, 'courseid' => $this->courseid));
    }

    private function is_autogroup() {
        return strstr($this->idnumber, 'autogroup|');
    }

    /**
     * delete this group from the application
     * @return bool
     */
    public function remove() {
        if ($this->is_autogroup()) {
            return \groups_delete_group($this->id);
        }
        return false;
    }

    public function update() {
        if (!$this->exists()) {
            return false;
        }
        return \groups_update_group($this->as_object());
    }
}
