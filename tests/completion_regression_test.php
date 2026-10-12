<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.

namespace local_trainingmatrix;

defined('MOODLE_INTERNAL') || die();

/**
 * Regression coverage for completion status and exports.
 *
 * @package local_trainingmatrix
 * @copyright 2026 LMS Labs
 * @license https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class completion_regression_test extends \advanced_testcase {
    /**
     * Completion status remains stable across expiry jobs.
     *
     * @covers ::local_trainingmatrix_get_staff_compliance
     */
    public function test_status_after_expiry_job(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $position = $DB->insert_record('local_tm_positions', (object)['name' => 'Regression']);
        $DB->insert_record('local_tm_staff', (object)[
            'userid' => $user->id, 'positionid' => $position, 'status' => 'active',
        ]);
        foreach ([-DAYSECS, 14 * DAYSECS, 120 * DAYSECS] as $index => $offset) {
            $competency = $DB->insert_record('local_tm_competencies', (object)[
                'name' => 'Competency ' . $index, 'code' => 'REG' . $index,
            ]);
            $DB->insert_record('local_tm_pos_comps', (object)[
                'positionid' => $position, 'competencyid' => $competency,
            ]);
            $DB->insert_record('local_tm_completions', (object)[
                'userid' => $user->id, 'courseid' => $course->id, 'competencyid' => $competency,
                'status' => 'completed', 'progress' => 100, 'expirydate' => time() + $offset,
            ]);
        }
        $before = local_trainingmatrix_get_staff_compliance($user->id)['stats'];
        ob_start();
        try {
            (new \local_trainingmatrix\task\check_expiry())->execute();
        } finally {
            ob_end_clean();
        }
        $this->assertSame($before, local_trainingmatrix_get_staff_compliance($user->id)['stats']);
        $this->assertEquals(1, local_trainingmatrix_get_compliance_stats()['expired']);
        $this->assertEquals(1, local_trainingmatrix_get_compliance_stats()['expiring_soon']);
        // A previously warned completion must eventually become expired.
        $DB->set_field('local_tm_completions', 'expirydate', time() - DAYSECS, ['status' => 'expiring_soon']);
        ob_start();
        try {
            (new \local_trainingmatrix\task\check_expiry())->execute();
        } finally {
            ob_end_clean();
        }
        $this->assertEquals(2, $DB->count_records('local_tm_completions', ['status' => 'expired']));
    }

    /**
     * Every required mapping is synced, without duplicates or unrelated awards.
     *
     * @covers ::local_trainingmatrix_sync_course_completion
     */
    public function test_direct_sync_mapping_scope(): void {
        global $DB, $CFG;
        require_once($CFG->dirroot . '/local/trainingmatrix/lib.php');
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $position = $DB->insert_record('local_tm_positions', (object)['name' => 'Regression']);
        $DB->insert_record('local_tm_staff', (object)[
            'userid' => $user->id, 'positionid' => $position, 'status' => 'active',
        ]);
        for ($i = 0; $i < 4; $i++) {
            $competency = $DB->insert_record('local_tm_competencies', (object)[
                'name' => 'Competency ' . $i, 'code' => 'REG' . $i,
            ]);
            $DB->insert_record('local_tm_comp_courses', (object)[
                'competencyid' => $competency, 'courseid' => $course->id, 'expirymonths' => 12,
            ]);
            if ($i < 3) {
                $DB->insert_record('local_tm_pos_comps', (object)[
                    'positionid' => $position, 'competencyid' => $competency,
                ]);
            }
        }
        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id, 'course' => $course->id, 'timecompleted' => time() - DAYSECS,
        ]);
        $this->assertTrue(local_trainingmatrix_sync_course_completion($course->id, $user->id));
        $this->assertTrue(local_trainingmatrix_sync_course_completion($course->id, $user->id));
        $this->assertEquals(3, $DB->count_records('local_tm_completions', ['userid' => $user->id]));
        $DB->set_field('local_tm_staff', 'status', 'inactive', ['userid' => $user->id]);
        $this->assertFalse(local_trainingmatrix_sync_course_completion($course->id, $user->id));
    }

    /**
     * Excel export is a real workbook, not CSV with another extension.
     *
     * @covers \local_trainingmatrix\external\export_report::execute
     */
    public function test_excel_workbook(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $result = \local_trainingmatrix\external\export_report::execute('excel');
        $this->assertStringEndsWith('.xlsx', $result['filename']);
        $file = tempnam(make_request_directory(), 'matrix');
        file_put_contents($file, base64_decode($result['content'], true));
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($file);
        try {
            $this->assertSame('Staff Name', $book->getActiveSheet()->getCell('A1')->getValue());
        } finally {
            $book->disconnectWorksheets();
            unlink($file);
        }
    }
}
