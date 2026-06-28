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
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native content search across what the user can access.
 *
 * Primary path: Moodle's global search (core_search), which applies the same
 * access checks as the search UI — results never include content the user
 * could not open. When global search is disabled on the site, a lightweight
 * fallback searches the names and descriptions of visible activities in the
 * user's enrolled courses, so agents always get a useful (if shallower)
 * answer. Designed for "where is X covered?" and discovery queries.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_search_content implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 50;

    /** @var int Maximum characters of a result snippet. */
    private const SNIPPET_LENGTH = 500;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_search_content';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Search course content';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Full-text search across the content the authenticated user can access (forum '
            . 'posts, pages, books, activity descriptions, …) via Moodle\'s global search, '
            . 'optionally restricted to one course. When global search is disabled on the site, '
            . 'falls back to searching the names and descriptions of visible activities in the '
            . 'user\'s enrolled courses (engine field tells you which path answered). Results '
            . 'respect all access rules. Use for "where is X covered?" and content discovery; '
            . 'follow up with moodle_get_resource on a result\'s cmid for the full text.';
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
                    'minLength' => 2,
                    'maxLength' => 200,
                    'description' => 'Free-text search query.',
                ],
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Restrict to a single course id.',
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
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['results', 'total', 'engine', 'summary'],
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['title', 'url'],
                        'properties' => [
                            'title' => ['type' => 'string'],
                            'snippet' => ['type' => 'string'],
                            'course_id' => ['type' => ['integer', 'null']],
                            'course_name' => ['type' => 'string'],
                            'area' => ['type' => 'string',
                                'description' => 'Search area / activity type the hit belongs to.'],
                            'cmid' => ['type' => ['integer', 'null'],
                                'description' => 'Course module id when resolvable (for moodle_get_resource).'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'engine' => ['type' => 'string', 'enum' => ['globalsearch', 'fallback'],
                    'description' => 'globalsearch = full-text index; fallback = activity names/descriptions only.'],
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
        $query = trim((string) ($arguments['query'] ?? ''));
        if (\core_text::strlen($query) < 2) {
            throw new tool_exception(
                'The search query must be at least 2 characters long.',
                ['query' => $query]
            );
        }
        $courseid = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;

        if ($courseid > 0) {
            $usercourses = enrol_get_users_courses((int) $user->id, true, ['id']);
            if (!isset($usercourses[$courseid]) && !is_siteadmin($user)) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseid}.",
                    ['course_id' => $courseid]
                );
            }
        }

        if (\core_search\manager::is_global_search_enabled()) {
            try {
                return self::global_search($query, $courseid, $limit);
            } catch (tool_exception $e) {
                throw $e;
            } catch (Throwable $e) {
                // Engine hiccup (e.g. backend down): degrade to the fallback.
                debugging('moodle_search_content: global search failed, using fallback: '
                    . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return self::fallback_search($query, $courseid, $limit, $user);
    }

    /**
     * Search via core_search (access checks applied by the engine).
     *
     * @param string $query The query.
     * @param int $courseid Course restriction (0 = none).
     * @param int $limit Max results.
     * @return array<string, mixed>
     */
    private static function global_search(string $query, int $courseid, int $limit): array {
        // Core_search\manager::search() accepts a stdClass with the same fields
        // as the search form data object. This mirrors Moodle's internal search
        // form payload without depending on UI form classes.
        $formdata = new stdClass();
        $formdata->q = $query;
        if ($courseid > 0) {
            $formdata->courseids = [$courseid];
        }

        $manager = \core_search\manager::instance();
        $docs = $manager->search($formdata, $limit);

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $results = [];
        foreach ($docs as $doc) {
            $snippet = content_to_text((string) $doc->get('content'), FORMAT_HTML);
            if (\core_text::strlen($snippet) > self::SNIPPET_LENGTH) {
                $snippet = \core_text::substr($snippet, 0, self::SNIPPET_LENGTH) . ' …';
            }
            $url = '';
            try {
                $url = $doc->get_doc_url()->out(false);
            } catch (Throwable $e) {
                $url = '';
            }
            $results[] = [
                'title' => format_string((string) $doc->get('title'), true, $stringopts),
                'snippet' => s($snippet),
                'course_id' => $doc->is_set('courseid') ? (int) $doc->get('courseid') : null,
                'course_name' => '',
                'area' => (string) $doc->get('areaid'),
                'cmid' => self::extract_cmid($url),
                'url' => $url,
            ];
            if (count($results) >= $limit) {
                break;
            }
        }

        return [
            'results' => $results,
            'total' => count($results),
            'engine' => 'globalsearch',
            'summary' => count($results) === 0
                ? sprintf('No results for "%s".', $query)
                : sprintf('Found %d result(s) for "%s".', count($results), $query),
        ];
    }

    /**
     * Fallback: search visible activity names and descriptions in enrolled courses.
     *
     * @param string $query The query.
     * @param int $courseid Course restriction (0 = none).
     * @param int $limit Max results.
     * @param stdClass $user The user.
     * @return array<string, mixed>
     */
    private static function fallback_search(string $query, int $courseid, int $limit, stdClass $user): array {
        $usercourses = enrol_get_users_courses((int) $user->id, true, ['id', 'fullname']);
        if ($courseid > 0) {
            $usercourses = array_intersect_key($usercourses, [$courseid => true]);
        }

        $needle = \core_text::strtolower($query);
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $results = [];

        foreach ($usercourses as $course) {
            $modinfo = get_fast_modinfo($course, (int) $user->id);
            foreach ($modinfo->get_cms() as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $name = (string) $cm->name;
                $intro = content_to_text((string) ($cm->content ?? ''), FORMAT_HTML);
                $haystack = \core_text::strtolower($name . ' ' . $intro);
                if (\core_text::strpos($haystack, $needle) === false) {
                    continue;
                }
                $snippet = $intro !== '' ? $intro : $name;
                if (\core_text::strlen($snippet) > self::SNIPPET_LENGTH) {
                    $snippet = \core_text::substr($snippet, 0, self::SNIPPET_LENGTH) . ' …';
                }
                $results[] = [
                    'title' => format_string($name, true, $stringopts),
                    'snippet' => s($snippet),
                    'course_id' => (int) $course->id,
                    'course_name' => format_string((string) $course->fullname, true, $stringopts),
                    'area' => 'activity:' . $cm->modname,
                    'cmid' => (int) $cm->id,
                    'url' => $cm->url ? $cm->url->out(false) : '',
                ];
                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return [
            'results' => $results,
            'total' => count($results),
            'engine' => 'fallback',
            'summary' => count($results) === 0
                ? sprintf('No activity matched "%s" (note: site-wide full-text search is disabled; '
                    . 'only activity names/descriptions were searched).', $query)
                : sprintf('Found %d activity match(es) for "%s" (names/descriptions only — '
                    . 'full-text search is disabled on this site).', count($results), $query),
        ];
    }

    /**
     * Best-effort cmid extraction from a /mod/<name>/view.php?id=N result URL.
     *
     * @param string $url The result URL.
     * @return int|null
     */
    private static function extract_cmid(string $url): ?int {
        if (preg_match('~/mod/[a-z0-9_]+/view\.php\?id=(\d+)~', $url, $matches)) {
            return (int) $matches[1];
        }
        return null;
    }
}
