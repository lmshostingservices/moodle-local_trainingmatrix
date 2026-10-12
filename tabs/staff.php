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

$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$filter_dept = optional_param('dept', 0, PARAM_INT);
$filter_pos = optional_param('pos', 0, PARAM_INT);
$filter_status = optional_param('status', '', PARAM_ALPHA);
$search = optional_param('search', '', PARAM_TEXT);

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    require_capability('local/trainingmatrix:assign', $context);
    $data = new stdClass();
    $data->userid = required_param('userid', PARAM_INT);
    $data->positionid = required_param('positionid', PARAM_INT);
    $data->departmentid = optional_param('departmentid', null, PARAM_INT) ?: null;
    $data->employeeid = optional_param('employeeid', '', PARAM_ALPHANUMEXT);
    $data->managerid = optional_param('managerid', null, PARAM_INT) ?: null;
    $data->status = optional_param('status', 'active', PARAM_ALPHA);
    
    $startdate = optional_param('startdate', '', PARAM_TEXT);
    $data->startdate = $startdate ? strtotime($startdate) : null;
    
    $enddate = optional_param('enddate', '', PARAM_TEXT);
    $data->enddate = $enddate ? strtotime($enddate) : null;
    
    $data->timemodified = time();
    if (!in_array($data->status, ['active', 'inactive', 'terminated'], true)) {
        throw new invalid_parameter_exception('Invalid staff status');
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
        $DB->delete_records('local_tm_completions', ['userid' => $staff->userid]);
        $DB->delete_records('local_tm_staff', ['id' => $id]);
    }
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('item_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo html_writer::start_div('tm-staff tm-animate-in');

// Header with add button
echo html_writer::start_div('tm-card-header', ['style' => 'padding: 0; margin-bottom: 24px; border: none;']);
echo html_writer::tag('h3', 
    html_writer::tag('i', '', ['class' => 'fa fa-users mr-2']) . 
    get_string('staff', 'local_trainingmatrix'), 
    ['class' => 'tm-card-title']
);
if (has_capability('local/trainingmatrix:assign', $context)) {
    echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff', 'action' => 'add']),
        html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_staff', 'local_trainingmatrix'),
        ['class' => 'tm-btn tm-btn-primary']
    );
}
echo html_writer::end_div();

