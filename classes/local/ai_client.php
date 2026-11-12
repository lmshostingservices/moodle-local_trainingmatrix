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

namespace local_trainingmatrix\local;

/**
 * Shared client for the LMS Labs competency generation service.
 *
 * Used by both ajax.php and the local_trainingmatrix_generate_competencies web service so
 * credentials, credit checks and sanitising of remote data live in one place.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
final class ai_client {
    /** Fixed owner-approved server tariff. */
    public const CREDITS_PER_POSITION = 10;

    /** @var string */
    private $apibase;
    /** @var string */
    private $siteid;
    /** @var string */
    private $apikey;

    /**
     * Resolve credentials: local_aiconfig first, then plugin settings.
     */
    public function __construct() {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $this->apibase = rtrim((string)(get_config('local_trainingmatrix', 'apiurl') ?: 'https://lms-labs.com'), '/');
        $siteid = '';
        $apikey = '';
        $aiconfiglib = $CFG->dirroot . '/local/aiconfig/lib.php';
        if (file_exists($aiconfiglib)) {
            require_once($aiconfiglib);
        }
        if (function_exists('local_aiconfig_get_siteid')) {
            $siteid = trim((string)(local_aiconfig_get_siteid() ?? ''));
        }
        if (function_exists('local_aiconfig_get_apikey')) {
            $apikey = trim((string)(local_aiconfig_get_apikey() ?? ''));
        }
        if ($siteid === '') {
            $siteid = trim((string)get_config('local_trainingmatrix', 'siteid'));
        }
        if ($apikey === '') {
            $apikey = trim((string)get_config('local_trainingmatrix', 'apikey'));
        }
        $this->siteid = $siteid;
        $this->apikey = $apikey;
    }

    /**
     * Whether credentials are present.
     *
     * @return bool
     */
    public function is_configured(): bool {
        return $this->siteid !== '' && $this->apikey !== '';
    }

    /**
     * Fetch available credits.
     *
     * @return array ['ok' => bool, 'credits' => int|null, 'error' => string]
     */
    public function get_credits(): array {
        if (!$this->is_configured()) {
            return ['ok' => false, 'credits' => null, 'error' => get_string('plugin_not_configured', 'local_trainingmatrix')];
        }
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 30, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl->setHeader(['X-Api-Key: ' . $this->apikey]);
        $response = $curl->get($this->apibase . '/api/credits?' . http_build_query([
            'siteId' => $this->siteid,
            'apiKey' => $this->apikey,
        ], '', '&'));
        if ($curl->error) {
            return ['ok' => false, 'credits' => null,
                'error' => get_string('ai_connectionfailed', 'local_trainingmatrix', s($curl->error))];
        }
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        $data = json_decode((string)$response, true);
        // A zero status only happens without a transport (e.g. mocked responses); errors were handled above.
        if (($httpcode === 0 || ($httpcode >= 200 && $httpcode < 300)) && is_array($data) && isset($data['credits'])) {
            return ['ok' => true, 'credits' => (int)$data['credits'], 'error' => ''];
        }
        $message = is_array($data) && isset($data['message']) ? clean_param($data['message'], PARAM_TEXT) : '';
        return ['ok' => false, 'credits' => null,
            'error' => $message !== '' ? $message : get_string('ai_creditsfailed', 'local_trainingmatrix')];
    }

    /**
     * Generate and link competencies for a position.
     *
     * @param int $positionid
     * @return array ['ok' => bool, 'error' => string, 'created' => string[], 'linked' => string[],
     *               'credits_used' => int, 'credits_remaining' => int|null]
     */
    public function generate(int $positionid): array {
        global $DB, $CFG;

        $fail = function (string $error): array {
            return ['ok' => false, 'error' => $error, 'created' => [], 'linked' => [], 'credits_used' => 0,
                'credits_remaining' => null];
        };

        $position = $DB->get_record('local_tm_positions', ['id' => $positionid], '*', MUST_EXIST);
        if (!$this->is_configured()) {
            return $fail(get_string('plugin_not_configured', 'local_trainingmatrix'));
        }
        $credits = $this->get_credits();
        if (!$credits['ok']) {
            return $fail($credits['error']);
        }
        $available = (int)$credits['credits'];
        if ($available !== -1 && $available < self::CREDITS_PER_POSITION) {
            return $fail(get_string('insufficient_credits', 'local_trainingmatrix', [
                'required' => self::CREDITS_PER_POSITION, 'available' => $available]));
        }

        $existinglist = [];
        foreach ($DB->get_records('local_tm_competencies', null, 'name', 'id, name, code') as $c) {
            $existinglist[] = $c->name . ($c->code ? " ({$c->code})" : '');
        }
        $prompt = "You are an expert HR consultant specializing in workplace competency frameworks. " .
            "Generate a list of 5-10 relevant competencies for the position: \"{$position->name}\"";
        if (!empty($position->description)) {
            $prompt .= "\n\nPosition description: " . strip_tags($position->description);
        }
        $prompt .= "\n\nExisting competencies in the system (avoid duplicates):\n" . implode(", ", $existinglist);
        $prompt .= "\n\nFor each competency, provide:\n1. name: Clear competency name\n" .
            "2. code: Short alphanumeric code (e.g., WHS001, LEAD02)\n" .
            "3. category: One of Safety, Technical, Leadership, Compliance, Professional Development, Induction\n" .
            "4. description: Brief description of the competency\n5. mandatory: true/false if this is essential for the role\n\n" .
            "Return as JSON array. Example:\n[{\"name\": \"Work at Heights\", \"code\": \"WHS001\", \"category\": \"Safety\", " .
            "\"description\": \"Ability to work safely at heights above 2 meters\", \"mandatory\": true}]";

        $payload = [
            'siteId' => $this->siteid,
            'apiKey' => $this->apikey,
            'prompt' => $prompt,
            'requestId' => hash('sha256', $position->id . ':' . $prompt),
            'plugin' => 'trainingmatrix',
            'action' => 'generate_competencies',
            'credits' => self::CREDITS_PER_POSITION,
        ];
        $curl = new \curl();
        $curl->setopt(['CURLOPT_TIMEOUT' => 120, 'CURLOPT_SSL_VERIFYPEER' => true]);
        $curl->setHeader(['Content-Type: application/json', 'X-Api-Key: ' . $this->apikey]);
        $response = $curl->post($this->apibase . '/api/training-matrix/generate', json_encode($payload));
        if ($curl->error) {
            return $fail(get_string('ai_generationfailed', 'local_trainingmatrix') . ' ' . s($curl->error));
        }
        $httpcode = (int)($curl->info['http_code'] ?? 0);
        $data = json_decode((string)$response, true);
        if (($httpcode !== 0 && ($httpcode < 200 || $httpcode >= 300)) || !is_array($data) ||
                !isset($data['competencies']) ||
                !is_array($data['competencies'])) {
            $message = is_array($data) && isset($data['message']) ? clean_param($data['message'], PARAM_TEXT) : '';
            return $fail($message !== '' ? $message : get_string('ai_generationfailed', 'local_trainingmatrix'));
        }

        $created = [];
        $linked = [];
        $now = time();
        foreach ($data['competencies'] as $comp) {
            if (!is_array($comp)) {
                continue;
            }
            // Remote data is untrusted: clean everything before it reaches the database.
            $name = \core_text::substr(trim(clean_param((string)($comp['name'] ?? ''), PARAM_TEXT)), 0, 255);
            if ($name === '') {
                continue;
            }
            $code = \core_text::substr(clean_param((string)($comp['code'] ?? ''), PARAM_ALPHANUMEXT), 0, 50);
            $category = \core_text::substr(trim(clean_param((string)($comp['category'] ?? ''), PARAM_TEXT)), 0, 100);
            $description = clean_param((string)($comp['description'] ?? ''), PARAM_TEXT);
            $mandatory = !empty($comp['mandatory']) ? 1 : 0;

            // Match existing competencies by code or name regardless of case, so "Manual handling" and
            // "Manual Handling" do not become two competencies.
            // Only shared competencies or the position's own client's can be reused; never another client's.
            $existing = false;
            $fit = '(clientid = 0 OR clientid = :client)';
            if ($code !== '') {
                $existing = $DB->get_record_select('local_tm_competencies',
                    $DB->sql_equal('code', ':code', false) . " AND $fit",
                    ['code' => $code, 'client' => (int)$position->clientid], 'id', IGNORE_MULTIPLE);
            }
            if (!$existing) {
                $existing = $DB->get_record_select('local_tm_competencies',
                    $DB->sql_equal('name', ':name', false) . " AND $fit",
                    ['name' => $name, 'client' => (int)$position->clientid], 'id', IGNORE_MULTIPLE);
            }
            if ($existing) {
                $compid = (int)$existing->id;
                $linked[] = $name;
            } else {
                $compid = (int)$DB->insert_record('local_tm_competencies', (object)[
                    'name' => $name,
                    'code' => $code,
                    'category' => $category !== '' ? $category : 'Other',
                    'description' => $description,
                    'mandatory' => $mandatory,
                    'clientid' => (int)$position->clientid,
                    'timecreated' => $now,
                    'timemodified' => $now,
                ]);
                $created[] = $name;
            }
            if (!$DB->record_exists('local_tm_pos_comps', ['positionid' => $positionid, 'competencyid' => $compid])) {
                $DB->insert_record('local_tm_pos_comps', (object)[
                    'positionid' => $positionid,
                    'competencyid' => $compid,
                    'mandatory' => $mandatory,
                    'timecreated' => $now,
                ]);
            }
        }

        // Reused competencies may already link a course: enrol current holders of the position in it.
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
        local_trainingmatrix_provision_positions([$positionid]);

        return [
            'ok' => true,
            'error' => '',
            'created' => $created,
            'linked' => $linked,
            'credits_used' => self::CREDITS_PER_POSITION,
            'credits_remaining' => isset($data['credits_remaining']) ? (int)$data['credits_remaining'] :
                ($available === -1 ? -1 : $available - self::CREDITS_PER_POSITION),
        ];
    }
}
