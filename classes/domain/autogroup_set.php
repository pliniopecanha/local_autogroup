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
use totara_reportbuilder\rb\display\ucfirst;

require_once(__DIR__ . "/../../../../group/lib.php");

/**
 * Class sort
 * @package local_autogroup\domain
 */
class autogroup_set extends domain {
    // ... [demais propriedades e métodos permanecem iguais] ...

    /**
     * Define o courseid deste conjunto de autogroup.
     * Necessário para compatibilidade com edit.php.
     */
    public function set_course($courseid) {
        if (is_numeric($courseid) && (int)$courseid > 0) {
            $this->courseid = $courseid;
        }
    }

    /**
     * @param \stdclass $user
     * @param \moodle_database $db
     * @param \context_course $context
     * @return bool
     */
    public function verify_user_group_membership(\stdclass $user, \moodle_database $db, \context_course $context) {
        $eligiblegroups = array();

        // Recupera o valor do filtro do campo de agrupamento (groupby_filtervalue), se houver
        $filtervalue = '';
        if (isset($this->sortconfig->filtervalue)) {
            $filtervalue = trim($this->sortconfig->filtervalue);
        }

        // Recupera o valor do campo do usuário selecionado em "Group by"
        $usergroupbyvalue = null;
        if (!empty($this->sortconfig->field)) {
            $fieldname = $this->sortconfig->field;
            if (isset($user->$fieldname)) {
                $usergroupbyvalue = $user->$fieldname;
            } elseif (isset($user->profile_field[$fieldname])) {
                $usergroupbyvalue = $user->profile_field[$fieldname];
            }
        }

        // Aplica o filtro: se existir filtro e valor do usuário não corresponder, não processa
        if ($filtervalue !== '') {
            if ($usergroupbyvalue !== $filtervalue) {
                // Não adiciona o usuário a nenhum grupo, apenas retorna
                return true;
            }
        }

        // Segue normalmente se o usuário tem algum dos papéis elegíveis
        if ($this->user_is_eligible_in_context($user->id, $db, $context)) {
            $eligiblegroups = $this->sortmodule->eligible_groups_for_user($user);
        }

        // An array of groupids which will be populated as we ensure membership.
        $validgroups = array();
        $newgroup = false;

        foreach ($eligiblegroups as $eligiblegroup) {
            list($group, $groupcreated) = $this->get_or_create_group_by_idnumber($eligiblegroup, $db);
            if ($group) {
                $validgroups[] = $group->id;
                $group->ensure_user_is_member($user->id);
                if ($group->courseid == $this->courseid) {
                    if (!$newgroup || $groupcreated) {
                        $newgroup = $group->id;
                    }
                }
            }
        }

        // Now run through other groups and ensure user is not a member.
        foreach ($this->groups as $key => $group) {
            if (!in_array($group->id, $validgroups)) {
                if ($group->ensure_user_is_not_member($user->id) && $newgroup) {
                    $this->update_forums($user->id, $group->id, $newgroup, $db);
                }
            }
        }

        return true;
    }

    // ... [demais métodos permanecem iguais] ...
}
