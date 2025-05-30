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
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;

class local_autogroup_renderer extends plugin_renderer_base {
    const URL_COURSE_SETTINGS = '/local/autogroup/edit.php';
    const URL_COURSE_MANAGE = '/local/autogroup/manage.php';

    public function add_new_groupset($courseid) {
        $output = '';
        $urlparams = array('action' => 'add', 'courseid' => (int)$courseid);
        $newurl = new moodle_url(self::URL_COURSE_SETTINGS, $urlparams);

        $modules = local_autogroup_get_sort_module_list();
        $select = new single_select($newurl, 'sortmodule', $modules);
        $select->set_label(get_string('create', 'local_autogroup'));

        $output .= $this->render($select);
        return $output;
    }

    public function intro_text($count = 0) {
        $output = '';
        $text = html_writer::tag('p', get_string('autogroupdescription', 'local_autogroup'));

        if (!$count) {
            $text .= html_writer::tag('p', get_string('newsettingsintro', 'local_autogroup'));
        } else {
            $text .= html_writer::tag('p', get_string('updatesettingsintro', 'local_autogroup', $count));
        }

        $output .= $this->heading(get_string('pluginname', 'local_autogroup'), 2);
        $output .= $this->box($text);

        return $output;
    }

    public function groupsets_table($groupsets) {
        if (!is_array($groupsets) || !count($groupsets)) {
            return null;
        }

        $data = array();
        foreach ($groupsets as $groupset) {
            $data[] = $this->groupsets_table_group($groupset);
        }

        $table = new html_table();
        $table->head = array(
            get_string('set_type', 'local_autogroup'),
            get_string('set_groupby', 'local_autogroup'),
            get_string('groupby_filtervalue', 'local_autogroup'),
            get_string('customgroupname_course', 'local_autogroup'),
            get_string('set_groups', 'local_autogroup'),
            get_string('set_roles', 'local_autogroup'),
            get_string('actions', 'local_autogroup')
        );
        $table->data = $data;

        return html_writer::table($table);
    }

    private function groupsets_table_group(local_autogroup\domain\autogroup_set $groupset) {
        $row = array();

        // Tipo (sort module)
        $row [] = ucfirst(local_autogroup_sanitise_sort_module_name($groupset->sortmodule));

        // Agrupamento
        $row [] = ucfirst($groupset->grouping_by_text());

        // Valor de filtro
        $filtervalue = '-';
        if (is_object($groupset->sortconfig) && isset($groupset->sortconfig->filtervalue)) {
            $filtervalue = $groupset->sortconfig->filtervalue;
        } elseif (is_string($groupset->sortconfig)) {
            $decoded = json_decode($groupset->sortconfig);
            if (is_object($decoded) && isset($decoded->filtervalue)) {
                $filtervalue = $decoded->filtervalue;
            }
        }
        $row[] = $filtervalue;

        // Nome personalizado do grupo
        $customgroupname = '-';
        if (property_exists($groupset, 'customgroupname') && !is_null($groupset->customgroupname) && trim($groupset->customgroupname) !== '') {
            $customgroupname = $groupset->customgroupname;
        }
        $row[] = (string)$customgroupname;

        // Contagem de grupos
        $row [] = $groupset->get_group_count();

        // Papéis elegíveis
        $roles = $groupset->get_eligible_roles();
        $roles = role_fix_names($roles, null, ROLENAME_ORIGINAL);
        $roletext = implode(', ', $roles);
        $row[] = $roletext;

        // Ações (edit/delete) - URLs sempre com courseid/action/gsid
        $editurl = new moodle_url('/local/autogroup/edit.php', array('gsid' => $groupset->id, 'action' => 'edit', 'courseid' => $groupset->courseid));
        $deleteurl = new moodle_url('/local/autogroup/edit.php', array('gsid' => $groupset->id, 'action' => 'delete', 'courseid' => $groupset->courseid));
        $row[] =
            $this->action_icon($editurl, new pix_icon('t/edit', get_string('edit'))) .
            $this->action_icon($deleteurl, new pix_icon('t/delete', get_string('delete')));

        return $row;
    }
}
