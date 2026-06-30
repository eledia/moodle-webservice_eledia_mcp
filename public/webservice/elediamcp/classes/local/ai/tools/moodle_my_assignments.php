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
use context_module;
use context_system;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native assignment overview.
 *
 * Lists all assignments visible to the authenticated user across the
 * courses they are enrolled in, with derived status
 * (notsubmitted / submitted / graded), due / cut-off dates and an
 * overdue flag. Designed for "what is due soon?" and "what have I not
 * submitted yet?" agent queries.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_my_assignments implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 30;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 100;

    /** @var string[] Allowed status filter values. */
    private const STATUS_VALUES = ['all', 'notsubmitted', 'submitted', 'graded', 'overdue', 'upcoming'];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_my_assignments';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'My assignments and submission status';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns assignments visible to the authenticated user across their enrolled '
            . 'courses, each with name, due date, cut-off date, derived status (notsubmitted, '
            . 'submitted, graded), grade (when graded) and an overdue flag. Filter with '
            . 'status (notsubmitted, submitted, graded, overdue, upcoming) or course_id. Use to '
            . 'answer "what is due soon?" and "what have I not submitted yet?".';
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
                'status' => [
                    'type' => 'string',
                    'enum' => self::STATUS_VALUES,
                    'default' => 'all',
                    'description' => 'Filter by derived status (default all).',
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
            'required' => ['assignments', 'total', 'summary'],
            'properties' => [
                'assignments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['cmid', 'assign_id', 'name', 'course_id', 'status', 'url'],
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'assign_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'duedate_iso' => ['type' => ['string', 'null']],
                            'cutoffdate_iso' => ['type' => ['string', 'null']],
                            'allowsubmissionsfromdate_iso' => ['type' => ['string', 'null']],
                            'status' => ['type' => 'string', 'enum' => ['notsubmitted', 'submitted', 'graded']],
                            'overdue' => ['type' => 'boolean'],
                            'submitted_iso' => ['type' => ['string', 'null']],
                            'grade' => ['type' => ['number', 'null']],
                            'grade_max' => ['type' => ['number', 'null']],
                            'grade_str' => ['type' => 'string'],
                            'graded_iso' => ['type' => ['string', 'null']],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'has_more' => ['type' => 'boolean'],
                'status' => ['type' => 'string'],
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
        global $DB, $CFG;

        $userid = (int) $user->id;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $statusfilter = isset($arguments['status']) ? (string) $arguments['status'] : 'all';
        if (!in_array($statusfilter, self::STATUS_VALUES, true)) {
            throw new tool_exception(
                "Unknown status '{$statusfilter}'. Allowed: " . implode(', ', self::STATUS_VALUES) . '.',
                ['allowed' => self::STATUS_VALUES]
            );
        }
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'assigns_%d_%d_%s_%d_%d',
            $userid,
            $courseidfilter,
            $statusfilter,
            $limit,
            $offset
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $usercourses = enrol_get_users_courses($userid, true, ['id', 'fullname']);
        $coursemap = [];
        foreach ($usercourses as $c) {
            $coursemap[(int) $c->id] = (string) $c->fullname;
        }
        if ($courseidfilter > 0) {
            if (!isset($coursemap[$courseidfilter]) && !is_siteadmin($user)) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseidfilter}.",
                    ['course_id' => $courseidfilter]
                );
            }
            $coursemap = [$courseidfilter => $coursemap[$courseidfilter] ?? ('Course ' . $courseidfilter)];
        }
        if (empty($coursemap)) {
            return self::empty_payload($statusfilter);
        }

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        [$insql, $params] = $DB->get_in_or_equal(array_keys($coursemap), SQL_PARAMS_NAMED, 'cid');
        $sql = "SELECT a.id AS assignid, a.name, a.course AS courseid,
                       a.duedate, a.cutoffdate, a.allowsubmissionsfromdate,
                       a.grade AS grademax,
                       cm.id AS cmid, cm.visible AS cmvisible
                  FROM {assign} a
                  JOIN {course_modules} cm ON cm.instance = a.id
                  JOIN {modules} m ON m.id = cm.module AND m.name = 'assign'
                 WHERE a.course $insql
              ORDER BY COALESCE(NULLIF(a.duedate, 0), a.allowsubmissionsfromdate, a.id) ASC";
        $rows = $DB->get_records_sql($sql, $params);

        $now = time();
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];

        foreach ($rows as $r) {
            $cmid = (int) $r->cmid;
            $modinfo = get_fast_modinfo((int) $r->courseid, $userid);
            $cm = $modinfo->cms[$cmid] ?? null;
            if (!$cm || !$cm->uservisible) {
                continue;
            }

            // Submission lookup (latest attempt).
            $submission = $DB->get_record('assign_submission', [
                'assignment' => (int) $r->assignid,
                'userid' => $userid,
                'latest' => 1,
            ], '*', IGNORE_MISSING);
            if (!$submission) {
                // Fallback: latest by timemodified.
                $submission = $DB->get_record_sql(
                    "SELECT * FROM {assign_submission}
                      WHERE assignment = :aid AND userid = :uid
                   ORDER BY timemodified DESC",
                    ['aid' => (int) $r->assignid, 'uid' => $userid],
                    IGNORE_MISSING
                );
            }
            $submittedat = null;
            $hassubmission = false;
            if ($submission && (string) $submission->status === 'submitted') {
                $hassubmission = true;
                $submittedat = (int) ($submission->timemodified ?? $submission->timecreated ?? 0);
            }

            // Grade lookup.
            $grade = $DB->get_record_sql(
                "SELECT * FROM {assign_grades}
                  WHERE assignment = :aid AND userid = :uid
               ORDER BY timemodified DESC",
                ['aid' => (int) $r->assignid, 'uid' => $userid],
                IGNORE_MISSING
            );
            $gradeval = null;
            $gradedat = null;
            if ($grade && (float) $grade->grade >= 0) {
                $gradeval = (float) $grade->grade;
                $gradedat = (int) $grade->timemodified;
            }

            $status = 'notsubmitted';
            if ($gradeval !== null) {
                $status = 'graded';
            } else if ($hassubmission) {
                $status = 'submitted';
            }
            $duedate = (int) ($r->duedate ?? 0);
            $overdue = $duedate > 0 && $duedate < $now && $status === 'notsubmitted';

            if (!self::matches_status_filter($status, $overdue, $duedate, $now, $statusfilter)) {
                continue;
            }

            $grademax = (float) ($r->grademax ?? 0);
            $gradestr = self::format_grade($gradeval, $grademax);

            $entries[] = [
                'cmid' => $cmid,
                'assign_id' => (int) $r->assignid,
                'name' => format_string((string) $r->name, true, $stringopts),
                'course_id' => (int) $r->courseid,
                'course_name' => isset($coursemap[(int) $r->courseid])
                    ? format_string($coursemap[(int) $r->courseid], true, $stringopts) : '',
                'duedate_iso' => $duedate > 0 ? gmdate('c', $duedate) : null,
                'cutoffdate_iso' => !empty($r->cutoffdate) ? gmdate('c', (int) $r->cutoffdate) : null,
                'allowsubmissionsfromdate_iso' => !empty($r->allowsubmissionsfromdate)
                    ? gmdate('c', (int) $r->allowsubmissionsfromdate) : null,
                'status' => $status,
                'overdue' => $overdue,
                'submitted_iso' => $submittedat ? gmdate('c', $submittedat) : null,
                'grade' => $gradeval,
                'grade_max' => $grademax > 0 ? $grademax : null,
                'grade_str' => $gradestr,
                'graded_iso' => $gradedat ? gmdate('c', $gradedat) : null,
                'url' => (new moodle_url('/mod/assign/view.php', ['id' => $cmid]))->out(false),
            ];
        }

        $total = count($entries);
        $page = array_slice($entries, $offset, $limit);

        $payload = [
            'assignments' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
            'status' => $statusfilter,
            'summary' => self::build_summary($total, $statusfilter, $courseidfilter, $coursemap),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_my_assignments cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Check whether an assignment row matches the requested status filter.
     *
     * @param string $status Derived status.
     * @param bool $overdue Overdue flag.
     * @param int $duedate Due timestamp.
     * @param int $now Current time.
     * @param string $filter Requested filter.
     * @return bool
     */
    private static function matches_status_filter(string $status, bool $overdue, int $duedate, int $now, string $filter): bool {
        switch ($filter) {
            case 'all':
                return true;
            case 'notsubmitted':
                return $status === 'notsubmitted' && !$overdue;
            case 'submitted':
                return $status === 'submitted';
            case 'graded':
                return $status === 'graded';
            case 'overdue':
                return $overdue;
            case 'upcoming':
                return $status === 'notsubmitted' && $duedate > 0 && $duedate >= $now && $duedate <= ($now + (7 * DAYSECS));
            default:
                return true;
        }
    }

    /**
     * Format a grade for human / LLM consumption.
     *
     * @param float|null $grade Numeric grade or null.
     * @param float $max Max grade (0 = unscaled / scale).
     * @return string
     */
    private static function format_grade(?float $grade, float $max): string {
        if ($grade === null) {
            return '-';
        }
        if ($max > 0) {
            return sprintf('%s / %s', self::format_number($grade), self::format_number($max));
        }
        return self::format_number($grade);
    }

    /**
     * Pretty number formatter that drops trailing zeros.
     *
     * @param float $value Number to format.
     * @return string
     */
    private static function format_number(float $value): string {
        $formatted = number_format($value, 2, '.', '');
        return rtrim(rtrim($formatted, '0'), '.');
    }

    /**
     * Empty payload helper for users with no eligible courses.
     *
     * @param string $statusfilter Active status filter.
     * @return array<string,mixed>
     */
    private static function empty_payload(string $statusfilter): array {
        return [
            'assignments' => [],
            'total' => 0,
            'limit' => self::DEFAULT_LIMIT,
            'offset' => 0,
            'has_more' => false,
            'status' => $statusfilter,
            'summary' => 'No assignments found.',
        ];
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Matching assignments.
     * @param string $status Active status filter.
     * @param int $courseidfilter Course filter (0 = none).
     * @param array $coursemap Map of course id => fullname.
     * @return string
     */
    private static function build_summary(int $total, string $status, int $courseidfilter, array $coursemap): string {
        if ($total === 0) {
            return $status === 'all' ? 'No assignments found.' : sprintf('No %s assignments.', $status);
        }
        $courselabel = '';
        if ($courseidfilter > 0) {
            $courselabel = isset($coursemap[$courseidfilter])
                ? sprintf(' in "%s"', $coursemap[$courseidfilter])
                : sprintf(' in course %d', $courseidfilter);
        }
        $word = $total === 1 ? 'assignment' : 'assignments';
        $statuslabel = $status === 'all' ? '' : ($status . ' ');
        return sprintf('Found %d %s%s%s.', $total, $statuslabel, $word, $courselabel);
    }
}
