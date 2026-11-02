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

// Form actions for the staff tab. Included by index.php before any output so redirect() works.

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    require_capability('local/trainingmatrix:assign', $context);
    $data = new stdClass();
    $data->userid = required_param('userid', PARAM_INT);
    $data->positionid = required_param('positionid', PARAM_INT);
    $data->departmentid = optional_param('departmentid', 0, PARAM_INT) ?: null;
    $data->employeeid = optional_param('employeeid', '', PARAM_ALPHANUMEXT);
    $data->managerid = optional_param('managerid', 0, PARAM_INT) ?: null;
    $data->status = optional_param('status', 'active', PARAM_ALPHA);
    
    $startdate = optional_param('startdate', '', PARAM_TEXT);
    $data->startdate = $startdate ? (strtotime($startdate) ?: null) : null;
    
    $enddate = optional_param('enddate', '', PARAM_TEXT);
    $data->enddate = $enddate ? (strtotime($enddate) ?: null) : null;
    
    $data->timemodified = time();
    if (!in_array($data->status, ['active', 'inactive', 'terminated'], true)) {
        throw new invalid_parameter_exception('Invalid staff status');
    }
    if ($data->status === 'active') {
        $duplicate = $DB->record_exists_select('local_tm_staff', 'userid = :userid AND status = :status AND id <> :id',
            ['userid' => $data->userid, 'status' => 'active', 'id' => $id]);
        if ($duplicate) {
            redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
                get_string('staff_duplicateactive', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_ERROR);
        }
    }
    $DB->get_record('local_tm_positions', ['id' => $data->positionid], '*', MUST_EXIST);
    $DB->get_record('user', ['id' => $data->userid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
    $transaction = $DB->start_delegated_transaction();
    
    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_tm_staff', $data);
    } else {
        $data->timecreated = time();
        $id = $DB->insert_record('local_tm_staff', $data);
    }
    if ($data->status === 'active') {
        local_trainingmatrix_assign_required_courses((int)$data->userid, (int)$data->positionid);
    }
    $transaction->allow_commit();
    
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('staff_saved', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    require_capability('local/trainingmatrix:assign', $context);
    $staff = $DB->get_record('local_tm_staff', ['id' => $id]);
    if ($staff) {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_tm_staff', ['id' => $id]);
        // Only drop tracking rows once the person has no remaining staff record.
        if (!$DB->record_exists('local_tm_staff', ['userid' => $staff->userid])) {
            $DB->delete_records('local_tm_notifications', ['userid' => $staff->userid]);
            $DB->delete_records('local_tm_completions', ['userid' => $staff->userid]);
        }
        $transaction->allow_commit();
    }
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('item_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}
