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

namespace local_trainingmatrix;

use local_trainingmatrix\local\scope;
use local_trainingmatrix\local\tenancy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Client and team scoping.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\local\scope::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\local\tenancy::class)]
final class scope_test extends \advanced_testcase {
    /**
     * Reset caches between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        tenancy::reset_cache();
    }

    /**
     * Create a department.
     *
     * @param array $fields
     * @return int
     */
    private function department(array $fields = []): int {
        global $DB;
        return (int)$DB->insert_record('local_tm_departments', (object)($fields + [
            'name' => 'Dept ' . random_string(4), 'clientid' => 0, 'sortorder' => 0,
            'timecreated' => time(), 'timemodified' => time()]));
    }

    /**
     * Create a position.
     *
     * @param array $fields
     * @return int
     */
    private function position(array $fields = []): int {
        global $DB;
        return (int)$DB->insert_record('local_tm_positions', (object)($fields + [
            'name' => 'Pos ' . random_string(4), 'clientid' => 0, 'level' => 1,
            'timecreated' => time(), 'timemodified' => time()]));
    }

    /**
     * Create a staff record.
     *
     * @param int $userid
     * @param int $positionid
     * @param array $fields
     * @return \stdClass
     */
    private function staff(int $userid, int $positionid, array $fields = []): \stdClass {
        global $DB;
        $record = (object)($fields + ['userid' => $userid, 'positionid' => $positionid, 'status' => 'active',
            'clientid' => 0, 'timecreated' => time(), 'timemodified' => time()]);
        $record->id = $DB->insert_record('local_tm_staff', $record);
        return $DB->get_record('local_tm_staff', ['id' => $record->id]);
    }

    /**
     * Create a Client portal client with learners.
     *
     * @param string $name
     * @param array $learners user ids
     * @return int client id
     */
    private function client(string $name, array $learners): int {
        $id = \local_clientportal\manager::save_client((object)['name' => $name, 'active' => 1]);
        $client = \local_clientportal\manager::get_client($id);
        foreach ($learners as $userid) {
            cohort_add_member($client->cohortid, $userid);
        }
        tenancy::reset_cache();
        return $id;
    }

    public function test_team_scope_covers_direct_reports_and_managed_department_tree(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $manager = $gen->create_user();
        [$direct, $deptmember, $subdeptmember, $outsider] = [$gen->create_user(), $gen->create_user(),
            $gen->create_user(), $gen->create_user()];
        $pos = $this->position();
        $dept = $this->department(['managerid' => $manager->id]);
        $sub = $this->department(['parentid' => $dept]);
        $other = $this->department();
        $this->staff($direct->id, $pos, ['managerid' => $manager->id, 'departmentid' => $other]);
        $this->staff($deptmember->id, $pos, ['departmentid' => $dept]);
        $this->staff($subdeptmember->id, $pos, ['departmentid' => $sub]);
        $outsiderstaff = $this->staff($outsider->id, $pos, ['departmentid' => $other]);

        $this->setUser($manager);
        $scope = scope::current(0);
        $this->assertSame(scope::TEAM, $scope->mode(), 'Every user holds viewteam by default.');
        $this->assertTrue($scope->has_staff());
        $this->assertEqualsCanonicalizing([$dept, $sub], $scope->managed_department_ids());
        foreach ([$direct, $deptmember, $subdeptmember] as $user) {
            $this->assertTrue($scope->contains_user((int)$user->id));
            $this->assertTrue(scope::can_view_user((int)$user->id));
        }
        $this->assertFalse($scope->contains_staff($outsiderstaff));
        $this->assertFalse(scope::can_view_user((int)$outsider->id));

        // The SQL and the PHP checks agree.
        global $DB;
        [$sql, $params] = $scope->staff_condition('s');
        $ids = $DB->get_fieldset_sql("SELECT s.userid FROM {local_tm_staff} s WHERE $sql", $params);
        $this->assertEqualsCanonicalizing([$direct->id, $deptmember->id, $subdeptmember->id], $ids);

        // Statistics and the learner view follow the same scope.
        $this->assertSame(3, local_trainingmatrix_get_compliance_stats($scope)['total_staff']);
        learner_training::for_user((int)$deptmember->id);
        $this->expectException(\required_capability_exception::class);
        learner_training::for_user((int)$outsider->id);
    }

    public function test_user_without_reports_oversees_nobody(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $learner = $gen->create_user();
        $other = $gen->create_user();
        $this->staff($other->id, $this->position());
        $this->setUser($learner);
        $scope = scope::current(0);
        $this->assertFalse($scope->has_staff());
        $this->assertFalse(\local_trainingmatrix\output\training_cards::can_oversee($scope));
        $this->assertSame(0, local_trainingmatrix_get_compliance_stats($scope)['total_staff']);
    }

