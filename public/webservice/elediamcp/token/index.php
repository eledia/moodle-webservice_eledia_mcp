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
 * Self-service MCP token management.
 *
 * Users with webservice/elediamcp:managetokens create, view metadata for, and revoke
 * their own MCP tokens here. The full token value is shown exactly once, right
 * after creation.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->dirroot . '/webservice/lib.php');

use core\output\notification;
use webservice_elediamcp\form\create_token_form;
use webservice_elediamcp\local\token_manager;

require_login(null, false);

if (isguestuser()) {
    throw new require_login_exception('Guests are not allowed to manage MCP tokens.');
}

$action = optional_param('action', '', PARAM_ALPHA);
$tokenid = optional_param('tokenid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$systemcontext = context_system::instance();
$usercontext = context_user::instance($USER->id);

require_capability('webservice/elediamcp:managetokens', $systemcontext);

$baseurl = new moodle_url('/webservice/elediamcp/token/index.php');

$PAGE->set_url($baseurl);
$PAGE->set_context($usercontext);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('tokens_heading', 'webservice_elediamcp'));
$PAGE->set_heading(fullname($USER));
$PAGE->navbar->add(get_string('tokens_heading', 'webservice_elediamcp'), $baseurl);

/**
 * Build the list of MCP services the current user may create a token for.
 *
 * Restricts the admin-configured MCP services to those the user can actually use
 * (required capability satisfied, and authorised when the service is restricted).
 *
 * @return array<int, string> Service id => display name.
 */
