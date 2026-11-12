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
 * Download a training matrix report (CSV or Excel).
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');
require_once($CFG->libdir . '/filelib.php');

$report = optional_param('report', 'compliance', PARAM_ALPHA);
$format = optional_param('format', 'csv', PARAM_ALPHA);
$departmentid = optional_param('departmentid', 0, PARAM_INT);

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:view', $context);
$PAGE->set_context($context);
$PAGE->set_url('/local/trainingmatrix/export.php', ['report' => $report, 'format' => $format]);

$result = \local_trainingmatrix\external\export_report::execute($format, $departmentid, $report);

\core\session\manager::write_close();
send_file(base64_decode($result['content']), clean_filename($result['filename']), 0, 0, true, true, $result['mimetype']);
