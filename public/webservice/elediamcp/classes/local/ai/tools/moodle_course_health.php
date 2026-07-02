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
use grade_item;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Teacher tool: read-only course health report.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_course_health implements ai_tool {
    /** @var int Maximum inactive students listed by name. */
    private const INACTIVE_LIST_CAP = 20;

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_course_health';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Course health report';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Read-only teacher report for one course: student count, students inactive for N '
            . 'days (never accessed or no recent access), per-assignment submission and grading '
            . 'coverage, and the course total grade summary. Requires '
            . 'moodle/course:viewparticipants and moodle/grade:viewall in the course. Good starting '
            . 'point before moodle_grading_queue (what needs grading) or '
            . 'moodle_message_course_students (nudge inactive or missing students).';
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
            'required' => ['course_id'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'inactive_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'default' => 14,
                    'description' => 'A student counts as inactive without course access in this many days.',
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
            'required' => ['student_count', 'inactive', 'assignments', 'summary'],
            'properties' => [
                'student_count' => ['type' => 'integer'],
                'inactive_count' => ['type' => 'integer'],
                'inactive' => [
                    'type' => 'array',
                    'description' => 'Capped list of inactive students.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'fullname' => ['type' => 'string'],
                            'last_access' => ['type' => 'integer', 'description' => '0 = never.'],
                        ],
                    ],
                ],
                'assignments' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'cmid' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'duedate' => ['type' => 'integer'],
                            'submitted' => ['type' => 'integer'],
                            'graded' => ['type' => 'integer'],
                            'missing' => ['type' => 'integer'],
                        ],
                    ],
                ],
                'grade_summary' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'graded_students' => ['type' => 'integer'],
                        'average' => ['type' => ['number', 'null']],
                        'min' => ['type' => ['number', 'null']],
                        'max' => ['type' => ['number', 'null']],
                        'grade_max' => ['type' => 'number'],
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
        global $CFG, $DB;

        $courseid = (int) ($arguments['course_id'] ?? 0);
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        $inactivedays = isset($arguments['inactive_days'])
            ? max(1, min(365, (int) $arguments['inactive_days']))
            : 14;

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        require_capability('moodle/course:viewparticipants', $context, $user->id);
        require_capability('moodle/grade:viewall', $context, $user->id);

        // Students = active enrolled users who can submit assignments.
        $students = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.id, u.firstname, u.lastname,
            u.firstnamephonetic, u.lastnamephonetic, u.middlename, u.alternatename', null, 0, 0, true);
        $studentids = array_map(static fn(stdClass $student): int => (int) $student->id, $students);
        $studentcount = count($studentids);

        // Inactivity via per-course last access.
        $cutoff = time() - $inactivedays * DAYSECS;
        $lastaccess = [];
        if ($studentids !== []) {
            [$insql, $params] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);
            $params['courseid'] = $courseid;
            $lastaccess = $DB->get_records_select_menu(
                'user_lastaccess',
                "courseid = :courseid AND userid $insql",
                $params,
                '',
                'userid, timeaccess'
            );
        }
        $inactive = [];
        foreach ($students as $student) {
            $access = (int) ($lastaccess[$student->id] ?? 0);
            if ($access < $cutoff) {
                $inactive[] = [
                    'id' => (int) $student->id,
                    'fullname' => fullname($student),
                    'last_access' => $access,
                ];
            }
        }
        usort($inactive, static fn(array $a, array $b): int => $a['last_access'] <=> $b['last_access']);
        $inactivecount = count($inactive);

        // Per-assignment submission and grading coverage (latest attempts).
        $assignments = [];
        $modinfo = get_fast_modinfo($course);
        foreach ($modinfo->get_instances_of('assign') as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $submitted = (int) $DB->get_field_sql(
                "SELECT COUNT(DISTINCT s.userid)
                   FROM {assign_submission} s
                  WHERE s.assignment = :assignment AND s.latest = 1 AND s.status = 'submitted'",
                ['assignment' => (int) $cm->instance]
            );
            $graded = (int) $DB->get_field_sql(
                'SELECT COUNT(DISTINCT g.userid)
                   FROM {assign_grades} g
                  WHERE g.assignment = :assignment AND g.grade IS NOT NULL AND g.grade >= 0',
                ['assignment' => (int) $cm->instance]
            );
            $instance = $DB->get_record('assign', ['id' => (int) $cm->instance], 'id, duedate');
            $assignments[] = [
                'cmid' => (int) $cm->id,
                'name' => $cm->get_formatted_name(),
                'duedate' => (int) ($instance->duedate ?? 0),
                'submitted' => $submitted,
                'graded' => $graded,
                'missing' => max(0, $studentcount - $submitted),
            ];
        }

        // Course total grade summary.
        require_once($CFG->libdir . '/gradelib.php');
        $gradesummary = null;
        $courseitem = grade_item::fetch_course_item($courseid);
        if ($courseitem && $studentids !== []) {
            [$insql, $params] = $DB->get_in_or_equal($studentids, SQL_PARAMS_NAMED);
            $params['itemid'] = (int) $courseitem->id;
            $stats = $DB->get_record_sql(
                "SELECT COUNT(finalgrade) AS graded, AVG(finalgrade) AS avggrade,
                        MIN(finalgrade) AS mingrade, MAX(finalgrade) AS maxgrade
                   FROM {grade_grades}
                  WHERE itemid = :itemid AND finalgrade IS NOT NULL AND userid $insql",
                $params
            );
            $gradesummary = [
                'graded_students' => (int) $stats->graded,
                'average' => $stats->avggrade !== null ? round((float) $stats->avggrade, 1) : null,
                'min' => $stats->mingrade !== null ? (float) $stats->mingrade : null,
                'max' => $stats->maxgrade !== null ? (float) $stats->maxgrade : null,
                'grade_max' => (float) $courseitem->grademax,
            ];
        }

        return [
            'student_count' => $studentcount,
            'inactive_count' => $inactivecount,
            'inactive' => array_slice($inactive, 0, self::INACTIVE_LIST_CAP),
            'assignments' => $assignments,
            'grade_summary' => $gradesummary,
            'summary' => format_string($course->fullname, true, ['context' => $context]) . ': '
                . $studentcount . ' student(s), ' . $inactivecount . ' inactive for over '
                . $inactivedays . ' days, ' . count($assignments) . ' assignment(s).'
                . ($inactivecount > self::INACTIVE_LIST_CAP
                    ? ' Inactive list capped at ' . self::INACTIVE_LIST_CAP . ' entries.'
                    : ''),
        ];
    }
}
