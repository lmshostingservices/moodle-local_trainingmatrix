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
 * AI Training Matrix HCM v1.0.0 - Get compliance stats external service
 *
 * @package    local_trainingmatrix
 * @copyright  2025 AI Grader
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_trainingmatrix\external;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

class get_compliance_stats extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'Optional department filter', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($departmentid = 0) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'departmentid' => $departmentid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/trainingmatrix:viewall', $context);

        $stats = local_trainingmatrix_get_compliance_stats();

        if ($params['departmentid'] > 0) {
            // Filter by department
            $staffRecords = $DB->get_records('local_tm_staff', [
                'status' => 'active',
                'departmentid' => $params['departmentid']
            ]);
            
            $stats['total_staff'] = count($staffRecords);
            $stats['fully_compliant'] = 0;
            
            foreach ($staffRecords as $staff) {
                $compliance = local_trainingmatrix_get_staff_compliance($staff->userid);
                if ($compliance && $compliance['stats']['compliance_rate'] == 100 && $compliance['stats']['expired'] == 0) {
                    $stats['fully_compliant']++;
                }
            }
        }

        return [
            'total_staff' => $stats['total_staff'],
            'fully_compliant' => $stats['fully_compliant'],
            'expiring_soon' => $stats['expiring_soon'],
            'expired' => $stats['expired'],
            'compliance_rate' => $stats['total_staff'] > 0 ? 
                round(($stats['fully_compliant'] / $stats['total_staff']) * 100) : 0,
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'total_staff' => new external_value(PARAM_INT, 'Total active staff'),
            'fully_compliant' => new external_value(PARAM_INT, 'Fully compliant staff'),
            'expiring_soon' => new external_value(PARAM_INT, 'Training expiring soon'),
            'expired' => new external_value(PARAM_INT, 'Expired training records'),
            'compliance_rate' => new external_value(PARAM_INT, 'Overall compliance rate percentage'),
        ]);
    }
}
