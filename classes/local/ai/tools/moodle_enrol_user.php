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
use core_user;
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course enrolment tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_enrol_user implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_enrol_user';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Enrol a user in a course';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Enrols an existing Moodle user into an existing course through the manual enrolment '
            . 'method. This is a write tool and requires enrol/manual:enrol in the target course. '
            . 'Two-step flow: first call returns a preview; call again with confirm=true to enrol.';
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
            'required' => ['user_id', 'course_id'],
            'properties' => [
                'user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Existing Moodle user id to enrol.',
                ],
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Existing Moodle course id.',
                ],
                'role_shortname' => [
                    'type' => 'string',
                    'default' => 'student',
                    'description' => 'Role shortname to assign. Defaults to student.',
                ],
                'timestart' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional enrolment start timestamp. 0 starts immediately.',
                ],
                'timeend' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional enrolment end timestamp. 0 means no end date.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually enrol the user.',
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
            'required' => ['enrolled', 'requires_confirmation', 'summary'],
            'properties' => [
                'enrolled' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'enrolment' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'course_id' => ['type' => 'integer'],
                        'role_id' => ['type' => 'integer'],
                        'role_shortname' => ['type' => 'string'],
                        'course_url' => ['type' => 'string'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'user_id' => ['type' => 'integer'],
                        'user_fullname' => ['type' => 'string'],
                        'course_id' => ['type' => 'integer'],
                        'course_fullname' => ['type' => 'string'],
                        'role_shortname' => ['type' => 'string'],
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
        global $DB;

        $userid = (int) ($arguments['user_id'] ?? 0);
        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($userid <= 0) {
            throw new tool_exception('user_id is required.');
        }
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }

        $targetuser = core_user::get_user($userid, '*', MUST_EXIST);
        if (!empty($targetuser->deleted)) {
            throw new tool_exception('Cannot enrol a deleted user.', ['user_id' => $userid]);
        }

        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);
        require_capability('enrol/manual:enrol', $coursecontext, $user->id);

        $roleshortname = trim((string) ($arguments['role_shortname'] ?? 'student')) ?: 'student';
        $role = $DB->get_record('role', ['shortname' => $roleshortname], 'id,shortname,name', MUST_EXIST);

        if (self::course_has_enrolment($userid, $courseid)) {
            throw new tool_exception('User is already enrolled in this course.', [
                'user_id' => $userid,
                'course_id' => $courseid,
            ]);
        }

        $instance = self::manual_instance($courseid);
        if ($instance === null) {
            throw new tool_exception('Manual enrolment is not enabled for this course.', ['course_id' => $courseid]);
        }

        $preview = [
            'user_id' => $userid,
            'user_fullname' => fullname($targetuser),
            'course_id' => $courseid,
            'course_fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
            'role_shortname' => (string) $role->shortname,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'enrolled' => false,
                'requires_confirmation' => true,
                'enrolment' => null,
                'preview' => $preview,
                'summary' => 'Ready to enrol ' . fullname($targetuser) . ' into ' . $course->fullname
                    . ' as ' . $role->shortname . '. Call again with confirm=true to enrol.',
            ];
        }

        $plugin = enrol_get_plugin('manual');
        if ($plugin === null) {
            throw new tool_exception('Manual enrolment plugin is not available.');
        }

        $plugin->enrol_user(
            $instance,
            $userid,
            (int) $role->id,
            max(0, (int) ($arguments['timestart'] ?? 0)),
            max(0, (int) ($arguments['timeend'] ?? 0)),
            ENROL_USER_ACTIVE
        );

        return [
            'enrolled' => true,
            'requires_confirmation' => false,
            'enrolment' => [
                'user_id' => $userid,
                'course_id' => $courseid,
                'role_id' => (int) $role->id,
                'role_shortname' => (string) $role->shortname,
                'course_url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ],
            'preview' => $preview,
            'summary' => 'Enrolled ' . fullname($targetuser) . ' into ' . $course->fullname
                . ' as ' . $role->shortname . '.',
        ];
    }

    /**
     * Whether a user already has an enrolment record in the course.
     */
    private static function course_has_enrolment(int $userid, int $courseid): bool {
        global $DB;

        return $DB->record_exists_sql(
            "SELECT 1
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE ue.userid = :userid
                AND e.courseid = :courseid",
            ['userid' => $userid, 'courseid' => $courseid]
        );
    }

    /**
     * Return the enabled manual enrolment instance for a course.
     */
    private static function manual_instance(int $courseid): ?stdClass {
        foreach (enrol_get_instances($courseid, true) as $instance) {
            if ($instance->enrol === 'manual') {
                return $instance;
            }
        }
        return null;
    }
}
