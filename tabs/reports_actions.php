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

// Form actions for the reports tab. Included by index.php before any output so redirect() works.

if ($action === 'remind' && confirm_sesskey()) {
    $completionid = required_param('cid', PARAM_INT);
    $returnreport = optional_param('report', 'expiry', PARAM_ALPHA);
    $tc = $DB->get_record_sql("SELECT tc.*, c.name AS competency_name, co.fullname AS course_name
                                 FROM {local_tm_completions} tc
                                 JOIN {local_tm_competencies} c ON c.id = tc.competencyid
                            LEFT JOIN {course} co ON co.id = tc.courseid
                                WHERE tc.id = ?", [$completionid], MUST_EXIST);
    // Reminders only go to people inside the sender's scope.
    if (!$accessscope->contains_user((int)$tc->userid)) {
        throw new required_capability_exception($context, 'local/trainingmatrix:viewall', 'nopermissions', '');
    }
    $recipient = core_user::get_user($tc->userid, '*', MUST_EXIST);
    $a = new stdClass();
    $a->competency = format_string($tc->competency_name);
    $a->course = $tc->course_name ? format_string($tc->course_name) : '';
    $a->date = $tc->expirydate ? userdate($tc->expirydate, get_string('strftimedate', 'langconfig'),
        core_date::get_user_timezone($recipient)) : '-';
    $a->days = $tc->expirydate ? (int)ceil(($tc->expirydate - time()) / DAYSECS) : 0;
    $subject = get_string('reminder_subject', 'local_trainingmatrix', $a);
    $body = get_string($a->days < 0 ? 'reminder_body_expired' : 'reminder_body_expiring', 'local_trainingmatrix', $a);
    $sent = !$recipient->deleted && !$recipient->suspended &&
        email_to_user($recipient, core_user::get_noreply_user(), $subject, $body);
    $DB->insert_record('local_tm_notifications', (object)[
        'userid' => $tc->userid, 'completionid' => $tc->id, 'type' => 'manual_reminder', 'subject' => $subject,
        'message' => $body, 'sent' => $sent ? 1 : 0, 'timecreated' => time(), 'timesent' => $sent ? time() : null,
    ]);
    redirect(new moodle_url('/local/trainingmatrix/index.php', ['tab' => 'reports', 'report' => $returnreport]),
        get_string($sent ? 'reminder_sent' : 'reminder_failed', 'local_trainingmatrix', fullname($recipient)), null,
        $sent ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR);
}
