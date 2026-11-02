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

// Form actions for the positions tab. Included by index.php before any output so redirect() works.

// Handle form submissions
if ($action === 'save' && confirm_sesskey()) {
    $data = new stdClass();
    $data->name = required_param('name', PARAM_TEXT);
    $data->description = optional_param('description', '', PARAM_CLEANHTML);
    $data->departmentid = optional_param('departmentid', 0, PARAM_INT) ?: null;
    $data->level = optional_param('level', 1, PARAM_INT);
    $data->timemodified = time();
    
    if ($id > 0) {
        $data->id = $id;
        $DB->update_record('local_tm_positions', $data);
    } else {
        $data->timecreated = time();
        $id = $DB->insert_record('local_tm_positions', $data);
    }
    
    // Handle competency assignments: keep existing links (and their mandatory flag), add new, remove unticked.
    $competencies = array_unique(array_filter(optional_param_array('competencies', [], PARAM_INT)));
    $current = $DB->get_records_menu('local_tm_pos_comps', ['positionid' => $id], '', 'competencyid, id');
    foreach ($competencies as $compid) {
        if (!isset($current[$compid]) && $DB->record_exists('local_tm_competencies', ['id' => $compid])) {
            $DB->insert_record('local_tm_pos_comps', (object)['positionid' => $id, 'competencyid' => $compid,
                'mandatory' => 1, 'timecreated' => time()]);
        }
        unset($current[$compid]);
    }
    if ($current) {
        $DB->delete_records_list('local_tm_pos_comps', 'id', array_values($current));
    }
    
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions']),
        get_string('position_saved', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}

if ($action === 'delete' && $id > 0 && confirm_sesskey()) {
    if ($DB->record_exists('local_tm_staff', ['positionid' => $id])) {
        redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions']),
            get_string('position_inuse', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_ERROR);
    }
    $DB->delete_records('local_tm_pos_comps', ['positionid' => $id]);
    $DB->delete_records('local_tm_positions', ['id' => $id]);
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'positions']),
        get_string('position_deleted', 'local_trainingmatrix'), null, \core\output\notification::NOTIFY_SUCCESS);
}
