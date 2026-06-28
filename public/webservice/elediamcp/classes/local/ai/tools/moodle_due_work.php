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

use stdClass;
use webservice_elediamcp\local\ai\ai_tool;

/**
 * AI-native learner due-work briefing.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_due_work implements ai_tool {
    /** @var int Default lookahead window. */
    private const DEFAULT_DAYS = 14;

    /** @var int Maximum lookahead window. */
    private const MAX_DAYS = 90;

    /** @var int Default number of items. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Maximum number of items. */
    private const MAX_LIMIT = 50;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_due_work';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Due and overdue Moodle work';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns a prioritised learner to-do list combining overdue assignments, upcoming '
            . 'assignment due dates and upcoming calendar events visible to the authenticated user. '
            . 'Use this to answer "what do I need to do?", "what is overdue?" and "what is due soon?".';
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
                    'minimum' => 1,
                    'description' => 'Restrict to a single course id.',
                ],
                'days_ahead' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DAYS,
                    'default' => self::DEFAULT_DAYS,
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
                'include_calendar' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include upcoming calendar events in addition to assignment due dates.',
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
            'required' => ['items', 'summary'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'title', 'priority', 'url'],
                        'properties' => [
                            'type' => ['type' => 'string'],
                            'title' => ['type' => 'string'],
                            'course_id' => ['type' => ['integer', 'null']],
                            'course_name' => ['type' => 'string'],
                            'due_iso' => ['type' => ['string', 'null']],
                            'priority' => ['type' => 'string', 'enum' => ['overdue', 'soon', 'upcoming', 'info']],
                            'status' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'overdue_count' => ['type' => 'integer'],
                'due_soon_count' => ['type' => 'integer'],
                'calendar_count' => ['type' => 'integer'],
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
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $user Authenticated user.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        $days = isset($arguments['days_ahead'])
            ? max(1, min(self::MAX_DAYS, (int) $arguments['days_ahead']))
            : self::DEFAULT_DAYS;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $courseid = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $includecalendar = !array_key_exists('include_calendar', $arguments) || !empty($arguments['include_calendar']);

        $assignmentargs = ['status' => 'all', 'limit' => self::MAX_LIMIT, 'offset' => 0];
        if ($courseid > 0) {
            $assignmentargs['course_id'] = $courseid;
        }
        $assignments = moodle_my_assignments::execute($assignmentargs, $user);

        $items = [];
        $now = time();
        $cutoff = $now + ($days * DAYSECS);
        foreach ($assignments['assignments'] as $assignment) {
            if (($assignment['status'] ?? '') !== 'notsubmitted') {
                continue;
            }
            $duets = self::parse_iso($assignment['duedate_iso'] ?? null);
            if ($duets === null || $duets > $cutoff) {
                continue;
            }
            $priority = !empty($assignment['overdue']) ? 'overdue' : (($duets - $now) <= DAYSECS * 3 ? 'soon' : 'upcoming');
            $items[] = [
                'type' => 'assignment',
                'title' => (string) $assignment['name'],
                'course_id' => (int) $assignment['course_id'],
                'course_name' => (string) ($assignment['course_name'] ?? ''),
                'due_iso' => $assignment['duedate_iso'],
                'priority' => $priority,
                'status' => (string) $assignment['status'],
                'url' => (string) $assignment['url'],
            ];
        }

        $calendarcount = 0;
        if ($includecalendar) {
            $calendarargs = ['days_ahead' => $days, 'limit' => $limit, 'include_descriptions' => false];
            if ($courseid > 0) {
                $calendarargs['course_id'] = $courseid;
            }
            $calendar = moodle_calendar_upcoming::execute($calendarargs, $user);
            foreach ($calendar['events'] as $event) {
                $calendarcount++;
                $items[] = [
                    'type' => 'calendar',
                    'title' => (string) $event['name'],
                    'course_id' => isset($event['course_id']) ? (int) $event['course_id'] : null,
                    'course_name' => (string) ($event['course_name'] ?? ''),
                    'due_iso' => $event['start_iso'] ?? null,
                    'priority' => 'info',
                    'status' => (string) ($event['event_type'] ?? 'event'),
                    'url' => (string) ($event['url'] ?? ''),
                ];
            }
        }

        usort($items, static function (array $a, array $b): int {
            $rank = ['overdue' => 0, 'soon' => 1, 'upcoming' => 2, 'info' => 3];
            $rankdiff = ($rank[$a['priority']] ?? 9) <=> ($rank[$b['priority']] ?? 9);
            if ($rankdiff !== 0) {
                return $rankdiff;
            }
            return (self::parse_iso($a['due_iso'] ?? null) ?? PHP_INT_MAX)
                <=> (self::parse_iso($b['due_iso'] ?? null) ?? PHP_INT_MAX);
        });

        $items = array_slice($items, 0, $limit);
        $overdue = count(array_filter($items, static fn(array $item): bool => $item['priority'] === 'overdue'));
        $soon = count(array_filter($items, static function (array $item): bool {
            return in_array($item['priority'], ['soon', 'upcoming'], true);
        }));

        return [
            'items' => $items,
            'overdue_count' => $overdue,
            'due_soon_count' => $soon,
            'calendar_count' => $calendarcount,
            'summary' => self::summary($overdue, $soon, $calendarcount),
        ];
    }

    /**
     * Parse an ISO-ish date string.
     *
     * @param string|null $value Date string.
     * @return int|null Timestamp.
     */
    private static function parse_iso(?string $value): ?int {
        if (empty($value)) {
            return null;
        }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }

    /**
     * Build a compact summary.
     *
     * @param int $overdue Overdue count.
     * @param int $soon Due-soon count.
     * @param int $calendar Calendar count.
     * @return string
     */
    private static function summary(int $overdue, int $soon, int $calendar): string {
        if ($overdue === 0 && $soon === 0 && $calendar === 0) {
            return 'No due work or upcoming events were found in the selected window.';
        }
        return "{$overdue} overdue item(s), {$soon} due item(s), {$calendar} calendar event(s) found.";
    }
}
