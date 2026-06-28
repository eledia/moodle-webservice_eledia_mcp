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

use context_coursecat;
use core_course_category;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course creation tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_create_course implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_create_course';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Create a Moodle course';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Creates a Moodle course in a course category. This is a write tool and '
            . 'requires moodle/course:create in the target category. Two-step flow: first '
            . 'call returns a preview; call again with confirm=true to create. Shortname '
            . 'and optional idnumber must be unique.';
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
            'required' => ['fullname', 'shortname'],
            'properties' => [
                'fullname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 254,
                ],
                'shortname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 255,
                    'description' => 'Unique course shortname.',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Target category id. If omitted, the default category is used.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'Optional plain-text course summary.',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'idnumber' => [
                    'type' => 'string',
                    'maxLength' => 100,
                ],
                'startdate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Optional Unix timestamp. Defaults to today.',
                ],
                'enddate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'description' => 'Optional Unix timestamp. Use 0 for no end date.',
                ],
                'format' => [
                    'type' => 'string',
                    'default' => 'topics',
                    'description' => 'Course format, e.g. topics or weeks.',
                ],
                'numsections' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 52,
                    'default' => 4,
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually create the course.',
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
            'required' => ['created', 'requires_confirmation', 'summary'],
            'properties' => [
                'created' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'course' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'shortname' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'category_id' => ['type' => 'integer'],
                        'visible' => ['type' => 'boolean'],
                        'url' => ['type' => 'string'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'shortname' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'category_id' => ['type' => 'integer'],
                        'category_name' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
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
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
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

        $fullname = trim((string) ($arguments['fullname'] ?? ''));
        $shortname = trim((string) ($arguments['shortname'] ?? ''));
        if ($fullname === '') {
            throw new tool_exception('fullname is required.');
        }
        if ($shortname === '') {
            throw new tool_exception('shortname is required.');
        }

        $categoryid = isset($arguments['category_id'])
            ? max(1, (int) $arguments['category_id'])
            : (int) core_course_category::get_default()->id;
        $category = core_course_category::get($categoryid, MUST_EXIST, true);
        $categorycontext = context_coursecat::instance($categoryid);
        require_capability('moodle/course:create', $categorycontext, $user->id);

        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            throw new tool_exception('shortname is already in use.', ['shortname' => $shortname]);
        }
        $idnumber = trim((string) ($arguments['idnumber'] ?? ''));
        if ($idnumber !== '' && $DB->record_exists('course', ['idnumber' => $idnumber])) {
            throw new tool_exception('idnumber is already in use.', ['idnumber' => $idnumber]);
        }

        $visible = array_key_exists('visible', $arguments) ? (bool) $arguments['visible'] : true;
        $format = trim((string) ($arguments['format'] ?? 'topics')) ?: 'topics';
        $numsections = isset($arguments['numsections']) ? max(1, min(52, (int) $arguments['numsections'])) : 4;

        $record = (object) [
            'fullname' => $fullname,
            'shortname' => $shortname,
            'category' => $categoryid,
            'summary' => trim((string) ($arguments['summary'] ?? '')),
            'summaryformat' => FORMAT_PLAIN,
            'visible' => $visible ? 1 : 0,
            'format' => $format,
            'numsections' => $numsections,
            'startdate' => isset($arguments['startdate']) ? max(0, (int) $arguments['startdate']) : time(),
            'enddate' => isset($arguments['enddate']) ? max(0, (int) $arguments['enddate']) : 0,
        ];
        if ($idnumber !== '') {
            $record->idnumber = $idnumber;
        }

        $preview = [
            'shortname' => $shortname,
            'fullname' => $fullname,
            'category_id' => $categoryid,
            'category_name' => $category->get_formatted_name(),
            'visible' => $visible,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'course' => null,
                'preview' => $preview,
                'summary' => 'Ready to create Moodle course ' . $shortname . '. Call again with confirm=true to create.',
            ];
        }

        require_once($CFG->dirroot . '/course/lib.php');
        try {
            $course = create_course($record);
        } catch (Throwable $ex) {
            throw new tool_exception('Course creation failed: ' . $ex->getMessage(), $preview);
        }

        return [
            'created' => true,
            'requires_confirmation' => false,
            'course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => (string) $course->fullname,
                'category_id' => (int) $course->category,
                'visible' => (bool) $course->visible,
                'url' => (new moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false),
            ],
            'preview' => $preview,
            'summary' => 'Created Moodle course ' . $course->fullname . ' (' . $course->shortname . ').',
        ];
    }
}
