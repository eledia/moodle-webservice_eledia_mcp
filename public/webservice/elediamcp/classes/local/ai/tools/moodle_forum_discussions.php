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
use context_system;
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native forum discussion reader.
 *
 * Lists the discussions of the forums the authenticated user can see in a
 * course (or one forum), and returns the posts of a single discussion. All
 * mod_forum visibility rules are enforced through the forum API itself —
 * group restrictions, Q&A forums (no peers' posts before posting yourself),
 * timed posts and private replies — so the tool never reveals more than the
 * forum UI would. Read-only: it cannot post.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_forum_discussions implements ai_tool {
    /** @var int Default page size. */
    private const DEFAULT_LIMIT = 20;

    /** @var int Maximum page size. */
    private const MAX_LIMIT = 50;

    /** @var int Maximum characters of a post body returned. */
    private const MESSAGE_LENGTH = 4000;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_forum_discussions';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Forum discussions and posts';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Reads course forums as the authenticated user. With course_id: lists the visible '
            . 'forums and their recent discussions; with cmid: discussions of that forum; with '
            . 'discussion_id: the posts of that discussion (plain text, threaded via parent_id). '
            . 'All forum visibility rules apply (groups, Q&A forums, timed posts, private '
            . 'replies) — the tool never shows more than the forum page would. Read-only. Use to '
            . 'answer "what are people discussing?" and "did anyone answer my question?".';
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
                    'description' => 'List visible forums + recent discussions of this course.',
                ],
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id of one forum: list its discussions.',
                ],
                'discussion_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Return the posts of this discussion.',
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
            'required' => ['summary'],
            'properties' => [
                'forums' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'forum_id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'type' => ['type' => 'string'],
                            'discussion_count' => ['type' => 'integer'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'discussions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'discussion_id' => ['type' => 'integer'],
                            'forum_cmid' => ['type' => 'integer'],
                            'forum_name' => ['type' => 'string'],
                            'subject' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'created_iso' => ['type' => 'string'],
                            'last_post_iso' => ['type' => 'string'],
                            'reply_count' => ['type' => 'integer'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'posts' => [
                    'type' => 'array',
                    'description' => 'Only when discussion_id was given; threaded via parent_id.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'post_id' => ['type' => 'integer'],
                            'parent_id' => ['type' => ['integer', 'null']],
                            'author' => ['type' => 'string'],
                            'subject' => ['type' => 'string'],
                            'message_text' => ['type' => 'string'],
                            'created_iso' => ['type' => 'string'],
                            'is_mine' => ['type' => 'boolean'],
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
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $discussionid = isset($arguments['discussion_id']) ? (int) $arguments['discussion_id'] : 0;
        $cmid = isset($arguments['cmid']) ? (int) $arguments['cmid'] : 0;
        $courseid = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $limit = isset($arguments['limit'])
            ? max(1, min(self::MAX_LIMIT, (int) $arguments['limit']))
            : self::DEFAULT_LIMIT;
        $offset = isset($arguments['offset']) ? max(0, (int) $arguments['offset']) : 0;

        if ($discussionid > 0) {
            return self::read_posts($discussionid, $user, $limit, $offset);
        }
        if ($cmid > 0) {
            return self::list_discussions([$cmid], $user, $limit, $offset);
        }
        if ($courseid > 0) {
            return self::list_course($courseid, $user, $limit, $offset);
        }
        throw new tool_exception(
            'Pass course_id (forums + discussions), cmid (one forum) or discussion_id (posts).',
            ['hint' => 'At least one of course_id, cmid, discussion_id is required.']
        );
    }

    /**
     * List visible forums of a course plus their recent discussions.
     *
     * @param int $courseid The course.
     * @param stdClass $user The user.
     * @param int $limit Page size for discussions.
     * @param int $offset Page offset for discussions.
     * @return array<string, mixed>
     */
    private static function list_course(int $courseid, stdClass $user, int $limit, int $offset): array {
        $usercourses = enrol_get_users_courses((int) $user->id, true, ['id']);
        if (!isset($usercourses[$courseid])) {
            throw new tool_exception(
                "You are not enrolled in course {$courseid}.",
                ['course_id' => $courseid]
            );
        }

        $modinfo = get_fast_modinfo($courseid, (int) $user->id);
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $forums = [];
        $cmids = [];
        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $cmids[] = (int) $cm->id;
            $forums[] = [
                'cmid' => (int) $cm->id,
                'forum_id' => (int) $cm->instance,
                'name' => format_string((string) $cm->name, true, $stringopts),
                'type' => '',
                'discussion_count' => 0,
                'url' => (new moodle_url('/mod/forum/view.php', ['id' => (int) $cm->id]))->out(false),
            ];
        }
        if (empty($cmids)) {
            return ['forums' => [], 'discussions' => [], 'total' => 0, 'limit' => $limit,
                'offset' => 0, 'has_more' => false, 'summary' => 'No visible forums in this course.'];
        }

        $result = self::list_discussions($cmids, $user, $limit, $offset);

        // Fill type + per-forum counts now that discussions were resolved.
        global $DB;
        foreach ($forums as $i => $forum) {
            $record = $DB->get_record('forum', ['id' => $forum['forum_id']], 'id, type');
            $forums[$i]['type'] = $record ? (string) $record->type : '';
            $forums[$i]['discussion_count'] = count(array_filter($result['discussions'],
                static fn($d) => $d['forum_cmid'] === $forum['cmid']));
        }
        $result['forums'] = $forums;
        return $result;
    }

    /**
     * List the discussions of one or more forums, newest activity first.
     *
     * @param int[] $cmids Forum course-module ids.
     * @param stdClass $user The user.
     * @param int $limit Page size.
     * @param int $offset Page offset.
     * @return array<string, mixed>
     */
    private static function list_discussions(array $cmids, stdClass $user, int $limit, int $offset): array {
        global $DB;

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];

        foreach ($cmids as $cmid) {
            [$forum, $cm, $course] = self::resolve_forum($cmid, $user);
            $context = context_module::instance($cm->id);
            if (!has_capability('mod/forum:viewdiscussion', $context, $user)) {
                continue;
            }

            $discussions = $DB->get_records('forum_discussions', ['forum' => (int) $forum->id],
                'timemodified DESC');
            $firstpostids = array_values(array_filter(array_map(
                static fn($discussion): int => (int) $discussion->firstpost,
                $discussions
            )));
            $firstposts = !empty($firstpostids)
                ? $DB->get_records_list('forum_posts', 'id', $firstpostids)
                : [];
            $replycounts = [];
            if (!empty($discussions)) {
                [$insql, $params] = $DB->get_in_or_equal(array_keys($discussions), SQL_PARAMS_NAMED, 'discussion');
                $sql = "SELECT discussion, COUNT(1) AS replycount
                          FROM {forum_posts}
                         WHERE discussion {$insql}
                               AND parent <> 0
                               AND privatereplyto = 0
                      GROUP BY discussion";
                foreach ($DB->get_records_sql($sql, $params) as $countrow) {
                    $replycounts[(int) $countrow->discussion] = (int) $countrow->replycount;
                }
            }
            foreach ($discussions as $discussion) {
                // The forum API enforces groups, timed posts and Q&A rules.
                if (!forum_user_can_see_discussion($forum, $discussion, $context, $user)) {
                    continue;
                }
                $firstpost = $firstposts[(int) $discussion->firstpost] ?? null;
                $replycount = $replycounts[(int) $discussion->id] ?? 0;

                $entries[] = [
                    'discussion_id' => (int) $discussion->id,
                    'forum_cmid' => (int) $cm->id,
                    'forum_name' => format_string((string) $forum->name, true, $stringopts),
                    'subject' => format_string((string) $discussion->name, true, $stringopts),
                    'author' => self::author_name((int) ($firstpost->userid ?? $discussion->userid)),
                    'created_iso' => gmdate('c', (int) $discussion->timemodified > 0
                        ? (int) ($firstpost->created ?? $discussion->timemodified)
                        : (int) $discussion->timemodified),
                    'last_post_iso' => gmdate('c', (int) $discussion->timemodified),
                    'reply_count' => (int) $replycount,
                    'url' => (new moodle_url('/mod/forum/discuss.php',
                        ['d' => (int) $discussion->id]))->out(false),
                ];
            }
        }

        usort($entries, static fn($a, $b) => strcmp($b['last_post_iso'], $a['last_post_iso']));
        $total = count($entries);
        $page = array_slice($entries, $offset, $limit);

        return [
            'discussions' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
            'summary' => $total === 0 ? 'No visible discussions.'
                : sprintf('Found %d visible discussion(s).', $total),
        ];
    }

    /**
     * Return the posts of one discussion, oldest first, threaded via parent_id.
     *
     * @param int $discussionid The discussion id.
     * @param stdClass $user The user.
     * @param int $limit Page size.
     * @param int $offset Page offset.
     * @return array<string, mixed>
     */
    private static function read_posts(int $discussionid, stdClass $user, int $limit, int $offset): array {
        global $DB;

        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid]);
        if (!$discussion) {
            throw new tool_exception("Discussion {$discussionid} not found.",
                ['discussion_id' => $discussionid]);
        }
        $cm = get_coursemodule_from_instance('forum', (int) $discussion->forum, 0, false, MUST_EXIST);
        [$forum, $cm] = self::resolve_forum((int) $cm->id, $user);
        $context = context_module::instance($cm->id);

        require_capability('mod/forum:viewdiscussion', $context, (int) $user->id);
        if (!forum_user_can_see_discussion($forum, $discussion, $context, $user)) {
            throw new tool_exception("Discussion {$discussionid} is not visible to you.",
                ['discussion_id' => $discussionid]);
        }

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $rows = $DB->get_records('forum_posts', ['discussion' => $discussionid], 'created ASC');
        $posts = [];
        foreach ($rows as $post) {
            // Per-post rules: Q&A gating and private replies.
            if (!forum_user_can_see_post($forum, $discussion, $post, $user, $cm)) {
                continue;
            }
            $text = content_to_text((string) $post->message, (int) $post->messageformat);
            if (\core_text::strlen($text) > self::MESSAGE_LENGTH) {
                $text = \core_text::substr($text, 0, self::MESSAGE_LENGTH) . ' …';
            }
            $posts[] = [
                'post_id' => (int) $post->id,
                'parent_id' => !empty($post->parent) ? (int) $post->parent : null,
                'author' => self::author_name((int) $post->userid),
                'subject' => format_string((string) $post->subject, true, $stringopts),
                'message_text' => $text,
                'created_iso' => gmdate('c', (int) $post->created),
                'is_mine' => (int) $post->userid === (int) $user->id,
            ];
        }

        $total = count($posts);
        $page = array_slice($posts, $offset, $limit);

        return [
            'posts' => $page,
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
            'has_more' => ($offset + $limit) < $total,
            'summary' => sprintf('Discussion "%s" with %d visible post(s).',
                format_string((string) $discussion->name, true, $stringopts), $total),
        ];
    }

    /**
     * Resolve a forum cmid into [forum record, cm_info, course], enforcing visibility.
     *
     * @param int $cmid Course module id.
     * @param stdClass $user The user.
     * @return array{0: stdClass, 1: \cm_info, 2: stdClass}
     */
    private static function resolve_forum(int $cmid, stdClass $user): array {
        global $DB;

        [$course, $cminfo] = get_course_and_cm_from_cmid($cmid, 'forum', 0, (int) $user->id);
        // Course-access gate before module visibility: uservisible does not
        // check course enrolment/visibility (see moodle_get_resource).
        if (!is_siteadmin($user) && !can_access_course($course, $user)) {
            throw new tool_exception("No forum with cmid {$cmid} is visible to you.",
                ['cmid' => $cmid]);
        }
        if (!$cminfo->uservisible) {
            throw new tool_exception("No forum with cmid {$cmid} is visible to you.",
                ['cmid' => $cmid]);
        }
        $forum = $DB->get_record('forum', ['id' => (int) $cminfo->instance], '*', MUST_EXIST);
        return [$forum, $cminfo, $course];
    }

    /**
     * Resolve a display name for a post author.
     *
     * @param int $userid The author id.
     * @return string
     */
    private static function author_name(int $userid): string {
        static $cache = [];

        if (array_key_exists($userid, $cache)) {
            return $cache[$userid];
        }

        $author = \core_user::get_user($userid);
        if (!$author || !empty($author->deleted)) {
            $cache[$userid] = '';
            return '';
        }
        $cache[$userid] = fullname($author);
        return $cache[$userid];
    }
}
