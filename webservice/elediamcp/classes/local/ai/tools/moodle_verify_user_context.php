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

use cache;
use context_course;
use context_system;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\event\context_verified;
use webservice_elediamcp\local\ai\ai_tool;

/**
 * AI-native user context verification.
 *
 * Designed as the bootstrap probe for AI tutors and agents: in a single
 * round-trip the agent learns who the user is, which courses they may access,
 * which roles they hold, and an LLM-friendly natural-language summary.
 *
 * The full capability tree is intentionally NOT included by default. It can
 * be requested through include_capabilities, which additionally requires the
 * webservice/elediamcp:viewcaps capability.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_verify_user_context implements ai_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_verify_user_context';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Verify Moodle user context';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Verifies the MCP token and returns the authenticated user, the courses they are '
            . 'enrolled in, their role in each course, optional group memberships, and a short '
            . 'natural-language summary suitable for an LLM system prompt. Use this as the first '
            . 'call in any tutor or agent conversation. Restrict to a single course by passing '
            . 'course_id. Capability information is only returned when include_capabilities is '
            . 'true and the caller has webservice/elediamcp:viewcaps.';
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
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'description' => 'Optional Moodle course id to restrict the response to.',
                ],
                'include_roles' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include role shortnames per course (default true).',
                ],
                'include_groups' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include the user\'s group memberships per course (default true).',
                ],
                'include_capabilities' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include the user\'s capability set. Requires webservice/elediamcp:viewcaps.',
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
            'required' => ['valid', 'user', 'summary'],
            'properties' => [
                'valid' => ['type' => 'boolean'],
                'checked_at' => ['type' => 'string', 'format' => 'date-time'],
                'user' => [
                    'type' => 'object',
                    'required' => ['id', 'username', 'fullname'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'username' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'lang' => ['type' => 'string'],
                        'timezone' => ['type' => 'string'],
                        'profile_url' => ['type' => 'string'],
                    ],
                ],
                'site' => [
                    'type' => 'object',
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'moodle_version' => ['type' => 'string'],
                    ],
                ],
                'is_admin' => ['type' => 'boolean'],
                'courses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'shortname' => ['type' => 'string'],
                            'fullname' => ['type' => 'string'],
                            'visible' => ['type' => 'boolean'],
                            'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'groups' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'start_date' => ['type' => ['string', 'null']],
                            'end_date' => ['type' => ['string', 'null']],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'capabilities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Only present when include_capabilities is true.',
                ],
                'summary' => [
                    'type' => 'string',
                    'description' => 'One-sentence natural-language summary for the LLM.',
                ],
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
        global $CFG, $SITE;

        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : null;
        $includeroles = !array_key_exists('include_roles', $arguments) || (bool) $arguments['include_roles'];
        $includegroups = !array_key_exists('include_groups', $arguments) || (bool) $arguments['include_groups'];
        $includecaps = !empty($arguments['include_capabilities']);

        $cachekey = sprintf(
            'verify_%d_%s_%d_%d_%d',
            (int) $user->id,
            $courseidfilter !== null ? (string) $courseidfilter : 'all',
            $includeroles ? 1 : 0,
            $includegroups ? 1 : 0,
            $includecaps ? 1 : 0
        );
        $cache = cache::make('webservice_elediamcp', 'verify_context');
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $isadmin = is_siteadmin($user);
        $userid = (int) $user->id;
        $usercourses = enrol_get_users_courses($userid, true, ['id', 'shortname', 'fullname', 'visible',
            'startdate', 'enddate']);

        if ($courseidfilter !== null) {
            $usercourses = array_filter($usercourses, static fn($c) => (int) $c->id === $courseidfilter);
        }

        global $DB;

        $courses = [];
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        foreach ($usercourses as $c) {
            $courseid = (int) $c->id;
            try {
                $coursecontext = context_course::instance($courseid);
            } catch (Throwable $ex) {
                // Skip stale enrolment rows that no longer have a course context.
                continue;
            }

            $roleshortnames = [];
            if ($includeroles) {
                $roles = get_user_roles($coursecontext, $userid, true);
                foreach ($roles as $r) {
                    $roleshortnames[] = $r->shortname;
                }
                $roleshortnames = array_values(array_unique($roleshortnames));
            }

            $groupnames = [];
            if ($includegroups && function_exists('groups_get_user_groups')) {
                $groupsbygrouping = groups_get_user_groups($courseid, $userid);
                $allgroupids = [];
                foreach ($groupsbygrouping as $gids) {
                    foreach ((array) $gids as $gid) {
                        $allgroupids[(int) $gid] = true;
                    }
                }
                if (!empty($allgroupids)) {
                    // Single batched DB lookup replaces N x groups_get_group() round-trips.
                    $records = $DB->get_records_list('groups', 'id', array_keys($allgroupids), '', 'id, name');
                    foreach ($records as $record) {
                        $groupnames[] = (string) $record->name;
                    }
                }
            }

            $courses[] = [
                'id' => $courseid,
                'shortname' => (string) $c->shortname,
                'fullname' => format_string((string) $c->fullname, true, $stringopts),
                'visible' => (bool) $c->visible,
                'roles' => $roleshortnames,
                'groups' => $groupnames,
                'start_date' => self::format_timestamp((int) ($c->startdate ?? 0)),
                'end_date' => self::format_timestamp((int) ($c->enddate ?? 0)),
                'url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ];
        }

        $payload = [
            'valid' => true,
            'checked_at' => gmdate('c'),
            'user' => [
                'id' => (int) $user->id,
                'username' => (string) $user->username,
                'fullname' => fullname($user),
                'email' => (string) ($user->email ?? ''),
                'lang' => (string) ($user->lang ?: ($CFG->lang ?? 'en')),
                'timezone' => (string) \core_date::get_user_timezone($user),
                'profile_url' => (new moodle_url('/user/profile.php', ['id' => $user->id]))->out(false),
            ],
            'site' => [
                'name' => format_string(
                    (string) ($SITE->fullname ?? $SITE->shortname ?? 'Moodle'),
                    true,
                    $stringopts
                ),
                'url' => (string) $CFG->wwwroot,
                'moodle_version' => (string) ($CFG->release ?? ''),
            ],
            'is_admin' => $isadmin,
            'courses' => $courses,
            'summary' => self::build_summary($user, $courses, $isadmin),
        ];

        if ($includecaps) {
            $payload['capabilities'] = self::collect_capabilities($user, $isadmin);
        }

        // Emit audit event (separate from the generic tool_invoked event).
        try {
            $event = context_verified::create([
                'context' => context_system::instance(),
                'userid' => $userid,
                'other' => [
                    'coursefilter' => $courseidfilter !== null ? (string) $courseidfilter : 'all',
                ],
            ]);
            $event->trigger();
        } catch (Throwable $ex) {
            // Auditing must never break the response.
            debugging('MCP context_verified event failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }

        $cache->set($cachekey, $payload);
        return $payload;
    }

    /**
     * Format a unix timestamp as ISO-8601 or null when unset/zero.
     *
     * @param int|null $timestamp Unix timestamp.
     * @return string|null
     */
    private static function format_timestamp(?int $timestamp): ?string {
        if (empty($timestamp)) {
            return null;
        }
        return gmdate('c', $timestamp);
    }

    /**
     * Build a natural-language summary suitable for an LLM system prompt.
     *
     * @param stdClass $user Authenticated user.
     * @param array<int, array<string, mixed>> $courses Course list (already filtered).
     * @param bool $isadmin Whether the user is a site administrator.
     * @return string
     */
    private static function build_summary(stdClass $user, array $courses, bool $isadmin): string {
        $name = fullname($user);
        $coursecount = count($courses);

        if ($coursecount === 0) {
            $base = $isadmin
                ? "You are authenticated as {$name}, a Moodle site administrator with no active enrolments."
                : "You are authenticated as {$name}. There are no active course enrolments visible to MCP.";
            return $base;
        }

        $rolepool = [];
        foreach ($courses as $c) {
            foreach ($c['roles'] as $r) {
                $rolepool[$r] = true;
            }
        }
        $roles = array_keys($rolepool);
        $rolelabel = empty($roles) ? '' : sprintf(' (roles: %s)', implode(', ', $roles));
        $adminlabel = $isadmin ? ', site administrator' : '';
        $countlabel = $coursecount === 1 ? '1 active course' : ($coursecount . ' active courses');
        return sprintf('You are authenticated as %s%s with access to %s%s.',
            $name, $adminlabel, $countlabel, $rolelabel);
    }

    /**
     * Collect a bounded list of capability shortnames for the user.
     *
     * Admin sees a marker; non-admin needs webservice/elediamcp:viewcaps to receive
     * an explicit list, and the list is restricted to the system context to
     * avoid an O(courses x capabilities) payload.
     *
     * @param stdClass $user Authenticated user.
     * @param bool $isadmin Whether the user is a site administrator.
     * @return string[]
     */
    private static function collect_capabilities(stdClass $user, bool $isadmin): array {
        $syscontext = context_system::instance();
        $userid = (int) $user->id;
        if (!$isadmin && !has_capability('webservice/elediamcp:viewcaps', $syscontext, $userid)) {
            return [];
        }
        if ($isadmin) {
            return ['*']; // Marker: all capabilities.
        }
        // Return only a small, high-signal capability set at the system context.
        $signalcaps = [
            'moodle/site:config', 'moodle/course:create', 'moodle/course:update',
            'moodle/course:view', 'moodle/grade:edit', 'moodle/user:create',
        ];
        $granted = [];
        foreach ($signalcaps as $cap) {
            if (has_capability($cap, $syscontext, $userid)) {
                $granted[] = $cap;
            }
        }
        return $granted;
    }
}
