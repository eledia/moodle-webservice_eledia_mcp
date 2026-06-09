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
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native upcoming-calendar probe.
 *
 * Returns time-sorted events (assignment deadlines, quiz windows, custom
 * calendar entries) for the next N days, scoped to the user's enrolled
 * courses and groups. The natural-language summary is designed to drop
 * directly into an LLM system prompt for tutor / agent personas.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_calendar_upcoming implements ai_tool {
    /** @var int Default lookahead window. */
    private const DEFAULT_DAYS = 14;

    /** @var int Maximum lookahead window. */
    private const MAX_DAYS = 90;

    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 50;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_calendar_upcoming';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Upcoming Moodle calendar events';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns calendar events visible to the authenticated user within the next '
            . 'days_ahead days (default 14, max 90), sorted by start time. Includes assignment '
            . 'deadlines, quiz windows, group, course and site events. Restrict to a single '
            . 'course with course_id. Use this to answer "what is due soon?" and "what is '
            . 'happening this week?".';
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
                'days_ahead' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DAYS,
                    'default' => self::DEFAULT_DAYS,
                    'description' => 'Look-ahead window in days (default 14, max 90).',
                ],
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Restrict to events for a single course id.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
                'include_descriptions' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include a short plain-text excerpt of each event description.',
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
            'required' => ['events', 'from', 'to', 'summary'],
            'properties' => [
                'events' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'name', 'event_type', 'start_iso', 'url'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'event_type' => ['type' => 'string'],
                            'module' => ['type' => 'string'],
                            'instance' => ['type' => 'integer'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'start_iso' => ['type' => 'string'],
                            'end_iso' => ['type' => ['string', 'null']],
                            'duration_seconds' => ['type' => 'integer'],
                            'url' => ['type' => 'string'],
                            'description_excerpt' => ['type' => 'string'],
                        ],
                    ],
                ],
                'from' => ['type' => 'string'],
                'to' => ['type' => 'string'],
                'total' => ['type' => 'integer'],
                'truncated' => ['type' => 'boolean'],
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
        global $CFG;

        $userid = (int) $user->id;
        $days = isset($arguments['days_ahead'])
            ? max(1, min(self::MAX_DAYS, (int) $arguments['days_ahead']))
            : self::DEFAULT_DAYS;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $includedesc = !empty($arguments['include_descriptions']);

        $tstart = time();
        $tend = $tstart + ($days * DAYSECS);

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'calendar_%d_%d_%d_%d_%d',
            $userid,
            $days,
            $courseidfilter,
            $limit,
            $includedesc ? 1 : 0
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        require_once($CFG->dirroot . '/calendar/lib.php');

        $usercourses = enrol_get_users_courses($userid, true, ['id', 'shortname', 'fullname']);
        $courseids = array_map(static fn($c) => (int) $c->id, array_values($usercourses));
        if ($courseidfilter > 0) {
            if (!in_array($courseidfilter, $courseids, true) && !is_siteadmin($user)) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseidfilter}.",
                    ['course_id' => $courseidfilter]
                );
            }
            $courseids = [$courseidfilter];
        }

        // Build groups list across the selected courses.
        $groupids = [];
        foreach ($courseids as $cid) {
            if (function_exists('groups_get_user_groups')) {
                $grouping = groups_get_user_groups($cid, $userid);
                foreach ($grouping as $gids) {
                    foreach ((array) $gids as $gid) {
                        $groupids[(int) $gid] = true;
                    }
                }
            }
        }

        $users = [$userid];
        $groupsparam = !empty($groupids) ? array_keys($groupids) : false;
        $coursesparam = !empty($courseids) ? $courseids : false;

        try {
            $events = calendar_get_legacy_events(
                $tstart,
                $tend,
                $users,
                $groupsparam,
                $coursesparam,
                true,
                true
            );
        } catch (Throwable $ex) {
            throw new tool_exception(
                'Failed to load calendar events: ' . $ex->getMessage(),
                ['days_ahead' => $days, 'course_id' => $courseidfilter]
            );
        }

        $coursenames = [];
        foreach ($usercourses as $c) {
            $coursenames[(int) $c->id] = (string) $c->fullname;
        }

        // Sort by start time ascending then trim.
        usort($events, static function ($a, $b): int {
            return ((int) $a->timestart) <=> ((int) $b->timestart);
        });
        $total = count($events);
        $events = array_slice($events, 0, $limit);

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];

        foreach ($events as $e) {
            $eventcourseid = (int) ($e->courseid ?? 0);
            $coursename = $eventcourseid > 0 && isset($coursenames[$eventcourseid])
                ? $coursenames[$eventcourseid] : '';
            $module = (string) ($e->modulename ?? '');
            $instance = (int) ($e->instance ?? 0);
            $start = (int) $e->timestart;
            $duration = (int) ($e->timeduration ?? 0);

            $url = self::event_url($module, $instance, $eventcourseid, (int) $e->id);

            $entry = [
                'id' => (int) $e->id,
                'name' => format_string((string) $e->name, true, $stringopts),
                'event_type' => (string) ($e->eventtype ?? 'user'),
                'module' => $module,
                'instance' => $instance,
                'course_id' => $eventcourseid,
                'course_name' => $coursename !== '' ? format_string($coursename, true, $stringopts) : '',
                'start_iso' => gmdate('c', $start),
                'end_iso' => $duration > 0 ? gmdate('c', $start + $duration) : null,
                'duration_seconds' => $duration,
                'url' => $url,
            ];
            if ($includedesc && !empty($e->description)) {
                $entry['description_excerpt'] = self::excerpt(
                    (string) $e->description,
                    (int) ($e->format ?? FORMAT_HTML)
                );
            }
            $entries[] = $entry;
        }

        $payload = [
            'events' => $entries,
            'from' => gmdate('c', $tstart),
            'to' => gmdate('c', $tend),
            'total' => $total,
            'truncated' => $total > $limit,
            'summary' => self::build_summary($total, $days, $courseidfilter, $coursenames),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_calendar_upcoming cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Resolve the most useful URL for a calendar event.
     *
     * @param string $module Module shortname (e.g. assign, quiz) or empty.
     * @param int $instance Module instance id (e.g. assign id) or 0.
     * @param int $courseid Course id or 0.
     * @param int $eventid Calendar event id (fallback).
     * @return string
     */
    private static function event_url(string $module, int $instance, int $courseid, int $eventid): string {
        if ($module !== '' && $instance > 0) {
            return (new moodle_url("/mod/{$module}/view.php", ['id' => $instance]))->out(false);
        }
        if ($courseid > 0) {
            return (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        }
        return (new moodle_url('/calendar/view.php', ['view' => 'day', 'event' => $eventid]))->out(false);
    }

    /**
     * Build a short plain-text excerpt of an event description.
     *
     * @param string $content Raw description.
     * @param int $format Text format.
     * @return string
     */
    private static function excerpt(string $content, int $format): string {
        $opts = ['context' => context_system::instance(), 'filter' => false, 'noclean' => true];
        $formatted = format_text($content, $format, $opts);
        $plain = trim(html_to_text($formatted, 0, false));
        if ($plain === '') {
            return '';
        }
        if (strlen($plain) > 200) {
            $plain = substr($plain, 0, 197) . '...';
        }
        return $plain;
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Total events found in the window.
     * @param int $days Window size in days.
     * @param int $courseidfilter Course filter (0 = none).
     * @param array<int, string> $coursenames Map of course id => fullname.
     * @return string
     */
    private static function build_summary(int $total, int $days, int $courseidfilter, array $coursenames): string {
        $courselabel = '';
        if ($courseidfilter > 0) {
            $courselabel = isset($coursenames[$courseidfilter])
                ? sprintf(' in "%s"', $coursenames[$courseidfilter])
                : sprintf(' in course %d', $courseidfilter);
        }
        if ($total === 0) {
            return sprintf('No calendar events%s in the next %d days.', $courselabel, $days);
        }
        $word = $total === 1 ? 'event' : 'events';
        return sprintf('You have %d %s%s in the next %d days.', $total, $word, $courselabel, $days);
    }
}
