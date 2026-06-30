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

use context_system;
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native quiz overview with the user's own attempt history.
 *
 * Lists quizzes visible to the authenticated user across their enrolled
 * courses with timing, attempt limits and the user's own best grade; for a
 * single quiz (cmid) it additionally returns the user's attempt history.
 * Only ever exposes the authenticated user's own attempts and grades.
 * Designed for "how did my quiz go?", "which quizzes are still open?" and
 * revision-planning queries.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_quiz_info implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 30;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 100;

    /** @var array<int,string> Quiz grade-method constants to labels. */
    private const GRADE_METHODS = [
        1 => 'highest',
        2 => 'average',
        3 => 'first',
        4 => 'last',
    ];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_quiz_info';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Quizzes and my attempt history';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns quizzes visible to the authenticated user across their enrolled courses, '
            . 'each with open/close dates, time limit, allowed attempts, grading method and the '
            . 'user\'s own attempt count and best grade. Pass cmid for a single quiz to also get '
            . 'the user\'s attempt history (state, started/finished, duration, grade per attempt). '
            . 'Never exposes other users\' attempts. Use to answer "which quizzes are open?", '
            . '"how did my last attempt go?" and to plan revision.';
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
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id of one quiz: returns its detail incl. my attempts.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
                'offset' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
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
            'required' => ['quizzes', 'total', 'summary'],
            'properties' => [
                'quizzes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['cmid', 'quiz_id', 'name', 'course_id', 'url'],
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'quiz_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'timeopen_iso' => ['type' => ['string', 'null']],
                            'timeclose_iso' => ['type' => ['string', 'null']],
                            'is_open_now' => ['type' => 'boolean'],
                            'timelimit_seconds' => ['type' => ['integer', 'null']],
                            'attempts_allowed' => ['type' => 'integer',
                                'description' => '0 = unlimited'],
                            'grade_method' => ['type' => 'string',
                                'enum' => array_values(self::GRADE_METHODS)],
                            'my_attempt_count' => ['type' => 'integer'],
                            'my_grade' => ['type' => ['number', 'null']],
                            'grade_max' => ['type' => ['number', 'null']],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'attempts' => [
                    'type' => 'array',
                    'description' => 'The user\'s own attempts; only when cmid was given.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['attempt', 'state'],
                        'properties' => [
                            'attempt' => ['type' => 'integer'],
                            'state' => ['type' => 'string'],
                            'started_iso' => ['type' => ['string', 'null']],
                            'finished_iso' => ['type' => ['string', 'null']],
                            'duration_seconds' => ['type' => ['integer', 'null']],
                            'grade' => ['type' => ['number', 'null']],
                            'grade_max' => ['type' => ['number', 'null']],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'has_more' => ['type' => 'boolean'],
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
     * @param array $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $DB;

        $userid = (int) $user->id;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $cmidfilter = isset($arguments['cmid']) ? (int) $arguments['cmid'] : 0;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $usercourses = enrol_get_users_courses($userid, true, ['id', 'fullname']);
        $coursemap = [];
        foreach ($usercourses as $c) {
            $coursemap[(int) $c->id] = (string) $c->fullname;
        }
        if ($courseidfilter > 0) {
            if (!isset($coursemap[$courseidfilter])) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseidfilter}.",
                    ['course_id' => $courseidfilter]
                );
            }
            $coursemap = [$courseidfilter => $coursemap[$courseidfilter]];
        }
        if (empty($coursemap)) {
            return self::empty_payload($limit);
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($coursemap), SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT q.id AS quizid, q.name, q.course AS courseid,
                       q.timeopen, q.timeclose, q.timelimit, q.attempts AS attemptsallowed,
                       q.grademethod, q.grade AS grademax, q.sumgrades,
                       cm.id AS cmid
                  FROM {quiz} q
                  JOIN {course_modules} cm ON cm.instance = q.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'quiz'
                 WHERE q.course $insql
              ORDER BY COALESCE(NULLIF(q.timeclose, 0), q.timeopen, q.id) ASC";
        $rows = $DB->get_records_sql($sql, $params);

        if ($cmidfilter > 0) {
            $rows = array_filter($rows, static fn($r) => (int) $r->cmid === $cmidfilter);
            if (empty($rows)) {
                throw new tool_exception(
                    "No quiz with cmid {$cmidfilter} is visible to you.",
                    ['cmid' => $cmidfilter]
                );
            }
        }

        $now = time();
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];
        $attempts = [];

        foreach ($rows as $r) {
            $cmid = (int) $r->cmid;
            $course = get_course((int) $r->courseid);
            if (!is_siteadmin($user) && !can_access_course($course, $user)) {
                if ($cmidfilter > 0) {
                    throw new tool_exception(
                        "No quiz with cmid {$cmidfilter} is visible to you.",
                        ['cmid' => $cmidfilter]
                    );
                }
                continue;
            }
            $modinfo = get_fast_modinfo((int) $r->courseid, $userid);
            $cm = $modinfo->cms[$cmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                if ($cmidfilter > 0) {
                    throw new tool_exception(
                        "No quiz with cmid {$cmidfilter} is visible to you.",
                        ['cmid' => $cmidfilter]
                    );
                }
                continue;
            }

            $timeopen = (int) ($r->timeopen ?? 0);
            $timeclose = (int) ($r->timeclose ?? 0);
            $grademax = (float) ($r->grademax ?? 0);

            // Only the calling user's own attempts and final grade, ever.
            $attemptcount = $DB->count_records('quiz_attempts', [
                'quiz' => (int) $r->quizid, 'userid' => $userid, 'preview' => 0,
            ]);
            $finalgrade = $DB->get_field('quiz_grades', 'grade', [
                'quiz' => (int) $r->quizid, 'userid' => $userid,
            ]);

            $entries[] = [
                'cmid' => $cmid,
                'quiz_id' => (int) $r->quizid,
                'name' => format_string((string) $r->name, true, $stringopts),
                'course_id' => (int) $r->courseid,
                'course_name' => format_string($coursemap[(int) $r->courseid] ?? '', true, $stringopts),
                'timeopen_iso' => $timeopen > 0 ? gmdate('c', $timeopen) : null,
                'timeclose_iso' => $timeclose > 0 ? gmdate('c', $timeclose) : null,
                'is_open_now' => ($timeopen === 0 || $timeopen <= $now)
                    && ($timeclose === 0 || $timeclose > $now),
                'timelimit_seconds' => !empty($r->timelimit) ? (int) $r->timelimit : null,
                'attempts_allowed' => (int) ($r->attemptsallowed ?? 0),
                'grade_method' => self::GRADE_METHODS[(int) ($r->grademethod ?? 1)] ?? 'highest',
                'my_attempt_count' => (int) $attemptcount,
                'my_grade' => $finalgrade !== false ? round((float) $finalgrade, 2) : null,
                'grade_max' => $grademax > 0 ? $grademax : null,
                'url' => (new moodle_url('/mod/quiz/view.php', ['id' => $cmid]))->out(false),
            ];

            if ($cmidfilter > 0) {
                $attempts = self::attempt_history(
                    (int) $r->quizid,
                    $userid,
                    (float) ($r->sumgrades ?? 0),
                    $grademax
                );
            }
        }

        $total = count($entries);
        $page = array_slice($entries, $offset, $limit);

        $payload = [
            'quizzes' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
            'summary' => self::build_summary($total, $cmidfilter, $attempts),
        ];
        if ($cmidfilter > 0) {
            $payload['attempts'] = $attempts;
        }
        return $payload;
    }

    /**
     * The calling user's own attempt history for one quiz.
     *
     * @param int $quizid Quiz instance id.
     * @param int $userid The user.
     * @param float $sumgrades Quiz sumgrades (for scaling raw marks to the grade).
     * @param float $grademax Quiz maximum grade.
     * @return array<int, array<string,mixed>>
     */
    private static function attempt_history(int $quizid, int $userid, float $sumgrades, float $grademax): array {
        global $DB;

        $rows = $DB->get_records('quiz_attempts', [
            'quiz' => $quizid, 'userid' => $userid, 'preview' => 0,
        ], 'attempt ASC');

        $attempts = [];
        foreach ($rows as $row) {
            $started = (int) ($row->timestart ?? 0);
            $finished = (int) ($row->timefinish ?? 0);
            $grade = null;
            if ($row->sumgrades !== null && $sumgrades > 0 && $grademax > 0) {
                $grade = round((float) $row->sumgrades / $sumgrades * $grademax, 2);
            }
            $attempts[] = [
                'attempt' => (int) $row->attempt,
                'state' => (string) $row->state,
                'started_iso' => $started > 0 ? gmdate('c', $started) : null,
                'finished_iso' => $finished > 0 ? gmdate('c', $finished) : null,
                'duration_seconds' => ($started > 0 && $finished > 0) ? ($finished - $started) : null,
                'grade' => $grade,
                'grade_max' => $grademax > 0 ? $grademax : null,
            ];
        }
        return $attempts;
    }

    /**
     * Empty payload helper.
     *
     * @param int $limit Page size.
     * @return array<string,mixed>
     */
    private static function empty_payload(int $limit): array {
        return [
            'quizzes' => [],
            'total' => 0,
            'limit' => $limit,
            'offset' => 0,
            'has_more' => false,
            'summary' => 'No quizzes found.',
        ];
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Matching quizzes.
     * @param int $cmidfilter Single-quiz filter (0 = none).
     * @param array $attempts Attempt history (detail mode).
     * @return string
     */
    private static function build_summary(int $total, int $cmidfilter, array $attempts): string {
        if ($total === 0) {
            return 'No quizzes found.';
        }
        if ($cmidfilter > 0) {
            $n = count($attempts);
            return $n === 0
                ? 'Quiz found; you have not attempted it yet.'
                : sprintf('Quiz found with %d attempt(s) of yours.', $n);
        }
        return sprintf('Found %d quiz(zes) across your courses.', $total);
    }
}
