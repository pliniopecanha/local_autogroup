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

defined("MOODLE_INTERNAL") || die();

use local_autogroup\domain;
use local_autogroup\exception;
use local_autogroup\sort_module;
use moodle_database;
use stdClass;

require_once(__DIR__ . "/../../../../group/lib.php");

class autogroup_set extends domain {
    protected $attributes = array(
        "id", "courseid", "sortmodule", "sortconfig", "timecreated", "timemodified", "customgroupname"
    );
    protected $courseid = 0;
    protected $sortmodule;
    protected $sortmodulename = "local_autogroup\\sort_module\\profile_field";
    protected $sortmoduleshortname = "profile_field";
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
            $sortmodulename = "local_autogroup\\sort_module\\" . $autogroupset->sortmodule;
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
        // Ensure sortmodulename is valid before instantiating
        if (isset($this->sortmodulename) && class_exists($this->sortmodulename)) {
             $this->sortmodule = new $this->sortmodulename($this->sortconfig, $this->courseid);
        } else {
             // Fallback or error handling if sort module class doesn't exist
             // For safety, fallback to a default or handle error appropriately
             // Using profile_field as a safe default if name is invalid
             $default_sortmodulename = "local_autogroup\\sort_module\\profile_field";
             $this->sortmodule = new $default_sortmodulename($this->sortconfig, $this->courseid);
             // Optionally log this issue
             // debugging("Invalid or missing sort module name: " . $this->sortmodulename . ". Falling back to profile_field.", DEBUG_DEVELOPER);
        }
    }

    private function get_autogroups(\moodle_database $db) {
        $sql = "SELECT g.*" . PHP_EOL
            . "FROM {groups} g" . PHP_EOL
            . "WHERE g.courseid = :courseid" . PHP_EOL
            . "AND " . $db->sql_like("g.idnumber", ":autogrouptag");
        $param = array(
            "courseid" => $this->courseid,
            "autogrouptag" => $this->generate_group_idnumber("%")
        );

        $this->groups = $db->get_records_sql($sql, $param);

        // Ensure groups is an array before iterating
        if (!is_array($this->groups)) {
            $this->groups = [];
        }

        foreach ($this->groups as $k => $group) {
            try {
                $this->groups[$k] = new domain\group($group, $db);
            } catch (exception\invalid_group_argument $e) {
                unset($this->groups[$k]);
            }
        }
    }

    private function generate_group_idnumber($groupname) {
        // Ensure $this->id is set, otherwise, idnumber might be invalid
        $setid = isset($this->id) ? $this->id : 0;
        $idnumber = implode("|", ["autogroup", $setid, $groupname]);
        return $idnumber;
    }

    private function retrieve_applicable_roles(\moodle_database $db) {
        $roles = []; // Default to empty array
        if ($this->exists()) {
             $roles = $db->get_records_menu("local_autogroup_roles", array("setid" => $this->id), "id", "id, roleid");
        }
        // If no roles found for this set OR if the set doesn't exist yet (e.g., creating new)
        if (empty($roles)) {
            $roles = $this->retrieve_default_roles();
        }
        // Ensure roles is always an array
        return is_array($roles) ? $roles : [];
    }

    private function retrieve_default_roles() {
        $config = \get_config("local_autogroup");
        $newroles = array();
        if ($roles = \get_all_roles()) {
            $roles = \role_fix_names($roles, null, ROLENAME_ORIGINAL);
            foreach ($roles as $role) {
                $attributename = "eligiblerole_" . $role->id;
                if (isset($config->$attributename) && $config->$attributename) {
                    $newroles[] = $role->id;
                }
            }
        }
        return $newroles;
    }

    public function delete(\moodle_database $db, $cleanupgroups = true) {
        if (!$this->exists()) {
            return false;
        }

        $db->delete_records("local_autogroup_set", array("id" => $this->id));
        $db->delete_records("local_autogroup_roles", array("setid" => $this->id));
        $db->delete_records("local_autogroup_manual", array("groupid" => $this->id)); // Assuming groupid refers to setid? Check schema.

        if ($cleanupgroups) {
            // Reload groups before deleting to ensure we have the latest list
            $this->get_autogroups($db);
            if (is_array($this->groups)) {
                 foreach ($this->groups as $k => $group) {
                     if ($group instanceof domain\group) {
                         $group->remove();
                     }
                     unset($this->groups[$k]);
                 }
            }
        } else {
            $this->disassociate_groups();
        }

        return true;
    }

    public function disassociate_groups() {
        // Reload groups before disassociating
        global $DB;
        $this->get_autogroups($DB);
        if (is_array($this->groups)) {
             foreach ($this->groups as $k => $group) {
                 if ($group instanceof domain\group) {
                     $group->idnumber = "";
                     $group->update();
                 }
                 unset($this->groups[$k]);
             }
        }
    }

    public function get_eligible_roles() {
        $cleanroles = array();
        // Ensure $this->roles is an array
        if (!is_array($this->roles)) {
             $this->roles = [];
        }
        foreach ($this->roles as $role) {
            $cleanroles[$role] = $role;
        }
        return $cleanroles;
    }

    public function set_eligible_roles($newroles) {
        // Ensure $newroles is an array
        if (!is_array($newroles)) {
             $newroles = [];
        }
        $oldroles = $this->roles;
        $this->roles = $newroles;

        // Check if roles actually changed
        $changed = false;
        $currentroles_check = array_flip($this->roles);
        $oldroles_check = array_flip($oldroles);

        foreach ($this->roles as $role) {
            if (!isset($oldroles_check[$role])) {
                $changed = true; // Role added
                break;
            }
        }
        if (!$changed) {
             foreach ($oldroles as $role) {
                 if (!isset($currentroles_check[$role])) {
                     $changed = true; // Role removed
                     break;
                 }
             }
        }
        return $changed;
    }

    public function get_group_by_options() {
        // Ensure sortmodule is initialized
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise(); // Attempt to initialize if not already
        }
        // Check again after attempting initialization
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "get_config_options")) {
             return $this->sortmodule->get_config_options();
        } else {
             return []; // Return empty array if sortmodule is invalid
        }
    }

    public function get_delimited_by_options() {
        // Ensure sortmodule is initialized
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise();
        }
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "get_delimiter_options")) {
             return $this->sortmodule->get_delimiter_options();
        } else {
             return [];
        }
    }

    public function get_group_count() {
        // Ensure groups are loaded
        if (empty($this->groups) && $this->exists()) {
             global $DB;
             $this->get_autogroups($DB);
        }
        return is_array($this->groups) ? count($this->groups) : 0;
    }

    public function get_membership_counts() {
        $result = array();
        // Ensure groups are loaded
        if (empty($this->groups) && $this->exists()) {
             global $DB;
             $this->get_autogroups($DB);
        }
        if (is_array($this->groups)) {
             foreach ($this->groups as $groupid => $group) {
                 if ($group instanceof domain\group) {
                     $result[$groupid] = $group->membership_count();
                 }
             }
        }
        return $result;
    }

    public function grouping_by() {
        // Ensure sortmodule is initialized
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise();
        }
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "grouping_by")) {
             return $this->sortmodule->grouping_by();
        } else {
             return false;
        }
    }

    public function grouping_by_text() {
        // Ensure sortmodule is initialized
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise();
        }
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "grouping_by_text")) {
             return $this->sortmodule->grouping_by_text();
        } else {
             return "";
        }
    }

    public function delimited_by() {
        // Ensure sortmodule is initialized
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise();
        }
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "delimited_by")) {
             return $this->sortmodule->delimited_by();
        } else {
             return false;
        }
    }

    public function set_course($courseid) {
        if (is_numeric($courseid) && (int)$courseid > 0) {
            $this->courseid = (int)$courseid;
        }
    }

    public function set_sort_module($sortmodule = "profile_field") {
        if ($this->sortmoduleshortname == $sortmodule) {
            return;
        }

        $this->sortmodulename = "local_autogroup\\sort_module\\" . $sortmodule;
        $this->sortmoduleshortname = $sortmodule;

        $this->sortconfig = new stdClass();

        // Re-initialise the sort module
        $this->initialise();
    }

    public function set_options(stdClass $config) {
        // Ensure sortmodule is initialized before checking config validity
        if (!isset($this->sortmodule) || !is_object($this->sortmodule)) {
             $this->initialise();
        }
        // Check again after attempting initialization
        if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "config_is_valid")) {
             if ($this->sortmodule->config_is_valid($config)) {
                 $this->sortconfig = $config;
                 // Re-initialise with new config
                 $this->initialise();
             }
        } else {
             // Handle error: sortmodule not available to validate config
        }
    }

    // --- START REVISED verify_user_group_membership --- //
    public function verify_user_group_membership(\stdclass $user, \moodle_database $db, \context_course $context) {
        // Check if user is eligible based on roles in this context
        $is_eligible = $this->user_is_eligible_in_context($user->id, $db, $context);

        $eligiblegroupnames = [];
        if ($is_eligible) {
            // User has an eligible role, let the sort module determine the group names/identifiers.
            // Ensure sortmodule is initialized.
            if (isset($this->sortmodule) && is_object($this->sortmodule) && method_exists($this->sortmodule, "eligible_groups_for_user")) {
                 $eligiblegroupnames = $this->sortmodule->eligible_groups_for_user($user);
                 // Ensure the result is always an array
                 if (!is_array($eligiblegroupnames)) {
                     $eligiblegroupnames = [];
                 }
            } else {
                 // Log error or handle case where sortmodule isn't ready?
                 // If not, $eligiblegroupnames remains empty.
                 // debugging("Sort module not initialized or method missing in verify_user_group_membership", DEBUG_DEVELOPER);
            }
        } else {
            // User is not eligible, they should be removed from all autogroups for this set.
            // We'll handle removal later based on $eligiblegroupnames being empty.
        }

        // Apply filter value if set
        $filtervalue = null;
        if (isset($this->sortconfig->filtervalue)) {
            $filtervalue = trim($this->sortconfig->filtervalue);
        }

        $finaleligiblegroups = [];
        if ($filtervalue !== null && $filtervalue !== "") {
            // Filter the list returned by the sort module
            foreach ($eligiblegroupnames as $groupname) {
                // Use case-insensitive comparison for robustness
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

        // Ensure groups are loaded before processing memberships
        if (empty($this->groups) && $this->exists()) {
             $this->get_autogroups($db);
        }
        // Ensure $this->groups is an array after loading
        if (!is_array($this->groups)) {
             $this->groups = [];
        }

        foreach ($finaleligiblegroups as $eligiblegroupname) {
            // Use the group name/identifier returned by the sort module
            list($group, $groupcreated) = $this->get_or_create_group_by_idnumber($eligiblegroupname, $db);
            if ($group instanceof domain\group) { // Check if a valid group object was returned
                $validgroups[] = $group->id; // Store the Moodle group ID
                $group->ensure_user_is_member($user->id);
                // Track if the user was added to a group that was just created
                if ($groupcreated && $group->courseid == $this->courseid) {
                    $newgroup = $group->id; // Store the ID of the new group
                }
            }
        }

        // Remove user from any autogroups in this set they are no longer eligible for
        foreach ($this->groups as $key => $group) {
             if ($group instanceof domain\group) { // Ensure it's a valid group object
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
        }

        return true;
    }
    // --- END REVISED verify_user_group_membership --- //

    // --- START REVISED user_is_eligible_in_context --- //
    private function user_is_eligible_in_context($userid, \moodle_database $db, \context_course $context) {
        // Check if roles array is initialized and not empty
        if (empty($this->roles) || !is_array($this->roles)) {
            // If no roles are configured for eligibility (should load defaults), treat as ineligible.
            return false;
        }
        $roleassignments = \get_user_roles($context, $userid);
        if (empty($roleassignments)) {
            return false; // No roles assigned in this context
        }
        foreach ($roleassignments as $role) {
            if (in_array($role->roleid, $this->roles)) {
                return true; // Found an eligible role
            }
        }
        return false; // No eligible roles found
    }
    // --- END REVISED user_is_eligible_in_context --- //

    // AJUSTE: Atualiza nome do grupo no banco se customgroupname for alterado após criação
    private function get_or_create_group_by_idnumber($groupidentifier, \moodle_database $db) {
        // Input can be a string (group name/value) or potentially an object (though less likely from sort modules)
        if (is_object($groupidentifier) && isset($groupidentifier->idnumber) && isset($groupidentifier->friendlyname)) {
            // This case seems less likely based on sort module implementations, but keep for compatibility
            $groupname = $groupidentifier->friendlyname;
            $groupidnumber_part = $groupidentifier->idnumber;
        } else {
            // Assume input is the string identifier (value from profile field, etc.)
            $groupidnumber_part = (string)$groupidentifier;
            $groupname = ucfirst((string)$groupidentifier); // Default group name based on identifier
        }

        // Generate the full Moodle group idnumber
        $idnumber = $this->generate_group_idnumber($groupidnumber_part);

        // Ensure groups are loaded before checking memory
        if (empty($this->groups) && $this->exists()) {
             $this->get_autogroups($db);
        }
        if (!is_array($this->groups)) {
             $this->groups = [];
        }

        // Check groups already loaded in memory.
        foreach ($this->groups as $groupobj) {
            if ($groupobj instanceof domain\group && $groupobj->idnumber == $idnumber) {
                // Group found in memory. Check if name needs update.
                $expectedname = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
                if ($groupobj->name != $expectedname) {
                    $groupobj->name = $expectedname;
                    $groupobj->update(); // Update the group object (which should update DB via its own method)
                }
                return [$groupobj, false]; // Return existing group object, not created now
            }
        }

        // Check groups in the database (if not loaded or missed).
        $grouprecord = $db->get_record("groups", array("courseid" => $this->courseid, "idnumber" => $idnumber));
        if (!empty($grouprecord)) {
            // Group found in DB. Update name if necessary.
            $expectedname = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
            if ($grouprecord->name != $expectedname) {
                $grouprecord->name = $expectedname;
                $db->update_record("groups", $grouprecord);
            }
            // Create domain object and add to memory cache
            try {
                 $groupobj = new domain\group($grouprecord, $db);
                 $this->groups[$grouprecord->id] = $groupobj;
                 return [$groupobj, false]; // Return existing group object, not created now
            } catch (exception\invalid_group_argument $e) {
                 // Failed to create domain object from record
                 return [false, false];
            }
        }

        // Create new group if it doesn"t exist.
        $data = new \stdclass();
        $data->id = 0; // Let DB assign ID
        $data->name = !empty($this->customgroupname) ? $this->customgroupname : $groupname;
        $data->idnumber = $idnumber;
        $data->courseid = $this->courseid;
        $data->description = ""; // Default description
        $data->descriptionformat = FORMAT_HTML; // Default format
        $data->enrolmentkey = null;
        $data->picture = 0;
        $data->hidepicture = 0;
        $data->timecreated = time();
        $data->timemodified = $data->timecreated;

        try {
            // Use Moodle core function to create group for better compatibility
            $newgroupid = groups_create_group($data);
            if ($newgroupid) {
                 // Fetch the newly created group record to create the domain object
                 $newgrouprecord = $db->get_record("groups", array("id" => $newgroupid));
                 $newgroupobj = new domain\group($newgrouprecord, $db);
                 $this->groups[$newgroupid] = $newgroupobj; // Add to memory cache
                 return [$newgroupobj, true]; // Return new group object, was created now
            } else {
                 // Group creation failed
                 return [false, false];
            }
        } catch (\Exception $e) { // Catch potential exceptions during group creation
            // Log error
            // debugging("Error creating group: " . $e->getMessage(), DEBUG_DEVELOPER);
            return [false, false];
        }
    }

    public function create(\moodle_database $db) {
        return $this->save($db);
    }

    public function save(moodle_database $db, $cleanupold = true) {
        $this->update_timestamps();

        $data = $this->as_object();
        // Ensure sortconfig is an object before encoding
        if (!is_object($data->sortconfig)) {
             $data->sortconfig = new stdClass();
        }
        $data->sortconfig = json_encode($data->sortconfig);

        if ($this->exists()) {
            $db->update_record("local_autogroup_set", $data);
        } else {
            // Ensure courseid is set before inserting
            if (empty($data->courseid)) {
                 // Handle error: courseid is required
                 return false;
            }
            $this->id = $db->insert_record("local_autogroup_set", $data);
            // Update the object's ID
            $data->id = $this->id;
        }

        // Save roles only if the set exists (has an ID)
        if ($this->exists()) {
             $this->save_roles($db);
        }

        // Cleanup or disassociate old groups if needed
        if ($this->exists()) { // Only relevant if the set exists
             if (!$cleanupold) {
                 $this->disassociate_groups();
             }
             // If cleanupold is true, the deletion logic might need revisiting
             // Currently, delete() handles cleanup, maybe save shouldn't?
             // Or perhaps cleanup refers to users in groups, not the groups themselves?
             // Assuming $cleanupold=true means remove users from groups they no longer belong to (handled in verify_user_group_membership)
        }
        return $this->id; // Return the ID of the saved/created set
    }

    private function as_object() {
        $autogroupset = new \stdclass();
        foreach ($this->attributes as $attribute) {
            // Ensure attribute exists before assigning
            if (property_exists($this, $attribute)) {
                 $autogroupset->$attribute = $this->$attribute;
            }
        }
        // Ensure sortmoduleshortname is set
        $autogroupset->sortmodule = isset($this->sortmoduleshortname) ? $this->sortmoduleshortname : "profile_field";
        return $autogroupset;
    }

    private function save_roles(moodle_database $db) {
        if (!$this->exists()) {
            return false;
        }

        // Ensure $this->roles is an array
        if (!is_array($this->roles)) {
             $this->roles = [];
        }

        $currentrolesindb = $db->get_records_menu(
            "local_autogroup_roles",
            array("setid" => $this->id),
            "roleid", // Keyed by roleid
            "id, roleid"
        );
        if (!is_array($currentrolesindb)) {
             $currentrolesindb = [];
        }

        $rolestoadd = array();
        $rolestokeep = array(); // Keep track of roles that are already in DB

        foreach ($this->roles as $roleid) {
            if (!isset($currentrolesindb[$roleid])) {
                // Role needs to be added
                $newrow = new stdClass();
                $newrow->setid = $this->id;
                $newrow->roleid = $roleid;
                $rolestoadd[] = $newrow;
            } else {
                // Role already exists, mark it to keep
                $rolestokeep[$roleid] = $currentrolesindb[$roleid]->id;
            }
        }

        // Determine roles to remove
        $rolestoremove_ids = array();
        foreach ($currentrolesindb as $roleid => $record) {
            if (!isset($rolestokeep[$roleid])) {
                $rolestoremove_ids[] = $record->id; // Get the primary key (id) for deletion
            }
        }

        $changed = false;

        // Remove roles that are no longer needed
        if (!empty($rolestoremove_ids)) {
            list($in_sql, $params) = $db->get_in_or_equal($rolestoremove_ids, SQL_PARAMS_NAMED, "delid");
            $sql = "DELETE FROM {local_autogroup_roles} WHERE id {$in_sql}";
            $db->execute($sql, $params);
            $changed = true;
        }

        // Add new roles
        if (!empty($rolestoadd)) {
            $db->insert_records("local_autogroup_roles", $rolestoadd);
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

    // --- START REVISED remove_user_from_all_autogroups --- //
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
        // Ensure $this->groups is an array
        if (!is_array($this->groups)) {
             return; // Nothing to do if groups aren't loaded or empty
        }
        foreach ($this->groups as $group) {
             if ($group instanceof domain\group) { // Check if it's a valid group object
                 $group->ensure_user_is_not_member($userid);
             }
        }
    }
    // --- END REVISED remove_user_from_all_autogroups --- //

    private function update_forums($userid, $oldgroupid, $newgroupid, $db) {
        // TODO: implement forum update logic if necessary - This seems non-critical for the core functionality
        // Check if this function is actually used or needed. If not, it can be removed or left empty.
    }
}
