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

use assign;
use context_module;
use core_user;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Teacher tool: grade one student's assignment submission.
 *
 * When the assignment uses marking workflow, the grade is stored in the
 * "In review" state so the teacher releases it from the Moodle UI; without
 * marking workflow the grade becomes visible to the student immediately.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_grade_submission implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_grade_submission';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Grade a student submission';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Sets the grade and an optional feedback comment for one student on an assignment. '
            . 'Only point-based grading is supported (no scales, no advanced grading forms). If the '
            . 'assignment uses marking workflow, the grade is stored as "In review" and the teacher '
            . 'releases it in Moodle; otherwise it is visible to the student immediately (the '
            . 'preview states which case applies). Requires mod/assign:grade. Two-step flow: first '
            . 'call returns a preview; call again with confirm=true. Only grade after the user has '
            . 'seen and approved the concrete grade and feedback text.';
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
            'required' => ['cmid', 'user_id', 'grade'],
            'properties' => [
                'cmid' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Course module id of the assignment.',
                ],
                'user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'The student to grade.',
                ],
                'grade' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'description' => 'Points between 0 and the assignment maximum.',
                ],
                'feedback' => [
                    'type' => 'string',
                    'description' => 'Optional feedback comment shown to the student (plain text or HTML).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually save the grade.',
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
            'required' => ['graded', 'requires_confirmation', 'summary'],
            'properties' => [
                'graded' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'result' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'grade' => ['type' => 'number'],
                        'max_grade' => ['type' => 'number'],
                        'workflow_state' => ['type' => 'string',
                            'description' => 'inreview when marking workflow holds the grade back, else released.'],
                        'feedback_saved' => ['type' => 'boolean'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'assignment_name' => ['type' => 'string'],
                        'student_fullname' => ['type' => 'string'],
                        'current_grade' => ['type' => ['number', 'null']],
                        'new_grade' => ['type' => 'number'],
                        'max_grade' => ['type' => 'number'],
                        'feedback_excerpt' => ['type' => 'string'],
                        'marking_workflow' => ['type' => 'boolean'],
                        'visibility_note' => ['type' => 'string'],
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
            'idempotentHint' => true,
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
        global $CFG;

        $cmid = (int) ($arguments['cmid'] ?? 0);
        $studentid = (int) ($arguments['user_id'] ?? 0);
        if ($cmid <= 0 || $studentid <= 0) {
            throw new tool_exception('cmid and user_id are required.');
        }
        if (!isset($arguments['grade']) || !is_numeric($arguments['grade'])) {
            throw new tool_exception('grade is required and must be numeric.');
        }
        $grade = (float) $arguments['grade'];
        $feedback = trim((string) ($arguments['feedback'] ?? ''));

        $cm = get_coursemodule_from_id('assign', $cmid, 0, false, MUST_EXIST);
        $course = get_course((int) $cm->course);
        $context = context_module::instance($cmid);
        require_capability('mod/assign:grade', $context, $user->id);
        $student = core_user::get_user($studentid, '*', MUST_EXIST);

        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        $assign = new assign($context, $cm, $course);
        $instance = $assign->get_instance();

        $maxgrade = (float) $instance->grade;
        if ($maxgrade <= 0) {
            throw new tool_exception(
                'This assignment does not use point grading (scale or no grade). '
                . 'Only point-based assignments can be graded with this tool.'
            );
        }
        if ($grade < 0 || $grade > $maxgrade) {
            throw new tool_exception(
                'grade must be between 0 and ' . $maxgrade . ' for this assignment.',
                ['max_grade' => $maxgrade]
            );
        }
        require_once($CFG->dirroot . '/grade/grading/lib.php');
        $gradingmanager = get_grading_manager($context, 'mod_assign', 'submissions');
        if ((string) $gradingmanager->get_active_method() !== '') {
            // Advanced grading (rubric/guide) needs criterion fillings we do not support.
            throw new tool_exception(
                'This assignment uses an advanced grading form (rubric or marking guide), '
                . 'which this tool does not support. Grade it in the Moodle UI.'
            );
        }

        $markingworkflow = !empty($instance->markingworkflow);
        $graderecord = $assign->get_user_grade($studentid, false);
        $currentgrade = null;
        if ($graderecord && $graderecord->grade !== null && (float) $graderecord->grade >= 0) {
            $currentgrade = (float) $graderecord->grade;
        }

        $feedbackplugin = $assign->get_feedback_plugin_by_type('comments');
        $feedbackenabled = $feedbackplugin && $feedbackplugin->is_enabled() && $feedbackplugin->is_visible();

        $preview = [
            'assignment_name' => format_string($instance->name, true, ['context' => $context]),
            'student_fullname' => fullname($student),
            'current_grade' => $currentgrade,
            'new_grade' => $grade,
            'max_grade' => $maxgrade,
            'feedback_excerpt' => shorten_text(html_to_text($feedback, 0, false), 200),
            'marking_workflow' => $markingworkflow,
            'visibility_note' => $markingworkflow
                ? 'Marking workflow is enabled: the grade will be stored as "In review" and must be '
                    . 'released by a teacher in Moodle before students see it.'
                : 'No marking workflow: the grade and feedback become visible to the student immediately.',
        ];

        if (empty($arguments['confirm'])) {
            return [
                'graded' => false,
                'requires_confirmation' => true,
                'result' => null,
                'preview' => $preview,
                'summary' => 'Ready to grade ' . fullname($student) . ' with ' . $grade . '/' . $maxgrade
                    . ' on "' . $preview['assignment_name'] . '". ' . $preview['visibility_note']
                    . ' Call again with confirm=true to save.',
            ];
        }

        $data = new stdClass();
        $data->grade = $grade;
        $data->attemptnumber = -1;
        $data->addattempt = false;
        $data->applytoall = false;
        $data->workflowstate = $markingworkflow ? ASSIGN_MARKING_WORKFLOW_STATE_INREVIEW : '';
        if ($feedback !== '' && $feedbackenabled) {
            $data->assignfeedbackcomments_editor = [
                'text' => $feedback,
                'format' => FORMAT_HTML,
            ];
        }

        try {
            $assign->save_grade($studentid, $data);
        } catch (Throwable $ex) {
            throw new tool_exception('Saving the grade failed: ' . $ex->getMessage());
        }

        return [
            'graded' => true,
            'requires_confirmation' => false,
            'result' => [
                'grade' => $grade,
                'max_grade' => $maxgrade,
                'workflow_state' => $markingworkflow ? 'inreview' : 'released',
                'feedback_saved' => $feedback !== '' && $feedbackenabled,
            ],
            'preview' => $preview,
            'summary' => 'Graded ' . fullname($student) . ' with ' . $grade . '/' . $maxgrade . ' on "'
                . $preview['assignment_name'] . '".'
                . ($feedback !== '' && !$feedbackenabled
                    ? ' Note: the feedback comments plugin is disabled, the feedback text was NOT saved.'
                    : '')
                . ' ' . $preview['visibility_note'],
        ];
    }
}
