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

namespace local_trainingmatrix\task;

defined('MOODLE_INTERNAL') || die();

class sync_completions extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_sync_completions', 'local_trainingmatrix');
    }

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

        // Get all competency-course links
        $compCourses = $DB->get_records('local_tm_comp_courses');

        $syncCount = 0;
        $now = time();

        foreach ($compCourses as $cc) {
            // Get all staff who have this competency required
            $staffWithComp = $DB->get_records_sql("
                SELECT DISTINCT s.userid
                FROM {local_tm_staff} s
                JOIN {local_tm_pos_comps} pc ON pc.positionid = s.positionid
                WHERE pc.competencyid = ? AND s.status = 'active'
            ", [$cc->competencyid]);

            foreach ($staffWithComp as $staff) {
                // Check Moodle course completion
                $completion = $DB->get_record('course_completions', [
                    'course' => $cc->courseid,
                    'userid' => $staff->userid,
                ]);

                if ($completion && $completion->timecompleted) {
                    // Calculate expiry date
                    $expiryDate = $completion->timecompleted + ($cc->expirymonths * 30 * 24 * 60 * 60);

                    // Check existing training matrix completion
                    $tmCompletion = $DB->get_record('local_tm_completions', [
                        'userid' => $staff->userid,
                        'competencyid' => $cc->competencyid,
                        'courseid' => $cc->courseid,
                    ]);

                    $status = 'completed';
                    if ($expiryDate < $now) {
                        $status = 'expired';
                    } else if ($expiryDate < ($now + (30 * 24 * 60 * 60))) {
                        $status = 'expiring_soon';
                    }

                    if ($tmCompletion) {
                        // Update existing
                        if ($tmCompletion->completiondate != $completion->timecompleted ||
                            $tmCompletion->status != $status) {
                            $tmCompletion->completiondate = $completion->timecompleted;
                            $tmCompletion->expirydate = $expiryDate;
                            $tmCompletion->status = $status;
                            $tmCompletion->progress = 100;
                            $tmCompletion->timemodified = $now;
                            $DB->update_record('local_tm_completions', $tmCompletion);
                            $syncCount++;
                        }
                    } else {
                        // Create new
                        $newCompletion = new \stdClass();
                        $newCompletion->userid = $staff->userid;
                        $newCompletion->competencyid = $cc->competencyid;
                        $newCompletion->courseid = $cc->courseid;
                        $newCompletion->completiondate = $completion->timecompleted;
                        $newCompletion->expirydate = $expiryDate;
                        $newCompletion->status = $status;
                        $newCompletion->progress = 100;
                        $newCompletion->notified = 0;
                        $newCompletion->timecreated = $now;
                        $newCompletion->timemodified = $now;
                        $DB->insert_record('local_tm_completions', $newCompletion);
                        $syncCount++;
                    }
                } else {
                    // Check for in-progress
                    $activities = $DB->get_record_sql("
                        SELECT COUNT(cmc.id) as completed, 
                               (SELECT COUNT(*) FROM {course_modules} cm WHERE cm.course = ? AND cm.completion > 0) as total
                        FROM {course_modules_completion} cmc
                        JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                        WHERE cm.course = ? AND cmc.userid = ? AND cmc.completionstate > 0
                    ", [$cc->courseid, $cc->courseid, $staff->userid]);

                    if ($activities && $activities->total > 0) {
                        $progress = round(($activities->completed / $activities->total) * 100);

                        $tmCompletion = $DB->get_record('local_tm_completions', [
                            'userid' => $staff->userid,
                            'competencyid' => $cc->competencyid,
                            'courseid' => $cc->courseid,
                        ]);

                        $status = $progress > 0 ? 'in_progress' : 'not_started';

                        if ($tmCompletion) {
                            if ($tmCompletion->progress != $progress && $tmCompletion->status != 'completed') {
                                $tmCompletion->progress = $progress;
                                $tmCompletion->status = $status;
                                $tmCompletion->timemodified = $now;
                                $DB->update_record('local_tm_completions', $tmCompletion);
                                $syncCount++;
                            }
                        } else {
                            $newCompletion = new \stdClass();
                            $newCompletion->userid = $staff->userid;
                            $newCompletion->competencyid = $cc->competencyid;
                            $newCompletion->courseid = $cc->courseid;
                            $newCompletion->status = $status;
                            $newCompletion->progress = $progress;
                            $newCompletion->notified = 0;
                            $newCompletion->timecreated = $now;
                            $newCompletion->timemodified = $now;
                            $DB->insert_record('local_tm_completions', $newCompletion);
                            $syncCount++;
                        }
                    }
                }
            }
        }

        mtrace("Synced $syncCount training completion records.");
    }
}
