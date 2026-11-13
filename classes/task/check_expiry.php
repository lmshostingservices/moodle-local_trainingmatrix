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
        $thirtydays = $now + (30 * 24 * 60 * 60);

        // Update status for expired completions.
        $DB->execute("
            UPDATE {local_trainingmatrix_compl}
            SET status = 'expired', timemodified = ?
            WHERE status IN ('completed', 'expiring_soon')
              AND expirydate IS NOT NULL
              AND expirydate < ?
        ", [$now, $now]);

        // Update status for expiring soon.
        $DB->execute("
            UPDATE {local_trainingmatrix_compl}
            SET status = 'expiring_soon', timemodified = ?
            WHERE status = 'completed'
              AND expirydate IS NOT NULL
              AND expirydate BETWEEN ? AND ?
        ", [$now, $now, $thirtydays]);

        mtrace('Training expiry status check completed.');
    }
}
