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

namespace local_trainingmatrix\local;

use stdClass;

/**
 * Bulk create or update staff records from a CSV file.
 *
 * Each row is checked first (preview), then applied one row at a time: a row that fails, for example because a required
 * course cannot take a manual enrolment, is rolled back and reported without stopping the others.
 *
 * Columns (header row, any order, case-insensitive):
 *   username | email | useridnumber   at least one, to find the Moodle account
 *   position                           required: position name
 *   employeeid, department, manager (username or email), startdate, enddate (YYYY-MM-DD or DD/MM/YYYY),
 *   status (active, inactive, terminated), client (client name)
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class staff_import {
    /** @var string[] Columns the importer understands. */
    public const COLUMNS = ['username', 'email', 'useridnumber', 'employeeid', 'position', 'department', 'manager',
        'startdate', 'enddate', 'status', 'client'];

    /** @var int client used when a row names none */
    private int $defaultclient;
    /** @var bool create positions and departments that do not exist yet */
    private bool $createmissing;
    /** @var array userid => line, to catch the same person twice in one file */
    private array $seenusers = [];
    /** @var array employeeid|client => line */
    private array $seenemployees = [];
    /** @var array cache of definitions created during apply(): "type|client|name" => id */
    private array $created = [];

    /**
     * Constructor.
     *
     * @param int $defaultclient
     * @param bool $createmissing
     */
    public function __construct(int $defaultclient = 0, bool $createmissing = false) {
        $this->defaultclient = tenancy::exists($defaultclient) ? $defaultclient : 0;
        $this->createmissing = $createmissing;
    }

    /**
     * Normalise a header row; unknown columns are reported, not fatal.
     *
     * @param array $columns raw header cells
     * @return array [normalised names, unknown names]
     */
    public static function normalise_columns(array $columns): array {
        $names = [];
        $unknown = [];
        foreach ($columns as $column) {
            $name = \core_text::strtolower(trim(str_replace([' ', '_', '-'], '', (string)$column)));
            $aliases = ['user' => 'username', 'emailaddress' => 'email', 'idnumber' => 'useridnumber',
                'employeenumber' => 'employeeid', 'empid' => 'employeeid', 'role' => 'position',
                'jobtitle' => 'position', 'linemanager' => 'manager', 'company' => 'client'];
            $name = $aliases[$name] ?? $name;
            if (!in_array($name, self::COLUMNS, true)) {
                $unknown[] = (string)$column;
            }
            $names[] = $name;
        }
        return [$names, $unknown];
    }

    /**
     * Check the header has what the importer needs.
     *
     * @param array $names normalised column names
     * @return string[] error messages
     */
    public static function header_errors(array $names): array {
        $errors = [];
        if (!array_intersect(['username', 'email', 'useridnumber'], $names)) {
            $errors[] = get_string('import_err_nousercol', 'local_trainingmatrix');
        }
        if (!in_array('position', $names, true)) {
            $errors[] = get_string('import_err_noposcol', 'local_trainingmatrix');
        }
        return $errors;
    }

    /**
     * Turn one CSV row into a plan, recording every problem found.
     *
     * @param array $names normalised column names
     * @param array $cells row cells
     * @param int $line line number in the file (header is line 1)
     * @return stdClass plan
     */
    public function plan(array $names, array $cells, int $line): stdClass {
        global $DB;
        $row = [];
        foreach ($names as $i => $name) {
            if (in_array($name, self::COLUMNS, true)) {
                $row[$name] = trim((string)($cells[$i] ?? ''));
            }
        }
        $plan = (object)[
            'line' => $line, 'errors' => [], 'warnings' => [], 'action' => 'error',
            'userid' => 0, 'userlabel' => $row['username'] ?? $row['email'] ?? $row['useridnumber'] ?? '',
            'staffid' => 0, 'clientid' => $this->defaultclient,
            'positionid' => 0, 'positionname' => $row['position'] ?? '',
            'departmentid' => null, 'departmentname' => $row['department'] ?? '',
            'managerid' => null, 'employeeid' => $row['employeeid'] ?? '',
            'startdate' => null, 'enddate' => null, 'status' => 'active',
        ];

        // Account.
        $user = $this->find_user($row, $plan);
        if ($user) {
            $plan->userid = (int)$user->id;
            $plan->userlabel = fullname($user) . ' (' . $user->email . ')';
            if (isset($this->seenusers[$user->id])) {
                $plan->errors[] = get_string('import_err_duplicateuser', 'local_trainingmatrix', $this->seenusers[$user->id]);
            } else {
                $this->seenusers[$user->id] = $line;
            }
        }

        // Client.
        if (($row['client'] ?? '') !== '') {
            $clientid = array_search(
                \core_text::strtolower($row['client']),
                array_map('core_text::strtolower', tenancy::clients()),
                true
            );
            if ($clientid === false) {
                $plan->errors[] = get_string('import_err_noclient', 'local_trainingmatrix', s($row['client']));
            } else {
                $plan->clientid = (int)$clientid;
            }
        }
        if ($plan->userid && !tenancy::is_learner($plan->clientid, $plan->userid)) {
            $plan->errors[] = get_string('client_notlearner', 'local_trainingmatrix', tenancy::name($plan->clientid));
        }

        // Employee ID.
        if ($plan->employeeid !== '' && clean_param($plan->employeeid, PARAM_ALPHANUMEXT) !== $plan->employeeid) {
            $plan->errors[] = get_string('import_err_employeeid', 'local_trainingmatrix', s($plan->employeeid));
        } else if ($plan->employeeid !== '') {
            $key = $plan->employeeid . '|' . $plan->clientid;
            if (isset($this->seenemployees[$key])) {
                $plan->errors[] = get_string(
                    'import_err_duplicateemployee',
                    'local_trainingmatrix',
                    $this->seenemployees[$key]
                );
            } else {
                $this->seenemployees[$key] = $line;
            }
        }

        // Position and department: the client's own is preferred over a shared one with the same name.
        if ($plan->positionname === '') {
            $plan->errors[] = get_string('import_err_noposition', 'local_trainingmatrix');
        } else {
            $plan->positionid = $this->find_definition('local_trainingmatrix_pos', $plan->positionname, $plan->clientid);
            if (!$plan->positionid) {
                if ($this->createmissing) {
                    $plan->warnings[] = get_string(
                        'import_willcreate_position',
                        'local_trainingmatrix',
                        s($plan->positionname)
                    );
                } else {
                    $plan->errors[] = get_string(
                        'import_err_positionmissing',
                        'local_trainingmatrix',
                        s($plan->positionname)
                    );
                }
            }
        }
        if ($plan->departmentname !== '') {
            $plan->departmentid = $this->find_definition('local_trainingmatrix_dept', $plan->departmentname, $plan->clientid);
            if (!$plan->departmentid) {
                if ($this->createmissing) {
                    $plan->warnings[] = get_string(
                        'import_willcreate_department',
                        'local_trainingmatrix',
                        s($plan->departmentname)
                    );
                } else {
                    $plan->errors[] = get_string(
                        'import_err_departmentmissing',
                        'local_trainingmatrix',
                        s($plan->departmentname)
                    );
                }
            }
        }

        // Manager.
        if (($row['manager'] ?? '') !== '') {
            $manager = $this->find_user(['username' => $row['manager'], 'email' => $row['manager']], null);
            if (!$manager) {
                $plan->errors[] = get_string('import_err_manager', 'local_trainingmatrix', s($row['manager']));
            } else if ((int)$manager->id === $plan->userid) {
                $plan->errors[] = get_string('import_err_selfmanager', 'local_trainingmatrix');
            } else {
                $plan->managerid = (int)$manager->id;
            }
        }

        // Dates and status.
        foreach (['startdate', 'enddate'] as $field) {
            if (($row[$field] ?? '') !== '') {
                $time = self::parse_date($row[$field]);
                if ($time === null) {
                    $plan->errors[] = get_string('import_err_date', 'local_trainingmatrix', s($row[$field]));
                } else {
                    $plan->{$field} = $time;
                }
            }
        }
        if (($row['status'] ?? '') !== '') {
            $status = \core_text::strtolower($row['status']);
            if (!in_array($status, ['active', 'inactive', 'terminated'], true)) {
                $plan->errors[] = get_string('import_err_status', 'local_trainingmatrix', s($row['status']));
            } else {
                $plan->status = $status;
            }
        }

        // Update or create: the same employee ID within the client first, then the person's existing record.
        if ($plan->userid) {
            $existing = null;
            if ($plan->employeeid !== '') {
                $existing = $DB->get_record('local_trainingmatrix_staff', ['employeeid' => $plan->employeeid,
                    'clientid' => $plan->clientid], '*', IGNORE_MULTIPLE);
                if ($existing && (int)$existing->userid !== $plan->userid) {
                    $plan->errors[] = get_string('import_err_employeetaken', 'local_trainingmatrix', s($plan->employeeid));
                    $existing = null;
                }
            }
            if (!$existing) {
                $records = $DB->get_records_sql("SELECT * FROM {local_trainingmatrix_staff} WHERE userid = :userid
                    ORDER BY CASE WHEN status = 'active' THEN 0 ELSE 1 END, id DESC", ['userid' => $plan->userid], 0, 1);
                $existing = $records ? reset($records) : null;
            }
            if ($existing) {
                $plan->staffid = (int)$existing->id;
                if ((int)$existing->clientid !== $plan->clientid) {
                    $plan->warnings[] = get_string('import_clientchange', 'local_trainingmatrix', (object)[
                        'from' => tenancy::name((int)$existing->clientid), 'to' => tenancy::name($plan->clientid)]);
                }
            }
        }

        if (!$plan->errors) {
            $plan->action = $plan->staffid ? 'update' : 'create';
        }
        return $plan;
    }

    /**
     * Apply a clean plan: create missing definitions, save the staff record and enrol required courses.
     *
     * Must run outside any transaction; each row is its own unit of work.
     *
     * @param stdClass $plan from plan(), with no errors
     * @return string|null error message, or null on success
     */
    public function apply(stdClass $plan): ?string {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
        if ($plan->errors) {
            return implode(' ', $plan->errors);
        }
        $transaction = $DB->start_delegated_transaction();
        try {
            $now = time();
            $positionid = $plan->positionid ?: $this->create_definition(
                'local_trainingmatrix_pos',
                $plan->positionname,
                $plan->clientid,
                $now
            );
            $departmentid = $plan->departmentid;
            if ($plan->departmentname !== '' && !$departmentid) {
                $departmentid = $this->create_definition(
                    'local_trainingmatrix_dept',
                    $plan->departmentname,
                    $plan->clientid,
                    $now
                );
            }
            if (
                $plan->status === 'active' && $DB->record_exists_select(
                    'local_trainingmatrix_staff',
                    'userid = :userid AND status = :status AND id <> :id',
                    ['userid' => $plan->userid, 'status' => 'active', 'id' => $plan->staffid]
                )
            ) {
                throw new \moodle_exception('staff_duplicateactive', 'local_trainingmatrix');
            }
            $record = (object)[
                'userid' => $plan->userid, 'positionid' => $positionid, 'departmentid' => $departmentid ?: null,
                'managerid' => $plan->managerid, 'employeeid' => $plan->employeeid, 'clientid' => $plan->clientid,
                'startdate' => $plan->startdate, 'enddate' => $plan->enddate, 'status' => $plan->status,
                'timemodified' => $now,
            ];
            if ($plan->staffid) {
                $record->id = $plan->staffid;
                $DB->update_record('local_trainingmatrix_staff', $record);
            } else {
                $record->timecreated = $now;
                $record->id = $DB->insert_record('local_trainingmatrix_staff', $record);
            }
            if ($record->status === 'active') {
                local_trainingmatrix_enrol_required_courses((int)$record->userid, (int)$positionid);
            }
            $transaction->allow_commit();
            return null;
        } catch (\Throwable $e) {
            try {
                $transaction->rollback($e);
            } catch (\Throwable $rethrown) {
                // Rollback rethrows the original exception; report it.
                unset($rethrown);
            }
            // Anything created inside the rolled-back transaction is gone.
            $this->created = [];
            return $e->getMessage();
        }
    }

    /**
     * Plan every row of a loaded CSV file.
     *
     * @param \csv_import_reader $reader loaded reader
     * @return array ['plans' => stdClass[], 'errors' => string[] header problems, 'unknown' => string[] ignored columns]
     */
    public function plan_file(\csv_import_reader $reader): array {
        [$names, $unknown] = self::normalise_columns($reader->get_columns() ?: []);
        $errors = self::header_errors($names);
        $plans = [];
        if (!$errors) {
            $reader->init();
            $line = 1;
            while ($cells = $reader->next()) {
                $line++;
                if (!array_filter(array_map('trim', array_map('strval', $cells)), 'strlen')) {
                    continue;
                }
                $plans[] = $this->plan($names, $cells, $line);
            }
            $reader->close();
        }
        return ['plans' => $plans, 'errors' => $errors, 'unknown' => $unknown];
    }

    /**
     * Parse YYYY-MM-DD or DD/MM/YYYY (Australian order) in the server timezone.
     *
     * @param string $value
     * @return int|null
     */
    public static function parse_date(string $value): ?int {
        $value = trim($value);
        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $value, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $value, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        if (!checkdate($mo, $d, $y)) {
            return null;
        }
        $date = new \DateTimeImmutable(
            sprintf('%04d-%02d-%02d 00:00:00', $y, $mo, $d),
            \core_date::get_server_timezone_object()
        );
        return $date->getTimestamp();
    }

    /**
     * Find a single, active Moodle account by username, then email, then ID number.
     *
     * @param array $row
     * @param stdClass|null $plan receives an error when given
     * @return stdClass|null
     */
    private function find_user(array $row, ?stdClass $plan): ?stdClass {
        global $DB, $CFG;
        $tried = false;
        foreach (['username' => 'username', 'email' => 'email', 'useridnumber' => 'idnumber'] as $column => $field) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value === '') {
                continue;
            }
            $tried = true;
            $select = $DB->sql_equal($field, ':value', false) .
                ' AND deleted = 0 AND mnethostid = :mnethost';
            $users = $DB->get_records_select(
                'user',
                $select,
                ['value' => $field === 'username' ? \core_text::strtolower($value) : $value,
                'mnethost' => $CFG->mnet_localhost_id],
                'id',
                '*',
                0,
                2
            );
            if (count($users) > 1) {
                if ($plan) {
                    $plan->errors[] = get_string('import_err_ambiguoususer', 'local_trainingmatrix', s($value));
                }
                return null;
            }
            if ($users) {
                $user = reset($users);
                if ($user->suspended && $plan) {
                    $plan->errors[] = get_string('import_err_suspended', 'local_trainingmatrix', s($value));
                    return null;
                }
                return $user;
            }
        }
        if ($plan) {
            $plan->errors[] = $tried ? get_string('import_err_nouser', 'local_trainingmatrix', s($plan->userlabel)) :
                get_string('import_err_nouservalue', 'local_trainingmatrix');
        }
        return null;
    }

    /**
     * Id of a department or position usable by the client, by name; the client's own wins over a shared one.
     *
     * @param string $table
     * @param string $name
     * @param int $clientid
     * @return int 0 when none
     */
    private function find_definition(string $table, string $name, int $clientid): int {
        global $DB;
        $key = $table . '|' . $clientid . '|' . \core_text::strtolower($name);
        if (isset($this->created[$key])) {
            return $this->created[$key];
        }
        $records = $DB->get_records_select(
            $table,
            $DB->sql_equal('name', ':name', false) . ' AND (clientid = 0 OR clientid = :client)',
            ['name' => $name, 'client' => $clientid],
            'clientid DESC, id ASC',
            'id, clientid',
            0,
            1
        );
        return $records ? (int)reset($records)->id : 0;
    }

    /**
     * Create a department or position for the client (once per file).
     *
     * @param string $table
     * @param string $name
     * @param int $clientid
     * @param int $now
     * @return int
     */
    private function create_definition(string $table, string $name, int $clientid, int $now): int {
        global $DB;
        $existing = $this->find_definition($table, $name, $clientid);
        if ($existing) {
            return $existing;
        }
        $record = (object)['name' => clean_param($name, PARAM_TEXT), 'description' => '', 'clientid' => $clientid,
            'timecreated' => $now, 'timemodified' => $now];
        if ($table === 'local_trainingmatrix_pos') {
            $record->level = 1;
        } else {
            $record->sortorder = 0;
        }
        $id = (int)$DB->insert_record($table, $record);
        $this->created[$table . '|' . $clientid . '|' . \core_text::strtolower($name)] = $id;
        return $id;
    }
}
