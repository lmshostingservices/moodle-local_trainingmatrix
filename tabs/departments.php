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

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->name = required_param('name', PARAM_TEXT);
    $data->description = optional_param('description', '', PARAM_RAW);
    $data->parentid = optional_param('parentid', null, PARAM_INT) ?: null;
    $data->managerid = optional_param('managerid', null, PARAM_INT) ?: null;
    $data->sortorder = optional_param('sortorder', 0, PARAM_INT);
    $data->timemodified = time();
    
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
    $DB->delete_records('local_tm_departments', ['id' => $id]);
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments']),
        get_string('department_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo html_writer::start_div('tm-departments tm-animate-in');

// Header with add button
echo html_writer::start_div('tm-card-header', ['style' => 'padding: 0; margin-bottom: 24px; border: none;']);
echo html_writer::tag('h3', 
    html_writer::tag('i', '', ['class' => 'fa fa-building mr-2']) . 
    get_string('departments', 'local_trainingmatrix'), 
    ['class' => 'tm-card-title']
);
echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'add']),
    html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_department', 'local_trainingmatrix'),
    ['class' => 'tm-btn tm-btn-primary']
);
echo html_writer::end_div();

// Show form if adding/editing
if ($action === 'add' || $action === 'edit') {
    $department = $action === 'edit' ? $DB->get_record('local_tm_departments', ['id' => $id]) : null;
    $allDepartments = $DB->get_records('local_tm_departments', null, 'name', 'id, name');
    
    echo html_writer::start_div('tm-card');
    echo html_writer::start_div('tm-card-header');
    echo html_writer::tag('h4', $action === 'edit' ? get_string('edit_department', 'local_trainingmatrix') : get_string('add_department', 'local_trainingmatrix'), ['class' => 'tm-card-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('tm-card-body');
    
    $formUrl = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'save', 'id' => $id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formUrl->out(false)]);
    
    // Name
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('department_name', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'name',
        'class' => 'tm-form-input',
        'required' => 'required',
        'value' => $department->name ?? '',
    ]);
    echo html_writer::end_div();
    
    // Description
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', 'Description', ['class' => 'tm-form-label']);
    echo html_writer::tag('textarea', $department->description ?? '', [
        'name' => 'description',
        'class' => 'tm-form-textarea',
        'rows' => 3,
    ]);
    echo html_writer::end_div();
    
    // Parent Department
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('parent_department', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $parentOptions = ['' => '-- None --'];
    foreach ($allDepartments as $d) {
        if ($d->id != $id) {
            $parentOptions[$d->id] = $d->name;
        }
    }
    echo html_writer::select($parentOptions, 'parentid', $department->parentid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Manager
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('department_manager', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $users = $DB->get_records_sql("SELECT id, firstname, lastname, email FROM {user} WHERE deleted = 0 AND suspended = 0 ORDER BY lastname, firstname LIMIT 500");
    $userOptions = ['' => '-- Select Manager --'];
    foreach ($users as $u) {
        $userOptions[$u->id] = fullname($u) . ' (' . $u->email . ')';
    }
    echo html_writer::select($userOptions, 'managerid', $department->managerid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Sort Order
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', 'Sort Order', ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'sortorder',
        'class' => 'tm-form-input',
        'value' => $department->sortorder ?? 0,
        'min' => 0,
    ]);
    echo html_writer::end_div();
    
    // Buttons
    echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 24px;']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-primary', 'value' => 'Save Department']);
    echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments']),
        'Cancel', ['class' => 'tm-btn tm-btn-secondary']);
    echo html_writer::end_div();
    
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
    
} else {
    // List departments
    $departments = $DB->get_records_sql("
        SELECT d.*, 
               pd.name as parent_name,
               CONCAT(m.firstname, ' ', m.lastname) as manager_name,
               (SELECT COUNT(*) FROM {local_tm_staff} s WHERE s.departmentid = d.id AND s.status = 'active') as staff_count,
               (SELECT COUNT(*) FROM {local_tm_positions} p WHERE p.departmentid = d.id) as position_count
        FROM {local_tm_departments} d
        LEFT JOIN {local_tm_departments} pd ON pd.id = d.parentid
        LEFT JOIN {user} m ON m.id = d.managerid
        ORDER BY d.sortorder, d.name
    ");
    
    if (empty($departments)) {
        echo html_writer::start_div('tm-empty');
        echo html_writer::tag('i', '', ['class' => 'fa fa-building tm-empty-icon']);
        echo html_writer::tag('div', get_string('no_departments', 'local_trainingmatrix'), ['class' => 'tm-empty-title']);
        echo html_writer::tag('div', 'Create your first department to start organising your staff.', ['class' => 'tm-empty-text']);
        echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'add']),
            html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_department', 'local_trainingmatrix'),
            ['class' => 'tm-btn tm-btn-primary']
        );
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('tm-table-container');
        echo html_writer::start_tag('table', ['class' => 'tm-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Department');
        echo html_writer::tag('th', 'Parent');
        echo html_writer::tag('th', 'Manager');
        echo html_writer::tag('th', 'Positions');
        echo html_writer::tag('th', 'Staff');
        echo html_writer::tag('th', 'Actions', ['style' => 'width: 100px;']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($departments as $dept) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                html_writer::tag('strong', format_string($dept->name)) .
                ($dept->description ? html_writer::tag('div', format_string(substr($dept->description, 0, 60)) . (strlen($dept->description) > 60 ? '...' : ''), ['style' => 'color: var(--tm-gray-500); font-size: 12px;']) : '')
            );
            echo html_writer::tag('td', $dept->parent_name ?: '-');
            echo html_writer::tag('td', $dept->manager_name ?: '-');
            echo html_writer::tag('td', html_writer::tag('span', $dept->position_count, ['class' => 'tm-badge info']));
            echo html_writer::tag('td', html_writer::tag('span', $dept->staff_count, ['class' => 'tm-badge primary']));
            echo html_writer::tag('td',
                html_writer::start_div('tm-dropdown') .
                html_writer::tag('button', html_writer::tag('i', '', ['class' => 'fa fa-ellipsis-v']), ['class' => 'tm-btn tm-btn-secondary tm-btn-icon tm-btn-sm']) .
                html_writer::start_div('tm-dropdown-menu') .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'edit', 'id' => $dept->id]),
                    html_writer::tag('i', '', ['class' => 'fa fa-edit mr-2']) . 'Edit',
                    ['class' => 'tm-dropdown-item']
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'delete', 'id' => $dept->id, 'sesskey' => sesskey()]),
                    html_writer::tag('i', '', ['class' => 'fa fa-trash mr-2']) . 'Delete',
                    ['class' => 'tm-dropdown-item danger', 'onclick' => "return confirm('Are you sure you want to delete this department?');"]
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
