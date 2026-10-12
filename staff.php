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

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once(__DIR__ . '/lib.php');

$id = required_param('id', PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:view', $context);

$staff = $DB->get_record('local_tm_staff', ['id' => $id], '*', MUST_EXIST);
if ((int)$staff->userid !== (int)$USER->id) {
    require_capability('local/trainingmatrix:viewall', $context);
}
$user = $DB->get_record('user', ['id' => $staff->userid], '*', MUST_EXIST);
$position = $DB->get_record('local_tm_positions', ['id' => $staff->positionid]);
$department = $staff->departmentid ? $DB->get_record('local_tm_departments', ['id' => $staff->departmentid]) : null;

$PAGE->set_url('/local/trainingmatrix/staff.php', ['id' => $id]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(fullname($user) . ' - AI Training Matrix');
$PAGE->set_heading(fullname($user) . ' - Training Progress');

$PAGE->requires->css('/local/trainingmatrix/styles.css');

$compliance = local_trainingmatrix_get_staff_compliance($staff->userid);

echo $OUTPUT->header();

echo html_writer::start_div('trainingmatrix-container tm-animate-in');

// Back link
echo html_writer::div(
    html_writer::link(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        html_writer::tag('i', '', ['class' => 'fa fa-arrow-left mr-2']) . 'Back to Staff List',
        ['class' => 'tm-btn tm-btn-secondary tm-btn-sm']
    ),
    'mb-4'
);

// Staff header card
echo html_writer::start_div('tm-card');
echo html_writer::start_div('tm-card-body');
echo html_writer::start_div('', ['style' => 'display: flex; gap: 24px; align-items: flex-start; flex-wrap: wrap;']);

// Avatar and basic info
echo html_writer::start_div('', ['style' => 'flex: 1; min-width: 250px;']);
echo html_writer::start_div('', ['style' => 'display: flex; gap: 16px; align-items: center;']);
echo $OUTPUT->user_picture($user, ['size' => 80, 'class' => 'rounded-circle']);
echo html_writer::start_div();
echo html_writer::tag('h2', fullname($user), ['style' => 'margin: 0 0 4px;']);
echo html_writer::tag('div', $user->email, ['style' => 'color: var(--tm-gray-500);']);
if ($staff->employeeid) {
    echo html_writer::tag('div', 'Employee ID: ' . $staff->employeeid, ['style' => 'color: var(--tm-gray-400); font-size: 13px;']);
}
echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::start_div('', ['style' => 'margin-top: 16px; display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;']);
echo html_writer::tag('div', 
    html_writer::tag('span', 'Position: ', ['style' => 'color: var(--tm-gray-500);']) . 
    html_writer::tag('strong', $position->name ?? 'Not assigned')
);
echo html_writer::tag('div', 
    html_writer::tag('span', 'Department: ', ['style' => 'color: var(--tm-gray-500);']) . 
    html_writer::tag('strong', $department->name ?? 'Not assigned')
);
echo html_writer::tag('div', 
    html_writer::tag('span', 'Start Date: ', ['style' => 'color: var(--tm-gray-500);']) . 
    html_writer::tag('strong', $staff->startdate ? date('d M Y', $staff->startdate) : 'Not set')
);
echo html_writer::tag('div', 
    html_writer::tag('span', 'Status: ', ['style' => 'color: var(--tm-gray-500);']) . 
    html_writer::tag('span', ucfirst($staff->status), ['class' => 'tm-badge ' . ($staff->status === 'active' ? 'active' : 'inactive')])
);
echo html_writer::end_div();
echo html_writer::end_div();

// Compliance meter
if ($compliance) {
    $stats = $compliance['stats'];
    $compRate = $stats['compliance_rate'];
    $circumference = 2 * 3.14159 * 45;
    $offset = $circumference - ($compRate / 100 * $circumference);
    $color = $compRate >= 100 ? 'var(--tm-success)' : ($compRate >= 70 ? 'var(--tm-warning)' : 'var(--tm-danger)');
    
    echo html_writer::start_div('', ['style' => 'text-align: center;']);
    echo html_writer::start_div('tm-compliance-ring');
    echo '<svg width="120" height="120" viewBox="0 0 120 120">
        <circle class="bg" cx="60" cy="60" r="45" stroke="var(--tm-gray-200)"/>
        <circle class="progress" cx="60" cy="60" r="45" stroke="' . $color . '" 
            stroke-dasharray="' . $circumference . '" stroke-dashoffset="' . $offset . '"/>
    </svg>';
    echo html_writer::tag('div', $compRate . '%', ['class' => 'tm-compliance-value']);
    echo html_writer::end_div();
    echo html_writer::tag('div', 'Compliance Rate', ['style' => 'color: var(--tm-gray-500); font-size: 14px; margin-top: 8px;']);
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();
echo html_writer::end_div();

// Stats cards
if ($compliance) {
    $stats = $compliance['stats'];
    
    echo html_writer::start_div('tm-stats-grid', ['style' => 'margin-top: 24px;']);
    
    echo html_writer::start_div('tm-stat-card info');
    echo html_writer::tag('div', $stats['total'], ['class' => 'tm-stat-value']);
    echo html_writer::tag('div', 'Total Required', ['class' => 'tm-stat-label']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('tm-stat-card success');
    echo html_writer::tag('div', $stats['completed'], ['class' => 'tm-stat-value']);
    echo html_writer::tag('div', 'Completed', ['class' => 'tm-stat-label']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('tm-stat-card warning');
    echo html_writer::tag('div', $stats['expiring_soon'], ['class' => 'tm-stat-value']);
    echo html_writer::tag('div', 'Expiring Soon', ['class' => 'tm-stat-label']);
    echo html_writer::end_div();
    
    echo html_writer::start_div('tm-stat-card danger');
    echo html_writer::tag('div', $stats['expired'], ['class' => 'tm-stat-value']);
    echo html_writer::tag('div', 'Expired', ['class' => 'tm-stat-label']);
    echo html_writer::end_div();
    
    echo html_writer::end_div();
}

// Competencies list
if ($compliance && !empty($compliance['competencies'])) {
    echo html_writer::start_div('tm-card', ['style' => 'margin-top: 24px;']);
    echo html_writer::start_div('tm-card-header');
    echo html_writer::tag('h4', 'Required Competencies', ['class' => 'tm-card-title']);
    echo html_writer::end_div();
    echo html_writer::start_div('tm-card-body', ['style' => 'padding: 0;']);
    
    echo html_writer::start_div('tm-table-container', ['style' => 'border: none;']);
    echo html_writer::start_tag('table', ['class' => 'tm-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Competency');
    echo html_writer::tag('th', 'Category');
    echo html_writer::tag('th', 'Progress');
    echo html_writer::tag('th', 'Completion Date');
    echo html_writer::tag('th', 'Expiry Date');
    echo html_writer::tag('th', 'Status');
    echo html_writer::tag('th', 'Action');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    $now = time();
    
    foreach ($compliance['competencies'] as $comp) {
        $statusLabel = get_string('status_' . $comp->status, 'local_trainingmatrix');
        $statusClass = $comp->status;
        if ($comp->status === 'expiring_soon') $statusClass = 'warning';
        if ($comp->status === 'expired') $statusClass = 'danger';
        
        $progressClass = $comp->progress >= 100 ? 'success' : ($comp->progress >= 50 ? 'info' : 'danger');
        
        // Get linked course
        $courseLink = $DB->get_record('local_tm_comp_courses', ['competencyid' => $comp->competencyid]);
        
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', 
            ($comp->code ? html_writer::tag('span', $comp->code, ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']) : '') .
            html_writer::tag('strong', format_string($comp->name))
        );
        echo html_writer::tag('td', $comp->category ?: '-');
        echo html_writer::tag('td',
            html_writer::start_div('', ['style' => 'display: flex; align-items: center; gap: 8px;']) .
            html_writer::start_div('tm-progress') .
            html_writer::div('', 'tm-progress-bar ' . $progressClass, ['style' => 'width: ' . $comp->progress . '%;']) .
            html_writer::end_div() .
            html_writer::tag('span', $comp->progress . '%', ['class' => 'tm-progress-label']) .
            html_writer::end_div()
        );
        echo html_writer::tag('td', $comp->completion && $comp->completion->completiondate ? date('d M Y', $comp->completion->completiondate) : '-');
        echo html_writer::tag('td', $comp->completion && $comp->completion->expirydate ? date('d M Y', $comp->completion->expirydate) : '-');
        echo html_writer::tag('td', html_writer::tag('span', $statusLabel, ['class' => 'tm-badge ' . $statusClass]));
        
        $actionBtn = '-';
        if ($courseLink && $courseLink->courseid) {
            if ($comp->status === 'not_started' || $comp->status === 'in_progress') {
                $actionBtn = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $courseLink->courseid]),
                    html_writer::tag('i', '', ['class' => 'fa fa-play mr-2']) . 'Start Course',
                    ['class' => 'tm-btn tm-btn-primary tm-btn-sm', 'target' => '_blank']
                );
            } else if ($comp->status === 'expired' || $comp->status === 'expiring_soon') {
                $actionBtn = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $courseLink->courseid]),
                    html_writer::tag('i', '', ['class' => 'fa fa-redo mr-2']) . 'Redo Course',
                    ['class' => 'tm-btn tm-btn-warning tm-btn-sm', 'target' => '_blank']
                );
            } else {
                $actionBtn = html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $courseLink->courseid]),
                    html_writer::tag('i', '', ['class' => 'fa fa-eye mr-2']) . 'View',
                    ['class' => 'tm-btn tm-btn-secondary tm-btn-sm', 'target' => '_blank']
                );
            }
        }
        echo html_writer::tag('td', $actionBtn);
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
    
    echo html_writer::end_div();
    echo html_writer::end_div();
}

echo html_writer::end_div();

echo $OUTPUT->footer();
