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
 * Teacher tool: create, rename or hide/show course sections.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_manage_sections implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_manage_sections';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Manage course sections';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Creates, renames or hides/shows a course section. Actions: create (appends a new '
            . 'section, optional name), rename (section + name), set_visibility (section + visible). '
            . 'Requires moodle/course:update. Two-step flow: first call returns a preview; call '
            . 'again with confirm=true. Use moodle_course_contents to inspect the current sections.';
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
            'required' => ['course_id', 'action'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'action' => [
                    'type' => 'string',
                    'enum' => ['create', 'rename', 'set_visibility'],
                ],
                'section' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Section number for rename/set_visibility (section 0 cannot be managed).',
                ],
                'name' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'New section name for create/rename.',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'description' => 'For set_visibility.',
                ],
                'confirm' => ['type' => 'boolean', 'default' => false],
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
            'required' => ['done', 'requires_confirmation', 'summary'],
            'properties' => [
                'done' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'section' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'number' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'action' => ['type' => 'string'],
                        'course_fullname' => ['type' => 'string'],
                        'detail' => ['type' => 'string'],
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
        global $CFG;

        $courseid = (int) ($arguments['course_id'] ?? 0);
        $action = trim((string) ($arguments['action'] ?? ''));
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        if (!in_array($action, ['create', 'rename', 'set_visibility'], true)) {
            throw new tool_exception('Invalid action. Allowed: create, rename, set_visibility.');
        }
        if ($courseid === SITEID) {
            throw new tool_exception('Sections of the site home cannot be managed.');
        }

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        require_capability('moodle/course:update', $context, $user->id);
        require_once($CFG->dirroot . '/course/lib.php');

        $name = trim((string) ($arguments['name'] ?? ''));
        $visible = array_key_exists('visible', $arguments) ? (bool) $arguments['visible'] : null;
        $sectionnum = max(0, (int) ($arguments['section'] ?? 0));
        $modinfo = get_fast_modinfo($course);
        $coursename = format_string($course->fullname, true, ['context' => $context]);

        $sectioninfo = null;
        if ($action !== 'create') {
            if ($sectionnum <= 0) {
                throw new tool_exception('section (>= 1) is required for ' . $action . '.');
            }
            $sectioninfo = $modinfo->get_section_info($sectionnum);
            if ($sectioninfo === null) {
                throw new tool_exception('Section ' . $sectionnum . ' does not exist in this course.');
            }
        }
        if ($action === 'rename' && $name === '') {
            throw new tool_exception('name is required for rename.');
        }
        if ($action === 'set_visibility' && $visible === null) {
            throw new tool_exception('visible is required for set_visibility.');
        }

        $detail = match ($action) {
            'create' => 'Append a new section' . ($name !== '' ? ' named "' . $name . '"' : '') . '.',
            'rename' => 'Rename section ' . $sectionnum . ' ("'
                . get_section_name($course, $sectioninfo) . '") to "' . $name . '".',
            'set_visibility' => ($visible ? 'Show' : 'Hide') . ' section ' . $sectionnum . ' ("'
                . get_section_name($course, $sectioninfo) . '").',
        };

        if (empty($arguments['confirm'])) {
            return [
                'done' => false,
                'requires_confirmation' => true,
                'section' => null,
                'preview' => ['action' => $action, 'course_fullname' => $coursename, 'detail' => $detail],
                'summary' => $detail . ' Call again with confirm=true to apply.',
            ];
        }

        try {
            switch ($action) {
                case 'create':
                    $created = course_create_section($course);
                    $sectionnum = (int) $created->section;
                    if ($name !== '') {
                        course_update_section($course, $created, ['name' => $name]);
                    }
                    break;
                case 'rename':
                    course_update_section($course, $sectioninfo, ['name' => $name]);
                    break;
                case 'set_visibility':
                    set_section_visible($courseid, $sectionnum, $visible ? 1 : 0);
                    break;
            }
        } catch (Throwable $ex) {
            throw new tool_exception('Section operation failed: ' . $ex->getMessage());
        }

        $freshinfo = get_fast_modinfo($courseid)->get_section_info($sectionnum);
        $courseurl = (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);

        return [
            'done' => true,
            'requires_confirmation' => false,
            'section' => [
                'number' => $sectionnum,
                'name' => get_section_name($course, $freshinfo),
                'visible' => (bool) $freshinfo->visible,
            ],
            'preview' => ['action' => $action, 'course_fullname' => $coursename, 'detail' => $detail],
            'summary' => 'Done: ' . $detail . ' Link: ' . $courseurl . ' — share this link with the user.',
        ];
    }
}
