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
 * Student tool: generate a personal self-study quiz.
 *
 * Only registered when local_lernhive_selfstudy is installed. Runs as the
 * authenticated (student) user; teacher opt-in and daily quotas are enforced
 * by the wrapped plugin.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_selfstudy_create_quiz implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_selfstudy_create_quiz';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Create a personal practice quiz';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Generates a personal practice quiz for the authenticated user about a topic, '
            . 'grounded in course content when source_cmid is given. Generation runs SERVER-SIDE '
            . 'through the Moodle site\'s AI provider — pass only the topic, never questions. '
            . 'The quiz is private to the user (no course activity, no question bank entry); '
            . 'results feed Moodle competencies as evidence. Requires the teacher to have enabled '
            . 'self-study quizzes in the course; a per-student daily limit applies. Two-step flow: '
            . 'first call returns a preview with readiness checks; call again with confirm=true. '
            . 'Afterwards use moodle_selfstudy_get_quiz to fetch the questions and quiz the user.';
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
            'required' => ['course_id', 'topic'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'topic' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'description' => 'Learning goal, e.g. "wie Scrum-Sprints geplant werden".',
                ],
                'count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10, 'default' => 5],
                'language' => [
                    'type' => 'string',
                    'description' => 'Language code such as "de". Defaults to the user\'s language.',
                ],
                'source_cmid' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Optional course module (Page, Book, File, Folder, Lesson) to ground '
                        . 'the questions in. 0 = topic only.',
                ],
                'title' => ['type' => 'string', 'maxLength' => 255, 'description' => 'Defaults to the topic.'],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually generate. Generation may take up to 60 seconds.',
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
                'quiz' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'quiz_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'question_count' => ['type' => 'integer'],
                        'competencies_tagged' => ['type' => 'integer'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'course_fullname' => ['type' => 'string'],
                        'topic' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'quota_remaining_today' => ['type' => 'integer'],
                        'ai_provider_ready' => ['type' => 'boolean'],
                        'competencies_available' => ['type' => 'integer'],
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
        if (!class_exists('\local_lernhive_selfstudy\local\quiz_service')) {
            throw new tool_exception('local_lernhive_selfstudy is not installed on this site.');
        }

        $courseid = (int) ($arguments['course_id'] ?? 0);
        $topic = trim((string) ($arguments['topic'] ?? ''));
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        if ($topic === '') {
            throw new tool_exception('topic is required.');
        }
        $count = isset($arguments['count']) ? max(1, min(10, (int) $arguments['count'])) : 5;
        $language = trim((string) ($arguments['language'] ?? ''));
        $sourcecmid = max(0, (int) ($arguments['source_cmid'] ?? 0));
        $title = trim((string) ($arguments['title'] ?? ''));

        $course = get_course($courseid);
        $coursecontext = context_course::instance($courseid);

        try {
            \local_lernhive_selfstudy\local\course_settings::require_enabled_for($courseid, (int) $user->id);
            \local_lernhive_selfstudy\local\generator::require_text_provider();
        } catch (moodle_exception $ex) {
            throw selfstudy_helper::map_exception($ex);
        }

        $grounding = 'none';
        if ($sourcecmid > 0) {
            $grounding = selfstudy_helper::validate_source_cm($course, $sourcecmid);
        }

        $remaining = \local_lernhive_selfstudy\local\quota::remaining((int) $user->id, $courseid);
        if ($remaining <= 0) {
            throw new tool_exception(
                'The daily limit of self-study quizzes in this course is reached. Try again tomorrow.',
                ['quota_remaining_today' => 0]
            );
        }

        $preview = [
            'course_fullname' => format_string($course->fullname, true, ['context' => $coursecontext]),
            'topic' => $topic,
            'count' => $count,
            'quota_remaining_today' => $remaining,
            'ai_provider_ready' => true,
            'competencies_available' => count(
                \local_lernhive_selfstudy\local\competency_bridge::course_competencies($courseid)
            ),
            'grounding' => $grounding,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'quiz' => null,
                'preview' => $preview,
                'summary' => 'Ready to generate a personal ' . $count . '-question practice quiz about "'
                    . $topic . '" (' . $remaining . ' generation(s) left today). Call again with '
                    . 'confirm=true; this may take up to 60 seconds.',
            ];
        }

        try {
            $quiz = \local_lernhive_selfstudy\local\quiz_service::create(
                $courseid,
                (int) $user->id,
                $topic,
                $count,
                $language,
                $sourcecmid,
                $title
            );
        } catch (tool_exception $ex) {
            throw $ex;
        } catch (moodle_exception $ex) {
            throw selfstudy_helper::map_exception($ex);
        }

        return [
            'created' => true,
            'requires_confirmation' => false,
            'quiz' => [
                'quiz_id' => (int) $quiz->id,
                'title' => (string) $quiz->title,
                'question_count' => (int) $quiz->questioncount,
                'competencies_tagged' => (int) $quiz->taggedcount,
            ],
            'preview' => $preview,
            'summary' => 'Created personal quiz "' . $quiz->title . '" with ' . $quiz->questioncount
                . ' questions. Fetch them with moodle_selfstudy_get_quiz (quiz_id ' . $quiz->id
                . ') and quiz the user one question at a time.',
        ];
    }
}