    public function test_client_filter_narrows_admin_lists_but_not_access(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        [$a, $b, $internal] = [$gen->create_user(), $gen->create_user(), $gen->create_user()];
        $acme = $this->client('Acme', [$a->id]);
        $bhp = $this->client('BHP', [$b->id]);
        $shared = $this->position();
        $acmepos = $this->position(['clientid' => $acme]);
        $bhppos = $this->position(['clientid' => $bhp]);
        $this->staff($a->id, $acmepos, ['clientid' => $acme]);
        $this->staff($b->id, $shared, ['clientid' => $bhp]);
        $this->staff($internal->id, $shared);

        $this->setAdminUser();
        $this->assertSame($acme, tenancy::current_filter($acme));
        $scope = scope::current();
        $this->assertSame($acme, $scope->clientid());
        [$sql, $params] = $scope->staff_condition('s');
        $this->assertEquals([$a->id], $DB->get_fieldset_sql("SELECT s.userid FROM {local_tm_staff} s WHERE $sql", $params));
        [$sql, $params] = $scope->definition_condition('p');
        $this->assertEqualsCanonicalizing([$shared, $acmepos],
            $DB->get_fieldset_sql("SELECT p.id FROM {local_tm_positions} p WHERE $sql", $params));
        $this->assertNotContains($bhppos, $DB->get_fieldset_sql("SELECT p.id FROM {local_tm_positions} p WHERE $sql",
            $params));
        // The filter is a view preference: an admin can still open anyone.
        $this->assertTrue(scope::can_view_user((int)$b->id));

        // The portal's own scope.
        $client = scope::for_client($bhp);
        $this->assertTrue($client->contains_user((int)$b->id));
        $this->assertFalse($client->contains_user((int)$a->id));
        $this->assertFalse($client->contains_user((int)$internal->id));
    }

    public function test_definition_client_rules(): void {
        $this->resetAfterTest();
        $gen = $this->getDataGenerator();
        $u = $gen->create_user();
        $acme = $this->client('Acme', [$u->id]);
        $bhp = $this->client('BHP', []);
        $this->assertTrue(tenancy::fits(0, $acme));
        $this->assertTrue(tenancy::fits($acme, $acme));
        $this->assertFalse(tenancy::fits($acme, 0));
        $this->assertFalse(tenancy::fits($acme, $bhp));

        $acmedept = $this->department(['clientid' => $acme]);
        // A shared position cannot sit in a client's department.
        $this->assertNotNull(local_trainingmatrix_definition_conflict('position', 0, 0, ['departmentid' => $acmedept]));
        $this->assertNull(local_trainingmatrix_definition_conflict('position', 0, $acme, ['departmentid' => $acmedept]));
        // A department cannot move to another client while staff of the first client work in it.
        $pos = $this->position(['clientid' => $acme]);
        $this->staff($u->id, $pos, ['clientid' => $acme, 'departmentid' => $acmedept]);
        $this->assertNotNull(local_trainingmatrix_definition_conflict('department', $acmedept, $bhp));
        // Making it shared is always allowed.
        $this->assertNull(local_trainingmatrix_definition_conflict('department', $acmedept, 0));
        $this->assertTrue(tenancy::is_learner($acme, (int)$u->id));
        $this->assertFalse(tenancy::is_learner($bhp, (int)$u->id));
    }

    public function test_position_change_enrols_existing_holders_and_reports_blocked_ones(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        [$ok, $blocked] = [$gen->create_user(), $gen->create_user()];
        $course = $gen->create_course();
        $pos = $this->position();
        $this->staff($ok->id, $pos);
        $this->staff($blocked->id, $pos);
        // A suspended enrolment is never silently lifted.
        $gen->enrol_user($blocked->id, $course->id, 'student', 'manual', 0, 0, ENROL_USER_SUSPENDED);

        $comp = $DB->insert_record('local_tm_competencies', (object)['name' => 'WHS', 'mandatory' => 1, 'clientid' => 0,
            'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_tm_comp_courses', (object)['competencyid' => $comp, 'courseid' => $course->id,
            'expirymonths' => 12, 'notifydays' => 30, 'timecreated' => time()]);
        $DB->insert_record('local_tm_pos_comps', (object)['positionid' => $pos, 'competencyid' => $comp,
            'mandatory' => 1, 'timecreated' => time()]);

        $result = local_trainingmatrix_provision_positions([$pos]);
        $this->assertSame(1, $result['enrolled']);
        $this->assertCount(1, $result['failed']);
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $ok->id, '', true));
        $this->assertTrue($DB->record_exists('local_tm_completions', ['userid' => $ok->id, 'competencyid' => $comp]));
        $this->assertFalse($DB->record_exists('local_tm_completions', ['userid' => $blocked->id]));
    }
}
