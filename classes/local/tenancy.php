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

/**
 * Optional link to Client portal clients.
 *
 * Matrix never requires the portal. Without it every record has clientid 0 and behaves exactly as before.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
final class tenancy {
    /** @var bool|null cached availability */
    private static ?bool $available = null;

    /** @var array|null cached id => name */
    private static ?array $clients = null;

    /**
     * Is the Client portal installed?
     *
     * @return bool
     */
    public static function available(): bool {
        global $DB;
        if (self::$available === null) {
            self::$available = \core_component::get_component_directory('local_clientportal') !== null &&
                $DB->get_manager()->table_exists('local_clientportal_client');
        }
        return self::$available;
    }

    /**
     * Clients, id => name, ordered by name. Empty without the portal.
     *
     * @return array
     */
    public static function clients(): array {
        global $DB;
        if (!self::available()) {
            return [];
        }
        if (self::$clients === null) {
            self::$clients = $DB->get_records_menu('local_clientportal_client', null, 'name', 'id, name');
        }
        return self::$clients;
    }

    /**
     * Does a client exist?
     *
     * @param int $clientid
     * @return bool
     */
    public static function exists(int $clientid): bool {
        return $clientid > 0 && array_key_exists($clientid, self::clients());
    }

    /**
     * Display name for a client id, or the shared label for 0.
     *
     * @param int $clientid
     * @return string
     */
    public static function name(int $clientid): string {
        if ($clientid <= 0) {
            return get_string('client_shared', 'local_trainingmatrix');
        }
        $clients = self::clients();
        return isset($clients[$clientid]) ? format_string($clients[$clientid]) :
            get_string('client_unknown', 'local_trainingmatrix', $clientid);
    }

    /**
     * Select options for a definition (department, position, competency): shared plus each client.
     *
     * @return array
     */
    public static function definition_options(): array {
        $options = [0 => get_string('client_shared', 'local_trainingmatrix')];
        foreach (self::clients() as $id => $name) {
            $options[$id] = format_string($name);
        }
        return $options;
    }

    /**
     * Select options for a staff record: no client plus each client.
     *
     * @return array
     */
    public static function staff_options(): array {
        $options = [0 => get_string('client_none', 'local_trainingmatrix')];
        foreach (self::clients() as $id => $name) {
            $options[$id] = format_string($name);
        }
        return $options;
    }

    /**
     * Clients whose learner cohort contains the user (portal staff of that client excluded).
     *
     * @param int $userid
     * @return int[]
     */
    public static function learner_clients(int $userid): array {
        global $DB;
        if (!self::available() || $userid <= 0) {
            return [];
        }
        $sql = "SELECT c.id
                  FROM {local_clientportal_client} c
                  JOIN {cohort_members} cm ON cm.cohortid = c.cohortid AND cm.userid = :userid
                 WHERE NOT EXISTS (SELECT 1 FROM {local_clientportal_member} m
                                    WHERE m.clientid = c.id AND m.userid = :userid2)
              ORDER BY c.name";
        return array_map('intval', $DB->get_fieldset_sql($sql, ['userid' => $userid, 'userid2' => $userid]));
    }

    /**
     * Is the user a learner of the client? Always true for clientid 0.
     *
     * @param int $clientid
     * @param int $userid
     * @return bool
     */
    public static function is_learner(int $clientid, int $userid): bool {
        if ($clientid <= 0) {
            return true;
        }
        return in_array($clientid, self::learner_clients($userid), true);
    }

    /**
     * Can a definition owned by $definitionclient be used by something owned by $ownerclient?
     *
     * Shared definitions (0) fit everyone; a client's own definition fits only that client.
     *
     * @param int $definitionclient
     * @param int $ownerclient
     * @return bool
     */
    public static function fits(int $definitionclient, int $ownerclient): bool {
        return $definitionclient === 0 || $definitionclient === $ownerclient;
    }

    /**
     * The client filter the current admin has chosen (0 = all clients). Remembered as a user preference.
     *
     * @param int|null $set new value to store, or null to read
     * @return int
     */
    public static function current_filter(?int $set = null): int {
        if (!self::available()) {
            return 0;
        }
        if ($set !== null) {
            $set = self::exists($set) ? $set : 0;
            set_user_preference('local_trainingmatrix_clientfilter', $set);
            return $set;
        }
        $value = (int)get_user_preferences('local_trainingmatrix_clientfilter', 0);
        return self::exists($value) ? $value : 0;
    }

    /**
     * Forget cached lookups (tests, or after clients change in the same request).
     */
    public static function reset_cache(): void {
        self::$available = null;
        self::$clients = null;
    }
}
