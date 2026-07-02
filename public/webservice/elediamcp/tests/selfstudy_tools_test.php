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
use webservice_elediamcp\local\ai\registry;
use webservice_elediamcp\local\ai\tool_exception;
use webservice_elediamcp\local\ai\tools\moodle_selfstudy_create_quiz;
use webservice_elediamcp\local\ai\tools\moodle_selfstudy_get_quiz;
use webservice_elediamcp\local\ai\tools\moodle_selfstudy_list_quizzes;
use webservice_elediamcp\local\ai\tools\moodle_selfstudy_submit_attempt;

/**
 * Tests for the optional self-study MCP tools.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\registry
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_selfstudy_create_quiz
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_selfstudy_get_quiz
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_selfstudy_list_quizzes
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_selfstudy_submit_attempt
 */
final class selfstudy_tools_test extends advanced_testcase {
    /** @var class-string[] All self-study tool classes. */
    private const TOOLS = [
        moodle_selfstudy_create_quiz::class,
        moodle_selfstudy_get_quiz::class,
        moodle_selfstudy_list_quizzes::class,
        moodle_selfstudy_submit_attempt::class,
    ];

    /**
     * Whether local_lernhive_selfstudy is installed on the test site.
     *
     * @return bool
     */
    private static function plugin_installed(): bool {
        return class_exists('\local_lernhive_selfstudy\local\quiz_service');
    }

    /**
     * The registry follows plugin presence and never fatals.
     */
    public function test_registry_follows_plugin_presence(): void {
        $names = registry::names();
        foreach (self::TOOLS as $class) {
            if (self::plugin_installed()) {
                $this->assertContains($class::name(), $names);
                $this->assertSame($class, registry::find($class::name()));
            } else {
                $this->assertNotContains($class::name(), $names);
                $this->assertNull(registry::find($class::name()));
            }
        }
    }

    /**
     * Static metadata is complete regardless of plugin presence.
     */
    public function test_tool_metadata_complete(): void {
        foreach (self::TOOLS as $class) {
            $this->assertNotSame('', $class::name());
            $this->assertNotSame('', $class::title());
            $this->assertNotSame('', $class::description());
            $input = $class::input_schema();
            $this->assertSame('object', $input['type']);
            $output = $class::output_schema();
            $this->assertContains('summary', $output['required']);
            $this->assertIsArray($class::annotations());
        }
        // The quiz flow tools must not leak solutions before submission.
        $this->assertStringContainsString('WITHOUT the solutions', moodle_selfstudy_get_quiz::description());
    }

    /**
     * Without the plugin every tool fails with a clear business error.
     */
    public function test_execute_requires_plugin(): void {
        global $USER;
        if (self::plugin_installed()) {
            $this->markTestSkipped('local_lernhive_selfstudy is installed on this site.');
        }
        $this->resetAfterTest(true);
        $this->setAdminUser();

        $cases = [
            [moodle_selfstudy_create_quiz::class, ['course_id' => 1, 'topic' => 'Test']],
            [moodle_selfstudy_list_quizzes::class, []],
            [moodle_selfstudy_get_quiz::class, ['quiz_id' => 1]],
            [moodle_selfstudy_submit_attempt::class,
                ['quiz_id' => 1, 'answers' => [['question_id' => 1, 'answer_index' => 0]]]],
        ];
        foreach ($cases as [$class, $args]) {
            try {
                $class::execute($args, $USER);
                $this->fail('tool_exception expected for ' . $class);
            } catch (tool_exception $ex) {
                $this->assertStringContainsString('not installed', $ex->getMessage());
            }
        }
    }

    /**
     * Create previews fail early when the course is not opted in.
     */
    public function test_create_requires_course_opt_in(): void {
        if (!self::plugin_installed()) {
            $this->markTestSkipped('local_lernhive_selfstudy is not installed on this site.');
        }
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);

        $this->expectException(tool_exception::class);
        $this->expectExceptionMessageMatches('/not enabled in this course/');
        moodle_selfstudy_create_quiz::execute([
            'course_id' => (int) $course->id,
            'topic' => 'Photosynthese',
        ], $student);
    }

    /**
     * Quiz flow works end to end against a seeded quiz: get without solutions,
     * submit grades and returns feedback.
     */
    public function test_get_and_submit_flow(): void {
        global $DB;
        if (!self::plugin_installed()) {
            $this->markTestSkipped('local_lernhive_selfstudy is not installed on this site.');
        }
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($student->id, $course->id, 'student');
        $this->setUser($student);
        \local_lernhive_selfstudy\local\course_settings::save((int) $course->id, true, 0, 2);

        $now = time();
        $quizid = $DB->insert_record('local_lhss_quiz', (object) [
            'courseid' => (int) $course->id,
            'userid' => (int) $student->id,
            'title' => 'Flow quiz',
            'topic' => 'Flow',
            'sourcecmid' => 0,
            'questioncount' => 1,
            'language' => 'en',
            'timecreated' => $now,
            'timemodified' => $now,
        ]);
        $DB->insert_record('local_lhss_question', (object) [
            'quizid' => $quizid,
            'sortorder' => 1,
            'qtype' => 'multichoice',
            'competencyid' => 0,
            'payload' => json_encode([
                'question' => 'Pick b', 'options' => ['a', 'b'], 'correct' => 1, 'feedback' => 'b it is',
            ]),
        ]);

        $got = moodle_selfstudy_get_quiz::execute(['quiz_id' => (int) $quizid], $student);
        $this->assertCount(1, $got['questions']);
        $this->assertArrayNotHasKey('correct', $got['questions'][0]);

        $submitted = moodle_selfstudy_submit_attempt::execute([
            'quiz_id' => (int) $quizid,
            'answers' => [['question_id' => $got['questions'][0]['question_id'], 'answer_index' => 1]],
        ], $student);
        $this->assertTrue($submitted['submitted']);
        $this->assertSame(100, $submitted['score']);
        $this->assertSame('b it is', $submitted['questions'][0]['feedback']);

        $list = moodle_selfstudy_list_quizzes::execute(['course_id' => (int) $course->id], $student);
        $this->assertCount(1, $list['quizzes']);
        $this->assertSame(100, $list['quizzes'][0]['best_score']);

        // Foreign quizzes stay inaccessible.
        $other = $this->getDataGenerator()->create_user();
        $this->expectException(tool_exception::class);
        moodle_selfstudy_get_quiz::execute(['quiz_id' => (int) $quizid], $other);
    }
}
