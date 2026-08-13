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

class send_notifications extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('task_send_notifications', 'local_trainingmatrix');
    }

    public function execute() {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');

        $now = time();

        // Get completions expiring soon that haven't been notified
        $expiringCompletions = $DB->get_records_sql("
            SELECT tc.*, c.name as competency_name, cc.notifydays, course.fullname as course_name,
                   u.id as userid, u.firstname, u.lastname, u.email
            FROM {local_tm_completions} tc
            JOIN {local_tm_competencies} c ON c.id = tc.competencyid
            JOIN {local_tm_comp_courses} cc ON cc.competencyid = c.id AND cc.courseid = tc.courseid
            JOIN {course} course ON course.id = tc.courseid
            JOIN {user} u ON u.id = tc.userid
            WHERE tc.notified = 0
              AND tc.expirydate IS NOT NULL
              AND tc.expirydate > ?
              AND tc.expirydate <= (? + (cc.notifydays * 86400))
        ", [$now, $now]);

        $count = 0;
        foreach ($expiringCompletions as $tc) {
            $daysUntilExpiry = ceil(($tc->expirydate - $now) / 86400);

            $user = new \stdClass();
            $user->id = $tc->userid;
            $user->email = $tc->email;
            $user->firstname = $tc->firstname;
            $user->lastname = $tc->lastname;

            $a = new \stdClass();
            $a->competency = $tc->competency_name;
            $a->days = $daysUntilExpiry;
            $a->date = userdate($tc->expirydate, '%d %B %Y');
            $a->course = $tc->course_name;

            $subject = get_string('notification_subject_expiry', 'local_trainingmatrix', $a);
            $message = get_string('notification_body_expiry', 'local_trainingmatrix', $a);

            // Create notification record
            $notification = new \stdClass();
            $notification->userid = $tc->userid;
            $notification->completionid = $tc->id;
            $notification->type = 'expiry_warning';
            $notification->subject = $subject;
            $notification->message = $message;
            $notification->sent = 1;
            $notification->timecreated = $now;
            $notification->timesent = $now;
            $DB->insert_record('local_tm_notifications', $notification);

            // Mark completion as notified
            $DB->set_field('local_tm_completions', 'notified', 1, ['id' => $tc->id]);

            // Send email
            $admin = get_admin();
            email_to_user($user, $admin, $subject, $message);

            $count++;
        }

        mtrace("Sent $count training expiry notifications.");
    }
}
