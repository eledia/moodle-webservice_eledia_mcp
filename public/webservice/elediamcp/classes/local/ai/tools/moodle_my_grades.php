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

use cache;
use context_course;
use context_system;
use grade_grade;
use grade_item;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native gradebook overview.
 *
 * Returns the authenticated user's course-final grade across each enrolled
 * course (defaults) or, when include_items is set, the per-item grade
 * breakdown for a single course. Hidden grades are respected for non-admins.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_my_grades implements ai_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_my_grades';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'My grades across courses';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns the authenticated user\'s course-final grade across each enrolled course. '
            . 'Set include_items=true with a course_id to receive the per-grade-item breakdown for '
            . 'that course (limited to items the user is allowed to see). Hidden grade items are '
            . 'omitted for non-admin users.';
    }

    /**
     * Input schema.
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
                    'description' => 'Restrict to a single course. Required when include_items=true.',
                ],
                'include_items' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Include per-grade-item breakdown for the chosen course.',
                ],
            ],
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string, mixed>
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
                        'required' => ['course_id', 'course_name', 'final_grade_str'],
                        'properties' => [
                            'course_id' => ['type' => 'integer'],
                            'course_name' => ['type' => 'string'],
                            'final_grade' => ['type' => ['number', 'null']],
                            'final_grade_max' => ['type' => ['number', 'null']],
                            'final_grade_str' => ['type' => 'string'],
                            'final_grade_long' => ['type' => 'string'],
                            'pass_grade' => ['type' => ['number', 'null']],
                            'has_passed' => ['type' => ['boolean', 'null']],
                            'feedback_excerpt' => ['type' => 'string'],
                            'url' => ['type' => 'string'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['item_id', 'name', 'grade_str'],
                                    'properties' => [
                                        'item_id' => ['type' => 'integer'],
                                        'name' => ['type' => 'string'],
                                        'item_type' => ['type' => 'string'],
                                        'item_module' => ['type' => 'string'],
                                        'grade' => ['type' => ['number', 'null']],
                                        'grade_max' => ['type' => ['number', 'null']],
                                        'grade_str' => ['type' => 'string'],
                                        'graded_iso' => ['type' => ['string', 'null']],
                                    ],
                                ],
                            ],
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
     * Execute the tool.
     *
     * @param array<string, mixed> $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG;

        $userid = (int) $user->id;
        $courseidfilter = isset($arguments['course_id']) ? (int) $arguments['course_id'] : 0;
        $includeitems = !empty($arguments['include_items']);
        if ($includeitems && $courseidfilter <= 0) {
            throw new tool_exception('include_items=true requires course_id.');
        }

        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->dirroot . '/grade/querylib.php');

        $cache = cache::make('webservice_elediamcp', 'responses');
        $cachekey = sprintf('grades_%d_%d_%d', $userid, $courseidfilter, $includeitems ? 1 : 0);
        $cached = $cache->get($cachekey);
        if (is_array($cached)) {
            return $cached;
        }

        $usercourses = enrol_get_users_courses($userid, true, ['id', 'fullname']);
        if ($courseidfilter > 0) {
            $usercourses = array_filter($usercourses, static fn($c) => (int) $c->id === $courseidfilter);
            if (empty($usercourses) && !is_siteadmin($user)) {
                throw new tool_exception(
                    "You are not enrolled in course {$courseidfilter}.",
                    ['course_id' => $courseidfilter]
                );
            }
        }
        if (empty($usercourses)) {
            return [
                'courses' => [],
                'summary' => 'No enrolled courses with a gradebook.',
            ];
        }

        $isadmin = is_siteadmin($user);
        $stringopts = ['context' => context_system::instance(), 'filter' => false, 'escape' => true];
        $coursesout = [];
        $passed = 0;
        $failed = 0;
        $ungraded = 0;

        foreach ($usercourses as $course) {
            $courseid = (int) $course->id;
            $coursename = format_string((string) $course->fullname, true, $stringopts);
            $coursecontext = context_course::instance($courseid);
            $canview = $isadmin || has_capability('moodle/grade:view', $coursecontext, $userid);

            $finalgrade = null;
            $finalmax = null;
            $finalstr = '-';
            $finallong = '-';
            $passgrade = null;
            $haspassed = null;
            $feedback = '';

            if ($canview) {
                $info = grade_get_course_grade($userid, $courseid);
                if ($info && !empty($info->item)) {
                    $finalmax = isset($info->item->grademax) ? (float) $info->item->grademax : null;
                    $passgrade = isset($info->item->gradepass) && $info->item->gradepass > 0
                        ? (float) $info->item->gradepass : null;
                    if ($info->grade !== false && $info->grade !== null) {
                        $finalgrade = (float) $info->grade;
                        $finalstr = (string) ($info->str_grade ?? '-');
                        $finallong = (string) ($info->str_long_grade ?? $finalstr);
                        if ($passgrade !== null) {
                            $haspassed = $finalgrade >= $passgrade;
                        }
                    } else {
                        // Grade === false means needsupdate / not yet calculated.
                        $rawstr = (string) ($info->str_grade ?? '-');
                        $errorstr = get_string('error');
                        $finalstr = ($rawstr === $errorstr || $rawstr === '-')
                            ? 'Not yet calculated' : $rawstr;
                        $finallong = (string) ($info->str_long_grade ?? $finalstr);
                        if ($finallong === $errorstr) {
                            $finallong = 'Course grade has not been calculated yet.';
                        }
                    }
                    if (!empty($info->feedback)) {
                        $feedback = self::excerpt(
                            (string) $info->feedback,
                            (int) ($info->feedbackformat ?? FORMAT_HTML)
                        );
                    }
                }
            } else {
                $finalstr = 'access denied';
                $finallong = 'You do not have permission to view grades in this course.';
            }

            if ($haspassed === true) {
                $passed++;
            } else if ($haspassed === false) {
                $failed++;
            } else if ($finalgrade === null) {
                $ungraded++;
            }

            $entry = [
                'course_id' => $courseid,
                'course_name' => $coursename,
                'final_grade' => $finalgrade,
                'final_grade_max' => $finalmax,
                'final_grade_str' => $finalstr,
                'final_grade_long' => $finallong,
                'pass_grade' => $passgrade,
                'has_passed' => $haspassed,
                'feedback_excerpt' => $feedback,
                'url' => (new moodle_url('/grade/report/user/index.php', ['id' => $courseid]))->out(false),
            ];

            if ($includeitems && $courseidfilter === $courseid && $canview) {
                $entry['items'] = self::collect_items($courseid, $userid, $isadmin, $stringopts);
            }

            $coursesout[] = $entry;
        }

        $payload = [
            'courses' => $coursesout,
            'summary' => self::build_summary(count($coursesout), $passed, $failed, $ungraded, $courseidfilter),
        ];

        try {
            $cache->set($cachekey, $payload);
        } catch (Throwable $ex) {
            debugging('moodle_my_grades cache write failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
        return $payload;
    }

    /**
     * Collect the per-item grade breakdown for a single course.
     *
     * @param int $courseid Course id.
     * @param int $userid User id.
     * @param bool $isadmin Whether the user is a site admin.
     * @param array<string, mixed> $stringopts format_string options.
     * @return array<int, array<string, mixed>>
     */
    private static function collect_items(int $courseid, int $userid, bool $isadmin, array $stringopts): array {
        $items = grade_item::fetch_all(['courseid' => $courseid]);
        if (empty($items)) {
            return [];
        }
        $out = [];
        foreach ($items as $item) {
            // Skip course total (already exposed as final_grade) and category aggregates here.
            if ($item->itemtype === 'course') {
                continue;
            }
            if (!$isadmin && $item->is_hidden()) {
                continue;
            }
            $grade = new grade_grade(['itemid' => (int) $item->id, 'userid' => $userid]);
            $grade->grade_item = $item;
            $value = $grade->finalgrade;
            if ($value === null && $grade->rawgrade !== null) {
                $value = $grade->rawgrade;
            }
            $gradeval = $value !== null ? (float) $value : null;
            $grademax = isset($item->grademax) ? (float) $item->grademax : null;
            $gradestr = $value !== null ? grade_format_gradevalue($value, $item) : '-';

            $out[] = [
                'item_id' => (int) $item->id,
                'name' => format_string((string) ($item->get_name() ?? ''), true, $stringopts),
                'item_type' => (string) ($item->itemtype ?? ''),
                'item_module' => (string) ($item->itemmodule ?? ''),
                'grade' => $gradeval,
                'grade_max' => $grademax,
                'grade_str' => (string) $gradestr,
                'graded_iso' => $grade->timemodified ? gmdate('c', (int) $grade->timemodified) : null,
            ];
        }
        return $out;
    }

    /**
     * Build a plain-text excerpt of grade feedback.
     *
     * @param string $feedback Raw feedback.
     * @param int $format Text format.
     * @return string
     */
    private static function excerpt(string $feedback, int $format): string {
        $opts = ['context' => context_system::instance(), 'filter' => false, 'noclean' => true];
        $formatted = format_text($feedback, $format, $opts);
        $plain = trim(html_to_text($formatted, 0, false));
        if ($plain === '') {
            return '';
        }
        if (\core_text::strlen($plain) > 300) {
            $plain = \core_text::substr($plain, 0, 297) . '...';
        }
        return $plain;
    }

    /**
     * Build a one-sentence summary for the LLM.
     *
     * @param int $total Total courses inspected.
     * @param int $passed Courses with a pass grade reached.
     * @param int $failed Courses below the pass grade.
     * @param int $ungraded Courses with no grade yet.
     * @param int $courseidfilter Course filter (0 = none).
     * @return string
     */
    private static function build_summary(int $total, int $passed, int $failed, int $ungraded, int $courseidfilter): string {
        if ($total === 0) {
            return 'No grade information available.';
        }
        if ($total === 1 && $courseidfilter > 0) {
            if ($passed > 0) {
                return 'Pass grade reached in the requested course.';
            }
            if ($failed > 0) {
                return 'Pass grade not yet reached in the requested course.';
            }
            return $ungraded > 0
                ? 'No final grade recorded yet for the requested course.'
                : 'Grade information available for the requested course.';
        }
        return sprintf(
            'Grades across %d courses: %d passed, %d below pass, %d not yet graded.',
            $total,
            $passed,
            $failed,
            $ungraded
        );
    }
}
