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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Form actions for the staff tab. Included by index.php before any output so redirect() works.

// Handle form submissions.
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
        $duplicate = $DB->record_exists_select(
            'local_trainingmatrix_staff',
            'userid = :userid AND status = :status AND id <> :id',
            ['userid' => $data->userid, 'status' => 'active', 'id' => $id]
        );
        if ($duplicate) {
            redirect(
                new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
                get_string('staff_duplicateactive', 'local_trainingmatrix'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
    }
    $position = $DB->get_record('local_trainingmatrix_pos', ['id' => $data->positionid], '*', MUST_EXIST);
    $department = $data->departmentid ?
        $DB->get_record('local_trainingmatrix_dept', ['id' => $data->departmentid], '*', MUST_EXIST) : null;
    $DB->get_record('user', ['id' => $data->userid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
    $existing = $id > 0 ? $DB->get_record('local_trainingmatrix_staff', ['id' => $id], '*', MUST_EXIST) : null;
    if ($existing && !$accessscope->contains_staff($existing)) {
        throw new required_capability_exception($context, 'local/trainingmatrix:viewall', 'nopermissions', '');
    }

    // Client: admins choose; otherwise keep the record's client. Either way a client-owned position or department
    // decides it when none was given, and every definition used must be shared or belong to that client.
    $tenancyon = (bool)\local_trainingmatrix\local\tenancy::clients();
    if ($tenancyon && $canviewall) {
        $data->clientid = optional_param('clientid', 0, PARAM_INT);
    } else {
        $data->clientid = $existing ? (int)$existing->clientid : 0;
    }
    if (!$data->clientid) {
        $data->clientid = (int)$position->clientid ?: ($department ? (int)$department->clientid : 0);
    }
    $staffreturn = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']);
    if ($data->clientid && !\local_trainingmatrix\local\tenancy::exists($data->clientid)) {
        redirect(
            $staffreturn,
            get_string('client_invalid', 'local_trainingmatrix'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (
        !\local_trainingmatrix\local\tenancy::fits((int)$position->clientid, $data->clientid) ||
            ($department && !\local_trainingmatrix\local\tenancy::fits((int)$department->clientid, $data->clientid))
    ) {
        redirect(
            $staffreturn,
            get_string('client_mismatch', 'local_trainingmatrix'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (!\local_trainingmatrix\local\tenancy::is_learner($data->clientid, $data->userid)) {
        redirect($staffreturn, get_string(
            'client_notlearner',
            'local_trainingmatrix',
            \local_trainingmatrix\local\tenancy::name($data->clientid)
        ), null, \core\output\notification::NOTIFY_ERROR);
    }
    // A line manager may only save records that stay inside their own team.
    if (!$accessscope->contains_staff($data)) {
        redirect(
            $staffreturn,
            get_string('staff_outsidescope', 'local_trainingmatrix'),
            null,
            \core\output\notification::NOTIFY_ERROR
        );
    }
    $transaction = $DB->start_delegated_transaction();

    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_trainingmatrix_staff', $data);
    } else {
        $data->timecreated = time();
        $id = $DB->insert_record('local_trainingmatrix_staff', $data);
    }
    if ($data->status === 'active') {
        local_trainingmatrix_assign_required_courses((int)$data->userid, (int)$data->positionid);
    }
    $transaction->allow_commit();

    redirect(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('staff_saved', 'local_trainingmatrix'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    require_capability('local/trainingmatrix:assign', $context);
    $staff = $DB->get_record('local_trainingmatrix_staff', ['id' => $id]);
    if ($staff && !$accessscope->contains_staff($staff)) {
        throw new required_capability_exception($context, 'local/trainingmatrix:viewall', 'nopermissions', '');
    }
    if ($staff) {
        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('local_trainingmatrix_staff', ['id' => $id]);
        // Only drop tracking rows once the person has no remaining staff record.
        if (!$DB->record_exists('local_trainingmatrix_staff', ['userid' => $staff->userid])) {
            $DB->delete_records('local_trainingmatrix_notif', ['userid' => $staff->userid]);
            $DB->delete_records('local_trainingmatrix_compl', ['userid' => $staff->userid]);
        }
        $transaction->allow_commit();
    }
    redirect(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('item_deleted', 'local_trainingmatrix'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}
