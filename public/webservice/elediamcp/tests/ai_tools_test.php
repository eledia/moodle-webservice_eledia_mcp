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
use webservice_elediamcp\local\ai\tools\moodle_me;
use webservice_elediamcp\local\ai\tools\moodle_verify_user_context;

/**
 * Tests for the AI-native tool registry and tools.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\registry
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_me
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_verify_user_context
 */
final class ai_tools_test extends advanced_testcase {
    /**
     * Registry exposes the MVP AI tools.
     */
    public function test_registry_exposes_mvp_tools(): void {
        $names = registry::names();
        $this->assertContains('moodle_me', $names);
        $this->assertContains('moodle_verify_user_context', $names);

        $this->assertSame(moodle_me::class, registry::find('moodle_me'));
        $this->assertSame(
            moodle_verify_user_context::class,
            registry::find('moodle_verify_user_context')
        );
        $this->assertNull(registry::find('does_not_exist'));
    }

    /**
     * Every registered tool advertises a sensible name, description and schemas.
     */
    public function test_registered_tools_have_complete_metadata(): void {
        foreach (registry::all() as $class) {
            $name = $class::name();
            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_.\\-]{1,128}$/', $name,
                "Tool name {$name} must be MCP-compliant.");
            $this->assertNotEmpty($class::title());
            $this->assertNotEmpty($class::description());

            $input = $class::input_schema();
            $this->assertIsArray($input);
            $this->assertSame('object', $input['type']);

            $output = $class::output_schema();
            $this->assertIsArray($output);
            $this->assertSame('object', $output['type']);

            $ann = $class::annotations();
            $this->assertIsArray($ann);
            $this->assertArrayHasKey('readOnlyHint', $ann);
            $this->assertArrayHasKey('destructiveHint', $ann);
        }
    }

    /**
     * moodle_me returns identity + site metadata for the authenticated user.
     */
    public function test_moodle_me_execute(): void {
        $this->resetAfterTest(true);
        $user = $this->getDataGenerator()->create_user([
            'firstname' => 'Maria',
            'lastname' => 'Mueller',
            'email' => 'maria@example.test',
            'lang' => 'en',
        ]);
        $this->setUser($user);

        $output = moodle_me::execute([], $user);

        $this->assertSame((int) $user->id, $output['user']['id']);
        $this->assertSame('Maria Mueller', $output['user']['fullname']);
        $this->assertSame('maria@example.test', $output['user']['email']);
        $this->assertSame('en', $output['user']['lang']);
        $this->assertFalse($output['user']['is_admin']);
        $this->assertArrayHasKey('url', $output['site']);
        $this->assertNotEmpty($output['site']['name']);
    }

    /**
     * moodle_verify_user_context lists active enrolments with role information.
     */
    public function test_moodle_verify_user_context_lists_courses(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['shortname' => 'C1', 'fullname' => 'Course One']);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        $output = moodle_verify_user_context::execute([], $user);

        $this->assertTrue($output['valid']);
        $this->assertSame((int) $user->id, $output['user']['id']);
        $this->assertNotEmpty($output['summary']);
        $this->assertCount(1, $output['courses']);
        $this->assertSame((int) $course->id, $output['courses'][0]['id']);
        $this->assertSame('C1', $output['courses'][0]['shortname']);
        $this->assertContains('student', $output['courses'][0]['roles']);
    }

    /**
     * The course_id filter restricts the response to one course.
     */
    public function test_moodle_verify_user_context_filter(): void {
        $this->resetAfterTest(true);

        $course1 = $this->getDataGenerator()->create_course();
        $course2 = $this->getDataGenerator()->create_course();
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course1->id, 'student');
        $this->getDataGenerator()->enrol_user($user->id, $course2->id, 'student');
        $this->setUser($user);

        $output = moodle_verify_user_context::execute(['course_id' => (int) $course2->id], $user);

        $this->assertCount(1, $output['courses']);
        $this->assertSame((int) $course2->id, $output['courses'][0]['id']);
    }

    /**
     * When the user has no enrolments the summary is informative.
     */
    public function test_moodle_verify_user_context_no_enrolments(): void {
        $this->resetAfterTest(true);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);

        $output = moodle_verify_user_context::execute([], $user);
        $this->assertTrue($output['valid']);
        $this->assertSame([], $output['courses']);
        $this->assertStringContainsString('no active course enrolments', $output['summary']);
    }

    /**
     * include_capabilities returns the wildcard marker for site admins.
     */
    public function test_moodle_verify_user_context_admin_capabilities(): void {
        $this->resetAfterTest(true);

        $this->setAdminUser();
        global $USER;
        $output = moodle_verify_user_context::execute(['include_capabilities' => true], $USER);

        $this->assertArrayHasKey('capabilities', $output);
        $this->assertContains('*', $output['capabilities']);
        $this->assertTrue($output['is_admin']);
    }
}
