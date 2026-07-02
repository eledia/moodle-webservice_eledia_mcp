# Model Context Protocol help

The eLeDia MCP plugin lets approved MCP clients and AI agents connect to Moodle
through a curated tool catalogue. Every request runs as the authenticated Moodle
user and follows Moodle's normal capability, enrolment and visibility checks.

## What administrators configure

Administrators use the MCP configuration page to choose which external services
may issue MCP tokens, define token and CORS policy, set request limits and decide
whether premium MCP tools are available through the optional premium add-on.

## Tokens

MCP clients authenticate with Moodle tokens. Treat every token like a password:
create one token per client, revoke tokens that are no longer needed and prefer
the `Authorization: Bearer` header over token values in URLs.

## Claude Desktop

The MCP page shows a ready-to-use Claude Desktop configuration snippet after a
token is created. Copy the token immediately; Moodle only shows the token value
once.

## Free and premium tools

Without the premium add-on, MCP exposes the free baseline tools for identity,
course discovery, course content, resources, announcements, calendar, assignments,
grades, progress, quiz information, course search, content search and user
creation where the Moodle user has the required capability.

With the premium add-on feature `mcp_tools`, the full MCP catalogue is available,
including additional communication, forum, submission, course creation and raw
Moodle web service tools when enabled by policy. Premium write tools include
course creation, course updates, manual course enrolment and activity creation
(page, label, url, book, assignment); they use a preview step and only change
Moodle after a second call with `confirm=true`.

## AI generation tools (eledia.ai suite)

On sites where the eledia.ai suite is installed, two additional premium tools
appear automatically: `moodle_generate_h5p` (requires `local_h5pauthor`)
generates interactive H5P content and publishes it to the content bank or as a
course activity, and `moodle_generate_questions` (requires
`local_lernhive_questiongen`) generates quiz questions into a question bank of
the course. Both run the generation server-side through the Moodle site's
configured AI provider (Site administration > AI) and use the same preview and
`confirm=true` flow as the other write tools.
