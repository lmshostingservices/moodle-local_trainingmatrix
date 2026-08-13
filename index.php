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

$tab = optional_param('tab', 'dashboard', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:view', $context);

$canmanage = has_capability('local/trainingmatrix:manage', $context);
$canviewall = has_capability('local/trainingmatrix:viewall', $context);

$PAGE->set_url('/local/trainingmatrix/index.php', ['tab' => $tab]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('trainingmatrix', 'local_trainingmatrix'));
$PAGE->set_heading(get_string('trainingmatrix', 'local_trainingmatrix'));

$PAGE->requires->css('/local/trainingmatrix/styles.css');

echo $OUTPUT->header();

echo html_writer::start_div('trainingmatrix-container');

// Premium header
echo html_writer::start_div('tm-header');
echo html_writer::start_div('tm-header-content');
echo html_writer::tag('h1', 
    html_writer::tag('i', '', ['class' => 'fa fa-th mr-3']) . 
    get_string('trainingmatrix', 'local_trainingmatrix'),
    ['class' => 'tm-title']
);
echo html_writer::tag('p', 'Human Capital Management & Compliance Tracking', ['class' => 'tm-subtitle']);
echo html_writer::end_div();
echo html_writer::end_div();

// Navigation tabs
$tabs = [
    'dashboard' => ['icon' => 'fa-tachometer-alt', 'label' => get_string('dashboard', 'local_trainingmatrix')],
    'departments' => ['icon' => 'fa-building', 'label' => get_string('departments', 'local_trainingmatrix')],
    'positions' => ['icon' => 'fa-user-tie', 'label' => get_string('positions', 'local_trainingmatrix')],
    'competencies' => ['icon' => 'fa-certificate', 'label' => get_string('competencies', 'local_trainingmatrix')],
    'staff' => ['icon' => 'fa-users', 'label' => get_string('staff', 'local_trainingmatrix')],
    'reports' => ['icon' => 'fa-chart-bar', 'label' => get_string('reports', 'local_trainingmatrix')],
];

echo html_writer::start_div('tm-nav');
foreach ($tabs as $key => $tabinfo) {
    $active = ($tab === $key) ? ' active' : '';
    $url = new moodle_url('/local/trainingmatrix/index.php', ['tab' => $key]);
    echo html_writer::link($url, 
        html_writer::tag('i', '', ['class' => 'fa ' . $tabinfo['icon'] . ' mr-2']) . $tabinfo['label'],
        ['class' => 'tm-nav-item' . $active]
    );
}
echo html_writer::end_div();

// Content area
echo html_writer::start_div('tm-content');

switch ($tab) {
    case 'dashboard':
        include(__DIR__ . '/tabs/dashboard.php');
        break;
    case 'departments':
        if ($canmanage) {
            include(__DIR__ . '/tabs/departments.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'manage departments'), 'error');
        }
        break;
    case 'positions':
        if ($canmanage) {
            include(__DIR__ . '/tabs/positions.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'manage positions'), 'error');
        }
        break;
    case 'competencies':
        if ($canmanage) {
            include(__DIR__ . '/tabs/competencies.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'manage competencies'), 'error');
        }
        break;
    case 'staff':
        if ($canviewall) {
            include(__DIR__ . '/tabs/staff.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'view staff'), 'error');
        }
        break;
    case 'reports':
        if ($canviewall) {
            include(__DIR__ . '/tabs/reports.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'view reports'), 'error');
        }
        break;
}

echo html_writer::end_div(); // tm-content
echo html_writer::end_div(); // trainingmatrix-container

echo $OUTPUT->footer();