$buildusableservices = function () use ($USER, $systemcontext): array {
    $usable = [];
    $manager = new webservice();
    foreach (token_manager::get_mcp_services() as $service) {
        if (!empty($service->requiredcapability)
                && !has_capability($service->requiredcapability, $systemcontext, $USER->id)) {
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

// Absolute URL of the MCP server endpoint that MCP clients connect to.
$serverurl = (new moodle_url('/webservice/elediamcp/server.php'))->out(false);

/**
 * Build a ready-to-paste Claude Desktop (mcp-remote) configuration snippet.
 *
 * Uses the mcp-remote bridge with header-based bearer authentication. The header
 * value is supplied through an environment variable (no space in the --header
 * argument) which is the documented workaround for Claude Desktop stripping
 * spaces from command arguments.
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

// Handle token revocation.
if ($action === 'revoke' && $tokenid) {
    $record = token_manager::get_token($tokenid);
    if ($record === null || (int) $record->userid !== (int) $USER->id) {
        throw new moodle_exception('error_token_not_found', 'webservice_elediamcp');
    }

    if ($confirm && confirm_sesskey()) {
        token_manager::revoke_token($tokenid, (int) $USER->id);
        redirect($baseurl, get_string('token_revoked_notice', 'webservice_elediamcp'), null,
            notification::NOTIFY_SUCCESS);
    }

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('tokens_heading', 'webservice_elediamcp'));
    echo $OUTPUT->confirm(
        get_string('token_revoke_confirm', 'webservice_elediamcp', s($record->name)),
        new moodle_url($baseurl, [
            'action' => 'revoke',
            'tokenid' => $tokenid,
            'confirm' => 1,
            'sesskey' => sesskey(),
        ]),
        $baseurl
    );
    echo $OUTPUT->footer();
    die;
}

$usableservices = $buildusableservices();

// Handle token creation.
$mform = null;
if (!empty($usableservices)) {
    $mform = new create_token_form($baseurl->out(false), ['services' => $usableservices]);

    if ($mform->is_cancelled()) {
        redirect($baseurl);
    } else if ($data = $mform->get_data()) {
        $serviceid = (int) $data->service;
        if (!isset($usableservices[$serviceid])) {
            throw new moodle_exception('error_service_not_mcp', 'webservice_elediamcp');
        }
        $validuntil = !empty($data->validuntil) ? (int) $data->validuntil : 0;

        $result = token_manager::create_token(
            (int) $USER->id,
            $serviceid,
            (string) $data->name,
            $validuntil
        );

        // Surface the secret exactly once via a one-shot session slot.
        $SESSION->webservice_elediamcp_newtoken = $result->token;
        redirect($baseurl);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tokens_heading', 'webservice_elediamcp'));
echo html_writer::div(get_string('tokens_intro', 'webservice_elediamcp'), 'text-muted mb-3');

// One-time reveal of a freshly created token.
if (!empty($SESSION->webservice_elediamcp_newtoken)) {
    $newtoken = $SESSION->webservice_elediamcp_newtoken;
    unset($SESSION->webservice_elediamcp_newtoken);

    $box = html_writer::tag('p', get_string('token_created_once', 'webservice_elediamcp'));
    $box .= html_writer::tag('code', s($newtoken), [
        'class' => 'd-inline-block p-2 bg-light border rounded user-select-all',
        'style' => 'word-break: break-all;',
    ]);
    echo $OUTPUT->notification($box, notification::NOTIFY_SUCCESS, false);

    // Offer the freshly created token pre-filled into a Claude Desktop snippet.
    echo html_writer::tag('p', get_string('claude_connect_snippet_withtoken', 'webservice_elediamcp'), ['class' => 'mb-2']);
    echo $renderjsonblock($buildclaudeconfig($serverurl, $newtoken));
}

// Existing tokens table. Show only the user's own self-service tokens;
// connector-provisioned tokens (with an owning component) are managed
// automatically by their plugin and are not listed here.
$tokens = token_manager::get_user_tokens((int) $USER->id, true, true);
if (empty($tokens)) {
    echo html_writer::div(get_string('tokens_none', 'webservice_elediamcp'), 'alert alert-info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('token_label', 'webservice_elediamcp'),
        get_string('token_service', 'webservice_elediamcp'),
        get_string('token_created', 'webservice_elediamcp'),
        get_string('token_validuntil', 'webservice_elediamcp'),
        get_string('token_lastused', 'webservice_elediamcp'),
        get_string('token_status', 'webservice_elediamcp'),
        get_string('token_actions', 'webservice_elediamcp'),
    ];
    $table->attributes['class'] = 'generaltable';

    $strnever = get_string('token_never', 'webservice_elediamcp');
    foreach ($tokens as $token) {
        $statusbadge = html_writer::span(
            get_string('token_status_' . $token->status, 'webservice_elediamcp'),
            'badge ' . ($token->status === token_manager::STATUS_ACTIVE ? 'badge-success bg-success' : 'badge-secondary bg-secondary')
        );

        $actions = '';
        if ($token->status === token_manager::STATUS_ACTIVE) {
            $actions = html_writer::link(
                new moodle_url($baseurl, ['action' => 'revoke', 'tokenid' => $token->id]),
                get_string('token_revoke', 'webservice_elediamcp'),
                ['class' => 'btn btn-sm btn-outline-danger']
            );
        }

        $table->data[] = [
            s($token->name),
            s($token->servicename ?? ''),
            userdate($token->timecreated),
            !empty($token->validuntil) ? userdate($token->validuntil) : $strnever,
            !empty($token->lastaccess) ? userdate($token->lastaccess) : $strnever,
            $statusbadge,
            $actions,
        ];
    }
    echo html_writer::table($table);
}

// Creation form (or a hint when no MCP service is available to the user).
echo $OUTPUT->heading(get_string('token_create', 'webservice_elediamcp'), 3);
if ($mform !== null) {
    $mform->display();
} else if (empty(token_manager::get_mcp_services())) {
    // No service has been ticked in the admin allow-list at all.
    echo html_writer::div(get_string('tokens_no_services_configured', 'webservice_elediamcp'), 'alert alert-warning');
} else {
    // Services exist, but the user is not permitted to use any of them.
    echo html_writer::div(get_string('tokens_no_services_permitted', 'webservice_elediamcp'), 'alert alert-warning');
}

// Connection guide for MCP clients (Claude Desktop via mcp-remote).
echo $OUTPUT->heading(get_string('claude_connect_heading', 'webservice_elediamcp'), 3);
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
    'text-muted small mb-3'
);

echo $OUTPUT->footer();
