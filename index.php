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
require_once(__DIR__ . '/lib.php');

$tab = optional_param('tab', 'dashboard', PARAM_ALPHA);
$action = optional_param('action', '', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$clientparam = optional_param('client', -1, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:view', $context);

$canmanage = has_capability('local/trainingmatrix:manage', $context);
$canviewall = has_capability('local/trainingmatrix:viewall', $context);
$canimport = has_capability('local/trainingmatrix:import', $context);

// Admins choose a client to work in; the choice is remembered. Line managers are never narrowed this way.
if ($clientparam >= 0 && ($canviewall || $canmanage)) {
    \local_trainingmatrix\local\tenancy::current_filter($clientparam);
}
$clientfilter = ($canviewall || $canmanage) ? \local_trainingmatrix\local\tenancy::current_filter() : 0;
// $scope narrows lists to the chosen client; $accessscope decides what may be opened or changed.
$scope = \local_trainingmatrix\local\scope::current($clientfilter);
$accessscope = \local_trainingmatrix\local\scope::current(0);
// Staff and reports are open to anyone whose scope holds people: all staff, or a manager's own team.
$canoversee = $canviewall || ($scope->mode() === \local_trainingmatrix\local\scope::TEAM && $scope->has_staff());

$PAGE->set_url('/local/trainingmatrix/index.php', ['tab' => $tab]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('trainingmatrix', 'local_trainingmatrix'));
$PAGE->set_heading(get_string('trainingmatrix', 'local_trainingmatrix'));

// State-changing actions run before any output so that redirect() can send a proper header.
$actionpermissions = [
    'departments' => $canmanage,
    'positions' => $canmanage,
    'competencies' => $canmanage,
    'staff' => $canoversee,
    'reports' => $canoversee,
    'import' => $canimport,
];
if (in_array($action, ['save', 'delete', 'remind', 'upload', 'confirm'], true) && !empty($actionpermissions[$tab])) {
    require_sesskey();
    require(__DIR__ . '/tabs/' . $tab . '_actions.php');
}

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
echo html_writer::tag('p', get_string('subtitle', 'local_trainingmatrix'), ['class' => 'tm-subtitle']);
echo html_writer::end_div();
if (($canviewall || $canmanage) && \local_trainingmatrix\local\tenancy::clients()) {
    $clientoptions = [0 => get_string('client_all', 'local_trainingmatrix')];
    foreach (\local_trainingmatrix\local\tenancy::clients() as $cid => $cname) {
        $clientoptions[$cid] = format_string($cname);
    }
    $select = new single_select(new moodle_url('/local/trainingmatrix/index.php', ['tab' => $tab]), 'client',
        $clientoptions, $clientfilter, null, 'tm-client-filter');
    $select->set_label(get_string('client_filter', 'local_trainingmatrix'));
    echo html_writer::div($OUTPUT->render($select), 'tm-client-filter-wrap');
} else if ($scope->mode() === \local_trainingmatrix\local\scope::TEAM && !$canviewall) {
    echo html_writer::tag('p', get_string('team_scope_note', 'local_trainingmatrix'), ['class' => 'tm-subtitle']);
}
echo html_writer::end_div();

// Navigation tabs
$tabs = [
    'dashboard' => ['icon' => 'fa-tachometer-alt', 'label' => get_string('dashboard', 'local_trainingmatrix')],
    'departments' => ['icon' => 'fa-building', 'label' => get_string('departments', 'local_trainingmatrix')],
    'positions' => ['icon' => 'fa-user-tie', 'label' => get_string('positions', 'local_trainingmatrix')],
    'competencies' => ['icon' => 'fa-certificate', 'label' => get_string('competencies', 'local_trainingmatrix')],
    'staff' => ['icon' => 'fa-users', 'label' => get_string('staff', 'local_trainingmatrix')],
    'reports' => ['icon' => 'fa-chart-bar', 'label' => get_string('reports', 'local_trainingmatrix')],
    'import' => ['icon' => 'fa-file-import', 'label' => get_string('import', 'local_trainingmatrix')],
];
$tabvisible = [
    'dashboard' => true,
    'departments' => $canmanage,
    'positions' => $canmanage,
    'competencies' => $canmanage,
    'staff' => $canoversee,
    'reports' => $canoversee,
    'import' => $canimport,
];

echo html_writer::start_div('tm-nav');
foreach ($tabs as $key => $tabinfo) {
    if (empty($tabvisible[$key])) {
        continue;
    }
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
        if ($canoversee) {
            include(__DIR__ . '/tabs/staff.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'view staff'), 'error');
        }
        break;
    case 'reports':
        if ($canoversee) {
            include(__DIR__ . '/tabs/reports.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'view reports'), 'error');
        }
        break;
    case 'import':
        if ($canimport) {
            include(__DIR__ . '/tabs/import.php');
        } else {
            echo $OUTPUT->notification(get_string('nopermissions', 'error', 'import staff'), 'error');
        }
        break;
}

echo html_writer::end_div(); // tm-content
echo html_writer::end_div(); // trainingmatrix-container

echo $OUTPUT->footer();
