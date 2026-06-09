# Contributing to webservice_elediamcp

This is an internal eLeDia plugin. These notes keep contributions consistent and
keep the CI pipeline green.

## Ground rules

- Follow the [Moodle coding style](https://moodledev.io/general/development/policies/codingstyle).
- Keep the plugin **security-first**: every new exposed operation must resolve the
  acting user and enforce Moodle capabilities, enrolment and context checks before
  doing any work. Never trust a caller-supplied user id or context.
- Never log or return secrets (tokens), and never widen data exposure beyond what
  the acting user could already see in Moodle.
- Add or update tests with every behavioural change.
- Update `CHANGELOG.md` (Unreleased section) and bump `version.php` when shipping.

## Branch & commit workflow

1. Branch from `main` (e.g. `feature/<short-name>` or `fix/<short-name>`).
2. Make focused commits with clear messages.
3. Open a merge request; CI must pass (or failures must be justified).
4. At least one reviewer approval before merge.

## Local quality gates

Run these before pushing (they mirror CI):

```bash
# Moodle Code Checker (PHPCS, Moodle standard)
vendor/bin/phpcs --standard=moodle webservice/elediamcp

# Static analysis (if configured)
vendor/bin/phpstan analyse webservice/elediamcp

# Unit tests
vendor/bin/phpunit --testsuite webservice_elediamcp_testsuite

# Behat (self-service UI)
vendor/bin/behat --tags @webservice_elediamcp
```

## Adding an AI-native tool

1. Create a class under `classes/local/ai/tools/` implementing
   `\webservice_elediamcp\local\ai\ai_tool`.
2. Implement `name()`, `title()`, `description()`, `input_schema()`,
   `output_schema()`, `annotations()` and `execute(array $arguments, \stdClass $user)`.
3. In `execute()`, **enforce permissions yourself** — operate as `$user`, validate
   every argument with `clean_param`/`PARAM_*`, check capabilities/enrolment, and
   use `format_string()`/`format_text()` with the correct context on any output.
4. Set `annotations()` honestly: read-only tools must declare `readOnlyHint: true`;
   anything that changes state must declare it (the audit layer relies on this to
   emit `write_performed`).
5. Register the class in `classes/local/ai/registry.php`.
6. Add tests under `tests/` (happy path, invalid input, unauthorised user, and a
   permission-boundary case).

## Versioning

- `version.php` `$plugin->version` is the integer build stamp (`YYYYMMDDXX`).
- `$plugin->release` is the human-readable SemVer string and should match the
  advertised `serverInfo.version` (`server::SERVER_VERSION`).

## Reporting security issues

See [`SECURITY.md`](SECURITY.md) — do **not** use public issues for vulnerabilities.
