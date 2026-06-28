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
course creation, course updates and manual course enrolment; they use a preview
step and only change Moodle after a second call with `confirm=true`.
