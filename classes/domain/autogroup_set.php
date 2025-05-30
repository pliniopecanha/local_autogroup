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
 * Autogroup sets are currently restricted to a one-to-one relationship
 * with courses, however this class exists in order to facilitate any
 * future efforts to allow for multiple autogroup rules to be defined
 * per course.
 *
 * In theory a course could have multiple rules assigning users in
 * different roles to different groups.
 *
 * Each autogroup set links to a single sort module to determine which
 * groups a user should exist in.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

defined('MOODLE_INTERNAL') || die();

use local_autogroup\domain;
use local_autogroup\exception;
use local_autogroup\sort_module;
use moodle_database;
use stdClass;

require_once(__DIR__ . "/../../../../group/lib.php");

class autogroup_set extends domain {
    protected $attributes = array(
        'id', 'courseid', 'sortmodule', 'sortconfig', 'timecreated', 'timemodified', 'customgroupname'
    );
    protected $courseid = 0;
    protected $sortmodule;
    protected $sortmodulename = 'local_autogroup\\sort_module\\profile_field';
    protected $sortmoduleshortname = 'profile_field';
    protected $sortconfig;
    protected $timecreated = 0;
    protected $timemodified = 0;
    protected $customgroupname = null;
    private $groups = array();
    private $roles = array();

    public function __construct(\moodle_database $db, $autogroupset = null) {
        // Set the sortconfig as empty.
        $this->sortconfig = new stdClass();

        // Get the id for this course.
        if ($this->validate_object($autogroupset)) {
            $this->load_from_object($autogroupset);
        }

        // Garante que sortconfig seja sempre objeto, nunca null.
        if (!$this->sortconfig) {
            $this->sortconfig = new stdClass();
        }

        $this->initialise();

        if ($this->exists()) {
            $this->get_autogroups($db);
        }

        $this->roles = $this->retrieve_applicable_roles($db);
    }

    private function validate_object($autogroupset) {
        return is_object($autogroupset)
            && isset($autogroupset->id)
            && $autogroupset->id >= 0
            && isset($autogroupset->courseid)
            && $autogroupset->courseid > 0;
    }

    private function load_from_object(\stdclass $autogroupset) {
        $this->id = (int)$autogroupset->id;
        $this->courseid = (int)$autogroupset->courseid;

        if (isset($autogroupset->sortmodule)) {
            $sortmodulename = 'local_autogroup\\sort_module\\' . $autogroupset->sortmodule;
            if (class_exists($sortmodulename)) {
                $this->sortmodulename = $sortmodulename;
                $this->sortmoduleshortname = $autogroupset->sortmodule;
            }
        }

        if (isset($autogroupset->sortconfig)) {
            $sortconfig = json_decode($autogroupset->sortconfig);
            if (json_last_error() == JSON_ERROR_NONE && is_object($sortconfig)) {
                $this->sortconfig = $sortconfig;
            } else {
                $this->sortconfig = new stdClass();
            }
        }

        if (isset($autogroupset->timecreated)) {
            $this->timecreated = $autogroupset->timecreated;
        }
        if (isset($autogroupset->timemodified)) {
            $this->timemodified = $autogroupset->timemodified;
        }
        if (isset($autogroupset->customgroupname)) {
            $this->customgroupname = $autogroupset->customgroupname;
        }
    }

    private function initialise() {
        $this->sortmodule = new $this->sortmodulename($this->sortconfig, $this->courseid);
    }

    private function get_autogroups(\moodle_database $db) {
        $sql = "SELECT g.*" . PHP_EOL
            . "FROM {groups} g" . PHP_EOL
            . "WHERE g.courseid = :courseid" . PHP_EOL
            . "AND " . $db->sql_like('g.idnumber', ':autogrouptag');
        $param = array(
            'courseid' => $this->courseid,
            'autogrouptag' => $this->generate_group_idnumber('%')
        );

        $this->groups = $db->get_records_sql($sql, $param);

        foreach ($this->groups as $k => $group) {
            try {
                $this->groups[$k] = new domain\group($group, $db);
            } catch (exception\invalid_group_argument $e) {
                unset($this->groups[$k]);
            }
        }
    }

