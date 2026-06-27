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
use context_system;
use core_course_category;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course catalogue search.
 *
 * Searches the course catalogue by free-text query and returns a paginated
 * list with short summary excerpts and direct URLs. Honours category and
 * course visibility for the calling user; admins see hidden courses too.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_search_courses implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 10;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 30;

    /** @var int Minimum query length. */
    private const MIN_QUERY = 2;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_search_courses';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Search the Moodle course catalogue';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Finds courses and returns a paginated list with short summary excerpts and direct '
            . 'URLs. Two modes via "scope": "catalogue" (default) searches/browses the whole '
            . 'visible course catalogue (use for courses the user is not enrolled in); "enrolled" '
            . 'restricts to the user\'s own courses. Omit "query" to browse/list all courses in '
            . 'the chosen scope (e.g. "how many courses are there"); provide "query" to search by '
            . 'keyword. Honours category and course visibility for the calling user (admins also '
            . 'see hidden courses).';
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
                'scope' => [
                    'type' => 'string',
                    'enum' => ['catalogue', 'enrolled'],
                    'default' => 'catalogue',
                    'description' => 'catalogue (default) = whole visible catalogue; '
                        . 'enrolled = only the user\'s own courses.',
                ],
                'query' => [
                    'type' => 'string',
                    'description' => 'Optional free-text query (matched against shortname, fullname '
                        . 'and summary). Omit to browse/list all courses in the scope. When given, '
                        . 'must be at least ' . self::MIN_QUERY . ' characters.',
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
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['courses', 'total', 'summary'],
            'properties' => [
                'courses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'shortname', 'fullname', 'url'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'shortname' => ['type' => 'string'],
                            'fullname' => ['type' => 'string'],
                            'summary_excerpt' => ['type' => 'string'],
                            'category_id' => ['type' => 'integer'],
                            'category_name' => ['type' => 'string'],
                            'visible' => ['type' => 'boolean'],
                            'enrolled' => ['type' => 'boolean'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'has_more' => ['type' => 'boolean'],
                'query' => ['type' => 'string'],
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
            // Course catalogue can change between calls.
            'openWorldHint' => true,
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
        $userid = (int) $user->id;
        $scope = isset($arguments['scope']) ? (string) $arguments['scope'] : 'catalogue';
        if (!in_array($scope, ['catalogue', 'enrolled'], true)) {
            throw new tool_exception(
                "Unknown scope '{$scope}'. Allowed values: catalogue, enrolled.",
                ['scope' => $scope]
            );
        }
        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
        if ($query !== '' && \core_text::strlen($query) < self::MIN_QUERY) {
            throw new tool_exception(
                sprintf('Query must be at least %d characters (or omit it to browse).', self::MIN_QUERY),
                ['query' => $query]
            );
        }
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        // Enrolled scope is consolidated onto moodle_my_courses (single source of
        // truth for "the user's own courses"); we only reshape its output.
        if ($scope === 'enrolled') {
            return self::list_enrolled($user, $query, $limit, $offset);
        }

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'searchcourses_%s_%d_%s_%d_%d',
            $scope,
            $userid,
            substr(sha1($query), 0, 16),
            $limit,
            $offset
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            if ($query === '') {
                // No query → browse the visible catalogue (enumeration, not search).
                $top = core_course_category::top();
                $results = $top->get_courses(
                    ['recursive' => true, 'offset' => $offset, 'limit' => $limit, 'sort' => ['fullname' => 1]]
                );
                $total = $top->get_courses_count(['recursive' => true]);
            } else {
                $results = core_course_category::search_courses(
                    ['search' => $query],
                    ['offset' => $offset, 'limit' => $limit, 'sort' => ['fullname' => 1]]
                );
                $total = core_course_category::search_courses_count(['search' => $query]);
            }
        } catch (Throwable $ex) {
            debugging('moodle_search_courses failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
            throw new tool_exception(
                'Course search failed.',
                ['query' => $query]
            );
        }

        // Pre-compute enrolment for highlighting.
        $enrolled = [];
        foreach (enrol_get_users_courses($userid, true, ['id']) as $c) {
            $enrolled[(int) $c->id] = true;
        }

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];
        foreach ($results as $course) {
            $courseid = (int) $course->id;
            $entries[] = [
                'id' => $courseid,
                'shortname' => (string) $course->shortname,
                'fullname' => format_string((string) $course->fullname, true, $stringopts),
                'summary_excerpt' => self::summary_excerpt(
                    (string) ($course->summary ?? ''),
                    (int) ($course->summaryformat ?? FORMAT_HTML)
                ),
                'category_id' => (int) ($course->category ?? 0),
                'category_name' => self::category_name((int) ($course->category ?? 0), $stringopts),
                'visible' => (bool) ($course->visible ?? true),
                'enrolled' => isset($enrolled[$courseid]),
                'url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ];
        }

        $payload = [
            'courses' => $entries,
            'total' => (int) $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < (int) $total,
            'query' => $query,
            'summary' => self::build_summary((int) $total, $query, count($entries)),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_search_courses cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Enrolled scope: list/filter the user's own courses.
     *
     * Delegates to {@see moodle_my_courses} so enrolment listing, filtering and
     * pagination have a single implementation, then reshapes the result into this
     * tool's course schema.
     *
     * @param stdClass $user Authenticated user record.
     * @param string $query Optional substring filter ('' lists all enrolled courses).
     * @param int $limit Page size.
     * @param int $offset Page offset.
     * @return array<string, mixed>
     */
    private static function list_enrolled(stdClass $user, string $query, int $limit, int $offset): array {
        $my = moodle_my_courses::execute([
            'classification' => 'all',
            'search' => $query,
            'limit' => $limit,
            'offset' => $offset,
        ], $user);

        $courses = [];
        foreach ((array) ($my['courses'] ?? []) as $c) {
            $courses[] = [
                'id' => (int) $c['id'],
                'shortname' => (string) $c['shortname'],
                'fullname' => (string) $c['fullname'],
                'summary_excerpt' => (string) ($c['summary_excerpt'] ?? ''),
                'category_id' => 0,
                'category_name' => '',
                'visible' => (bool) ($c['visible'] ?? true),
                'enrolled' => true,
                'url' => (string) $c['url'],
            ];
        }

        $total = (int) ($my['total'] ?? count($courses));
        if ($total === 0) {
            $summary = $query !== ''
                ? sprintf('No enrolled courses match "%s".', $query)
                : 'You are not enrolled in any courses.';
        } else {
            $match = $query !== '' ? sprintf(' matching "%s"', $query) : '';
            $summary = sprintf('Found %d enrolled course(s)%s.', $total, $match);
        }

        return [
            'courses' => $courses,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => (bool) ($my['has_more'] ?? false),
            'query' => $query,
            'summary' => $summary,
        ];
    }

    /**
     * Resolve a category name with a small in-request memo.
     *
     * @param int $categoryid Category id.
     * @param array<string, mixed> $stringopts format_string options.
     * @return string
     */
    private static function category_name(int $categoryid, array $stringopts): string {
        static $memo = [];
        if ($categoryid <= 0) {
            return '';
        }
        if (isset($memo[$categoryid])) {
            return $memo[$categoryid];
        }
        try {
            $cat = core_course_category::get($categoryid, IGNORE_MISSING, true);
            if (!$cat) {
                return $memo[$categoryid] = '';
            }
            return $memo[$categoryid] = format_string((string) $cat->name, true, $stringopts);
        } catch (Throwable $ex) {
            return $memo[$categoryid] = '';
        }
    }

    /**
     * Build a short plain-text excerpt of a course summary.
     *
     * @param string $summary Raw summary.
     * @param int $format Text format.
     * @return string
     */
    private static function summary_excerpt(string $summary, int $format): string {
        if (trim($summary) === '') {
            return '';
        }
        $opts = ['context' => context_system::instance(), 'filter' => false, 'noclean' => true];
        $formatted = format_text($summary, $format, $opts);
        $plain = trim(html_to_text($formatted, 0, false));
        if (\core_text::strlen($plain) > 200) {
            $plain = \core_text::substr($plain, 0, 197) . '...';
        }
        return $plain;
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Total matches.
     * @param string $query Search query.
     * @param int $shown Number of results in this page.
     * @return string
     */
    private static function build_summary(int $total, string $query, int $shown): string {
        if ($total === 0) {
            return $query !== ''
                ? sprintf('No courses match "%s".', $query)
                : 'No visible courses in the catalogue.';
        }
        $word = $total === 1 ? 'course' : 'courses';
        $for = $query !== '' ? sprintf(' matching "%s"', $query) : ' in the catalogue';
        $shownlabel = $shown < $total ? sprintf(' (showing %d)', $shown) : '';
        return sprintf('Found %d %s%s%s.', $total, $word, $for, $shownlabel);
    }
}
