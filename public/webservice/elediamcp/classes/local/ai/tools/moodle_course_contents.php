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
use cm_info;
use context_course;
use context_system;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course contents probe.
 *
 * Returns the section / activity-module structure of a single course so the
 * LLM can answer "what is in this course?" and reason about the right module
 * to open. Honours the user's enrolment and the per-cm uservisible flag, so
 * the response is restricted to what the user is actually allowed to see.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_course_contents implements ai_tool {
    /** @var int Default excerpt length when descriptions are requested. */
    private const DEFAULT_EXCERPT = 200;

    /** @var int Maximum excerpt length to keep payloads token-friendly. */
    private const MAX_EXCERPT = 800;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_course_contents';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'List sections and activities of a course';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the sections and activity modules visible to the authenticated user in '
            . 'one course, including module type, name, URL and (optionally) a short description '
            . 'excerpt. Pass include_descriptions=true to request short text excerpts (capped at '
            . 'excerpt_chars). Use module_types to limit the response to specific activity types '
            . '(for example ["assign","quiz","forum"]). Always call moodle_my_courses or '
            . 'moodle_verify_user_context first to discover course_id values.';
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
            'required' => ['course_id'],
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Moodle course id.',
                ],
                'include_descriptions' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include a short plain-text excerpt of each module description.',
                ],
                'excerpt_chars' => [
                    'type' => 'integer',
                    'minimum' => 60,
                    'maximum' => self::MAX_EXCERPT,
                    'default' => self::DEFAULT_EXCERPT,
                    'description' => 'Maximum length of each description excerpt.',
                ],
                'module_types' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Optional list of activity module shortnames to include (e.g. ["assign","quiz"]).',
                ],
                'include_hidden' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include modules that exist but are not currently visible to the user '
                        . '(requires moodle/course:viewhiddenactivities).',
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
            'required' => ['course', 'sections', 'total_modules', 'summary'],
            'properties' => [
                'course' => [
                    'type' => 'object',
                    'required' => ['id', 'shortname', 'fullname', 'url'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'shortname' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'format' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                    ],
                ],
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['number', 'name', 'modules'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'number' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'visible' => ['type' => 'boolean'],
                            'summary_excerpt' => ['type' => 'string'],
                            'modules' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['id', 'cmid', 'name', 'modname', 'url'],
                                    'properties' => [
                                        'id' => ['type' => 'integer'],
                                        'cmid' => [
                                            'type' => 'integer',
                                            'description' => 'Course-module id. Alias of id, named cmid so it can be '
                                                . 'fed directly into moodle_get_resource.',
                                        ],
                                        'name' => ['type' => 'string'],
                                        'modname' => ['type' => 'string'],
                                        'modplural' => ['type' => 'string'],
                                        'instance' => ['type' => 'integer'],
                                        'visible' => ['type' => 'boolean'],
                                        'available' => ['type' => 'boolean'],
                                        'url' => ['type' => 'string'],
                                        'icon_url' => ['type' => 'string'],
                                        'description_excerpt' => ['type' => 'string'],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'total_modules' => ['type' => 'integer'],
                'filtered_modules' => ['type' => 'integer'],
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
        $courseid = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required and must be a positive integer.');
        }

        $includedesc = !empty($arguments['include_descriptions']);
        $excerpt = isset($arguments['excerpt_chars'])
            ? max(60, min(self::MAX_EXCERPT, (int) $arguments['excerpt_chars']))
            : self::DEFAULT_EXCERPT;
        $includehidden = !empty($arguments['include_hidden']);
        $modtypes = [];
        if (!empty($arguments['module_types']) && is_array($arguments['module_types'])) {
            foreach ($arguments['module_types'] as $t) {
                if (is_string($t) && preg_match('/^[a-z][a-z0-9_]*$/i', $t)) {
                    $modtypes[strtolower($t)] = true;
                }
            }
        }
        $modtypeskey = empty($modtypes) ? 'all' : substr(sha1(implode(',', array_keys($modtypes))), 0, 12);

        // Locate the course or fail with a friendly LLM message.
        $course = $DB->get_record('course', ['id' => $courseid], 'id, shortname, fullname, format, visible, summary');
        if (!$course) {
            throw new tool_exception(
                "Course with id {$courseid} does not exist.",
                ['course_id' => $courseid]
            );
        }

        $context = context_course::instance($courseid);
        $isadmin = is_siteadmin($user);
        $canaccess = $isadmin
            || is_enrolled($context, $userid, '', true)
            || has_capability('moodle/course:view', $context, $userid)
            || has_capability('moodle/course:viewhiddenactivities', $context, $userid);
        if (!$canaccess) {
            throw new tool_exception(
                "You are not enrolled in course {$courseid} and cannot view its contents.",
                ['course_id' => $courseid]
            );
        }

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf(
            'coursecontents_%d_%d_%d_%d_%d_%s',
            $userid,
            $courseid,
            $includedesc ? 1 : 0,
            $excerpt,
            $includehidden ? 1 : 0,
            $modtypeskey
        );
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $modinfo = get_fast_modinfo($courseid, $userid);
        $stringopts = ['context' => $context, 'filter' => false, 'escape' => true];

        $sections = [];
        $totalmodules = 0;
        $filtered = 0;

        $canviewhidden = $isadmin || has_capability('moodle/course:viewhiddenactivities', $context, $userid);
        foreach ($modinfo->get_section_info_all() as $sectionnum => $sectioninfo) {
            $sectionmodules = [];
            $cmids = $modinfo->sections[$sectionnum] ?? [];
            foreach ($cmids as $cmid) {
                /** @var cm_info $cm */
                $cm = $modinfo->cms[$cmid] ?? null;
                if (!$cm) {
                    continue;
                }
                $totalmodules++;
                if (!empty($modtypes) && !isset($modtypes[$cm->modname])) {
                    continue;
                }
                if (!$cm->uservisible) {
                    if (!$includehidden || !$canviewhidden) {
                        $filtered++;
                        continue;
                    }
                }

                $url = $cm->url ? $cm->url->out(false) : (
                    (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) . '#module-' . $cm->id
                );
                $iconurl = '';
                try {
                    $iconurl = (string) $cm->get_icon_url();
                } catch (Throwable $ex) {
                    // Some modules misbehave during icon resolution; ignore.
                    $iconurl = '';
                }

                $entry = [
                    'id' => (int) $cm->id,
                    'cmid' => (int) $cm->id,
                    'name' => format_string((string) $cm->name, true, $stringopts),
                    'modname' => (string) $cm->modname,
                    'modplural' => (string) ($cm->modplural ?? ''),
                    'instance' => (int) $cm->instance,
                    'visible' => (bool) $cm->visible,
                    'available' => (bool) $cm->available,
                    'url' => $url,
                    'icon_url' => $iconurl,
                ];

                if ($includedesc && trim((string) $cm->content) !== '') {
                    $entry['description_excerpt'] = self::excerpt(
                        (string) $cm->content,
                        FORMAT_HTML,
                        $context,
                        $excerpt
                    );
                }

                $sectionmodules[] = $entry;
            }

            if (!$sectioninfo->uservisible && (!$includehidden || !$canviewhidden)) {
                continue;
            }

            $sectionentry = [
                'id' => (int) $sectioninfo->id,
                'number' => (int) $sectionnum,
                'name' => format_string(
                    (string) (get_section_name($courseid, $sectioninfo) ?: ''),
                    true,
                    $stringopts
                ),
                'visible' => (bool) $sectioninfo->visible,
                'modules' => $sectionmodules,
            ];
            if ($includedesc && trim((string) ($sectioninfo->summary ?? '')) !== '') {
                $sectionentry['summary_excerpt'] = self::excerpt(
                    (string) $sectioninfo->summary,
                    (int) ($sectioninfo->summaryformat ?? FORMAT_HTML),
                    $context,
                    $excerpt
                );
            }

            $sections[] = $sectionentry;
        }

        $payload = [
            'course' => [
                'id' => (int) $course->id,
                'shortname' => (string) $course->shortname,
                'fullname' => format_string((string) $course->fullname, true, $stringopts),
                'format' => (string) ($course->format ?? ''),
                'url' => (new moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
            ],
            'sections' => $sections,
            'total_modules' => $totalmodules,
            'filtered_modules' => $filtered,
            'summary' => self::build_summary(
                (string) $course->fullname,
                $totalmodules,
                $filtered,
                count($sections)
            ),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_course_contents cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Build a short plain-text excerpt of HTML/Markdown content.
     *
     * @param string $content Raw content.
     * @param int $format Moodle text format constant.
     * @param context_course $context Course context.
     * @param int $max Max characters.
     * @return string
     */
    private static function excerpt(string $content, int $format, context_course $context, int $max): string {
        $opts = ['context' => $context, 'filter' => false, 'noclean' => true];
        $formatted = format_text($content, $format, $opts);
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
     * Build a one-sentence summary for the LLM.
     *
     * @param string $coursename Course fullname.
     * @param int $totalmodules Total modules in the course (before filtering).
     * @param int $filtered Modules hidden from the response.
     * @param int $sectioncount Number of sections included.
     * @return string
     */
    private static function build_summary(string $coursename, int $totalmodules, int $filtered, int $sectioncount): string {
        $shown = max(0, $totalmodules - $filtered);
        if ($shown === 0) {
            return sprintf('Course "%s" has no activity modules visible to you.', $coursename);
        }
        $hiddenlabel = $filtered > 0 ? sprintf(' (%d hidden)', $filtered) : '';
        return sprintf(
            'Course "%s" has %d visible %s across %d %s%s.',
            $coursename,
            $shown,
            $shown === 1 ? 'activity' : 'activities',
            $sectioncount,
            $sectioncount === 1 ? 'section' : 'sections',
            $hiddenlabel
        );
    }
}
