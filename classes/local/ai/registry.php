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

namespace webservice_elediamcp\local\ai;

use webservice_elediamcp\local\ai\tools\moodle_calendar_upcoming;
use webservice_elediamcp\local\ai\tools\moodle_course_contents;
use webservice_elediamcp\local\ai\tools\moodle_create_course;
use webservice_elediamcp\local\ai\tools\moodle_create_user;
use webservice_elediamcp\local\ai\tools\moodle_enrol_user;
use webservice_elediamcp\local\ai\tools\moodle_find_user;
use webservice_elediamcp\local\ai\tools\moodle_forum_discussions;
use webservice_elediamcp\local\ai\tools\moodle_get_announcements;
use webservice_elediamcp\local\ai\tools\moodle_get_resource;
use webservice_elediamcp\local\ai\tools\moodle_me;
use webservice_elediamcp\local\ai\tools\moodle_my_assignments;
use webservice_elediamcp\local\ai\tools\moodle_my_courses;
use webservice_elediamcp\local\ai\tools\moodle_my_grades;
use webservice_elediamcp\local\ai\tools\moodle_my_progress;
use webservice_elediamcp\local\ai\tools\moodle_my_submission_files;
use webservice_elediamcp\local\ai\tools\moodle_quiz_info;
use webservice_elediamcp\local\ai\tools\moodle_search_content;
use webservice_elediamcp\local\ai\tools\moodle_search_courses;
use webservice_elediamcp\local\ai\tools\moodle_send_message;
use webservice_elediamcp\local\ai\tools\moodle_update_course;
use webservice_elediamcp\local\ai\tools\moodle_verify_user_context;

/**
 * Registry of AI-native MCP tools.
 *
 * Lists the curated tool implementations exposed by the MCP server. Keeping
 * the registry static keeps {@see \webservice_elediamcp\local\tool_provider} simple
 * and free of dynamic discovery overhead.
 *
 * Add new tools by:
 * 1. Creating an implementation under webservice_elediamcp\local\ai\tools\*.
 * 2. Adding the class name to the array returned by {@see all()}.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class registry {
    /**
     * Return the list of registered AI tool class names.
     *
     * @return class-string<ai_tool>[]
     */
    public static function all(): array {
        return [
            // Identity & context.
            moodle_me::class,
            moodle_verify_user_context::class,
            // People discovery (messageable users).
            moodle_find_user::class,
            // Course discovery & contents.
            moodle_my_courses::class,
            moodle_search_courses::class,
            moodle_course_contents::class,
            moodle_get_resource::class,
            moodle_search_content::class,
            // Communication & activity feeds.
            moodle_get_announcements::class,
            moodle_forum_discussions::class,
            moodle_calendar_upcoming::class,
            // Learner progress.
            moodle_my_assignments::class,
            moodle_my_grades::class,
            moodle_my_progress::class,
            moodle_quiz_info::class,
            moodle_my_submission_files::class,
            // Write tools (require confirm).
            moodle_send_message::class,
            moodle_create_user::class,
            moodle_create_course::class,
            moodle_update_course::class,
            moodle_enrol_user::class,
        ];
    }

    /**
     * Look up an AI tool by name.
     *
     * @param string $name Tool name.
     * @return class-string<ai_tool>|null
     */
    public static function find(string $name): ?string {
        foreach (self::all() as $class) {
            if ($class::name() === $name) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Return the set of tool names registered with the AI layer.
     *
     * @return string[]
     */
    public static function names(): array {
        return array_map(static fn(string $class): string => $class::name(), self::all());
    }
}
