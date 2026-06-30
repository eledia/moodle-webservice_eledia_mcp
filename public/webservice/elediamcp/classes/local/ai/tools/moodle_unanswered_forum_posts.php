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

/**
 * AI-native teacher view of forum discussions without replies.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_unanswered_forum_posts implements ai_tool {
    /** @var int Default search window. */
    private const DEFAULT_DAYS = 30;

    /** @var int Maximum search window. */
    private const MAX_DAYS = 180;

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
        return 'moodle_unanswered_forum_posts';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Unanswered forum discussions';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns visible forum discussions that have no replies yet and can be answered by '
            . 'the authenticated user. Use this for teacher prompts such as "which forum questions '
            . 'still need an answer?".';
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
                'days_back' => [
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
            'required' => ['discussions', 'total', 'summary'],
            'properties' => [
                'discussions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['discussion_id', 'forum_cmid', 'subject', 'course_id', 'created_iso', 'url'],
                        'properties' => [
                            'discussion_id' => ['type' => 'integer'],
                            'forum_cmid' => ['type' => 'integer'],
                            'forum_name' => ['type' => 'string'],
                            'subject' => ['type' => 'string'],
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'author' => ['type' => 'string'],
                            'created_iso' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'total' => ['type' => 'integer'],
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
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $days = isset($arguments['days_back'])
            ? max(1, min(self::MAX_DAYS, (int) $arguments['days_back']))
            : self::DEFAULT_DAYS;
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
                'discussions' => [],
                'total' => 0,
                'summary' => 'No visible courses with answerable forum discussions found for this user.',
            ];
        }

        $since = time() - ($days * DAYSECS);
        $entries = [];
        foreach ($courses as $course) {
            $entries = array_merge($entries, self::course_unanswered((int) $course->id, $course, $user, $since));
        }

        usort($entries, static fn(array $a, array $b): int => strcmp($b['created_iso'], $a['created_iso']));
        $total = count($entries);
        $entries = array_slice($entries, 0, $limit);

        return [
            'discussions' => $entries,
            'total' => $total,
            'summary' => $total === 0
                ? 'No unanswered forum discussions found in the selected window.'
                : "Found {$total} unanswered forum discussion(s) in the selected window.",
        ];
    }

    /**
     * Return unanswered discussions for one course.
     *
     * @param int $courseid Course id.
     * @param stdClass $course Course record.
     * @param stdClass $user Authenticated user.
     * @param int $since Earliest first-post timestamp.
     * @return array<int, array<string, mixed>>
     */
    private static function course_unanswered(int $courseid, stdClass $course, stdClass $user, int $since): array {
        global $DB;

        $modinfo = get_fast_modinfo($courseid, (int) $user->id);
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $entries = [];

        foreach ($modinfo->get_instances_of('forum') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $context = context_module::instance((int) $cm->id);
            if (
                !has_capability('mod/forum:viewdiscussion', $context, $user->id)
                || !has_capability('mod/forum:replypost', $context, $user->id)
            ) {
                continue;
            }

            $forum = $DB->get_record('forum', ['id' => (int) $cm->instance], '*', MUST_EXIST);
            $sql = "SELECT d.id, d.id AS discussionid, d.course, d.forum, d.groupid,
                           d.firstpost, d.name, d.timemodified, d.timestart, d.timeend,
                           p.userid, p.created, p.subject, COUNT(r.id) AS replycount
                      FROM {forum_discussions} d
                      JOIN {forum_posts} p ON p.id = d.firstpost
                 LEFT JOIN {forum_posts} r
                        ON r.discussion = d.id
                       AND r.parent <> 0
                       AND r.privatereplyto = 0
                     WHERE d.forum = :forumid
                       AND p.created >= :since
                  GROUP BY d.id, d.course, d.forum, d.groupid, d.firstpost, d.name, d.timemodified,
                           d.timestart, d.timeend, p.userid, p.created, p.subject
                    HAVING COUNT(r.id) = 0
                  ORDER BY p.created DESC";
            $rows = $DB->get_records_sql($sql, ['forumid' => (int) $forum->id, 'since' => $since]);
            foreach ($rows as $row) {
                if (!forum_user_can_see_discussion($forum, $row, $context, $user)) {
                    continue;
                }
                $entries[] = [
                    'discussion_id' => (int) $row->discussionid,
                    'forum_cmid' => (int) $cm->id,
                    'forum_name' => format_string((string) $forum->name, true, $stringopts),
                    'subject' => format_string((string) ($row->subject ?: $row->name), true, $stringopts),
                    'course_id' => $courseid,
                    'course_name' => format_string((string) $course->fullname, true, $stringopts),
                    'author' => self::author_name((int) $row->userid),
                    'created_iso' => date(DATE_ATOM, (int) $row->created),
                    'url' => (new moodle_url('/mod/forum/discuss.php', ['d' => (int) $row->discussionid]))->out(false),
                ];
            }
        }

        return $entries;
    }

    /**
     * Resolve a display name for a post author.
     *
     * @param int $userid Author id.
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
