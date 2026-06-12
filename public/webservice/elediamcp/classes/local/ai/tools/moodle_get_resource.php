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
use context_module;
use context_system;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native resource reader.
 *
 * Returns the readable content of a single course module so an LLM can
 * quote from it. Supports page, book (+ chapters), label, url, resource
 * (metadata only) and folder (metadata only). For unknown module types
 * the intro field is returned with a note.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_get_resource implements ai_tool {
    /** @var int Default max characters returned for any text body. */
    private const DEFAULT_MAX_CHARS = 4000;

    /** @var int Hard upper bound on returned text length. */
    private const HARD_MAX_CHARS = 12000;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_get_resource';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Read the content of a course resource';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the readable content of a single course module identified by cmid '
            . '(course_module id). Supports page (full content), book (chapter list, plus full '
            . 'chapter text when chapter_id is given), label (intro is the content), url '
            . '(external link), resource and folder (metadata + file links, no binary content). '
            . 'For other module types only the intro is returned. Use moodle_course_contents to '
            . 'discover cmid values.';
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
            'required' => ['cmid'],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id (cmid).',
                ],
                'chapter_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'For book modules: return the full text of this chapter.',
                ],
                'max_chars' => [
                    'type' => 'integer',
                    'minimum' => 200,
                    'maximum' => self::HARD_MAX_CHARS,
                    'default' => self::DEFAULT_MAX_CHARS,
                    'description' => 'Truncate returned text to at most this many characters.',
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
            'required' => ['cmid', 'modname', 'name', 'course_id', 'url', 'content', 'summary'],
            'properties' => [
                'cmid' => ['type' => 'integer'],
                'modname' => ['type' => 'string'],
                'name' => ['type' => 'string'],
                'course_id' => ['type' => 'integer'],
                'course_name' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'intro' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'content_truncated' => ['type' => 'boolean'],
                'external_url' => ['type' => 'string'],
                'chapters' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['id', 'title'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'pagenum' => ['type' => 'integer'],
                            'subchapter' => ['type' => 'boolean'],
                        ],
                    ],
                ],
                'files' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['filename', 'url'],
                        'properties' => [
                            'filename' => ['type' => 'string'],
                            'mimetype' => ['type' => 'string'],
                            'filesize' => ['type' => 'integer'],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
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
        global $DB, $CFG;

        $userid = (int) $user->id;
        $cmid = isset($arguments['cmid']) ? (int) $arguments['cmid'] : 0;
        if ($cmid <= 0) {
            throw new tool_exception('cmid is required and must be a positive integer.');
        }
        $chapterid = isset($arguments['chapter_id']) ? (int) $arguments['chapter_id'] : 0;
        $maxchars = isset($arguments['max_chars'])
            ? max(200, min(self::HARD_MAX_CHARS, (int) $arguments['max_chars']))
            : self::DEFAULT_MAX_CHARS;

        // Resolve cm.
        try {
            [$course, $cm] = get_course_and_cm_from_cmid($cmid);
        } catch (Throwable $ex) {
            throw new tool_exception(
                "Course module {$cmid} does not exist.",
                ['cmid' => $cmid]
            );
        }
        /** @var cm_info $cm */
        $courseid = (int) $course->id;
        $modname = (string) $cm->modname;
        $instanceid = (int) $cm->instance;

        // Refresh modinfo in the user's security context.
        $modinfo = get_fast_modinfo($courseid, $userid);
        $cm = $modinfo->cms[$cmid] ?? null;
        if (!$cm) {
            throw new tool_exception(
                "Course module {$cmid} is not visible to you.",
                ['cmid' => $cmid]
            );
        }
        $context = context_module::instance($cmid);
        $isadmin = is_siteadmin($user);
        // Course-access gate FIRST: cm_info::uservisible checks module visibility,
        // availability and mod/<x>:view, but NOT course enrolment or course
        // visibility — and mod/page:view etc. are granted to the 'user' archetype.
        // Without this, any authenticated user could read a module in a course
        // (even a hidden one) they cannot otherwise access. can_access_course()
        // honours enrolment, the course-visible flag and moodle/course:view*.
        if (!$isadmin && !can_access_course($course, $user)) {
            throw new tool_exception(
                "Course module {$cmid} is not visible to you.",
                ['cmid' => $cmid]
            );
        }
        if (!$cm->uservisible && !$isadmin) {
            throw new tool_exception(
                "You do not have permission to view course module {$cmid}.",
                ['cmid' => $cmid]
            );
        }

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf('resource_%d_%d_%d_%d', $userid, $cmid, $chapterid, $maxchars);
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $stringopts = ['context' => $context, 'filter' => false, 'escape' => true];
        $textopts = ['context' => $context, 'filter' => false, 'noclean' => true];

        $payload = [
            'cmid' => $cmid,
            'modname' => $modname,
            'name' => format_string((string) $cm->name, true, $stringopts),
            'course_id' => $courseid,
            'course_name' => format_string((string) $course->fullname, true, $stringopts),
            'url' => $cm->url ? $cm->url->out(false)
                : (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'intro' => '',
            'content' => '',
            'content_truncated' => false,
            'external_url' => '',
            'chapters' => [],
            'files' => [],
            'summary' => '',
        ];

        switch ($modname) {
            case 'page':
                $page = $DB->get_record('page', ['id' => $instanceid], 'id, name, intro, introformat, content, contentformat', IGNORE_MISSING);
                if ($page) {
                    $payload['intro'] = self::plain($page->intro ?? '', (int) ($page->introformat ?? FORMAT_HTML), $textopts);
                    $rendered = self::plain($page->content ?? '', (int) ($page->contentformat ?? FORMAT_HTML), $textopts);
                    [$payload['content'], $payload['content_truncated']] = self::truncate($rendered, $maxchars);
                }
                break;

            case 'book':
                require_once($CFG->dirroot . '/mod/book/locallib.php');
                $book = $DB->get_record('book', ['id' => $instanceid], '*', IGNORE_MISSING);
                $chapters = $DB->get_records('book_chapters', ['bookid' => $instanceid, 'hidden' => 0], 'pagenum ASC');
                if ($book) {
                    $payload['intro'] = self::plain($book->intro ?? '', (int) ($book->introformat ?? FORMAT_HTML), $textopts);
                }
                foreach ($chapters as $chapter) {
                    $payload['chapters'][] = [
                        'id' => (int) $chapter->id,
                        'title' => format_string((string) $chapter->title, true, $stringopts),
                        'pagenum' => (int) ($chapter->pagenum ?? 0),
                        'subchapter' => (bool) ($chapter->subchapter ?? 0),
                    ];
                }
                if ($chapterid > 0 && isset($chapters[$chapterid])) {
                    $c = $chapters[$chapterid];
                    $rendered = self::plain((string) $c->content, (int) $c->contentformat, $textopts);
                    [$payload['content'], $payload['content_truncated']] = self::truncate($rendered, $maxchars);
                } else if ($chapterid > 0) {
                    throw new tool_exception(
                        "Chapter {$chapterid} not found in book {$cmid}.",
                        ['cmid' => $cmid, 'chapter_id' => $chapterid]
                    );
                }
                break;

            case 'label':
                $label = $DB->get_record('label', ['id' => $instanceid], 'id, name, intro, introformat', IGNORE_MISSING);
                if ($label) {
                    $intro = self::plain($label->intro ?? '', (int) ($label->introformat ?? FORMAT_HTML), $textopts);
                    $payload['intro'] = $intro;
                    [$payload['content'], $payload['content_truncated']] = self::truncate($intro, $maxchars);
                }
                break;

            case 'url':
                $url = $DB->get_record('url', ['id' => $instanceid], 'id, name, intro, introformat, externalurl', IGNORE_MISSING);
                if ($url) {
                    $payload['intro'] = self::plain($url->intro ?? '', (int) ($url->introformat ?? FORMAT_HTML), $textopts);
                    $payload['external_url'] = (string) ($url->externalurl ?? '');
                    $payload['content'] = $payload['external_url'];
                }
                break;

            case 'resource':
            case 'folder':
                $intro = self::generic_intro($modname, $instanceid, $textopts);
                $payload['intro'] = $intro;
                $payload['files'] = self::collect_files($context, $modname);
                break;

            default:
                $payload['intro'] = self::generic_intro($modname, $instanceid, $textopts);
                break;
        }

        $payload['summary'] = self::build_summary($modname, $payload, $chapterid);

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_get_resource cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Resolve the intro of an arbitrary module via its mod table.
     *
     * @param string $modname Module shortname.
     * @param int $instanceid Module instance id.
     * @param array<string, mixed> $textopts format_text options.
     * @return string
     */
    private static function generic_intro(string $modname, int $instanceid, array $textopts): string {
        global $DB;
        try {
            $record = $DB->get_record($modname, ['id' => $instanceid], 'id, intro, introformat', IGNORE_MISSING);
            if ($record && !empty($record->intro)) {
                return self::plain((string) $record->intro, (int) ($record->introformat ?? FORMAT_HTML), $textopts);
            }
        } catch (Throwable $ex) {
            // Unknown table or missing intro column. Ignore.
        }
        return '';
    }

    /**
     * Collect attached files (metadata only) for a module context.
     *
     * @param context_module $context Module context.
     * @param string $modname Module shortname.
     * @return array<int, array<string, mixed>>
     */
    private static function collect_files(context_module $context, string $modname): array {
        $component = 'mod_' . $modname;
        $filearea = $modname === 'folder' ? 'content' : 'content';
        $fs = get_file_storage();
        $files = $fs->get_area_files($context->id, $component, $filearea, 0, 'filename ASC', false);
        $out = [];
        foreach ($files as $f) {
            if ($f->is_directory()) {
                continue;
            }
            $url = moodle_url::make_pluginfile_url(
                $context->id,
                $component,
                $filearea,
                (int) ($f->get_itemid() ?: 0),
                $f->get_filepath(),
                $f->get_filename(),
                false
            );
            $out[] = [
                'filename' => $f->get_filename(),
                'mimetype' => (string) $f->get_mimetype(),
                'filesize' => (int) $f->get_filesize(),
                'url' => $url->out(false),
            ];
        }
        return $out;
    }

    /**
     * Convert formatted text to a clean plain-text string.
     *
     * @param string $raw Raw content.
     * @param int $format Text format.
     * @param array<string, mixed> $textopts format_text options.
     * @return string
     */
    private static function plain(string $raw, int $format, array $textopts): string {
        if (trim($raw) === '') {
            return '';
        }
        $formatted = format_text($raw, $format, $textopts);
        return trim(html_to_text($formatted, 0, false));
    }

    /**
     * Truncate text to the configured maximum.
     *
     * @param string $text Plain text.
     * @param int $max Maximum length.
     * @return array{0:string,1:bool} Tuple of truncated text and truncation flag.
     */
    private static function truncate(string $text, int $max): array {
        if (strlen($text) <= $max) {
            return [$text, false];
        }
        return [substr($text, 0, max(0, $max - 3)) . '...', true];
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param string $modname Module shortname.
     * @param array<string, mixed> $payload Built payload.
     * @param int $chapterid Requested chapter id (book only).
     * @return string
     */
    private static function build_summary(string $modname, array $payload, int $chapterid): string {
        switch ($modname) {
            case 'page':
                return $payload['content'] !== ''
                    ? sprintf('Page "%s" (%d chars).', $payload['name'], strlen($payload['content']))
                    : sprintf('Page "%s" has no content.', $payload['name']);
            case 'book':
                $count = count($payload['chapters']);
                if ($chapterid > 0 && $payload['content'] !== '') {
                    return sprintf('Book chapter loaded (%d chars; %d chapters total).', strlen($payload['content']), $count);
                }
                return sprintf('Book "%s" has %d chapters. Pass chapter_id to load a chapter.', $payload['name'], $count);
            case 'label':
                return sprintf('Label "%s" (%d chars).', $payload['name'], strlen($payload['content']));
            case 'url':
                return sprintf('URL "%s": %s', $payload['name'], $payload['external_url']);
            case 'resource':
            case 'folder':
                $files = count($payload['files']);
                return sprintf('%s "%s" with %d %s.', ucfirst($modname), $payload['name'], $files, $files === 1 ? 'file' : 'files');
            default:
                return sprintf('Module "%s" of type %s (intro only).', $payload['name'], $modname);
        }
    }
}
