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
 * AI-native announcements feed.
 *
 * Returns the most recent posts in each course's news forum
 * (forum.type='news', the per-course Announcements forum) for the
 * authenticated user's enrolled courses. Designed to answer "what's new
 * in my courses?" in a single round-trip.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_get_announcements implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 10;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 30;

    /** @var int Default lookback window (days). */
    private const DEFAULT_DAYS = 30;

    /** @var int Maximum lookback window (days). */
    private const MAX_DAYS = 180;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_get_announcements';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Latest course announcements';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the most recent posts in each course\'s Announcements forum (forum.type='
            . '\'news\') for the authenticated user. Restrict to one course with course_id and '
            . 'set lookback_days to bound how far back to look (default 30). Use this to answer '
            . '"what\'s new in my courses?" or "did my teacher post anything?".';
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
                    'description' => 'Optional course id to restrict the feed to a single course.',
                ],
                'lookback_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_DAYS,
                    'default' => self::DEFAULT_DAYS,
                    'description' => 'Look-back window in days (default 30, max 180).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                ],
                'include_body' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'Include a plain-text excerpt of each post body.',
                ],
                'excerpt_chars' => [
                    'type' => 'integer',
                    'minimum' => 80,
                    'maximum' => 1200,
                    'default' => 400,
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
            'required' => ['announcements', 'total', 'summary'],
            'properties' => [
                'announcements' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['discussion_id', 'subject', 'course_id', 'posted_iso', 'url'],
                        'properties' => [
                            'discussion_id' => ['type' => 'integer'],
                            'post_id' => ['type' => 'integer'],
                            'subject' => ['type' => 'string'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'forum_id' => ['type' => 'integer'],
                            'author_id' => ['type' => 'integer'],
                            'author_name' => ['type' => 'string'],
                            'posted_iso' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'body_excerpt' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
                'lookback_days' => ['type' => 'integer'],
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
        global $DB;

        $userid = (int) $user->id;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $lookback = isset($arguments['lookback_days'])
            ? max(1, min(self::MAX_DAYS, (int) $arguments['lookback_days']))
            : self::DEFAULT_DAYS;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $includebody = !array_key_exists('include_body', $arguments) || (bool) $arguments['include_body'];
        $excerpt = isset($arguments['excerpt_chars'])
            ? max(80, min(1200, (int) $arguments['excerpt_chars']))
            : 400;

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'announce_%d_%d_%d_%d_%d_%d',
            $userid,
            $courseidfilter,
            $lookback,
            $limit,
            $includebody ? 1 : 0,
            $excerpt
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        // Resolve eligible course ids.
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
            return self::empty_payload($lookback);
        }

        // Only read announcement forums whose course-module is visible to the user
        // (honours hidden modules, availability restrictions and course visibility),
        // and remember group access so separate-groups discussions stay scoped.
        $visibleforumids = [];
        $foruminfo = [];
        foreach (array_keys($coursemap) as $cid) {
            try {
                $modinfo = get_fast_modinfo((int) $cid, $userid);
            } catch (Throwable $ex) {
                continue;
            }
            foreach ($modinfo->get_instances_of('forum') as $cm) {
                if (!$cm->uservisible) {
                    continue;
                }
                $forumid = (int) $cm->instance;
                $visibleforumids[$forumid] = true;
                $groupmode = (int) $cm->effectivegroupmode;
                $modcontext = \context_module::instance($cm->id);
                $accessall = $groupmode !== SEPARATEGROUPS
                    || has_capability('moodle/site:accessallgroups', $modcontext, $userid);
                $foruminfo[$forumid] = [
                    'accessall' => $accessall,
                    'allowed' => $accessall
                        ? []
                        : array_keys(groups_get_all_groups((int) $cid, $userid, (int) $cm->groupingid)),
                ];
            }
        }
        if (empty($visibleforumids)) {
            return self::empty_payload($lookback);
        }

        [$insql, $params] = $DB->get_in_or_equal(array_keys($coursemap), SQL_PARAMS_NAMED, 'cid');
        [$inforumsql, $forumparams] = $DB->get_in_or_equal(array_keys($visibleforumids), SQL_PARAMS_NAMED, 'fid');
        $params += $forumparams;
        $params['since'] = time() - ($lookback * DAYSECS);

        // We pull the first post per discussion (the announcement body) and order by
        // discussion timemodified (which is bumped on replies, useful for "what changed
        // recently?").
        $sql = "SELECT d.id AS discussionid, d.name AS subject, d.timemodified, d.course AS courseid,
                       d.groupid AS groupid,
                       p.id AS postid, p.userid AS authorid, p.message AS body, p.messageformat,
                       p.created AS posttime, f.id AS forumid
                  FROM {forum_discussions} d
                  JOIN {forum} f ON f.id = d.forum AND f.type = 'news'
                  JOIN {forum_posts} p ON p.id = d.firstpost
                 WHERE d.course $insql
                   AND f.id $inforumsql
                   AND d.timemodified >= :since
              ORDER BY d.timemodified DESC";
        $rows = $DB->get_records_sql($sql, $params, 0, $limit + 1); // +1 for has_more probe.
        $total = count($rows);
        $rows = array_slice($rows, 0, $limit);

        // Batch-load author names.
        $authorids = array_unique(array_map(static fn($r) => (int) $r->authorid, $rows));
        $authors = [];
        if (!empty($authorids)) {
            $namefields = 'id, firstname, lastname, firstnamephonetic, lastnamephonetic, '
                . 'middlename, alternatename';
            $authrecords = $DB->get_records_list('user', 'id', $authorids, '', $namefields);
            foreach ($authrecords as $a) {
                $authors[(int) $a->id] = fullname($a);
            }
        }

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];
        foreach ($rows as $r) {
            $courseid = (int) $r->courseid;
            // Separate-groups announcement: skip discussions targeted at a group the
            // user is not in (unless they may access all groups).
            $fi = $foruminfo[(int) $r->forumid] ?? null;
            if ($fi !== null && !$fi['accessall']) {
                $gid = (int) $r->groupid;
                if ($gid > 0 && !in_array($gid, $fi['allowed'], true)) {
                    continue;
                }
            }
            $entry = [
                'discussion_id' => (int) $r->discussionid,
                'post_id' => (int) $r->postid,
                'subject' => format_string((string) $r->subject, true, $stringopts),
                'course_id' => $courseid,
                'course_name' => isset($coursemap[$courseid])
                    ? format_string($coursemap[$courseid], true, $stringopts) : '',
                'forum_id' => (int) $r->forumid,
                'author_id' => (int) $r->authorid,
                'author_name' => $authors[(int) $r->authorid] ?? '',
                'posted_iso' => gmdate('c', (int) $r->posttime),
                'url' => (new moodle_url('/mod/forum/discuss.php', ['d' => (int) $r->discussionid]))->out(false),
            ];
            if ($includebody) {
                $entry['body_excerpt'] = self::excerpt(
                    (string) $r->body,
                    (int) $r->messageformat,
                    $excerpt
                );
            }
            $entries[] = $entry;
        }

        $payload = [
            'announcements' => $entries,
            'total' => count($entries),
            'lookback_days' => $lookback,
            'has_more' => $total > $limit,
            'summary' => self::build_summary(count($entries), $lookback, $courseidfilter, $coursemap),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_get_announcements cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Build a plain-text excerpt from forum-post HTML.
     *
     * @param string $body Raw body.
     * @param int $format Text format.
     * @param int $max Maximum length.
     * @return string
     */
    private static function excerpt(string $body, int $format, int $max): string {
        $opts = ['context' => context_system::instance(), 'filter' => false, 'noclean' => true];
        $formatted = format_text($body, $format, $opts);
        $plain = trim(html_to_text($formatted, 0, false));
        if ($plain === '') {
            return '';
        }
        if (\core_text::strlen($plain) > $max) {
            $plain = \core_text::substr($plain, 0, $max - 3) . '...';
        }
        return $plain;
    }

    /**
     * Empty payload helper for users with no eligible courses.
     *
     * @param int $lookback Lookback window in days.
     * @return array<string, mixed>
     */
    private static function empty_payload(int $lookback): array {
        return [
            'announcements' => [],
            'total' => 0,
            'lookback_days' => $lookback,
            'has_more' => false,
            'summary' => sprintf('No announcements in the last %d days.', $lookback),
        ];
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $shown Posts in the page.
     * @param int $lookback Days searched.
     * @param int $courseidfilter Course filter (0 = none).
     * @param array<int, string> $coursemap Map of course id => fullname.
     * @return string
     */
    private static function build_summary(int $shown, int $lookback, int $courseidfilter, array $coursemap): string {
        if ($shown === 0) {
            return sprintf('No new announcements in the last %d days.', $lookback);
        }
        $courselabel = '';
        if ($courseidfilter > 0) {
            $courselabel = isset($coursemap[$courseidfilter])
                ? sprintf(' in "%s"', $coursemap[$courseidfilter])
                : sprintf(' in course %d', $courseidfilter);
        }
        $word = $shown === 1 ? 'announcement' : 'announcements';
        return sprintf('Found %d %s%s in the last %d days.', $shown, $word, $courselabel, $lookback);
    }
}
