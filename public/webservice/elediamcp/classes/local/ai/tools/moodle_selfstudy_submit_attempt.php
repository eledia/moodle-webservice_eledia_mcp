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
 * Student tool: submit answers for an own quiz and get graded feedback.
 *
 * Intentionally has no confirm step: submitting the answers the user just
 * gave is the explicit intent of the conversation, the action only touches
 * the user's own data, and a second round-trip would break the quiz flow.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_selfstudy_submit_attempt implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_selfstudy_submit_attempt';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Submit practice quiz answers';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Grades the user\'s answers to one of their personal practice quizzes, stores the '
            . 'attempt and records competency evidence in Moodle (flagging competencies for teacher '
            . 'review once the user performs consistently well). Answers are 0-based option indexes '
            . 'as presented by moodle_selfstudy_get_quiz. Returns per-question results with the '
            . 'correct options and feedback — walk the user through their mistakes afterwards. '
            . 'Only submit answers the user actually gave; never invent answers.';
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
            'required' => ['quiz_id', 'answers'],
            'properties' => [
                'quiz_id' => ['type' => 'integer', 'minimum' => 1],
                'answers' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['question_id', 'answer_index'],
                        'properties' => [
                            'question_id' => ['type' => 'integer', 'minimum' => 1],
                            'answer_index' => [
                                'type' => 'integer',
                                'minimum' => 0,
                                'description' => '0-based index into the question options.',
                            ],
                        ],
                    ],
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
            'required' => ['submitted', 'score', 'summary'],
            'properties' => [
                'submitted' => ['type' => 'boolean'],
                'attempt_id' => ['type' => 'integer'],
                'score' => ['type' => 'integer', 'description' => 'Percent 0-100.'],
                'correct_count' => ['type' => 'integer'],
                'question_count' => ['type' => 'integer'],
                'questions' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'question_id' => ['type' => 'integer'],
                            'chosen' => ['type' => 'integer'],
                            'correct_option' => ['type' => 'integer'],
                            'is_correct' => ['type' => 'boolean'],
                            'feedback' => ['type' => 'string'],
                        ],
                    ],
                ],
                'competencies' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'competency_id' => ['type' => 'integer'],
                            'correct' => ['type' => 'integer'],
                            'total' => ['type' => 'integer'],
                            'recommended_for_review' => ['type' => 'boolean'],
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
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
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
        if (!class_exists('\local_lernhive_selfstudy\local\attempt_service')) {
            throw new tool_exception('local_lernhive_selfstudy is not installed on this site.');
        }

        $quizid = (int) ($arguments['quiz_id'] ?? 0);
        if ($quizid <= 0) {
            throw new tool_exception('quiz_id is required.');
        }
        $rawanswers = $arguments['answers'] ?? null;
        if (!is_array($rawanswers) || $rawanswers === []) {
            throw new tool_exception('answers is required and must be a non-empty array.');
        }
        $answers = [];
        foreach ($rawanswers as $answer) {
            if (!is_array($answer) || !isset($answer['question_id'], $answer['answer_index'])) {
                throw new tool_exception('Each answer needs question_id and answer_index.');
            }
            $answers[(int) $answer['question_id']] = (int) $answer['answer_index'];
        }

        try {
            $result = \local_lernhive_selfstudy\local\attempt_service::submit($quizid, (int) $user->id, $answers);
        } catch (moodle_exception $ex) {
            throw selfstudy_helper::map_exception($ex);
        }

        $recommended = array_filter($result['competencies'], static fn(array $c): bool => $c['recommended_for_review']);
        $summary = 'Scored ' . $result['score'] . '% (' . $result['correct_count'] . '/'
            . $result['question_count'] . ' correct). Review the wrong answers with the user using '
            . 'the feedback provided.';
        if ($recommended !== []) {
            $summary .= ' ' . count($recommended) . ' competency(ies) were flagged for teacher review '
                . 'based on the user\'s track record — mention this achievement.';
        }

        return [
            'submitted' => true,
            'attempt_id' => $result['attempt_id'],
            'score' => $result['score'],
            'correct_count' => $result['correct_count'],
            'question_count' => $result['question_count'],
            'questions' => $result['questions'],
            'competencies' => $result['competencies'],
            'summary' => $summary,
        ];
    }
}
