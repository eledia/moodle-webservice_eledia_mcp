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
use webservice_elediamcp\local\ai\tool_exception;
use webservice_elediamcp\local\ai\tools\moodle_calendar_upcoming;
use webservice_elediamcp\local\ai\tools\moodle_course_contents;
use webservice_elediamcp\local\ai\tools\moodle_find_user;
use webservice_elediamcp\local\ai\tools\moodle_get_announcements;
use webservice_elediamcp\local\ai\tools\moodle_get_resource;
use webservice_elediamcp\local\ai\tools\moodle_my_assignments;
use webservice_elediamcp\local\ai\tools\moodle_my_grades;
use webservice_elediamcp\local\ai\tools\moodle_search_courses;
use webservice_elediamcp\local\ai\tools\moodle_send_message;
use webservice_elediamcp\local\ai\tools\moodle_verify_user_context;

/**
 * Permission-denial and visibility-boundary (negative) tests for the original
 * MVP AI tools. Each test sets up a boundary a user must not cross (hidden
 * course / hidden activity / foreign enrolment / privacy rule) and asserts
 * the tool either raises a tool_exception or silently excludes the data.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_course_contents
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_get_resource
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_search_courses
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_my_assignments
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_my_grades
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_get_announcements
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_calendar_upcoming
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_find_user
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_send_message
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_verify_user_context
 */
final class negative_cases_test extends advanced_testcase {
    /**
     * Course contents of a hidden course are rejected for an unenrolled user.
     */
    public function test_course_contents_rejects_unenrolled_user_on_hidden_course(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            moodle_course_contents::execute(['course_id' => (int) $course->id], $stranger);
            $this->fail('Expected tool_exception for an unenrolled user on a hidden course.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('not enrolled', $e->getMessage());
        }
    }

