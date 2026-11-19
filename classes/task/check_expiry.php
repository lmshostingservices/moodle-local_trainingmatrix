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
 * Scheduled task that flags expiring and expired competencies.
 */
class check_expiry extends \core\task\scheduled_task {
    /**
     * Get the task name shown to admins.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_check_expiry', 'local_trainingmatrix');
    }

    /**
     * Run the task.
     */
    public function execute() {
        global $DB;

        $now = time();

        $DB->execute("
            UPDATE {local_trainingmatrix_compl}
            SET status = 'expired', timemodified = ?
            WHERE status IN ('completed', 'expiring_soon')
              AND expirydate IS NOT NULL
              AND expirydate < ?
        ", [$now, $now]);

        // Expiring soon uses each course link's own "days before expiry to notify" (30 when the link has gone).
        $soon = $DB->get_fieldset_sql("
            SELECT tc.id
              FROM {local_trainingmatrix_compl} tc
         LEFT JOIN {local_trainingmatrix_compcrs} cc ON cc.competencyid = tc.competencyid AND cc.courseid = tc.courseid
             WHERE tc.status = 'completed'
               AND tc.expirydate IS NOT NULL
               AND tc.expirydate >= ?
               AND tc.expirydate <= ? + COALESCE(cc.notifydays, 30) * 86400
        ", [$now, $now]);
        foreach (array_chunk($soon, 500) as $ids) {
            [$in, $params] = $DB->get_in_or_equal($ids);
            $DB->execute(
                "UPDATE {local_trainingmatrix_compl} SET status = 'expiring_soon', timemodified = ? WHERE id $in",
                array_merge([$now], $params)
            );
        }

        mtrace('Training expiry status check completed.');
    }
}
