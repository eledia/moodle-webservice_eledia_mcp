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
 * Admin settings for the MCP web service plugin.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $configurationurl = new moodle_url('/webservice/elediamcp/configuration.php');
    if (empty($GLOBALS['CLI_SCRIPT']) && optional_param('section', '', PARAM_ALPHANUMEXT) === 'webservicesettingelediamcp') {
        redirect($configurationurl);
    }
    $settings->add(new admin_setting_heading(
        'webservice_elediamcp/configuration_shell',
        get_string('configuration_shell_link', 'webservice_elediamcp'),
        html_writer::link($configurationurl, get_string('configuration_shell_link_desc', 'webservice_elediamcp'))
    ));
}
