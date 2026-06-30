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
use webservice_elediamcp\local\ai\registry;

/**
 * Tool provider for the MCP protocol.
 *
 * Aggregates two tool sources into the {@see server}'s tool catalogue:
 *
 * 1. AI-native tools registered through {@see registry}. These provide a
 *    stable, curated, LLM-friendly surface.
 * 2. Raw Moodle external functions assigned to the authenticated service,
 *    exposed when the site admin enables expose_raw_functions. Useful for
 *    administrators and power users; not recommended for production AI
 *    agents because of the large schema surface.
 *
 * Output shape is negotiated per protocol version: legacy clients receive
 * the {result: ...} envelope around structuredContent, newer clients receive
 * the canonical shape directly.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_provider {
    /**
     * AI-native tools available without the premium add-on.
     *
     * @var string[]
     */
    private const FREE_AI_TOOLS = [
        'moodle_search_courses',
        'moodle_search_content',
        'moodle_create_user',
        'moodle_me',
        'moodle_verify_user_context',
        'moodle_my_courses',
        'moodle_course_contents',
        'moodle_get_resource',
        'moodle_get_announcements',
        'moodle_calendar_upcoming',
        'moodle_due_work',
        'moodle_my_assignments',
        'moodle_my_grades',
        'moodle_my_progress',
        'moodle_quiz_info',
    ];

    /**
     * Retrieve the full set of tools available to the supplied token.
     *
     * @param string $token External service token.
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return array<int, array<string,mixed>> Tool definitions ready for tools/list.
     */
    public static function get_tools(string $token, string $protocolversion = protocol::LATEST): array {
        $tools = self::get_ai_tools($protocolversion);

        if (premium::has_mcp_tools() && security::expose_raw_functions()) {
            $tools = array_merge($tools, self::get_raw_function_tools($token, $protocolversion));
        }

        return $tools;
    }

    /**
     * Retrieve the raw Moodle external functions exposed to the token.
     *
     * @param string $token External service token.
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return array<int, array<string,mixed>>
     */
    public static function get_raw_function_tools(string $token, string $protocolversion = protocol::LATEST): array {
        global $DB;

        $tokenrecord = $DB->get_record('external_tokens', ['token' => $token], '*', IGNORE_MISSING);
        if (!$tokenrecord) {
            return [];
        }

        $functions = $DB->get_records(
            'external_services_functions',
            ['externalserviceid' => $tokenrecord->externalserviceid],
            'functionname'
        );

        $ainames = registry::names();
        $tools = [];
        foreach ($functions as $function) {
            $info = external_api::external_function_info($function->functionname);
            if (empty($info) || !empty($info->deprecated)) {
                continue;
            }

            // Avoid name collisions with AI-native tools.
            if (in_array($info->name, $ainames, true)) {
                continue;
            }

            $tools[] = self::format_raw_tool($info, $protocolversion);
        }

        return $tools;
    }

    /**
     * Retrieve the AI-native tool definitions.
     *
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return array<int, array<string,mixed>>
     */
    public static function get_ai_tools(string $protocolversion = protocol::LATEST): array {
        $tools = [];
        foreach (registry::all() as $class) {
            if (!self::is_ai_tool_available($class::name())) {
                continue;
            }

            $tool = [
                'name' => $class::name(),
                'description' => $class::description(),
                'inputSchema' => $class::input_schema(),
                'outputSchema' => self::wrap_output_schema($class::output_schema(), $protocolversion),
            ];

            if (protocol::supports_tool_title($protocolversion)) {
                $tool['title'] = $class::title();
            }
            if (protocol::supports_annotations($protocolversion)) {
                $tool['annotations'] = $class::annotations();
            }
            $tools[] = $tool;
        }
        return $tools;
    }

    /**
     * Return the AI-native tool names available in the free edition.
     *
     * @return string[]
     */
    public static function free_ai_tool_names(): array {
        return self::FREE_AI_TOOLS;
    }

    /**
     * Return the AI-native tool names that require the premium add-on.
     *
     * @return string[]
     */
    public static function premium_ai_tool_names(): array {
        return array_values(array_diff(registry::names(), self::FREE_AI_TOOLS));
    }

    /**
     * Apply pagination to a list of tools.
     *
     * @param array $tools Full set of tools.
     * @param string|null $cursor Opaque cursor supplied by the client.
     * @param int|null $pagesize Optional override for the page size.
     * @return array{tools:array<int,array<string,mixed>>,nextCursor:string|null}
     */
    public static function paginate(array $tools, ?string $cursor, ?int $pagesize = null): array {
        $size = $pagesize ?? security::tools_page_size();
        $size = max(1, $size);

        $offset = 0;
        if (!empty($cursor)) {
            $decoded = json_decode((string) base64_decode($cursor, true), true);
            if (is_array($decoded) && isset($decoded['offset']) && is_int($decoded['offset'])) {
                $offset = max(0, $decoded['offset']);
            }
        }

        $slice = array_slice($tools, $offset, $size);
        $next = null;
        if ($offset + $size < count($tools)) {
            $next = base64_encode(json_encode(['offset' => $offset + $size]));
        }
        return ['tools' => $slice, 'nextCursor' => $next];
    }

    /**
     * Look up the AI tool class for a given tool name, if any.
     *
     * @param string $name Tool name.
     * @return class-string|null
     */
    public static function find_ai_tool(string $name): ?string {
        if (!self::is_ai_tool_available($name)) {
            return null;
        }
        return registry::find($name);
    }

    /**
     * Whether the named AI-native tool is available for the current edition.
     *
     * @param string $name Tool name.
     * @return bool
     */
    private static function is_ai_tool_available(string $name): bool {
        return premium::has_mcp_tools() || in_array($name, self::FREE_AI_TOOLS, true);
    }

    /**
     * Format a raw Moodle external function as an MCP tool.
     *
     * @param object $info external_function_info() result.
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return array<string,mixed>
     */
    protected static function format_raw_tool(object $info, string $protocolversion): array {
        $inputschema = self::build_schema($info->parameters_desc);
        $outputschema = self::wrap_output_schema(self::build_schema($info->returns_desc), $protocolversion);

        $tool = [
            'name' => $info->name,
            'description' => $info->description ?? '',
            'inputSchema' => $inputschema,
            'outputSchema' => $outputschema,
        ];

        if (protocol::supports_tool_title($protocolversion)) {
            $tool['title'] = $info->description ?? $info->name;
        }

        if (protocol::supports_annotations($protocolversion)) {
            $tool['annotations'] = self::infer_annotations($info);
        }

        return $tool;
    }

    /**
     * Wrap an output schema in the legacy {result: ...} envelope when the
     * negotiated protocol version requires it.
     *
     * @param array $schema Canonical output schema.
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return array<string,mixed>
     */
    protected static function wrap_output_schema(array $schema, string $protocolversion): array {
        if (!protocol::uses_result_envelope($protocolversion)) {
            return $schema;
        }
        return [
            'type' => 'object',
            'properties' => [
                'result' => $schema,
            ],
        ];
    }

    /**
     * Infer MCP tool annotations from a Moodle external function name.
     *
     * The Moodle naming convention contains strong hints: *_get_*, *_view_*,
     * *_is_*, *_search_*, *_list_* are read-only; *_delete_*, *_remove_* are
     * destructive; *_create_*, *_add_*, *_save_*, *_update_*, *_send_*,
     * *_submit_* perform writes that are typically not idempotent.
     *
     * @param object $info external_function_info() result.
     * @return array<string,mixed>
     */
    public static function infer_annotations(object $info): array {
        $name = strtolower($info->name);
        $readonly = (bool) preg_match('/(^|_)(get|view|is|has|search|list|fetch|read|find|count|check)(_|$)/', $name);
        $destructive = (bool) preg_match('/(^|_)(delete|remove|destroy|drop|purge|unenrol|unassign)(_|$)/', $name);
        $write = $destructive || (bool) preg_match(
            '/(^|_)(create|add|save|update|set|send|submit|enrol|assign|post|reply|grade|edit|move|copy|publish)(_|$)/',
            $name
        );

        // A read verb on the action wins over an ambiguous write verb that is
        // really a subsystem name (e.g. core_enrol_get_users_courses, where
        // "enrol" is the component, not the action). Such a function is treated
        // as read-only unless it also carries a clearly destructive verb.
        $readonlyhint = $readonly && !$destructive;

        return [
            'title' => $info->description ?? $info->name,
            'readOnlyHint' => $readonlyhint,
            'destructiveHint' => $destructive,
            // Read-only operations are idempotent; genuine writes are not assumed to be.
            'idempotentHint' => $readonlyhint,
            'openWorldHint' => false,
        ];
    }

    /**
     * Build a JSON Schema from an external description object.
     *
     * @param external_description|null $desc External description.
     * @return array<string,mixed>
     */
    public static function build_schema(?external_description $desc): array {
        if ($desc === null) {
            return ['type' => 'object', 'properties' => new \stdClass()];
        }
        return self::generate_schema($desc);
    }

    /**
     * Generate a JSON Schema fragment from an external description.
     *
     * @param external_description $param External description.
     * @return array<string,mixed>
     */
    protected static function generate_schema(external_description $param): array {
        $type = self::get_schema_type($param);
        $schema = ['type' => $type];

        if ($param instanceof external_value) {
            if (!empty($param->desc)) {
                $schema['description'] = $param->desc;
            }
            if ($param->required === VALUE_REQUIRED) {
                $schema['_required'] = true;
            }
            return $schema;
        }

        if ($param instanceof external_single_structure) {
            $properties = [];
            $required = [];
            foreach ($param->keys as $key => $subparam) {
                $subschema = self::generate_schema($subparam);
                if (!empty($subschema['_required'])) {
                    $required[] = $key;
                }
                unset($subschema['_required']);
                $properties[$key] = $subschema;
            }
            $schema['properties'] = $properties;
            if (!empty($required)) {
                $schema['required'] = $required;
            }
            if (isset($param->required) && $param->required !== VALUE_REQUIRED) {
                $schema['default'] = new \stdClass();
            }
            return $schema;
        }

        if ($param instanceof external_multiple_structure) {
            $itemschema = self::generate_schema($param->content);
            unset($itemschema['_required']);
            $schema['items'] = $itemschema;
            if (isset($param->required) && $param->required !== VALUE_REQUIRED) {
                $schema['default'] = [];
            }
            return $schema;
        }

        return $schema;
    }

    /**
     * Map a Moodle external_value type to a JSON Schema type.
     *
     * @param external_description $param Description.
     * @return string
     */
    protected static function get_schema_type(external_description $param): string {
        if ($param instanceof external_value) {
            switch ($param->type) {
                case PARAM_INT:
                case PARAM_FLOAT:
                    return 'number';
                case PARAM_BOOL:
                    return 'boolean';
                default:
                    return 'string';
            }
        }
        if ($param instanceof external_single_structure) {
            return 'object';
        }
        if ($param instanceof external_multiple_structure) {
            return 'array';
        }
        return 'object';
    }
}
