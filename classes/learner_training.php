<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software under the GNU General Public License v3 or later.

namespace local_trainingmatrix;

defined('MOODLE_INTERNAL') || die();

/**
 * Read-only, current-user projection. Never issues awards or runs reset services.
 *
 * @package local_trainingmatrix
 * @copyright 2026 LMS-Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class learner_training {
    /** Return only the signed-in learner's records, including historical documents. */
    public static function get(): array {
        global $USER, $CFG;
        require_login();
        require_capability('local/trainingmatrix:view', \context_system::instance());
        require_once($CFG->libdir . '/enrollib.php');
        $result = ['items' => [], 'notices' => []];
        $courses = enrol_get_users_courses($USER->id, false, 'id,fullname,visible,enablecompletion');
        self::courses($result, $courses);
        self::programs($result);
        self::delivery_plans($result, $courses);
        self::recertification($result, $courses);
        self::certificates($result);
        self::rto_awards($result);
        return $result;
    }

    /** Optional vocational schedule, never interpreted as program completion. */
    private static function delivery_plans(array &$result, array $courses): void {
        global $DB, $USER;
        if (!self::available([
            'block_trainingplan_schedule' => ['id', 'userid', 'courseid', 'enddate', 'status'],
        ], 'Training Plan', $result)) {
            return;
        }
        foreach ($DB->get_records('block_trainingplan_schedule', ['userid' => $USER->id]) as $plan) {
            if (!isset($courses[$plan->courseid])) {
                continue;
            }
            $result['items'][] = self::item('program', $courses[$plan->courseid]->fullname,
                'Training Plan', 'Delivery schedule: ' . $plan->status, (int)$plan->enddate);
        }
    }

    /** Preserve the regulated issuer's own download and validation controls. */
    private static function rto_awards(array &$result): void {
        global $DB, $USER, $CFG;
        if (!self::available([
            'local_rtocompliance_certs' => ['id', 'userid', 'status', 'certtype', 'certnumber'],
        ], 'RTO Compliance', $result)) {
            return;
        }
        if (!is_readable($CFG->dirroot . '/local/rtocompliance/mycerts.php') ||
                !has_capability('local/rtocompliance:viewown', \context_user::instance($USER->id))) {
            $result['notices'][] = 'RTO Compliance: certificate wallet access unavailable.';
            return;
        }
        foreach ($DB->get_records('local_rtocompliance_certs',
                ['userid' => $USER->id, 'status' => 'issued']) as $award) {
            $result['items'][] = self::item('certificate', $award->certtype . ' — ' . $award->certnumber,
                'RTO Compliance', 'Issued — view document with statutory issuer', 0, 0,
                new \moodle_url('/local/rtocompliance/mycerts.php'));
        }
    }

    /** Fail closed for absent or partially upgraded optional schemas. */
    private static function available(array $tables, string $source, array &$result): bool {
        global $DB;
        foreach ($tables as $table => $fields) {
            if (!$DB->get_manager()->table_exists(new \xmldb_table($table)) ||
                    array_diff($fields, array_keys($DB->get_columns($table)))) {
                $result['notices'][] = $source . ': unavailable (not installed or requires an upgrade).';
                return false;
            }
        }
        return true;
    }

    /** Common presentation contract; dates retain their source meaning. */
    private static function item(string $kind, string $name, string $source, string $status,
            int $due = 0, int $expires = 0, ?\moodle_url $url = null, string $action = ''): array {
        return compact('kind', 'name', 'source', 'status', 'due', 'expires', 'url', 'action');
    }

    /** Core completion is not activity progress, and course end date is not a deadline. */
    private static function courses(array &$result, array $courses): void {
        global $DB, $USER;
        foreach ($courses as $course) {
            $context = \context_course::instance($course->id);
            $access = can_access_course($course, $USER, '', true);
            if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) {
                $access = false;
            }
            $completed = $DB->get_field('course_completions', 'timecompleted',
                ['course' => $course->id, 'userid' => $USER->id]);
            $status = $completed ? 'Course completed' :
                ($course->enablecompletion ? 'Course not completed' : 'Completion tracking unavailable');
            $result['items'][] = self::item('course', $course->fullname, 'Moodle', $status, 0, 0,
                $access ? new \moodle_url('/course/view.php', ['id' => $course->id]) : null,
                $access ? '' : 'Course access unavailable — contact your training team');
        }
    }

    /** Required program evidence is independent of optional task percentages. */
    private static function programs(array &$result): void {
        global $DB, $USER;
        if (!self::available([
            'local_tp_assignments' => ['id', 'userid', 'pathwayid', 'status', 'targetdate'],
            'local_tp_pathways' => ['id', 'name'],
            'local_tp_stages' => ['id', 'pathwayid'],
            'local_tp_tasks' => ['id', 'stageid', 'required', 'tasktype', 'courseid'],
            'local_tp_progress' => ['assignmentid', 'taskid', 'userid', 'status', 'signedoffby'],
        ], 'Training Pathways', $result)) {
            return;
        }
        if (!has_capability('local/trainingpathways:view', \context_system::instance())) {
            $result['notices'][] = 'Training Pathways: program access unavailable.';
            return;
        }
        $assignments = $DB->get_records_sql(
            'SELECT a.*, p.name FROM {local_tp_assignments} a
               JOIN {local_tp_pathways} p ON p.id = a.pathwayid WHERE a.userid = :userid',
            ['userid' => $USER->id]);
        foreach ($assignments as $assignment) {
            $tasks = $DB->get_records_sql(
                'SELECT t.* FROM {local_tp_tasks} t JOIN {local_tp_stages} s ON s.id = t.stageid
                  WHERE s.pathwayid = :pathwayid AND t.required = 1', ['pathwayid' => $assignment->pathwayid]);
            $done = 0;
            $signoff = false;
            foreach ($tasks as $task) {
                $progress = $DB->get_record('local_tp_progress', [
                    'assignmentid' => $assignment->id, 'taskid' => $task->id, 'userid' => $USER->id,
                ]);
                if ($task->tasktype === 'course') {
                    // Legacy task ticks cannot substitute for official Moodle completion.
                    $complete = $task->courseid && $DB->get_field('course_completions', 'timecompleted',
                        ['course' => $task->courseid, 'userid' => $USER->id]);
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
            $result['items'][] = self::item('program', $assignment->name, 'Training Pathways',
                $status, (int)$assignment->targetdate, 0, null, $action);
        }
    }

    /** Legacy reset schedules provide dates, not a certification validity ledger. */
    private static function recertification(array &$result, array $courses): void {
        global $DB, $USER, $CFG;
        if (!self::available([
            'local_recertify_schedule' => ['id', 'courseid', 'enabled', 'scheduletype', 'intervalmonths', 'fixeddate'],
            'local_recertify_log' => ['scheduleid', 'userid', 'action', 'resettime'],
        ], 'Course Recertification', $result)) {
            return;
        }
        $lib = $CFG->dirroot . '/local/recertify/lib.php';
        if (is_readable($lib)) {
            require_once($lib);
        }
        if (!function_exists('local_recertify_next_reset_time') ||
                !function_exists('local_recertify_last_due_reset_time')) {
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
                ['userid' => $USER->id, 'courseid' => $course->id, 'nowstart' => time(), 'nowend' => time()]);
            $completed = (int)$DB->get_field('course_completions', 'timecompleted',
                ['course' => $course->id, 'userid' => $USER->id]);
            $dates = [];
            foreach ($enrolments as $enrolment) {
                $start = (int)$enrolment->timestart ?: (int)$enrolment->timecreated;
                $due = local_recertify_last_due_reset_time($schedule, $start, time(), $completed);
                if (!$due || $DB->record_exists('local_recertify_log', [
                    'scheduleid' => $schedule->id, 'userid' => $USER->id, 'action' => 'reset', 'resettime' => $due,
                ])) {
                    $due = local_recertify_next_reset_time($schedule, $start, time(), $completed);
                }
                if ($due) {
                    $dates[] = $due;
                }
            }
            $due = $dates ? min($dates) : 0;
            $result['items'][] = self::item('certification', $course->fullname, 'Course Recertification',
                'Certification validity unavailable — reset schedule only', $due, 0, null,
                !$enrolments ? 'No active enrolment for renewal' :
                    ($due && $due <= time() ? 'Scheduled reset due; not evidence of renewal' : ''));
        }
    }

    /** Existing stored PDFs only: get_file() would render/write a missing document. */
    private static function certificates(array &$result): void {
        global $DB, $USER;
        if (!self::available([
            'certificatepro' => ['id', 'course', 'name'],
            'certificatepro_issues' => ['id', 'userid', 'certificateproid', 'expires', 'revoked'],
        ], 'Certificate Pro', $result)) {
            return;
        }
        $issues = $DB->get_records_sql(
            'SELECT i.*, c.course, c.name FROM {certificatepro_issues} i
               JOIN {certificatepro} c ON c.id = i.certificateproid WHERE i.userid = :userid',
            ['userid' => $USER->id]);
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
                    has_capability('mod/certificatepro:view', $context);
                if ($access && in_array($documentstatus, ['valid', 'expired'], true)) {
                    $files = get_file_storage()->get_area_files($context->id, 'mod_certificatepro',
                        'issue', $issue->id, 'id', false);
                    if ($files) {
                        $file = reset($files);
                        $url = \moodle_url::make_pluginfile_url($context->id, 'mod_certificatepro', 'issue',
                            $issue->id, $file->get_filepath(), $file->get_filename(), true);
                        if ($status === 'Document pending') {
                            $status = 'Document available';
                        }
                    }
                }
            }
            $result['items'][] = self::item('certificate', $issue->name, 'Certificate Pro', $status,
                0, (int)$issue->expires, $url,
                !$access ? 'Document access unavailable — contact the issuer' :
                    ($status === 'Document pending' ? 'Awaiting stored PDF from issuer' : ''));
        }
    }
}
