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

require_login(null, false);
require_sesskey();
$context = context_system::instance();
require_capability('local/trainingmatrix:manage', $context);

// Release session lock before long-running API calls to prevent blocking other requests.
\core\session\manager::write_close();

header('Content-Type: application/json');
$client = new \local_trainingmatrix\local\ai_client();

switch ($action) {
    case 'getcredits':
        $result = $client->get_credits();
        echo json_encode($result['ok'] ? [
            'ok' => true,
            'credits' => $result['credits'],
            'credits_required' => \local_trainingmatrix\local\ai_client::CREDITS_PER_POSITION,
        ] : ['ok' => false, 'error' => $result['error']]);
        break;

    case 'generate':
        $positionid = required_param('positionid', PARAM_INT);
        $result = $client->generate($positionid);
        echo json_encode($result['ok'] ? [
            'ok' => true,
            'message' => get_string('competencies_generated', 'local_trainingmatrix'),
            'created' => $result['created'],
            'linked' => $result['linked'],
            'credits_used' => $result['credits_used'],
            'credits_remaining' => $result['credits_remaining'],
        ] : ['ok' => false, 'error' => $result['error']]);
        break;

    default:
        echo json_encode(['ok' => false, 'error' => get_string('unknownaction', 'local_trainingmatrix')]);
}