    /**
     * A hidden activity is excluded for a student while the visible structure
     * of the course is still returned.
     */
    public function test_course_contents_excludes_hidden_activity_for_student(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $visiblepage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Open page',
        ]);
        $hiddenpage = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Hidden page', 'visible' => 0,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $output = moodle_course_contents::execute(['course_id' => (int) $course->id], $student);

        $cmids = [];
        foreach ($output['sections'] as $section) {
            foreach ($section['modules'] as $module) {
                $cmids[] = $module['cmid'];
            }
        }
        $this->assertContains((int) $visiblepage->cmid, $cmids);
        $this->assertNotContains((int) $hiddenpage->cmid, $cmids,
            'A hidden activity must not be listed for a student.');
        $this->assertSame(2, $output['total_modules']);
        $this->assertSame(1, $output['filtered_modules']);
        $this->assertNotEmpty($output['sections'], 'Visible section structure must survive.');
    }

    /**
     * A hidden page module is not readable by an enrolled student.
     */
    public function test_get_resource_rejects_hidden_page_for_student(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'visible' => 0,
            'content' => '<p>Unreleased solution sheet</p>',
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        try {
            moodle_get_resource::execute(['cmid' => (int) $page->cmid], $student);
            $this->fail('Expected tool_exception for a hidden page module.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('permission', $e->getMessage());
        }
    }

    /**
     * A module in a hidden course the user is not enrolled in must not be
     * readable. If this fails, the tool leaks course content across the
     * enrolment / course-visibility boundary.
     */
    public function test_get_resource_rejects_module_in_inaccessible_course(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course(['visible' => 0]);
        $page = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id,
            'content' => '<p>SECRET-FOREIGN-CONTENT-9981</p>',
        ]);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            $output = moodle_get_resource::execute(['cmid' => (int) $page->cmid], $stranger);
            $this->fail('SECURITY LEAK: moodle_get_resource returned content of a module in a '
                . 'hidden course the user is not enrolled in: "' . ($output['content'] ?? '') . '"');
        } catch (tool_exception $e) {
            // The course-access gate rejects before any content is resolved.
            $this->assertStringContainsString('not visible to you', $e->getMessage());
        }
    }

    /**
     * A hidden course never shows up in the catalogue search for a student.
     */
    public function test_search_courses_hides_hidden_course_from_student(): void {
        $this->resetAfterTest(true);

        $hidden = $this->getDataGenerator()->create_course([
            'fullname' => 'Zebraquux Secret Pilot', 'shortname' => 'zebraquux1', 'visible' => 0,
        ]);
        $student = $this->getDataGenerator()->create_user();
        $this->setUser($student);

        $output = moodle_search_courses::execute(['query' => 'Zebraquux'], $student);

        $ids = array_column($output['courses'], 'id');
        $this->assertNotContains((int) $hidden->id, $ids,
            'A hidden course must not appear in search results for a student.');
        $this->assertSame(0, $output['total']);
    }

    /**
     * The same hidden course is visible to a site admin in the search.
     */
    public function test_search_courses_shows_hidden_course_to_admin(): void {
        $this->resetAfterTest(true);

        $hidden = $this->getDataGenerator()->create_course([
            'fullname' => 'Zebraquux Secret Pilot', 'shortname' => 'zebraquux1', 'visible' => 0,
        ]);
        $this->setAdminUser();
        $admin = get_admin();

        $output = moodle_search_courses::execute(['query' => 'Zebraquux'], $admin);

        $ids = array_column($output['courses'], 'id');
        $this->assertContains((int) $hidden->id, $ids);
    }

    /**
     * Assignment listing rejects a course_id the user is not enrolled in.
     */
    public function test_my_assignments_rejects_unenrolled_course_filter(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            moodle_my_assignments::execute(['course_id' => (int) $course->id], $stranger);
            $this->fail('Expected tool_exception for an unenrolled course filter.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('not enrolled', $e->getMessage());
        }
    }

    /**
     * A hidden assignment is excluded from a student's assignment list.
     */
    public function test_my_assignments_excludes_hidden_assignment(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $visible = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Open assignment',
        ]);
        $hidden = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Hidden assignment', 'visible' => 0,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $output = moodle_my_assignments::execute([], $student);

        $cmids = array_column($output['assignments'], 'cmid');
        $this->assertContains((int) $visible->cmid, $cmids);
        $this->assertNotContains((int) $hidden->cmid, $cmids,
            'A hidden assignment must not be listed for a student.');
        $this->assertSame(1, $output['total']);
    }

    /**
     * Grade overview rejects a course_id the user is not enrolled in.
     */
    public function test_my_grades_rejects_unenrolled_course_filter(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            moodle_my_grades::execute(['course_id' => (int) $course->id], $stranger);
            $this->fail('Expected tool_exception for an unenrolled course filter.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('not enrolled', $e->getMessage());
        }
    }

    /**
     * Announcements from courses the user is not enrolled in never appear,
     * and requesting a foreign course explicitly is rejected.
     */
    public function test_announcements_exclude_foreign_courses(): void {
        $this->resetAfterTest(true);

        $mycourse = $this->getDataGenerator()->create_course();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $myforum = $this->getDataGenerator()->create_module('forum', [
            'course' => $mycourse->id, 'type' => 'news',
        ]);
        $foreignforum = $this->getDataGenerator()->create_module('forum', [
            'course' => $foreigncourse->id, 'type' => 'news',
        ]);
        $teacher = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($teacher->id, $mycourse->id, 'editingteacher');
        $this->getDataGenerator()->enrol_user($teacher->id, $foreigncourse->id, 'editingteacher');

        /** @var \mod_forum_generator $fg */
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $fg->create_discussion((object) [
            'course' => $mycourse->id, 'forum' => $myforum->id, 'userid' => $teacher->id,
            'name' => 'Welcome to my course',
        ]);
        $fg->create_discussion((object) [
            'course' => $foreigncourse->id, 'forum' => $foreignforum->id, 'userid' => $teacher->id,
            'name' => 'Secret foreign announcement xyzzy',
        ]);

        $student = $this->getDataGenerator()->create_and_enrol($mycourse, 'student');
        $this->setUser($student);

        $output = moodle_get_announcements::execute([], $student);

        $subjects = array_column($output['announcements'], 'subject');
        $courseids = array_column($output['announcements'], 'course_id');
        $this->assertContains('Welcome to my course', $subjects);
        $this->assertNotContains('Secret foreign announcement xyzzy', $subjects,
            'Announcements of foreign courses must never appear.');
        $this->assertNotContains((int) $foreigncourse->id, $courseids);

        // Explicitly requesting the foreign course is rejected.
        $this->expectException(tool_exception::class);
        moodle_get_announcements::execute(['course_id' => (int) $foreigncourse->id], $student);
    }

    /**
     * A course calendar event in a foreign course does not appear in the
     * upcoming feed, while an event of an enrolled course does.
     */
    public function test_calendar_upcoming_excludes_foreign_course_event(): void {
        $this->resetAfterTest(true);

        $mycourse = $this->getDataGenerator()->create_course();
        $foreigncourse = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($mycourse, 'student');

        $this->setAdminUser();
        $this->getDataGenerator()->create_event([
            'eventtype' => 'course', 'courseid' => $mycourse->id,
            'name' => 'My course exam', 'timestart' => time() + HOURSECS,
        ]);
        $this->getDataGenerator()->create_event([
            'eventtype' => 'course', 'courseid' => $foreigncourse->id,
            'name' => 'Foreign secret meeting xyzzy', 'timestart' => time() + HOURSECS,
        ]);

        $this->setUser($student);
        $output = moodle_calendar_upcoming::execute(['days_ahead' => 7], $student);

        $names = array_column($output['events'], 'name');
        $this->assertContains('My course exam', $names);
        $this->assertNotContains('Foreign secret meeting xyzzy', $names,
            'Course events of foreign courses must never appear.');
    }

    /**
     * With site-wide messaging disabled (default), a user who shares no
     * course with the searcher is not returned; after sharing a course the
     * same user becomes findable.
     */
    public function test_find_user_respects_messaging_privacy_boundary(): void {
        $this->resetAfterTest(true);

        $searcher = $this->getDataGenerator()->create_user();
        $target = $this->getDataGenerator()->create_user([
            'firstname' => 'Zebrafinch', 'lastname' => 'Quokka',
        ]);
        $this->setUser($searcher);

        // No shared course: the privacy boundary hides the user entirely.
        $output = moodle_find_user::execute(['query' => 'Zebrafinch'], $searcher);
        $this->assertSame(0, $output['total_matches'],
            'A user sharing no course must not be findable when messagingallusers is off.');
        $this->assertSame([], $output['contacts']);
        $this->assertSame([], $output['noncontacts']);

        // Shared course: the same query now resolves the user.
        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($searcher->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($target->id, $course->id, 'student');

        $output = moodle_find_user::execute(['query' => 'Zebrafinch'], $searcher);
        $this->assertSame(1, $output['total_matches']);
        $this->assertSame((int) $target->id, $output['noncontacts'][0]['id']);
    }

    /**
     * Calling moodle_send_message without confirm performs no send at all.
     */
    public function test_send_message_without_confirm_sends_nothing(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $sender = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $recipient = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($sender);

        $before = $DB->count_records('messages');

        $output = moodle_send_message::execute([
            'to_user_id' => (int) $recipient->id,
            'message' => 'Hello, is this graded?',
        ], $sender);

        $this->assertFalse($output['sent']);
        $this->assertTrue($output['requires_confirmation']);
        $this->assertNull($output['message_id']);
        $this->assertNull($output['conversation_id']);
        $this->assertSame($before, $DB->count_records('messages'),
            'A preview call must not create any message rows.');
    }

    /**
     * Sending to a suspended or deleted recipient is rejected before any
     * message is created.
     */
    public function test_send_message_rejects_suspended_and_deleted_recipients(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $sender = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $suspended = $this->getDataGenerator()->create_user(['suspended' => 1]);
        $deleted = $this->getDataGenerator()->create_user();
        delete_user($deleted);
        $this->setUser($sender);

        try {
            moodle_send_message::execute([
                'to_user_id' => (int) $suspended->id,
                'message' => 'Hello?', 'confirm' => true,
            ], $sender);
            $this->fail('Expected tool_exception for a suspended recipient.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('suspended', $e->getMessage());
        }

        try {
            moodle_send_message::execute([
                'to_user_id' => (int) $deleted->id,
                'message' => 'Hello?', 'confirm' => true,
            ], $sender);
            $this->fail('Expected tool_exception for a deleted recipient.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('deleted', $e->getMessage());
        }

        $this->assertSame(0, $DB->count_records('messages'),
            'Rejected sends must not create any message rows.');
    }

    /**
     * The capabilities export stays empty for a user without
     * webservice/elediamcp:viewcaps.
     */
    public function test_verify_user_context_omits_capabilities_without_viewcaps(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $output = moodle_verify_user_context::execute(['include_capabilities' => true], $student);

        $this->assertTrue($output['valid']);
        $this->assertArrayHasKey('capabilities', $output);
        $this->assertSame([], $output['capabilities'],
            'Without webservice/elediamcp:viewcaps no capability list may be exported.');
    }
}