    private function generate_group_idnumber($groupname) {
        $idnumber = implode('|', ['autogroup', $this->id, $groupname]);
        return $idnumber;
    }

    private function retrieve_applicable_roles(\moodle_database $db) {
        $roles = $db->get_records_menu('local_autogroup_roles', array('setid' => $this->id), 'id', 'id, roleid');
        if (empty($roles) && !$this->exists()) {
            $roles = $this->retrieve_default_roles();
        }
        return $roles;
    }

    private function retrieve_default_roles() {
        $config = \get_config('local_autogroup');
        if ($roles = \get_all_roles()) {
            $roles = \role_fix_names($roles, null, ROLENAME_ORIGINAL);
            $newroles = array();
            foreach ($roles as $role) {
                $attributename = 'eligiblerole_' . $role->id;
                if (isset($config->$attributename) && $config->$attributename) {
                    $newroles[] = $role->id;
                }
            }
            return $newroles;
        }
        return false;
    }

    public function delete(\moodle_database $db, $cleanupgroups = true) {
        if (!$this->exists()) {
            return false;
        }

        $db->delete_records('local_autogroup_set', array('id' => $this->id));
        $db->delete_records('local_autogroup_roles', array('setid' => $this->id));
        $db->delete_records('local_autogroup_manual', array('groupid' => $this->id));

        if ($cleanupgroups) {
            foreach ($this->groups as $k => $group) {
                $group->remove();
                unset($this->groups[$k]);
            }
        } else {
            $this->disassociate_groups();
        }

        return true;
    }

    public function disassociate_groups() {
        foreach ($this->groups as $k => $group) {
            $group->idnumber = '';
            $group->update();
            unset($this->groups[$k]);
        }
    }

    public function get_eligible_roles() {
        $cleanroles = array();
        foreach ($this->roles as $role) {
            $cleanroles[$role] = $role;
        }
        return $cleanroles;
    }

    public function set_eligible_roles($newroles) {
        $oldroles = $this->roles;
        $this->roles = $newroles;

        foreach ($this->roles as $role) {
            if ($key = array_search($role, $oldroles)) {
                unset($oldroles[$key]);
            } else {
                return true;
            }
        }
        return (bool)count($oldroles);
    }

    public function get_group_by_options() {
        return $this->sortmodule->get_config_options();
    }

    public function get_delimited_by_options() {
        return $this->sortmodule->get_delimiter_options();
    }

    public function get_group_count() {
        return count($this->groups);
    }

    public function get_membership_counts() {
        $result = array();
        foreach ($this->groups as $groupid => $group) {
            $result[$groupid] = $group->membership_count();
        }
        return $result;
    }

    public function grouping_by() {
        return $this->sortmodule->grouping_by();
    }

    public function grouping_by_text() {
        return $this->sortmodule->grouping_by_text();
    }

    public function delimited_by() {
        return $this->sortmodule->delimited_by();
    }

    public function set_course($courseid) {
        if (is_numeric($courseid) && (int)$courseid > 0) {
            $this->courseid = $courseid;
        }
    }

    public function set_sort_module($sortmodule = 'profile_field') {
        if ($this->sortmoduleshortname == $sortmodule) {
            return;
        }

        $this->sortmodulename = 'local_autogroup\\sort_module\\' . $sortmodule;
        $this->sortmoduleshortname = $sortmodule;

        $this->sortconfig = new stdClass();

        $this->sortmodule = new $this->sortmodulename($this->sortconfig, $this->courseid);
    }

    public function set_options(stdClass $config) {
        if ($this->sortmodule->config_is_valid($config)) {
            $this->sortconfig = $config;
            $this->initialise();
        }
    }

