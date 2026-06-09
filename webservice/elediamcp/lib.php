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

/**
 * Moodle callbacks for the MCP web service plugin.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Add the MCP token management link to the user's preferences navigation.
 *
 * Shown only on the current user's own preferences page, and only when they hold
 * webservice/elediamcp:managetokens. This is the entry point to the self-service token
 * management UI.
 *
 * @param navigation_node $navigation The user settings navigation node.
 * @param stdClass $user The user whose preferences are being viewed.
 * @param context_user $usercontext The user context.
 * @param stdClass $course The current course.
 * @param context_course $coursecontext The current course context.
 * @return void
 */
function webservice_elediamcp_extend_navigation_user_settings($navigation, $user, $usercontext, $course, $coursecontext): void {
    global $USER;

    // Self-service only: a user manages their own tokens.
    if (empty($USER->id) || (int) $user->id !== (int) $USER->id) {
        return;
    }
    if (isguestuser() || !isloggedin()) {
        return;
    }
    if (!has_capability('webservice/elediamcp:managetokens', context_system::instance())) {
        return;
    }

    $navigation->add(
        get_string('tokens_navlabel', 'webservice_elediamcp'),
        new moodle_url('/webservice/elediamcp/token/index.php'),
        navigation_node::TYPE_SETTING,
        null,
        'webservice_elediamcp_tokens',
        new pix_icon('i/key', '')
    );
}
