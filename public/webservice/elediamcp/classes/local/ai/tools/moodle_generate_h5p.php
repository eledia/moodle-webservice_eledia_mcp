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
use moodle_exception;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native H5P generation tool wrapping local_h5pauthor.
 *
 * Only registered when local_h5pauthor is installed (see
 * {@see \webservice_elediamcp\local\ai\registry::all()}); generation runs
 * server-side through the site's core_ai provider.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_generate_h5p implements ai_tool {
    /** @var string[] Supported H5P content type keys. */
    private const TYPES = ['dialogcards', 'blanks', 'singlechoice'];

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_generate_h5p';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Generate H5P content with AI';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Generates interactive H5P content (dialogcards = flashcards, blanks = fill in the '
            . 'blanks, singlechoice = single-choice quiz) about a topic and publishes it to the '
            . 'course content bank or as a course activity. Generation runs SERVER-SIDE through '
            . 'the Moodle site\'s configured AI provider — pass only the topic and parameters, not '
            . 'the content itself. Optionally grounds the content in an existing course activity '
            . 'via source_cmid. Requires local/h5pauthor:use in the course. Two-step flow: first '
            . 'call returns a preview with readiness checks; call again with confirm=true to '
            . 'generate. The confirmed call may take up to 60 seconds.';
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
            'required' => ['course_id', 'type', 'topic'],
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                ],
                'type' => [
                    'type' => 'string',
                    'enum' => self::TYPES,
                    'description' => 'H5P content type: Dialog Cards (flashcards), Fill in the Blanks, '
                        . 'or Single Choice Set quiz.',
                ],
                'topic' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'description' => 'Subject the AI should generate content about.',
                ],
                'title' => [
                    'type' => 'string',
                    'maxLength' => 255,
                    'description' => 'Title of the created item. Defaults to the topic.',
                ],
                'count' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 12,
                    'default' => 8,
                    'description' => 'Number of cards/sentences/questions. Larger counts take longer.',
                ],
                'language' => [
                    'type' => 'string',
                    'description' => 'Language code such as "de" or "en". Defaults to the site language.',
                ],
                'difficulty' => [
                    'type' => 'string',
                    'enum' => ['', 'easy', 'medium', 'hard'],
                    'default' => '',
                ],
                'source_cmid' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional course module id (Page, Book, File, Folder, Lesson) in the '
                        . 'same course to ground the content in. 0 = none.',
                ],
                'target' => [
                    'type' => 'string',
                    'enum' => ['contentbank', 'activity'],
                    'default' => 'contentbank',
                    'description' => 'Where to publish: reusable content bank item or H5P course activity.',
                ],
                'section' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Course section for target=activity.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually generate and publish.',
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
                'item' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'type' => ['type' => 'string', 'description' => 'contentbank or activity.'],
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'course_fullname' => ['type' => 'string'],
                        'content_type' => ['type' => 'string'],
                        'h5p_library' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'target' => ['type' => 'string'],
                        'ai_provider_ready' => ['type' => 'boolean'],
                        'grounding' => ['type' => 'string'],
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
        if (!class_exists('\local_h5pauthor\local\authoring_service')) {
            throw new tool_exception('local_h5pauthor is not installed on this site.');
        }

        $type = trim((string) ($arguments['type'] ?? ''));
        if (!in_array($type, self::TYPES, true)) {
            throw new tool_exception(
                'Invalid type. Supported types: ' . implode(', ', self::TYPES) . '.',
                ['type' => $type]
            );
        }
        $topic = trim((string) ($arguments['topic'] ?? ''));
        if ($topic === '') {
            throw new tool_exception('topic is required.');
        }
        $target = trim((string) ($arguments['target'] ?? 'contentbank')) ?: 'contentbank';
        if (!in_array($target, ['contentbank', 'activity'], true)) {
            throw new tool_exception('Invalid target. Allowed: contentbank, activity.', ['target' => $target]);
        }

        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);
        require_capability('local/h5pauthor:use', $coursecontext, $user->id);
        if ($target === 'activity') {
            require_capability('moodle/course:manageactivities', $coursecontext, $user->id);
        } else {
            require_capability('moodle/contentbank:upload', $coursecontext, $user->id);
        }

        $library = \local_h5pauthor\local\library_checker::installed_dependency($type);
        $machinename = \local_h5pauthor\local\content_type::machine_name($type);
        if ($library === null) {
            throw new tool_exception(
                'The H5P library for type "' . $type . '" (' . $machinename . ') is not installed. '
                . 'An administrator must install it via the H5P content type hub before this tool can be used.',
                ['type' => $type, 'library' => $machinename]
            );
        }

        if (!self::ai_provider_ready()) {
            throw new tool_exception(
                'No AI provider is configured for text generation. An administrator must enable one '
                . 'under Site administration > AI. Do not retry until that is done.'
            );
        }

        $sourcecmid = max(0, (int) ($arguments['source_cmid'] ?? 0));
        $grounding = 'none';
        if ($sourcecmid > 0) {
            $grounding = self::validate_source_cm($course, $sourcecmid);
        }

        $count = isset($arguments['count']) ? max(1, min(12, (int) $arguments['count'])) : 8;
        $title = trim((string) ($arguments['title'] ?? '')) ?: $topic;
        $language = trim((string) ($arguments['language'] ?? ''));
        $difficulty = trim((string) ($arguments['difficulty'] ?? ''));
        if (!in_array($difficulty, ['', 'easy', 'medium', 'hard'], true)) {
            throw new tool_exception('Invalid difficulty. Allowed: easy, medium, hard or empty.');
        }
        $section = max(0, (int) ($arguments['section'] ?? 0));

        $preview = [
            'course_fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
            'content_type' => $type,
            'h5p_library' => $machinename . ' ' . implode('.', $library),
            'count' => $count,
            'target' => $target,
            'ai_provider_ready' => true,
            'grounding' => $grounding,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'item' => null,
                'preview' => $preview,
                'summary' => 'Ready to generate ' . $count . ' ' . $type . ' item(s) about "' . $topic
                    . '" into the ' . $target . ' of ' . $preview['course_fullname']
                    . '. Call again with confirm=true to generate; this may take up to 60 seconds.',
            ];
        }

        try {
            $item = \local_h5pauthor\local\authoring_service::generate(
                $courseid,
                (int) $user->id,
                $type,
                $topic,
                $count,
                $title,
                $target,
                $section,
                $language !== '' ? $language : 'und',
                $sourcecmid > 0 ? $sourcecmid : null,
                $difficulty
            );
        } catch (tool_exception $ex) {
            throw $ex;
        } catch (moodle_exception $ex) {
            throw self::map_exception($ex, $type);
        }

        return [
            'created' => true,
            'requires_confirmation' => false,
            'item' => [
                'type' => (string) $item['type'],
                'id' => (int) $item['id'],
                'name' => (string) $item['name'],
                'url' => (string) $item['url'],
            ],
            'preview' => $preview,
            'summary' => 'Generated H5P ' . $type . ' "' . $item['name'] . '" and published it to the '
                . $target . ' of ' . $preview['course_fullname'] . '.',
        ];
    }

    /**
     * Whether a core_ai text-generation provider is enabled.
     *
     * @return bool
     */
    private static function ai_provider_ready(): bool {
        $manager = \core\di::get(\core_ai\manager::class);
        $providers = $manager->get_providers_for_actions([\core_ai\aiactions\generate_text::class], true);
        return !empty($providers[\core_ai\aiactions\generate_text::class]);
    }

    /**
     * Validate the grounding source course module.
     *
     * @param stdClass $course Course record.
     * @param int $sourcecmid Course module id.
     * @return string Human-readable grounding description for the preview.
     */
    private static function validate_source_cm(stdClass $course, int $sourcecmid): string {
        $modinfo = get_fast_modinfo($course);
        $cms = $modinfo->get_cms();
        if (!isset($cms[$sourcecmid]) || !$cms[$sourcecmid]->uservisible) {
            throw new tool_exception(
                'source_cmid ' . $sourcecmid . ' is not a visible activity in this course. '
                . 'Use moodle_course_contents to list the available activities.',
                ['source_cmid' => $sourcecmid]
            );
        }
        $cm = $cms[$sourcecmid];
        return $cm->modname . ' "' . $cm->get_formatted_name() . '" (cmid ' . $sourcecmid . ')';
    }

    /**
     * Map local_h5pauthor exceptions to LLM-actionable tool exceptions.
     *
     * @param moodle_exception $ex Wrapped exception.
     * @param string $type Requested content type.
     * @return tool_exception
     */
    private static function map_exception(moodle_exception $ex, string $type): tool_exception {
        $context = ['errorcode' => (string) $ex->errorcode];
        switch ($ex->errorcode) {
            case 'error_librarymissing':
                return new tool_exception(
                    'The H5P library for "' . $type . '" is not installed. An administrator must install '
                    . 'it from the H5P content type hub. Do not retry.',
                    $context
                );
            case 'error_aiunavailable':
                return new tool_exception(
                    'No AI provider is configured for text generation. An administrator must enable one '
                    . 'under Site administration > AI. Do not retry.',
                    $context
                );
            case 'error_notopic':
            case 'error_notopicorsource':
                return new tool_exception('A non-empty topic is required. Retry with a topic argument.', $context);
            case 'error_emptyresponse':
            case 'error_badjson':
                return new tool_exception(
                    'The AI returned no usable content. Retry once; if it fails again, simplify the topic '
                    . 'or reduce count.',
                    $context
                );
            case 'error_extract_unsupported':
                return new tool_exception(
                    'Text cannot be extracted from that activity type. Supported source types: Page, Book, '
                    . 'File, Folder, Lesson. Pick a different source_cmid (use moodle_course_contents to '
                    . 'list activities).',
                    $context
                );
            case 'error_unknowntype':
            case 'error_unknowntarget':
                return new tool_exception('Invalid type or target value: ' . $ex->getMessage(), $context);
            default:
                return new tool_exception('H5P generation failed: ' . $ex->getMessage(), $context);
        }
    }
}
