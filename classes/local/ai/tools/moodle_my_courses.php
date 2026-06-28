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
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native list of the authenticated user's courses.
 *
 * Lighter than moodle_verify_user_context (no roles, no groups, no capability
 * detection) and supports classification (in-progress, future, past),
 * substring search and pagination. Designed to be the second call an agent
 * makes after moodle_me, before drilling into a specific course with
 * moodle_course_contents.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_my_courses implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 50;

    /** @var string[] Allowed classification values. */
    private const CLASSIFICATIONS = ['all', 'inprogress', 'future', 'past'];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_my_courses';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'List my Moodle courses';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the courses the authenticated user is enrolled in, with optional '
            . 'classification (all, inprogress, future, past), substring search and pagination. '
            . 'Lighter than moodle_verify_user_context: no roles, no groups, no capability set. '
            . 'Use this to enumerate the user\'s own courses, then call moodle_course_contents '
            . 'with a specific course_id to see what is inside. To find courses the user is NOT '
            . 'enrolled in, use moodle_search_courses (scope=catalogue).';
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
                'classification' => [
                    'type' => 'string',
                    'enum' => self::CLASSIFICATIONS,
                    'default' => 'inprogress',
                    'description' => 'Filter by lifecycle: all, inprogress (default), future or past.',
                ],
                'search' => [
                    'type' => 'string',
                    'description' => 'Case-insensitive substring match on shortname or fullname.',
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
                            'classification' => ['type' => 'string'],
                            'start_date' => ['type' => ['string', 'null']],
                            'end_date' => ['type' => ['string', 'null']],
                            'visible' => ['type' => 'boolean'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'limit' => ['type' => 'integer'],
                'offset' => ['type' => 'integer'],
                'classification' => ['type' => 'string'],
                'has_more' => ['type' => 'boolean'],
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
        $userid = (int) $user->id;
        $classification = isset($arguments['classification']) ? (string) $arguments['classification'] : 'inprogress';
        if (!in_array($classification, self::CLASSIFICATIONS, true)) {
            throw new tool_exception(
                "Unknown classification '{$classification}'. Allowed values: "
                    . implode(', ', self::CLASSIFICATIONS) . '.',
                ['allowed' => self::CLASSIFICATIONS]
            );
        }
        $search = isset($arguments['search']) ? trim((string) $arguments['search']) : '';
        $limit = isset($arguments['limit']) ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit'])) : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'mycourses_%d_%s_%s_%d_%d',
            $userid,
            $classification,
            $search !== '' ? substr(sha1($search), 0, 12) : 'none',
            $limit,
            $offset
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $usercourses = enrol_get_users_courses(
            $userid,
            true,
            ['id', 'shortname', 'fullname', 'summary', 'summaryformat', 'visible', 'startdate', 'enddate']
        );

        $now = time();
        $classified = [];
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];

        foreach ($usercourses as $c) {
            $startdate = (int) ($c->startdate ?? 0);
            $enddate = (int) ($c->enddate ?? 0);
            $life = self::classify($startdate, $enddate, $now);
            if ($classification !== 'all' && $life !== $classification) {
                continue;
            }
            if ($search !== '') {
                $haystack = strtolower(($c->shortname ?? '') . ' ' . ($c->fullname ?? ''));
                if (!str_contains($haystack, strtolower($search))) {
                    continue;
                }
            }
            $courseid = (int) $c->id;
            $classified[] = [
                'id' => $courseid,
                'shortname' => (string) $c->shortname,
                'fullname' => format_string((string) $c->fullname, true, $stringopts),
                'summary_excerpt' => self::summary_excerpt(
                    (string) ($c->summary ?? ''),
                    (int) ($c->summaryformat ?? FORMAT_HTML),
                    $courseid
                ),
                'classification' => $life,
                'start_date' => self::format_timestamp($startdate),
                'end_date' => self::format_timestamp($enddate),
                'visible' => (bool) ($c->visible ?? true),
                'url' => (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            ];
        }

        // Stable ordering: in-progress / future first by start ascending, past by end descending.
        usort($classified, static function (array $a, array $b): int {
            $aweight = $a['classification'] === 'past' ? 1 : 0;
            $bweight = $b['classification'] === 'past' ? 1 : 0;
            if ($aweight !== $bweight) {
                return $aweight <=> $bweight;
            }
            return strcasecmp($a['fullname'], $b['fullname']);
        });

        $total = count($classified);
        $page = array_slice($classified, $offset, $limit);

        $payload = [
            'courses' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'classification' => $classification,
            'has_more' => ($offset + $limit) < $total,
            'summary' => self::build_summary($total, $classification, $search, count($page)),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_my_courses cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Classify a course by lifecycle.
     *
     * @param int $startdate Course start unix timestamp (0 = unset).
     * @param int $enddate   Course end unix timestamp (0 = unset).
     * @param int $now       Reference time.
     * @return string One of inprogress / future / past.
     */
    private static function classify(int $startdate, int $enddate, int $now): string {
        if ($startdate > 0 && $startdate > $now) {
            return 'future';
        }
        if ($enddate > 0 && $enddate < $now) {
            return 'past';
        }
        return 'inprogress';
    }

    /**
     * Build a short text excerpt of a course summary.
     *
     * @param string $summary Raw summary text (may contain HTML).
     * @param int $format Summary format constant.
     * @param int $courseid Course id (for filter context).
     * @return string
     */
    private static function summary_excerpt(string $summary, int $format, int $courseid): string {
        if (trim($summary) === '') {
            return '';
        }
        $opts = [
            'context' => context_system::instance(),
            'filter' => false,
            'noclean' => true,
        ];
        $plain = format_text($summary, $format, $opts);
        $plain = trim(html_to_text($plain, 0, false));
        if (\core_text::strlen($plain) > 240) {
            $plain = \core_text::substr($plain, 0, 237) . '...';
        }
        return $plain;
    }

    /**
     * Format a unix timestamp as ISO-8601 or null when unset / zero.
     *
     * @param int $timestamp Unix timestamp.
     * @return string|null
     */
    private static function format_timestamp(int $timestamp): ?string {
        if ($timestamp <= 0) {
            return null;
        }
        return gmdate('c', $timestamp);
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Total matching courses.
     * @param string $classification Active classification filter.
     * @param string $search Active search query.
     * @param int $shown Number of courses included in the page.
     * @return string
     */
    private static function build_summary(int $total, string $classification, string $search, int $shown): string {
        if ($total === 0) {
            $searchlabel = $search !== '' ? sprintf(' matching "%s"', $search) : '';
            return sprintf('No %s courses%s.', $classification === 'all' ? '' : $classification, $searchlabel);
        }
        $label = $classification === 'all' ? 'course' : ($classification . ' course');
        $countlabel = $total === 1 ? "1 {$label}" : sprintf('%d %ss', $total, $label);
        $shownlabel = $shown < $total ? sprintf(' (showing %d)', $shown) : '';
        $searchlabel = $search !== '' ? sprintf(' matching "%s"', $search) : '';
        return sprintf('Found %s%s%s.', $countlabel, $searchlabel, $shownlabel);
    }
}
