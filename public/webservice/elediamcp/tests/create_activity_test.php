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
use webservice_elediamcp\local\ai\tools\moodle_create_activity;

/**
 * Tests for the moodle_create_activity AI tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_create_activity
 */
final class create_activity_test extends advanced_testcase {
    /**
     * The registry lists and resolves the tool.
     */
    public function test_registry_contains_tool(): void {
        $this->assertContains('moodle_create_activity', registry::names());
        $this->assertSame(moodle_create_activity::class, registry::find('moodle_create_activity'));
    }

    /**
     * Input and output schemas have the expected shape.
     */
    public function test_schema_shapes(): void {
        $input = moodle_create_activity::input_schema();
        $this->assertSame('object', $input['type']);
        $this->assertContains('course_id', $input['required']);
        $this->assertContains('type', $input['required']);
        $this->assertFalse($input['properties']['confirm']['default']);

        $output = moodle_create_activity::output_schema();
        $this->assertContains('requires_confirmation', $output['required']);

        $annotations = moodle_create_activity::annotations();
        $this->assertFalse($annotations['readOnlyHint']);
        $this->assertTrue($annotations['openWorldHint']);
    }

    /**
     * Without confirm the tool previews and creates nothing.
     */
    public function test_preview_requires_confirmation(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $before = $DB->count_records('course_modules', ['course' => $course->id]);
        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'page',
            'name' => 'Preview page',
            'content' => '<p>Body</p>',
        ], $USER);

        $this->assertFalse($result['created']);
        $this->assertTrue($result['requires_confirmation']);
        $this->assertNull($result['activity']);
        $this->assertSame('page', $result['preview']['type']);
        $this->assertSame($before, $DB->count_records('course_modules', ['course' => $course->id]));
    }

    /**
     * A page is created with its content persisted in the requested section.
     */
    public function test_create_page(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);

        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'page',
            'name' => 'My page',
            'content' => '<p>Hello world</p>',
            'section' => 2,
            'confirm' => true,
        ], $USER);

        $this->assertTrue($result['created']);
        $activity = $result['activity'];
        $this->assertGreaterThan(0, $activity['cmid']);
        $page = $DB->get_record('page', ['id' => $activity['instance_id']], '*', MUST_EXIST);
        $this->assertSame('<p>Hello world</p>', $page->content);
        $this->assertEquals(FORMAT_HTML, $page->contentformat);

        $modinfo = get_fast_modinfo($course->id);
        $cm = $modinfo->get_cm($activity['cmid']);
        $this->assertSame(2, (int) $cm->sectionnum);
        $this->assertSame('My page', $cm->get_formatted_name());
    }

    /**
     * A label derives its name from the intro content.
     */
    public function test_create_label(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'label',
            'intro' => '<p>Welcome to the course</p>',
            'confirm' => true,
        ], $USER);

        $this->assertTrue($result['created']);
        $label = $DB->get_record('label', ['id' => $result['activity']['instance_id']], '*', MUST_EXIST);
        $this->assertStringContainsString('Welcome to the course', $label->name);
        $this->assertStringContainsString('#module-' . $result['activity']['cmid'], $result['activity']['url']);
    }

    /**
     * A url activity is created with the external url normalised.
     */
    public function test_create_url(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'url',
            'name' => 'Example link',
            'external_url' => 'www.example.com',
            'confirm' => true,
        ], $USER);

        $this->assertTrue($result['created']);
        $url = $DB->get_record('url', ['id' => $result['activity']['instance_id']], '*', MUST_EXIST);
        $this->assertSame('http://www.example.com', $url->externalurl);
    }

    /**
     * A book is created with sequential chapters.
     */
    public function test_create_book_with_chapters(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'book',
            'name' => 'Handbook',
            'chapters' => [
                ['title' => 'Intro', 'content' => '<p>One</p>'],
                ['title' => 'Middle', 'content' => '<p>Two</p>'],
                ['title' => 'End', 'content' => '<p>Three</p>'],
            ],
            'confirm' => true,
        ], $USER);

        $this->assertTrue($result['created']);
        $this->assertSame(3, $result['activity']['chapters_created']);
        $bookid = $result['activity']['instance_id'];
        $chapters = array_values($DB->get_records('book_chapters', ['bookid' => $bookid], 'pagenum ASC'));
        $this->assertCount(3, $chapters);
        $this->assertSame([1, 2, 3], array_map(static fn($chapter): int => (int) $chapter->pagenum, $chapters));
        $this->assertSame(['Intro', 'Middle', 'End'], array_map(static fn($chapter): string => $chapter->title, $chapters));
    }

    /**
     * An assignment persists dates, grade and submission plugin settings.
     */
    public function test_create_assign(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $duedate = time() + WEEKSECS;

        $result = moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'assign',
            'name' => 'Essay',
            'intro' => '<p>Write an essay.</p>',
            'duedate' => $duedate,
            'grade' => 80,
            'confirm' => true,
        ], $USER);

        $this->assertTrue($result['created']);
        $instanceid = $result['activity']['instance_id'];
        $assign = $DB->get_record('assign', ['id' => $instanceid], '*', MUST_EXIST);
        $this->assertEquals($duedate, $assign->duedate);
        $this->assertEquals(80, $assign->grade);

        foreach (['onlinetext', 'file'] as $plugin) {
            $this->assertEquals(1, $DB->get_field('assign_plugin_config', 'value', [
                'assignment' => $instanceid,
                'subtype' => 'assignsubmission',
                'plugin' => $plugin,
                'name' => 'enabled',
            ]));
        }

        $this->assertTrue($DB->record_exists('grade_items', [
            'itemtype' => 'mod',
            'itemmodule' => 'assign',
            'iteminstance' => $instanceid,
            'courseid' => $course->id,
        ]));
    }

    /**
     * Users without moodle/course:manageactivities are rejected.
     */
    public function test_capability_denied(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(required_capability_exception::class);
        moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'page',
            'name' => 'Nope',
            'content' => '<p>x</p>',
            'confirm' => true,
        ], $student);
    }

    /**
     * A nonexistent section is rejected and never created.
     */
    public function test_invalid_section(): void {
        global $DB, $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course(['numsections' => 3]);
        $sectionsbefore = $DB->count_records('course_sections', ['course' => $course->id]);

        try {
            moodle_create_activity::execute([
                'course_id' => (int) $course->id,
                'type' => 'page',
                'name' => 'Ghost',
                'content' => '<p>x</p>',
                'section' => 99,
                'confirm' => true,
            ], $USER);
            $this->fail('tool_exception expected.');
        } catch (tool_exception $ex) {
            $this->assertStringContainsString('Section 99 does not exist', $ex->getMessage());
        }
        $this->assertSame($sectionsbefore, $DB->count_records('course_sections', ['course' => $course->id]));
    }

    /**
     * Invalid types and missing type-specific fields are rejected.
     */
    public function test_invalid_type_and_missing_fields(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $base = ['course_id' => (int) $course->id, 'confirm' => true];

        $cases = [
            ['args' => $base + ['type' => 'quiz', 'name' => 'X'], 'needle' => 'Invalid type'],
            ['args' => $base + ['type' => 'page', 'name' => 'X'], 'needle' => 'content is required'],
            ['args' => $base + ['type' => 'url', 'name' => 'X'], 'needle' => 'external_url is required'],
            ['args' => $base + ['type' => 'url', 'name' => 'X', 'external_url' => 'not a url'],
                'needle' => 'not a valid URL'],
            ['args' => $base + ['type' => 'book', 'name' => 'X'], 'needle' => 'chapters is required'],
            ['args' => $base + ['type' => 'book', 'name' => 'X', 'chapters' => [['title' => 'T', 'content' => '']]],
                'needle' => 'Chapter 1'],
            ['args' => $base + ['type' => 'page', 'content' => '<p>x</p>'], 'needle' => 'name is required'],
            ['args' => $base + ['type' => 'label'], 'needle' => 'intro is required'],
        ];

        foreach ($cases as $case) {
            try {
                moodle_create_activity::execute($case['args'], $USER);
                $this->fail('tool_exception expected for: ' . $case['needle']);
            } catch (tool_exception $ex) {
                $this->assertStringContainsString($case['needle'], $ex->getMessage());
            }
        }
    }

    /**
     * Assignment date plausibility is validated.
     */
    public function test_assign_date_validation(): void {
        global $USER;
        $this->resetAfterTest(true);
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $this->expectException(tool_exception::class);
        $this->expectExceptionMessageMatches('/duedate must not be before/');
        moodle_create_activity::execute([
            'course_id' => (int) $course->id,
            'type' => 'assign',
            'name' => 'Essay',
            'duedate' => time(),
            'allowsubmissionsfromdate' => time() + DAYSECS,
            'confirm' => true,
        ], $USER);
    }
}
