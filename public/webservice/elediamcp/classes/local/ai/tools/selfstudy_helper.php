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
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Shared helpers for the self-study MCP tools.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class selfstudy_helper {
    /**
     * Map local_lernhive_selfstudy exceptions to LLM-actionable tool exceptions.
     *
     * @param moodle_exception $ex Wrapped exception.
     * @return tool_exception
     */
    public static function map_exception(moodle_exception $ex): tool_exception {
        $context = ['errorcode' => (string) $ex->errorcode];
        switch ($ex->errorcode) {
            case 'error_notenabled':
                return new tool_exception(
                    'Self-study quizzes are not enabled in this course. A teacher must enable them '
                    . 'first (course navigation > Self-study quizzes). Do not retry until then.',
                    $context
                );
            case 'error_quotaexceeded':
                return new tool_exception(
                    'The daily limit of self-study quizzes in this course is reached. Try again tomorrow.',
                    $context
                );
            case 'error_aiunavailable':
                return new tool_exception(
                    'No AI provider is configured for text generation. An administrator must enable one '
                    . 'under Site administration > AI. Do not retry.',
                    $context
                );
            case 'error_notopic':
                return new tool_exception('A non-empty topic is required. Retry with a topic argument.', $context);
            case 'error_emptyresponse':
            case 'error_badjson':
            case 'error_noquestions':
                return new tool_exception(
                    'The AI returned no usable questions. Retry once; if it fails again, simplify the '
                    . 'topic or reduce count.',
                    $context
                );
            case 'error_notyourquiz':
                return new tool_exception(
                    'This quiz belongs to another user. Use moodle_selfstudy_list_quizzes to find your own.',
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
                return new tool_exception('Self-study operation failed: ' . $ex->getMessage(), $context);
        }
    }

    /**
     * Validate the grounding source course module.
     *
     * @param stdClass $course Course record.
     * @param int $sourcecmid Course module id.
     * @return string Human-readable grounding description for previews.
     */
    public static function validate_source_cm(stdClass $course, int $sourcecmid): string {
        $cms = get_fast_modinfo($course)->get_cms();
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
}
