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

/**
 * Regression tests for defects fixed in 1.0.21.
 *
 * @package    local_trainingmatrix
 * @copyright  2026 LMS-Labs
 * @license    http://www.gnu.org/licenses/gpl-3.0.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\local\ai_client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\external\get_compliance_stats::class)]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_trainingmatrix_expiry_date')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_trainingmatrix_get_staff_compliance')]
#[\PHPUnit\Framework\Attributes\CoversFunction('local_trainingmatrix_user_options')]
final class regression_test extends \advanced_testcase {
    /** Load the library. */
    protected function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
    }

    /** Expiry uses calendar months, and zero months never expires. */
    public function test_expiry_months(): void {
        $this->resetAfterTest();
        $this->setTimezone('UTC');
        $start = gmmktime(0, 0, 0, 1, 31, 2026);
        $this->assertSame(gmmktime(0, 0, 0, 1, 31, 2027), local_trainingmatrix_expiry_date($start, 12));
        $this->assertSame(gmmktime(0, 0, 0, 7, 31, 2026), local_trainingmatrix_expiry_date($start, 6));
        $this->assertNull(local_trainingmatrix_expiry_date($start, 0));
        $this->assertSame('completed', local_trainingmatrix_status_for_expiry(null, time()));
    }

    /** With two linked courses the best tracking row decides the competency status. */
    public function test_best_completion_row_wins(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $c1 = $this->getDataGenerator()->create_course();
        $c2 = $this->getDataGenerator()->create_course();
        $pos = $DB->insert_record('local_tm_positions', (object)['name' => 'P']);
        $DB->insert_record('local_tm_staff', (object)['userid' => $user->id, 'positionid' => $pos, 'status' => 'active']);
        $comp = $DB->insert_record('local_tm_competencies', (object)['name' => 'C']);
        $DB->insert_record('local_tm_pos_comps', (object)['positionid' => $pos, 'competencyid' => $comp]);
        // Insert the completed row first and the not-started row last; "last row wins" used to report not started.
        $DB->insert_record('local_tm_completions', (object)['userid' => $user->id, 'competencyid' => $comp,
            'courseid' => $c1->id, 'status' => 'completed', 'progress' => 100, 'expirydate' => time() + YEARSECS]);
        $DB->insert_record('local_tm_completions', (object)['userid' => $user->id, 'competencyid' => $comp,
            'courseid' => $c2->id, 'status' => 'not_started', 'progress' => 0]);
        $stats = local_trainingmatrix_get_staff_compliance($user->id)['stats'];
        $this->assertSame(1, $stats['completed']);
        $this->assertEquals(100, $stats['compliance_rate']);
    }

    /** Department filtering applies to every statistic, not only the totals. */
    public function test_department_stats_filter(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $pos = $DB->insert_record('local_tm_positions', (object)['name' => 'P']);
        $comp = $DB->insert_record('local_tm_competencies', (object)['name' => 'C']);
        $DB->insert_record('local_tm_pos_comps', (object)['positionid' => $pos, 'competencyid' => $comp]);
        $d1 = $DB->insert_record('local_tm_departments', (object)['name' => 'D1']);
        $d2 = $DB->insert_record('local_tm_departments', (object)['name' => 'D2']);
        $course = $this->getDataGenerator()->create_course();
        foreach ([$d1, $d2] as $dept) {
            $user = $this->getDataGenerator()->create_user();
            $DB->insert_record('local_tm_staff', (object)['userid' => $user->id, 'positionid' => $pos,
                'departmentid' => $dept, 'status' => 'active']);
            $DB->insert_record('local_tm_completions', (object)['userid' => $user->id, 'competencyid' => $comp,
                'courseid' => $course->id, 'status' => 'completed', 'progress' => 100,
                'expirydate' => $dept == $d1 ? time() - DAYSECS : time() + YEARSECS]);
        }
        $all = external\get_compliance_stats::execute(0);
        $this->assertEquals(1, $all['expired']);
        $only2 = external\get_compliance_stats::execute($d2);
        $this->assertEquals(1, $only2['total_staff']);
        $this->assertEquals(0, $only2['expired']);
    }

    /** Remote AI output is cleaned before it is stored. */
    public function test_ai_generation_is_sanitised(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('siteid', 'site', 'local_trainingmatrix');
        set_config('apikey', 'key', 'local_trainingmatrix');
        $pos = $DB->insert_record('local_tm_positions', (object)['name' => 'Operator']);
        \curl::mock_response(json_encode(['competencies' => [
            ['name' => 'Safe <img src=x onerror=alert(1)>work', 'code' => '<b>X1</b>', 'category' => '<i>Safety</i>',
                'description' => '<script>bad()</script>Desc', 'mandatory' => true],
            ['code' => 'NONAME'],
            'not-an-array',
        ]]));
        // Mocked responses are served last-in first-out: the credit check comes first.
        \curl::mock_response(json_encode(['credits' => 100]));
        $result = (new local\ai_client())->generate((int)$pos);
        $this->assertTrue($result['ok'], $result['error']);
        $this->assertCount(1, $result['created']);
        $record = $DB->get_record('local_tm_competencies', ['code' => 'bX1b']);
        $this->assertNotFalse($record);
        $this->assertStringNotContainsString('<', $record->name . $record->category . $record->description);
        $this->assertTrue($DB->record_exists('local_tm_pos_comps', ['positionid' => $pos, 'competencyid' => $record->id]));
    }

    /** User pickers always include the selected user, even beyond the listing limit. */
    public function test_user_options_include_selected(): void {
        $this->resetAfterTest();
        $users = [];
        for ($i = 0; $i < 3; $i++) {
            $users[] = $this->getDataGenerator()->create_user(['lastname' => 'Zz' . $i]);
        }
        $options = local_trainingmatrix_user_options([$users[2]->id], 1);
        $this->assertArrayHasKey($users[2]->id, $options);
        $this->assertArrayNotHasKey(guest_user()->id, $options);
    }

    /** AI output matches existing competencies by code or name regardless of case. */
    public function test_ai_generation_matches_case_insensitively(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('siteid', 'site', 'local_trainingmatrix');
        set_config('apikey', 'key', 'local_trainingmatrix');
        $pos = $DB->insert_record('local_tm_positions', (object)['name' => 'Operator']);
        $existing = $DB->insert_record('local_tm_competencies', (object)['name' => 'Manual Handling', 'code' => 'MH1']);
        \curl::mock_response(json_encode(['competencies' => [
            ['name' => 'manual handling', 'code' => ''],
            ['name' => 'Something else', 'code' => 'mh1'],
        ]]));
        \curl::mock_response(json_encode(['credits' => 100]));
        $result = (new local\ai_client())->generate((int)$pos);
        $this->assertSame([], $result['created']);
        $this->assertCount(2, $result['linked']);
        $this->assertSame(1, $DB->count_records('local_tm_competencies'));
        $this->assertTrue($DB->record_exists('local_tm_pos_comps', ['positionid' => $pos, 'competencyid' => $existing]));
    }
}
