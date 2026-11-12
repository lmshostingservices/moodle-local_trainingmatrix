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
 * local_trainingmatrix file.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Form actions for the departments tab. Included by index.php before any output so redirect() works.

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->name = required_param('name', PARAM_TEXT);
    $data->description = optional_param('description', '', PARAM_CLEANHTML);
    $data->parentid = optional_param('parentid', 0, PARAM_INT) ?: null;
    if ($data->parentid && ($data->parentid == $id || !$DB->record_exists('local_tm_departments', ['id' => $data->parentid]))) {
        $data->parentid = null;
    }
    $data->managerid = optional_param('managerid', 0, PARAM_INT) ?: null;
    $data->sortorder = optional_param('sortorder', 0, PARAM_INT);
    $data->timemodified = time();
    // Client: shared (0) or one client's own. Kept as-is when no Client portal clients exist.
    $existingclient = $id > 0 ? (int)$DB->get_field('local_tm_departments', 'clientid', ['id' => $id]) : 0;
    $data->clientid = \local_trainingmatrix\local\tenancy::clients() ?
        optional_param('clientid', 0, PARAM_INT) : $existingclient;
    $conflict = local_trainingmatrix_definition_conflict('department', $id, (int)$data->clientid,
        ['parentid' => $data->parentid]);
    if ($conflict) {
        redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => $id ? 'edit' : 'add', 'id' => $id]),
            $conflict, null, \core\output\notification::NOTIFY_ERROR);
    }
    
    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_tm_departments', $data);
    } else {
        $data->timecreated = time();
        $DB->insert_record('local_tm_departments', $data);
    }
    
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments']),
        get_string('department_saved', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    $transaction = $DB->start_delegated_transaction();
    // Detach dependants so no record points at a deleted department.
    $DB->set_field('local_tm_departments', 'parentid', null, ['parentid' => $id]);
    $DB->set_field('local_tm_positions', 'departmentid', null, ['departmentid' => $id]);
    $DB->set_field('local_tm_staff', 'departmentid', null, ['departmentid' => $id]);
    $DB->delete_records('local_tm_departments', ['id' => $id]);
    $transaction->allow_commit();
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments']),
        get_string('department_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}
