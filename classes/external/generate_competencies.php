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
 * AI Training Matrix HCM v1.0.0 - Generate competencies external service
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
use core_external\external_multiple_structure;

class generate_competencies extends external_api {
    public static function execute_parameters() {
        return new external_function_parameters([
            'positionid' => new external_value(PARAM_INT, 'Position ID to generate competencies for'),
        ]);
    }

    public static function execute($positionid) {
        $params = self::validate_parameters(self::execute_parameters(), [
            'positionid' => $positionid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/trainingmatrix:manage', $context);

        // Release the session lock before the external HTTP calls.
        \core\session\manager::write_close();

        $result = (new \local_trainingmatrix\local\ai_client())->generate((int)$params['positionid']);
        return [
            'success' => $result['ok'],
            'message' => $result['ok'] ? get_string('competencies_generated', 'local_trainingmatrix') : $result['error'],
            'created' => $result['created'],
            'linked' => $result['linked'],
            'credits_used' => $result['credits_used'],
        ];
    }

    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'Success status'),
            'message' => new external_value(PARAM_TEXT, 'Result message'),
            'created' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Created competency names')
            ),
            'linked' => new external_multiple_structure(
                new external_value(PARAM_TEXT, 'Linked competency names')
            ),
            'credits_used' => new external_value(PARAM_INT, 'Credits consumed'),
        ]);
    }
}
