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

use context_course;
use context_module;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native course activity creation tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_create_activity implements ai_tool {
    /** @var string[] Supported activity/resource types. */
    private const TYPES = ['page', 'label', 'url', 'book', 'assign'];

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_create_activity';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Create a course activity';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Creates an activity or resource in a course section. Supported types and their '
            . 'required fields: page (content = HTML body), label (intro = text shown on the course '
            . 'page, name is derived from it), url (external_url), book (chapters = array of '
            . '{title, content}), assign (optional duedate, grade and submission settings). '
            . 'All types except label require name. Requires moodle/course:manageactivities plus '
            . 'mod/<type>:addinstance in the course. Two-step flow: first call returns a preview; '
            . 'call again with confirm=true to create. The target section must already exist.';
    }

    /**
     * Return the JSON schema for accepted arguments.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['course_id', 'type'],
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => self::TYPES,
                    'description' => 'Activity type to create.',
                ],
                'name' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Display name. Required for all types except label.',
                ],
                'section' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Existing course section number. Sections are never created.',
                ],
                'intro' => [
                    'type' => 'string',
                    'description' => 'HTML description. For type=label this is the displayed content (required).',
                ],
                'visible' => [
                    'type' => 'boolean',
                    'default' => true,
                ],
                'idnumber' => [
                    'type' => 'string',
                    'maxLength' => 100,
                    'description' => 'Optional course module idnumber.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'HTML page body. Required for type=page.',
                ],
                'external_url' => [
                    'type' => 'string',
                    'description' => 'Target URL. Required for type=url.',
                ],
                'chapters' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Book chapters in order. Required for type=book.',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['title', 'content'],
                        'properties' => [
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                            'content' => ['type' => 'string', 'minLength' => 1, 'description' => 'Chapter HTML body.'],
                        ],
                    ],
                ],
                'duedate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'assign only: due date as Unix timestamp, 0 = none.',
                ],
                'allowsubmissionsfromdate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'assign only: submissions allowed from, Unix timestamp, 0 = always.',
                ],
                'cutoffdate' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'assign only: cut-off date, Unix timestamp, 0 = none.',
                ],
                'grade' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'maximum' => 100,
                    'default' => 100,
                    'description' => 'assign only: maximum points, 0 = no grade.',
                ],
                'onlinetext_enabled' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'assign only: accept online text submissions.',
                ],
                'filesubmission_enabled' => [
                    'type' => 'boolean',
                    'default' => true,
                    'description' => 'assign only: accept file submissions.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually create the activity.',
                ],
            ],
        ];
    }

    /**
     * Return the JSON schema for tool output.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['created', 'requires_confirmation', 'summary'],
            'properties' => [
                'created' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'activity' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'cmid' => ['type' => 'integer'],
                        'instance_id' => ['type' => 'integer'],
                        'type' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'course_id' => ['type' => 'integer'],
                        'section' => ['type' => 'integer'],
                        'visible' => ['type' => 'boolean'],
                        'url' => ['type' => 'string'],
                        'chapters_created' => ['type' => 'integer'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'type' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'course_id' => ['type' => 'integer'],
                        'course_fullname' => ['type' => 'string'],
                        'section' => ['type' => 'integer'],
                        'section_name' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'details' => ['type' => 'object'],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Return MCP annotations for this tool.
     *
     * @return array<string, mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ];
    }

    /**
     * Execute the tool for the authenticated user.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $user Authenticated Moodle user.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG, $DB, $PAGE;

        $type = trim((string) ($arguments['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new tool_exception(
                'Invalid type. Supported types: ' . implode(', ', self::TYPES) . '.',
                ['type' => $type]
            );
        }

        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        if ($courseid === SITEID) {
            throw new tool_exception('Activities cannot be created on the site home course.');
        }
        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $coursecontext, $user->id);
        require_capability('mod/' . $type . ':addinstance', $coursecontext, $user->id);

        $module = $DB->get_record('modules', ['name' => $type]);
        if ($module === false || empty($module->visible)) {
            throw new tool_exception('The ' . $type . ' module is disabled on this site.', ['type' => $type]);
        }

        $section = max(0, (int) ($arguments['section'] ?? 0));
        $modinfo = get_fast_modinfo($course);
        if ($modinfo->get_section_info($section) === null) {
            $maxsection = count($modinfo->get_section_info_all()) - 1;
            throw new tool_exception(
                'Section ' . $section . ' does not exist in this course. Valid sections: 0..' . $maxsection . '.',
                ['section' => $section, 'max_section' => $maxsection]
            );
        }

        $name = trim((string) ($arguments['name'] ?? ''));
        $intro = trim((string) ($arguments['intro'] ?? ''));
        if ($type !== 'label' && $name === '') {
            throw new tool_exception('name is required for type ' . $type . '.');
        }
        if ($type === 'label' && $intro === '') {
            throw new tool_exception('intro is required for type label; it is the displayed content.');
        }

        $visible = array_key_exists('visible', $arguments) ? (bool) $arguments['visible'] : true;
        $details = self::validate_type_arguments($type, $arguments);

        $sectioninfo = $modinfo->get_section_info($section);
        $preview = [
            'type' => $type,
            'name' => $type === 'label' ? shorten_text(html_to_text($intro), 50) : $name,
            'course_id' => $courseid,
            'course_fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
            'section' => $section,
            'section_name' => get_section_name($course, $sectioninfo),
            'visible' => $visible,
            'details' => $details,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'activity' => null,
                'preview' => $preview,
                'summary' => 'Ready to create ' . $type . ' "' . $preview['name'] . '" in section '
                    . $section . ' of ' . $preview['course_fullname'] . '. Call again with confirm=true to create.',
            ];
        }

        $moduleinfo = self::build_moduleinfo($type, $arguments, $course, $section, $name, $intro, $visible);

        require_once($CFG->dirroot . '/course/lib.php');
        $PAGE->set_context($coursecontext);
        try {
            $created = create_module($moduleinfo);
        } catch (Throwable $ex) {
            throw new tool_exception('Activity creation failed: ' . $ex->getMessage(), $preview);
        }

        $cmid = (int) $created->coursemodule;
        $instanceid = (int) $created->instance;
        $chapterscreated = 0;
        if ($type === 'book') {
            $chapterscreated = self::create_book_chapters($instanceid, $cmid, (array) $arguments['chapters']);
        }

        $finalname = $type === 'label'
            ? (string) $DB->get_field('label', 'name', ['id' => $instanceid])
            : $name;
        $activityurl = $type === 'label'
            ? (new moodle_url('/course/view.php', ['id' => $courseid]))->out(false) . '#module-' . $cmid
            : (new moodle_url('/mod/' . $type . '/view.php', ['id' => $cmid]))->out(false);

        return [
            'created' => true,
            'requires_confirmation' => false,
            'activity' => [
                'cmid' => $cmid,
                'instance_id' => $instanceid,
                'type' => $type,
                'name' => $finalname,
                'course_id' => $courseid,
                'section' => $section,
                'visible' => $visible,
                'url' => $activityurl,
                'chapters_created' => $chapterscreated,
            ],
            'preview' => $preview,
            'summary' => 'Created ' . $type . ' "' . $finalname . '" in section ' . $section
                . ' of ' . $preview['course_fullname'] . '.'
                . ($type === 'book' ? ' ' . $chapterscreated . ' chapters added.' : ''),
        ];
    }

    /**
     * Validate the type-specific arguments and return preview details.
     *
     * @param string $type Activity type.
     * @param array<string, mixed> $arguments Tool arguments.
     * @return array<string, mixed> Preview details.
     */
    private static function validate_type_arguments(string $type, array $arguments): array {
        global $CFG;

        switch ($type) {
            case 'page':
                $content = trim((string) ($arguments['content'] ?? ''));
                if ($content === '') {
                    throw new tool_exception('content is required for type page.');
                }
                return ['content_length' => strlen($content)];

            case 'label':
                return ['intro_excerpt' => shorten_text(html_to_text((string) $arguments['intro']), 120)];

            case 'url':
                $externalurl = trim((string) ($arguments['external_url'] ?? ''));
                if ($externalurl === '') {
                    throw new tool_exception('external_url is required for type url.');
                }
                require_once($CFG->dirroot . '/mod/url/locallib.php');
                $fixedurl = url_fix_submitted_url($externalurl);
                if (!url_appears_valid_url($fixedurl)) {
                    throw new tool_exception('external_url is not a valid URL.', ['external_url' => $externalurl]);
                }
                return ['external_url' => $fixedurl];

            case 'book':
                $chapters = $arguments['chapters'] ?? null;
                if (!is_array($chapters) || $chapters === []) {
                    throw new tool_exception('chapters is required for type book and must be a non-empty array.');
                }
                $titles = [];
                foreach (array_values($chapters) as $index => $chapter) {
                    $title = is_array($chapter) ? trim((string) ($chapter['title'] ?? '')) : '';
                    $content = is_array($chapter) ? trim((string) ($chapter['content'] ?? '')) : '';
                    if ($title === '' || $content === '') {
                        throw new tool_exception(
                            'Chapter ' . ($index + 1) . ' must have a non-empty title and content.',
                            ['chapter_index' => $index + 1]
                        );
                    }
                    $titles[] = $title;
                }
                return ['chapter_count' => count($titles), 'chapter_titles' => $titles];

            case 'assign':
                $duedate = max(0, (int) ($arguments['duedate'] ?? 0));
                $allowfrom = max(0, (int) ($arguments['allowsubmissionsfromdate'] ?? 0));
                $cutoff = max(0, (int) ($arguments['cutoffdate'] ?? 0));
                if ($duedate > 0 && $allowfrom > 0 && $duedate < $allowfrom) {
                    throw new tool_exception('duedate must not be before allowsubmissionsfromdate.');
                }
                if ($cutoff > 0 && $duedate > 0 && $cutoff < $duedate) {
                    throw new tool_exception('cutoffdate must not be before duedate.');
                }
                return [
                    'duedate' => $duedate,
                    'allowsubmissionsfromdate' => $allowfrom,
                    'cutoffdate' => $cutoff,
                    'grade' => self::assign_grade($arguments),
                    'onlinetext_enabled' => self::flag($arguments, 'onlinetext_enabled', true),
                    'filesubmission_enabled' => self::flag($arguments, 'filesubmission_enabled', true),
                ];

            default:
                return [];
        }
    }

    /**
     * Build the moduleinfo object consumed by create_module().
     *
     * @param string $type Activity type.
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $course Course record.
     * @param int $section Section number.
     * @param string $name Display name.
     * @param string $intro Intro HTML.
     * @param bool $visible Visibility flag.
     * @return stdClass
     */
    private static function build_moduleinfo(
        string $type,
        array $arguments,
        stdClass $course,
        int $section,
        string $name,
        string $intro,
        bool $visible
    ): stdClass {
        global $CFG;

        require_once($CFG->libdir . '/resourcelib.php');

        $moduleinfo = new stdClass();
        $moduleinfo->modulename = $type;
        $moduleinfo->course = (int) $course->id;
        $moduleinfo->section = $section;
        $moduleinfo->visible = $visible ? 1 : 0;
        $moduleinfo->visibleoncoursepage = 1;
        // For label an empty name makes label_add_instance() derive it from the intro.
        $moduleinfo->name = $name;
        $moduleinfo->introeditor = ['text' => $intro, 'format' => FORMAT_HTML, 'itemid' => 0];
        $moduleinfo->cmidnumber = trim((string) ($arguments['idnumber'] ?? ''));

        switch ($type) {
            case 'page':
                $moduleinfo->content = (string) $arguments['content'];
                $moduleinfo->contentformat = FORMAT_HTML;
                $moduleinfo->display = RESOURCELIB_DISPLAY_OPEN;
                $moduleinfo->printintro = 0;
                $moduleinfo->printlastmodified = 1;
                break;

            case 'label':
                // Labels display the intro; the name is derived from it in label_add_instance().
                break;

            case 'url':
                $moduleinfo->externalurl = url_fix_submitted_url((string) $arguments['external_url']);
                $moduleinfo->display = RESOURCELIB_DISPLAY_AUTO;
                $moduleinfo->printintro = 1;
                break;

            case 'book':
                $moduleinfo->numbering = 1; // BOOK_NUM_NUMBERS.
                $moduleinfo->customtitles = 0;
                break;

            case 'assign':
                self::apply_assign_fields($moduleinfo, $arguments);
                break;
        }

        return $moduleinfo;
    }

    /**
     * Apply the assign-specific moduleinfo fields.
     *
     * assign::add_instance() reads most of these unconditionally, so every
     * field must be present (defaults follow the mod_assign generator).
     *
     * @param stdClass $moduleinfo Moduleinfo being built.
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private static function apply_assign_fields(stdClass $moduleinfo, array $arguments): void {
        $moduleinfo->duedate = max(0, (int) ($arguments['duedate'] ?? 0));
        $moduleinfo->allowsubmissionsfromdate = max(0, (int) ($arguments['allowsubmissionsfromdate'] ?? 0));
        $moduleinfo->cutoffdate = max(0, (int) ($arguments['cutoffdate'] ?? 0));
        $moduleinfo->gradingduedate = 0;
        $moduleinfo->grade = self::assign_grade($arguments);
        $moduleinfo->alwaysshowdescription = 1;
        $moduleinfo->submissiondrafts = 0;
        $moduleinfo->requiresubmissionstatement = 0;
        $moduleinfo->sendnotifications = 0;
        $moduleinfo->sendstudentnotifications = 1;
        $moduleinfo->sendlatenotifications = 0;
        $moduleinfo->teamsubmission = 0;
        $moduleinfo->requireallteammemberssubmit = 0;
        $moduleinfo->teamsubmissiongroupingid = 0;
        $moduleinfo->blindmarking = 0;
        $moduleinfo->attemptreopenmethod = 'untilpass';
        $moduleinfo->maxattempts = 1;
        $moduleinfo->markingworkflow = 0;
        $moduleinfo->markingallocation = 0;
        $moduleinfo->markinganonymous = 0;
        $moduleinfo->timelimit = 0;
        $moduleinfo->submissionattachments = 0;

        $onlinetext = self::flag($arguments, 'onlinetext_enabled', true);
        $filesubmission = self::flag($arguments, 'filesubmission_enabled', true);
        $moduleinfo->assignsubmission_onlinetext_enabled = $onlinetext ? 1 : 0;
        $moduleinfo->assignsubmission_file_enabled = $filesubmission ? 1 : 0;
        if ($filesubmission) {
            $moduleinfo->assignsubmission_file_maxfiles = 20;
            $moduleinfo->assignsubmission_file_maxsizebytes = 0;
        }
    }

    /**
     * Insert the book chapters after the book instance has been created.
     *
     * There is no core API for chapter creation; this replicates the insert
     * performed by mod/book/edit.php.
     *
     * @param int $bookid Book instance id.
     * @param int $cmid Course module id.
     * @param array<int, array<string, string>> $chapters Chapter definitions.
     * @return int Number of chapters created.
     */
    private static function create_book_chapters(int $bookid, int $cmid, array $chapters): int {
        global $DB;

        $book = $DB->get_record('book', ['id' => $bookid], '*', MUST_EXIST);
        $context = context_module::instance($cmid);
        $now = time();
        $pagenum = 0;

        $transaction = $DB->start_delegated_transaction();
        foreach (array_values($chapters) as $chapterdata) {
            $pagenum++;
            $chapter = (object) [
                'bookid' => $bookid,
                'pagenum' => $pagenum,
                'subchapter' => 0,
                'title' => trim((string) $chapterdata['title']),
                'content' => (string) $chapterdata['content'],
                'contentformat' => FORMAT_HTML,
                'hidden' => 0,
                'importsrc' => '',
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $chapter->id = $DB->insert_record('book_chapters', $chapter);
            \mod_book\event\chapter_created::create_from_chapter($book, $context, $chapter)->trigger();
        }
        $DB->set_field('book', 'revision', (int) $book->revision + 1, ['id' => $bookid]);
        $transaction->allow_commit();

        return $pagenum;
    }

    /**
     * Resolve the assign grade argument.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @return int
     */
    private static function assign_grade(array $arguments): int {
        return isset($arguments['grade']) ? max(0, min(100, (int) $arguments['grade'])) : 100;
    }

    /**
     * Read a boolean argument with a default.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param string $key Argument key.
     * @param bool $default Default value.
     * @return bool
     */
    private static function flag(array $arguments, string $key, bool $default): bool {
        return array_key_exists($key, $arguments) ? (bool) $arguments[$key] : $default;
    }
}
