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

use PHPUnit\Framework\Attributes\CoversClass;
use advanced_testcase;
use completion_info;
use webservice_elediamcp\local\ai\tool_exception;
use webservice_elediamcp\local\ai\tools\moodle_due_work;
use webservice_elediamcp\local\ai\tools\moodle_forum_discussions;
use webservice_elediamcp\local\ai\tools\moodle_grading_queue;
use webservice_elediamcp\local\ai\tools\moodle_my_progress;
use webservice_elediamcp\local\ai\tools\moodle_quiz_info;
use webservice_elediamcp\local\ai\tools\moodle_unanswered_forum_posts;

/**
 * Tests for the learner-progress AI tools added in 0.9.0, including
 * permission-denial (negative) cases.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_my_progress::class)]
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_quiz_info::class)]
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_forum_discussions::class)]
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_due_work::class)]
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_grading_queue::class)]
#[CoversClass(\webservice_elediamcp\local\ai\tools\moodle_unanswered_forum_posts::class)]
final class learner_tools_test extends advanced_testcase {
    /**
     * Progress reports percentage and per-activity states for a tracked course.
     */
    public function test_my_progress_tracked_course(): void {
        global $CFG;
        $this->resetAfterTest(true);
        $CFG->enablecompletion = 1;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $page1 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $page2 = $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        // Complete one of the two tracked activities.
        $completion = new completion_info(get_course($course->id));
        $cm1 = get_fast_modinfo($course->id, (int) $user->id)->cms[$page1->cmid];
        $completion->update_state($cm1, COMPLETION_COMPLETE, (int) $user->id);

        $output = moodle_my_progress::execute(
            ['course_id' => (int) $course->id, 'include_activities' => true],
            $user
        );

        $this->assertCount(1, $output['courses']);
        $entry = $output['courses'][0];
        $this->assertTrue($entry['completion_enabled']);
        $this->assertSame(1, $entry['completed_count']);
        $this->assertSame(2, $entry['total_count']);
        $this->assertEqualsWithDelta(50.0, $entry['progress_percent'], 0.01);

        $states = array_column($output['activities'], 'state', 'cmid');
        $this->assertSame('complete', $states[(int) $page1->cmid]);
        $this->assertSame('notcompleted', $states[(int) $page2->cmid]);
        $this->assertNotEmpty($output['summary']);
    }

    /**
     * Progress rejects courses the user is not enrolled in and bad arguments.
     */
    public function test_my_progress_negative_cases(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            moodle_my_progress::execute(['course_id' => (int) $course->id], $stranger);
            $this->fail('Expected tool_exception for unenrolled course.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('not enrolled', $e->getMessage());
        }

        $this->expectException(tool_exception::class);
        moodle_my_progress::execute(['include_activities' => true], $stranger);
    }

    /**
     * Quiz info lists quizzes with the user's own attempts and grade.
     */
    public function test_quiz_info_with_own_attempts(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'grade' => 10, 'sumgrades' => 2,
            'attempts' => 3, 'timelimit' => 600,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($other->id, $course->id, 'student');
        $this->setUser($user);

        // One finished attempt of the user's own (raw rows: 1.0 of 2 => grade 5/10),
        // plus an attempt by someone else that must never surface.
        $now = time();
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id, 'userid' => $user->id, 'attempt' => 1, 'uniqueid' => 990001,
            'layout' => '1,0', 'currentpage' => 0, 'preview' => 0, 'state' => 'finished',
            'timestart' => $now - 500, 'timefinish' => $now - 200, 'timemodified' => $now,
            'timemodifiedoffline' => 0, 'sumgrades' => 1.0,
        ]);
        $DB->insert_record('quiz_attempts', (object) [
            'quiz' => $quiz->id, 'userid' => $other->id, 'attempt' => 1, 'uniqueid' => 990002,
            'layout' => '1,0', 'currentpage' => 0, 'preview' => 0, 'state' => 'finished',
            'timestart' => $now - 400, 'timefinish' => $now - 100, 'timemodified' => $now,
            'timemodifiedoffline' => 0, 'sumgrades' => 2.0,
        ]);
        $DB->insert_record('quiz_grades', (object) [
            'quiz' => $quiz->id, 'userid' => $user->id, 'grade' => 5.0, 'timemodified' => $now,
        ]);

        $output = moodle_quiz_info::execute(['cmid' => (int) $quiz->cmid], $user);

        $this->assertSame(1, $output['total']);
        $entry = $output['quizzes'][0];
        $this->assertSame((int) $quiz->cmid, $entry['cmid']);
        $this->assertSame(3, $entry['attempts_allowed']);
        $this->assertSame(600, $entry['timelimit_seconds']);
        $this->assertSame(1, $entry['my_attempt_count']);
        $this->assertEqualsWithDelta(5.0, $entry['my_grade'], 0.01);

        // Only the user's own attempt appears, scaled to the quiz grade.
        $this->assertCount(1, $output['attempts']);
        $attempt = $output['attempts'][0];
        $this->assertSame('finished', $attempt['state']);
        $this->assertSame(300, $attempt['duration_seconds']);
        $this->assertEqualsWithDelta(5.0, $attempt['grade'], 0.01);
    }

    /**
     * A hidden quiz is invisible to students, and foreign courses are rejected.
     */
    public function test_quiz_info_negative_cases(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $quiz = $this->getDataGenerator()->create_module('quiz', [
            'course' => $course->id, 'visible' => 0,
        ]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        $this->setUser($user);

        try {
            moodle_quiz_info::execute(['cmid' => (int) $quiz->cmid], $user);
            $this->fail('Expected tool_exception for hidden quiz.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('visible', $e->getMessage());
        }

        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $this->expectException(tool_exception::class);
        moodle_quiz_info::execute(['course_id' => (int) $course->id], $stranger);
    }

    /**
     * Forum discussions and posts are readable by an enrolled student.
     */
    public function test_forum_discussions_and_posts(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        /** @var \mod_forum_generator $fg */
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $fg->create_discussion((object) [
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher->id,
            'name' => 'Week 1 questions',
        ]);
        $fg->create_post((object) [
            'discussion' => $discussion->id, 'userid' => $student->id,
            'parent' => $discussion->firstpost, 'subject' => 'Re: Week 1 questions',
            'message' => '<p>I have a question about <b>task two</b>.</p>',
        ]);

        $this->setUser($student);

        $list = moodle_forum_discussions::execute(['course_id' => (int) $course->id], $student);
        $this->assertSame(1, $list['total']);
        $this->assertSame('Week 1 questions', $list['discussions'][0]['subject']);
        $this->assertSame(1, $list['discussions'][0]['reply_count']);
        $this->assertNotEmpty($list['forums']);

        $posts = moodle_forum_discussions::execute(
            ['discussion_id' => (int) $discussion->id],
            $student
        );
        $this->assertSame(2, $posts['total']);
        $reply = $posts['posts'][1];
        $this->assertSame('Re: Week 1 questions', $reply['subject']);
        $this->assertStringContainsStringIgnoringCase('task two', $reply['message_text']);
        $this->assertStringNotContainsString('<b>', $reply['message_text']);
        $this->assertTrue($reply['is_mine']);
    }

    /**
     * Separate-groups forums hide other groups' discussions, and unenrolled
     * users are rejected.
     */
    public function test_forum_discussions_respects_groups(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $DB->set_field('course_modules', 'groupmode', SEPARATEGROUPS, ['id' => $forum->cmid]);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);

        /** @var \mod_forum_generator $fg */
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $fg->create_discussion((object) [
            'course' => $course->id, 'forum' => $forum->id, 'userid' => $teacher->id,
            'groupid' => $group->id, 'name' => 'Group-only thread',
        ]);

        // The student is not in the group: the discussion must be invisible.
        $this->setUser($student);
        $list = moodle_forum_discussions::execute(['cmid' => (int) $forum->cmid], $student);
        $this->assertSame(0, $list['total']);

        $posts = null;
        try {
            $posts = moodle_forum_discussions::execute(
                ['discussion_id' => (int) $discussion->id],
                $student
            );
        } catch (tool_exception $e) {
            $this->assertStringContainsString('not visible', $e->getMessage());
        }
        $this->assertNull($posts, 'Group-restricted posts must not be readable.');

        // Unenrolled user: course listing rejected.
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);
        $this->expectException(tool_exception::class);
        moodle_forum_discussions::execute(['course_id' => (int) $course->id], $stranger);
    }

    /**
     * Due work combines visible assignment due dates into a learner briefing.
     */
    public function test_due_work_lists_due_assignment(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Reflection task',
            'duedate' => time() + DAYSECS,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $output = moodle_due_work::execute([
            'course_id' => (int) $course->id,
            'days_ahead' => 3,
            'include_calendar' => false,
        ], $student);

        $this->assertNotEmpty($output['items']);
        $this->assertSame('assignment', $output['items'][0]['type']);
        $this->assertSame('Reflection task', $output['items'][0]['title']);
        $this->assertSame((int) $assign->course, $output['items'][0]['course_id']);
        $this->assertGreaterThanOrEqual(1, $output['due_soon_count']);
    }

    /**
     * The teacher grading queue reports submitted assignments that still need grading.
     */
    public function test_grading_queue_lists_submitted_assignments(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
            'name' => 'Essay to grade',
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $now = time();
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id,
            'userid' => $student->id,
            'attemptnumber' => 0,
            'latest' => 1,
            'status' => 'submitted',
            'timecreated' => $now - 100,
            'timemodified' => $now - 50,
        ]);

        $this->setUser($teacher);
        $output = moodle_grading_queue::execute(['course_id' => (int) $course->id], $teacher);

        $this->assertSame(1, $output['total_needing_grading']);
        $this->assertCount(1, $output['assignments']);
        $this->assertSame('Essay to grade', $output['assignments'][0]['name']);
        $this->assertSame(1, $output['assignments'][0]['needs_grading_count']);
    }

    /**
     * Teachers can ask for visible forum discussions that have no replies.
     */
    public function test_unanswered_forum_posts_lists_open_discussions(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $forum = $this->getDataGenerator()->create_module('forum', ['course' => $course->id]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        /** @var \mod_forum_generator $fg */
        $fg = $this->getDataGenerator()->get_plugin_generator('mod_forum');
        $discussion = $fg->create_discussion((object) [
            'course' => $course->id,
            'forum' => $forum->id,
            'userid' => $student->id,
            'name' => 'Question without answer',
        ]);

        $this->setUser($teacher);
        $output = moodle_unanswered_forum_posts::execute(['course_id' => (int) $course->id], $teacher);

        $this->assertSame(1, $output['total']);
        $this->assertSame((int) $discussion->id, $output['discussions'][0]['discussion_id']);
        $this->assertSame('Question without answer', $output['discussions'][0]['subject']);
    }
}
