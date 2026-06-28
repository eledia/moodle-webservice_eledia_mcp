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

namespace webservice_elediamcp\local;

use core_external\external_api;
use core_external\external_description;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use Exception;
use moodle_exception;
use moodle_url;
use webservice_base_server;
use webservice_elediamcp\event\tool_invoked;
use webservice_elediamcp\event\write_performed;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * MCP (Model Context Protocol) web service server.
 *
 * Handles JSON-RPC 2.0 requests following the current MCP specification.
 * Implements:
 * - Streamable HTTP transport (POST/GET/OPTIONS/DELETE),
 * - multi-version protocol negotiation (initialize + MCP-Protocol-Version),
 * - tools/list with pagination, annotations, and structured output schemas,
 * - tools/call with both AI-native and raw Moodle Web Service tool sources,
 * - business errors as MCP isError tool results (LLM self-correction),
 * - Origin/CORS validation, rate limiting, emergency disable.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server extends webservice_base_server {
    /** @var string Server name advertised in serverInfo. */
    public const SERVER_NAME = 'Moodle MCP Server';

    /** @var string Server semantic version advertised in serverInfo. */
    public const SERVER_VERSION = '1.1.0';

    /** @var string HTTP request method. */
    protected string $httpmethod = 'GET';

    /** @var request|null Parsed MCP request object. */
    protected ?request $mcprequest = null;

    /** @var string Negotiated MCP protocol version (defaults to LEGACY per spec). */
    protected string $protocolversion = protocol::LEGACY;

    /** @var string|null Inbound Origin header. */
    protected ?string $origin = null;

    /** @var bool Whether the inbound token came from the query string. */
    protected bool $tokenfromquery = false;

    /**
     * Constructor.
     *
     * @param int $authmethod Authentication method.
     */
    public function __construct(int $authmethod) {
        parent::__construct($authmethod);
        $this->wsname = 'elediamcp';
    }

    /**
     * Main entry point.
     *
     * NOTE: do NOT wrap this body in try { ... } finally { die; } — `die` in a
     * finally block runs before any uncaught exception reaches the registered
     * exception_handler, silently swallowing errors with an empty 200 response.
     *
     * @return void
     */
    public function run(): void {
        $this->capture_request_metadata();
        $this->set_headers();

        if (security::is_emergency_disabled()) {
            $this->emit_emergency_disabled();
            die;
        }

        if (!$this->ensure_origin_allowed()) {
            die;
        }

        raise_memory_limit(MEMORY_EXTRA);
        external_api::set_timeout();

        set_exception_handler([$this, 'exception_handler']);

        $this->parse_request();

        if ($this->httpmethod !== 'POST') {
            $this->handle_non_post();
            die;
        }

        if (!$this->ensure_request_size_ok()) {
            die;
        }

        $this->adopt_protocol_version_from_header();

        if (!$this->ensure_rate_limit_ok()) {
            die;
        }

        if (empty($this->functionname)) {
            $this->authenticate_user();
            $this->require_mcp_capability();
            $this->handle_mcp_method();
            $this->session_cleanup();
            die;
        }

        // Tools/call: AI-native tool path first, then fall through to parent for raw WS.
        $aiclass = tool_provider::find_ai_tool((string) $this->functionname);
        if ($aiclass !== null) {
            $this->authenticate_user();
            $this->require_mcp_capability();
            $this->dispatch_ai_tool($aiclass);
            $this->session_cleanup();
            die;
        }

        if (!premium::has_mcp_tools()) {
            throw new \webservice_access_exception('accessexception');
        }

        // Parent's run() ends with die; the rest of this method is unreachable.
        parent::run();
    }

    /**
     * Authenticate the token holder and, unless disabled, enforce that the token
     * belongs to a configured MCP external service.
     *
     * Overriding the base authenticator covers every entry path (MCP methods,
     * AI-native tools and raw Web Service functions via parent::run()). After
     * authentication, {@see webservice_server::$restricted_serviceid} holds the
     * token's external service id.
     *
     * @return void
     * @throws \webservice_access_exception When the service is not an MCP service.
     */
    protected function authenticate_user(): void {
        parent::authenticate_user();

        if (!security::enforce_mcp_service()) {
            return;
        }
        if (!token_manager::is_mcp_service((int) $this->restricted_serviceid)) {
            throw new \webservice_access_exception(
                get_string('err_not_mcp_service', 'webservice_elediamcp')
            );
        }
    }

    /**
     * Enforce webservice/elediamcp:use before handling MCP requests.
     *
     * @return void
     * @throws \core\exception\required_capability_exception
     */
    protected function require_mcp_capability(): void {
        $context = \core\context\system::instance();
        if (has_capability('webservice/elediamcp:use', $context, $this->userid ?: null)) {
            return;
        }
        // Site administrators always pass.
        if (!empty($this->userid) && is_siteadmin($this->userid)) {
            return;
        }
        throw new \core\exception\required_capability_exception(
            $context,
            'webservice/elediamcp:use',
            'nopermissions',
            'error'
        );
    }

    /**
     * Capture inbound HTTP method and Origin header.
     *
     * @return void
     */
    protected function capture_request_metadata(): void {
        $this->httpmethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $this->origin = $this->read_header('Origin');
    }

    /**
     * Parse and validate the incoming JSON-RPC request body and extract the token.
     *
     * @return void
     */
    protected function parse_request(): void {
        parent::set_web_service_call_settings();

        $this->token = $this->extract_token();

        $rawbody = $this->httpmethod === 'POST' ? file_get_contents('php://input') : '';
        if ($this->httpmethod === 'POST' && $rawbody !== false && $rawbody !== '') {
            $this->mcprequest = request::from_string($rawbody);
            if ($this->is_tool_call()) {
                $this->extract_tool_call();
            }
        }
    }

    /**
     * Determine whether the incoming MCP request represents a tools/call invocation.
     *
     * @return bool
     */
    private function is_tool_call(): bool {
        return !empty($this->mcprequest->method) && $this->mcprequest->method === 'tools/call';
    }

    /**
     * Extract the function name and parameters from a tools/call request.
     *
     * @return void
     * @throws moodle_exception If the tool name is missing.
     */
    protected function extract_tool_call(): void {
        if (empty($this->mcprequest->params) || empty($this->mcprequest->params['name'])) {
            throw new moodle_exception('err_missing_tool_name', 'webservice_elediamcp');
        }

        $this->functionname = $this->mcprequest->params['name'];
        $this->parameters = $this->mcprequest->params['arguments'] ?? [];

        // Skip coercion for AI tools (they manage their own validation).
        if (tool_provider::find_ai_tool((string) $this->functionname) !== null) {
            return;
        }

        $this->coerce_parameters();
    }

    /**
     * Coerce raw-function parameters to match Moodle external function type expectations.
     *
     * @return void
     */
    protected function coerce_parameters(): void {
        if (empty($this->functionname) || empty($this->parameters) || !is_array($this->parameters)) {
            return;
        }

        try {
            $info = external_api::external_function_info($this->functionname);
            if ($info === null || $info->parameters_desc === null) {
                return;
            }
            $this->parameters = $this->coerce_value($this->parameters, $info->parameters_desc);
        } catch (Exception $ex) {
            return;
        }
    }

    /**
     * Recursively coerce a value to match the expected external description type.
     *
     * @param mixed $value Incoming value.
     * @param external_description $desc Expected description.
     * @return mixed Coerced value.
     */
    protected function coerce_value(mixed $value, external_description $desc): mixed {
        if ($desc instanceof external_single_structure) {
            if (!is_array($value)) {
                $value = [];
            }
            foreach ($desc->keys as $key => $subdesc) {
                if (array_key_exists($key, $value)) {
                    $value[$key] = $this->coerce_value($value[$key], $subdesc);
                }
            }
            return $value;
        }

        if ($desc instanceof external_multiple_structure) {
            if ($value === '' || $value === null || (is_string($value) && trim($value) === '')) {
                return [];
            }
            if (!is_array($value)) {
                return [];
            }
            foreach ($value as $index => $item) {
                $value[$index] = $this->coerce_value($item, $desc->content);
            }
            return $value;
        }

        if ($desc instanceof external_value) {
            return $this->coerce_scalar($value, $desc);
        }

        return $value;
    }

    /**
     * Coerce a scalar value to match the expected external_value type.
     *
     * @param mixed $value Incoming value.
     * @param external_value $desc Expected description.
     * @return mixed Coerced value.
     */
    protected function coerce_scalar(mixed $value, external_value $desc): mixed {
        switch ($desc->type) {
            case PARAM_INT:
                if (is_numeric($value)) {
                    return (int) $value;
                }
                break;
            case PARAM_FLOAT:
                if (is_numeric($value)) {
                    return (float) $value;
                }
                break;
            case PARAM_BOOL:
                if (is_string($value)) {
                    $lower = strtolower($value);
                    if (in_array($lower, ['true', '1', 'yes'], true)) {
                        return true;
                    }
                    if (in_array($lower, ['false', '0', 'no', ''], true)) {
                        return false;
                    }
                }
                break;
        }
        return $value;
    }

    /**
     * Extract the authentication token from the Authorization header or
     * the wstoken query/form parameter.
     *
     * @return string|null
     */
    protected function extract_token(): ?string {
        $auth = $this->read_header('Authorization');
        if (!empty($auth) && preg_match('/Bearer\s+(\S+)/i', $auth, $matches)) {
            $this->tokenfromquery = false;
            return $matches[1];
        }

        if (!security::allow_token_in_query()) {
            // Reject silently here; missing tokens will trigger 401 downstream.
            return null;
        }

        $token = optional_param('wstoken', null, PARAM_ALPHANUMEXT);
        if (!empty($token)) {
            $this->tokenfromquery = true;
            if (!headers_sent()) {
                header('Deprecation: true');
                header('Sunset: Wed, 31 Dec 2025 23:59:59 GMT');
            }
            return $token;
        }
        return null;
    }

    /**
     * Read an HTTP header in a case-insensitive manner, falling back to common
     * CGI/FPM $_SERVER aliases.
     *
     * @param string $name Header name.
     * @return string|null
     */
    protected function read_header(string $name): ?string {
        if (function_exists('getallheaders')) {
            $headers = array_change_key_case(getallheaders(), CASE_LOWER);
            if (!empty($headers[strtolower($name)])) {
                return (string) $headers[strtolower($name)];
            }
        }
        $candidates = [
            'HTTP_' . strtoupper(str_replace('-', '_', $name)),
            'REDIRECT_HTTP_' . strtoupper(str_replace('-', '_', $name)),
            $name,
        ];
        foreach ($candidates as $key) {
            if (!empty($_SERVER[$key])) {
                return (string) $_SERVER[$key];
            }
        }
        return null;
    }

    /**
     * Honour the MCP-Protocol-Version request header.
     *
     * Per spec, if the header is missing the server SHOULD assume 2025-03-26.
     * If present but unsupported, the server MUST respond with 400.
     *
     * @return void
     */
    protected function adopt_protocol_version_from_header(): void {
        $header = $this->read_header('MCP-Protocol-Version');
        if ($header === null || $header === '') {
            return;
        }
        if (!protocol::is_supported($header)) {
            http_response_code(400);
            echo $this->safe_json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => 'Unsupported MCP-Protocol-Version',
                    'data' => ['supported' => protocol::SUPPORTED],
                ],
                'id' => null,
            ]);
            die;
        }
        $this->protocolversion = $header;
    }

    /**
     * Handle non-POST HTTP methods (CORS preflight, server-info GET, DELETE).
     *
     * @return void
     */
    protected function handle_non_post(): void {
        if ($this->httpmethod === 'OPTIONS') {
            http_response_code(204);
            return;
        }
        if ($this->httpmethod === 'DELETE') {
            http_response_code(405);
            header('Allow: POST, GET, OPTIONS');
            return;
        }
        if ($this->httpmethod === 'GET') {
            $accept = (string) $this->read_header('Accept');
            if (str_contains($accept, 'text/event-stream')) {
                // SSE is not currently offered by this server.
                http_response_code(405);
                header('Allow: POST');
                return;
            }
            $this->send_server_info();
            return;
        }
        http_response_code(405);
        header('Allow: POST, GET, OPTIONS');
    }

    /**
     * Verify the Origin header against the admin allow-list.
     *
     * @return bool True if the request may continue, false otherwise (in which
     *              case the response has already been written).
     */
    protected function ensure_origin_allowed(): bool {
        if (security::is_origin_allowed($this->origin)) {
            return true;
        }
        http_response_code(403);
        echo $this->safe_json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => get_string('err_forbidden_origin', 'webservice_elediamcp'),
            ],
            'id' => null,
        ]);
        return false;
    }

    /**
     * Ensure the request body does not exceed the configured maximum size.
     *
     * @return bool True if OK, false otherwise (response already sent).
     */
    protected function ensure_request_size_ok(): bool {
        $contentlength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentlength > 0 && $contentlength > security::max_request_size()) {
            http_response_code(413);
            echo $this->safe_json_encode([
                'jsonrpc' => '2.0',
                'error' => [
                    'code' => -32600,
                    'message' => get_string('err_request_too_large', 'webservice_elediamcp'),
                ],
                'id' => null,
            ]);
            return false;
        }
        return true;
    }

    /**
     * Enforce the per-token / per-IP rate limit.
     *
     * @return bool True if OK, false otherwise (response already sent).
     */
    protected function ensure_rate_limit_ok(): bool {
        $key = security::rate_limit_key($this->token);
        $result = security::enforce_rate_limit($key);
        if ($result['remaining_minute'] >= 0) {
            header('X-RateLimit-Remaining-Minute: ' . $result['remaining_minute']);
        }
        if ($result['remaining_hour'] >= 0) {
            header('X-RateLimit-Remaining-Hour: ' . $result['remaining_hour']);
        }
        if ($result['allowed']) {
            return true;
        }

        http_response_code(429);
        header('Retry-After: ' . $result['retry_after']);
        echo $this->safe_json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => get_string('err_rate_limit_exceeded', 'webservice_elediamcp', $result['retry_after']),
                'data' => ['retry_after' => $result['retry_after']],
            ],
            'id' => $this->mcprequest->id ?? null,
        ]);
        return false;
    }

    /**
     * Send a 503 emergency-disabled response.
     *
     * @return void
     */
    protected function emit_emergency_disabled(): void {
        http_response_code(503);
        header('Retry-After: 3600');
        echo $this->safe_json_encode([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32000,
                'message' => get_string('err_emergency_disabled', 'webservice_elediamcp'),
            ],
            'id' => null,
        ]);
    }

    /**
     * Handle MCP methods that do not map directly to a tool invocation.
     *
     * @return void
     */
    protected function handle_mcp_method(): void {
        if (!($this->mcprequest instanceof request) || !isset($this->mcprequest->method)) {
            http_response_code(400);
            echo $this->safe_json_encode([
                'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
                'error' => ['code' => -32600, 'message' => 'Invalid Request'],
                'id' => $this->mcprequest->id ?? null,
            ]);
            return;
        }

        switch ($this->mcprequest->method) {
            case 'initialize':
                $this->negotiate_initialize();
                $this->send_initialize_response();
                break;

            case 'notifications/initialized':
                http_response_code(202);
                break;

            case 'ping':
                echo $this->safe_json_encode([
                    'jsonrpc' => $this->mcprequest->jsonrpc,
                    'id' => $this->mcprequest->id,
                    'result' => new \stdClass(),
                ]);
                break;

            case 'tools/list':
                $this->send_tools_list_response();
                break;

            case 'resources/list':
            case 'prompts/list':
                // Capability not advertised; respond with empty list for friendly clients.
                echo $this->safe_json_encode([
                    'jsonrpc' => $this->mcprequest->jsonrpc,
                    'id' => $this->mcprequest->id,
                    'result' => $this->mcprequest->method === 'resources/list'
                        ? ['resources' => []]
                        : ['prompts' => []],
                ]);
                break;

            default:
                if ($this->mcprequest->id === null) {
                    // Notification: silently accept per JSON-RPC 2.0.
                    http_response_code(202);
                    break;
                }
                echo $this->safe_json_encode([
                    'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
                    'error' => ['code' => -32601, 'message' => 'Method not found'],
                    'id' => $this->mcprequest->id,
                ]);
                break;
        }
    }

    /**
     * Negotiate the protocol version requested by an initialize call.
     *
     * @return void
     */
    protected function negotiate_initialize(): void {
        $requested = null;
        if (!empty($this->mcprequest->params) && is_array($this->mcprequest->params)) {
            $requested = $this->mcprequest->params['protocolVersion'] ?? null;
        }
        $this->protocolversion = protocol::negotiate(is_string($requested) ? $requested : null);
    }

    /**
     * Emit server-info JSON for GET requests (browser/debugging convenience).
     *
     * @return void
     */
    protected function send_server_info(): void {
        echo $this->safe_json_encode([
            'name' => self::SERVER_NAME,
            'version' => self::SERVER_VERSION,
            'protocolVersion' => protocol::LATEST,
            'supportedProtocolVersions' => protocol::SUPPORTED,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'oauth' => [
                'protectedResourceMetadata' => $this->oauth_metadata_url(),
            ],
        ]);
    }

    /**
     * Build the URL of the OAuth Protected Resource metadata document.
     *
     * @return string
     */
    protected function oauth_metadata_url(): string {
        global $CFG;
        return $CFG->wwwroot . '/webservice/elediamcp/.well-known/oauth-protected-resource';
    }

    /**
     * Send the MCP initialize response.
     *
     * @return void
     */
    protected function send_initialize_response(): void {
        $result = [
            'protocolVersion' => $this->protocolversion,
            'capabilities' => [
                'tools' => ['listChanged' => true],
            ],
            'serverInfo' => [
                'name' => self::SERVER_NAME,
                'version' => self::SERVER_VERSION,
            ],
            'instructions' => get_string('server_instructions', 'webservice_elediamcp'),
        ];

        echo $this->safe_json_encode([
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ]);
    }

    /**
     * Send the tools/list response (paginated).
     *
     * @return void
     */
    protected function send_tools_list_response(): void {
        $cursor = null;
        if (!empty($this->mcprequest->params) && is_array($this->mcprequest->params)) {
            $cursor = isset($this->mcprequest->params['cursor'])
                ? (string) $this->mcprequest->params['cursor']
                : null;
        }
        $alltools = tool_provider::get_tools((string) $this->token, $this->protocolversion);
        $page = tool_provider::paginate($alltools, $cursor);

        $result = ['tools' => $page['tools']];
        if ($page['nextCursor'] !== null) {
            $result['nextCursor'] = $page['nextCursor'];
        }

        echo $this->safe_json_encode([
            'jsonrpc' => $this->mcprequest->jsonrpc,
            'id' => $this->mcprequest->id,
            'result' => $result,
        ]);
    }

    /**
     * Dispatch a tools/call to an AI-native tool implementation.
     *
     * @param class-string<ai_tool> $class Tool class implementing ai_tool.
     * @return void
     */
    protected function dispatch_ai_tool(string $class): void {
        global $USER;

        $name = $class::name();
        $arguments = is_array($this->parameters) ? $this->parameters : [];
        $start = microtime(true);
        $iserror = false;

        try {
            $output = $class::execute($arguments, $USER);
            $this->emit_tool_result($output, false);
        } catch (tool_exception $ex) {
            $iserror = true;
            $this->emit_tool_result([
                'error' => $ex->getMessage(),
                'context' => $ex->get_context(),
            ], true, $ex->getMessage());
        } catch (\Throwable $ex) {
            $iserror = true;
            $this->log_throwable_for_debug($ex);
            $this->emit_tool_result([
                'error' => $ex->getMessage(),
            ], true, $ex->getMessage());
        } finally {
            $this->record_tool_invocation($name, $iserror, $start, true);
        }
    }

    /**
     * Standard-function response handler (called by parent::run()).
     *
     * Returns a CallToolResult shaped per the negotiated protocol version.
     * Moodle business exceptions are converted to isError:true tool results
     * so the LLM can self-correct, instead of JSON-RPC errors which abort
     * the conversation in most MCP clients.
     *
     * @return void
     */
    protected function send_response(): void {
        $name = (string) ($this->functionname ?? 'unknown');
        $start = microtime(true);
        $validatedvalues = null;

        try {
            if ($this->function->returns_desc !== null) {
                $validatedvalues = external_api::clean_returnvalue(
                    $this->function->returns_desc,
                    $this->returns
                );
            } else {
                $validatedvalues = $this->returns;
            }
        } catch (Exception $ex) {
            $this->emit_tool_result(['error' => $ex->getMessage()], true, $ex->getMessage());
            $this->record_tool_invocation($name, true, $start, false);
            return;
        }

        $this->emit_tool_result($validatedvalues, false);
        $this->record_tool_invocation($name, false, $start, false);
    }

    /**
     * Send an error response when running the standard function flow.
     *
     * Moodle business exceptions are converted to MCP tool execution errors
     * (result.isError = true) so that the LLM can self-correct, instead of
     * JSON-RPC protocol errors which abort the conversation in most clients.
     *
     * @param Exception|null $ex Caught exception, if any.
     * @return void
     */
    protected function send_error($ex = null): void {
        if ($ex !== null && debugging('', DEBUG_MINIMAL)) {
            $this->log_exception_for_debug($ex);
        }

        // Tools/call: surface as an isError result.
        if (!empty($this->functionname) && $ex !== null) {
            $name = (string) $this->functionname;
            $this->emit_tool_result(['error' => $ex->getMessage()], true, $ex->getMessage());
            $this->record_tool_invocation($name, true, microtime(true), false);
            return;
        }

        // Non-tool-call (e.g. authentication failure): JSON-RPC error.
        if (!$this->is_authenticated_for_request($ex)) {
            $this->emit_authentication_required($ex);
            return;
        }

        echo $this->safe_json_encode($this->generate_error($ex));
    }

    /**
     * Emit a CallToolResult-shaped response, applying the legacy envelope when
     * required by the negotiated protocol version.
     *
     * @param mixed $structured Structured payload (already validated).
     * @param bool $iserror Whether the result represents an execution error.
     * @param string|null $errormessage Optional plain text for the LLM.
     * @return void
     */
    protected function emit_tool_result(mixed $structured, bool $iserror, ?string $errormessage = null): void {
        $envelope = protocol::uses_result_envelope($this->protocolversion);
        $structuredcontent = $envelope ? ['result' => $structured] : $structured;
        $text = $iserror && $errormessage !== null
            ? $errormessage
            : json_encode($structuredcontent, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $result = [
            'content' => [[
                'type' => 'text',
                'text' => $text,
            ]],
        ];

        if ($iserror) {
            $result['isError'] = true;
        }

        // StructuredContent is required when a tool defines an outputSchema.
        $result['structuredContent'] = $structuredcontent;

        echo $this->safe_json_encode([
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'id' => $this->mcprequest->id ?? null,
            'result' => $result,
        ]);
    }

    /**
     * Determine whether the response should be returned as a 401 Authentication
     * Required instead of a regular JSON-RPC error.
     *
     * @param Exception|null $ex Caught exception.
     * @return bool
     */
    protected function is_authenticated_for_request(?Exception $ex): bool {
        if ($ex === null) {
            return true;
        }
        if ($ex instanceof moodle_exception) {
            $code = $ex->errorcode ?? '';
            $authcodes = ['invalidtoken', 'accessexception', 'sitemaintenance',
                'invalid_parameter_exception', 'enabledirectaccess'];
            return !in_array($code, $authcodes, true);
        }
        return true;
    }

    /**
     * Emit a 401 with WWW-Authenticate hint for OAuth-aware clients.
     *
     * @param Exception|null $ex Caught exception.
     * @return void
     */
    protected function emit_authentication_required(?Exception $ex): void {
        http_response_code(401);
        $resource = $this->oauth_metadata_url();
        header(sprintf(
            'WWW-Authenticate: Bearer realm="Moodle MCP", resource_metadata="%s"',
            $resource
        ));
        echo $this->safe_json_encode([
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'error' => [
                'code' => -32001,
                'message' => $ex !== null ? $ex->getMessage() : 'Authentication required',
                'data' => [
                    'oauth_protected_resource' => $resource,
                ],
            ],
            'id' => $this->mcprequest->id ?? null,
        ]);
    }

    /**
     * Build a standard JSON-RPC error payload from a Moodle exception.
     *
     * @param Exception|null $ex Caught exception.
     * @return array<string, mixed>
     */
    protected function generate_error(?Exception $ex): array {
        if ($ex === null) {
            return [
                'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal error'],
                'id' => $this->mcprequest->id ?? null,
            ];
        }

        $errordata = [
            'exception' => get_class($ex),
            'message' => $ex->getMessage(),
        ];

        if (isset($ex->errorcode)) {
            $errordata['errorcode'] = $ex->errorcode;
        }

        if (debugging() && isset($ex->debuginfo)) {
            $errordata['debuginfo'] = $ex->debuginfo;
        }

        $code = -32603;
        if (isset($ex->code) && is_numeric($ex->code)) {
            $code = (int) $ex->code;
        }

        return [
            'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
            'error' => [
                'code' => $code,
                'message' => $ex->getMessage(),
                'data' => $errordata,
            ],
            'id' => $this->mcprequest->id ?? null,
        ];
    }

    /**
     * Set the response headers (Content-Type, caching, CORS).
     *
     * @return void
     */
    protected function set_headers(): void {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: private, must-revalidate, max-age=0');
        header('Expires: ' . gmdate('D, d M Y H:i:s', 0) . ' GMT');
        header('Pragma: no-cache');
        header('Vary: Origin, Authorization');
        header('X-Content-Type-Options: nosniff');

        $cors = security::resolve_cors_origin($this->origin);
        if ($cors !== null) {
            header('Access-Control-Allow-Origin: ' . $cors);
            if (security::cors_allows_credentials($this->origin)) {
                header('Access-Control-Allow-Credentials: true');
            }
            header('Access-Control-Expose-Headers: Mcp-Session-Id, Mcp-Protocol-Version, Retry-After, WWW-Authenticate');
        }
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, '
            . 'Mcp-Session-Id, Mcp-Protocol-Version, Last-Event-ID, Accept');
        header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
        header('Access-Control-Max-Age: 600');
    }

    /**
     * Record an MCP tool invocation in the Moodle event log.
     *
     * @param string $toolname Tool name.
     * @param bool $iserror Whether the call ended in error.
     * @param float $start Timing start (microtime(true)).
     * @param bool $aitool Whether this was an AI-native tool.
     * @return void
     */
    protected function record_tool_invocation(string $toolname, bool $iserror, float $start, bool $aitool): void {
        try {
            $durationms = (int) round((microtime(true) - $start) * 1000);
            $userid = (int) ($this->userid ?? 0);

            $event = tool_invoked::create([
                'context' => \core\context\system::instance(),
                'userid' => $userid > 0 ? $userid : 0,
                'other' => [
                    'toolname' => $toolname,
                    'iserror' => $iserror,
                    'durationms' => $durationms,
                    'aitool' => $aitool,
                    'tokenfromquery' => $this->tokenfromquery,
                    'protocolversion' => $this->protocolversion,
                ],
            ]);
            $event->trigger();

            if (!$iserror && $this->is_write_tool($toolname, $aitool)) {
                $write = write_performed::create([
                    'context' => \core\context\system::instance(),
                    'userid' => $userid > 0 ? $userid : 0,
                    'other' => ['toolname' => $toolname],
                ]);
                $write->trigger();
            }
        } catch (Exception $ex) {
            // Never let auditing break the response.
            debugging('MCP audit logging failed: ' . $ex->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Determine whether the named tool is a write/state-changing tool.
     *
     * @param string $toolname Tool name.
     * @param bool $aitool Whether the tool is AI-native.
     * @return bool
     */
    protected function is_write_tool(string $toolname, bool $aitool): bool {
        if ($aitool) {
            $class = tool_provider::find_ai_tool($toolname);
            if ($class === null) {
                return false;
            }
            $ann = $class::annotations();
            return !empty($ann['destructiveHint']) || empty($ann['readOnlyHint']);
        }
        try {
            $info = external_api::external_function_info($toolname);
            if ($info === null) {
                return false;
            }
            $ann = tool_provider::infer_annotations($info);
            return !empty($ann['destructiveHint']) || empty($ann['readOnlyHint']);
        } catch (Exception $ex) {
            return false;
        }
    }

    /**
     * Safely encode data to JSON.
     *
     * @param mixed $data Data to encode.
     * @return string
     */
    protected function safe_json_encode(mixed $data): string {
        if (defined('JSON_THROW_ON_ERROR')) {
            try {
                return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            } catch (\JsonException $ex) {
                return json_encode([
                    'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
                    'error' => ['code' => -32603, 'message' => 'Internal JSON encoding error'],
                    'id' => $this->mcprequest->id ?? null,
                ]);
            }
        }
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return json_encode([
                'jsonrpc' => $this->mcprequest->jsonrpc ?? '2.0',
                'error' => ['code' => -32603, 'message' => 'Internal JSON encoding error'],
                'id' => $this->mcprequest->id ?? null,
            ]);
        }
        return $encoded;
    }

    /**
     * Log rich exception information when debugging is enabled.
     *
     * @param Exception $ex Caught exception.
     * @return void
     */
    protected function log_exception_for_debug(Exception $ex): void {
        $info = get_exception_info($ex);
        $message = 'MCP exception handler: ' . $info->message .
            ' Debug: ' . ($info->debuginfo ?? '') . "\n" .
            format_backtrace($info->backtrace ?? [], true);
        debugging($message);
    }

    /**
     * Log rich PHP Error / Throwable information when debugging is enabled.
     *
     * @param \Throwable $ex Caught throwable.
     * @return void
     */
    protected function log_throwable_for_debug(\Throwable $ex): void {
        if (!debugging('', DEBUG_MINIMAL)) {
            return;
        }
        $message = sprintf(
            'MCP AI tool throwable: %s: %s in %s:%d',
            get_class($ex),
            $ex->getMessage(),
            $ex->getFile(),
            $ex->getLine()
        );
        debugging($message . "\n" . $ex->getTraceAsString(), DEBUG_DEVELOPER);
    }
}
