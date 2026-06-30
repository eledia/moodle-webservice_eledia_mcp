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
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;

/**
 * AI-native teacher grading queue.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_grading_queue implements ai_tool {
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
        return 'moodle_grading_queue';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Assignments waiting for grading';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns assignments the authenticated teacher can grade, with counts of submitted '
            . 'attempts that appear to need grading. Use this to answer "what do I need to correct?" '
            . 'or to build a teacher daily briefing.';
    }

    /**
     * Input schema.
     *
     * @return array<string,mixed>
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
                'include_empty' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include gradeable assignments even when no submissions need grading.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
            ],
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string,mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['assignments', 'total_needing_grading', 'summary'],
            'properties' => [
                'assignments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['cmid', 'assign_id', 'name', 'course_id', 'needs_grading_count', 'url'],
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'assign_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'needs_grading_count' => ['type' => 'integer'],
                            'submitted_count' => ['type' => 'integer'],
                            'oldest_submission_iso' => ['type' => ['string', 'null']],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total_needing_grading' => ['type' => 'integer'],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Tool annotations.
     *
     * @return array<string,mixed>
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
     * @param array $arguments Tool arguments.
     * @param stdClass $user Authenticated user.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $DB;

        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $includeempty = !empty($arguments['include_empty']);
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;

        $courses = enrol_get_users_courses((int) $user->id, true, ['id', 'fullname']);
        if ($courseidfilter > 0) {
            $courses = isset($courses[$courseidfilter]) || is_siteadmin($user)
                ? [$courseidfilter => $courses[$courseidfilter] ?? get_course($courseidfilter)]
                : [];
        }
        if (empty($courses)) {
            return [
                'assignments' => [],
                'total_needing_grading' => 0,
                'summary' => 'No gradeable courses found for this user.',
            ];
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($courses), SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT a.id AS assignid, a.name, a.course AS courseid, cm.id AS cmid
                  FROM {assign} a
                  JOIN {course_modules} cm ON cm.instance = a.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                 WHERE a.course $insql
              ORDER BY a.course ASC, a.name ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $entries = [];
        $total = 0;
        foreach ($rows as $row) {
            $context = context_module::instance((int) $row->cmid);
            if (!has_capability('mod/assign:grade', $context, $user->id)) {
                continue;
            }
            $modinfo = get_fast_modinfo((int) $row->courseid, (int) $user->id);
            $cm = $modinfo->cms[(int) $row->cmid] ?? null;
            if (!$cm || (!$cm->uservisible && !has_capability('moodle/course:viewhiddenactivities', $context, $user->id))) {
                continue;
            }

            $stats = self::submission_stats((int) $row->assignid);
            if (!$includeempty && $stats['needs_grading_count'] === 0) {
                continue;
            }
            $total += $stats['needs_grading_count'];
            $entries[] = [
                'cmid' => (int) $row->cmid,
                'assign_id' => (int) $row->assignid,
                'name' => format_string((string) $row->name, true, ['context' => $context]),
                'course_id' => (int) $row->courseid,
                'course_name' => format_string((string) ($courses[(int) $row->courseid]->fullname ?? ''), true),
                'needs_grading_count' => $stats['needs_grading_count'],
                'submitted_count' => $stats['submitted_count'],
                'oldest_submission_iso' => $stats['oldest_submission'] ? date(DATE_ATOM, $stats['oldest_submission']) : null,
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => (int) $row->cmid, 'action' => 'grading']))->out(false),
            ];
        }

        usort($entries, static fn(array $a, array $b): int => $b['needs_grading_count'] <=> $a['needs_grading_count']);
        $entries = array_slice($entries, 0, $limit);

        return [
            'assignments' => $entries,
            'total_needing_grading' => $total,
            'summary' => $total === 0
                ? 'No submitted assignments appear to need grading.'
                : "{$total} submitted assignment(s) appear to need grading.",
        ];
    }

    /**
     * Count submissions for one assignment.
     *
     * @param int $assignmentid Assignment id.
     * @return array{needs_grading_count: int, submitted_count: int, oldest_submission: int|null}
     */
    private static function submission_stats(int $assignmentid): array {
        global $DB;

        $sql = "SELECT s.id, s.userid, s.timemodified,
                       g.timemodified AS gradetime, g.grade
                  FROM {assign_submission} s
             LEFT JOIN {assign_grades} g
                    ON g.assignment = s.assignment
                   AND g.userid = s.userid
                   AND g.attemptnumber = s.attemptnumber
                 WHERE s.assignment = :assignment
                   AND s.latest = 1
                   AND s.status = :submitted";
        $rows = $DB->get_records_sql($sql, ['assignment' => $assignmentid, 'submitted' => 'submitted']);

        $needs = 0;
        $oldest = null;
        foreach ($rows as $row) {
            $graded = $row->grade !== null
                && (float) $row->grade >= 0
                && (int) $row->gradetime >= (int) $row->timemodified;
            if (!$graded) {
                $needs++;
                $time = (int) $row->timemodified;
                $oldest = $oldest === null ? $time : min($oldest, $time);
            }
        }

        return [
            'needs_grading_count' => $needs,
            'submitted_count' => count($rows),
            'oldest_submission' => $oldest,
        ];
    }
}
