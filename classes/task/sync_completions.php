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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_trainingmatrix\task;

/**
 * Scheduled task that records competencies completed through courses.
 */
class sync_completions extends \core\task\scheduled_task {
    /**
     * Get the task name shown to admins.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sync_completions', 'local_trainingmatrix');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

        // Get all competency-course links.
        $compcourses = $DB->get_records('local_trainingmatrix_compcrs');

        $synccount = 0;
        $now = time();

        foreach ($compcourses as $cc) {
            // Get all staff who have this competency required.
            $staffwithcomp = $DB->get_records_sql("
                SELECT DISTINCT s.userid
                FROM {local_trainingmatrix_staff} s
                JOIN {local_trainingmatrix_poscomp} pc ON pc.positionid = s.positionid
                WHERE pc.competencyid = ? AND s.status = 'active'
            ", [$cc->competencyid]);

            foreach ($staffwithcomp as $staff) {
                // Check Moodle course completion.
                $completion = $DB->get_record('course_completions', [
                    'course' => $cc->courseid,
                    'userid' => $staff->userid,
                ]);

                if ($completion && $completion->timecompleted) {
                    // Calculate expiry date.
                    $expirydate = local_trainingmatrix_expiry_date(
                        (int)$completion->timecompleted,
                        (int)$cc->expirymonths
                    );

                    // Check existing training matrix completion.
                    $tmcompletion = $DB->get_record('local_trainingmatrix_compl', [
                        'userid' => $staff->userid,
                        'competencyid' => $cc->competencyid,
                        'courseid' => $cc->courseid,
                    ]);

                    $status = local_trainingmatrix_status_for_expiry($expirydate, $now);

                    if ($tmcompletion) {
                        // Update existing.
                        if (
                            $tmcompletion->completiondate != $completion->timecompleted ||
                            $tmcompletion->expirydate != $expirydate ||
                            $tmcompletion->status != $status
                        ) {
                            if (
                                $tmcompletion->completiondate != $completion->timecompleted ||
                                    $tmcompletion->expirydate != $expirydate
                            ) {
                                $tmcompletion->notified = 0;
                            }
                            $tmcompletion->completiondate = $completion->timecompleted;
                            $tmcompletion->expirydate = $expirydate;
                            $tmcompletion->status = $status;
                            $tmcompletion->progress = 100;
                            $tmcompletion->timemodified = $now;
                            $DB->update_record('local_trainingmatrix_compl', $tmcompletion);
                            $synccount++;
                        }
                    } else {
                        // Create new.
                        $newcompletion = new \stdClass();
                        $newcompletion->userid = $staff->userid;
                        $newcompletion->competencyid = $cc->competencyid;
                        $newcompletion->courseid = $cc->courseid;
                        $newcompletion->completiondate = $completion->timecompleted;
                        $newcompletion->expirydate = $expirydate;
                        $newcompletion->status = $status;
                        $newcompletion->progress = 100;
                        $newcompletion->notified = 0;
                        $newcompletion->timecreated = $now;
                        $newcompletion->timemodified = $now;
                        $DB->insert_record('local_trainingmatrix_compl', $newcompletion);
                        $synccount++;
                    }
                } else {
                    // Check for in-progress.
                    $activities = $DB->get_record_sql("
                        SELECT COUNT(cmc.id) as completed,
                               (SELECT COUNT(*) FROM {course_modules} cm2 WHERE cm2.course = ? AND cm2.completion > 0
                                AND cm2.deletioninprogress = 0) as total
                        FROM {course_modules_completion} cmc
                        JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
                        WHERE cm.course = ? AND cm.completion > 0 AND cmc.userid = ? AND cmc.completionstate > 0
                    ", [$cc->courseid, $cc->courseid, $staff->userid]);

                    if ($activities && $activities->total > 0) {
                        $progress = round(($activities->completed / $activities->total) * 100);

                        $tmcompletion = $DB->get_record('local_trainingmatrix_compl', [
                            'userid' => $staff->userid,
                            'competencyid' => $cc->competencyid,
                            'courseid' => $cc->courseid,
                        ]);

                        $status = $progress > 0 ? 'in_progress' : 'not_started';

                        if ($tmcompletion) {
                            if ($tmcompletion->progress != $progress && $tmcompletion->status != 'completed') {
                                $tmcompletion->progress = $progress;
                                $tmcompletion->status = $status;
                                $tmcompletion->timemodified = $now;
                                $DB->update_record('local_trainingmatrix_compl', $tmcompletion);
                                $synccount++;
                            }
                        } else {
                            $newcompletion = new \stdClass();
                            $newcompletion->userid = $staff->userid;
                            $newcompletion->competencyid = $cc->competencyid;
                            $newcompletion->courseid = $cc->courseid;
                            $newcompletion->status = $status;
                            $newcompletion->progress = $progress;
                            $newcompletion->notified = 0;
                            $newcompletion->timecreated = $now;
                            $newcompletion->timemodified = $now;
                            $DB->insert_record('local_trainingmatrix_compl', $newcompletion);
                            $synccount++;
                        }
                    }
                }
            }
        }

        mtrace("Synced $synccount training completion records.");
    }
}
