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

namespace webservice_elediamcp\local\ai;

use stdClass;

/**
 * Contract every AI-native MCP tool must implement.
 *
 * AI tools are curated, task-oriented operations layered on top of the raw
 * Moodle Web Service surface. They are intended to be:
 *
 * - stable across Moodle minor releases,
 * - deterministic in input / output shape,
 * - permission-aware (always run in the authenticated user's context),
 * - LLM-friendly (flat, denormalised, with helpful descriptions).
 *
 * Implementations live in webservice_elediamcp\local\ai\tools\* and register
 * themselves in {@see registry::all()}.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface ai_tool {
    /**
     * Unique tool name. Must satisfy the MCP naming rules: A-Z, a-z, 0-9, _, ., -.
     *
     * @return string
     */
    public static function name(): string;

    /**
     * Optional human-readable display name (MCP "title" field).
     *
     * @return string
     */
    public static function title(): string;

    /**
     * Human-readable description shown to LLM clients.
     *
     * @return string
     */
    public static function description(): string;

    /**
     * JSON Schema for the input arguments.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array;

    /**
     * JSON Schema for the structured output. Must describe the actual shape
     * that {@see execute()} returns.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array;

    /**
     * MCP tool annotations (readOnlyHint, destructiveHint, idempotentHint,
     * openWorldHint, title overrides).
     *
     * @return array<string, mixed>
     */
    public static function annotations(): array;

    /**
     * Execute the tool in the security context of the supplied user.
     *
     * Implementations MUST:
     * - perform Moodle capability checks themselves where applicable,
     * - return structured data matching {@see output_schema()},
     * - throw \webservice_elediamcp\local\ai\tool_exception for business errors so
     *   the server can surface them as MCP tool execution errors with
     *   isError: true, instead of JSON-RPC protocol errors.
     *
     * @param array<string, mixed> $arguments Validated input arguments.
     * @param stdClass $user Authenticated Moodle user.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array;
}
