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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_trainingmatrix;

defined('MOODLE_INTERNAL') || die();

#[\PHPUnit\Framework\Attributes\CoversClass(\local_trainingmatrix\learner_training::class)]
/**
 * Core integration coverage, runnable in an installed Moodle PHPUnit environment.
 *
 * @package local_trainingmatrix
 * @copyright 2026 LMS-Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_trainingmatrix\learner_training
 */
final class learner_training_test extends \advanced_testcase {
    public function test_subject_projection_rejects_unprivileged_actor_without_identity_change(): void {
        global $USER;
        $this->resetAfterTest();
        $actor = $this->getDataGenerator()->create_user();
        $subject = $this->getDataGenerator()->create_user();
        $this->setUser($actor);
        try {
            learner_training::for_user((int)$subject->id);
            $this->fail('Target reads must require system viewall.');
        } catch (\required_capability_exception $e) {
            $this->assertEquals($actor->id, $USER->id);
        }
    }

    public function test_authorised_projection_keeps_actor_and_all_enrolled_courses(): void {
        global $USER;
        $this->resetAfterTest();
        $subject = $this->getDataGenerator()->create_user();
        for ($i = 0; $i < 7; $i++) {
            $course = $this->getDataGenerator()->create_course();
            $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        }
        $this->setAdminUser();
        $actorid = $USER->id;
        $data = learner_training::for_user((int)$subject->id);
        $courses = array_filter($data['items'], static function ($item) {
            return $item['kind'] === 'course';
        });
        $this->assertCount(7, $courses);
        $this->assertEquals($actorid, $USER->id);
        $html = \local_trainingmatrix\output\training_cards::items($data['items']);
        foreach ($courses as $item) {
            $this->assertStringContainsString(s($item['name']), $html);
        }
        $this->assertEquals(7, substr_count($html, '<details'));
    }

    public function test_submitted_assignment_is_not_completion_or_a_mark(): void {
        global $DB;
        $this->resetAfterTest();
        $subject = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $DB->insert_record('assign_submission', (object)[
            'assignment' => $assign->id, 'userid' => $subject->id, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(), 'latest' => 1, 'attemptnumber' => 0,
        ]);
        $this->setUser($subject);
        $item = learner_training::get()['items'][0];
        $this->assertSame('Course not completed', $item['status']);
        $this->assertTrue($item['evidence'][0]['awaiting']);
        $this->assertSame('Submitted — awaiting marking', $item['evidence'][0]['status']);
        $this->assertFalse($DB->record_exists('course_completions', ['userid' => $subject->id]));
    }

    public function test_certificate_links_require_stored_pdf_and_actor_report_permission(): void {
        if (!\core_component::get_component_directory('mod_certificatepro')) {
            $this->markTestSkipped('mod_certificatepro is not installed.');
        }
        global $DB, $USER;
        $this->resetAfterTest();
        $subject = $this->getDataGenerator()->create_user();
        $actor = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($subject->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($actor->id, $course->id, 'student');
        $cert = $this->getDataGenerator()->create_module('certificatepro', ['course' => $course->id]);
        $context = \context_module::instance($cert->cmid);
        $role = create_role('Oversight only', 'tmoversight', '');
        assign_capability('local/trainingmatrix:viewall', CAP_ALLOW, $role, \context_system::instance()->id);
        role_assign($role, $actor->id, \context_system::instance()->id);
        $issueid = $DB->insert_record('certificatepro_issues', (object)[
            'certificateproid' => $cert->id, 'userid' => $subject->id,
            'code' => 'training-matrix-test', 'timecreated' => time(),
        ]);
        $this->setUser($subject);
        $find = static function (array $data): array {
            return array_values(array_filter($data['items'], static function ($item) {
                return $item['source'] === 'Certificate Pro';
            }))[0];
        };
        $this->assertNull($find(learner_training::get())['url']);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id, 'component' => 'mod_certificatepro', 'filearea' => 'issue',
            'itemid' => $issueid, 'filepath' => '/', 'filename' => 'award.pdf', 'mimetype' => 'application/pdf',
        ], '%PDF-1.4 test fixture');
        $this->assertInstanceOf(\moodle_url::class, $find(learner_training::get())['url']);
        $this->setUser($actor);
        $this->assertNull($find(learner_training::for_user((int)$subject->id))['url']);
        $this->assertEquals($actor->id, $USER->id);
        assign_capability('mod/certificatepro:viewreport', CAP_ALLOW, $role, $context->id);
        accesslib_clear_all_caches_for_unit_testing();
        $this->assertInstanceOf(
            \moodle_url::class,
            $find(learner_training::for_user((int)$subject->id))['url']
        );
        $DB->set_field('certificatepro_issues', 'revoked', 1, ['id' => $issueid]);
        $this->assertNull($find(learner_training::for_user((int)$subject->id))['url']);
        $this->assertEquals(1, $DB->count_records('certificatepro_issues'));
        $this->assertEquals($actor->id, $USER->id);
    }

    public function test_core_enrolments_are_owned_and_do_not_require_matrix_position(): void {
        global $DB;
        $this->resetAfterTest();
        $learner = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $private = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($learner->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $private->id, 'student');
        $DB->insert_record('course_completions', (object)[
            'course' => $course->id, 'userid' => $other->id, 'timecompleted' => time(),
        ]);
        $this->setUser($learner);
        $data = learner_training::get();
        $courses = array_values(array_filter($data['items'], static function ($item) {
            return $item['kind'] === 'course';
        }));
        $this->assertCount(1, $courses);
        $this->assertSame($course->fullname, $courses[0]['name']);
        $this->assertSame('Course not completed', $courses[0]['status']);
        $this->assertInstanceOf(\moodle_url::class, $courses[0]['url']);
        $DB->insert_record('course_completions', (object)[
            'course' => $course->id, 'userid' => $learner->id, 'timecompleted' => time(),
        ]);
        $data = learner_training::get();
        $this->assertSame('Course completed', $data['items'][0]['status']);
    }

    public function test_empty_learner_and_escaped_presentation(): void {
        $this->resetAfterTest();
        $this->setUser($this->getDataGenerator()->create_user());
        $this->assertSame([], learner_training::get()['items']);
        $html = \local_trainingmatrix\output\learner_view::table([[
            'kind' => 'course', 'name' => '<script>alert(1)</script>', 'source' => 'Moodle',
            'status' => 'Course not completed', 'due' => 0, 'expires' => 0,
            'url' => null, 'action' => '',
        ]]);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }
}