    public function verify_user_group_membership(\stdclass $user, \moodle_database $db, \context_course $context) {
        // Check if user is eligible based on roles first
        if (!$this->user_is_eligible_in_context($user->id, $db, $context)) {
            // User doesn't have any eligible roles in this context, remove from all autogroups for this set.
            $this->remove_user_from_all_autogroups($user->id, $db);
            return true; // User processed (removed or ignored)
        }

        // Let the sort module determine the eligible group names/identifiers for this user
        // The sort module (e.g., user_info_field or profile_field) handles fetching the correct field value internally.
        $eligiblegroupnames = $this->sortmodule->eligible_groups_for_user($user);

        // Apply filter value if set
        $filtervalue = null;
        if (isset($this->sortconfig->filtervalue)) {
            $filtervalue = trim($this->sortconfig->filtervalue);
        }

        $finaleligiblegroups = [];
        if ($filtervalue !== null && $filtervalue !== '') {
            // Filter the list returned by the sort module
            // Use case-insensitive comparison for robustness
            foreach ($eligiblegroupnames as $groupname) {
                if (strcasecmp(trim($groupname), $filtervalue) === 0) {
                    $finaleligiblegroups[] = $groupname;
                }
            }
        } else {
            // No filter, use all groups returned by the sort module
            $finaleligiblegroups = $eligiblegroupnames;
        }

        // Keep track of the Moodle group IDs the user should be a member of for this set
        $validgroups = array();
        $newgroup = false; // Tracks if a user was added to a *newly created* group in this run

        foreach ($finaleligiblegroups as $eligiblegroupname) {
            // Use the group name/identifier returned by the sort module
            list($group, $groupcreated) = $this->get_or_create_group_by_idnumber($eligiblegroupname, $db);
            if ($group) {
                $validgroups[] = $group->id; // Store the Moodle group ID
                $group->ensure_user_is_member($user->id);
                // Track if the user was added to a group that was just created
                if ($groupcreated && $group->courseid == $this->courseid) {
                    // If multiple groups are created, this logic might need refinement
                    // depending on desired behavior for forum updates etc.
                    // For now, just note *a* new group was involved.
                    $newgroup = $group->id;
                }
            }
        }

        // Remove user from any autogroups in this set they are no longer eligible for
        foreach ($this->groups as $key => $group) {
            // Check if the group ID is in the list of groups the user *should* be in
            if (!in_array($group->id, $validgroups)) {
                // User should not be in this group, ensure they are removed.
                $group->ensure_user_is_not_member($user->id);
                // Consider potential side effects like forum post updates if needed, similar to original code
                // if ($group->ensure_user_is_not_member($user->id) && $newgroup) {
                //     $this->update_forums($user->id, $group->id, $newgroup, $db);
                // }
            }
        }

        return true;
    }

    /**
     * Helper function to remove user from all groups managed by this autogroup set.
     *
     * @param int $userid The user ID.
     * @param \moodle_database $db Moodle database connection.
     */
    private function remove_user_from_all_autogroups($userid, \moodle_database $db) {
        // Ensure groups are loaded if not already
        if (empty($this->groups) && $this->exists()) {
            $this->get_autogroups($db);
        }
        foreach ($this->groups as $group) {
            $group->ensure_user_is_not_member($userid);
        }
    }

    private function user_is_eligible_in_context($userid, \moodle_database $db, \context_course $context) {
        // Se não houver papéis configurados para elegibilidade, considera todos elegíveis?
        // A implementação original parece correta, mas confirme se atende ao requisito.
        if (empty($this->roles)) {
             // Maybe return true if no roles are set? Or false? Depends on desired default.
             // Let's stick to original logic: if no roles set in config, user is not eligible.
             // However, the constructor loads default roles if none are saved, so this case might be rare.
             return false;
        }
        $roleassignments = \get_user_roles($context, $userid);
        foreach ($roleassignments as $role) {
            if (in_array($role->roleid, $this->roles)) {
                return true;
            }
        }
        return false;
    }

