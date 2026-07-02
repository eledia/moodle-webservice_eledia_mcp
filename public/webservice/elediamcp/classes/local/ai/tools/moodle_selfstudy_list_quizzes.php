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

use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Student tool: list own self-study quizzes with progress.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_selfstudy_list_quizzes implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_selfstudy_list_quizzes';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'List my practice quizzes';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Lists the authenticated user\'s personal self-study quizzes, newest first, with '
            . 'attempt counts and best scores. Optionally filtered by course. Use the quiz_id with '
            . 'moodle_selfstudy_get_quiz to fetch questions.';
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
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Optional course filter. Omit to list across all courses.',
                ],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => 20],
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
            'required' => ['quizzes', 'summary'],
            'properties' => [
                'quizzes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'quiz_id' => ['type' => 'integer'],
                            'course_id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'topic' => ['type' => 'string'],
                            'question_count' => ['type' => 'integer'],
                            'language' => ['type' => 'string'],
                            'attempts' => ['type' => 'integer'],
                            'best_score' => ['type' => ['integer', 'null']],
                            'time_created' => ['type' => 'integer'],
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

        $courseid = max(0, (int) ($arguments['course_id'] ?? 0));
        $limit = isset($arguments['limit']) ? max(1, min(50, (int) $arguments['limit'])) : 20;

        $quizzes = \local_lernhive_selfstudy\local\quiz_service::list_for_user((int) $user->id, $courseid, $limit);

        return [
            'quizzes' => $quizzes,
            'summary' => count($quizzes) === 0
                ? 'No personal practice quizzes yet. Create one with moodle_selfstudy_create_quiz.'
                : count($quizzes) . ' personal practice quiz(zes) found.',
        ];
    }
}
