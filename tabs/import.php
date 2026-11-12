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
 * Staff import tab: upload, then preview every row before anything changes.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/csvlib.class.php');

$iid = optional_param('iid', 0, PARAM_INT);

echo html_writer::start_div('tm-import tm-animate-in');
echo html_writer::tag('h3', html_writer::tag('i', '', ['class' => 'fa fa-file-import mr-2']) .
    get_string('import_title', 'local_trainingmatrix'), ['class' => 'tm-card-title', 'style' => 'margin-bottom: 16px;']);

if ($iid) {
    // Preview.
    $previewclient = optional_param('clientid', 0, PARAM_INT);
    $createmissing = optional_param('createmissing', 0, PARAM_BOOL);
    $importer = new \local_trainingmatrix\local\staff_import($previewclient, (bool)$createmissing);
    $reader = new csv_import_reader($iid, 'local_trainingmatrix_staff');
    $file = $importer->plan_file($reader);
    $back = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'import']);

    if ($file['errors']) {
        echo $OUTPUT->notification(implode('<br>', array_map('s', $file['errors'])), 'error');
        echo html_writer::link($back, get_string('import_again', 'local_trainingmatrix'), ['class' => 'tm-btn tm-btn-secondary']);
        echo html_writer::end_div();
        return;
    }
    if ($file['unknown']) {
        echo $OUTPUT->notification(get_string('import_unknowncols', 'local_trainingmatrix',
            s(implode(', ', $file['unknown']))), 'warning');
    }

    $counts = ['create' => 0, 'update' => 0, 'error' => 0];
    foreach ($file['plans'] as $plan) {
        $counts[$plan->action]++;
    }
    echo html_writer::start_div('tm-stats-grid', ['style' => 'margin-bottom: 24px;']);
    foreach (['create' => 'success', 'update' => 'info', 'error' => 'danger'] as $key => $tone) {
        echo html_writer::div(
            html_writer::div($counts[$key], 'tm-stat-value') .
            html_writer::div(get_string('import_count_' . $key, 'local_trainingmatrix'), 'tm-stat-label'),
            'tm-stat-card ' . $tone);
    }
    echo html_writer::end_div();

    $table = new html_table();
    $table->attributes['class'] = 'tm-table';
    $table->head = [
        get_string('import_col_line', 'local_trainingmatrix'),
        get_string('staff_member', 'local_trainingmatrix'),
        get_string('client', 'local_trainingmatrix'),
        get_string('import_col_position', 'local_trainingmatrix'),
        get_string('import_col_department', 'local_trainingmatrix'),
        get_string('import_col_action', 'local_trainingmatrix'),
        get_string('import_col_notes', 'local_trainingmatrix'),
    ];
    $tones = ['create' => 'completed', 'update' => 'info', 'error' => 'danger'];
    foreach ($file['plans'] as $plan) {
        $notes = '';
        foreach ($plan->errors as $message) {
            $notes .= html_writer::div($message, 'text-danger small');
        }
        foreach ($plan->warnings as $message) {
            $notes .= html_writer::div($message, 'text-warning small');
        }
        $table->data[] = [
            $plan->line,
            s($plan->userlabel),
            (\local_trainingmatrix\local\tenancy::clients() && $plan->clientid) ?
                \local_trainingmatrix\local\tenancy::name($plan->clientid) : get_string('client_none', 'local_trainingmatrix'),
            s($plan->positionname),
            s($plan->departmentname) ?: '-',
            html_writer::tag('span', get_string('import_action_' . $plan->action, 'local_trainingmatrix'),
                ['class' => 'tm-badge ' . $tones[$plan->action]]),
            $notes ?: '-',
        ];
    }
    echo html_writer::div(html_writer::table($table), 'tm-table-container');

    echo html_writer::start_div('', ['style' => 'display: flex; gap: 12px; margin-top: 24px; align-items: center;']);
    if ($counts['create'] + $counts['update']) {
        echo html_writer::start_tag('form', ['method' => 'post', 'action' =>
            (new moodle_url('/local/trainingmatrix/index.php'))->out(false)]);
        foreach (['tab' => 'import', 'action' => 'confirm', 'iid' => $iid, 'clientid' => $previewclient,
                'createmissing' => (int)$createmissing, 'sesskey' => sesskey()] as $name => $value) {
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
        }
        echo html_writer::empty_tag('input', ['type' => 'submit', 'class' => 'tm-btn tm-btn-primary',
            'value' => get_string('import_confirm', 'local_trainingmatrix', $counts['create'] + $counts['update'])]);
        echo html_writer::end_tag('form');
    }
    echo html_writer::link($back, get_string('cancel'), ['class' => 'tm-btn tm-btn-secondary']);
    echo html_writer::end_div();
    if ($counts['error']) {
        echo html_writer::tag('p', get_string('import_errorsskipped', 'local_trainingmatrix'),
            ['class' => 'tm-muted small mt-2']);
    }
    echo html_writer::end_div();
    return;
}

// Instructions and upload form.
echo html_writer::tag('p', get_string('import_intro', 'local_trainingmatrix'));
$columns = new html_table();
$columns->attributes['class'] = 'tm-table';
$columns->head = [get_string('import_col_column', 'local_trainingmatrix'),
    get_string('import_col_meaning', 'local_trainingmatrix')];
foreach (\local_trainingmatrix\local\staff_import::COLUMNS as $column) {
    if ($column === 'client' && !\local_trainingmatrix\local\tenancy::clients()) {
        continue;
    }
    $columns->data[] = [html_writer::tag('code', $column), get_string('import_help_' . $column, 'local_trainingmatrix')];
}
echo html_writer::div(html_writer::table($columns), 'tm-table-container mb-3');
echo html_writer::tag('p', html_writer::link(new moodle_url('/local/trainingmatrix/import_template.csv'),
    html_writer::tag('i', '', ['class' => 'fa fa-download mr-2']) . get_string('import_template', 'local_trainingmatrix'),
    ['class' => 'tm-btn tm-btn-secondary tm-btn-sm']));

if (!isset($importform)) {
    $importform = new \local_trainingmatrix\form\import_form(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'import', 'action' => 'upload']),
        ['clientid' => $clientfilter]);
}
echo html_writer::div($importform->render(), 'tm-card tm-card-body');
echo html_writer::end_div();
