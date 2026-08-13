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

$stats = local_trainingmatrix_get_compliance_stats();

echo html_writer::start_div('tm-dashboard tm-animate-in');

// Stats grid
echo html_writer::start_div('tm-stats-grid');

// Total Staff
echo html_writer::start_div('tm-stat-card primary');
echo html_writer::start_div('tm-stat-icon primary');
echo html_writer::tag('i', '', ['class' => 'fa fa-users']);
echo html_writer::end_div();
echo html_writer::tag('div', $stats['total_staff'], ['class' => 'tm-stat-value']);
echo html_writer::tag('div', get_string('total_staff', 'local_trainingmatrix'), ['class' => 'tm-stat-label']);
echo html_writer::end_div();

// Fully Compliant
echo html_writer::start_div('tm-stat-card success');
echo html_writer::start_div('tm-stat-icon success');
echo html_writer::tag('i', '', ['class' => 'fa fa-check-circle']);
echo html_writer::end_div();
echo html_writer::tag('div', $stats['fully_compliant'], ['class' => 'tm-stat-value']);
echo html_writer::tag('div', get_string('fully_compliant', 'local_trainingmatrix'), ['class' => 'tm-stat-label']);
echo html_writer::end_div();

// Expiring Soon
echo html_writer::start_div('tm-stat-card warning');
echo html_writer::start_div('tm-stat-icon warning');
echo html_writer::tag('i', '', ['class' => 'fa fa-exclamation-triangle']);
echo html_writer::end_div();
echo html_writer::tag('div', $stats['expiring_soon'], ['class' => 'tm-stat-value']);
echo html_writer::tag('div', get_string('expiring_soon', 'local_trainingmatrix'), ['class' => 'tm-stat-label']);
echo html_writer::end_div();

// Overdue
echo html_writer::start_div('tm-stat-card danger');
echo html_writer::start_div('tm-stat-icon danger');
echo html_writer::tag('i', '', ['class' => 'fa fa-times-circle']);
echo html_writer::end_div();
echo html_writer::tag('div', $stats['expired'], ['class' => 'tm-stat-value']);
echo html_writer::tag('div', get_string('overdue', 'local_trainingmatrix'), ['class' => 'tm-stat-label']);
echo html_writer::end_div();

echo html_writer::end_div(); // stats-grid

// Quick actions section
echo html_writer::start_div('tm-card mt-4');
echo html_writer::start_div('tm-card-header');
echo html_writer::tag('h3', 'Quick Actions', ['class' => 'tm-card-title']);
echo html_writer::end_div();
echo html_writer::start_div('tm-card-body');

$actions = [
    ['icon' => 'fa-building', 'label' => 'Add Department', 'url' => new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'departments', 'action' => 'add']), 'class' => 'primary'],
    ['icon' => 'fa-user-tie', 'label' => 'Add Position', 'url' => new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions', 'action' => 'add']), 'class' => 'info'],
    ['icon' => 'fa-certificate', 'label' => 'Add Competency', 'url' => new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => 'add']), 'class' => 'success'],
    ['icon' => 'fa-user-plus', 'label' => 'Assign Staff', 'url' => new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff', 'action' => 'add']), 'class' => 'warning'],
];

echo html_writer::start_div('tm-quick-actions', ['style' => 'display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 16px;']);
foreach ($actions as $action) {
    if ($canmanage || ($action['label'] === 'Assign Staff' && has_capability('local/trainingmatrix:assign', $context))) {
        echo html_writer::link($action['url'],
            html_writer::tag('i', '', ['class' => 'fa ' . $action['icon'] . ' mr-2']) . $action['label'],
            ['class' => 'tm-btn tm-btn-' . $action['class'], 'style' => 'justify-content: center;']
        );
    }
}
echo html_writer::end_div();

echo html_writer::end_div();
echo html_writer::end_div();

// Recent activity / Upcoming expirations
echo html_writer::start_div('tm-card mt-4');
echo html_writer::start_div('tm-card-header');
echo html_writer::tag('h3', 'Upcoming Expirations (Next 30 Days)', ['class' => 'tm-card-title']);
echo html_writer::end_div();
echo html_writer::start_div('tm-card-body');

$now = time();
$thirtyDays = $now + (30 * 24 * 60 * 60);

$upcomingExpirations = $DB->get_records_sql("
    SELECT tc.*, c.name as competency_name, c.code as competency_code, 
           u.firstname, u.lastname, u.email
    FROM {local_tm_completions} tc
    JOIN {local_tm_competencies} c ON c.id = tc.competencyid
    JOIN {user} u ON u.id = tc.userid
    WHERE tc.status = 'completed' 
      AND tc.expirydate IS NOT NULL 
      AND tc.expirydate BETWEEN ? AND ?
    ORDER BY tc.expirydate ASC
    LIMIT 10
", [$now, $thirtyDays]);

if (empty($upcomingExpirations)) {
    echo html_writer::start_div('tm-empty');
    echo html_writer::tag('i', '', ['class' => 'fa fa-check-circle tm-empty-icon', 'style' => 'color: var(--tm-success);']);
    echo html_writer::tag('div', 'No expirations in the next 30 days', ['class' => 'tm-empty-title']);
    echo html_writer::end_div();
} else {
    echo html_writer::start_div('tm-table-container');
    echo html_writer::start_tag('table', ['class' => 'tm-table']);
    echo html_writer::start_tag('thead');
    echo html_writer::start_tag('tr');
    echo html_writer::tag('th', 'Staff Member');
    echo html_writer::tag('th', 'Competency');
    echo html_writer::tag('th', 'Expiry Date');
    echo html_writer::tag('th', 'Days Remaining');
    echo html_writer::tag('th', 'Actions');
    echo html_writer::end_tag('tr');
    echo html_writer::end_tag('thead');
    echo html_writer::start_tag('tbody');
    
    foreach ($upcomingExpirations as $exp) {
        $daysRemaining = ceil(($exp->expirydate - $now) / 86400);
        $badgeClass = $daysRemaining <= 7 ? 'danger' : ($daysRemaining <= 14 ? 'warning' : 'info');
        
        echo html_writer::start_tag('tr');
        echo html_writer::tag('td', 
            html_writer::tag('strong', $exp->firstname . ' ' . $exp->lastname) . 
            html_writer::tag('br') .
            html_writer::tag('span', $exp->email, ['style' => 'color: var(--tm-gray-500); font-size: 12px;'])
        );
        echo html_writer::tag('td', 
            ($exp->competency_code ? html_writer::tag('span', $exp->competency_code, ['class' => 'tm-badge info', 'style' => 'margin-right: 8px;']) : '') .
            $exp->competency_name
        );
        echo html_writer::tag('td', userdate($exp->expirydate, '%d %b %Y'));
        echo html_writer::tag('td', 
            html_writer::tag('span', $daysRemaining . ' days', ['class' => 'tm-badge ' . $badgeClass])
        );
        echo html_writer::tag('td',
            html_writer::link('#', 
                html_writer::tag('i', '', ['class' => 'fa fa-bell']),
                ['class' => 'tm-btn tm-btn-secondary tm-btn-icon tm-btn-sm', 'title' => 'Send Reminder', 'data-tooltip' => 'Send Reminder']
            )
        );
        echo html_writer::end_tag('tr');
    }
    
    echo html_writer::end_tag('tbody');
    echo html_writer::end_tag('table');
    echo html_writer::end_div();
}

echo html_writer::end_div();
echo html_writer::end_div();

echo html_writer::end_div(); // tm-dashboard
