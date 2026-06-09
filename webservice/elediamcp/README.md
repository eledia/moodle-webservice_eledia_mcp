# Moodle MCP Web Service Plugin

A Moodle web service protocol plugin that implements the **Model Context Protocol (MCP)** as an **AI-native integration layer** for Moodle. It exposes a curated set of stable, LLM-friendly tools on top of Moodle's external functions, while remaining fully compatible with the underlying Moodle Web Service permission model. Designed for AI tutors, agents, RAG systems, and MCP-compatible clients such as Claude Desktop, Cursor, Windsurf, Langflow and the eLeDia.ai Tutor Agent.

**Developed by [eLeDia GmbH](https://eledia.de), Berlin.**

## Highlights (0.8.0)

- **Token management**: self-service UI for users to create, view metadata for, and revoke their own MCP tokens, plus an internal PHP API for trusted first-party plugins to provision and revoke user-scoped tokens. See [Token management](#token-management).
- **MCP multi-version support**: negotiates `2025-11-25`, `2025-06-18` and `2025-03-26` (legacy) per the current MCP specification.
- **Streamable HTTP transport** with full lifecycle (`initialize`, `notifications/initialized`, `ping`, `tools/list`, `tools/call`).
- **AI-native tool layer**: stable, curated tools (`moodle_me`, `moodle_verify_user_context`, …) layered on top of raw Moodle Web Services.
- **Canonical structured output**: matches MCP 2025-06-18+ shape; legacy `{ "result": … }` envelope still emitted for older clients.
- **Tool annotations**: `readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint` and `title` advertised on every tool.
- **Tool execution errors as `isError: true`**: business errors are surfaced as MCP tool results so LLMs can self-correct, instead of JSON-RPC protocol errors that abort the conversation.
- **`tools/list` pagination** with opaque cursors.
- **Security hardening**: Origin validation, CORS allow-list, optional query-string token (off by default), rate limiting, request-size limits, emergency disable.
- **OAuth Protected Resource discovery** (`/.well-known/oauth-protected-resource`) and `WWW-Authenticate` on 401 for zero-config client onboarding.
- **Comprehensive audit logging** via Moodle events (`tool_invoked`, `write_performed`, `context_verified`).
- **Service-scoped** tool discovery (only functions assigned to the authenticated external service are exposed).

## Architecture

```
public/webservice/elediamcp/
├── .well-known/
│   └── oauth-protected-resource.php   # RFC 9728 metadata endpoint
├── classes/
│   ├── event/
│   │   ├── context_verified.php
│   │   ├── tool_invoked.php
│   │   └── write_performed.php
│   ├── local/
│   │   ├── ai/
│   │   │   ├── ai_tool.php            # AI tool interface
│   │   │   ├── registry.php           # AI tool registry
│   │   │   ├── tool_exception.php     # Business error -> isError
│   │   │   └── tools/
│   │   │       ├── moodle_me.php
│   │   │       └── moodle_verify_user_context.php
│   │   ├── protocol.php               # Protocol version negotiation
│   │   ├── request.php                # JSON-RPC 2.0 request parser
│   │   ├── security.php               # Origin / CORS / rate limit / config
│   │   ├── server.php                 # Main MCP server
│   │   └── tool_provider.php          # Tool catalogue assembly
│   └── privacy/
│       └── provider.php
├── db/
│   ├── access.php                     # Capabilities
│   └── caches.php                     # MUC cache definitions
├── lang/en/webservice_elediamcp.php
├── lib.php
├── locallib.php
├── server.php                         # MCP endpoint
├── settings.php                       # Admin settings
└── version.php
```

## What is MCP?

The **Model Context Protocol (MCP)** is an open protocol that standardises how applications provide context and tools to AI assistants and Large Language Models. This plugin bridges Moodle's web service API with MCP, allowing AI agents to interact with Moodle through a standard interface while honouring every existing Moodle capability check.

## Requirements

- **Moodle** 4.2 or higher
- **PHP** 8.1 or higher
- **Web services** enabled in Moodle

## Installation

1. Place the plugin folder into your Moodle installation:
   ```bash
   cd /path/to/moodle/webservice
   # Copy or clone the plugin folder here and ensure it is named "mcp"
   ```
2. Visit **Site administration → Notifications** to complete the installation.
3. The plugin is installed as `webservice_elediamcp`.

## Configuration

### 1. Enable web services

**Site administration → Advanced features**: enable **Enable web services**.

### 2. Enable the MCP protocol

**Site administration → Plugins → Web services → Manage protocols**: enable **Model Context Protocol**.

### 3. Configure MCP-specific settings

**Site administration → Plugins → Web services → Model Context Protocol** exposes:

| Setting | Default | Purpose |
|---|---|---|
| **Allowed CORS origins** | _(empty)_ | One origin per line. Empty = same-origin only. Use `*` to allow any (not recommended). |
| **Allow token in query string** | Off | When off (recommended), only `Authorization: Bearer` is accepted. When on, `?wstoken=` is also accepted and a `Deprecation` header is emitted. |
| **Expose raw Moodle Web Service functions** | On | When off, only the curated AI-native tools are advertised. Recommended for production AI agents. |
| **Rate limit per minute / per hour** | 60 / 600 | Per-token (or per-IP if unauthenticated). |
| **Maximum request body size** | 1 MiB | Larger requests are rejected with HTTP 413. |
| **Default page size for tools/list** | 50 | Larger catalogues are paginated through `nextCursor`. |
| **Emergency disable** | Off | Returns HTTP 503 for every request. Incident-response kill switch. |

### 4. Create an external service

**Site administration → Server → Web services → External services**.

Add an MCP service, e.g.

- **Name**: MCP Service
- **Short name**: mcp_service
- **Enabled**: Yes
- **Authorized users only**: Yes (recommended)

Add the external functions you want to expose. The AI-native tools (`moodle_me`, `moodle_verify_user_context`) are always available, independent of the service's function list.

### 5. Create a token

**Site administration → Server → Web services → Manage tokens** → **Add**:

- **User**: the user this token authenticates as
- **Service**: the service created above

### 6. Assign the capability

Ensure users have `webservice/elediamcp:use` (granted by default to the `user` archetype) to access the MCP endpoint. Site administrators additionally hold `webservice/elediamcp:viewcaps`, required to use the `include_capabilities` argument of `moodle_verify_user_context`.

## Usage

### Endpoint

```
https://your-moodle-site.com/webservice/elediamcp/server.php
```

Authentication uses an HTTP **Authorization** header:

```
Authorization: Bearer YOUR_TOKEN
```

The query-string fallback (`?wstoken=YOUR_TOKEN`) is **off by default** in 0.5.0. Enable it under the plugin settings if you need it for development.

### Connecting an MCP client

| Setting | Value |
|---|---|
| Transport | HTTP / Streamable HTTP |
| URL | `https://your-moodle-site.com/webservice/elediamcp/server.php` |
| Headers | `{"Authorization": "Bearer YOUR_TOKEN"}` |
| Negotiated protocol | `2025-11-25` for new clients, automatic fallback to `2025-03-26` for older ones |

#### Claude Desktop (and other stdio-only clients) via `mcp-remote`

Claude Desktop speaks MCP over stdio, so it reaches this remote HTTP server
through the [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge, which
needs [Node.js](https://nodejs.org/) (for `npx`) on the client machine. Add the
following to `claude_desktop_config.json`
(macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`,
Windows: `%APPDATA%\Claude\claude_desktop_config.json`), then fully restart
Claude Desktop:

```json
{
  "mcpServers": {
    "moodle": {
      "command": "npx",
      "args": [
        "-y",
        "mcp-remote",
        "https://your-moodle-site.com/webservice/elediamcp/server.php",
        "--header",
        "Authorization:${AUTH_HEADER}"
      ],
      "env": {
        "AUTH_HEADER": "Bearer YOUR_TOKEN"
      }
    }
  }
}
```

> The header value is passed through the `AUTH_HEADER` environment variable, and
> the `--header` argument has no space after the colon. This is the documented
> workaround for Claude Desktop stripping spaces from command arguments.

The self-service token page (**Preferences → MCP tokens**) renders this exact
snippet with your token pre-filled at creation time — copy it straight into the
config file.

Discovery for OAuth-aware clients:

```
GET https://your-moodle-site.com/webservice/elediamcp/.well-known/oauth-protected-resource
```

When a request is unauthenticated, the server responds with:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="Moodle MCP", resource_metadata="https://.../webservice/elediamcp/.well-known/oauth-protected-resource"
```

## AI-native tools (curated)

These tools are stable, low-cost, and recommended for AI agents. Every tool runs
**as the authenticated user** and enforces the same capability, enrolment and
visibility checks as the equivalent Moodle screen — they cannot be used to reach
data the user could not otherwise see. All but `moodle_send_message` are
read-only; `moodle_send_message` is the only write tool and requires an explicit
two-step confirmation.

| Tool | Type | Purpose |
|---|---|---|
| `moodle_me` | read | Identity probe: who is the authenticated user, on which site, in which language. Cheap to call every turn. |
| `moodle_verify_user_context` | read | Compact bootstrap: enumerates active enrolments with roles and groups, optionally the user's capability set (gated by `webservice/elediamcp:viewcaps`). |
| `moodle_find_user` | read | Resolves a free-text name fragment to messageable users (respects messaging privacy rules). |
| `moodle_my_courses` | read | Lists the user's enrolled courses with progress classification and search. |
| `moodle_search_courses` | read | Searches the visible course catalogue (respects course/category visibility). |
| `moodle_course_contents` | read | Lists sections and visible activities of a course the user may access. |
| `moodle_get_resource` | read | Returns the readable body of a page/book chapter/label/URL/resource by `cmid`. |
| `moodle_get_announcements` | read | Recent news-forum posts across the user's enrolled courses. |
| `moodle_calendar_upcoming` | read | Upcoming deadlines and events scoped to the user's courses/groups. |
| `moodle_my_assignments` | read | Assignment submission and grade status across enrolled courses. |
| `moodle_my_grades` | read | Course-final grades, or per-item breakdown (respects hidden grade items). |
| `moodle_send_message` | **write** | Sends a one-to-one message. Two-step: preview, then `confirm=true`. Respects `can_send_message()`. |

When **Expose raw Moodle Web Service functions** is enabled, every external
function assigned to the authenticated service is additionally exposed as an MCP
tool. This is convenient for power users but enlarges the schema surface; disable
it for production AI agents to restrict the catalogue to the curated set above.

### Example: `moodle_verify_user_context`

Request:

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "method": "tools/call",
  "params": {
    "name": "moodle_verify_user_context",
    "arguments": { "course_id": 42 }
  }
}
```

Response (MCP 2025-11-25 client):

```json
{
  "jsonrpc": "2.0",
  "id": 1,
  "result": {
    "content": [
      { "type": "text", "text": "{\"valid\":true,...}" }
    ],
    "structuredContent": {
      "valid": true,
      "user": { "id": 42, "fullname": "Maria Mueller", "lang": "de" },
      "courses": [
        { "id": 42, "shortname": "STAT101", "roles": ["student"], "groups": ["Group B"] }
      ],
      "summary": "You are authenticated as Maria Mueller with access to 1 active course (roles: student)."
    }
  }
}
```

### Error semantics

Business errors (capability denied, validation failures, not found) are returned as MCP tool execution errors so the LLM can self-correct:

```json
{
  "jsonrpc": "2.0",
  "id": 7,
  "result": {
    "isError": true,
    "content": [{ "type": "text", "text": "Course 9999 does not exist or you do not have access to it." }],
    "structuredContent": { "error": "Course 9999 does not exist or you do not have access to it." }
  }
}
```

Protocol errors (malformed request, unknown method, unsupported protocol version) remain JSON-RPC errors with the appropriate code.

## Supported MCP methods

| Method | Description |
|---|---|
| `initialize` | Capability negotiation; honours the client's `protocolVersion`. |
| `notifications/initialized` | Acknowledged with HTTP 202 per Streamable HTTP spec. |
| `ping` | Lightweight health check. |
| `tools/list` | Paginated list of curated AI-native tools + (optionally) raw Moodle Web Service functions. Supports `cursor` / `nextCursor`. |
| `tools/call` | Invokes either an AI-native tool or a Moodle external function in the authenticated user's security context. |
| `resources/list`, `prompts/list` | Currently return an empty list. Reserved for upcoming releases. |

The `GET` method without `Accept: text/event-stream` returns a server-info JSON. `OPTIONS` returns 204 for CORS preflight. `DELETE` returns 405 (sessions are not currently issued).

## Security model

- **Token never appears in URLs by default.** Query-string tokens are an opt-in setting and emit a `Deprecation` header when used.
- **Origin validated** against an admin allow-list. Same-origin and server-to-server requests (no `Origin`) are always accepted.
- **CORS** reflects only allow-listed origins; never `*` together with credentials.
- **Rate limiting** per token (or per IP if unauthenticated). HTTP 429 with `Retry-After` and `X-RateLimit-Remaining-*` headers.
- **Request size limit** enforced at the entry point.
- **Capability `webservice/elediamcp:use`** checked at system context after authentication, in addition to per-function Moodle capability checks.
- **Audit events** (`webservice_elediamcp\event\tool_invoked`, `write_performed`, `context_verified`) appear in **Site admin → Reports → Logs**, and can be forwarded to external stores through Moodle's Logstore plugins.
- **Emergency disable** returns HTTP 503 immediately, before any other processing.

## Token management

Beyond the admin **Manage tokens** page, the plugin ships production-grade,
MCP-scoped token management with a clean audit trail.

### Configuring MCP services

Under **Site administration → Plugins → Web services → Model Context Protocol**,
the **MCP external services** setting selects which external services may issue
MCP tokens. Only services listed there can be chosen in the self-service UI or
targeted through the internal API. This keeps MCP credentials isolated from
unrelated web services.

### Self-service UI

Users holding `webservice/elediamcp:managetokens` (granted to the `user` archetype by
default) get an **MCP tokens** entry on their **Preferences** page. There they
can:

- **create** a token by choosing a label, an MCP service they are permitted to
  use, and an optional expiry date;
- **view metadata** for their existing tokens — label, service, creation date,
  expiry, last-used date and status (active / expired / revoked);
- **revoke** a token, which immediately deletes the backing credential.

The full token value is shown **exactly once**, right after creation. It is never
stored in clear text and never shown again — only a SHA-256 hash is retained for
correlation.

### Internal PHP API

Trusted first-party plugins provision and revoke user-scoped MCP tokens through
`\webservice_elediamcp\api`. Every call is attributed to the calling component for
auditing, and component-scoped revocation only affects tokens that component
created (self-service tokens are never touched).

```php
// Provision a token for a learner's AI tutor.
$result = \webservice_elediamcp\api::create_token(
    'local_aitutor',     // calling component (must be installed)
    $userid,             // token owner
    $serviceid,          // a configured MCP external service
    'AI tutor',          // label
    time() + WEEKSECS    // optional expiry (0 = never)
);
// Show $result->token to the user once, then discard it.
$tokenmetadata = $result->record; // metadata only, no secret

// Later: revoke a single token, or all of this component's tokens for the pair.
\webservice_elediamcp\api::revoke_token('local_aitutor', $result->record->id);
\webservice_elediamcp\api::revoke_user_service_tokens('local_aitutor', $userid, $serviceid);

// Read-only metadata listing (no secrets) and the configured service list.
$tokens   = \webservice_elediamcp\api::get_user_tokens($userid);
$services = \webservice_elediamcp\api::get_services();
```

Creation always enforces the same guarantees regardless of caller: the service
must be a configured, enabled MCP service; the target user must be active and
satisfy the service's required capability (and authorised-user list, for
restricted services); and any expiry must be in the future. Lifecycle changes
emit the `token_created` and `token_revoked` audit events, visible under
**Site administration → Reports → Logs**.

### Storage model

Authentication continues to flow through Moodle's core `external_tokens` table.
A companion `webservice_elediamcp_token` table holds the MCP-specific lifecycle and
audit metadata and **survives revocation** (the backing core token is deleted so
it can no longer authenticate, while the metadata row is flagged revoked and the
last-used timestamp is snapshotted), keeping revoked tokens auditable.

## Testing

```bash
vendor/bin/phpunit --testsuite webservice_elediamcp_testsuite
```

Test coverage includes:

- JSON-RPC 2.0 request parsing and validation
- Protocol version negotiation (`protocol_test`)
- Security helper, Origin validation, rate limiting (`security_test`)
- Tool provider, annotation inference, pagination (`tool_provider_test`, `tool_provider_extras_test`)
- AI-native tools (`ai_tools_test`)
- Server lifecycle and coercion (`server_test`)
- Token lifecycle manager: creation, revocation, expiry, listing (`token_manager_test`)
- Internal token API: component attribution and ownership scoping (`api_test`)
- Client library (`client_test`)

Behat coverage (`--tags @webservice_elediamcp`) drives the self-service UI end to end: the
Preferences link, the create → reveal-once → revoke flow, and the "no usable
service" guidance.

## Troubleshooting

| Symptom | Resolution |
|---|---|
| "Invalid token" | Verify the token, the user's `webservice/elediamcp:use` capability, and that the service is enabled. |
| 401 with `WWW-Authenticate` | The request is unauthenticated. Add `Authorization: Bearer YOUR_TOKEN`. |
| 403 "Origin not allowed by site policy" | Add the client origin to **Allowed CORS origins** in the plugin settings. |
| 429 with `Retry-After` | Rate limit exceeded; wait the indicated number of seconds or raise the limits in settings. |
| 413 "Request body exceeds the maximum allowed size" | Raise the **Maximum request body size** setting. |
| 503 with `Retry-After: 3600` | **Emergency disable** is on in plugin settings. |
| Empty tools list | Check that your service has functions added (raw tools), and that the user holds the required capabilities. AI-native tools are always present. |
| `?wstoken=` returns 401 | Enable **Allow token in query string** in plugin settings, or use the Authorization header. |

## Roadmap

See the project roadmap discussion for the planned V2 (token self-service, OAuth 2.1, write tools, resources, prompts) and V3 (semantic search, multi-tenant SaaS, elicitation, mobile/LTI integrations).

---

© 2025–2026 [eLeDia GmbH](https://eledia.de), Berlin. All rights reserved.
