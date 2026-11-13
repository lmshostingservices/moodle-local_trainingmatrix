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

use local_trainingmatrix\local\staff_import;
use local_trainingmatrix\local\tenancy;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/cohort/lib.php');

/**
 * Staff CSV import.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\local\staff_import::class)]
final class staff_import_test extends \advanced_testcase {
    /**
     * Reset caches between tests.
     */
    protected function setUp(): void {
        parent::setUp();
        tenancy::reset_cache();
    }

    /**
     * Plan and apply a CSV string.
     *
     * @param staff_import $importer
     * @param string $csv
     * @return array [plans, apply results by line]
     */
    private function run_csv(staff_import $importer, string $csv): array {
        $iid = \csv_import_reader::get_new_iid('local_trainingmatrix_staff');
        $reader = new \csv_import_reader($iid, 'local_trainingmatrix_staff');
        $this->assertGreaterThan(0, $reader->load_csv_content($csv, 'UTF-8', 'comma'), (string)$reader->get_error());
        $file = $importer->plan_file($reader);
        $this->assertSame([], $file['errors']);
        $results = [];
        foreach ($file['plans'] as $plan) {
            $results[$plan->line] = $plan->errors ? 'skipped' : ($importer->apply($plan) ?? 'ok');
        }
        $reader->cleanup(true);
        return [$file['plans'], $results];
    }

    /**
     * Position requiring one course.
     *
     * @param string $name
     * @param int $courseid
     * @param int $clientid
     * @return int
     */
    private function position_with_course(string $name, int $courseid, int $clientid = 0): int {
        global $DB;
        $pos = $DB->insert_record('local_trainingmatrix_pos', (object)['name' => $name, 'level' => 1, 'clientid' => $clientid,
            'timecreated' => time(), 'timemodified' => time()]);
        $comp = $DB->insert_record('local_trainingmatrix_comp', (object)['name' => $name . ' induction', 'mandatory' => 1,
            'clientid' => $clientid, 'timecreated' => time(), 'timemodified' => time()]);
        $DB->insert_record('local_trainingmatrix_compcrs', (object)['competencyid' => $comp, 'courseid' => $courseid,
            'expirymonths' => 0, 'notifydays' => 30, 'timecreated' => time()]);
        $DB->insert_record('local_trainingmatrix_poscomp', (object)['positionid' => $pos, 'competencyid' => $comp,
            'mandatory' => 1, 'timecreated' => time()]);
        return (int)$pos;
    }

    public function test_import_creates_updates_and_enrols(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $this->position_with_course('Warehouse Operator', (int)$course->id);
        $jo = $gen->create_user(['username' => 'jo', 'email' => 'jo@example.com']);
        $al = $gen->create_user(['username' => 'al', 'email' => 'al@example.com']);
        $boss = $gen->create_user(['username' => 'boss']);
        $cy = $gen->create_user(['username' => 'cy']);
        $DB->insert_record('local_trainingmatrix_dept', (object)['name' => 'Warehouse', 'clientid' => 0, 'sortorder' => 0,
            'timecreated' => time(), 'timemodified' => time()]);

        $csv = "Username,Email,Employee ID,Position,Department,Manager,Start date,Status\n" .
            "jo,,E1,warehouse operator,Warehouse,boss,2026-01-15,active\n" .
            ",AL@example.com,E2,Warehouse Operator,,,15/02/2026,\n" .
            "nobody,,E3,Warehouse Operator,,,,\n" .
            "cy,,E4,Forklift Driver,,,,\n";
        [$plans, $results] = $this->run_csv(new staff_import(0, false), $csv);

        $this->assertSame(['ok', 'ok', 'skipped', 'skipped'], array_values($results));
        $staff = $DB->get_record('local_trainingmatrix_staff', ['userid' => $jo->id], '*', MUST_EXIST);
        $this->assertSame('E1', $staff->employeeid);
        $this->assertEquals($boss->id, $staff->managerid);
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $jo->id, '', true));
        $this->assertTrue(is_enrolled(\context_course::instance($course->id), $al->id, '', true));
        $this->assertNotEmpty($plans[2]->errors);
        $this->assertNotEmpty($plans[3]->errors, 'Missing positions are errors unless creation is allowed.');

        // Same file again with creation allowed: earlier rows update, the new position is created.
        [$plans, $results] = $this->run_csv(new staff_import(0, true), $csv);
        $this->assertSame('update', $plans[0]->action);
        $this->assertSame('ok', $results[5]);
        $this->assertTrue($DB->record_exists('local_trainingmatrix_pos', ['name' => 'Forklift Driver']));
        $this->assertEquals(1, $DB->count_records('local_trainingmatrix_staff', ['userid' => $jo->id]));
        $this->assertTrue($DB->record_exists('local_trainingmatrix_staff', ['userid' => $cy->id]));
    }

    public function test_import_respects_clients(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $gen = $this->getDataGenerator();
        $course = $gen->create_course();
        $member = $gen->create_user(['username' => 'member']);
        $stranger = $gen->create_user(['username' => 'stranger']);
        if (!class_exists(\local_clientportal\manager::class)) {
            $this->markTestSkipped('Client Portal is not installed.');
        }
        $acme = \local_clientportal\manager::save_client((object)['name' => 'Acme', 'active' => 1]);
        cohort_add_member(\local_clientportal\manager::get_client($acme)->cohortid, $member->id);
        tenancy::reset_cache();
        $this->position_with_course('Picker', (int)$course->id, $acme);

        $csv = "username,position,client\nmember,Picker,acme\nstranger,Picker,Acme\n";
        [$plans, $results] = $this->run_csv(new staff_import(), $csv);
        $this->assertSame('ok', $results[2]);
        $this->assertSame('skipped', $results[3], 'Only the client\'s learners can be its staff.');
        $this->assertEquals($acme, $DB->get_field('local_trainingmatrix_staff', 'clientid', ['userid' => $member->id]));

        // A client's own position is invisible to other rows.
        [$plans] = $this->run_csv(new staff_import(), "username,position\nstranger,Picker\n");
        $this->assertNotEmpty($plans[0]->errors);
    }

    public function test_dates(): void {
        $this->resetAfterTest();
        $this->assertSame(staff_import::parse_date('2026-02-15'), staff_import::parse_date('15/02/2026'));
        $this->assertNull(staff_import::parse_date('31/02/2026'));
        $this->assertNull(staff_import::parse_date('next week'));
    }
}
