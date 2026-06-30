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

use context_course;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course update tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_update_course implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_update_course';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Update a Moodle course';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Updates an existing Moodle course title, shortname, visibility, summary, start date '
            . 'or end date. This is a write tool and requires moodle/course:update in the target course. '
            . 'Changing visibility also requires moodle/course:visibility. Two-step flow: first call '
            . 'returns a preview; call again with confirm=true to update.';
    }

    /**
     * Return the JSON schema for tool input.
     *
     * @return array<string,mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['course_id'],
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Existing Moodle course id.',
                ],
                'fullname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 254,
                    'description' => 'Optional new course full name.',
                ],
                'shortname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'description' => 'Optional new unique course shortname.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional new plain-text course summary.',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'Optional course visibility.',
                ],
                'startdate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Optional Unix timestamp for the course start date.',
                ],
                'enddate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Optional Unix timestamp for the course end date. Use 0 for no end date.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually update the course.',
                ],
            ],
        ];
    }

    /**
     * Return the JSON schema for tool output.
     *
     * @return array<string,mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['updated', 'requires_confirmation', 'summary'],
            'properties' => [
                'updated' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'course' => [
                    'type' => ['object', 'null'],
                    'properties' => self::course_schema_properties(),
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'course_id' => ['type' => 'integer'],
                        'changes' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'field' => ['type' => 'string'],
                                    'from' => ['type' => ['string', 'integer', 'boolean', 'null']],
                                    'to' => ['type' => ['string', 'integer', 'boolean', 'null']],
                                ],
                            ],
                        ],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Return MCP tool annotations.
     *
     * @return array<string,mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ];
    }

    /**
     * Execute the course update.
     *
     * @param array $arguments Tool arguments.
     * @param stdClass $user Authenticated Moodle user.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG, $DB;

        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        require_capability('moodle/course:update', $context, $user->id);

        $updates = ['id' => $courseid];
        $changes = [];

        if (array_key_exists('fullname', $arguments)) {
            $fullname = trim((string) $arguments['fullname']);
            if ($fullname === '') {
                throw new tool_exception('fullname cannot be empty.');
            }
            self::add_change($updates, $changes, 'fullname', (string) $course->fullname, $fullname);
        }

        if (array_key_exists('shortname', $arguments)) {
            $shortname = trim((string) $arguments['shortname']);
            if ($shortname === '') {
                throw new tool_exception('shortname cannot be empty.');
            }
            if (
                $shortname !== (string) $course->shortname
                    && $DB->record_exists('course', ['shortname' => $shortname])
            ) {
                throw new tool_exception('shortname is already in use.', ['shortname' => $shortname]);
            }
            self::add_change($updates, $changes, 'shortname', (string) $course->shortname, $shortname);
        }

        if (array_key_exists('summary', $arguments)) {
            self::add_change($updates, $changes, 'summary', (string) $course->summary, trim((string) $arguments['summary']));
            $updates['summaryformat'] = FORMAT_PLAIN;
        }

        if (array_key_exists('visible', $arguments)) {
            $visible = (bool) $arguments['visible'];
            if ($visible !== (bool) $course->visible) {
                require_capability('moodle/course:visibility', $context, $user->id);
            }
            self::add_change($updates, $changes, 'visible', (bool) $course->visible, $visible);
        }

        if (array_key_exists('startdate', $arguments)) {
            self::add_change(
                $updates,
                $changes,
                'startdate',
                (int) $course->startdate,
                max(0, (int) $arguments['startdate'])
            );
        }

        if (array_key_exists('enddate', $arguments)) {
            self::add_change(
                $updates,
                $changes,
                'enddate',
                (int) $course->enddate,
                max(0, (int) $arguments['enddate'])
            );
        }

        if (empty($changes)) {
            throw new tool_exception('No course changes were requested.', ['course_id' => $courseid]);
        }

        $preview = [
            'course_id' => $courseid,
            'changes' => $changes,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'updated' => false,
                'requires_confirmation' => true,
                'course' => null,
                'preview' => $preview,
                'summary' => 'Ready to update Moodle course ' . $course->shortname
                    . '. Call again with confirm=true to update.',
            ];
        }

        require_once($CFG->dirroot . '/course/lib.php');
        try {
            update_course((object) $updates);
        } catch (Throwable $ex) {
            throw new tool_exception('Course update failed: ' . $ex->getMessage(), $preview);
        }

        $updated = get_course($courseid);

        return [
            'updated' => true,
            'requires_confirmation' => false,
            'course' => self::course_output($updated),
            'preview' => $preview,
            'summary' => 'Updated Moodle course ' . $updated->fullname . ' (' . $updated->shortname . ').',
        ];
    }

    /**
     * Course output schema properties.
     *
     * @return array<string, array<string,string>>
     */
    private static function course_schema_properties(): array {
        return [
            'id' => ['type' => 'integer'],
            'shortname' => ['type' => 'string'],
            'fullname' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'visible' => ['type' => 'boolean'],
            'startdate' => ['type' => 'integer'],
            'enddate' => ['type' => 'integer'],
            'url' => ['type' => 'string'],
        ];
    }

    /**
     * Add a changed field to the update record and preview list.
     *
     * @param array $updates Update record.
     * @param array $changes Preview changes.
     * @param string $field Field name.
     * @param mixed $from Current value.
     * @param mixed $to New value.
     */
    private static function add_change(array &$updates, array &$changes, string $field, $from, $to): void {
        if ($from === $to) {
            return;
        }
        $updates[$field] = $to;
        $changes[] = [
            'field' => $field,
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * Format a course record for the tool response.
     *
     * @param stdClass $course Course record.
     * @return array<string,mixed>
     */
    private static function course_output(stdClass $course): array {
        return [
            'id' => (int) $course->id,
            'shortname' => (string) $course->shortname,
            'fullname' => (string) $course->fullname,
            'summary' => (string) $course->summary,
            'visible' => (bool) $course->visible,
            'startdate' => (int) $course->startdate,
            'enddate' => (int) $course->enddate,
            'url' => (new moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false),
        ];
    }
}
