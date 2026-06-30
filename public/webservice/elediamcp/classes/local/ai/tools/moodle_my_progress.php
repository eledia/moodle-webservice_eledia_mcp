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

use completion_info;
use context_system;
use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native completion / learning-progress overview.
 *
 * Reports the authenticated user's completion progress per enrolled course
 * (percentage plus completed/total activity counts) and, for a single course,
 * the per-activity completion states. Designed for "how far am I?", "what's
 * left to do in this course?" and tutor-style progress coaching queries.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_my_progress implements ai_tool {
    /**
     * Map of completion state values to API labels.
     *
     * Literal values (not the COMPLETION_* constants): this constant is
     * evaluated when the class is loaded for tools/list introspection, where
     * lib/completionlib.php is not included yet. The values mirror
     * COMPLETION_INCOMPLETE/COMPLETE/COMPLETE_PASS/COMPLETE_FAIL.
     *
     * @var array<int,string>
     */
    private const STATE_LABELS = [
        0 => 'notcompleted',
        1 => 'complete',
        2 => 'complete_pass',
        3 => 'complete_fail',
    ];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_my_progress';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'My course completion progress';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the authenticated user\'s completion progress for their enrolled courses: '
            . 'progress percentage and completed/total activity counts per course (null when the '
            . 'course has completion tracking disabled). Pass course_id with '
            . 'include_activities=true to additionally list every tracked activity with its '
            . 'completion state (notcompleted, complete, complete_pass, complete_fail). Use to '
            . 'answer "how far am I?", "what is left to do?" and to coach next steps.';
    }

    /**
     * Input schema.
     *
     * @return array<string,mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'course_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Restrict to a single course id.',
                ],
                'include_activities' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include per-activity completion states (requires course_id).',
                ],
            ],
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string,mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['courses', 'summary'],
            'properties' => [
                'courses' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['course_id', 'course_name', 'completion_enabled', 'url'],
                        'properties' => [
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'completion_enabled' => ['type' => 'boolean'],
                            'progress_percent' => ['type' => ['number', 'null']],
                            'completed_count' => ['type' => ['integer', 'null']],
                            'total_count' => ['type' => ['integer', 'null']],
                            'course_completed' => ['type' => ['boolean', 'null']],
                            'url' => ['type' => 'string'],
                        ],
                    ],
                ],
                'activities' => [
                    'type' => 'array',
                    'description' => 'Per-activity states; only with course_id + include_activities.',
                    'items' => [
                        'type' => 'object',
                        'required' => ['cmid', 'name', 'modname', 'state', 'url'],
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'modname' => ['type' => 'string'],
                            'section' => ['type' => 'integer'],
                            'state' => ['type' => 'string',
                                'enum' => array_values(self::STATE_LABELS)],
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
     * @return array<string,mixed>
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
     * @param array $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $userid = (int) $user->id;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $includeactivities = !empty($arguments['include_activities']);

        if ($includeactivities && $courseidfilter <= 0) {
            throw new tool_exception(
                'include_activities requires a course_id.',
                ['hint' => 'Pass course_id to list per-activity completion states.']
            );
        }

        $usercourses = enrol_get_users_courses($userid, true, ['id', 'fullname', 'enablecompletion']);
        if ($courseidfilter > 0) {
            if (!isset($usercourses[$courseidfilter])) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseidfilter}.",
                    ['course_id' => $courseidfilter]
                );
            }
            $usercourses = [$courseidfilter => $usercourses[$courseidfilter]];
        }

        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $courses = [];
        $activities = [];
        $trackedcourses = 0;

        foreach ($usercourses as $course) {
            $completion = new completion_info($course);
            $enabled = $completion->is_enabled() != COMPLETION_DISABLED;

            $entry = [
                'course_id' => (int) $course->id,
                'course_name' => format_string((string) $course->fullname, true, $stringopts),
                'completion_enabled' => $enabled,
                'progress_percent' => null,
                'completed_count' => null,
                'total_count' => null,
                'course_completed' => null,
                'url' => (new moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false),
            ];

            if ($enabled) {
                $trackedcourses++;
                $modinfo = get_fast_modinfo($course, $userid);
                $completed = 0;
                $total = 0;
                foreach ($modinfo->get_cms() as $cm) {
                    if (
                        !$cm->uservisible
                            || (int) $completion->is_enabled($cm) === COMPLETION_TRACKING_NONE
                    ) {
                        continue;
                    }
                    $total++;
                    $state = (int) $completion->get_data($cm, false, $userid)->completionstate;
                    $iscomplete = in_array(
                        $state,
                        [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS],
                        true
                    );
                    if ($iscomplete) {
                        $completed++;
                    }
                    if ($includeactivities && $courseidfilter > 0) {
                        $activities[] = [
                            'cmid' => (int) $cm->id,
                            'name' => format_string((string) $cm->name, true, $stringopts),
                            'modname' => (string) $cm->modname,
                            'section' => (int) $cm->sectionnum,
                            'state' => self::STATE_LABELS[$state] ?? 'notcompleted',
                            'url' => $cm->url ? $cm->url->out(false) : '',
                        ];
                    }
                }
                $entry['completed_count'] = $completed;
                $entry['total_count'] = $total;
                $entry['progress_percent'] = $total > 0
                    ? round($completed * 100 / $total, 1) : null;
                $entry['course_completed'] = $completion->is_course_complete($userid);
            }

            $courses[] = $entry;
        }

        $payload = [
            'courses' => $courses,
            'summary' => self::build_summary($courses, $trackedcourses, $courseidfilter),
        ];
        if ($includeactivities && $courseidfilter > 0) {
            $payload['activities'] = $activities;
        }
        return $payload;
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param array $courses Course entries.
     * @param int $trackedcourses Courses with completion enabled.
     * @param int $courseidfilter Course filter (0 = none).
     * @return string
     */
    private static function build_summary(array $courses, int $trackedcourses, int $courseidfilter): string {
        if (empty($courses)) {
            return 'No enrolled courses found.';
        }
        if ($courseidfilter > 0) {
            $entry = $courses[0];
            if (!$entry['completion_enabled']) {
                return sprintf('"%s" has completion tracking disabled.', $entry['course_name']);
            }
            return sprintf(
                '"%s": %d of %d tracked activities completed (%s%%).',
                $entry['course_name'],
                (int) $entry['completed_count'],
                (int) $entry['total_count'],
                $entry['progress_percent'] ?? 0
            );
        }
        return sprintf(
            'Progress for %d enrolled course(s), %d with completion tracking enabled.',
            count($courses),
            $trackedcourses
        );
    }
}
