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
    $data->code = optional_param('code', '', PARAM_ALPHANUMEXT);
    $data->description = optional_param('description', '', PARAM_CLEANHTML);
    $data->category = optional_param('category', '', PARAM_TEXT);
    $data->mandatory = optional_param('mandatory', 0, PARAM_INT);
    $data->timemodified = time();
    
    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_tm_competencies', $data);
    } else {
        $data->timecreated = time();
        $id = $DB->insert_record('local_tm_competencies', $data);
    }
    
    // Handle course link
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $expirymonths = optional_param('expirymonths', 12, PARAM_INT);
    $notifydays = optional_param('notifydays', 30, PARAM_INT);
    
    $DB->delete_records('local_tm_comp_courses', ['competencyid' => $id]);
    if ($courseid > 0) {
        $cc = new stdClass();
        $cc->competencyid = $id;
        $cc->courseid = $courseid;
        $cc->expirymonths = $expirymonths;
        $cc->notifydays = $notifydays;
        $cc->timecreated = time();
        $DB->insert_record('local_tm_comp_courses', $cc);
    }
    
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies']),
        get_string('competency_saved', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    $DB->delete_records('local_tm_comp_courses', ['competencyid' => $id]);
    $DB->delete_records('local_tm_pos_comps', ['competencyid' => $id]);
    $DB->delete_records('local_tm_competencies', ['id' => $id]);
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies']),
        get_string('competency_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo html_writer::start_div('tm-competencies tm-animate-in');

// Header with add button
echo html_writer::start_div('tm-card-header', ['style' => 'padding: 0; margin-bottom: 24px; border: none;']);
echo html_writer::tag('h3', 
    html_writer::tag('i', '', ['class' => 'fa fa-certificate mr-2']) . 
    get_string('competencies', 'local_trainingmatrix'), 
    ['class' => 'tm-card-title']
);
echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'add']),
    html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_competency', 'local_trainingmatrix'),
    ['class' => 'tm-btn tm-btn-primary']
);
echo html_writer::end_div();

