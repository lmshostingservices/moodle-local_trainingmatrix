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
 * AI Training Matrix HCM v1.0.0 - Export report external service
 *
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_trainingmatrix\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

class export_report extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'format' => new external_value(PARAM_ALPHA, 'Export format: csv or excel', VALUE_DEFAULT, 'csv'),
            'departmentid' => new external_value(PARAM_INT, 'Filter by department', VALUE_DEFAULT, 0),
            'reporttype' => new external_value(PARAM_ALPHA, 'Report type: compliance, staff, expiry', VALUE_DEFAULT, 'compliance'),
        ]);
    }

    public static function execute($format = 'csv', $departmentid = 0, $reporttype = 'compliance') {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

        $params = self::validate_parameters(self::execute_parameters(), [
            'format' => $format,
            'departmentid' => $departmentid,
            'reporttype' => $reporttype,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/trainingmatrix:viewall', $context);

        if (!in_array($params['format'], ['csv', 'excel'], true) ||
                !in_array($params['reporttype'], ['compliance', 'staff', 'expiry'], true)) {
            throw new \invalid_parameter_exception('Unsupported report format or type');
        }

        $rows = [];
        $headers = [];

        switch ($params['reporttype']) {
            case 'compliance':
                $headers = ['Staff Name', 'Email', 'Position', 'Department', 'Compliance Rate', 'Completed', 'Expired', 'Expiring Soon'];
                
                $where = "s.status = 'active'";
                $params_sql = [];
                
                if ($params['departmentid'] > 0) {
                    $where .= " AND s.departmentid = ?";
                    $params_sql[] = $params['departmentid'];
                }

                $staff = $DB->get_records_sql("
                    SELECT s.*, u.firstname, u.lastname, u.email, p.name as position_name, d.name as department_name
                    FROM {local_tm_staff} s
                    JOIN {user} u ON u.id = s.userid
                    LEFT JOIN {local_tm_positions} p ON p.id = s.positionid
                    LEFT JOIN {local_tm_departments} d ON d.id = s.departmentid
                    WHERE $where
                    ORDER BY u.lastname, u.firstname
                ", $params_sql);

                foreach ($staff as $s) {
                    $compliance = \local_trainingmatrix_get_staff_compliance($s->userid);
                    $stats = $compliance ? $compliance['stats'] : ['compliance_rate' => 0, 'completed' => 0, 'expired' => 0, 'expiring_soon' => 0];
                    
                    $rows[] = [
                        $s->firstname . ' ' . $s->lastname,
                        $s->email,
                        $s->position_name ?? '',
                        $s->department_name ?? '',
                        $stats['compliance_rate'] . '%',
                        $stats['completed'],
                        $stats['expired'],
                        $stats['expiring_soon'],
                    ];
                }
                break;

            case 'expiry':
                $headers = ['Staff Name', 'Email', 'Competency', 'Code', 'Expiry Date', 'Days Remaining', 'Course'];
                
                $now = time();
                $thirtyDays = $now + (30 * 24 * 60 * 60);
                $filter = '';
                $filterparams = [$now, $thirtyDays];
                if ($params['departmentid'] > 0) {
                    $filter = ' AND EXISTS (SELECT 1 FROM {local_tm_staff} s
                        WHERE s.userid = tc.userid AND s.departmentid = ?)';
                    $filterparams[] = $params['departmentid'];
                }

                $expirations = $DB->get_records_sql("
                    SELECT tc.*, c.name as competency_name, c.code,
                           u.firstname, u.lastname, u.email,
                           course.fullname as course_name
                    FROM {local_tm_completions} tc
                    JOIN {local_tm_competencies} c ON c.id = tc.competencyid
                    JOIN {user} u ON u.id = tc.userid
                    LEFT JOIN {course} course ON course.id = tc.courseid
                    WHERE tc.status IN ('completed', 'expiring_soon')
                      AND tc.expirydate IS NOT NULL
                      AND tc.expirydate BETWEEN ? AND ?
                      $filter
                    ORDER BY tc.expirydate ASC
                ", $filterparams);

                foreach ($expirations as $e) {
                    $daysRemaining = ceil(($e->expirydate - $now) / 86400);
                    $rows[] = [
                        $e->firstname . ' ' . $e->lastname,
                        $e->email,
                        $e->competency_name,
                        $e->code ?? '',
                        date('Y-m-d', $e->expirydate),
                        $daysRemaining,
                        $e->course_name ?? '',
                    ];
                }
                break;

            default: // staff
                $headers = ['Staff Name', 'Email', 'Position', 'Department', 'Status', 'Start Date', 'Employee ID'];
                $filter = $params['departmentid'] > 0 ? 'WHERE s.departmentid = ?' : '';
                $filterparams = $params['departmentid'] > 0 ? [$params['departmentid']] : [];
                
                $staff = $DB->get_records_sql("
                    SELECT s.*, u.firstname, u.lastname, u.email, p.name as position_name, d.name as department_name
                    FROM {local_tm_staff} s
                    JOIN {user} u ON u.id = s.userid
                    LEFT JOIN {local_tm_positions} p ON p.id = s.positionid
                    LEFT JOIN {local_tm_departments} d ON d.id = s.departmentid
                    $filter
                    ORDER BY u.lastname, u.firstname
                ", $filterparams);

                foreach ($staff as $s) {
                    $rows[] = [
                        $s->firstname . ' ' . $s->lastname,
                        $s->email,
                        $s->position_name ?? '',
                        $s->department_name ?? '',
                        $s->status,
                        $s->startdate ? date('Y-m-d', $s->startdate) : '',
                        $s->employeeid ?? '',
                    ];
                }
        }

        if ($params['format'] === 'excel') {
            require_once($CFG->libdir . '/excellib.class.php');
            $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $book->getActiveSheet();
            $sheet->setTitle('Training Matrix');
            foreach (array_merge([$headers], $rows) as $rowindex => $row) {
                foreach ($row as $column => $value) {
                    $cell = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($column + 1)
                        . ($rowindex + 1);
                    // Explicit strings prevent names or other user data becoming formulas.
                    $sheet->setCellValueExplicit($cell, (string)$value,
                        \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                }
            }
            ob_start();
            try {
                $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
                $writer->save('php://output');
                $content = ob_get_contents();
            } finally {
                ob_end_clean();
                $book->disconnectWorksheets();
            }
            return [
                'success' => true,
                'filename' => 'training_matrix_' . $params['reporttype'] . '_' . date('Y-m-d') . '.xlsx',
                'content' => base64_encode($content),
                'mimetype' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'rows' => count($rows),
            ];
        }

        // Prevent spreadsheet applications interpreting user-controlled CSV cells as formulas.
        $output = fopen('php://temp', 'r+');
        fputcsv($output, $headers);
        foreach ($rows as $row) {
            $row = array_map(static function ($value) {
                $value = (string)$value;
                return preg_match('/^[\s]*[=+@-]|^[\t\r\n]/u', $value) ? "'" . $value : $value;
            }, $row);
            fputcsv($output, $row);
        }
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        return [
            'success' => true,
            'filename' => 'training_matrix_' . $params['reporttype'] . '_' . date('Y-m-d') . '.csv',
            'content' => base64_encode($csv),
            'mimetype' => 'text/csv',
            'rows' => count($rows),
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'filename' => new external_value(PARAM_TEXT, 'Suggested filename'),
            'content' => new external_value(PARAM_TEXT, 'Base64 encoded file content'),
            'mimetype' => new external_value(PARAM_TEXT, 'MIME type'),
            'rows' => new external_value(PARAM_INT, 'Number of data rows'),
        ]);
    }
}
