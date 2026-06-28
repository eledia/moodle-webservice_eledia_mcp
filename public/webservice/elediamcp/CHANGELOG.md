# Changelog

All notable changes to the **webservice_elediamcp** plugin are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-06-28

### Added
- Plugin-owned MCP help page that renders the plugin documentation inside the
  MCP plugin shell without a runtime dependency on LernHive.
- Optional Premium gating for the extended MCP tool surface through the
  `mcp_tools` feature of `local_elediaai_tutor_premium`.
- Premium `moodle_enrol_user` tool for manual course enrolments with preview
  and confirmation flow.

### Changed
- MCP configuration, token management and Claude connection guidance now live on
  one MCP page in the shared plugin shell.
- Token creation uses a post/redirect/get flow so refreshes do not accidentally
  create another token.
- DevFlow documentation was consolidated into the root `Docs/` structure.

### Fixed
- The MCP shell help action now opens the plugin-owned MCP help page instead of
  the eLeDia.ai Tutor/LernHive help route.

## [1.0.1] - 2026-06-27

### Added
- `moodle_search_courses` gained a `scope` parameter (`catalogue` default |
  `enrolled`) and now treats `query` as optional: omitting it browses/lists all
  courses in the scope (catalogue browsing answers "how many courses are there"),
  while a keyword still searches. The `enrolled` scope is consolidated onto
  `moodle_my_courses` (single implementation for the user's own courses).
- `moodle_find_user` now finds users for callers with site-wide messaging rights
  (site admins / `moodle/site:sendmessage`) even without a shared course, so
  discovery matches what `moodle_send_message` can actually reach. Multi-word
  name fragments ("Paul Maier") are matched across first/last name. Non-messageable
  users stay filtered out, and non-privileged callers are unchanged.

### Hardened
- Rate limiting is now atomic: the per-bucket read-increment-write is serialised
  through the MUC lock, so limits hold even on cache stores that are not natively
  atomic (Redis still recommended for performance).
- `moodle_get_announcements` now only reads announcement forums whose course-module
  is visible to the user (hidden modules / availability restrictions are honoured)
  and scopes separate-groups announcements to the user's groups.

### Security
- Email visibility now actually works: `moodle_me` and `moodle_verify_user_context`
  read the correct user field `maildisplay` (previously the non-existent
  `emaildisplay`, so the guard never triggered and the address was returned
  regardless of the user's "hide my email" preference). Verified by PHPUnit.
- The MCP endpoint now restricts access to tokens that belong to a configured
  MCP external service (new admin setting **Restrict endpoint to MCP services**,
  enabled by default). Previously any valid Moodle web-service token could reach
  the AI tools; the MCP service list now governs endpoint access, not just token
  issuance. Can be disabled for transitional setups.
- `moodle_create_user` now accepts only **enabled** auth methods instead of any
  installed auth plugin, so callers cannot bypass the site's account policy.
- `moodle_search_content` now HTML-escapes result snippets, consistent with the
  other tool fields.
- `moodle_course_contents` no longer reveals hidden section names/summaries to
  users without `moodle/course:viewhiddenactivities`.
- `moodle_search_courses` and `moodle_calendar_upcoming` no longer surface
  internal exception messages to the client (logged via `debugging()` instead).

### Fixed
- `moodle_me` returned no email because of a misplaced assignment; the email is
  now resolved correctly (still gated by the user's `emaildisplay`).
- `moodle_forum_discussions` reported `discussion_count` from the current page
  only; it now reflects the full, visibility-filtered discussion count.
- Text truncation across several tools is now multibyte-safe (`core_text`),
  avoiding broken UTF-8 in excerpts/previews.
- Removed dead `request::from_raw_input()` / `request::is_raw_input_empty()`
  helpers that re-read `php://input`.

## [1.0.0] - 2026-06-12

### Added
- `moodle_search_content` — full-text search across the content the user can
  access via core global search (all engine access checks apply); graceful
  fallback to visible activity names/descriptions when global search is
  disabled, flagged via the `engine` response field.
- `moodle_my_submission_files` — the authenticated user's **own** latest
  submission for one assignment: attached files (name, size, mime type,
  download URL) and the plain text of online-text submissions. Strictly
  self-scoped by construction.
- Negative-case test sweep across the original twelve AI tools
  (visibility/enrolment/privacy boundaries), complementing the 0.9.0 tests.

### Security
- **`moodle_get_resource` no longer returns module content from courses the
  caller cannot access.** It previously gated only on `cm_info::uservisible`,
  which checks module visibility/availability and `mod/<x>:view` but **not**
  course enrolment or course visibility — and `mod/page:view` (etc.) is granted
  to the `user` archetype, so any authenticated user could read a page/book/URL
  in any course, including hidden ones. A `can_access_course()` gate is now
  applied first. The same gate was added defensively to
  `moodle_my_submission_files` and `moodle_forum_discussions` (both resolve a
  module by `cmid`). Found by the 1.0 negative-case test sweep.

### Changed
- Plugin maturity raised to **STABLE**.

## [0.9.0] - 2026-06-12

### Added
- **Three new AI tools** closing the learner-progress gaps for tutor agents:
  - `moodle_my_progress` — completion progress per enrolled course
    (percentage, completed/total tracked activities, course-completed flag),
    optionally per-activity completion states for a single course.
  - `moodle_quiz_info` — quizzes across enrolled courses with open/close
    windows, time limits, allowed attempts and grading method, plus the
    authenticated user's **own** attempt history and best grade for a single
    quiz (`cmid`). Never exposes other users' attempts.
  - `moodle_forum_discussions` — read course forums: visible forums and
    discussions per course or forum, and the posts of one discussion as plain
    text. All mod_forum visibility rules enforced through the forum API
    (group modes, Q&A first-post gating, timed posts, private replies).
    Read-only by design.

## [Unreleased]

### Fixed
- Fatal parse error in `classes/local/server.php` (`$this->wsname` assignment) that
  prevented the server class from loading at all.
- Upgrade savepoint in `db/upgrade.php` used the wrong plugin name (`mcp` instead
  of `elediamcp`), which would have broken the upgrade path on existing sites.
- Capability language strings (`elediamcp:use`, `elediamcp:viewcaps`,
  `elediamcp:managetokens`) were keyed under the pre-rename `mcp:*` names and did
  not resolve in the role-definition UI.
- Stale unit-test assertion pinning the advertised server version to `0.5.0`.

### Changed
- Advertised MCP `serverInfo.version` aligned with the plugin release (`0.8.0`).
- Relocated the convenience HTTP client from `lib.php` to the autoloaded class
  `\webservice_elediamcp\client`, so `lib.php` now contains only Moodle callbacks.
- `lib.php` now declares the `MOODLE_INTERNAL` guard.

### Added
- Self-service token page now renders a ready-to-paste **Claude Desktop**
  (`mcp-remote`) configuration snippet, with the freshly created token pre-filled
  once at creation time and a generic placeholder example for existing tokens.
- GitLab CI/CD pipeline (`.gitlab-ci.yml`): PHPCS (Moodle standard), PHPStan,
  Semgrep, Trivy, PHPUnit (with plugin-only coverage), Behat, PHPDepend metrics
  and a consolidated summary report.
- Repository governance files: `CHANGELOG.md`, `LICENSE`, `SECURITY.md`,
  `CONTRIBUTING.md`, `.gitignore`.

## [0.8.0]

### Added
- Self-service token management UI (create / view metadata / revoke) and an
  internal PHP API (`\webservice_elediamcp\api`) for first-party plugins to
  provision and revoke user-scoped tokens, with component attribution.
- Companion `webservice_elediamcp_token` metadata table that survives revocation
  for a durable audit trail; full Privacy API provider.
- Curated AI-native tool catalogue (`moodle_me`, `moodle_verify_user_context`,
  `moodle_find_user`, `moodle_my_courses`, `moodle_search_courses`,
  `moodle_course_contents`, `moodle_get_resource`, `moodle_get_announcements`,
  `moodle_calendar_upcoming`, `moodle_my_assignments`, `moodle_my_grades`,
  `moodle_send_message`).
- Multi-version MCP protocol negotiation (`2025-11-25`, `2025-06-18`,
  `2025-03-26`), tool annotations, structured output, `tools/list` pagination.
- Security controls: Origin/CORS allow-list, per-token/per-IP rate limiting,
  request-size limits, emergency disable, optional (off-by-default) query-string
  token, audit events.
- OAuth Protected Resource discovery (`/.well-known/oauth-protected-resource`)
  and `WWW-Authenticate` hints on 401.

[Unreleased]: https://eledia.de
[0.8.0]: https://eledia.de
