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

use cm_info;
use context_course;
use context_module;
use moodle_exception;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native question generation tool wrapping local_lernhive_questiongen.
 *
 * Only registered when local_lernhive_questiongen is installed (see
 * {@see \webservice_elediamcp\local\ai\registry::all()}); generation runs
 * server-side through the site's core_ai provider and imports the result
 * into a question bank of the course.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_generate_questions implements ai_tool {
    /** @var string[] Supported generation modes. */
    private const MODES = ['topic', 'coursecontents'];

    /** @var string[] Supported question types. */
    private const QUESTION_TYPES = ['multichoice', 'truefalse', 'shortanswer', 'matching', 'ordering'];

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_generate_questions';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Generate quiz questions with AI';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Generates quiz questions and imports them into a question bank of the course. '
            . 'Generation runs SERVER-SIDE through the Moodle site\'s configured AI provider — pass '
            . 'only the topic or a source activity, never the question content itself. Modes: '
            . 'topic (free-text topic) or coursecontents (ground the questions in an existing '
            . 'course activity via source_cmid). Requires local/lernhive_questiongen:use and '
            . 'moodle/question:add on the target question bank. Two-step flow: first call returns '
            . 'a preview with the resolved question bank and readiness checks; call again with '
            . 'confirm=true to generate. The confirmed call may take up to 60 seconds.';
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
            'required' => ['course_id', 'mode'],
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'mode' => [
                    'type' => 'string',
                    'enum' => self::MODES,
                    'description' => 'topic: generate from a free-text topic. coursecontents: ground the '
                        . 'generation in an existing course activity (requires source_cmid).',
                ],
                'topic' => [
                    'type' => 'string',
                    'description' => 'Required when mode=topic.',
                ],
                'source_cmid' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Required when mode=coursecontents. Page, Book, File, Folder or '
                        . 'Lesson in the same course.',
                ],
                'count' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'default' => 4,
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Language code such as "de" or "en". Defaults to the site language.',
                ],
                'question_types' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'default' => ['multichoice'],
                    'items' => [
                        'type' => 'string',
                        'enum' => self::QUESTION_TYPES,
                    ],
                ],
                'multichoice_correct_mode' => [
                    'type' => 'string',
                    'enum' => ['single', 'multiple', 'auto'],
                    'default' => 'single',
                ],
                'multichoice_correct_count' => [
                    'type' => 'integer',
                    'minimum' => 2,
                    'maximum' => 4,
                    'default' => 2,
                    'description' => 'Number of correct options when multichoice_correct_mode=multiple.',
                ],
                'qbank_cmid' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional course module id of the target question bank (mod_qbank). '
                        . 'If omitted, the first usable question bank in the course is chosen; if none '
                        . 'exists one is created on confirm (requires moodle/course:manageactivities).',
                ],
                'category_id' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional question category id inside the chosen question bank. '
                        . 'Defaults to the bank\'s default category.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually generate and import.',
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
                'result' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'imported' => ['type' => 'integer'],
                        'skipped' => ['type' => 'integer'],
                        'category_id' => ['type' => 'integer'],
                        'category_name' => ['type' => 'string'],
                        'qbank_cmid' => ['type' => 'integer'],
                        'qbank_name' => ['type' => 'string'],
                        'question_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'qbank_cmid' => ['type' => 'integer'],
                        'qbank_name' => ['type' => 'string'],
                        'qbank_action' => ['type' => 'string', 'description' => 'use or create.'],
                        'category_id' => ['type' => 'integer'],
                        'category_name' => ['type' => 'string'],
                        'mode' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'question_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                        'ai_provider_ready' => ['type' => 'boolean'],
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
        global $CFG, $DB;

        if (!class_exists('\local_lernhive_questiongen\local\generator')) {
            throw new tool_exception('local_lernhive_questiongen is not installed on this site.');
        }

        $mode = trim((string) ($arguments['mode'] ?? ''));
        if (!in_array($mode, self::MODES, true)) {
            throw new tool_exception('Invalid mode. Supported modes: ' . implode(', ', self::MODES) . '.');
        }

        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        $topic = trim((string) ($arguments['topic'] ?? ''));
        $sourcecmid = max(0, (int) ($arguments['source_cmid'] ?? 0));
        if ($mode === 'topic' && $topic === '') {
            throw new tool_exception('topic is required when mode=topic.');
        }
        if ($mode === 'coursecontents') {
            if ($sourcecmid <= 0) {
                throw new tool_exception('source_cmid is required when mode=coursecontents.');
            }
            self::validate_source_cm($course, $sourcecmid);
        }

        $questiontypes = self::validate_question_types($arguments);
        $count = isset($arguments['count']) ? max(1, min(10, (int) $arguments['count'])) : 4;
        $language = trim((string) ($arguments['language'] ?? ''));
        $mcmode = trim((string) ($arguments['multichoice_correct_mode'] ?? 'single')) ?: 'single';
        if (!in_array($mcmode, ['single', 'multiple', 'auto'], true)) {
            throw new tool_exception('Invalid multichoice_correct_mode. Allowed: single, multiple, auto.');
        }
        $mccount = isset($arguments['multichoice_correct_count'])
            ? max(2, min(4, (int) $arguments['multichoice_correct_count']))
            : 2;

        // Resolve the target question bank (mod_qbank instance).
        [$qbankcm, $qbankaction] = self::resolve_qbank($course, (int) ($arguments['qbank_cmid'] ?? 0), $user);
        if ($qbankaction === 'create') {
            require_capability('moodle/course:manageactivities', $coursecontext, $user->id);
        }

        // Resolve the target question category inside the bank.
        require_once($CFG->libdir . '/questionlib.php');
        $categoryid = max(0, (int) ($arguments['category_id'] ?? 0));
        $categoryname = '';
        if ($categoryid > 0) {
            if ($qbankcm === null) {
                throw new tool_exception('category_id cannot be used when no question bank exists yet. Omit it.');
            }
            $modcontext = context_module::instance($qbankcm->id);
            $category = $DB->get_record('question_categories', ['id' => $categoryid], 'id, contextid, name');
            if (!$category || (int) $category->contextid !== (int) $modcontext->id) {
                throw new tool_exception(
                    'category_id ' . $categoryid . ' does not belong to the chosen question bank.',
                    ['category_id' => $categoryid, 'qbank_cmid' => (int) $qbankcm->id]
                );
            }
            $categoryname = (string) $category->name;
        } else if ($qbankcm !== null) {
            $modcontext = context_module::instance($qbankcm->id);
            $default = question_get_default_category((int) $modcontext->id);
            if ($default) {
                $categoryid = (int) $default->id;
                $categoryname = (string) $default->name;
            } else {
                $categoryname = 'Default (will be created)';
            }
        } else {
            $categoryname = 'Default (will be created)';
        }

        // Fail early when no AI provider serves text generation.
        try {
            \local_lernhive_questiongen\local\generator::require_text_provider();
        } catch (moodle_exception $ex) {
            throw self::map_exception($ex);
        }

        $preview = [
            'qbank_cmid' => $qbankcm !== null ? (int) $qbankcm->id : 0,
            'qbank_name' => $qbankcm !== null
                ? $qbankcm->get_formatted_name()
                : format_string($course->shortname) . ' question bank (will be created)',
            'qbank_action' => $qbankaction,
            'category_id' => $categoryid,
            'category_name' => $categoryname,
            'mode' => $mode,
            'count' => $count,
            'question_types' => $questiontypes,
            'ai_provider_ready' => true,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'result' => null,
                'preview' => $preview,
                'summary' => 'Ready to generate ' . $count . ' question(s) (' . implode(', ', $questiontypes)
                    . ') into question bank "' . $preview['qbank_name'] . '". Call again with confirm=true '
                    . 'to generate; this may take up to 60 seconds.',
            ];
        }

        // Create the question bank module if the course has none yet.
        if ($qbankcm === null) {
            $qbankcm = self::create_question_bank($course);
        }
        $modcontext = context_module::instance($qbankcm->id);
        if ($categoryid <= 0) {
            $default = question_get_default_category((int) $modcontext->id, true);
            if (!$default) {
                throw new tool_exception('Could not resolve a default question category for the bank.');
            }
            $categoryid = (int) $default->id;
            $categoryname = (string) $default->name;
        }

        try {
            $result = \local_lernhive_questiongen\local\generator::generate((object) [
                'mode' => $mode,
                'topic' => $topic,
                'sourcecmid' => $sourcecmid,
                'count' => $count,
                'language' => $language !== '' ? $language : current_language(),
                'questiontypes' => $questiontypes,
                'multichoicecorrectmode' => $mcmode,
                'multichoicecorrectcount' => $mccount,
                'categoryid' => $categoryid,
                'contextid' => (int) $modcontext->id,
                'courseid' => $courseid,
                'userid' => (int) $user->id,
            ]);
        } catch (tool_exception $ex) {
            throw $ex;
        } catch (moodle_exception $ex) {
            throw self::map_exception($ex);
        }

        if ((int) $result->imported === 0) {
            throw new tool_exception(
                'The AI response contained no importable questions. Retry with a lower count or fewer '
                . 'question types (multichoice is most reliable).',
                ['skipped' => (int) $result->skipped]
            );
        }

        return [
            'created' => true,
            'requires_confirmation' => false,
            'result' => [
                'imported' => (int) $result->imported,
                'skipped' => (int) $result->skipped,
                'category_id' => $categoryid,
                'category_name' => $categoryname,
                'qbank_cmid' => (int) $qbankcm->id,
                'qbank_name' => $qbankcm->get_formatted_name(),
                'question_names' => self::imported_question_names($categoryid, (int) $result->imported),
            ],
            'preview' => $preview,
            'summary' => 'Imported ' . (int) $result->imported . ' question(s) into "'
                . $qbankcm->get_formatted_name() . '"'
                . ((int) $result->skipped > 0 ? ' (' . (int) $result->skipped . ' skipped)' : '') . '.',
        ];
    }

    /**
     * Validate and normalise the question_types argument.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @return string[]
     */
    private static function validate_question_types(array $arguments): array {
        $types = $arguments['question_types'] ?? ['multichoice'];
        if (!is_array($types) || $types === []) {
            throw new tool_exception('question_types must be a non-empty array.');
        }
        $normalised = [];
        foreach ($types as $type) {
            $type = trim((string) $type);
            if (!in_array($type, self::QUESTION_TYPES, true)) {
                throw new tool_exception(
                    'Invalid question type "' . $type . '". Allowed: ' . implode(', ', self::QUESTION_TYPES) . '.'
                );
            }
            $normalised[$type] = $type;
        }
        return array_values($normalised);
    }

    /**
     * Validate the grounding source course module.
     *
     * @param stdClass $course Course record.
     * @param int $sourcecmid Course module id.
     */
    private static function validate_source_cm(stdClass $course, int $sourcecmid): void {
        $cms = get_fast_modinfo($course)->get_cms();
        if (!isset($cms[$sourcecmid]) || !$cms[$sourcecmid]->uservisible) {
            throw new tool_exception(
                'source_cmid ' . $sourcecmid . ' is not a visible activity in this course. '
                . 'Use moodle_course_contents to list the available activities.',
                ['source_cmid' => $sourcecmid]
            );
        }
    }

    /**
     * Resolve the target mod_qbank instance for the course.
     *
     * @param stdClass $course Course record.
     * @param int $qbankcmid Explicit qbank course module id, 0 for auto.
     * @param stdClass $user Acting user.
     * @return array{0: cm_info|null, 1: string} The bank (or null) and the action (use|create).
     */
    private static function resolve_qbank(stdClass $course, int $qbankcmid, stdClass $user): array {
        $modinfo = get_fast_modinfo($course);
        $banks = $modinfo->get_instances_of('qbank');

        if ($qbankcmid > 0) {
            foreach ($banks as $cm) {
                if ((int) $cm->id === $qbankcmid) {
                    self::require_bank_capabilities($cm, $user);
                    return [$cm, 'use'];
                }
            }
            throw new tool_exception(
                'qbank_cmid ' . $qbankcmid . ' is not a question bank in this course.',
                ['qbank_cmid' => $qbankcmid]
            );
        }

        foreach ($banks as $cm) {
            $modcontext = context_module::instance($cm->id);
            $canuse = has_capability('local/lernhive_questiongen:use', $modcontext, $user->id)
                && has_capability('moodle/question:add', $modcontext, $user->id);
            if ($canuse) {
                return [$cm, 'use'];
            }
        }

        return [null, 'create'];
    }

    /**
     * Require the questiongen capabilities on a question bank module.
     *
     * @param cm_info $cm Question bank course module.
     * @param stdClass $user Acting user.
     */
    private static function require_bank_capabilities(cm_info $cm, stdClass $user): void {
        $modcontext = context_module::instance($cm->id);
        require_capability('local/lernhive_questiongen:use', $modcontext, $user->id);
        require_capability('moodle/question:add', $modcontext, $user->id);
    }

    /**
     * Create a default open question bank instance in the course.
     *
     * @param stdClass $course Course record.
     * @return cm_info
     */
    private static function create_question_bank(stdClass $course): cm_info {
        if (!class_exists('\core_question\local\bank\question_bank_helper')) {
            throw new tool_exception(
                'This Moodle version has no shared question bank support. Create a question bank '
                . 'activity manually and pass its cmid as qbank_cmid.'
            );
        }
        $bankname = shorten_text(format_string($course->shortname) . ' question bank', 100);
        return \core_question\local\bank\question_bank_helper::create_default_open_instance($course, $bankname);
    }

    /**
     * Fetch the names of the most recently imported questions in a category.
     *
     * @param int $categoryid Question category id.
     * @param int $limit Number of questions to fetch.
     * @return string[]
     */
    private static function imported_question_names(int $categoryid, int $limit): array {
        global $DB;

        $records = $DB->get_records_sql(
            'SELECT q.id, q.name
               FROM {question_bank_entries} qbe
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
              WHERE qbe.questioncategoryid = :categoryid
           ORDER BY q.id DESC',
            ['categoryid' => $categoryid],
            0,
            $limit
        );

        $names = array_map(static fn(stdClass $record): string => (string) $record->name, array_values($records));
        return array_reverse($names);
    }

    /**
     * Map local_lernhive_questiongen exceptions to LLM-actionable tool exceptions.
     *
     * @param moodle_exception $ex Wrapped exception.
     * @return tool_exception
     */
    private static function map_exception(moodle_exception $ex): tool_exception {
        $context = ['errorcode' => (string) $ex->errorcode];
        switch ($ex->errorcode) {
            case 'error_aiunavailable':
                return new tool_exception(
                    'No AI provider is configured for text generation. An administrator must enable one '
                    . 'under Site administration > AI. Do not retry.',
                    $context
                );
            case 'error_notopic':
            case 'error_nostory':
                return new tool_exception(
                    'The generation source was empty. Retry with a non-empty topic or a source_cmid whose '
                    . 'activity contains readable text.',
                    $context
                );
            case 'error_emptyresponse':
                return new tool_exception(
                    'The AI returned no usable content. Retry once; if it fails again, simplify the topic '
                    . 'or reduce count.',
                    $context
                );
            case 'error_importfailed':
                return new tool_exception(
                    'The AI output could not be parsed into valid Moodle questions. Retry with a lower '
                    . 'count or fewer question types (multichoice is most reliable).',
                    $context
                );
            case 'error_extract_unsupported':
                return new tool_exception(
                    'Text cannot be extracted from that activity type. Supported source types: Page, Book, '
                    . 'File, Folder, Lesson. Pick a different source_cmid (use moodle_course_contents to '
                    . 'list activities).',
                    $context
                );
            default:
                return new tool_exception('Question generation failed: ' . $ex->getMessage(), $context);
        }
    }
}
