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
require_once($CFG->dirroot . '/webservice/lib.php');

use core\output\notification;
use webservice_elediamcp\form\configuration_form;
use webservice_elediamcp\form\create_token_form;
use webservice_elediamcp\local\token_manager;
use webservice_elediamcp\output\shell;

require_login();

$context = \core\context\system::instance();
require_capability('moodle/site:config', $context);

$url = new moodle_url('/webservice/elediamcp/configuration.php');
$tokenanchorurl = new moodle_url('/webservice/elediamcp/configuration.php', [], 'mcp-tokens');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('configuration_heading', 'webservice_elediamcp'));
$PAGE->set_heading('');
$PAGE->set_pagelayout('standard');

$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

/**
 * Build the list of MCP services the current user may create a token for.
 *
 * @return array<int, string> Service id => display name.
 */
$buildusableservices = function () use ($USER, $context): array {
    $usable = [];
    $manager = new webservice();
    foreach (token_manager::get_mcp_services() as $service) {
        if (!empty($service->requiredcapability)
                && !has_capability($service->requiredcapability, $context, $USER->id)) {
            continue;
        }
        if (!empty($service->restrictedusers)
                && empty($manager->get_ws_authorised_user($service->id, $USER->id))) {
            continue;
        }
        $usable[(int) $service->id] = format_string($service->name);
    }
    return $usable;
};

/**
 * Build a ready-to-paste Claude Desktop (mcp-remote) configuration snippet.
 *
 * @param string $endpoint Absolute MCP server URL.
 * @param string $token Token value, or a placeholder for the generic example.
 * @return string Pretty-printed JSON configuration.
 */