// Show form if adding/editing
if (($action === 'add' || $action === 'edit') && has_capability('local/trainingmatrix:assign', $context)) {
    $staff = $action === 'edit' ? $DB->get_record('local_tm_staff', ['id' => $id]) : null;
    $departments = $DB->get_records('local_tm_departments', null, 'name', 'id, name');
    $positions = $DB->get_records('local_tm_positions', null, 'name', 'id, name, departmentid');
    $users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE deleted = 0 AND suspended = 0 ORDER BY lastname, firstname LIMIT 1000");
    
    echo html_writer::start_div('tm-card');
    echo html_writer::start_div('tm-card-header');
    echo html_writer::tag('h4', $action === 'edit' ? get_string('edit_staff', 'local_trainingmatrix') : get_string('add_staff', 'local_trainingmatrix'), ['class' => 'tm-card-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('tm-card-body');
    
    $formUrl = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff', 'action' => 'save', 'id' => $id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formUrl->out(false)]);
    
    // Two column layout
    echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 24px;']);
    
    // Left column
    echo html_writer::start_div();
    
    // User selection
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('staff_member', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    $userOptions = ['' => get_string('select_user', 'local_trainingmatrix')];
    foreach ($users as $u) {
        $userOptions[$u->id] = fullname($u) . ' (' . $u->email . ')';
    }
    echo html_writer::select($userOptions, 'userid', $staff->userid ?? '', false, ['class' => 'tm-form-select', 'required' => 'required']);
    echo html_writer::end_div();
    
    // Position
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('position_name', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    $posOptions = ['' => get_string('select_position', 'local_trainingmatrix')];
    foreach ($positions as $p) {
        $posOptions[$p->id] = $p->name;
    }
    echo html_writer::select($posOptions, 'positionid', $staff->positionid ?? '', false, ['class' => 'tm-form-select', 'required' => 'required']);
    echo html_writer::end_div();
    
    // Department
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('departments', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $deptOptions = ['' => get_string('select_department', 'local_trainingmatrix')];
    foreach ($departments as $d) {
        $deptOptions[$d->id] = $d->name;
    }
    echo html_writer::select($deptOptions, 'departmentid', $staff->departmentid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Employee ID
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('employee_id', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'employeeid',
        'class' => 'tm-form-input',
        'value' => $staff->employeeid ?? '',
        'placeholder' => 'e.g. EMP001',
    ]);
    echo html_writer::end_div();
    
    echo html_writer::end_div(); // Left column
    
    // Right column
    echo html_writer::start_div();
    
    // Start Date
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('start_date', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'date',
        'name' => 'startdate',
        'class' => 'tm-form-input',
        'value' => $staff->startdate ? date('Y-m-d', $staff->startdate) : '',
    ]);
    echo html_writer::end_div();
    
    // End Date
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('end_date', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'date',
        'name' => 'enddate',
        'class' => 'tm-form-input',
        'value' => $staff->enddate ? date('Y-m-d', $staff->enddate) : '',
    ]);
    echo html_writer::end_div();
    
    // Manager
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('direct_manager', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::select($userOptions, 'managerid', $staff->managerid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Status
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('staff_status', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $statusOptions = [
        'active' => get_string('status_active', 'local_trainingmatrix'),
        'inactive' => get_string('status_inactive', 'local_trainingmatrix'),
        'terminated' => get_string('status_terminated', 'local_trainingmatrix'),
    ];
    echo html_writer::select($statusOptions, 'status', $staff->status ?? 'active', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    echo html_writer::end_div(); // Right column
    echo html_writer::end_div(); // Grid
    
    // Buttons
    echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 24px;']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-primary', 'value' => 'Save Staff Member']);
    echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        'Cancel', ['class' => 'tm-btn tm-btn-secondary']);
    echo html_writer::end_div();
    
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
    
} else {
    // Filters
    $departments = $DB->get_records('local_tm_departments', null, 'name', 'id, name');
    $positions = $DB->get_records('local_tm_positions', null, 'name', 'id, name');
    
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => new moodle_url('/local/trainingmatrix/index.php')]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'tab', 'value' => 'staff']);
    
    echo html_writer::start_div('tm-filters');
    
    // Search
    echo html_writer::start_div('tm-search');
    echo html_writer::tag('i', '', ['class' => 'fa fa-search tm-search-icon']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'search',
        'class' => 'tm-search-input',
        'value' => $search,
        'placeholder' => 'Search staff...',
    ]);
    echo html_writer::end_div();
    
    // Department filter
    $deptOptions = ['' => get_string('all_departments', 'local_trainingmatrix')];
    foreach ($departments as $d) {
        $deptOptions[$d->id] = $d->name;
    }
    echo html_writer::select($deptOptions, 'dept', $filter_dept, false, ['class' => 'tm-filter-select']);
    
    // Position filter
    $posOptions = ['' => get_string('all_positions', 'local_trainingmatrix')];
    foreach ($positions as $p) {
        $posOptions[$p->id] = $p->name;
    }
    echo html_writer::select($posOptions, 'pos', $filter_pos, false, ['class' => 'tm-filter-select']);
    
    // Status filter
    $statusOptions = [
        '' => get_string('all_statuses', 'local_trainingmatrix'),
        'active' => get_string('status_active', 'local_trainingmatrix'),
        'inactive' => get_string('status_inactive', 'local_trainingmatrix'),
    ];
    echo html_writer::select($statusOptions, 'status', $filter_status, false, ['class' => 'tm-filter-select']);
    
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-secondary', 'value' => 'Filter']);
    
    echo html_writer::end_div();
    echo html_writer::end_tag('form');
    
    // Build query
    $params = [];
    $where = ['s.status != ?'];
    $params[] = 'deleted';
    
    if ($filter_dept) {
        $where[] = 's.departmentid = ?';
        $params[] = $filter_dept;
    }
    if ($filter_pos) {
        $where[] = 's.positionid = ?';
        $params[] = $filter_pos;
    }
    if ($filter_status) {
        $where[] = 's.status = ?';
        $params[] = $filter_status;
    }
    if ($search) {
        $where[] = "(u.firstname LIKE ? OR u.lastname LIKE ? OR u.email LIKE ? OR s.employeeid LIKE ?)";
        $searchWild = '%' . $search . '%';
        $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild]);
    }
    
    $whereClause = implode(' AND ', $where);
    
    $staffList = $DB->get_records_sql("
        SELECT s.*, 
               u.firstname, u.lastname, u.email,
               p.name as position_name,
               d.name as department_name,
               CONCAT(m.firstname, ' ', m.lastname) as manager_name
        FROM {local_tm_staff} s
        JOIN {user} u ON u.id = s.userid
        LEFT JOIN {local_tm_positions} p ON p.id = s.positionid
        LEFT JOIN {local_tm_departments} d ON d.id = s.departmentid
        LEFT JOIN {user} m ON m.id = s.managerid
        WHERE $whereClause
        ORDER BY u.lastname, u.firstname
    ", $params);
    
    if (empty($staffList)) {
        echo html_writer::start_div('tm-empty');
        echo html_writer::tag('i', '', ['class' => 'fa fa-users tm-empty-icon']);
        echo html_writer::tag('div', get_string('no_staff', 'local_trainingmatrix'), ['class' => 'tm-empty-title']);
        echo html_writer::tag('div', 'Assign staff members to positions to track their training compliance.', ['class' => 'tm-empty-text']);
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('tm-table-container');
        echo html_writer::start_tag('table', ['class' => 'tm-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Staff Member');
        echo html_writer::tag('th', 'Position');
        echo html_writer::tag('th', 'Department');
        echo html_writer::tag('th', 'Start Date');
        echo html_writer::tag('th', 'Compliance');
        echo html_writer::tag('th', 'Status');
        echo html_writer::tag('th', 'Actions', ['style' => 'width: 100px;']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($staffList as $staff) {
            $compliance = local_trainingmatrix_get_staff_compliance($staff->userid);
            $compRate = $compliance ? $compliance['stats']['compliance_rate'] : 0;
            $progressClass = $compRate >= 100 ? 'success' : ($compRate >= 70 ? 'warning' : 'danger');
            
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                html_writer::tag('strong', $staff->firstname . ' ' . $staff->lastname) .
                html_writer::tag('div', $staff->email, ['style' => 'color: var(--tm-gray-500); font-size: 12px;']) .
                ($staff->employeeid ? html_writer::tag('div', 'ID: ' . $staff->employeeid, ['style' => 'color: var(--tm-gray-400); font-size: 11px;']) : '')
            );
            echo html_writer::tag('td', $staff->position_name ?: '-');
            echo html_writer::tag('td', $staff->department_name ?: '-');
            echo html_writer::tag('td', $staff->startdate ? date('d M Y', $staff->startdate) : '-');
            echo html_writer::tag('td',
                html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']) .
                html_writer::start_div('tm-progress') .
                html_writer::div('', 'tm-progress-bar ' . $progressClass, ['style' => 'width: ' . $compRate . '%;']) .
                html_writer::end_div() .
                html_writer::tag('span', $compRate . '%', ['class' => 'tm-progress-label']) .
                html_writer::end_div()
            );
            
            $statusBadge = 'active';
            if ($staff->status === 'inactive') $statusBadge = 'inactive';
            if ($staff->status === 'terminated') $statusBadge = 'danger';
            echo html_writer::tag('td', html_writer::tag('span', ucfirst($staff->status), ['class' => 'tm-badge ' . $statusBadge]));
            
            echo html_writer::tag('td',
                html_writer::start_div('tm-dropdown') .
                html_writer::tag('button', html_writer::tag('i', '', ['class' => 'fa fa-ellipsis-v']), ['class' => 'tm-btn tm-btn-secondary tm-btn-icon tm-btn-sm']) .
                html_writer::start_div('tm-dropdown-menu') .
                html_writer::link(new moodle_url('/local/trainingmatrix/staff.php', ['id' => $staff->id]),
                    html_writer::tag('i', '', ['class' => 'fa fa-eye mr-2']) . 'View Details',
                    ['class' => 'tm-dropdown-item']
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff', 'action' => 'edit', 'id' => $staff->id]),
                    html_writer::tag('i', '', ['class' => 'fa fa-edit mr-2']) . 'Edit',
                    ['class' => 'tm-dropdown-item']
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff', 'action' => 'delete', 'id' => $staff->id, 'sesskey' => sesskey()]),
                    html_writer::tag('i', '', ['class' => 'fa fa-trash mr-2']) . 'Delete',
                    ['class' => 'tm-dropdown-item danger', 'onclick' => "return confirm('Are you sure?');"]
                ) .
                html_writer::end_div() .
                html_writer::end_div()
            );
            echo html_writer::end_tag('tr');
        }
        
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_div();
    }
}

echo html_writer::end_div();
