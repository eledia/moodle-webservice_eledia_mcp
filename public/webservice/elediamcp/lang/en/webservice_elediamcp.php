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
 * English language strings for the MCP web service plugin.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Audit events.
$string['event_context_verified'] = 'MCP user context verified';
$string['event_context_verified_desc'] = 'The user with id \'{$a->userid}\' verified their MCP context (course filter: {$a->coursefilter}).';
$string['event_token_created'] = 'MCP token created';
$string['event_token_created_desc'] = 'The user with id \'{$a->userid}\' created MCP token \'{$a->label}\' for the user with id \'{$a->relateduserid}\' on service \'{$a->service}\' (via {$a->component}).';
$string['event_token_revoked'] = 'MCP token revoked';
$string['event_token_revoked_desc'] = 'The user with id \'{$a->userid}\' revoked MCP token \'{$a->label}\' belonging to the user with id \'{$a->relateduserid}\' on service \'{$a->service}\'.';
$string['event_tool_invoked'] = 'MCP tool invoked';
$string['event_tool_invoked_desc'] = 'The user with id \'{$a->userid}\' invoked MCP tool \'{$a->toolname}\' (isError: {$a->iserror}, duration: {$a->durationms} ms).';
$string['event_write_performed'] = 'MCP write action performed';
$string['event_write_performed_desc'] = 'The user with id \'{$a->userid}\' performed a write action through MCP tool \'{$a->toolname}\'.';

// Errors.
$string['error_expiry_in_past'] = 'The expiry date must be in the future.';
$string['error_invalid_component'] = 'Unknown component \'{$a}\'. MCP tokens can only be provisioned on behalf of an installed Moodle component.';
$string['error_label_required'] = 'A token label is required.';
$string['error_service_disabled'] = 'The selected web service is disabled.';
$string['error_service_not_mcp'] = 'The selected web service is not configured as an MCP service. Tokens can only be created for configured MCP services.';
$string['error_token_not_found'] = 'The requested MCP token does not exist.';
$string['error_token_not_owned_by_component'] = 'This MCP token was not provisioned by the calling component and cannot be revoked through the internal API.';
$string['err_emergency_disabled'] = 'The MCP web service is temporarily disabled by the site administrator.';
$string['err_empty_request'] = 'Request body is empty';
$string['err_forbidden_origin'] = 'Origin not allowed by site policy';
$string['err_invalid_json'] = 'Invalid JSON';
$string['err_invalid_jsonrpc'] = 'Invalid JSON-RPC version';
$string['err_invalid_protocol_version'] = 'Unsupported MCP protocol version';
$string['err_missing_method'] = 'Missing method';
$string['err_missing_tool_name'] = 'Missing tool name';
$string['err_rate_limit_exceeded'] = 'Rate limit exceeded. Retry after {$a} seconds.';
$string['err_request_too_large'] = 'Request body exceeds the maximum allowed size';
$string['err_token_in_query_disabled'] = 'Token in query string is disabled by site policy. Use the Authorization header instead.';

// Capabilities.
$string['elediamcp:managetokens'] = 'Create and revoke own MCP tokens';
$string['elediamcp:use'] = 'Use MCP web service';
$string['elediamcp:viewcaps'] = 'View own capability tree through MCP';

// Generic.
$string['disabled'] = 'disabled';

// Plugin metadata.
$string['pluginname'] = 'Model Context Protocol';
$string['privacy:metadata:webservice_elediamcp_token'] = 'Metadata about MCP web service tokens issued to or on behalf of a user. The token secret itself is never stored here.';
$string['privacy:metadata:webservice_elediamcp_token:component'] = 'The first-party component that provisioned the token, if any.';
$string['privacy:metadata:webservice_elediamcp_token:creatorid'] = 'The user who created the token.';
$string['privacy:metadata:webservice_elediamcp_token:externalserviceid'] = 'The external service the token is scoped to.';
$string['privacy:metadata:webservice_elediamcp_token:lastaccess'] = 'The time the token was last used.';
$string['privacy:metadata:webservice_elediamcp_token:name'] = 'The token label.';
$string['privacy:metadata:webservice_elediamcp_token:revoked'] = 'Whether the token has been revoked.';
$string['privacy:metadata:webservice_elediamcp_token:revokedby'] = 'The user who revoked the token.';
$string['privacy:metadata:webservice_elediamcp_token:timecreated'] = 'The time the token was created.';
$string['privacy:metadata:webservice_elediamcp_token:userid'] = 'The user the token authenticates as.';
$string['privacy:metadata:webservice_elediamcp_token:validuntil'] = 'The token expiry time.';

