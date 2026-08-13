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
 * AI Training Matrix HCM v1.0.0 - Get staff completions external service
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
use core_external\external_multiple_structure;

class get_staff_completions extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'userid' => new external_value(PARAM_INT, 'User ID to get completions for', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute($userid = 0) {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'userid' => $userid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);

        $targetuserid = $params['userid'] > 0 ? $params['userid'] : $USER->id;

        // Check capability - users can view their own, managers can view all
        if ($targetuserid != $USER->id) {
            require_capability('local/trainingmatrix:viewall', $context);
        } else {
            require_capability('local/trainingmatrix:view', $context);
        }

        $compliance = local_trainingmatrix_get_staff_compliance($targetuserid);

        if (!$compliance) {
            return [
                'userid' => $targetuserid,
                'position' => '',
                'department' => '',
                'completions' => [],
                'stats' => [
                    'total' => 0,
                    'completed' => 0,
                    'in_progress' => 0,
                    'expired' => 0,
                    'expiring_soon' => 0,
                    'not_started' => 0,
                    'compliance_rate' => 0,
                ],
            ];
        }

        $completions = [];
        foreach ($compliance['competencies'] as $comp) {
            $completions[] = [
                'competencyid' => (int)$comp->competencyid,
                'name' => $comp->name,
                'code' => $comp->code ?? '',
                'category' => $comp->category ?? '',
                'status' => $comp->status,
                'progress' => (int)$comp->progress,
                'mandatory' => (bool)$comp->mandatory,
                'completiondate' => $comp->completion ? (int)$comp->completion->completiondate : 0,
                'expirydate' => $comp->completion ? (int)$comp->completion->expirydate : 0,
            ];
        }

        return [
            'userid' => $targetuserid,
            'position' => $compliance['position']->name ?? '',
            'department' => '',
            'completions' => $completions,
            'stats' => [
                'total' => $compliance['stats']['total'],
                'completed' => $compliance['stats']['completed'],
                'in_progress' => $compliance['stats']['in_progress'],
                'expired' => $compliance['stats']['expired'],
                'expiring_soon' => $compliance['stats']['expiring_soon'],
                'not_started' => $compliance['stats']['not_started'],
                'compliance_rate' => $compliance['stats']['compliance_rate'],
            ],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'userid' => new external_value(PARAM_INT, 'User ID'),
            'position' => new external_value(PARAM_TEXT, 'Position name'),
            'department' => new external_value(PARAM_TEXT, 'Department name'),
            'completions' => new external_multiple_structure(
                new external_single_structure([
                    'competencyid' => new external_value(PARAM_INT, 'Competency ID'),
                    'name' => new external_value(PARAM_TEXT, 'Competency name'),
                    'code' => new external_value(PARAM_TEXT, 'Competency code'),
                    'category' => new external_value(PARAM_TEXT, 'Category'),
                    'status' => new external_value(PARAM_TEXT, 'Status'),
                    'progress' => new external_value(PARAM_INT, 'Progress 0-100'),
                    'mandatory' => new external_value(PARAM_BOOL, 'Is mandatory'),
                    'completiondate' => new external_value(PARAM_INT, 'Completion timestamp'),
                    'expirydate' => new external_value(PARAM_INT, 'Expiry timestamp'),
                ])
            ),
            'stats' => new external_single_structure([
                'total' => new external_value(PARAM_INT, 'Total competencies'),
                'completed' => new external_value(PARAM_INT, 'Completed'),
                'in_progress' => new external_value(PARAM_INT, 'In progress'),
                'expired' => new external_value(PARAM_INT, 'Expired'),
                'expiring_soon' => new external_value(PARAM_INT, 'Expiring soon'),
                'not_started' => new external_value(PARAM_INT, 'Not started'),
                'compliance_rate' => new external_value(PARAM_INT, 'Compliance rate'),
            ]),
        ]);
    }
}
