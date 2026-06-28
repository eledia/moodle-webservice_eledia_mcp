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
use webservice_elediamcp\local\ai\tools\moodle_my_submission_files;
use webservice_elediamcp\local\ai\tools\moodle_search_content;

/**
 * Tests for the content tools added in 1.0.0.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_search_content
 * @covers      \webservice_elediamcp\local\ai\tools\moodle_my_submission_files
 */
final class content_tools_test extends advanced_testcase {
    /**
     * With global search disabled the fallback finds visible activities by
     * name, skips hidden ones, and is scoped to enrolled courses.
     */
    public function test_search_content_fallback(): void {
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Photosynthesis basics',
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $course->id, 'name' => 'Hidden photosynthesis extras', 'visible' => 0,
        ]);
        $othercourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_module('page', [
            'course' => $othercourse->id, 'name' => 'Photosynthesis elsewhere',
        ]);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $output = moodle_search_content::execute(['query' => 'photosynthesis'], $student);

        $this->assertSame('fallback', $output['engine']);
        $titles = array_column($output['results'], 'title');
        $this->assertContains('Photosynthesis basics', $titles);
        // Hidden activity and unenrolled course never surface.
        $this->assertNotContains('Hidden photosynthesis extras', $titles);
        $this->assertNotContains('Photosynthesis elsewhere', $titles);
        $this->assertNotEmpty($output['results'][0]['cmid']);
    }

    /**
     * Search rejects too-short queries and foreign course restrictions.
     */
    public function test_search_content_negative_cases(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();
        $this->setUser($stranger);

        try {
            moodle_search_content::execute(['query' => 'x'], $stranger);
            $this->fail('Expected tool_exception for a too-short query.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('2 characters', $e->getMessage());
        }

        $this->expectException(tool_exception::class);
        moodle_search_content::execute(
            ['query' => 'anything', 'course_id' => (int) $course->id],
            $stranger
        );
    }

    /**
     * The submission reader returns the user's own files and online text.
     */
    public function test_my_submission_files_own_submission(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'name' => 'Essay 1',
            'assignsubmission_onlinetext_enabled' => 1,
            'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 5,
            'assignsubmission_file_maxsizebytes' => 1048576,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $submission = (object) [
            'assignment' => $assign->id, 'userid' => $student->id, 'attemptnumber' => 0,
            'latest' => 1, 'status' => 'submitted',
            'timecreated' => time() - 100, 'timemodified' => time() - 50,
        ];
        $submission->id = $DB->insert_record('assign_submission', $submission);
        $DB->insert_record('assignsubmission_onlinetext', (object) [
            'assignment' => $assign->id, 'submission' => $submission->id,
            'onlinetext' => '<p>My essay about <b>plants</b>.</p>', 'onlineformat' => FORMAT_HTML,
        ]);
        $fs = get_file_storage();
        $fs->create_file_from_string([
            'contextid' => \context_module::instance($assign->cmid)->id,
            'component' => 'assignsubmission_file', 'filearea' => 'submission_files',
            'itemid' => $submission->id, 'filepath' => '/', 'filename' => 'essay.pdf',
        ], '%PDF-fake');

        $output = moodle_my_submission_files::execute(['cmid' => (int) $assign->cmid], $student);

        $this->assertTrue($output['has_submission']);
        $this->assertSame('submitted', $output['status']);
        $this->assertSame(1, $output['attempt']);
        $this->assertCount(1, $output['files']);
        $this->assertSame('essay.pdf', $output['files'][0]['filename']);
        $this->assertStringContainsStringIgnoringCase('plants', $output['onlinetext']);
        $this->assertSame('Essay 1', $output['assignment_name']);
    }

    /**
     * Self-scoping: another user's submission never surfaces, hidden
     * assignments are rejected, unknown cmids are rejected.
     */
    public function test_my_submission_files_negative_cases(): void {
        global $DB;
        $this->resetAfterTest(true);

        $course = $this->getDataGenerator()->create_course();
        $assign = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Only the OTHER student has submitted.
        $DB->insert_record('assign_submission', (object) [
            'assignment' => $assign->id, 'userid' => $other->id, 'attemptnumber' => 0,
            'latest' => 1, 'status' => 'submitted',
            'timecreated' => time(), 'timemodified' => time(),
        ]);

        $this->setUser($student);
        $output = moodle_my_submission_files::execute(['cmid' => (int) $assign->cmid], $student);
        $this->assertFalse($output['has_submission']);
        $this->assertSame([], $output['files']);

        // Hidden assignment: invisible to students.
        $hidden = $this->getDataGenerator()->create_module('assign', [
            'course' => $course->id, 'visible' => 0,
        ]);
        try {
            moodle_my_submission_files::execute(['cmid' => (int) $hidden->cmid], $student);
            $this->fail('Expected tool_exception for hidden assignment.');
        } catch (tool_exception $e) {
            $this->assertStringContainsString('visible', $e->getMessage());
        }

        $this->expectException(tool_exception::class);
        moodle_my_submission_files::execute(['cmid' => 999999], $student);
    }
}
