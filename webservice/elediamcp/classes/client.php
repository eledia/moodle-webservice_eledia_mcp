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

namespace webservice_elediamcp;

use curl;
use moodle_url;

/**
 * Lightweight MCP web service client for testing and integration.
 *
 * Provides a small convenience wrapper for issuing JSON-RPC 2.0 requests to the
 * MCP endpoint. It is primarily used by the plugin's own tests but is safe to
 * reuse from trusted server-side integrations.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client {
    /** @var moodle_url The MCP server URL. */
    private moodle_url $serverurl;

    /** @var string Authentication token. */
    private string $token;

    /**
     * Constructor.
     *
     * @param string $serverurl The URL of the MCP web service endpoint.
     * @param string $token The authentication token for the web service.
     */
    public function __construct(string $serverurl, string $token) {
        $this->serverurl = new moodle_url($serverurl);
        $this->token = $token;
    }

    /**
     * Set or update the authentication token.
     *
     * @param string $token The new authentication token.
     * @return void
     */
    public function set_token(string $token): void {
        $this->token = $token;
    }

    /**
     * Execute a web service request using JSON-RPC 2.0 format.
     *
     * Constructs the request, sends it via Moodle's cURL wrapper, and returns
     * the decoded response.
     *
     * @param string $method The method name to call.
     * @param array $params The parameters for the method.
     * @param int|string|null $id Optional request ID (defaults to 1).
     * @return mixed The decoded JSON response.
     */
    public function call(string $method, array $params = [], int|string|null $id = 1): mixed {
        $request = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => $params,
            'id' => $id,
        ];

        $requestjson = json_encode($request);

        $url = new moodle_url($this->serverurl);
        $url->param('wstoken', $this->token);

        $curl = new curl();
        $options = [
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($requestjson),
            ],
        ];

        $result = $curl->post($url->out(false), $requestjson, $options);

        return json_decode($result, true);
    }

    /**
     * Execute an MCP tools/list request.
     *
     * @return mixed The decoded response containing the tools list.
     */
    public function list_tools(): mixed {
        return $this->call('tools/list', []);
    }

    /**
     * Execute an MCP tools/call request.
     *
     * @param string $toolname The name of the tool to call.
     * @param array $arguments The arguments to pass to the tool.
     * @return mixed The decoded response from the tool invocation.
     */
    public function call_tool(string $toolname, array $arguments = []): mixed {
        return $this->call('tools/call', [
            'name' => $toolname,
            'arguments' => $arguments,
        ]);
    }

    /**
     * Execute an MCP initialize request.
     *
     * @return mixed The decoded response from the initialization.
     */
    public function initialize(): mixed {
        return $this->call('initialize', []);
    }
}
