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
 * Edit and create autogroup set
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__.'/../../config.php');
require_once($CFG->dirroot.'/local/autogroup/locallib.php');
require_once($CFG->dirroot.'/local/autogroup/classes/form/autogroup_set_settings.php');
require_once($CFG->dirroot.'/local/autogroup/classes/form/autogroup_set_delete.php');
require_once($CFG->dirroot.'/local/autogroup/classes/domain/autogroup_set.php');
require_once($CFG->dirroot.'/local/autogroup/renderer.php');

use local_autogroup\domain;
use local_autogroup\form;

$action   = required_param('action', PARAM_ALPHA); // add|edit|delete
$courseid = required_param('courseid', PARAM_INT);
$sortmodule = optional_param('sortmodule', '', PARAM_TEXT);
$gsid     = optional_param('gsid', 0, PARAM_INT);

require_login($courseid);

$context = context_course::instance($courseid);
require_capability('moodle/course:managegroups', $context);

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/autogroup/edit.php', array('action' => $action, 'courseid' => $courseid, 'sortmodule' => $sortmodule, 'gsid' => $gsid)));
$PAGE->set_title(get_string('coursesettings', 'local_autogroup'));
$PAGE->set_heading(get_string('coursesettings', 'local_autogroup'));
$PAGE->set_pagelayout('admin');

$groupset = null;
if ($gsid) {
    $setrecord = $DB->get_record('local_autogroup_set', array('id' => $gsid), '*', MUST_EXIST);
    $groupset = new domain\autogroup_set($DB, $setrecord);
} else {
    $groupset = new domain\autogroup_set($DB);
    $groupset->set_course($courseid);
    if ($sortmodule) {
        $groupset->set_sort_module($sortmodule);
    }
}

// Parâmetros de retorno para as ações, sempre mantendo todos os contextos.
$returnparams = array(
    'courseid' => $courseid,
    'sortmodule' => $sortmodule,
);

if ($gsid) {
    $returnparams['gsid'] = $gsid;
}

// Ajuste: define corretamente o action para o returnurl
if ($action == 'add') {
    $returnparams['action'] = 'add';
} elseif ($action == 'edit') {
    $returnparams['action'] = 'edit';
} elseif ($action == 'delete') {
    $returnparams['action'] = 'delete';
} else {
    // fallback
    $returnparams['action'] = $action;
}

$returnurl = new moodle_url(local_autogroup_renderer::URL_COURSE_SETTINGS, $returnparams);
$aborturl = new moodle_url(local_autogroup_renderer::URL_COURSE_MANAGE, array('courseid' => $courseid));

if ($action == 'delete') {
    $form = new form\autogroup_set_delete($returnurl, $groupset);
} else {
    $form = new form\autogroup_set_settings($returnurl, $groupset);
}

if ($form->is_cancelled()) {
    redirect($aborturl);
}

if ($data = $form->get_data()) {

    // Salva o nome personalizado do grupo no objeto groupset, se informado.
    if (isset($data->customgroupname)) {
        $groupset->customgroupname = trim($data->customgroupname);
    }

    $updategroupmembership = false;
    $cleanupold = isset($data->cleanupold) ? (bool)$data->cleanupold : true;

    if ($action == 'delete') {
        $groupset->delete($DB, $cleanupold);

        $groupset = new domain\autogroup_set($DB);
        $groupset->set_course($courseid);

        $updategroupmembership = true;
    } else {
        $updated = false;
        $options = new stdClass();
        if (!empty($data->groupby) && $data->groupby != $groupset->grouping_by()) {
            $options->field = $data->groupby;
            $updated = true;
        }
        if (!empty($data->delimitedby) && $data->delimitedby != $groupset->delimited_by()) {
            $options->delimiter = $data->delimitedby;
            $updated = true;
        }
        if (isset($data->groupby_filtervalue)) {
            $options->filtervalue = trim($data->groupby_filtervalue);
            $updated = true;
        }

        if ($updated || $action == 'add') {
            // Ao criar ou atualizar opções.
            $groupset->set_options($options);
            $groupset->save($DB, $cleanupold);
            $updategroupmembership = true;
        }

        // Check for role settings.
        if ($groupset->exists() && $roles = \get_all_roles()) {
            $roles = \role_fix_names($roles, null, ROLENAME_ORIGINAL);
            $newroles = array();
            foreach ($roles as $role) {
                $fieldname = 'role_' . $role->id;
                if (!empty($data->$fieldname)) {
                    $newroles[] = $role->id;
                }
            }
            $groupset->set_eligible_roles($newroles);
            $groupset->save($DB, $cleanupold);
        }
    }

    // Processa os alunos já inscritos no curso para criar/atualizar os grupos!
    $context = context_course::instance($courseid);
    $enrolledusers = get_enrolled_users($context, '', 0, 'u.*');
    foreach ($enrolledusers as $user) {
        $groupset->verify_user_group_membership($user, $DB, $context);
    }

    // Redireciona para a página de gerenciamento com mensagem de sucesso!
    redirect($aborturl, get_string('changessaved'), 2);
}

echo $OUTPUT->header();
if ($action == 'delete') {
    echo $OUTPUT->heading(get_string('delete'));
} elseif ($action == 'add') {
    echo $OUTPUT->heading(get_string('coursesettingsaddtitle', 'local_autogroup', $courseid));
} else {
    echo $OUTPUT->heading(get_string('coursesettingstitle', 'local_autogroup', $courseid));
}
$form->display();
echo $OUTPUT->footer();
