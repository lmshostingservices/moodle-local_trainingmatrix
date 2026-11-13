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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_trainingmatrix;

/**
 * Read-only, explicitly authorised subject projection. Never issues awards or runs reset services.
 *
 * @package local_trainingmatrix
 * @copyright 2026 LMS-Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class learner_training {
    /**
     * Return only the signed-in learner's records, including historical documents.
     *
     * @return array
     */
    public static function get(): array {
        global $USER, $CFG;
        return self::for_user((int)$USER->id);
    }

    /**
     * Project an explicit subject without ever changing the authenticated actor.
     *
     * @param int $userid The user id; 0 for the current user.
     * @return array
     */
    public static function for_user(int $userid): array {
        global $USER, $CFG, $DB;
        require_login();
        // Own records, or a subject inside the viewer's staff scope (all staff, or their own team).
        \local_trainingmatrix\local\scope::require_view_user($userid);
        $subject = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        require_once($CFG->libdir . '/enrollib.php');
        $result = ['items' => [], 'notices' => []];
        $courses = enrol_get_users_courses($userid, false, 'id,fullname,visible,enablecompletion,startdate,enddate');
        self::courses($result, $courses, $subject);
        self::programs($result, $subject);
        self::delivery_plans($result, $courses, $subject);
        self::recertification($result, $courses, $subject);
        self::certificates($result, $subject);
        if ($userid === (int)$USER->id) {
            self::rto_awards($result);
        } else {
            $result['notices'][] = 'RTO Compliance: statutory wallet is available to its owner only.';
        }
        return $result;
    }

    /**
     * Optional vocational schedule, never interpreted as program completion.
     *
     * @param array $result Result being built, passed by reference.
     * @param array $courses The learner's courses.
     * @param \stdClass $subject The learner.
     */
    private static function delivery_plans(array &$result, array $courses, \stdClass $subject): void {
        global $DB;
        if (
            !self::available([
            'block_trainingplan_schedule' => ['id', 'userid', 'courseid', 'enddate', 'status'],
            ], 'Training Plan', $result)
        ) {
            return;
        }
        foreach ($DB->get_records('block_trainingplan_schedule', ['userid' => $subject->id]) as $plan) {
            if (!isset($courses[$plan->courseid])) {
                continue;
            }
            $result['items'][] = self::item(
                'program',
                $courses[$plan->courseid]->fullname,
                'Training Plan',
                'Delivery schedule: ' . $plan->status,
                (int)$plan->enddate
            );
        }
    }

    /**
     * Preserve the regulated issuer's own download and validation controls.
     *
     * @param array $result Result being built, passed by reference.
     */
    private static function rto_awards(array &$result): void {
        global $DB, $USER, $CFG;
        if (
            !self::available([
            'local_rtocompliance_certs' => ['id', 'userid', 'status', 'certtype', 'certnumber'],
            ], 'RTO Compliance', $result)
        ) {
            return;
        }
        if (
            !is_readable($CFG->dirroot . '/local/rtocompliance/mycerts.php') ||
                !has_capability('local/rtocompliance:viewown', \context_user::instance($USER->id))
        ) {
            $result['notices'][] = 'RTO Compliance: certificate wallet access unavailable.';
            return;
        }
        foreach (
            $DB->get_records(
                'local_rtocompliance_certs',
                ['userid' => $USER->id, 'status' => 'issued']
            ) as $award
        ) {
            $result['items'][] = self::item(
                'certificate',
                $award->certtype . ' — ' . $award->certnumber,
                'RTO Compliance',
                'Issued — view document with statutory issuer',
                0,
                0,
                new \moodle_url('/local/rtocompliance/mycerts.php')
            );
        }
    }

    /**
     * Fail closed for absent or partially upgraded optional schemas.
     *
     * @param array $tables Tables the source needs.
     * @param string $source Source name shown to the learner.
     * @param array $result Result being built, passed by reference.
     * @return bool True if available.
     */
    private static function available(array $tables, string $source, array &$result): bool {
        global $DB;
        foreach ($tables as $table => $fields) {
            if (
                !$DB->get_manager()->table_exists(new \xmldb_table($table)) ||
                    array_diff($fields, array_keys($DB->get_columns($table)))
            ) {
                $result['notices'][] = $source . ': unavailable (not installed or requires an upgrade).';
                return false;
            }
        }
        return true;
    }

    /**
     * Common presentation contract; dates retain their source meaning.
     *
     * @param string $kind Item type.
     * @param string $name Item name.
     * @param string $source Source name shown to the learner.
     * @param string $status Status text.
     * @param int $due Due date, or 0.
     * @param int $expires Expiry date, or 0.
     * @param ?\moodle_url $url Link to the item, if any.
     * @param string $action Next step text.
     * @return array
     */
    private static function item(
        string $kind,
        string $name,
        string $source,
        string $status,
        int $due = 0,
        int $expires = 0,
        ?\moodle_url $url = null,
        string $action = ''
    ): array {
        return compact('kind', 'name', 'source', 'status', 'due', 'expires', 'url', 'action');
    }

    /**
     * Core completion is not activity progress, and course end date is not a deadline.
     *
     * @param array $result Result being built, passed by reference.
     * @param array $courses The learner's courses.
     * @param \stdClass $subject The learner.
     */
    private static function courses(array &$result, array $courses, \stdClass $subject): void {
        global $DB;
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $access = can_access_course($course, $subject, '', true);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context, $subject)) {
                $access = false;
            }
            $completed = $DB->get_field(
                'course_completions',
                'timecompleted',
                ['course' => $course->id, 'userid' => $subject->id]
            );
            $status = $completed ? 'Course completed' :
                ($course->enablecompletion ? 'Course not completed' : 'Completion tracking unavailable');
            $sequence = class_exists('\\local_trainingpathways\\sequence_service')
                ? \local_trainingpathways\sequence_service::blockers((int)$subject->id, (int)$course->id) : [];
            if ($sequence) {
                $access = false;
                $status = 'Locked';
            }
            $item = self::item(
                'course',
                $course->fullname,
                'Moodle',
                $status,
                0,
                0,
                $access ? new \moodle_url('/course/view.php', ['id' => $course->id]) : null,
                $sequence ? implode('; ', $sequence) :
                ($access ? '' : 'Course access unavailable — contact your training team')
            );
            $item['courseid'] = (int)$course->id;
            $item['state'] = $sequence ? 'locked' : ($completed ? 'completed' : ($access ? 'available' : 'locked'));
            $item['dates'] = ['Course starts' => (int)$course->startdate,
                'Course ends (not a deadline)' => (int)$course->enddate, 'Course completed' => (int)$completed];
            $enrolments = $DB->get_records_sql(
                'SELECT ue.id, ue.timestart, ue.timeend, ue.status,
                    e.status AS methodstatus FROM {user_enrolments} ue JOIN {enrol} e ON e.id = ue.enrolid
                    WHERE ue.userid = :userid AND e.courseid = :courseid',
                ['userid' => $subject->id, 'courseid' => $course->id]
            );
            foreach ($enrolments as $enrolment) {
                $item['dates']['Enrolment ' . $enrolment->id . ' starts'] = (int)$enrolment->timestart;
                $item['dates']['Enrolment ' . $enrolment->id . ' ends'] = (int)$enrolment->timeend;
                if ($enrolment->status || $enrolment->methodstatus) {
                    $item['action'] = trim($item['action'] . '; Enrolment ' . $enrolment->id . ' suspended', '; ');
                }
            }
            $item['evidence'] = self::activity_evidence($course, $subject);
            $result['items'][] = $item;
        }
    }

    /**
     * Submission and quiz evidence are never substituted for official completion.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $subject The learner.
     * @return array
     */
    private static function activity_evidence(\stdClass $course, \stdClass $subject): array {
        global $DB, $CFG;
        $rows = [];
        $modinfo = get_fast_modinfo($course, $subject->id);
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            if ($cm->modname === 'assign') {
                require_once($CFG->dirroot . '/mod/assign/locallib.php');
                $assign = new \assign(\context_module::instance($cm->id), $cm, $course);
                $assignment = $assign->get_instance((int)$subject->id);
                $override = $assign->override_exists((int)$subject->id);
                foreach (['duedate', 'cutoffdate', 'allowsubmissionsfromdate'] as $field) {
                    if ($override->{$field} !== null) {
                        $assignment->{$field} = $override->{$field};
                    }
                }
                $submission = $assignment->teamsubmission ?
                    $assign->get_group_submission($subject->id, 0, false) :
                    $assign->get_user_submission($subject->id, false);
                $extension = $DB->get_record(
                    'assign_user_flags',
                    ['assignment' => $cm->instance, 'userid' => $subject->id]
                );
                $grade = $submission ? $DB->get_record(
                    'assign_grades',
                    ['assignment' => $cm->instance, 'userid' => $subject->id,
                    'attemptnumber' => $submission->attemptnumber]
                ) : false;
                $submitted = $submission && $submission->status === 'submitted';
                $awaiting = $submitted && (!$grade || $grade->grade === null || $grade->grade < 0 ||
                    $grade->timemodified < $submission->timemodified);
                $rows[] = ['name' => $cm->name,
                    'status' => $submitted ? ($awaiting ? 'Submitted — awaiting marking' : 'Submitted — mark recorded') :
                        'No submitted assignment recorded',
                    'awaiting' => $awaiting,
                    'dates' => ['Submission' => $submitted ? (int)$submission->timemodified : 0,
                        'Assignment due' => (int)$assignment->duedate,
                        'Submission opens' => (int)$assignment->allowsubmissionsfromdate,
                        'Submission cutoff' => (int)$assignment->cutoffdate,
                        'Extension due' => $extension ? (int)$extension->extensionduedate : 0]];
            } else if ($cm->modname === 'quiz') {
                require_once($CFG->dirroot . '/mod/quiz/lib.php');
                $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
                $quiz = quiz_update_effective_access($quiz, $subject->id);
                $attempts = $DB->get_records(
                    'quiz_attempts',
                    ['quiz' => $cm->instance, 'userid' => $subject->id, 'preview' => 0, 'state' => 'finished'],
                    'timefinish DESC',
                    '*',
                    0,
                    1
                );
                $attempt = $attempts ? reset($attempts) : false;
                $rows[] = ['name' => $cm->name, 'status' => $attempt ?
                    'Quiz attempt finished — no pass outcome inferred' : 'No finished quiz attempt',
                    'awaiting' => false, 'dates' => ['Quiz opens' => (int)$quiz->timeopen,
                        'Quiz closes' => (int)$quiz->timeclose, 'Attempt finished' => $attempt ? (int)$attempt->timefinish : 0]];
            }
        }
        return $rows;
    }

    /**
     * Required program evidence is independent of optional task percentages.
     *
     * @param array $result Result being built, passed by reference.
     * @param \stdClass $subject The learner.
     */
    private static function programs(array &$result, \stdClass $subject): void {
        global $DB;
        if (
            !self::available([
            'local_tp_assignments' => ['id', 'userid', 'pathwayid', 'status', 'targetdate'],
            'local_tp_pathways' => ['id', 'name'],
            'local_tp_stages' => ['id', 'pathwayid'],
            'local_tp_tasks' => ['id', 'stageid', 'required', 'tasktype', 'courseid'],
            'local_tp_progress' => ['assignmentid', 'taskid', 'userid', 'status', 'signedoffby'],
            ], 'Training Pathways', $result)
        ) {
            return;
        }
        if (!has_capability('local/trainingpathways:view', \context_system::instance())) {
            $result['notices'][] = 'Training Pathways: program access unavailable.';
            return;
        }
        $assignments = $DB->get_records_sql(
            'SELECT a.*, p.name FROM {local_tp_assignments} a
               JOIN {local_tp_pathways} p ON p.id = a.pathwayid WHERE a.userid = :userid',
            ['userid' => $subject->id]
        );
        foreach ($assignments as $assignment) {
            $tasks = $DB->get_records_sql(
                'SELECT t.* FROM {local_tp_tasks} t JOIN {local_tp_stages} s ON s.id = t.stageid
                  WHERE s.pathwayid = :pathwayid AND t.required = 1',
                ['pathwayid' => $assignment->pathwayid]
            );
            $done = 0;
            $signoff = false;
            foreach ($tasks as $task) {
                $progress = $DB->get_record('local_tp_progress', [
                    'assignmentid' => $assignment->id, 'taskid' => $task->id, 'userid' => $subject->id,
                ]);
                if ($task->tasktype === 'course') {
                    // Legacy task ticks cannot substitute for official Moodle completion.
                    $complete = $task->courseid && $DB->get_field(
                        'course_completions',
                        'timecompleted',
                        ['course' => $task->courseid, 'userid' => $subject->id]
                    );
                } else {
                    $complete = $progress && $progress->status === 'complete';
                    if ($task->tasktype === 'signoff') {
                        $complete = $complete && !empty($progress->signedoffby);
                        $signoff = $signoff || !$complete;
                    }
                }
                $done += $complete ? 1 : 0;
            }
            $satisfied = count($tasks) > 0 && $done === count($tasks);
            $status = $satisfied ? 'Program requirements satisfied' : 'Program requirements not satisfied';
            $status .= ' (' . $done . '/' . count($tasks) . ' required; assignment: ' . $assignment->status . ')';
            $action = $signoff ? 'Sign-off required' : '';
            if ($assignment->status === 'completed' && !$satisfied) {
                $action = 'Historical completion needs evidence review';
            } else if (!$satisfied && $assignment->targetdate && $assignment->targetdate < time()) {
                $action = trim($action . ' — Program overdue', ' —');
            }
            $result['items'][] = self::item(
                'program',
                $assignment->name,
                'Training Pathways',
                $status,
                (int)$assignment->targetdate,
                0,
                null,
                $action
            );
        }
    }

    /**
     * Legacy reset schedules provide dates, not a certification validity ledger.
     *
     * @param array $result Result being built, passed by reference.
     * @param array $courses The learner's courses.
     * @param \stdClass $subject The learner.
     */
    private static function recertification(array &$result, array $courses, \stdClass $subject): void {
        global $DB, $CFG;
        if (
            !self::available([
            'local_recertify_schedule' => ['id', 'courseid', 'enabled', 'scheduletype', 'intervalmonths', 'fixeddate'],
            'local_recertify_log' => ['scheduleid', 'userid', 'action', 'resettime'],
            ], 'Course Recertification', $result)
        ) {
            return;
        }
        $lib = $CFG->dirroot . '/local/recertify/lib.php';
        if (is_readable($lib)) {
            require_once($lib);
        }
        if (
            !function_exists('local_recertify_next_reset_time') ||
                !function_exists('local_recertify_last_due_reset_time')
        ) {
            $result['notices'][] = 'Course Recertification: schedule reader requires an upgrade.';
            return;
        }
        $result['notices'][] = 'Course Recertification provides reset schedules, not earned certification validity. '
            . 'Certificate expiry is document expiry; neither proves current certification.';
        foreach ($courses as $course) {
            $schedule = $DB->get_record('local_recertify_schedule', ['courseid' => $course->id, 'enabled' => 1]);
            if (!$schedule) {
                continue;
            }
            $enrolments = $DB->get_records_sql(
                'SELECT ue.id, ue.timestart, ue.timecreated FROM {user_enrolments} ue
                   JOIN {enrol} e ON e.id = ue.enrolid
                  WHERE ue.userid = :userid AND e.courseid = :courseid AND ue.status = 0 AND e.status = 0
                    AND (ue.timestart = 0 OR ue.timestart <= :nowstart)
                    AND (ue.timeend = 0 OR ue.timeend > :nowend)',
                ['userid' => $subject->id, 'courseid' => $course->id, 'nowstart' => time(), 'nowend' => time()]
            );
            $completed = (int)$DB->get_field(
                'course_completions',
                'timecompleted',
                ['course' => $course->id, 'userid' => $subject->id]
            );
            $dates = [];
            foreach ($enrolments as $enrolment) {
                $start = (int)$enrolment->timestart ?: (int)$enrolment->timecreated;
                $due = local_recertify_last_due_reset_time($schedule, $start, time(), $completed);
                if (
                    !$due || $DB->record_exists('local_recertify_log', [
                    'scheduleid' => $schedule->id, 'userid' => $subject->id, 'action' => 'reset', 'resettime' => $due,
                    ])
                ) {
                    $due = local_recertify_next_reset_time($schedule, $start, time(), $completed);
                }
                if ($due) {
                    $dates[] = $due;
                }
            }
            $due = $dates ? min($dates) : 0;
            $result['items'][] = self::item(
                'certification',
                $course->fullname,
                'Course Recertification',
                'Certification validity unavailable — reset schedule only',
                $due,
                0,
                null,
                !$enrolments ? 'No active enrolment for renewal' :
                ($due && $due <= time() ? 'Scheduled reset due; not evidence of renewal' : '')
            );
        }
    }

    /**
     * Existing stored PDFs only: get_file() would render/write a missing document.
     *
     * @param array $result Result being built, passed by reference.
     * @param \stdClass $subject The learner.
     */
    private static function certificates(array &$result, \stdClass $subject): void {
        global $DB, $USER;
        if (
            !self::available([
            'certificatepro' => ['id', 'course', 'name'],
            'certificatepro_issues' => ['id', 'userid', 'certificateproid', 'expires', 'revoked'],
            ], 'Certificate Pro', $result)
        ) {
            return;
        }
        $issues = $DB->get_records_sql(
            'SELECT i.*, c.course, c.name FROM {certificatepro_issues} i
               JOIN {certificatepro} c ON c.id = i.certificateproid WHERE i.userid = :userid',
            ['userid' => $subject->id]
        );
        foreach ($issues as $issue) {
            $cm = get_coursemodule_from_instance('certificatepro', $issue->certificateproid, $issue->course);
            $url = null;
            $status = $issue->revoked ? 'Document revoked' :
                ($issue->expires && $issue->expires <= time() ? 'Document expired' : 'Document pending');
            $documentstatus = class_exists('\\mod_certificatepro\\local\\issue_service')
                ? \mod_certificatepro\local\issue_service::status($issue)
                : ($issue->revoked ? 'revoked' : (!empty($issue->cycleid) ? 'unknown' :
                    ($issue->expires && $issue->expires <= time() ? 'expired' : 'valid')));
            if (!empty($issue->cycleid)) {
                $labels = ['revoked' => 'Document revoked', 'expired' => 'Document expired',
                    'pending' => 'Document pending', 'unknown' => 'Document unavailable', 'valid' => 'Document pending'];
                $status = $labels[$documentstatus] ?? 'Document unavailable';
            }
            $access = false;
            if ($cm) {
                $context = \context_module::instance($cm->id);
                $course = get_course($issue->course);
                $modinfo = get_fast_modinfo($course)->get_cm($cm->id);
                $access = can_access_course($course, $USER, '', true) && $modinfo->uservisible &&
                    has_capability('mod/certificatepro:view', $context) &&
                    ((int)$subject->id === (int)$USER->id || has_capability('mod/certificatepro:viewreport', $context));
                if ($access && !$issue->revoked && in_array($documentstatus, ['valid', 'expired'], true)) {
                    $files = get_file_storage()->get_area_files(
                        $context->id,
                        'mod_certificatepro',
                        'issue',
                        $issue->id,
                        'id',
                        false
                    );
                    foreach ($files as $file) {
                        if (
                            $file->get_mimetype() !== 'application/pdf' ||
                                strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION)) !== 'pdf'
                        ) {
                            continue;
                        }
                        $url = \moodle_url::make_pluginfile_url(
                            $context->id,
                            'mod_certificatepro',
                            'issue',
                            $issue->id,
                            $file->get_filepath(),
                            $file->get_filename(),
                            true
                        );
                        if ($status === 'Document pending') {
                            $status = 'Document available';
                        }
                        break;
                    }
                }
            }
            $item = self::item(
                'certificate',
                $issue->name,
                'Certificate Pro',
                $status,
                0,
                (int)$issue->expires,
                $url,
                !$access ? 'Document access unavailable — contact the issuer' :
                ($status === 'Document pending' ? 'Awaiting stored PDF from issuer' : '')
            );
            $item['courseid'] = (int)$issue->course;
            $item['state'] = $documentstatus;
            $item['dates'] = ['Document issued' => (int)($issue->timecreated ?? 0),
                'Document revoked' => (int)($issue->timerevoked ?? 0)];
            $result['items'][] = $item;
        }
    }
}
