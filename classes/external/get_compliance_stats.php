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

require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;

/**
 * Web service: compliance figures for a department or client.
 */
class get_compliance_stats extends external_api {
    /**
     * Describe the parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'departmentid' => new external_value(PARAM_INT, 'Optional department filter', VALUE_DEFAULT, 0),
            'clientid' => new external_value(
                PARAM_INT,
                'Client filter for staff overseers: -1 remembered choice, 0 all, or a client id',
                VALUE_DEFAULT,
                -1
            ),
        ]);
    }

    /**
     * Get compliance figures for a department or client.
     *
     * @param int $departmentid Department id; 0 for all departments.
     * @param int $clientid Client id; -1 for the current scope.
     */
    public static function execute($departmentid = 0, $clientid = -1) {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'departmentid' => $departmentid,
            'clientid' => $clientid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        $scope = \local_trainingmatrix\local\scope::current($params['clientid'] >= 0 ? $params['clientid'] : null);
        if (!$scope->is_oversight() || ($scope->mode() === \local_trainingmatrix\local\scope::TEAM && !$scope->has_staff())) {
            throw new \required_capability_exception($context, 'local/trainingmatrix:viewall', 'nopermissions', '');
        }

        $stats = local_trainingmatrix_get_compliance_stats($scope, (int)$params['departmentid']);

        return [
            'total_staff' => $stats['total_staff'],
            'fully_compliant' => $stats['fully_compliant'],
            'expiring_soon' => $stats['expiring_soon'],
            'expired' => $stats['expired'],
            'compliance_rate' => $stats['total_staff'] > 0 ?
                round(($stats['fully_compliant'] / $stats['total_staff']) * 100) : 0,
        ];
    }

    /**
     * Describe the result.
     *
     * @return external_single_structure
     */
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
