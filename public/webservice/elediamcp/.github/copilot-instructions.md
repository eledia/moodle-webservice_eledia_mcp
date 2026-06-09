# Copilot instructions for `webservice_elediamcp`

## Big picture
- This is a **Moodle web service protocol plugin** (`webservice_elediamcp`), not a standalone PHP app.
- The HTTP endpoint is `server.php`, which only bootstraps Moodle and then delegates to `\webservice_elediamcp\local\server`.
- Core flow is split in `classes/local/server.php`:
  - `initialize` / `tools/list` / GET info / OPTIONS are handled as MCP protocol methods.
  - `tools/call` is translated into Moodle external function execution by mapping `params.name` -> `$functionname` and `params.arguments` -> `$parameters`, then using `parent::run()`.

## Architecture and data flow
- Request parsing/validation lives in `classes/local/request.php` (strict JSON-RPC 2.0 checks).
- Tool discovery/schema generation lives in `classes/local/tool_provider.php`:
  - Reads service scope from `external_tokens` and `external_services_functions`.
  - Pulls function metadata via `core_external\external_api::external_function_info()`.
  - Skips deprecated functions.
- Authentication token extraction supports:
  - `Authorization: Bearer <token>` header first
  - `wstoken` request param fallback
- `tools/call` responses are MCP-shaped (`content` + `structuredContent`) and wrap Moodle return values under `result`.

## Project-specific implementation patterns
- Keep `declare(strict_types=1);` on namespaced class files under `classes/`.
- Preserve Moodle plugin file headers and `defined('MOODLE_INTERNAL') || die();` guards where used.
- Error messages should use plugin language keys from `lang/en/webservice_elediamcp.php` (e.g. `err_missing_tool_name`).
- JSON schema mapping in this repo is intentionally simple:
  - `PARAM_INT`/`PARAM_FLOAT` -> `number`
  - `PARAM_BOOL` -> `boolean`
  - most other `external_value` types -> `string`
- Required fields are collected using an internal `_required` marker during recursive schema generation; do not expose `_required` in final output schemas.

## Critical workflows
- Tests are Moodle PHPUnit tests in `tests/` and rely on Moodle test base classes (`advanced_testcase`, `externallib_advanced_testcase`).
- Run plugin tests from Moodle root using the documented suite command:
  - `vendor/bin/phpunit --testsuite webservice_elediamcp_testsuite`
- If you change protocol constants/response shapes, update matching assertions in:
  - `tests/server_test.php`
  - `tests/request_test.php`
  - `tests/tool_provider_test.php`
  - `tests/client_test.php`

## Integration touchpoints
- Capability: `db/access.php` defines `webservice/elediamcp:use`; keep `lang/en/webservice_elediamcp.php` in sync.
- Privacy API is a null provider in `classes/privacy/provider.php` (plugin stores no personal data).
- `lib.php` contains a lightweight client used by tests/integrations; keep request format aligned with server JSON-RPC handling.
- Keep plugin metadata coherent in `version.php` (`component`, `requires`, `version`, `release`).
