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

/**
 * Add Training Matrix links to the main navigation.
 *
 * @param global_navigation $nav The navigation tree.
 */
function local_trainingmatrix_extend_navigation(global_navigation $nav) {
    global $PAGE, $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $context = context_system::instance();
    if (!has_capability('local/trainingmatrix:view', $context)) {
        return;
    }
    $nav->add(
        get_string('learner_mytraining', 'local_trainingmatrix'),
        new moodle_url('/local/trainingmatrix/my.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_trainingmatrix_mytraining'
    );
}

/**
 * Add Training Matrix links to the settings navigation.
 *
 * @param settings_navigation $nav The settings navigation.
 * @param context $context The current context.
 */
function local_trainingmatrix_extend_settings_navigation(settings_navigation $nav, context $context) {
    global $PAGE;

    if (!has_capability('local/trainingmatrix:manage', $context)) {
        return;
    }

    if ($settingnode = $nav->find('siteadministration', navigation_node::TYPE_SITE_ADMIN)) {
        $node = $settingnode->add(
            get_string('trainingmatrix', 'local_trainingmatrix'),
            new moodle_url('/local/trainingmatrix/index.php'),
            navigation_node::TYPE_SETTING,
            null,
            'trainingmatrix',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Add the learner's training link to their profile page.
 *
 * @param core_user\output\myprofile\tree $tree The profile tree.
 * @param stdClass $user The profile owner.
 * @param bool $iscurrentuser True if viewing their own profile.
 * @param stdClass|null $course The course, if any.
 */
function local_trainingmatrix_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;

    $context = context_system::instance();
    if (!has_capability('local/trainingmatrix:view', $context)) {
        return;
    }

    $staff = $DB->get_record('local_trainingmatrix_staff', ['userid' => $user->id, 'status' => 'active'], '*', IGNORE_MULTIPLE);
    if ($staff && ($iscurrentuser || \local_trainingmatrix\local\scope::can_view_user((int)$user->id))) {
        $url = new moodle_url('/local/trainingmatrix/staff.php', ['id' => $staff->id]);
        $node = new core_user\output\myprofile\node(
            'miscellaneous',
            'trainingmatrix',
            get_string('trainingmatrix', 'local_trainingmatrix'),
            null,
            $url
        );
        $tree->add_node($node);
    }
}

/**
 * Get a staff member's position, department and compliance figures.
 *
 * @param int $userid The user id.
 * @return array|null Compliance data, or null if they are not active staff.
 */
function local_trainingmatrix_get_staff_compliance($userid) {
    global $DB;

    $staff = $DB->get_record('local_trainingmatrix_staff', ['userid' => $userid, 'status' => 'active'], '*', IGNORE_MULTIPLE);
    if (!$staff) {
        return null;
    }

    $position = $DB->get_record('local_trainingmatrix_pos', ['id' => $staff->positionid]);
    if (!$position) {
        return null;
    }

    $requiredcomps = $DB->get_records_sql("
        SELECT pc.*, c.name, c.code, c.category, c.mandatory as comp_mandatory
        FROM {local_trainingmatrix_poscomp} pc
        JOIN {local_trainingmatrix_comp} c ON c.id = pc.competencyid
        WHERE pc.positionid = ?
        ORDER BY c.category, c.name
    ", [$staff->positionid]);

    // When a competency has more than one tracking row (one per linked course), the best row wins rather
    // than whichever row the database happens to return last.
    $completions = $DB->get_records('local_trainingmatrix_compl', ['userid' => $userid]);
    $rank = ['completed' => 4, 'expiring_soon' => 3, 'expired' => 2, 'in_progress' => 1, 'not_started' => 0];
    $completionsbycomp = [];
    foreach ($completions as $c) {
        $prev = $completionsbycomp[$c->competencyid] ?? null;
        $crank = $rank[$c->status] ?? 0;
        $prank = $prev ? ($rank[$prev->status] ?? 0) : -1;
        if (!$prev || $crank > $prank || ($crank === $prank && (int)$c->expirydate > (int)$prev->expirydate)) {
            $completionsbycomp[$c->competencyid] = $c;
        }
    }

    $stats = [
        'total' => count($requiredcomps),
        'completed' => 0,
        'in_progress' => 0,
        'expired' => 0,
        'expiring_soon' => 0,
        'not_started' => 0,
        'compliance_rate' => 0,
    ];

    $now = time();
    $soonthreshold = $now + (30 * 24 * 60 * 60);

    foreach ($requiredcomps as &$comp) {
        $comp->completion = $completionsbycomp[$comp->competencyid] ?? null;

        if (!$comp->completion) {
            $comp->status = 'not_started';
            $comp->progress = 0;
            $stats['not_started']++;
        } else {
            $comp->progress = $comp->completion->progress;

            if (in_array($comp->completion->status, ['completed', 'expired', 'expiring_soon'], true)) {
                if ($comp->completion->expirydate && $comp->completion->expirydate < $now) {
                    $comp->status = 'expired';
                    $stats['expired']++;
                } else if ($comp->completion->expirydate && $comp->completion->expirydate < $soonthreshold) {
                    $comp->status = 'expiring_soon';
                    $stats['expiring_soon']++;
                    $stats['completed']++;
                } else {
                    $comp->status = 'completed';
                    $stats['completed']++;
                }
            } else if ($comp->completion->progress > 0) {
                $comp->status = 'in_progress';
                $stats['in_progress']++;
            } else {
                $comp->status = 'not_started';
                $stats['not_started']++;
            }
        }
    }
    unset($comp);

    if ($stats['total'] > 0) {
        $stats['compliance_rate'] = round(($stats['completed'] / $stats['total']) * 100);
    }

    return [
        'staff' => $staff,
        'position' => $position,
        'competencies' => $requiredcomps,
        'stats' => $stats,
    ];
}

/**
 * Provision required course access without reactivating suspended enrolments.
 *
 * Call inside the staff-assignment transaction so failure does not save a partial assignment.
 *
 * @param int $userid Learner.
 * @param int $positionid Assigned position.
 */
function local_trainingmatrix_assign_required_courses(int $userid, int $positionid): void {
    require_capability('local/trainingmatrix:assign', context_system::instance());
    local_trainingmatrix_enrol_required_courses($userid, $positionid);
}

/**
 * Enrol a learner in every course their position requires and create the tracking rows.
 *
 * Callers check permission first. Throws assignment_access_blocked when a course cannot take a manual enrolment or the
 * learner has a suspended enrolment there (suspensions are never silently lifted).
 *
 * @param int $userid Learner.
 * @param int $positionid Assigned position.
 */
function local_trainingmatrix_enrol_required_courses(int $userid, int $positionid): void {
    global $DB, $CFG;
    require_once($CFG->libdir . '/enrollib.php');
    $links = $DB->get_records_sql("
        SELECT cc.* FROM {local_trainingmatrix_compcrs} cc
        JOIN {local_trainingmatrix_poscomp} pc ON pc.competencyid = cc.competencyid
        WHERE pc.positionid = ?", [$positionid]);
    $courses = [];
    foreach ($links as $link) {
        if ($DB->record_exists('course', ['id' => $link->courseid])) {
            $courses[$link->courseid] = (int)$link->courseid;
        }
    }
    foreach ($courses as $courseid) {
        $context = context_course::instance($courseid);
        if (is_enrolled($context, $userid, '', true)) {
            continue;
        }
        if (is_enrolled($context, $userid, '', false)) {
            throw new moodle_exception('assignment_access_blocked', 'local_trainingmatrix', '', $courseid);
        }
        require_capability('enrol/manual:enrol', $context);
        $manual = null;
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if (in_array($instance->enrol, ['prerequisite', 'prereq2'], true)) {
                throw new moodle_exception('assignment_access_blocked', 'local_trainingmatrix', '', $courseid);
            }
            if ($instance->enrol === 'manual') {
                $manual = $instance;
            }
        }
        $plugin = enrol_get_plugin('manual');
        if (!$manual || !$plugin || !$plugin->allow_enrol($manual) || empty($manual->roleid)) {
            throw new moodle_exception('assignment_access_blocked', 'local_trainingmatrix', '', $courseid);
        }
        $plugin->enrol_user($manual, $userid, $manual->roleid, time());
    }
    foreach ($links as $link) {
        if (!isset($courses[$link->courseid])) {
            continue;
        }
        $key = ['userid' => $userid, 'competencyid' => $link->competencyid, 'courseid' => $link->courseid];
        if (!$DB->record_exists('local_trainingmatrix_compl', $key)) {
            $DB->insert_record('local_trainingmatrix_compl', (object)($key + [
                'status' => 'not_started', 'progress' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]));
        }
    }
    foreach ($courses as $courseid) {
        local_trainingmatrix_sync_course_completion($courseid, $userid);
    }
}

/**
 * After a position's requirements change, enrol every active holder in any newly required course.
 *
 * One blocked learner never stops the others: each is tried separately and failures are reported back.
 *
 * @param int[] $positionids
 * @return array ['enrolled' => int learners processed, 'failed' => string[] "Name: reason"]
 */
function local_trainingmatrix_provision_positions(array $positionids): array {
    global $DB;
    $result = ['enrolled' => 0, 'failed' => []];
    $positionids = array_values(array_unique(array_filter(array_map('intval', $positionids))));
    if (!$positionids) {
        return $result;
    }
    [$insql, $params] = $DB->get_in_or_equal($positionids, SQL_PARAMS_NAMED);
    $holders = $DB->get_records_sql("SELECT s.id, s.userid, s.positionid
                                       FROM {local_trainingmatrix_staff} s
                                       JOIN {user} u ON u.id = s.userid AND u.deleted = 0 AND u.suspended = 0
                                      WHERE s.status = 'active' AND s.positionid $insql", $params);
    foreach ($holders as $holder) {
        $transaction = null;
        try {
            $transaction = $DB->start_delegated_transaction();
            local_trainingmatrix_enrol_required_courses((int)$holder->userid, (int)$holder->positionid);
            $transaction->allow_commit();
            $result['enrolled']++;
        } catch (moodle_exception $e) {
            // Must not run inside an outer transaction: each learner is its own unit of work.
            if ($transaction && !$transaction->is_disposed()) {
                try {
                    $transaction->rollback($e);
                } catch (moodle_exception $rethrown) {
                    // Rollback rethrows the original exception; it is reported below.
                    unset($rethrown);
                }
            }
            $user = core_user::get_user($holder->userid);
            $result['failed'][] = ($user ? fullname($user) : $holder->userid) . ': ' . $e->getMessage();
        }
    }
    return $result;
}

/**
 * Turn a provisioning result into a notification for the page that triggered it.
 *
 * @param array $result from local_trainingmatrix_provision_positions()
 */
function local_trainingmatrix_notify_provision(array $result): void {
    if ($result['failed']) {
        \core\notification::warning(get_string('provision_failed', 'local_trainingmatrix', (object)[
            'count' => count($result['failed']),
            'list' => s(implode('; ', array_slice($result['failed'], 0, 10))),
        ]));
    }
}

/**
 * Expiry timestamp for a completion, using calendar months. Zero months means the competency never expires.
 *
 * @param int $completed Completion time.
 * @param int $months Validity in months.
 * @return int|null Expiry time or null when it does not expire.
 */
function local_trainingmatrix_expiry_date(int $completed, int $months): ?int {
    if ($months <= 0 || $completed <= 0) {
        return null;
    }
    $date = (new DateTimeImmutable('@' . $completed))->setTimezone(core_date::get_server_timezone_object());
    return $date->modify('+' . $months . ' months')->getTimestamp();
}

/**
 * Tracking status for a completed competency.
 *
 * @param int|null $expirydate
 * @param int $now
 * @return string
 */
function local_trainingmatrix_status_for_expiry(?int $expirydate, int $now): string {
    if ($expirydate === null) {
        return 'completed';
    }
    if ($expirydate < $now) {
        return 'expired';
    }
    return $expirydate < $now + 30 * DAYSECS ? 'expiring_soon' : 'completed';
}

/**
 * Record the competencies a user completes when they complete a course.
 *
 * @param int $courseid The completed course.
 * @param int $userid The user who completed it.
 * @return bool True when completions were checked, false when nothing applies.
 */
function local_trainingmatrix_sync_course_completion($courseid, $userid) {
    global $DB;

    // Match the scheduled sync's active-staff and position-requirement scope.
    $mappings = $DB->get_records_sql("
        SELECT DISTINCT cc.*
        FROM {local_trainingmatrix_compcrs} cc
        JOIN {local_trainingmatrix_poscomp} pc ON pc.competencyid = cc.competencyid
        JOIN {local_trainingmatrix_staff} s ON s.positionid = pc.positionid
        WHERE cc.courseid = ? AND s.userid = ? AND s.status = 'active'
    ", [$courseid, $userid]);
    if (!$mappings) {
        return false;
    }

    $completion = $DB->get_record('course_completions', [
        'course' => $courseid,
        'userid' => $userid,
    ]);

    if (!$completion || !$completion->timecompleted) {
        return false;
    }

    foreach ($mappings as $compcourse) {
        $expirydate = local_trainingmatrix_expiry_date((int)$completion->timecompleted, (int)$compcourse->expirymonths);
        $existing = $DB->get_record('local_trainingmatrix_compl', [
            'userid' => $userid,
            'competencyid' => $compcourse->competencyid,
            'courseid' => $courseid,
        ]);
        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->competencyid = $compcourse->competencyid;
        $record->courseid = $courseid;
        $record->completiondate = $completion->timecompleted;
        $record->expirydate = $expirydate;
        $record->status = local_trainingmatrix_status_for_expiry($expirydate, $now);
        $record->progress = 100;
        $record->timemodified = $now;
        if ($existing) {
            $record->id = $existing->id;
            if ($existing->completiondate != $record->completiondate || $existing->expirydate != $expirydate) {
                $record->notified = 0;
            }
            $DB->update_record('local_trainingmatrix_compl', $record);
        } else {
            $record->timecreated = $now;
            $record->notified = 0;
            $DB->insert_record('local_trainingmatrix_compl', $record);
        }
    }

    return true;
}

/**
 * Compliance statistics for the staff inside a scope.
 *
 * @param \local_trainingmatrix\local\scope|null $scope null = every active staff record (legacy behaviour)
 * @param int $departmentid optional department filter
 * @return array
 */
function local_trainingmatrix_get_compliance_stats(
    ?\local_trainingmatrix\local\scope $scope = null,
    int $departmentid = 0
) {
    global $DB;

    $where = "s.status = 'active'";
    $params = [];
    if ($scope) {
        [$scopesql, $params] = $scope->staff_condition('s');
        $where .= " AND $scopesql";
    }
    if ($departmentid > 0) {
        $where .= ' AND s.departmentid = :tmdept';
        $params['tmdept'] = $departmentid;
    }
    $staffrecords = $DB->get_records_sql("SELECT s.* FROM {local_trainingmatrix_staff} s WHERE $where", $params);

    $stats = [
        'total_staff' => count($staffrecords),
        'fully_compliant' => 0,
        'expiring_soon' => 0,
        'expired' => 0,
        'in_progress' => 0,
        'not_started' => 0,
    ];

    foreach ($staffrecords as $staff) {
        $compliance = local_trainingmatrix_get_staff_compliance($staff->userid);
        if ($compliance && $compliance['stats']['compliance_rate'] == 100 && $compliance['stats']['expired'] == 0) {
            $stats['fully_compliant']++;
        }
        if ($compliance) {
            foreach (['expired', 'expiring_soon', 'in_progress', 'not_started'] as $status) {
                $stats[$status] += $compliance['stats'][$status];
            }
        }
    }

    return $stats;
}

/**
 * Options for user pickers: active, confirmed, non-guest users plus any currently selected user.
 *
 * @param array $selected User ids that must be present (e.g. the record being edited).
 * @param int $limit Maximum number of users to list.
 * @return array id => label
 */
function local_trainingmatrix_user_options(array $selected = [], int $limit = 5000): array {
    global $DB, $CFG;
    $fields = \core_user\fields::for_name()->get_sql('u', false, '', '', false)->selects;
    $sql = "SELECT u.id, u.email, {$fields}
              FROM {user} u
             WHERE u.deleted = 0 AND u.suspended = 0 AND u.confirmed = 1 AND u.id <> :guestid
          ORDER BY u.lastname, u.firstname, u.id";
    $users = $DB->get_records_sql($sql, ['guestid' => $CFG->siteguest], 0, $limit);
    $selected = array_filter(array_map('intval', $selected));
    $missing = array_diff($selected, array_keys($users));
    if ($missing) {
        [$insql, $inparams] = $DB->get_in_or_equal($missing, SQL_PARAMS_NAMED);
        $users += $DB->get_records_sql("SELECT u.id, u.email, {$fields} FROM {user} u WHERE u.id {$insql}", $inparams);
    }
    $options = [];
    foreach ($users as $u) {
        $options[$u->id] = fullname($u) . ' (' . $u->email . ')';
    }
    return $options;
}

/**
 * Client field for a definition form (department, position, competency). Empty when no Client portal clients exist.
 *
 * @param int $selected current client id, 0 = shared
 * @return string HTML
 */
function local_trainingmatrix_client_form_group(int $selected): string {
    if (!\local_trainingmatrix\local\tenancy::clients()) {
        return '';
    }
    return html_writer::div(
        html_writer::tag('label', get_string('client', 'local_trainingmatrix'), ['class' => 'tm-form-label']) .
        html_writer::select(
            \local_trainingmatrix\local\tenancy::definition_options(),
            'clientid',
            $selected,
            false,
            ['class' => 'tm-form-select']
        ) .
        html_writer::tag('div', get_string('client_definition_help', 'local_trainingmatrix'), ['class' => 'tm-muted small']),
        'tm-form-group'
    );
}

/**
 * A definition's display name, with its client in brackets when it belongs to one.
 *
 * @param stdClass $record with name and clientid
 * @return string already formatted
 */
function local_trainingmatrix_definition_label(stdClass $record): string {
    $name = format_string($record->name);
    if (!empty($record->clientid) && \local_trainingmatrix\local\tenancy::clients()) {
        $name .= ' [' . \local_trainingmatrix\local\tenancy::name((int)$record->clientid) . ']';
    }
    return $name;
}

/**
 * Check a definition's client against everything it points at and everything that points at it.
 *
 * Rule: a record may only use definitions that are shared or belong to its own client. So a client's department can sit
 * under a shared parent, but a shared position cannot sit in a client's department, and a client's competency can only
 * be required by that client's positions.
 *
 * @param string $type department, position or competency
 * @param int $id record id, 0 when new
 * @param int $clientid proposed client, 0 = shared
 * @param array $refs ids the record will point at: parentid, departmentid, competencyids
 * @return string|null error message, or null when consistent
 */
function local_trainingmatrix_definition_conflict(string $type, int $id, int $clientid, array $refs = []): ?string {
    global $DB;
    $fits = static fn(int $owner, int $user) => \local_trainingmatrix\local\tenancy::fits($owner, $user);
    $bad = static fn(string $what) => get_string('client_conflict', 'local_trainingmatrix', $what);
    if ($clientid > 0 && !\local_trainingmatrix\local\tenancy::exists($clientid)) {
        return get_string('client_invalid', 'local_trainingmatrix');
    }

    // What this record points at.
    if (!empty($refs['parentid'])) {
        $parent = (int)$DB->get_field('local_trainingmatrix_dept', 'clientid', ['id' => $refs['parentid']]);
        if (!$fits($parent, $clientid)) {
            return $bad(get_string('parent_department', 'local_trainingmatrix'));
        }
    }
    if (!empty($refs['departmentid'])) {
        $dept = (int)$DB->get_field('local_trainingmatrix_dept', 'clientid', ['id' => $refs['departmentid']]);
        if (!$fits($dept, $clientid)) {
            return $bad(get_string('departments', 'local_trainingmatrix'));
        }
    }
    if (!empty($refs['competencyids'])) {
        [$insql, $params] = $DB->get_in_or_equal($refs['competencyids'], SQL_PARAMS_NAMED);
        foreach ($DB->get_fieldset_select('local_trainingmatrix_comp', 'clientid', "id $insql", $params) as $comp) {
            if (!$fits((int)$comp, $clientid)) {
                return $bad(get_string('competencies', 'local_trainingmatrix'));
            }
        }
    }
    if (!$id || $clientid === 0) {
        // A new record has no dependants, and a shared record fits every dependant.
        return null;
    }

    // What points at this record: each dependant must be the same client.
    $dependants = [
        'department' => [
            ['local_trainingmatrix_dept', 'parentid', 'departments'],
            ['local_trainingmatrix_pos', 'departmentid', 'positions'],
            ['local_trainingmatrix_staff', 'departmentid', 'staff'],
        ],
        'position' => [
            ['local_trainingmatrix_staff', 'positionid', 'staff'],
        ],
        'competency' => [],
    ];
    foreach ($dependants[$type] as [$table, $field, $label]) {
        if (
            $DB->record_exists_select(
                $table,
                "$field = :id AND clientid <> :client",
                ['id' => $id, 'client' => $clientid]
            )
        ) {
            return $bad(get_string($label, 'local_trainingmatrix'));
        }
    }
    if ($type === 'competency') {
        $sql = "SELECT 1 FROM {local_trainingmatrix_poscomp} pc JOIN {local_trainingmatrix_pos} p ON p.id = pc.positionid
                 WHERE pc.competencyid = :id AND p.clientid <> :client";
        if ($DB->record_exists_sql($sql, ['id' => $id, 'client' => $clientid])) {
            return $bad(get_string('positions', 'local_trainingmatrix'));
        }
    }
    return null;
}