// Token management UI.
$string['claude_config_file'] = 'Configuration file location';
$string['claude_config_file_help'] = 'On macOS: <code>~/Library/Application Support/Claude/claude_desktop_config.json</code>. On Windows: <code>%APPDATA%\\Claude\\claude_desktop_config.json</code>. Create the file if it does not exist.';
$string['claude_connect_heading'] = 'Connect an MCP client (Claude Desktop)';
$string['claude_connect_intro'] = 'Claude Desktop and other stdio-only MCP clients connect to this remote server through the <a href="https://www.npmjs.com/package/mcp-remote" target="_blank" rel="noopener">mcp-remote</a> bridge, which requires <a href="https://nodejs.org/" target="_blank" rel="noopener">Node.js</a> (for <code>npx</code>) on the client machine. Add the snippet below to your <code>claude_desktop_config.json</code>, replace the token, then fully restart Claude Desktop.';
$string['claude_connect_serverurl'] = 'MCP server URL';
$string['claude_connect_snippet'] = 'Claude Desktop configuration';
$string['claude_connect_snippet_withtoken'] = 'Ready-to-use Claude Desktop configuration (your new token is already filled in — copy it now)';
$string['claude_connect_tokenhint'] = 'Replace <code>{$a}</code> with a token you created above.';
$string['token_actions'] = 'Actions';
$string['token_create'] = 'Create token';
$string['token_created'] = 'Created';
$string['token_created_once'] = 'Your new token has been created. Copy it now — for security it will not be shown again.';
$string['token_label'] = 'Label';
$string['token_label_help'] = 'A name to help you recognise this token later, for example the device or application it is used by.';
$string['token_lastused'] = 'Last used';
$string['token_never'] = 'Never';
$string['token_revoke'] = 'Revoke';
$string['token_revoke_confirm'] = 'Are you sure you want to revoke the token "{$a}"? Any application using it will immediately lose access. This cannot be undone.';
$string['token_revoked_notice'] = 'The token has been revoked.';
$string['token_service'] = 'Service';
$string['token_service_help'] = 'The MCP service this token grants access to. Only services your administrator has enabled for MCP and that you are permitted to use are listed.';
$string['token_status'] = 'Status';
$string['token_status_active'] = 'Active';
$string['token_status_expired'] = 'Expired';
$string['token_status_revoked'] = 'Revoked';
$string['token_validuntil'] = 'Expires';
$string['token_validuntil_help'] = 'An optional date after which the token stops working. Leave disabled for a token that never expires.';
$string['tokens_heading'] = 'MCP tokens';
$string['tokens_intro'] = 'Tokens let MCP clients and AI agents access Moodle on your behalf. Treat each token like a password.';
$string['tokens_navlabel'] = 'MCP tokens';
$string['tokens_no_services'] = 'No MCP services are currently available to you. Contact your administrator if you need MCP access.';
$string['tokens_no_services_configured'] = 'No MCP services have been configured on this site yet. An administrator must select one or more external services under Site administration → Plugins → Web services → Model Context Protocol → "MCP external services" before tokens can be created.';
$string['tokens_no_services_permitted'] = 'MCP services are configured on this site, but you are not currently permitted to use any of them. This usually means the service is restricted to authorised users; contact your administrator to be granted access.';
$string['tokens_none'] = 'You have not created any MCP tokens yet.';