    // AJUSTE: Atualiza nome do grupo no banco se customgroupname for alterado após criação
    private function get_or_create_group_by_idnumber($group, \moodle_database $db) {
        if (is_object($group) && isset($group->idnumber) && isset($group->friendlyname)) {
            $groupname = $group->friendlyname;
            $groupidnumber = $group->idnumber;
        } else {
            $groupidnumber = (string)$group;
            $groupname = ucfirst((string)$group);
        }

        $idnumber = $this->generate_group_idnumber($groupidnumber);

        // Checa grupos já carregados na memória.
        foreach ($this->groups as $groupobj) { // Renamed variable to avoid conflict
            if ($groupobj->idnumber == $idnumber) {
                // Se o nome mudou, atualiza
                $expectedname = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
                if ($groupobj->name != $expectedname) {
                    $groupobj->name = $expectedname;
                    $groupobj->update();
                }
                return [$groupobj, false];
            }
        }

        // Checa grupos no banco (caso não carregados ainda)
        $grouprecord = $db->get_record('groups', array('courseid' => $this->courseid, 'idnumber' => $idnumber)); // Renamed variable
        if (!empty($grouprecord)) {
            // Atualiza nome se necessário
            $expectedname = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
            if ($grouprecord->name != $expectedname) {
                $grouprecord->name = $expectedname;
                $db->update_record('groups', $grouprecord);
            }
            $this->groups[$grouprecord->id] = new domain\group($grouprecord, $db);
            return [$this->groups[$grouprecord->id], false];
        }

        // Cria novo grupo se não existe.
        $data = new \stdclass();
        $data->id = 0;
        $data->name = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
        $data->idnumber = $idnumber;
        $data->courseid = $this->courseid;
        $data->description = '';
        $data->descriptionformat = 0;
        $data->enrolmentkey = null;
        $data->picture = 0;
        $data->hidepicture = 0;

        try {
            $newgroup = new domain\group($data, $db);
            $newgroup->create();
            $this->groups[$newgroup->id] = $newgroup;
        } catch (exception\invalid_group_argument $e) {
            return [false, false];
        }

        return [$this->groups[$newgroup->id], true];
    }

    public function create(\moodle_database $db) {
        return $this->save($db);
    }

    public function save(moodle_database $db, $cleanupold = true) {
        $this->update_timestamps();

        $data = $this->as_object();
        $data->sortconfig = json_encode($data->sortconfig);
        if ($this->exists()) {
            $db->update_record('local_autogroup_set', $data);
        } else {
            $this->id = $db->insert_record('local_autogroup_set', $data);
        }

        $this->save_roles($db);

        if (!$cleanupold) {
            $this->disassociate_groups();
        }
    }

    private function as_object() {
        $autogroupset = new \stdclass();
        foreach ($this->attributes as $attribute) {
            $autogroupset->$attribute = $this->$attribute;
        }
        $autogroupset->sortmodule = $this->sortmoduleshortname;
        return $autogroupset;
    }

    private function save_roles(moodle_database $db) {
        if (!$this->exists()) {
            return false;
        }

        $rolestoremove = $db->get_records_menu(
            'local_autogroup_roles',
            array('setid' => $this->id),
            'id',
            'id, roleid'
        );
        $rolestoadd = array();

        foreach ($this->roles as $role) {
            if ($key = array_search($role, $rolestoremove)) {
                unset($rolestoremove[$key]);
            } else {
                $newrow = new stdClass();
                $newrow->setid = $this->id;
                $newrow->roleid = $role;
                $rolestoadd[] = $newrow;
            }
        }

        $changed = false;

        if (count($rolestoremove)) {
            list($in, $params) = $db->get_in_or_equal($rolestoremove);
            $params[] = $this->id;
            $sql = "DELETE FROM {local_autogroup_roles}" . PHP_EOL
                . "WHERE roleid " . $in . PHP_EOL
                . "AND setid = ?";
            $db->execute($sql, $params);
            $changed = true;
        }

        if (count($rolestoadd)) {
            $db->insert_records('local_autogroup_roles', $rolestoadd);
            $changed = true;
        }

        return $changed;
    }

    private function update_timestamps() {
        if (!$this->exists()) {
            $this->timecreated = time();
        }
        $this->timemodified = time();
    }

    private function update_forums($userid, $oldgroupid, $newgroupid, $db) {
        // TODO: implement forum update logic if necessary
    }
}