$buildclaudeconfig = function (string $endpoint, string $token): string {
    $config = [
        'mcpServers' => [
            'moodle' => [
                'command' => 'npx',
                'args' => [
                    '-y',
                    'mcp-remote',
                    $endpoint,
                    '--header',
                    'Authorization:${AUTH_HEADER}',
                ],
                'env' => [
                    'AUTH_HEADER' => 'Bearer ' . $token,
                ],
            ],
        ],
    ];
    return (string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
};

/**
 * Render a JSON snippet as an escaped, copy-friendly code block.
 *
 * @param string $json JSON text to display.
 * @return string HTML.
 */
$renderjsonblock = function (string $json): string {
    return html_writer::tag(
        'pre',
        html_writer::tag('code', s($json), ['class' => 'language-json']),
        ['class' => 'p-3 bg-light border rounded user-select-all', 'style' => 'white-space: pre; overflow-x: auto;']
    );
};

if ($action === 'revoke' && $tokenid) {
    require_capability('webservice/elediamcp:managetokens', $context);

    $record = token_manager::get_token($tokenid);
    if ($record === null || (int) $record->userid !== (int) $USER->id) {
        throw new moodle_exception('error_token_not_found', 'webservice_elediamcp');
    }

    if ($confirm && confirm_sesskey()) {
        token_manager::revoke_token($tokenid, (int) $USER->id);
        redirect($tokenanchorurl, get_string('token_revoked_notice', 'webservice_elediamcp'), null,
            notification::NOTIFY_SUCCESS);
    }

    shell::require_css();
    echo $OUTPUT->header();
    shell::open();
    echo $OUTPUT->confirm(
        get_string('token_revoke_confirm', 'webservice_elediamcp', s($record->name)),
        new moodle_url($url, [
            'action' => 'revoke',
            'tokenid' => $tokenid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ], 'mcp-tokens'),
        $tokenanchorurl
    );
    shell::close();
    echo $OUTPUT->footer();
    die;
}

$allservices = $DB->get_records('external_services', null, 'name ASC', 'id, name, enabled');
$services = [];
foreach ($allservices as $service) {
    $label = format_string($service->name);
    if (empty($service->enabled)) {
        $label .= ' (' . get_string('disabled', 'webservice_elediamcp') . ')';
    }
    $services[(int) $service->id] = $label;
}

$usableservices = has_capability('webservice/elediamcp:managetokens', $context) ? $buildusableservices() : [];
$tokenform = null;
$newtoken = null;
if (has_capability('webservice/elediamcp:managetokens', $context) && !empty($usableservices)) {
    $tokenform = new create_token_form($tokenanchorurl->out(false), ['services' => $usableservices]);

    if ($tokenform->is_cancelled()) {
        redirect($tokenanchorurl);
    } else if ($tokendata = $tokenform->get_data()) {
        $serviceid = (int) $tokendata->service;
        if (!isset($usableservices[$serviceid])) {
            throw new moodle_exception('error_service_not_mcp', 'webservice_elediamcp');
        }
        $validuntil = !empty($tokendata->validuntil) ? (int) $tokendata->validuntil : 0;

        $result = token_manager::create_token(
            (int) $USER->id,
            $serviceid,
            (string) $tokendata->name,
            $validuntil
        );

        $newtoken = $result->token;
    }
}

$mform = new configuration_form($url->out(false), ['services' => $services]);

$current = (object) [
    'services' => array_values(array_filter(array_map('intval',
        explode(',', (string) get_config('webservice_elediamcp', 'services'))))),
    'token_retention_days' => get_config('webservice_elediamcp', 'token_retention_days') ?: 30,
    'allowed_origins' => get_config('webservice_elediamcp', 'allowed_origins') ?: '',
    'allow_token_in_query' => (int) (get_config('webservice_elediamcp', 'allow_token_in_query') ?: 0),
    'expose_raw_functions' => (int) (get_config('webservice_elediamcp', 'expose_raw_functions') !== false
        ? get_config('webservice_elediamcp', 'expose_raw_functions') : 0),
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
echo html_writer::start_div('webservice-elediamcp-page-content');
$mform->display();

if (has_capability('webservice/elediamcp:managetokens', $context)) {
    $serverurl = (new moodle_url('/webservice/elediamcp/server.php'))->out(false);

    if ($newtoken !== null) {
        $box = html_writer::tag('p', get_string('token_created_once', 'webservice_elediamcp'));
        $box .= html_writer::tag('code', s($newtoken), [
            'class' => 'd-inline-block p-2 bg-light border rounded user-select-all',
            'style' => 'word-break: break-all;',
        ]);
        echo $OUTPUT->notification($box, notification::NOTIFY_SUCCESS, false);

        echo html_writer::tag('p', get_string('claude_connect_snippet_withtoken', 'webservice_elediamcp'),
            ['class' => 'mb-2']);
        echo $renderjsonblock($buildclaudeconfig($serverurl, $newtoken));
    }

    echo html_writer::tag('h3', get_string('tokens_heading', 'webservice_elediamcp'), [
        'class' => 'webservice-elediamcp-card__title',
        'id' => 'mcp-tokens',
    ]);
    echo html_writer::div(get_string('tokens_intro', 'webservice_elediamcp'),
        'webservice-elediamcp-section-info alert alert-info');

    echo html_writer::start_tag('section', [
        'class' => 'webservice-elediamcp-card webservice-elediamcp-token-card webservice-elediamcp-token-list-card',
    ]);
    echo html_writer::start_div('webservice-elediamcp-list-header');
    echo html_writer::tag('span', html_writer::tag('i', '', [
        'class' => 'fa fa-list-ul',
        'aria-hidden' => 'true',
    ]), ['class' => 'webservice-elediamcp-list-header__icon']);
    echo html_writer::start_div('webservice-elediamcp-list-header__text');
    echo html_writer::div(get_string('configuration_tag_mcp', 'webservice_elediamcp'),
        'webservice-elediamcp-list-header__eyebrow');
    echo html_writer::tag('h4', get_string('tokens_existing_heading', 'webservice_elediamcp'),
        ['class' => 'webservice-elediamcp-list-header__title']);
    echo html_writer::end_div();
    echo html_writer::end_div();

    $tokens = token_manager::get_user_tokens((int) $USER->id, true, true);
    if (empty($tokens)) {
        echo html_writer::div(get_string('tokens_none', 'webservice_elediamcp'), 'alert alert-info');
    } else {
        echo html_writer::start_tag('div', ['class' => 'webservice-elediamcp-token-table-wrap']);
        echo html_writer::start_tag('table', ['class' => 'webservice-elediamcp-token-table']);
        echo html_writer::start_tag('thead');
        echo html_writer::start_tag('tr');
        foreach ([
            get_string('token_label', 'webservice_elediamcp'),
            get_string('token_service', 'webservice_elediamcp'),
            get_string('token_created', 'webservice_elediamcp'),
            get_string('token_validuntil', 'webservice_elediamcp'),
            get_string('token_lastused', 'webservice_elediamcp'),
            get_string('token_status', 'webservice_elediamcp'),
            get_string('token_actions', 'webservice_elediamcp'),
        ] as $heading) {
            echo html_writer::tag('th', $heading, ['scope' => 'col']);
        }
        echo html_writer::end_tag('tr');
        echo html_writer::end_tag('thead');
        echo html_writer::start_tag('tbody');

        $strnever = get_string('token_never', 'webservice_elediamcp');
        foreach ($tokens as $token) {
            $statuslabel = get_string('token_status_' . $token->status, 'webservice_elediamcp');
            $statusbadge = html_writer::span(
                s($statuslabel),
                'webservice-elediamcp-status webservice-elediamcp-status--' . s($token->status)
            );

            $actions = '';
            if ($token->status === token_manager::STATUS_ACTIVE) {
                $actions = html_writer::link(
                    new moodle_url($url, ['action' => 'revoke', 'tokenid' => $token->id], 'mcp-tokens'),
                    html_writer::tag('i', '', ['class' => 'fa fa-ban', 'aria-hidden' => 'true'])
                        . html_writer::span(get_string('token_revoke', 'webservice_elediamcp'), 'sr-only'),
                    [
                        'class' => 'webservice-elediamcp-icon-action webservice-elediamcp-icon-action--danger',
                        'title' => get_string('token_revoke', 'webservice_elediamcp'),
                        'aria-label' => get_string('token_revoke', 'webservice_elediamcp'),
                    ]
                );
            }

            echo html_writer::start_tag('tr');
            echo html_writer::tag('td', html_writer::span(s($token->name), 'webservice-elediamcp-token-name'));
            echo html_writer::tag('td', s($token->servicename ?? ''));
            echo html_writer::tag('td', userdate($token->timecreated), ['class' => 'webservice-elediamcp-token-muted']);
            echo html_writer::tag('td', !empty($token->validuntil) ? userdate($token->validuntil) : $strnever,
                ['class' => 'webservice-elediamcp-token-muted']);
            echo html_writer::tag('td', !empty($token->lastaccess) ? userdate($token->lastaccess) : $strnever,
                ['class' => 'webservice-elediamcp-token-muted']);
            echo html_writer::tag('td', $statusbadge);
            echo html_writer::tag('td', html_writer::span($actions, 'webservice-elediamcp-token-actions'));
            echo html_writer::end_tag('tr');
        }
        echo html_writer::end_tag('tbody');
        echo html_writer::end_tag('table');
        echo html_writer::end_tag('div');
    }
    echo html_writer::end_tag('section');

    echo html_writer::tag('h3', get_string('token_create', 'webservice_elediamcp'), [
        'class' => 'webservice-elediamcp-card__title',
    ]);
    echo html_writer::start_tag('section', ['class' => 'webservice-elediamcp-card webservice-elediamcp-token-card']);
    if ($tokenform !== null) {
        $tokenform->display();
    } else if (empty(token_manager::get_mcp_services())) {
        echo html_writer::div(get_string('tokens_no_services_configured', 'webservice_elediamcp'), 'alert alert-warning');
    } else {
        echo html_writer::div(get_string('tokens_no_services_permitted', 'webservice_elediamcp'), 'alert alert-warning');
    }
    echo html_writer::end_tag('section');

    echo html_writer::tag('h3', get_string('claude_connect_heading', 'webservice_elediamcp'), [
        'class' => 'webservice-elediamcp-card__title',
        'id' => 'mcp-claude',
    ]);
    echo html_writer::start_tag('section', ['class' => 'webservice-elediamcp-card webservice-elediamcp-guide-card']);
    echo html_writer::div(get_string('claude_connect_intro', 'webservice_elediamcp'), 'text-muted mb-3');
    echo html_writer::tag('p', html_writer::tag(
        'strong',
        get_string('claude_connect_serverurl', 'webservice_elediamcp') . ': '
    ) . html_writer::tag('code', s($serverurl)), ['class' => 'mb-2']);
    echo html_writer::tag('p', html_writer::tag(
        'strong',
        get_string('claude_config_file', 'webservice_elediamcp')
    ) . ' — ' . get_string('claude_config_file_help', 'webservice_elediamcp'), ['class' => 'mb-2 text-muted']);

    $placeholder = 'YOUR_TOKEN_HERE';
    echo html_writer::tag('p', get_string('claude_connect_snippet', 'webservice_elediamcp') . ':', ['class' => 'mb-2']);
    echo $renderjsonblock($buildclaudeconfig($serverurl, $placeholder));
    echo html_writer::div(
        get_string('claude_connect_tokenhint', 'webservice_elediamcp', s($placeholder)),
        'text-muted small mb-3 webservice-elediamcp-token-placeholder-hint'
    );
    echo html_writer::end_tag('section');
}

echo html_writer::end_div();
shell::close();
echo $OUTPUT->footer();
