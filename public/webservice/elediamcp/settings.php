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
    global $DB;

    // Token management: which external services may issue MCP tokens.
    $mcpservices = [];
    if (during_initial_install() === false) {
        $allservices = $DB->get_records('external_services', null, 'name ASC', 'id, name, enabled');
        foreach ($allservices as $mcpservice) {
            $label = format_string($mcpservice->name);
            if (empty($mcpservice->enabled)) {
                $label .= ' (' . get_string('disabled', 'webservice_elediamcp') . ')';
            }
            $mcpservices[$mcpservice->id] = $label;
        }
    }
    $settings->add(new admin_setting_configmultiselect(
        'webservice_elediamcp/services',
        get_string('setting_services', 'webservice_elediamcp'),
        get_string('setting_services_desc', 'webservice_elediamcp'),
        [],
        $mcpservices
    ));

    // Security: allowed CORS / Origin allow-list.
    $settings->add(new admin_setting_configtextarea(
        'webservice_elediamcp/allowed_origins',
        get_string('setting_allowed_origins', 'webservice_elediamcp'),
        get_string('setting_allowed_origins_desc', 'webservice_elediamcp'),
        '',
        PARAM_RAW,
        60,
        5
    ));

    // Security: allow ?wstoken= in query string (off by default in production).
    $settings->add(new admin_setting_configcheckbox(
        'webservice_elediamcp/allow_token_in_query',
        get_string('setting_allow_token_in_query', 'webservice_elediamcp'),
        get_string('setting_allow_token_in_query_desc', 'webservice_elediamcp'),
        0
    ));

    // Tooling: expose raw Moodle Web Service functions alongside AI-native tools.
    $settings->add(new admin_setting_configcheckbox(
        'webservice_elediamcp/expose_raw_functions',
        get_string('setting_expose_raw_functions', 'webservice_elediamcp'),
        get_string('setting_expose_raw_functions_desc', 'webservice_elediamcp'),
        1
    ));

    // Rate limiting: requests per minute per token.
    $settings->add(new admin_setting_configtext(
        'webservice_elediamcp/rate_limit_per_minute',
        get_string('setting_rate_limit_per_minute', 'webservice_elediamcp'),
        get_string('setting_rate_limit_per_minute_desc', 'webservice_elediamcp'),
        60,
        PARAM_INT
    ));

    // Rate limiting: requests per hour per token.
    $settings->add(new admin_setting_configtext(
        'webservice_elediamcp/rate_limit_per_hour',
        get_string('setting_rate_limit_per_hour', 'webservice_elediamcp'),
        get_string('setting_rate_limit_per_hour_desc', 'webservice_elediamcp'),
        600,
        PARAM_INT
    ));

    // Request limits: max body size in bytes.
    $settings->add(new admin_setting_configtext(
        'webservice_elediamcp/max_request_size',
        get_string('setting_max_request_size', 'webservice_elediamcp'),
        get_string('setting_max_request_size_desc', 'webservice_elediamcp'),
        1048576,
        PARAM_INT
    ));

    // Pagination: default page size for tools/list.
    $settings->add(new admin_setting_configtext(
        'webservice_elediamcp/tools_page_size',
        get_string('setting_tools_page_size', 'webservice_elediamcp'),
        get_string('setting_tools_page_size_desc', 'webservice_elediamcp'),
        50,
        PARAM_INT
    ));

    // Emergency kill switch.
    $settings->add(new admin_setting_configcheckbox(
        'webservice_elediamcp/emergency_disable',
        get_string('setting_emergency_disable', 'webservice_elediamcp'),
        get_string('setting_emergency_disable_desc', 'webservice_elediamcp'),
        0
    ));
}
