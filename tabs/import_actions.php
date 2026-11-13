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
 * Staff import actions. Included by index.php before any output so redirect() works.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/csvlib.class.php');
require_capability('local/trainingmatrix:import', $context);

$importurl = new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'import']);

if ($action === 'upload') {
    $importform = new \local_trainingmatrix\form\import_form(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'import', 'action' => 'upload']),
        ['clientid' => $clientfilter]
    );
    if ($data = $importform->get_data()) {
        $iid = csv_import_reader::get_new_iid('local_trainingmatrix_staff');
        $reader = new csv_import_reader($iid, 'local_trainingmatrix_staff');
        $count = $reader->load_csv_content($importform->get_file_content('csvfile'), $data->encoding, $data->delimiter);
        $error = $reader->get_error();
        if ($error || !$count) {
            $reader->cleanup(true);
            redirect(
                $importurl,
                $error ?: get_string('import_err_empty', 'local_trainingmatrix'),
                null,
                \core\output\notification::NOTIFY_ERROR
            );
        }
        redirect(new moodle_url($importurl, ['iid' => $iid, 'clientid' => (int)$data->clientid,
            'createmissing' => (int)!empty($data->createmissing)]));
    }
    // An invalid submission falls through: the tab shows $importform again with its errors.
}

if ($action === 'confirm') {
    $iid = required_param('iid', PARAM_INT);
    $importer = new \local_trainingmatrix\local\staff_import(
        optional_param('clientid', 0, PARAM_INT),
        (bool)optional_param('createmissing', 0, PARAM_BOOL)
    );
    $reader = new csv_import_reader($iid, 'local_trainingmatrix_staff');
    $file = $importer->plan_file($reader);
    if ($file['errors']) {
        $reader->cleanup(true);
        redirect($importurl, implode(' ', $file['errors']), null, \core\output\notification::NOTIFY_ERROR);
    }
    core_php_time_limit::raise();
    $counts = ['create' => 0, 'update' => 0, 'skipped' => 0, 'failed' => 0];
    $problems = [];
    foreach ($file['plans'] as $plan) {
        if ($plan->errors) {
            $counts['skipped']++;
            continue;
        }
        $error = $importer->apply($plan);
        if ($error === null) {
            $counts[$plan->action]++;
        } else {
            $counts['failed']++;
            $problems[] = get_string('import_line', 'local_trainingmatrix', (object)[
                'line' => $plan->line, 'message' => $error]);
        }
    }
    $reader->cleanup(true);
    if ($problems) {
        \core\notification::warning(get_string('import_failedrows', 'local_trainingmatrix', (object)[
            'count' => count($problems), 'list' => s(implode(' | ', array_slice($problems, 0, 20)))]));
    }
    redirect(
        new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'staff']),
        get_string('import_done', 'local_trainingmatrix', (object)$counts),
        null,
        $counts['create'] + $counts['update'] ? \core\output\notification::NOTIFY_SUCCESS :
        \core\output\notification::NOTIFY_WARNING
    );
}
