<?php
// This file is part of Moodle - http://moodle.org/
// Moodle is free software under the GNU General Public License v3 or later.

namespace local_trainingmatrix;

defined('MOODLE_INTERNAL') || die();

/**
 * Core integration coverage, runnable in an installed Moodle PHPUnit environment.
 *
 * @package local_trainingmatrix
 * @copyright 2026 LMS-Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_trainingmatrix\learner_training
 */
final class learner_training_test extends \advanced_testcase {
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
