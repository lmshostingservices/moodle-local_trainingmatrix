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

require_once($CFG->libdir . '/externallib.php');

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
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'positionid' => $positionid,
        ]);

        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/trainingmatrix:manage', $context);

        // SESSION LOCK: Release before TGA API + AI generation calls (external HTTP).
        \core\session\manager::write_close();

        $position = $DB->get_record('local_tm_positions', ['id' => $params['positionid']], '*', MUST_EXIST);

        $apibase = get_config('local_trainingmatrix', 'apiurl') ?: 'https://lms-labs.com';
        $apibase = rtrim($apibase, '/');
        $siteid = trim(get_config('local_trainingmatrix', 'siteid') ?? '');
        $apikey = trim(get_config('local_trainingmatrix', 'apikey') ?? '');
        $credits_per_position = (int)(get_config('local_trainingmatrix', 'credits_per_position') ?: 10);

        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            return [
                'success' => false,
                'message' => get_string('plugin_not_configured', 'local_trainingmatrix'),
                'created' => [],
                'linked' => [],
                'credits_used' => 0,
            ];
        }

        // Check credits
        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $creditResponse = $curl->get($url);

        $creditData = json_decode($creditResponse, true);
        $availableCredits = $creditData['credits'] ?? 0;

        if ($availableCredits < $credits_per_position) {
            return [
                'success' => false,
                'message' => get_string('insufficient_credits', 'local_trainingmatrix', [
                    'required' => $credits_per_position,
                    'available' => $availableCredits
                ]),
                'created' => [],
                'linked' => [],
                'credits_used' => 0,
            ];
        }

        // Build prompt
        $existingCompetencies = $DB->get_records('local_tm_competencies', null, '', 'id, name, code');
        $existingList = [];
        foreach ($existingCompetencies as $c) {
            $existingList[] = $c->name . ($c->code ? " ({$c->code})" : '');
        }

        $prompt = "You are an expert HR consultant. Generate 5-10 competencies for: \"{$position->name}\"";
        if (!empty($position->description)) {
            $prompt .= "\nDescription: {$position->description}";
        }
        $prompt .= "\n\nExisting (avoid duplicates): " . implode(", ", $existingList);
        $prompt .= "\n\nReturn JSON array with: name, code, category (Safety/Technical/Leadership/Compliance/Professional Development/Induction), description, mandatory (true/false)";

        $aiPayload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'prompt' => $prompt,
            'plugin' => 'trainingmatrix',
            'action' => 'generate_competencies',
            'credits' => $credits_per_position,
        ];

        $curl2 = new \curl();
        $curl2->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl2->setHeader(['Content-Type: application/json']);
        $aiResponse = $curl2->post($apibase . '/api/training-matrix/generate', json_encode($aiPayload));
        $httpcode = $curl2->info['http_code'];

        $aiData = json_decode($aiResponse, true);

        if ($httpcode >= 200 && $httpcode < 300 && isset($aiData['competencies'])) {
            $created = [];
            $linked = [];

            foreach ($aiData['competencies'] as $comp) {
                $existing = $DB->get_record('local_tm_competencies', ['code' => $comp['code']]);
                if (!$existing) {
                    $existing = $DB->get_record('local_tm_competencies', ['name' => $comp['name']]);
                }

                if ($existing) {
                    $compId = $existing->id;
                    $linked[] = $comp['name'];
                } else {
                    $newComp = new \stdClass();
                    $newComp->name = $comp['name'];
                    $newComp->code = $comp['code'] ?? '';
                    $newComp->category = $comp['category'] ?? 'Other';
                    $newComp->description = $comp['description'] ?? '';
                    $newComp->mandatory = !empty($comp['mandatory']) ? 1 : 0;
                    $newComp->timecreated = time();
                    $newComp->timemodified = time();
                    $compId = $DB->insert_record('local_tm_competencies', $newComp);
                    $created[] = $comp['name'];
                }

                if (!$DB->record_exists('local_tm_pos_comps', [
                    'positionid' => $positionid,
                    'competencyid' => $compId
                ])) {
                    $posComp = new \stdClass();
                    $posComp->positionid = $positionid;
                    $posComp->competencyid = $compId;
                    $posComp->mandatory = !empty($comp['mandatory']) ? 1 : 0;
                    $posComp->timecreated = time();
                    $DB->insert_record('local_tm_pos_comps', $posComp);
                }
            }

            return [
                'success' => true,
                'message' => get_string('competencies_generated', 'local_trainingmatrix'),
                'created' => $created,
                'linked' => $linked,
                'credits_used' => $credits_per_position,
            ];
        }

        return [
            'success' => false,
            'message' => $aiData['message'] ?? 'AI generation failed',
            'created' => [],
            'linked' => [],
            'credits_used' => 0,
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