// Settings.
$string['configuration_error_nonnegative'] = 'Enter a value of zero or higher.';
$string['configuration_heading'] = 'MCP configuration';
$string['configuration_hint'] = 'Configure external services, token policy, security limits and the MCP tool catalogue.';
$string['configuration_saved'] = 'MCP configuration saved.';
$string['configuration_security_heading'] = 'Security and request limits';
$string['configuration_services_heading'] = 'Services and tokens';
$string['configuration_shell_link'] = 'Open MCP Plugin Shell';
$string['configuration_shell_link_desc'] = 'Open the plugin-owned MCP configuration page.';
$string['configuration_tag_mcp'] = 'MCP';
$string['configuration_tag_security'] = 'Security';
$string['configuration_tagline'] = 'Configuration';
$string['configuration_tools_heading'] = 'Tool catalogue';
$string['setting_allow_token_in_query'] = 'Allow token in query string';
$string['setting_allow_token_in_query_desc'] = 'When enabled, the MCP endpoint accepts the token through the <code>?wstoken=</code> query parameter. Disabled by default because tokens in URLs leak into web server logs, browser history, and HTTP referer headers. Clients should use the <code>Authorization: Bearer</code> header.';
$string['setting_allowed_origins'] = 'Allowed CORS origins';
$string['setting_allowed_origins_desc'] = 'One origin per line (e.g. <code>https://app.example.com</code>). Use <code>*</code> to allow any origin (not recommended). When empty, only same-origin requests are accepted. The MCP server validates the <code>Origin</code> header on all requests and returns HTTP 403 for any origin not on this list.';
$string['setting_emergency_disable'] = 'Emergency disable';
$string['setting_emergency_disable_desc'] = 'When enabled, every request to the MCP endpoint returns HTTP 503 Service Unavailable. Use this as a temporary kill switch during incident response.';
$string['setting_expose_raw_functions'] = 'Expose raw Moodle Web Service functions';
$string['setting_expose_raw_functions_desc'] = 'When enabled, every external function assigned to the authenticated service is exposed as an MCP tool, in addition to the curated AI-native tools. Disabling this restricts the surface to the AI-native tool set only, which is recommended for production AI agents.';
$string['setting_max_request_size'] = 'Maximum request body size (bytes)';
$string['setting_max_request_size_desc'] = 'Requests with a body larger than this value are rejected with HTTP 413. Default 1 MiB.';
$string['setting_rate_limit_per_hour'] = 'Rate limit per hour (per token)';
$string['setting_rate_limit_per_hour_desc'] = 'Maximum number of requests per hour for a single token. Default 600.';
$string['setting_rate_limit_per_minute'] = 'Rate limit per minute (per token)';
$string['setting_rate_limit_per_minute_desc'] = 'Maximum number of requests per minute for a single token. Default 60.';
$string['setting_token_retention_days'] = 'Revoked token retention (days)';
$string['setting_token_retention_days_desc'] = 'How many days to keep the audit record of a revoked MCP token before the scheduled cleanup task deletes it. Connector-provisioned tokens are re-minted regularly, so their revoked records can accumulate. Set to 0 to keep every revoked token record indefinitely.';
$string['setting_services'] = 'MCP external services';
$string['setting_services_desc'] = 'The external services that may issue MCP tokens. Only services selected here can be chosen in the self-service token UI or targeted through the internal token API. Create the services under <em>Site administration → Server → Web services → External services</em> first, then enable them here.';
$string['setting_tools_page_size'] = 'Default page size for tools/list';
$string['setting_tools_page_size_desc'] = 'Maximum number of tools returned per <code>tools/list</code> response. Larger sets are paginated through the <code>nextCursor</code> field.';

// Server metadata.
$string['server_instructions'] = 'Moodle MCP server with curated AI-native tools.

Identity & context: call moodle_me first to confirm identity, then moodle_verify_user_context for the full roles/groups/capabilities probe.

People discovery: moodle_find_user resolves a free-form name fragment (e.g. "erika") to a list of messageable Moodle users with concrete ids. Use it BEFORE moodle_send_message whenever you do not already know the recipient\'s exact user id.

Course discovery: moodle_my_courses lists enrolled courses; moodle_search_courses searches the public catalogue; moodle_course_contents enumerates sections/activities of one course; moodle_get_resource returns the body of a page/book chapter/label/url/resource by cmid.

Feeds & progress: moodle_get_announcements for the latest news-forum posts; moodle_calendar_upcoming for deadlines; moodle_my_assignments for submission status; moodle_my_grades for course finals (or per-item with include_items + course_id).

Write tools: moodle_send_message accepts to_user_id (preferred), to_username (exact match) or to_query (fuzzy, single-match only). Two-step flow — call once without confirm to receive a preview, then call again with confirm=true and the user\'s explicit go-ahead to actually send. Hard limit 4000 chars.

Read-only tools are safe to call automatically; write tools require an explicit "confirm" argument.';
$string['servername'] = 'Moodle MCP Server';

// Scheduled tasks.
$string['task_prune_revoked_tokens'] = 'Prune old revoked MCP tokens';