// Show form if adding/editing
if ($action === 'add' || $action === 'edit') {
    $competency = $action === 'edit' ? $DB->get_record('local_tm_competencies', ['id' => $id]) : null;
    $courseLink = $action === 'edit' ? $DB->get_record('local_tm_comp_courses', ['competencyid' => $id]) : null;
    
    // Get all courses
    $courses = $DB->get_records_sql("
        SELECT c.id, c.fullname, c.shortname, cat.name as category_name
        FROM {course} c
        LEFT JOIN {course_categories} cat ON cat.id = c.category
        WHERE c.id > 1
        ORDER BY cat.name, c.fullname
    ");
    
    echo html_writer::start_div('tm-card');
    echo html_writer::start_div('tm-card-header');
    echo html_writer::tag('h4', $action === 'edit' ? get_string('edit_competency', 'local_trainingmatrix') : get_string('add_competency', 'local_trainingmatrix'), ['class' => 'tm-card-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('tm-card-body');
    
    $formUrl = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'save', 'id' => $id, 'sesskey' => sesskey()]);
    echo html_writer::start_tag('form', ['method' => 'post', 'action' => $formUrl->out(false)]);
    
    // Two column layout
    echo html_writer::start_div('', ['style' => 'display: grid; grid-template-columns: 1fr 1fr; gap: 24px;']);
    
    // Left column
    echo html_writer::start_div();
    
    // Name
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('competency_name', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'name',
        'class' => 'tm-form-input',
        'required' => 'required',
        'value' => $competency->name ?? '',
        'placeholder' => 'e.g. Work at Heights, First Aid',
    ]);
    echo html_writer::end_div();
    
    // Code
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('competency_code', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'text',
        'name' => 'code',
        'class' => 'tm-form-input',
        'value' => $competency->code ?? '',
        'placeholder' => 'e.g. WHS001, FIRST-AID',
    ]);
    echo html_writer::end_div();
    
    // Category
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('competency_category', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    $categoryOptions = [
        '' => '-- Select Category --',
        'Safety' => get_string('category_safety', 'local_trainingmatrix'),
        'Technical' => get_string('category_technical', 'local_trainingmatrix'),
        'Leadership' => get_string('category_leadership', 'local_trainingmatrix'),
        'Compliance' => get_string('category_compliance', 'local_trainingmatrix'),
        'Professional Development' => get_string('category_professional', 'local_trainingmatrix'),
        'Induction' => get_string('category_induction', 'local_trainingmatrix'),
        'Other' => get_string('category_other', 'local_trainingmatrix'),
    ];
    echo html_writer::select($categoryOptions, 'category', $competency->category ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::end_div();
    
    // Mandatory
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', '', ['class' => 'tm-form-label']);
    echo html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']);
    echo html_writer::checkbox('mandatory', 1, $competency->mandatory ?? true, '', ['id' => 'mandatory']);
    echo html_writer::tag('label', 'This competency is mandatory for compliance', ['for' => 'mandatory', 'style' => 'cursor: pointer;']);
    echo html_writer::end_div();
    echo html_writer::end_div();
    
    echo html_writer::end_div(); // Left column
    
    // Right column
    echo html_writer::start_div();
    
    // Linked Course
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('linked_course', 'local_trainingmatrix') . ' *', ['class' => 'tm-form-label']);
    $courseOptions = ['' => get_string('select_course', 'local_trainingmatrix')];
    foreach ($courses as $c) {
        $courseOptions[$c->id] = $c->fullname . ' [' . $c->shortname . ']';
    }
    echo html_writer::select($courseOptions, 'courseid', $courseLink->courseid ?? '', false, ['class' => 'tm-form-select']);
    echo html_writer::tag('div', 'The Moodle course that provides this competency', ['style' => 'color: var(--tm-gray-500); font-size: 12px; margin-top: 4px;']);
    echo html_writer::end_div();
    
    // Expiry Months
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('expiry_months', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'expirymonths',
        'class' => 'tm-form-input',
        'value' => $courseLink->expirymonths ?? 12,
        'min' => 1,
        'max' => 120,
    ]);
    echo html_writer::tag('div', get_string('tooltip_expiry_months', 'local_trainingmatrix'), ['style' => 'color: var(--tm-gray-500); font-size: 12px; margin-top: 4px;']);
    echo html_writer::end_div();
    
    // Notify Days
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', get_string('notify_days', 'local_trainingmatrix'), ['class' => 'tm-form-label']);
    echo html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'notifydays',
        'class' => 'tm-form-input',
        'value' => $courseLink->notifydays ?? 30,
        'min' => 1,
        'max' => 90,
    ]);
    echo html_writer::tag('div', get_string('tooltip_notify_days', 'local_trainingmatrix'), ['style' => 'color: var(--tm-gray-500); font-size: 12px; margin-top: 4px;']);
    echo html_writer::end_div();
    
    echo html_writer::end_div(); // Right column
    echo html_writer::end_div(); // Grid
    
    // Description (full width)
    echo html_writer::start_div('tm-form-group');
    echo html_writer::tag('label', 'Description', ['class' => 'tm-form-label']);
    echo html_writer::tag('textarea', $competency->description ?? '', [
        'name' => 'description',
        'class' => 'tm-form-textarea',
        'rows' => 3,
    ]);
    echo html_writer::end_div();
    
    // Buttons
    echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 24px;']);
    echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-primary', 'value' => 'Save Competency']);
    echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies']),
        'Cancel', ['class' => 'tm-btn tm-btn-secondary']);
    echo html_writer::end_div();
    
    echo html_writer::end_tag('form');
    echo html_writer::end_div();
    echo html_writer::end_div();
    
} else {
    // List competencies
    $competencies = $DB->get_records_sql("
        SELECT c.*, 
               cc.courseid, cc.expirymonths, cc.notifydays,
               course.fullname as course_name,
               (SELECT COUNT(*) FROM {local_tm_pos_comps} pc WHERE pc.competencyid = c.id) as position_count
        FROM {local_tm_competencies} c
        LEFT JOIN {local_tm_comp_courses} cc ON cc.competencyid = c.id
        LEFT JOIN {course} course ON course.id = cc.courseid
        ORDER BY c.category, c.name
    ");
    
    if (empty($competencies)) {
        echo html_writer::start_div('tm-empty');
        echo html_writer::tag('i', '', ['class' => 'fa fa-certificate tm-empty-icon']);
        echo html_writer::tag('div', get_string('no_competencies', 'local_trainingmatrix'), ['class' => 'tm-empty-title']);
        echo html_writer::tag('div', 'Create competencies and link them to Moodle courses for tracking.', ['class' => 'tm-empty-text']);
        echo html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'add']),
            html_writer::tag('i', '', ['class' => 'fa fa-plus mr-2']) . get_string('add_competency', 'local_trainingmatrix'),
            ['class' => 'tm-btn tm-btn-primary']
        );
        echo html_writer::end_div();
    } else {
        echo html_writer::start_div('tm-table-container');
        echo html_writer::start_tag('table', ['class' => 'tm-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        echo html_writer::tag('th', 'Competency');
        echo html_writer::tag('th', 'Category');
        echo html_writer::tag('th', 'Linked Course');
        echo html_writer::tag('th', 'Expiry');
        echo html_writer::tag('th', 'Positions');
        echo html_writer::tag('th', 'Type');
        echo html_writer::tag('th', 'Actions', ['style' => 'width: 100px;']);
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');
        
        foreach ($competencies as $comp) {
            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', 
                ($comp->code ? html_writer::tag('span', $comp->code, ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']) : '') .
                html_writer::tag('strong', format_string($comp->name))
            );
            echo html_writer::tag('td', $comp->category ?: '-');
            echo html_writer::tag('td', $comp->course_name ? 
                html_writer::link(new moodle_url('/course/view.php', ['id' => $comp->courseid]), format_string($comp->course_name), ['target' => '_blank']) : 
                html_writer::tag('span', 'Not linked', ['style' => 'color: var(--tm-danger);'])
            );
            echo html_writer::tag('td', $comp->expirymonths ? $comp->expirymonths . ' months' : '-');
            echo html_writer::tag('td', html_writer::tag('span', $comp->position_count, ['class' => 'tm-badge primary']));
            echo html_writer::tag('td', 
                $comp->mandatory ? 
                    html_writer::tag('span', 'Mandatory', ['class' => 'tm-badge danger']) : 
                    html_writer::tag('span', 'Optional', ['class' => 'tm-badge not_started'])
            );
            echo html_writer::tag('td',
                html_writer::start_div('tm-dropdown') .
                html_writer::tag('button', html_writer::tag('i', '', ['class' => 'fa fa-ellipsis-v']), ['class' => 'tm-btn tm-btn-secondary tm-btn-icon tm-btn-sm']) .
                html_writer::start_div('tm-dropdown-menu') .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'edit', 'id' => $comp->id]),
                    html_writer::tag('i', '', ['class' => 'fa fa-edit mr-2']) . 'Edit',
                    ['class' => 'tm-dropdown-item']
                ) .
                html_writer::link(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'delete', 'id' => $comp->id, 'sesskey' => sesskey()]),
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
