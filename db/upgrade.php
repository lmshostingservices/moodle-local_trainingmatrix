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
 * Upgrade steps for local_trainingmatrix.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run upgrade steps.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_trainingmatrix_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026111200) {
        // Client scoping. Existing records become clientid 0: shared definitions and staff not tied to a client,
        // which is exactly how they behaved before this release.
        $comments = [
            'local_tm_departments' => 'Client portal client id; 0 = shared across all clients',
            'local_tm_positions' => 'Client portal client id; 0 = shared across all clients',
            'local_tm_competencies' => 'Client portal client id; 0 = shared across all clients',
            'local_tm_staff' => 'Client portal client id; 0 = not tied to a client',
        ];
        foreach ($comments as $tablename => $comment) {
            $table = new xmldb_table($tablename);
            $field = new xmldb_field('clientid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', null, $comment);
            if (!$dbman->field_exists($table, $field)) {
                $dbman->add_field($table, $field);
            }
            $index = new xmldb_index('clientid', XMLDB_INDEX_NOTUNIQUE, ['clientid']);
            if (!$dbman->index_exists($table, $index)) {
                $dbman->add_index($table, $index);
            }
        }
        $table = new xmldb_table('local_tm_staff');
        $index = new xmldb_index('employeeid', XMLDB_INDEX_NOTUNIQUE, ['employeeid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }
        upgrade_plugin_savepoint(true, 2026111200, 'local', 'trainingmatrix');
    }

    if ($oldversion < 2026111301) {
        // Rename the tables to use the plugin's own name as their prefix. Data, keys and indexes move with them.
        $renames = [
            'local_tm_notifications' => 'local_trainingmatrix_notif',
            'local_tm_comp_courses' => 'local_trainingmatrix_compcrs',
            'local_tm_competencies' => 'local_trainingmatrix_comp',
            'local_tm_completions' => 'local_trainingmatrix_compl',
            'local_tm_departments' => 'local_trainingmatrix_dept',
            'local_tm_pos_comps' => 'local_trainingmatrix_poscomp',
            'local_tm_positions' => 'local_trainingmatrix_pos',
            'local_tm_staff' => 'local_trainingmatrix_staff',
        ];
        foreach ($renames as $oldname => $newname) {
            $table = new xmldb_table($oldname);
            if ($dbman->table_exists($table) && !$dbman->table_exists($newname)) {
                $dbman->rename_table($table, $newname);
            }
        }
        upgrade_plugin_savepoint(true, 2026111301, 'local', 'trainingmatrix');
    }

    return true;
}
