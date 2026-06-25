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
 * Plugin-owned configuration page for the MCP web service.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');

use core\output\notification;
use webservice_elediamcp\form\configuration_form;
use webservice_elediamcp\output\shell;

require_login();

$context = context_system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/webservice/elediamcp/configuration.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('configuration_heading', 'webservice_elediamcp'));
$PAGE->set_heading('');
$PAGE->set_pagelayout('standard');

$allservices = $DB->get_records('external_services', null, 'name ASC', 'id, name, enabled');
$services = [];
foreach ($allservices as $service) {
    $label = format_string($service->name);
    if (empty($service->enabled)) {
        $label .= ' (' . get_string('disabled', 'webservice_elediamcp') . ')';
    }
    $services[(int) $service->id] = $label;
}

$mform = new configuration_form($url->out(false), ['services' => $services]);

$current = (object) [
    'services' => array_values(array_filter(array_map('intval',
        explode(',', (string) get_config('webservice_elediamcp', 'services'))))),
    'token_retention_days' => get_config('webservice_elediamcp', 'token_retention_days') ?: 30,
    'allowed_origins' => get_config('webservice_elediamcp', 'allowed_origins') ?: '',
    'allow_token_in_query' => (int) (get_config('webservice_elediamcp', 'allow_token_in_query') ?: 0),
    'expose_raw_functions' => (int) (get_config('webservice_elediamcp', 'expose_raw_functions') !== false
        ? get_config('webservice_elediamcp', 'expose_raw_functions') : 1),
    'rate_limit_per_minute' => get_config('webservice_elediamcp', 'rate_limit_per_minute') ?: 60,
    'rate_limit_per_hour' => get_config('webservice_elediamcp', 'rate_limit_per_hour') ?: 600,
    'max_request_size' => get_config('webservice_elediamcp', 'max_request_size') ?: 1048576,
    'tools_page_size' => get_config('webservice_elediamcp', 'tools_page_size') ?: 50,
    'emergency_disable' => (int) (get_config('webservice_elediamcp', 'emergency_disable') ?: 0),
];
$mform->set_data($current);

if ($mform->is_cancelled()) {
    redirect(new moodle_url('/admin/settings.php', ['section' => 'webservicesettingelediamcp']));
}

if ($data = $mform->get_data()) {
    $selectedservices = $data->services ?? [];
    if (!is_array($selectedservices)) {
        $selectedservices = [$selectedservices];
    }
    $selectedservices = array_values(array_unique(array_filter(array_map('intval', $selectedservices))));

    set_config('services', implode(',', $selectedservices), 'webservice_elediamcp');
    set_config('token_retention_days', max(0, (int) $data->token_retention_days), 'webservice_elediamcp');
    set_config('allowed_origins', (string) $data->allowed_origins, 'webservice_elediamcp');
    set_config('allow_token_in_query', empty($data->allow_token_in_query) ? 0 : 1, 'webservice_elediamcp');
    set_config('expose_raw_functions', empty($data->expose_raw_functions) ? 0 : 1, 'webservice_elediamcp');
    set_config('rate_limit_per_minute', max(0, (int) $data->rate_limit_per_minute), 'webservice_elediamcp');
    set_config('rate_limit_per_hour', max(0, (int) $data->rate_limit_per_hour), 'webservice_elediamcp');
    set_config('max_request_size', max(0, (int) $data->max_request_size), 'webservice_elediamcp');
    set_config('tools_page_size', max(0, (int) $data->tools_page_size), 'webservice_elediamcp');
    set_config('emergency_disable', empty($data->emergency_disable) ? 0 : 1, 'webservice_elediamcp');

    redirect($url, get_string('configuration_saved', 'webservice_elediamcp'), null, notification::NOTIFY_SUCCESS);
}

shell::require_css();
echo $OUTPUT->header();
shell::open();
$mform->display();
shell::close();
echo $OUTPUT->footer();
