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

use moodle_exception;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Student tool: fetch the questions of an own quiz, without solutions.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_selfstudy_get_quiz implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_selfstudy_get_quiz';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Get my practice quiz questions';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the questions of one of the authenticated user\'s personal practice quizzes '
            . 'WITHOUT the solutions. Present the questions to the user one at a time, collect their '
            . 'chosen option indexes, then submit everything with moodle_selfstudy_submit_attempt. '
            . 'Never guess or reveal answers yourself; the correct answers and feedback come back '
            . 'from the submit call.';
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
            'required' => ['quiz_id'],
            'properties' => [
                'quiz_id' => ['type' => 'integer', 'minimum' => 1],
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
            'required' => ['quiz', 'questions', 'summary'],
            'properties' => [
                'quiz' => [
                    'type' => 'object',
                    'properties' => [
                        'quiz_id' => ['type' => 'integer'],
                        'course_id' => ['type' => 'integer'],
                        'title' => ['type' => 'string'],
                        'topic' => ['type' => 'string'],
                        'language' => ['type' => 'string'],
                    ],
                ],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question_id' => ['type' => 'integer'],
                            'sortorder' => ['type' => 'integer'],
                            'type' => ['type' => 'string'],
                            'question' => ['type' => 'string'],
                            'options' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'competency_id' => ['type' => 'integer'],
                        ],
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
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
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

        $quizid = (int) ($arguments['quiz_id'] ?? 0);
        if ($quizid <= 0) {
            throw new tool_exception('quiz_id is required.');
        }

        try {
            $quiz = \local_lernhive_selfstudy\local\quiz_service::get_owned($quizid, (int) $user->id);
        } catch (moodle_exception $ex) {
            throw selfstudy_helper::map_exception($ex);
        }
        $questions = \local_lernhive_selfstudy\local\quiz_service::questions($quizid, false);

        return [
            'quiz' => [
                'quiz_id' => (int) $quiz->id,
                'course_id' => (int) $quiz->courseid,
                'title' => (string) $quiz->title,
                'topic' => (string) $quiz->topic,
                'language' => (string) $quiz->language,
            ],
            'questions' => $questions,
            'summary' => count($questions) . ' questions. Quiz the user one question at a time, '
                . 'collect option indexes (0-based), then call moodle_selfstudy_submit_attempt.',
        ];
    }
}
