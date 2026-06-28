<p align="center">
  <a href="https://eledia.de" title="eLeDia GmbH — eLearning im Dialog">
    <img src="https://eledia.de/wp-content/uploads/2025/01/cropped-eLeDia_Logo-300x112.png"
         alt="eLeDia GmbH — eLearning im Dialog"
         width="220" height="82">
  </a>
</p>

<h1 align="center">Moodle MCP Web Service</h1>

<p align="center">
  <strong>Moodle plugin · <code>webservice_elediamcp</code></strong>
  <br>
  <em>An AI-native integration layer for Moodle: the Model Context Protocol (MCP)<br>
  exposed as a curated, LLM-friendly tool surface on top of Moodle's web services —<br>
  every call honouring the existing Moodle permission model.</em>
</p>

<p align="center">
  <a href="https://moodle.org"><img alt="Moodle 4.2+ / 5.x" src="https://img.shields.io/badge/Moodle-4.2%2B%20%E2%80%A2%205.x-003366?logo=moodle&logoColor=white"></a>
  <a href="https://www.php.net"><img alt="PHP 8.1+" src="https://img.shields.io/badge/PHP-8.1%2B-0066b3?logo=php&logoColor=white"></a>
  <img alt="Maturity: Stable" src="https://img.shields.io/badge/Maturity-Stable%20%C2%B7%20v1.1.0-00834a">
  <img alt="MCP 2025-11-25" src="https://img.shields.io/badge/MCP-2025--11--25%20%E2%80%A2%202025--03--26-6b7280">
  <img alt="Privacy: GDPR ready" src="https://img.shields.io/badge/Privacy-GDPR%20provider-6b7280">
  <a href="LICENSE"><img alt="GPL v3+" src="https://img.shields.io/badge/License-GPL%20v3%2B-0066b3"></a>
</p>

<p align="center">
  <a href="#-quick-start">Quick start</a> ·
  <a href="#-highlights">Highlights</a> ·
  <a href="#-ai-native-tools">AI tools</a> ·
  <a href="#-security-model">Security</a> ·
  <a href="#-token-management">Token management</a> ·
  <a href="#-testing">Development</a> ·
  <a href="https://eledia.de"><strong>eledia.de</strong></a>
</p>

---

## ✨ At a glance

This plugin implements the **Model Context Protocol (MCP)** as a Moodle web service
protocol. It bridges Moodle's external-function API with MCP so that AI tutors,
agents, RAG systems and MCP-compatible clients — Claude Desktop, Cursor, Windsurf,
Langflow, the eLeDia.ai Tutor Agent — can talk to Moodle through a single, standard
interface.

Instead of exposing Moodle's full raw web-service surface, it offers a **curated set
of stable, denormalised, LLM-friendly tools** (`moodle_me`, `moodle_my_courses`,
`moodle_forum_discussions`, …). Every tool runs **as the authenticated user** and
enforces the same capability, enrolment and visibility checks as the equivalent
Moodle screen — it can never reach data the user could not otherwise see.

