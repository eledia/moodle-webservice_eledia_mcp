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
 * OAuth 2.0 Protected Resource Metadata document for the MCP server.
 *
 * Implements RFC 9728 so that MCP clients (Claude Desktop, Cursor, Windsurf,
 * etc.) can perform zero-configuration discovery of the resource and its
 * associated authorisation server(s).
 *
 * The current release advertises the resource only. A future release will
 * publish a companion /.well-known/oauth-authorization-server document for
 * the OAuth 2.1 Authorization Code + PKCE flow described in the project
 * roadmap.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_DEBUG_DISPLAY', true);
define('NO_MOODLE_COOKIES', true);

// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');

$resource = $CFG->wwwroot . '/webservice/elediamcp/server.php';

$metadata = [
    'resource' => $resource,
    'authorization_servers' => [
        // To be populated when the in-Moodle OAuth issuer is implemented.
    ],
    'bearer_methods_supported' => ['header'],
    'resource_documentation' => $CFG->wwwroot . '/webservice/elediamcp/README.md',
    'scopes_supported' => [
        'mcp:tools.read',
        'mcp:tools.call',
        'mcp:resources.read',
        'mcp:prompts.read',
    ],
    'token_introspection_endpoint' => null,
];

echo json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
