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
 * Scheduled task that sends training notifications.
 */
class send_notifications extends \core\task\scheduled_task {
    /**
     * Get the task name shown to admins.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_send_notifications', 'local_trainingmatrix');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

        $now = time();

        // Get completions expiring soon that haven't been notified.
        $expiringcompletions = $DB->get_records_sql("
            SELECT tc.*, c.name AS competency_name, cc.notifydays, course.fullname AS course_name
            FROM {local_trainingmatrix_compl} tc
            JOIN {local_trainingmatrix_comp} c ON c.id = tc.competencyid
            JOIN {local_trainingmatrix_compcrs} cc ON cc.competencyid = c.id AND cc.courseid = tc.courseid
            JOIN {course} course ON course.id = tc.courseid
            JOIN {user} u ON u.id = tc.userid
            WHERE tc.notified = 0
              AND u.deleted = 0 AND u.suspended = 0
              AND tc.status IN ('completed', 'expiring_soon')
              AND tc.expirydate IS NOT NULL
              AND tc.expirydate > ?
              AND tc.expirydate <= (? + (cc.notifydays * 86400))
              AND EXISTS (SELECT 1
                            FROM {local_trainingmatrix_staff} s
                            JOIN {local_trainingmatrix_poscomp} pc ON pc.positionid = s.positionid
                           WHERE s.userid = tc.userid AND s.status = 'active' AND pc.competencyid = tc.competencyid)
        ", [$now, $now]);

        $count = 0;
        foreach ($expiringcompletions as $tc) {
            $factory = \core\lock\lock_config::get_lock_factory('local_trainingmatrix');
            $lock = $factory->get_lock('expiry_notification_' . $tc->id, 0);
            if (!$lock) {
                continue;
            }
            try {
                // The query above may predate another worker's successful send.
                if ($DB->get_field('local_trainingmatrix_compl', 'notified', ['id' => $tc->id])) {
                    continue;
                }
                $daysuntilexpiry = ceil(($tc->expirydate - $now) / 86400);

                $user = \core_user::get_user($tc->userid, '*', MUST_EXIST);

                $a = new \stdClass();
                $a->competency = $tc->competency_name;
                $a->days = $daysuntilexpiry;
                $a->date = userdate(
                    $tc->expirydate,
                    get_string('strftimedate', 'langconfig'),
                    \core_date::get_user_timezone($user)
                );
                $a->course = format_string($tc->course_name, true, ['context' => \context_course::instance($tc->courseid)]);

                $subject = get_string('notification_subject_expiry', 'local_trainingmatrix', $a);
                $message = get_string('notification_body_expiry', 'local_trainingmatrix', $a);

                // Create notification record.
                $notification = new \stdClass();
                $notification->userid = $tc->userid;
                $notification->completionid = $tc->id;
                $notification->type = 'expiry_warning';
                $notification->subject = $subject;
                $notification->message = $message;
                $notification->sent = 0;
                $notification->timecreated = $now;
                $notification->timesent = 0;
                $pending = $DB->get_record('local_trainingmatrix_notif', [
                'completionid' => $tc->id, 'type' => 'expiry_warning', 'sent' => 0,
                ], '*', IGNORE_MULTIPLE);
                if ($pending) {
                    $notification->id = $pending->id;
                    $DB->update_record('local_trainingmatrix_notif', $notification);
                } else {
                    $notification->id = $DB->insert_record('local_trainingmatrix_notif', $notification);
                }

                if (!$this->send_email($user, $subject, $message)) {
                    mtrace("Training expiry notification failed for completion {$tc->id}; retained for retry.");
                    continue;
                }

                // Transport acceptance is not proof of final mailbox delivery.
                $notification->sent = 1;
                $notification->timesent = time();
                $transaction = $DB->start_delegated_transaction();
                $DB->update_record('local_trainingmatrix_notif', $notification);
                $DB->set_field('local_trainingmatrix_compl', 'notified', 1, ['id' => $tc->id]);
                $transaction->allow_commit();
                $count++;
            } finally {
                $lock->release();
            }
        }

        mtrace("Sent $count training expiry notifications.");
    }

    /**
     * Isolated transport boundary for deterministic failure tests.
     *
     * @param \stdClass $user Recipient.
     * @param string $subject Subject.
     * @param string $message Body.
     * @return bool Whether Moodle accepted the send.
     */
    protected function send_email(\stdClass $user, string $subject, string $message): bool {
        return email_to_user($user, \core_user::get_noreply_user(), $subject, $message);
    }
}
