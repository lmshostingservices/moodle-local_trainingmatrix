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

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/lib.php');

$action = required_param('action', PARAM_ALPHA);
$sesskey = required_param('sesskey', PARAM_ALPHANUM);

if (!confirm_sesskey($sesskey)) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => false, 'error' => 'Invalid session']);
    exit;
}

require_login();
$context = context_system::instance();
require_capability('local/trainingmatrix:manage', $context);

$apibase = get_config('local_trainingmatrix', 'apiurl') ?: 'https://lms-labs.com';
$apibase = rtrim($apibase, '/');

// Explicitly include aiconfig lib.php if available
$aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
if (file_exists($aiconfiglib)) {
    require_once($aiconfiglib);
}

// Priority 1: Central Config (recommended for multi-plugin setups)
$siteid = '';
$apikey = '';
if (function_exists('local_aiconfig_get_siteid')) {
    $siteid = trim(local_aiconfig_get_siteid() ?? '');
}
if (function_exists('local_aiconfig_get_apikey')) {
    $apikey = trim(local_aiconfig_get_apikey() ?? '');
}

// Priority 2: Plugin settings as fallback
if (empty($siteid)) {
    $siteid = trim(get_config('local_trainingmatrix', 'siteid') ?? '');
}
if (empty($apikey)) {
    $apikey = trim(get_config('local_trainingmatrix', 'apikey') ?? '');
}
$credits_per_position = 10; // Fixed owner-approved server tariff.

// Release session lock before long-running API calls to prevent blocking other requests.
\core\session\manager::write_close();

header('Content-Type: application/json');

switch ($action) {
    case 'getcredits':
        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode([
                'ok' => false,
                'error' => get_string('plugin_not_configured', 'local_trainingmatrix')
            ]);
            break;
        }

        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true, 'CURLOPT_FOLLOWLOCATION' => true]);
        $response = $curl->get($url);
        $curlerror = $curl->error;
        $httpcode = $curl->info['http_code'];

        if ($curlerror) {
            echo json_encode(['ok' => false, 'error' => 'Connection failed: ' . $curlerror]);
            break;
        }

        $data = json_decode($response, true);
        if ($httpcode >= 200 && $httpcode < 300 && isset($data['credits'])) {
            echo json_encode([
                'ok' => true,
                'credits' => $data['credits'],
                'credits_required' => $credits_per_position
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => $data['message'] ?? 'Failed to fetch credits']);
        }
        break;

    case 'generate':
        $positionid = required_param('positionid', PARAM_INT);
        $position = $DB->get_record('local_tm_positions', ['id' => $positionid], '*', MUST_EXIST);

        if (strlen($siteid) === 0 || strlen($apikey) === 0) {
            echo json_encode([
                'ok' => false,
                'error' => get_string('plugin_not_configured', 'local_trainingmatrix')
            ]);
            break;
        }

        $url = $apibase . '/api/credits?' . http_build_query([
            'siteId' => $siteid,
            'apiKey' => $apikey,
        ], '', '&');

        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $creditResponse = $curl->get($url);

        $creditData = json_decode($creditResponse, true);
        $availableCredits = $creditData['credits'] ?? 0;

        if ((int)$availableCredits !== -1 && $availableCredits < $credits_per_position) {
            echo json_encode([
                'ok' => false,
                'error' => get_string('insufficient_credits', 'local_trainingmatrix', [
                    'required' => $credits_per_position,
                    'available' => $availableCredits
                ])
            ]);
            break;
        }

        $existingCompetencies = $DB->get_records('local_tm_competencies', null, '', 'id, name, code, category');
        $existingList = [];
        foreach ($existingCompetencies as $c) {
            $existingList[] = $c->name . ($c->code ? " ({$c->code})" : '');
        }

        $prompt = "You are an expert HR consultant specializing in workplace competency frameworks. Generate a list of 5-10 relevant competencies for the position: \"{$position->name}\"";
        if (!empty($position->description)) {
            $prompt .= "\n\nPosition description: {$position->description}";
        }
        $prompt .= "\n\nExisting competencies in the system (avoid duplicates):\n" . implode(", ", $existingList);
        $prompt .= "\n\nFor each competency, provide:\n1. name: Clear competency name\n2. code: Short alphanumeric code (e.g., WHS001, LEAD02)\n3. category: One of Safety, Technical, Leadership, Compliance, Professional Development, Induction\n4. description: Brief description of the competency\n5. mandatory: true/false if this is essential for the role\n\nReturn as JSON array. Example:\n[{\"name\": \"Work at Heights\", \"code\": \"WHS001\", \"category\": \"Safety\", \"description\": \"Ability to work safely at heights above 2 meters\", \"mandatory\": true}]";

        $aiPayload = [
            'siteId' => $siteid,
            'apiKey' => $apikey,
            'prompt' => $prompt,
            'requestId' => hash('sha256', $position->id . ':' . $prompt),
            'plugin' => 'trainingmatrix',
            'action' => 'generate_competencies',
            'credits' => $credits_per_position,
        ];

        $curl2 = new \curl();
        $curl2->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl2->setHeader(['Content-Type: application/json']);
        $aiResponse = $curl2->post($apibase . '/api/training-matrix/generate', json_encode($aiPayload));
        $curlerror = $curl2->error;
        $httpcode = $curl2->info['http_code'];

        if ($curlerror) {
            echo json_encode(['ok' => false, 'error' => 'AI generation failed: ' . $curlerror]);
            break;
        }

        $aiData = json_decode($aiResponse, true);

        if ($httpcode >= 200 && $httpcode < 300 && isset($aiData['competencies'])) {
            $competencies = $aiData['competencies'];
            $created = [];
            $linked = [];

            foreach ($competencies as $comp) {
                $existingByCode = !empty($comp['code']) ? 
                    $DB->get_record('local_tm_competencies', ['code' => $comp['code']]) : null;
                $existingByName = $DB->get_record('local_tm_competencies', ['name' => $comp['name']]);
                
                if ($existingByCode) {
                    $compId = $existingByCode->id;
                    $linked[] = $comp['name'];
                } else if ($existingByName) {
                    $compId = $existingByName->id;
                    $linked[] = $comp['name'];
                } else {
                    $newComp = new stdClass();
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

                $existingLink = $DB->get_record('local_tm_pos_comps', [
                    'positionid' => $positionid,
                    'competencyid' => $compId
                ]);

                if (!$existingLink) {
                    $posComp = new stdClass();
                    $posComp->positionid = $positionid;
                    $posComp->competencyid = $compId;
                    $posComp->mandatory = !empty($comp['mandatory']) ? 1 : 0;
                    $posComp->timecreated = time();
                    $DB->insert_record('local_tm_pos_comps', $posComp);
                }
            }

            echo json_encode([
                'ok' => true,
                'message' => get_string('competencies_generated', 'local_trainingmatrix'),
                'created' => $created,
                'linked' => $linked,
                'credits_used' => $credits_per_position,
                'credits_remaining' => $aiData['credits_remaining'] ?? ($availableCredits - $credits_per_position)
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'error' => $aiData['message'] ?? 'AI generation failed'
            ]);
        }
        break;

    default:
        echo json_encode(['ok' => false, 'error' => 'Unknown action']);
}
