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

declare(strict_types=1);

namespace webservice_elediamcp\local\ai\tools;

use assign;
use context_module;
use core_user;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Teacher tool: read one student's assignment submission content.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_read_submission implements ai_tool {
    /** @var int Character cap per extracted text block. */
    private const TEXT_CAP = 8000;

    /** @var int Byte cap for readable file extraction. */
    private const FILE_BYTE_CAP = 102400;

    /** @var string[] File extensions treated as readable text. */
    private const TEXT_EXTENSIONS = ['txt', 'md', 'csv', 'html', 'htm', 'json', 'xml'];

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_read_submission';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Read a student submission';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the content of one student\'s assignment submission for grading: submission '
            . 'status and timing, the online text, and attached files (plain-text file types are '
            . 'returned inline, others as metadata only). Also includes the current grade if any. '
            . 'Requires mod/assign:grade on the assignment. Use moodle_grading_queue to find '
            . 'cmid/user_id pairs that need grading, then this tool to read, then '
            . 'moodle_grade_submission to grade.';
    }

    /**
     * Return the JSON schema for accepted arguments.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['cmid', 'user_id'],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id of the assignment.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The student whose submission to read.',
                ],
            ],
        ];
    }

    /**
     * Return the JSON schema for tool output.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['assignment', 'student', 'submission', 'summary'],
            'properties' => [
                'assignment' => [
                    'type' => 'object',
                    'properties' => [
                        'cmid' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'duedate' => ['type' => 'integer'],
                        'max_grade' => ['type' => 'number'],
                    ],
                ],
                'student' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'fullname' => ['type' => 'string'],
                    ],
                ],
                'submission' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'status' => ['type' => 'string'],
                        'submitted_at' => ['type' => 'integer'],
                        'late' => ['type' => 'boolean'],
                        'attempt' => ['type' => 'integer'],
                    ],
                ],
                'online_text' => ['type' => ['string', 'null']],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'size' => ['type' => 'integer'],
                            'mimetype' => ['type' => 'string'],
                            'text_content' => ['type' => ['string', 'null'],
                                'description' => 'Inline content for readable text files, else null.'],
                        ],
                    ],
                ],
                'current_grade' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'grade' => ['type' => 'number'],
                        'graded_at' => ['type' => 'integer'],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Return MCP annotations for this tool.
     *
     * @return array<string, mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * Execute the tool for the authenticated user.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $user Authenticated Moodle user.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG, $DB;

        $cmid = (int) ($arguments['cmid'] ?? 0);
        $studentid = (int) ($arguments['user_id'] ?? 0);
        if ($cmid <= 0 || $studentid <= 0) {
            throw new tool_exception('cmid and user_id are required.');
        }

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cm->course);
        $context = context_module::instance($cmid);
        require_capability('mod/assign:grade', $context, $user->id);
        $student = core_user::get_user($studentid, '*', MUST_EXIST);

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new assign($context, $cm, $course);
        $instance = $assign->get_instance();

        $submission = $assign->get_user_submission($studentid, false);
        $duedate = (int) $instance->duedate;

        $submissiondata = null;
        $onlinetext = null;
        $files = [];
        if ($submission) {
            $submittedat = (int) $submission->timemodified;
            $submissiondata = [
                'status' => (string) $submission->status,
                'submitted_at' => $submittedat,
                'late' => $duedate > 0 && $submittedat > $duedate,
                'attempt' => (int) $submission->attemptnumber,
            ];

            $textrecord = $DB->get_record('assignsubmission_onlinetext', ['submission' => $submission->id]);
            if ($textrecord && trim((string) $textrecord->onlinetext) !== '') {
                $onlinetext = shorten_text(html_to_text((string) $textrecord->onlinetext, 0, false), self::TEXT_CAP);
            }

            $storedfiles = get_file_storage()->get_area_files(
                $context->id,
                'assignsubmission_file',
                'submission_files',
                $submission->id,
                'filename',
                false
            );
            foreach ($storedfiles as $file) {
                $files[] = [
                    'filename' => $file->get_filename(),
                    'size' => (int) $file->get_filesize(),
                    'mimetype' => (string) $file->get_mimetype(),
                    'text_content' => self::extract_text($file),
                ];
            }
        }

        $graderecord = $assign->get_user_grade($studentid, false);
        $currentgrade = null;
        if ($graderecord && $graderecord->grade !== null && (float) $graderecord->grade >= 0) {
            $currentgrade = [
                'grade' => (float) $graderecord->grade,
                'graded_at' => (int) $graderecord->timemodified,
            ];
        }

        $statusword = $submissiondata !== null ? $submissiondata['status'] : 'none';
        return [
            'assignment' => [
                'cmid' => $cmid,
                'name' => format_string($instance->name, true, ['context' => $context]),
                'duedate' => $duedate,
                'max_grade' => (float) $instance->grade,
            ],
            'student' => [
                'id' => $studentid,
                'fullname' => fullname($student),
            ],
            'submission' => $submissiondata,
            'online_text' => $onlinetext,
            'files' => $files,
            'current_grade' => $currentgrade,
            'summary' => 'Submission of ' . fullname($student) . ': status ' . $statusword
                . ($onlinetext !== null ? ', online text included' : '')
                . (count($files) > 0 ? ', ' . count($files) . ' file(s)' : '')
                . ($currentgrade !== null ? ', already graded ' . $currentgrade['grade'] : ', not graded yet')
                . '.',
        ];
    }

    /**
     * Extract inline text from readable submission files.
     *
     * @param \stored_file $file Stored file.
     * @return string|null
     */
    private static function extract_text(\stored_file $file): ?string {
        if ($file->get_filesize() > self::FILE_BYTE_CAP) {
            return null;
        }
        $extension = strtolower(pathinfo($file->get_filename(), PATHINFO_EXTENSION));
        $mimetype = (string) $file->get_mimetype();
        if (!in_array($extension, self::TEXT_EXTENSIONS, true) && strpos($mimetype, 'text/') !== 0) {
            return null;
        }
        $content = (string) $file->get_content();
        if (in_array($extension, ['html', 'htm'], true)) {
            $content = html_to_text($content, 0, false);
        }
        return shorten_text($content, self::TEXT_CAP);
    }
}
