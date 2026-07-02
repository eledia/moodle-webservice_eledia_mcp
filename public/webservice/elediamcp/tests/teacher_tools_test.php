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

namespace webservice_elediamcp;

use advanced_testcase;
use required_capability_exception;
use webservice_elediamcp\local\ai\registry;
use webservice_elediamcp\local\ai\tool_exception;
use webservice_elediamcp\local\ai\tools\moodle_course_health;
use webservice_elediamcp\local\ai\tools\moodle_grade_submission;
use webservice_elediamcp\local\ai\tools\moodle_manage_sections;
use webservice_elediamcp\local\ai\tools\moodle_message_course_students;
use webservice_elediamcp\local\ai\tools\moodle_read_submission;
use webservice_elediamcp\local\ai\tools\moodle_update_activity;

/**
 * Tests for the teacher workflow tools.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_course_health
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_grade_submission
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_manage_sections
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_message_course_students
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_read_submission
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_update_activity
 */
final class teacher_tools_test extends advanced_testcase {
    /**
     * Create course, teacher and two students, one with a submitted assignment.
     *
     * @return array{0: \stdClass, 1: \stdClass, 2: \stdClass, 3: \stdClass, 4: \stdClass}
     *         Course, teacher, submitting student, other student, assign cm record.
     */
    private function grading_fixture(): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $student1 = $this->getDataGenerator()->create_user();
        $student2 = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($student1->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($student2->id, $course->id, 'student');

        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay',
            'grade' => 100,
            'assignsubmission_onlinetext_enabled' => 1,
            'assignfeedback_comments_enabled' => 1,
        ]);
        $cm = get_coursemodule_from_instance('assign', $assign->id, $course->id, false, MUST_EXIST);

        // Submitted online text for student1 (latest attempt).
        $submissionid = $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $student1->id,
            'status' => 'submitted',
            'attemptnumber' => 0,
            'latest' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $assign->id,
            'submission' => $submissionid,
            'onlinetext' => '<p>Meine Antwort zur Aufgabe.</p>',
            'onlineformat' => FORMAT_HTML,
        ]);

        return [$course, $teacher, $student1, $student2, $cm];
    }

    /**
     * The registry lists all six teacher tools.
     */
    public function test_registry_contains_teacher_tools(): void {
        $names = registry::names();
        $expected = [
            'moodle_read_submission', 'moodle_grade_submission', 'moodle_course_health',
            'moodle_message_course_students', 'moodle_update_activity', 'moodle_manage_sections',
        ];
        foreach ($expected as $name) {
            $this->assertContains($name, $names);
        }
    }

    /**
     * read_submission returns the online text and requires the grading capability.
     */
    public function test_read_submission(): void {
        $this->resetAfterTest(true);
        [, $teacher, $student1, $student2, $cm] = $this->grading_fixture();
        $this->setUser($teacher);

        $result = moodle_read_submission::execute(['cmid' => (int) $cm->id, 'user_id' => (int) $student1->id], $teacher);
        $this->assertSame('submitted', $result['submission']['status']);
        $this->assertStringContainsString('Meine Antwort', $result['online_text']);
        $this->assertNull($result['current_grade']);

        $nosubmission = moodle_read_submission::execute(
            ['cmid' => (int) $cm->id, 'user_id' => (int) $student2->id],
            $teacher
        );
        $this->assertNull($nosubmission['submission']);

        $this->setUser($student1);
        $this->expectException(required_capability_exception::class);
        moodle_read_submission::execute(['cmid' => (int) $cm->id, 'user_id' => (int) $student1->id], $student1);
    }

    /**
     * grade_submission previews, saves the grade and stores feedback.
     */
    public function test_grade_submission_preview_and_confirm(): void {
        global $DB;
        $this->resetAfterTest(true);
        [, $teacher, $student1, , $cm] = $this->grading_fixture();
        $this->setUser($teacher);

        $args = [
            'cmid' => (int) $cm->id,
            'user_id' => (int) $student1->id,
            'grade' => 85,
            'feedback' => 'Gute Argumentation, Quellen fehlen.',
        ];
        $preview = moodle_grade_submission::execute($args, $teacher);
        $this->assertTrue($preview['requires_confirmation']);
        $this->assertFalse($preview['preview']['marking_workflow']);
        $this->assertNull($DB->get_field('assign_grades', 'grade', ['userid' => $student1->id]) ?: null);

        $result = moodle_grade_submission::execute($args + ['confirm' => true], $teacher);
        $this->assertTrue($result['graded']);
        $this->assertSame('released', $result['result']['workflow_state']);
        $this->assertTrue($result['result']['feedback_saved']);
        $this->assertEquals(85, (float) $DB->get_field('assign_grades', 'grade', ['userid' => $student1->id]));

        $read = moodle_read_submission::execute(['cmid' => (int) $cm->id, 'user_id' => (int) $student1->id], $teacher);
        $this->assertEquals(85, $read['current_grade']['grade']);
    }

    /**
     * grade_submission validates the grade range.
     */
    public function test_grade_submission_range_validation(): void {
        $this->resetAfterTest(true);
        [, $teacher, $student1, , $cm] = $this->grading_fixture();
        $this->setUser($teacher);

        $this->expectException(tool_exception::class);
        $this->expectExceptionMessageMatches('/between 0 and 100/');
        moodle_grade_submission::execute([
            'cmid' => (int) $cm->id, 'user_id' => (int) $student1->id, 'grade' => 150, 'confirm' => true,
        ], $teacher);
    }

    /**
     * course_health reports students, inactivity and submission coverage.
     */
    public function test_course_health(): void {
        $this->resetAfterTest(true);
        [$course, $teacher, , , ] = $this->grading_fixture();
        $this->setUser($teacher);

        $result = moodle_course_health::execute(['course_id' => (int) $course->id], $teacher);
        $this->assertSame(2, $result['student_count']);
        // Freshly created users never accessed the course.
        $this->assertSame(2, $result['inactive_count']);
        $this->assertCount(1, $result['assignments']);
        $this->assertSame(1, $result['assignments'][0]['submitted']);
        $this->assertSame(1, $result['assignments'][0]['missing']);
    }

    /**
     * message_course_students resolves the not_submitted target and sends on confirm.
     */
    public function test_message_course_students(): void {
        $this->resetAfterTest(true);
        [$course, $teacher, , $student2, $cm] = $this->grading_fixture();
        $this->setUser($teacher);

        $args = [
            'course_id' => (int) $course->id,
            'message' => 'Bitte denk an die Abgabe!',
            'target' => 'not_submitted',
            'assign_cmid' => (int) $cm->id,
        ];
        $preview = moodle_message_course_students::execute($args, $teacher);
        $this->assertTrue($preview['requires_confirmation']);
        $this->assertSame(1, $preview['recipient_count']);
        $this->assertStringContainsString(fullname($student2), implode(' ', $preview['recipients_preview']));

        $result = moodle_message_course_students::execute($args + ['confirm' => true], $teacher);
        $this->assertTrue($result['sent']);
        $this->assertSame(1, $result['sent_count'], 'Skipped: ' . json_encode($result['skipped']));
    }

    /**
     * update_activity changes assign dates without disabling submission plugins.
     */
    public function test_update_activity_assign_dates(): void {
        global $DB;
        $this->resetAfterTest(true);
        [, $teacher, , , $cm] = $this->grading_fixture();
        $this->setUser($teacher);
        $duedate = time() + WEEKSECS;

        $result = moodle_update_activity::execute([
            'cmid' => (int) $cm->id,
            'duedate' => $duedate,
            'name' => 'Essay v2',
            'confirm' => true,
        ], $teacher);

        $this->assertTrue($result['updated']);
        $assign = $DB->get_record('assign', ['id' => (int) $cm->instance], '*', MUST_EXIST);
        $this->assertEquals($duedate, (int) $assign->duedate);
        $this->assertSame('Essay v2', $assign->name);
        // The onlinetext submission plugin must still be enabled.
        $this->assertEquals(1, $DB->get_field('assign_plugin_config', 'value', [
            'assignment' => (int) $cm->instance,
            'subtype' => 'assignsubmission',
            'plugin' => 'onlinetext',
            'name' => 'enabled',
        ]));
    }

    /**
     * update_activity updates page content and visibility.
     */
    public function test_update_activity_page(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Old', 'content' => '<p>old</p>',
        ]);
        $this->setUser($teacher);

        $result = moodle_update_activity::execute([
            'cmid' => (int) $page->cmid,
            'content' => '<p>brand new body</p>',
            'visible' => false,
            'confirm' => true,
        ], $teacher);

        $this->assertTrue($result['updated']);
        $this->assertFalse($result['activity']['visible']);
        $this->assertSame('<p>brand new body</p>', $DB->get_field('page', 'content', ['id' => $page->id]));
    }

    /**
     * update_activity rejects calls without any supported change.
     */
    public function test_update_activity_requires_changes(): void {
        $this->resetAfterTest(true);
        [, $teacher, , , $cm] = $this->grading_fixture();
        $this->setUser($teacher);

        $this->expectException(tool_exception::class);
        $this->expectExceptionMessageMatches('/No supported field/');
        moodle_update_activity::execute(['cmid' => (int) $cm->id], $teacher);
    }

    /**
     * manage_sections creates, renames and hides sections.
     */
    public function test_manage_sections(): void {
        global $DB;
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['numsections' => 2]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $course->id, 'editingteacher');
        $this->setUser($teacher);

        $created = moodle_manage_sections::execute([
            'course_id' => (int) $course->id, 'action' => 'create', 'name' => 'Extra Woche', 'confirm' => true,
        ], $teacher);
        $this->assertTrue($created['done']);
        $this->assertSame(3, $created['section']['number']);
        $this->assertSame('Extra Woche', $created['section']['name']);

        $renamed = moodle_manage_sections::execute([
            'course_id' => (int) $course->id, 'action' => 'rename', 'section' => 1, 'name' => 'Woche 1', 'confirm' => true,
        ], $teacher);
        $this->assertSame('Woche 1', $renamed['section']['name']);

        $hidden = moodle_manage_sections::execute([
            'course_id' => (int) $course->id, 'action' => 'set_visibility', 'section' => 2,
            'visible' => false, 'confirm' => true,
        ], $teacher);
        $this->assertFalse($hidden['section']['visible']);
        $this->assertEquals(0, $DB->get_field('course_sections', 'visible', [
            'course' => $course->id, 'section' => 2,
        ]));
    }

    /**
     * Section management requires moodle/course:update.
     */
    public function test_manage_sections_capability(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        moodle_manage_sections::execute([
            'course_id' => (int) $course->id, 'action' => 'create', 'confirm' => true,
        ], $student);
    }
}
