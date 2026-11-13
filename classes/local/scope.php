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
 * Which staff records a viewer may see.
 *
 * Every staff list, statistic, report, export and reminder goes through one of these, so the rules live in one place:
 *
 * - all:    the viewer holds local/trainingmatrix:viewall. Optionally narrowed to one client.
 * - team:   the viewer holds local/trainingmatrix:viewteam and sees their direct reports (staff whose manager they are)
 *           plus everyone in departments they manage, including sub-departments. Optionally narrowed to one client.
 * - client: everyone tied to one Client portal client. Used by the portal; never chosen from capabilities.
 * - none:   nobody.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class scope {
    /** @var string Sees every staff record. */
    public const ALL = 'all';
    /** @var string Sees direct reports and managed departments. */
    public const TEAM = 'team';
    /** @var string Sees one client's staff. */
    public const CLIENT = 'client';
    /** @var string Sees nobody. */
    public const NONE = 'none';

    /** @var int SQL parameter counter so several scopes can share one query. */
    private static int $seq = 0;

    /** @var string */
    private string $mode;
    /** @var int viewer user id (team mode) */
    private int $viewerid;
    /** @var int client restriction, 0 = none */
    private int $clientid;
    /** @var int[]|null managed department ids, team mode only */
    private ?array $departmentids = null;

    /**
     * Use the factory methods.
     *
     * @param string $mode
     * @param int $viewerid
     * @param int $clientid
     */
    private function __construct(string $mode, int $viewerid, int $clientid) {
        $this->mode = $mode;
        $this->viewerid = $viewerid;
        $this->clientid = max(0, $clientid);
    }

    /**
     * Scope for the current user, narrowed to the admin's chosen client filter.
     *
     * @param int|null $clientid client restriction; null uses the remembered filter
     * @return self
     */
    public static function current(?int $clientid = null): self {
        global $USER;
        return self::for_user((int)$USER->id, $clientid ?? tenancy::current_filter());
    }

    /**
     * Scope for any user, decided by their capabilities.
     *
     * @param int $userid
     * @param int $clientid client restriction, 0 = none
     * @return self
     */
    public static function for_user(int $userid, int $clientid = 0): self {
        $context = \context_system::instance();
        if ($userid > 0 && has_capability('local/trainingmatrix:viewall', $context, $userid)) {
            return new self(self::ALL, $userid, $clientid);
        }
        if ($userid > 0 && has_capability('local/trainingmatrix:viewteam', $context, $userid)) {
            return new self(self::TEAM, $userid, $clientid);
        }
        return new self(self::NONE, $userid, 0);
    }

    /**
     * Team scope for a manager, regardless of capabilities (the caller has already checked access).
     *
     * @param int $managerid
     * @param int $clientid
     * @return self
     */
    public static function team(int $managerid, int $clientid = 0): self {
        return new self(self::TEAM, $managerid, $clientid);
    }

    /**
     * Everyone tied to one client (for the Client portal, which does its own access checks).
     *
     * @param int $clientid
     * @return self
     */
    public static function for_client(int $clientid): self {
        return $clientid > 0 ? new self(self::CLIENT, 0, $clientid) : new self(self::NONE, 0, 0);
    }

    /**
     * Scope mode.
     *
     * @return string
     */
    public function mode(): string {
        return $this->mode;
    }

    /**
     * Client restriction, 0 = none.
     *
     * @return int
     */
    public function clientid(): int {
        return $this->clientid;
    }

    /**
     * Can see every staff record on the site, with no client narrowing?
     *
     * @return bool
     */
    public function sees_all(): bool {
        return $this->mode === self::ALL && $this->clientid === 0;
    }

    /**
     * Is this an oversight scope (all, team or client) rather than nobody?
     *
     * @return bool
     */
    public function is_oversight(): bool {
        return $this->mode !== self::NONE;
    }

    /**
     * Does the scope currently contain at least one staff record? Team managers with no reports see nothing.
     *
     * @return bool
     */
    public function has_staff(): bool {
        global $DB;
        if ($this->mode === self::NONE) {
            return false;
        }
        [$sql, $params] = $this->staff_condition('s');
        return $DB->record_exists_sql("SELECT 1 FROM {local_trainingmatrix_staff} s WHERE $sql", $params);
    }

    /**
     * Departments the team viewer manages, including all sub-departments.
     *
     * @return int[]
     */
    public function managed_department_ids(): array {
        global $DB;
        if ($this->mode !== self::TEAM) {
            return [];
        }
        if ($this->departmentids === null) {
            $all = $DB->get_records('local_trainingmatrix_dept', null, '', 'id, parentid, managerid');
            $children = [];
            $queue = [];
            foreach ($all as $d) {
                $children[(int)$d->parentid][] = (int)$d->id;
                if ((int)$d->managerid === $this->viewerid) {
                    $queue[] = (int)$d->id;
                }
            }
            $found = [];
            while ($queue) {
                $id = array_shift($queue);
                if (isset($found[$id])) {
                    continue;
                }
                $found[$id] = $id;
                foreach ($children[$id] ?? [] as $child) {
                    $queue[] = $child;
                }
            }
            $this->departmentids = array_values($found);
        }
        return $this->departmentids;
    }

    /**
     * SQL condition on a local_trainingmatrix_staff alias.
     *
     * @param string $alias staff table alias
     * @return array [sql, named params]
     */
    public function staff_condition(string $alias = 's'): array {
        global $DB;
        $p = 'tmsc' . (++self::$seq);
        $conditions = [];
        $params = [];
        switch ($this->mode) {
            case self::ALL:
                break;
            case self::CLIENT:
                break;
            case self::TEAM:
                $team = ["{$alias}.managerid = :{$p}mgr"];
                $params["{$p}mgr"] = $this->viewerid;
                $departments = $this->managed_department_ids();
                if ($departments) {
                    [$insql, $inparams] = $DB->get_in_or_equal($departments, SQL_PARAMS_NAMED, "{$p}d");
                    $team[] = "{$alias}.departmentid $insql";
                    $params += $inparams;
                }
                $conditions[] = '(' . implode(' OR ', $team) . ')';
                break;
            default:
                return ['1 = 0', []];
        }
        if ($this->clientid > 0) {
            $conditions[] = "{$alias}.clientid = :{$p}client";
            $params["{$p}client"] = $this->clientid;
        }
        return [$conditions ? implode(' AND ', $conditions) : '1 = 1', $params];
    }

    /**
     * SQL condition on a user id column: the user has a staff record inside this scope.
     *
     * @param string $useridcolumn e.g. 'tc.userid'
     * @return array [sql, named params]
     */
    public function user_condition(string $useridcolumn): array {
        if ($this->sees_all()) {
            return ['1 = 1', []];
        }
        if ($this->mode === self::NONE) {
            return ['1 = 0', []];
        }
        $alias = 'tmus' . (self::$seq + 1);
        [$sql, $params] = $this->staff_condition($alias);
        return ["EXISTS (SELECT 1 FROM {local_trainingmatrix_staff} {$alias} WHERE {$alias}.userid = {$useridcolumn} AND {$sql})",
            $params];
    }

    /**
     * SQL condition on a definition (department, position, competency) alias: shared or the scope's client.
     *
     * @param string $alias
     * @return array [sql, named params]
     */
    public function definition_condition(string $alias): array {
        if ($this->clientid <= 0) {
            return ['1 = 1', []];
        }
        $p = 'tmdc' . (++self::$seq);
        return ["({$alias}.clientid = 0 OR {$alias}.clientid = :{$p})", [$p => $this->clientid]];
    }

    /**
     * Is a staff record inside this scope?
     *
     * @param stdClass $staff local_trainingmatrix_staff record
     * @return bool
     */
    public function contains_staff(stdClass $staff): bool {
        if ($this->mode === self::NONE) {
            return false;
        }
        if ($this->clientid > 0 && (int)$staff->clientid !== $this->clientid) {
            return false;
        }
        if ($this->mode !== self::TEAM) {
            return true;
        }
        return (int)$staff->managerid === $this->viewerid ||
            ($staff->departmentid && in_array((int)$staff->departmentid, $this->managed_department_ids(), true));
    }

    /**
     * Does the user have a staff record inside this scope?
     *
     * @param int $userid
     * @return bool
     */
    public function contains_user(int $userid): bool {
        global $DB;
        if ($this->sees_all()) {
            return true;
        }
        foreach ($DB->get_records('local_trainingmatrix_staff', ['userid' => $userid]) as $staff) {
            if ($this->contains_staff($staff)) {
                return true;
            }
        }
        return false;
    }

    /**
     * May the current user read another user's training records? Own records always; others through scope.
     *
     * @param int $userid subject
     * @return bool
     */
    public static function can_view_user(int $userid): bool {
        global $USER;
        if ($userid === (int)$USER->id) {
            return has_capability('local/trainingmatrix:view', \context_system::instance());
        }
        return self::for_user((int)$USER->id)->contains_user($userid);
    }

    /**
     * Stop unless the current user may read the user's training records.
     *
     * @param int $userid
     */
    public static function require_view_user(int $userid): void {
        global $USER;
        if ($userid === (int)$USER->id) {
            require_capability('local/trainingmatrix:view', \context_system::instance());
            return;
        }
        if (!self::for_user((int)$USER->id)->contains_user($userid)) {
            throw new \required_capability_exception(
                \context_system::instance(),
                'local/trainingmatrix:viewall',
                'nopermissions',
                ''
            );
        }
    }
}
