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

defined('MOODLE_INTERNAL') || die();

// Form actions for the competencies tab. Included by index.php before any output so redirect() works.

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->name = required_param('name', PARAM_TEXT);
    $data->code = optional_param('code', '', PARAM_ALPHANUMEXT);
    $data->description = optional_param('description', '', PARAM_CLEANHTML);
    $data->category = optional_param('category', '', PARAM_TEXT);
    $data->mandatory = optional_param('mandatory', 0, PARAM_BOOL) ? 1 : 0;
    $data->timemodified = time();
    // Client: shared (0) or one client's own. Kept as-is when no Client portal clients exist.
    $existingclient = $id > 0 ? (int)$DB->get_field('local_tm_competencies', 'clientid', ['id' => $id]) : 0;
    $data->clientid = \local_trainingmatrix\local\tenancy::clients() ?
        optional_param('clientid', 0, PARAM_INT) : $existingclient;
    $conflict = local_trainingmatrix_definition_conflict('competency', $id, (int)$data->clientid);
    if ($conflict) {
        redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies', 'action' => $id ? 'edit' : 'add', 'id' => $id]),
            $conflict, null, \core\output\notification::NOTIFY_ERROR);
    }
    
    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_tm_competencies', $data);
    } else {
        $data->timecreated = time();
        $id = $DB->insert_record('local_tm_competencies', $data);
    }
    
    // Handle course link
    $courseid = optional_param('courseid', 0, PARAM_INT);
    $expirymonths = max(0, min(600, optional_param('expirymonths', 12, PARAM_INT)));
    $notifydays = max(0, min(365, optional_param('notifydays', 30, PARAM_INT)));

    $existinglinks = $DB->get_records('local_tm_comp_courses', ['competencyid' => $id]);
    $keep = null;
    foreach ($existinglinks as $link) {
        if ((int)$link->courseid === $courseid && $courseid > 0) {
            $keep = $link;
            continue;
        }
        // Course changed or removed: drop the link and the tracking rows that belonged to it.
        $DB->delete_records('local_tm_completions', ['competencyid' => $id, 'courseid' => $link->courseid]);
        $DB->delete_records('local_tm_comp_courses', ['id' => $link->id]);
    }
    if ($courseid > 0 && $DB->record_exists('course', ['id' => $courseid])) {
        if ($keep) {
            $keep->expirymonths = $expirymonths;
            $keep->notifydays = $notifydays;
            $DB->update_record('local_tm_comp_courses', $keep);
        } else {
            $DB->insert_record('local_tm_comp_courses', (object)['competencyid' => $id, 'courseid' => $courseid,
                'expirymonths' => $expirymonths, 'notifydays' => $notifydays, 'timecreated' => time()]);
            // Staff already in positions that require this competency need a tracking row for the new course.
            $holders = $DB->get_records_sql("SELECT DISTINCT s.userid
                                               FROM {local_tm_staff} s
                                               JOIN {local_tm_pos_comps} pc ON pc.positionid = s.positionid
                                              WHERE pc.competencyid = ? AND s.status = 'active'", [$id]);
            foreach ($holders as $holder) {
                $key = ['userid' => $holder->userid, 'competencyid' => $id, 'courseid' => $courseid];
                if (!$DB->record_exists('local_tm_completions', $key)) {
                    $DB->insert_record('local_tm_completions', (object)($key + ['status' => 'not_started',
                        'progress' => 0, 'timecreated' => time(), 'timemodified' => time()]));
                }
            }
        }
    }
    
    // Everyone in a position that requires this competency is enrolled in its (possibly new) course.
    $positionids = $DB->get_fieldset_select('local_tm_pos_comps', 'positionid', 'competencyid = ?', [$id]);
    local_trainingmatrix_notify_provision(local_trainingmatrix_provision_positions($positionids));

    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies']),
        get_string('competency_saved', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    $DB->delete_records('local_tm_comp_courses', ['competencyid' => $id]);
    $DB->delete_records('local_tm_pos_comps', ['competencyid' => $id]);
    $completionids = $DB->get_fieldset_select('local_tm_completions', 'id', 'competencyid = ?', [$id]);
    if ($completionids) {
        $DB->delete_records_list('local_tm_notifications', 'completionid', $completionids);
    }
    $DB->delete_records('local_tm_completions', ['competencyid' => $id]);
    $DB->delete_records('local_tm_competencies', ['id' => $id]);
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'competencies']),
        get_string('competency_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}
