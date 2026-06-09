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
        return 'Searches the course catalogue by free-text query and returns a paginated list '
            . 'with short summary excerpts and direct URLs. Use this when the user mentions a '
            . 'course they are not currently enrolled in. Honours category and course visibility '
            . 'for the calling user.';
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
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => self::MIN_QUERY,
                    'description' => 'Free-text query (matched against shortname, fullname and summary).',
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
        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
        if (strlen($query) < self::MIN_QUERY) {
            throw new tool_exception(
                sprintf('Query must be at least %d characters.', self::MIN_QUERY),
                ['query' => $query]
            );
        }
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'searchcourses_%d_%s_%d_%d',
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
            $results = core_course_category::search_courses(
                ['search' => $query],
                ['offset' => $offset, 'limit' => $limit, 'sort' => ['fullname' => 1]]
            );
            $total = core_course_category::search_courses_count(['search' => $query]);
        } catch (Throwable $ex) {
            throw new tool_exception(
                'Course search failed: ' . $ex->getMessage(),
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
        if (strlen($plain) > 200) {
            $plain = substr($plain, 0, 197) . '...';
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
            return sprintf('No courses match "%s".', $query);
        }
        $word = $total === 1 ? 'course' : 'courses';
        $shownlabel = $shown < $total ? sprintf(' (showing %d)', $shown) : '';
        return sprintf('Found %d %s matching "%s"%s.', $total, $word, $query, $shownlabel);
    }
}
