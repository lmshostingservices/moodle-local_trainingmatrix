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

defined('MOODLE_INTERNAL') || die();

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

function local_trainingmatrix_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    global $DB;

    $context = context_system::instance();
    if (!has_capability('local/trainingmatrix:view', $context)) {
        return;
    }

    $staff = $DB->get_record('local_tm_staff', ['userid' => $user->id, 'status' => 'active']);
    if ($staff) {
        $url = new moodle_url('/local/trainingmatrix/staff.php', ['id' => $staff->id]);
        $node = new core_user\output\myprofile\node('miscellaneous', 'trainingmatrix',
            get_string('trainingmatrix', 'local_trainingmatrix'), null, $url);
        $tree->add_node($node);
    }
}

function local_trainingmatrix_get_staff_compliance($userid) {
    global $DB;

    $staff = $DB->get_record('local_tm_staff', ['userid' => $userid, 'status' => 'active']);
    if (!$staff) {
        return null;
    }

    $position = $DB->get_record('local_tm_positions', ['id' => $staff->positionid]);
    if (!$position) {
        return null;
    }

    $requiredComps = $DB->get_records_sql("
        SELECT pc.*, c.name, c.code, c.category, c.mandatory as comp_mandatory
        FROM {local_tm_pos_comps} pc
        JOIN {local_tm_competencies} c ON c.id = pc.competencyid
        WHERE pc.positionid = ?
        ORDER BY c.category, c.name
    ", [$staff->positionid]);

    $completions = $DB->get_records('local_tm_completions', ['userid' => $userid]);
    $completionsByComp = [];
    foreach ($completions as $c) {
        $completionsByComp[$c->competencyid] = $c;
    }

    $stats = [
        'total' => count($requiredComps),
        'completed' => 0,
        'in_progress' => 0,
        'expired' => 0,
        'expiring_soon' => 0,
        'not_started' => 0,
        'compliance_rate' => 0,
    ];

    $now = time();
    $soonThreshold = $now + (30 * 24 * 60 * 60);

    foreach ($requiredComps as &$comp) {
        $comp->completion = $completionsByComp[$comp->competencyid] ?? null;

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
                } else if ($comp->completion->expirydate && $comp->completion->expirydate < $soonThreshold) {
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

    if ($stats['total'] > 0) {
        $stats['compliance_rate'] = round(($stats['completed'] / $stats['total']) * 100);
    }

    return [
        'staff' => $staff,
        'position' => $position,
        'competencies' => $requiredComps,
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
    global $DB, $CFG;
    require_once($CFG->libdir . '/enrollib.php');
    require_capability('local/trainingmatrix:assign', context_system::instance());
    $links = $DB->get_records_sql("
        SELECT cc.* FROM {local_tm_comp_courses} cc
        JOIN {local_tm_pos_comps} pc ON pc.competencyid = cc.competencyid
        WHERE pc.positionid = ?", [$positionid]);
    $courses = [];
    foreach ($links as $link) {
        $courses[$link->courseid] = $link->courseid;
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
        $key = ['userid' => $userid, 'competencyid' => $link->competencyid, 'courseid' => $link->courseid];
        if (!$DB->record_exists('local_tm_completions', $key)) {
            $DB->insert_record('local_tm_completions', (object)($key + [
                'status' => 'not_started', 'progress' => 0, 'timecreated' => time(), 'timemodified' => time(),
            ]));
        }
    }
    foreach ($courses as $courseid) {
        local_trainingmatrix_sync_course_completion($courseid, $userid);
    }
}

function local_trainingmatrix_sync_course_completion($courseid, $userid) {
    global $DB;

    // Match the scheduled sync's active-staff and position-requirement scope.
    $mappings = $DB->get_records_sql("
        SELECT DISTINCT cc.*
        FROM {local_tm_comp_courses} cc
        JOIN {local_tm_pos_comps} pc ON pc.competencyid = cc.competencyid
        JOIN {local_tm_staff} s ON s.positionid = pc.positionid
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

    foreach ($mappings as $compCourse) {
        $expirydate = $completion->timecompleted + ($compCourse->expirymonths * 30 * 24 * 60 * 60);
        $existing = $DB->get_record('local_tm_completions', [
            'userid' => $userid,
            'competencyid' => $compCourse->competencyid,
            'courseid' => $courseid,
        ]);
        $now = time();
        $record = new stdClass();
        $record->userid = $userid;
        $record->competencyid = $compCourse->competencyid;
        $record->courseid = $courseid;
        $record->completiondate = $completion->timecompleted;
        $record->expirydate = $expirydate;
        $record->status = $expirydate < $now ? 'expired' :
            ($expirydate < $now + 30 * DAYSECS ? 'expiring_soon' : 'completed');
        $record->progress = 100;
        $record->timemodified = $now;
        if ($existing) {
            $record->id = $existing->id;
            if ($existing->completiondate != $record->completiondate || $existing->expirydate != $expirydate) {
                $record->notified = 0;
            }
            $DB->update_record('local_tm_completions', $record);
        } else {
            $record->timecreated = $now;
            $record->notified = 0;
            $DB->insert_record('local_tm_completions', $record);
        }
    }

    return true;
}

function local_trainingmatrix_get_compliance_stats() {
    global $DB;

    $now = time();
    $soonThreshold = $now + (30 * 24 * 60 * 60);

    $totalStaff = $DB->count_records('local_tm_staff', ['status' => 'active']);

    $stats = [
        'total_staff' => $totalStaff,
        'fully_compliant' => 0,
        'expiring_soon' => 0,
        'expired' => 0,
        'in_progress' => 0,
        'not_started' => 0,
    ];

    $staffRecords = $DB->get_records('local_tm_staff', ['status' => 'active']);
    foreach ($staffRecords as $staff) {
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
