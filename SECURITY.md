# Security Policy

`webservice_elediamcp` exposes Moodle functionality over an authenticated
HTTP/MCP interface and is treated as **security-sensitive**. Please handle any
weakness responsibly.

## Reporting a vulnerability

This is a private, internally maintained plugin. **Do not** open a public issue,
merge request, or chat message describing a suspected vulnerability.

Instead, report it privately to the maintainers:

- **Email:** security@eledia.de (subject: `webservice_elediamcp security`)
- **Maintainer:** Christopher Reimann <christopher.reimann@eledia.de>

Please include:

1. Affected plugin version (`$plugin->release` from `version.php`) and Moodle version.
2. A description of the issue and its impact (data exposure, privilege escalation,
   authentication bypass, etc.).
3. Step-by-step reproduction, ideally with a minimal request/response transcript
   (redact tokens and personal data).
4. Any suggested remediation.

You can expect an acknowledgement within **2 business days** and a triage
assessment within **5 business days**.

## Scope

In scope:

- The MCP endpoint (`server.php`) and its authentication, authorisation,
  rate-limiting, CORS/Origin and input-validation logic.
- The token lifecycle (`classes/local/token_manager.php`, `classes/api.php`,
  `token/index.php`) and stored token metadata.
- The AI-native tools under `classes/local/ai/tools/` and the raw-function
  exposure path.
- Information disclosure in error responses and logs.

Out of scope:

- Vulnerabilities in Moodle core or third-party MCP clients (report those to the
  respective projects).
- Misconfiguration of the hosting site (e.g. running without HTTPS, exposing
  `?wstoken=` after explicitly enabling it).

## Security model (summary)

- Authentication is delegated to Moodle's core `external_tokens`; tokens are
  bearer credentials scoped to a user **and** a configured MCP external service.
- Every exposed operation resolves the acting user and enforces Moodle
  capabilities, enrolment and context checks **before** acting. Tools cannot be
  used to bypass the role/capability model.
- Defaults are conservative: query-string tokens off, rate limiting on,
  emergency kill switch available, Origin allow-list enforced.
- Secrets are never stored in plaintext (only a SHA-256 correlation hash) and are
  never written to logs or audit events.

See [`README.md`](README.md#security-model) for the full description.
