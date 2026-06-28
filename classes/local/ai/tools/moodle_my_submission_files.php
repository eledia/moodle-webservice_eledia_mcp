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

use context_module;
use context_system;
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native reader for the user's OWN assignment submission.
 *
 * For one assignment (cmid) it returns the authenticated user's latest
 * submission: the attached files (name, size, type) and the full text of an
 * online-text submission, plus submission status and timestamps. Strictly
 * self-scoped — it can never read another user's submission, regardless of
 * the caller's roles. Designed for "give me feedback on what I submitted"
 * and "did my upload go through?" queries.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_my_submission_files implements ai_tool {
    /** @var int Maximum characters of online-text content returned. */
    private const TEXT_LENGTH = 20000;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_my_submission_files';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'My assignment submission (files and text)';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the authenticated user\'s OWN latest submission for one assignment '
            . '(cmid from moodle_my_assignments): attached files with name, size and mime type, '
            . 'the full plain text of an online-text submission, and the submission status with '
            . 'timestamps. Strictly self-scoped — never returns another user\'s submission. Use '
            . 'for "give me feedback on what I submitted" and "did my upload go through?".';
    }

    /**
     * Input schema.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['cmid'],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id of the assignment.',
                ],
            ],
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['has_submission', 'summary'],
            'properties' => [
                'has_submission' => ['type' => 'boolean'],
                'status' => ['type' => ['string', 'null'],
                    'description' => 'Raw submission status (new, draft, submitted, reopened).'],
                'submitted_iso' => ['type' => ['string', 'null']],
                'attempt' => ['type' => ['integer', 'null']],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['filename', 'size_bytes', 'mimetype'],
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'size_bytes' => ['type' => 'integer'],
                            'mimetype' => ['type' => 'string'],
                            'modified_iso' => ['type' => 'string'],
                            'url' => ['type' => 'string',
                                'description' => 'Browser download URL (requires a Moodle session).'],
                        ],
                    ],
                ],
                'onlinetext' => ['type' => ['string', 'null'],
                    'description' => 'Plain text of an online-text submission, if any.'],
                'assignment_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Tool annotations.
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
     * Execute the tool.
     *
     * @param array<string, mixed> $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $DB;

        $cmid = (int) ($arguments['cmid'] ?? 0);
        $userid = (int) $user->id;

        try {
            [$course, $cminfo] = get_course_and_cm_from_cmid($cmid, 'assign', 0, $userid);
        } catch (\moodle_exception $e) {
            throw new tool_exception(
                "No assignment with cmid {$cmid} is visible to you.",
                ['cmid' => $cmid]
            );
        }
        // Course-access gate before module visibility: uservisible does not
        // check course enrolment/visibility (see moodle_get_resource).
        if (!is_siteadmin($user) && !can_access_course($course, $user)) {
            throw new tool_exception(
                "No assignment with cmid {$cmid} is visible to you.",
                ['cmid' => $cmid]
            );
        }
        if (!$cminfo->uservisible) {
            throw new tool_exception(
                "No assignment with cmid {$cmid} is visible to you.",
                ['cmid' => $cmid]
            );
        }

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $assignname = format_string((string) $cminfo->name, true, $stringopts);
        $assignurl = (new moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false);

        // Strictly the calling user's own latest submission.
        $submission = $DB->get_record('assign_submission', [
            'assignment' => (int) $cminfo->instance,
            'userid' => $userid,
            'latest' => 1,
        ], '*', IGNORE_MISSING);

        if (!$submission || (string) $submission->status === 'new') {
            return [
                'has_submission' => false,
                'status' => $submission ? (string) $submission->status : null,
                'submitted_iso' => null,
                'attempt' => null,
                'files' => [],
                'onlinetext' => null,
                'assignment_name' => $assignname,
                'url' => $assignurl,
                'summary' => sprintf('You have not submitted anything for "%s" yet.', $assignname),
            ];
        }

        $context = context_module::instance($cmid);
        $fs = get_file_storage();
        $files = [];
        foreach (
            $fs->get_area_files(
                $context->id,
                'assignsubmission_file',
                'submission_files',
                (int) $submission->id,
                'filename',
                false
            ) as $file
        ) {
            $files[] = [
                'filename' => $file->get_filename(),
                'size_bytes' => (int) $file->get_filesize(),
                'mimetype' => (string) $file->get_mimetype(),
                'modified_iso' => gmdate('c', (int) $file->get_timemodified()),
                'url' => moodle_url::make_pluginfile_url(
                    $context->id,
                    'assignsubmission_file',
                    'submission_files',
                    (int) $submission->id,
                    $file->get_filepath(),
                    $file->get_filename()
                )->out(false),
            ];
        }

        $onlinetext = null;
        $textrow = $DB->get_record('assignsubmission_onlinetext', [
            'assignment' => (int) $cminfo->instance,
            'submission' => (int) $submission->id,
        ], '*', IGNORE_MISSING);
        if ($textrow && trim((string) $textrow->onlinetext) !== '') {
            $onlinetext = content_to_text(
                (string) $textrow->onlinetext,
                (int) ($textrow->onlineformat ?? FORMAT_HTML)
            );
            if (\core_text::strlen($onlinetext) > self::TEXT_LENGTH) {
                $onlinetext = \core_text::substr($onlinetext, 0, self::TEXT_LENGTH) . ' …';
            }
        }

        $submittedat = (int) ($submission->timemodified ?? 0);
        return [
            'has_submission' => true,
            'status' => (string) $submission->status,
            'submitted_iso' => $submittedat > 0 ? gmdate('c', $submittedat) : null,
            'attempt' => (int) $submission->attemptnumber + 1,
            'files' => $files,
            'onlinetext' => $onlinetext,
            'assignment_name' => $assignname,
            'url' => $assignurl,
            'summary' => sprintf(
                '"%s": status %s with %d file(s)%s.',
                $assignname,
                (string) $submission->status,
                count($files),
                $onlinetext !== null ? ' and an online-text submission' : ''
            ),
        ];
    }
}
