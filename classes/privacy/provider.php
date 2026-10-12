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

namespace local_trainingmatrix\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

class provider implements \core_privacy\local\metadata\provider,
        \core_privacy\local\request\plugin\provider,
        \core_privacy\local\request\core_userlist_provider {
    private const FIELDS = [
        'local_tm_staff' => ['userid', 'positionid', 'departmentid', 'startdate', 'enddate',
            'status', 'managerid', 'employeeid', 'timecreated', 'timemodified'],
        'local_tm_completions' => ['userid', 'competencyid', 'courseid', 'completiondate',
            'expirydate', 'status', 'progress', 'notified', 'timecreated', 'timemodified'],
        'local_tm_notifications' => ['userid', 'completionid', 'type', 'subject', 'message',
            'sent', 'timecreated', 'timesent'],
    ];

    public static function get_metadata(collection $collection): collection {
        foreach (self::FIELDS as $table => $fields) {
            $collection->add_database_table($table,
                array_fill_keys($fields, 'privacy:metadata:field'), 'privacy:metadata:' . $table);
        }
        $collection->add_database_table('local_tm_departments',
            ['managerid' => 'privacy:metadata:field'], 'privacy:metadata:local_tm_departments');
        return $collection;
    }

    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;
        $contexts = new contextlist();
        foreach (self::FIELDS as $table => $fields) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                $contexts->add_system_context();
                return $contexts;
            }
        }
        if ($DB->record_exists('local_tm_staff', ['managerid' => $userid]) ||
                $DB->record_exists('local_tm_departments', ['managerid' => $userid])) {
            $contexts->add_system_context();
        }
        return $contexts;
    }

    public static function get_users_in_context(userlist $userlist): void {
        if ($userlist->get_context()->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        foreach (self::FIELDS as $table => $fields) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}}", []);
        }
        foreach (['local_tm_staff', 'local_tm_departments'] as $table) {
            $userlist->add_from_sql('managerid', "SELECT managerid FROM {{$table}} WHERE managerid > 0", []);
        }
    }

    public static function export_user_data(approved_contextlist $contexts): void {
        global $DB;
        if (!in_array(\context_system::instance()->id, $contexts->get_contextids())) {
            return;
        }
        $userid = $contexts->get_user()->id;
        $data = new \stdClass();
        foreach (self::FIELDS as $table => $fields) {
            $data->$table = array_values($DB->get_records($table, ['userid' => $userid]));
        }
        // Export only the manager's relationship, not other employees' private records.
        $data->managedstaff = array_values($DB->get_records('local_tm_staff',
            ['managerid' => $userid], '', 'id, userid, managerid'));
        $data->manageddepartments = array_values($DB->get_records('local_tm_departments',
            ['managerid' => $userid], '', 'id, managerid'));
        writer::with_context(\context_system::instance())->export_data(
            [get_string('pluginname', 'local_trainingmatrix')], $data);
    }

    public static function delete_data_for_all_users_in_context(\context $context): void {
        global $DB;
        if ($context->contextlevel !== CONTEXT_SYSTEM) {
            return;
        }
        foreach (array_reverse(array_keys(self::FIELDS)) as $table) {
            $DB->delete_records($table);
        }
        $DB->set_field('local_tm_departments', 'managerid', null);
    }

    public static function delete_data_for_user(approved_contextlist $contexts): void {
        if (in_array(\context_system::instance()->id, $contexts->get_contextids())) {
            self::delete_user((int)$contexts->get_user()->id);
        }
    }

    public static function delete_data_for_users(approved_userlist $users): void {
        if ($users->get_context()->contextlevel === CONTEXT_SYSTEM) {
            foreach ($users->get_userids() as $userid) {
                self::delete_user((int)$userid);
            }
        }
    }

    private static function delete_user(int $userid): void {
        global $DB;
        foreach (array_reverse(array_keys(self::FIELDS)) as $table) {
            $DB->delete_records($table, ['userid' => $userid]);
        }
        $DB->set_field('local_tm_staff', 'managerid', null, ['managerid' => $userid]);
        $DB->set_field('local_tm_departments', 'managerid', null, ['managerid' => $userid]);
    }
}