> Built and maintained by [eLeDia GmbH](https://eledia.de), Berlin.

---

## 🚀 Quick start

```bash
# 1. Drop the plugin into your Moodle install (Moodle 5.x with public/ root):
git clone https://gitlab.eledia.de/eledia_plugins/webservice/moodle-webservice_elediamcp.git \
  path/to/moodle/public/webservice/elediamcp

# 2. Trigger the install:
php path/to/moodle/admin/cli/upgrade.php --non-interactive
```

Then, as a site administrator:

1. **Enable web services** under *Site administration → Advanced features*.
2. **Enable the MCP protocol** under *Plugins → Web services → Manage protocols*.
3. **Open the MCP configuration** at *Plugins → Web services → Model Context Protocol*
   and select which **external service(s)** may issue MCP tokens.
4. **Create a token** — admins via the configuration page, users via
   *Preferences → MCP tokens* (requires `webservice/elediamcp:managetokens`).
5. **Connect a client** to the endpoint with an `Authorization: Bearer` header
   (see [Usage](#-usage)).

> The token page renders a ready-to-paste Claude Desktop snippet with your token
> pre-filled at creation time.

---

## 🧩 Highlights

- **AI-native tool layer** — 19 stable, curated tools layered on top of raw Moodle
  Web Services, designed to be stable across Moodle minor releases.
- **Token management** — self-service UI for users to create, inspect metadata for,
  and revoke their own MCP tokens, plus an internal PHP API for trusted first-party
  plugins to provision and revoke user-scoped tokens. See [Token management](#-token-management).
- **MCP multi-version support** — negotiates `2025-11-25`, `2025-06-18` and
  `2025-03-26` (legacy) per the current MCP specification.
- **Streamable HTTP transport** with the full lifecycle (`initialize`,
  `notifications/initialized`, `ping`, `tools/list`, `tools/call`).
- **Canonical structured output** — matches the MCP 2025-06-18+ shape; the legacy
  `{ "result": … }` envelope is still emitted for older clients.
- **Tool annotations** — `readOnlyHint`, `destructiveHint`, `idempotentHint`,
  `openWorldHint` and `title` advertised on every tool.
- **Tool execution errors as `isError: true`** — business errors are surfaced as MCP
  tool results so LLMs can self-correct, instead of JSON-RPC errors that abort the
  conversation.
- **`tools/list` pagination** with opaque cursors.
- **Security hardening** — Origin validation, CORS allow-list, optional query-string
  token (off by default), rate limiting, request-size limits, emergency disable.
- **OAuth Protected Resource discovery** (`/.well-known/oauth-protected-resource`)
  and `WWW-Authenticate` on 401 for zero-config client onboarding.
- **Comprehensive audit logging** via Moodle events (`tool_invoked`,
  `write_performed`, `context_verified`, `token_created`, `token_revoked`).
- **Service-scoped raw-function discovery** — when raw functions are exposed, only
  the functions assigned to the authenticated external service are advertised.

---

## 🤔 What is MCP?

The **Model Context Protocol (MCP)** is an open protocol that standardises how
applications provide context and tools to AI assistants and Large Language Models.
This plugin bridges Moodle's web service API with MCP, allowing AI agents to interact
with Moodle through a standard interface while honouring every existing Moodle
capability check.

---

## 🗂 Requirements & compatibility

| Component | Version |
|-----------|---------|
| Moodle    | **4.2 (build 2023041800) and newer**, including 5.x |
| PHP       | **8.1+** |
| Web services | Must be enabled in Moodle |

---

## 📥 Installation

1. Place the plugin folder into your Moodle installation so it resolves to
   `webservice/elediamcp` (on a Moodle 5.x `public/` root that is
   `public/webservice/elediamcp`).
2. Visit **Site administration → Notifications** to complete the installation.
3. The plugin is installed as `webservice_elediamcp`.

---

## ⚙️ Configuration

### 1. Enable web services

**Site administration → Advanced features**: enable **Enable web services**.

### 2. Enable the MCP protocol

**Site administration → Plugins → Web services → Manage protocols**: enable
**Model Context Protocol**.

### 3. Configure MCP-specific settings

**Site administration → Plugins → Web services → Model Context Protocol** exposes:

| Setting | Default | Purpose |
|---|---|---|
| **MCP external services** | _(none)_ | Which external services may issue MCP tokens. Only these appear in the self-service UI and the internal API. |
| **Allowed CORS origins** | _(empty)_ | One origin per line. Empty = same-origin only. Wildcard is rejected by the form. |
| **Allow token in query string** | Off | When off (recommended), only `Authorization: Bearer` is accepted. When on, `?wstoken=` is also accepted and a `Deprecation` header is emitted. |
| **Expose raw Moodle Web Service functions** | Off | When off, only the curated AI-native tools are advertised. Recommended for production AI agents. |
| **Rate limit per minute / per hour** | 60 / 600 | Per-token (or per-IP if unauthenticated). |
| **Maximum request body size** | 1 MiB | Larger requests are rejected with HTTP 413. |
| **Default page size for tools/list** | 50 | Larger catalogues are paginated through `nextCursor`. |
| **Token retention (days)** | 30 | How long revoked token metadata is kept before the cleanup task prunes it. |
| **Emergency disable** | Off | Returns HTTP 503 for every request. Incident-response kill switch. |

### 4. Create an external service

**Site administration → Server → Web services → External services**. Add an MCP
service (e.g. *Name*: `MCP Service`, *Short name*: `mcp_service`, *Enabled*: Yes,
*Authorized users only*: Yes — recommended), then add the raw external functions you
want to expose. The AI-native tools are always available, independent of the
service's function list. Finally select the service under **MCP external services**
(step 3) so it can issue MCP tokens.

### 5. Create a token

Admins use the **MCP configuration page**; users with
`webservice/elediamcp:managetokens` use **Preferences → MCP tokens**. The full token
value is shown **exactly once**, right after creation.

### 6. Assign the capability

Ensure users have `webservice/elediamcp:use` (granted by default to the `user`
archetype) to access the MCP endpoint. Site administrators additionally hold
`webservice/elediamcp:viewcaps`, required to use the `include_capabilities` argument
of `moodle_verify_user_context`.

---

## 🔌 Usage

### Endpoint

```
https://your-moodle-site.com/webservice/elediamcp/server.php
```

Authentication uses an HTTP **Authorization** header:

```
Authorization: Bearer YOUR_TOKEN
```

The query-string fallback (`?wstoken=YOUR_TOKEN`) is **off by default**. Enable it
under the plugin settings if you need it for development.

### Connecting an MCP client

| Setting | Value |
|---|---|
| Transport | HTTP / Streamable HTTP |
| URL | `https://your-moodle-site.com/webservice/elediamcp/server.php` |
| Headers | `{"Authorization": "Bearer YOUR_TOKEN"}` |
| Negotiated protocol | `2025-11-25` for new clients, automatic fallback to `2025-03-26` for older ones |

#### Claude Desktop (and other stdio-only clients) via `mcp-remote`

Claude Desktop speaks MCP over stdio, so it reaches this remote HTTP server through
the [`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge, which needs
[Node.js](https://nodejs.org/) (for `npx`) on the client machine. Add the following
to `claude_desktop_config.json`
(macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`,
Windows: `%APPDATA%\Claude\claude_desktop_config.json`), then fully restart Claude
Desktop:

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

> The header value is passed through the `AUTH_HEADER` environment variable, and the
> `--header` argument has no space after the colon. This is the documented workaround
> for Claude Desktop stripping spaces from command arguments.

The self-service token page (**Preferences → MCP tokens**) renders this exact snippet
with your token pre-filled at creation time — copy it straight into the config file.

Discovery for OAuth-aware clients:

```
GET https://your-moodle-site.com/webservice/elediamcp/.well-known/oauth-protected-resource
```

When a request is unauthenticated, the server responds with:

```
HTTP/1.1 401 Unauthorized
WWW-Authenticate: Bearer realm="Moodle MCP", resource_metadata="https://.../webservice/elediamcp/.well-known/oauth-protected-resource"
```

---

## 🧰 AI-native tools

These tools are stable, low-cost, and recommended for AI agents. Every tool runs **as
the authenticated user** and enforces the same capability, enrolment and visibility
checks as the equivalent Moodle screen — they cannot be used to reach data the user
could not otherwise see. All but the three write tools are read-only; write tools
require an explicit two-step confirmation.

| Tool | Type | Purpose |
|---|---|---|
| `moodle_me` | read | Identity probe: who is the authenticated user, on which site, in which language. Cheap to call every turn. |
| `moodle_verify_user_context` | read | Compact bootstrap: enumerates active enrolments with roles and groups, optionally the user's capability set (gated by `webservice/elediamcp:viewcaps`). |
| `moodle_find_user` | read | Resolves a free-text name fragment to messageable users (respects messaging privacy rules). |
| `moodle_my_courses` | read | Lists the user's enrolled courses with progress classification and search. |
| `moodle_search_courses` | read | Searches the visible course catalogue (respects course/category visibility). |
| `moodle_course_contents` | read | Lists sections and visible activities of a course the user may access. |
| `moodle_get_resource` | read | Returns the readable body of a page/book chapter/label/URL/resource by `cmid`. |
| `moodle_search_content` | read | Full-text search across accessible content via global search (graceful fallback to activity names/descriptions when disabled). |
| `moodle_get_announcements` | read | Recent news-forum posts across the user's enrolled courses. |
| `moodle_forum_discussions` | read | Course forum discussions and posts (enforces groups, Q&A gating, timed posts and private replies via the forum API). |
| `moodle_calendar_upcoming` | read | Upcoming deadlines and events scoped to the user's courses/groups. |
| `moodle_my_assignments` | read | Assignment submission and grade status across enrolled courses. |
| `moodle_my_grades` | read | Course-final grades, or per-item breakdown (respects hidden grade items). |
| `moodle_my_progress` | read | Completion progress per enrolled course, optionally per-activity states for one course. |
| `moodle_quiz_info` | read | Quizzes with timing/attempt limits and the user's **own** attempt history and best grade — never other users' attempts. |
| `moodle_my_submission_files` | read | The user's **own** latest submission for one assignment: files and online-text content. Strictly self-scoped. |
| `moodle_send_message` | **write** | Sends a one-to-one message. Two-step: preview, then `confirm=true`. Respects `can_send_message()`. |
| `moodle_create_user` | **write** | Creates a user account. Two-step confirmation; requires `moodle/user:create`. |
| `moodle_create_course` | **write** | Creates a course in a category. Two-step confirmation; requires `moodle/course:create` in the category context. |
| `moodle_update_course` | **write** | Updates course title, shortname, visibility, summary and dates. Two-step confirmation; requires `moodle/course:update`. |
| `moodle_enrol_user` | **write** | Enrols an existing user through manual enrolment. Two-step confirmation; requires `enrol/manual:enrol`. |

When **Expose raw Moodle Web Service functions** is enabled, every external function
assigned to the authenticated service is additionally exposed as an MCP tool. This is
convenient for power users but enlarges the schema surface; disable it for production
AI agents to restrict the catalogue to the curated set above.

### Error semantics

Business errors (capability denied, validation failures, not found) are returned as
MCP tool execution errors so the LLM can self-correct:

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

Protocol errors (malformed request, unknown method, unsupported protocol version)
remain JSON-RPC errors with the appropriate code.

### Supported MCP methods

| Method | Description |
|---|---|
| `initialize` | Capability negotiation; honours the client's `protocolVersion`. |
| `notifications/initialized` | Acknowledged with HTTP 202 per Streamable HTTP spec. |
| `ping` | Lightweight health check. |
| `tools/list` | Paginated list of curated AI-native tools + (optionally) raw Moodle Web Service functions. Supports `cursor` / `nextCursor`. |
| `tools/call` | Invokes either an AI-native tool or a Moodle external function in the authenticated user's security context. |
| `resources/list`, `prompts/list` | Currently return an empty list. Reserved for upcoming releases. |

`GET` without `Accept: text/event-stream` returns a server-info JSON. `OPTIONS`
returns 204 for CORS preflight. `DELETE` returns 405 (sessions are not currently
issued).

---

## 🛡 Security model

| Boundary | Enforcement |
|----------|-------------|
| Token in URLs | Off by default; query-string tokens are opt-in and emit a `Deprecation` header when used. |
| Origin | Validated against an admin allow-list. Same-origin and server-to-server requests (no `Origin`) are always accepted. |
| CORS | Reflects only allow-listed origins; never `*` together with credentials. |
| Rate limiting | Per token (or per IP if unauthenticated). HTTP 429 with `Retry-After` and `X-RateLimit-Remaining-*` headers. |
| Request size | Enforced at the entry point (HTTP 413 above the limit). |
| Endpoint capability | `webservice/elediamcp:use` checked at system context after authentication, in addition to per-function Moodle capability checks. |
| Per-tool authorisation | Each tool re-checks the relevant Moodle capability with the explicit user id and stays scoped to the authenticated user's data. |
| Audit | `tool_invoked`, `write_performed`, `context_verified`, `token_created`, `token_revoked` events under *Site admin → Reports → Logs* (forwardable via Logstore plugins). |
| Emergency disable | Returns HTTP 503 immediately, before any other processing. |

> **Note on rate limiting:** counters live in the Moodle Application cache. For strict
> production limits, configure a shared atomic cache store (e.g. Redis); the increment
> is not guaranteed to be atomic on every backend.

---

## 🔑 Token management

Beyond the admin **Manage tokens** page, the plugin ships production-grade,
MCP-scoped token management with a clean audit trail.

### Configuring MCP services

Under **Site administration → Plugins → Web services → Model Context Protocol**, the
**MCP external services** setting selects which external services may issue MCP
tokens. Only services listed there can be chosen in the self-service UI or targeted
through the internal API. This keeps MCP credentials isolated from unrelated web
services.

### Self-service UI

Users holding `webservice/elediamcp:managetokens` (granted to the `user` archetype by
default) get an **MCP tokens** entry on their **Preferences** page. There they can:

- **create** a token by choosing a label, an MCP service they are permitted to use,
  and an optional expiry date;
- **view metadata** for their existing tokens — label, service, creation date,
  expiry, last-used date and status (active / expired / revoked);
- **revoke** a token, which immediately deletes the backing credential.

The full token value is shown **exactly once**, right after creation. It is never
stored in clear text and never shown again — only a SHA-256 hash is retained for
correlation.

### Internal PHP API

Trusted first-party plugins provision and revoke user-scoped MCP tokens through
`\webservice_elediamcp\api`. Every call is attributed to the calling component for
auditing, and component-scoped revocation only affects tokens that component created
(self-service tokens are never touched).

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

Creation always enforces the same guarantees regardless of caller: the service must
be a configured, enabled MCP service; the target user must be active and satisfy the
service's required capability (and authorised-user list, for restricted services);
and any expiry must be in the future. Lifecycle changes emit the `token_created` and
`token_revoked` audit events.

### Storage model

Authentication continues to flow through Moodle's core `external_tokens` table. A
companion `webservice_elediamcp_token` table holds the MCP-specific lifecycle and
audit metadata and **survives revocation** (the backing core token is deleted so it
can no longer authenticate, while the metadata row is flagged revoked and the
last-used timestamp is snapshotted), keeping revoked tokens auditable. A scheduled
task prunes revoked metadata older than the configured retention window.

---

## 🛂 Privacy / GDPR

This plugin stores **token metadata only** — owner, service, label, timestamps and
revocation state — in `webservice_elediamcp_token`. The token secret itself is never
stored (only a SHA-256 hash for correlation). A full privacy provider declares this
data and implements export and erasure, including detaching deleted users from tokens
they merely created or revoked. The protocol layer itself stores no personal data.

---

## ✅ Testing

```bash
# From the Moodle root, after php admin/tool/phpunit/cli/init.php:
vendor/bin/phpunit --testsuite webservice_elediamcp_testsuite
```

Test coverage includes:

- JSON-RPC 2.0 request parsing and validation (`request_test`)
- Protocol version negotiation (`protocol_test`)
- Security helper, Origin validation, rate limiting (`security_test`)
- Tool provider, annotation inference, pagination (`tool_provider_test`, `tool_provider_extras_test`)
- AI-native, content and learner tools (`ai_tools_test`, `content_tools_test`, `learner_tools_test`)
- Negative / failure cases across all tools (`negative_cases_test`)
- Server lifecycle and parameter coercion (`server_test`)
- Token lifecycle manager: creation, revocation, expiry, listing (`token_manager_test`)
- Internal token API: component attribution and ownership scoping (`api_test`)
- Privacy provider export/erase (`privacy_provider_test`)
- Client library (`client_test`)

Behat coverage (`--tags @webservice_elediamcp`) drives the self-service UI end to end:
the Preferences link, the create → reveal-once → revoke flow, and the "no usable
service" guidance.

---

## 🩺 Troubleshooting

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

---

## 🗺 Roadmap

See the project roadmap for the planned OAuth 2.1 Authorization Code + PKCE flow
(`/.well-known/oauth-authorization-server`), additional write tools, resources and
prompts, and semantic search.

---

## 🤝 Contributing

Pull requests, issues, and security reports are welcome. Please consult
[CONTRIBUTING.md](CONTRIBUTING.md) for branching conventions, commit message
standards, and review expectations, and [SECURITY.md](SECURITY.md) for the private
vulnerability-disclosure process. The release history lives in
[CHANGELOG.md](CHANGELOG.md).

## 📜 License

GPL v3 or later — see [LICENSE](LICENSE) for the full text.

---

<p align="center">
  <a href="https://eledia.de" title="eLeDia GmbH — eLearning im Dialog">
    <img src="https://eledia.de/wp-content/uploads/2025/01/cropped-eLeDia_Logo-136x51.png"
         alt="eLeDia GmbH"
         width="136" height="51">
  </a>
  <br><br>
  <strong>eLeDia GmbH</strong> · <em>eLearning im Dialog</em><br>
  Wilhelmsaue 37 · 10713 Berlin · Germany<br>
  <a href="tel:+4930505610700">+49 30 5056 10-70</a> ·
  <a href="mailto:info@eledia.de">info@eledia.de</a> ·
  <a href="https://eledia.de">eledia.de</a><br>
  <sub>Moodle Premium Partner · Moodle Global Partner of the Year 2025</sub>
</p>

<p align="center">
  <sub>© 2025–2026 eLeDia GmbH, Berlin · Released under the GNU GPL v3 or later · Built with ❤️ in Berlin</sub>
</p>
