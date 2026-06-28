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

namespace webservice_elediamcp\local\ai\tools;

use context_system;
use core_text;
use core_user;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native user creation tool.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_create_user implements ai_tool {
    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_create_user';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Create a Moodle user';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Creates a Moodle user account. This is a write tool and requires the '
            . 'authenticated user to have moodle/user:create at system level. Two-step flow: '
            . 'the first call with confirm omitted or false returns a preview; the second call '
            . 'with confirm=true creates the account. Username and email must be unique.';
    }

    /**
     * Return the JSON schema for accepted arguments.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['username', 'firstname', 'lastname', 'email'],
            'properties' => [
                'username' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 100,
                    'description' => 'Unique Moodle username.',
                ],
                'firstname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'lastname' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 100,
                ],
                'email' => [
                    'type' => 'string',
                    'format' => 'email',
                    'description' => 'Unique email address.',
                ],
                'password' => [
                    'type' => 'string',
                    'minLength' => 8,
                    'description' => 'Optional initial password. If omitted, Moodle creates the account with auth manual '
                        . 'and no known password.',
                ],
                'auth' => [
                    'type' => 'string',
                    'default' => 'manual',
                    'description' => 'Authentication plugin. Default: manual.',
                ],
                'idnumber' => [
                    'type' => 'string',
                    'maxLength' => 255,
                ],
                'city' => ['type' => 'string'],
                'country' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'maxLength' => 2,
                    'description' => 'Optional two-letter country code.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually create the user.',
                ],
            ],
        ];
    }

    /**
     * Return the JSON schema for tool output.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['created', 'requires_confirmation', 'summary'],
            'properties' => [
                'created' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'user' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'username' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'profile_url' => ['type' => 'string'],
                    ],
                ],
                'preview' => [
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string'],
                        'firstname' => ['type' => 'string'],
                        'lastname' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'auth' => ['type' => 'string'],
                    ],
                ],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Return MCP annotations for this tool.
     *
     * @return array<string, mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => false,
            'destructiveHint' => false,
            'idempotentHint' => false,
            'openWorldHint' => true,
        ];
    }

    /**
     * Execute the tool for the authenticated user.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $user Authenticated Moodle user.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG, $DB;

        require_capability('moodle/user:create', context_system::instance(), $user->id);

        $record = (object) [
            'username' => core_user::clean_field(core_text::strtolower(trim((string) ($arguments['username'] ?? ''))), 'username'),
            'firstname' => trim((string) ($arguments['firstname'] ?? '')),
            'lastname' => trim((string) ($arguments['lastname'] ?? '')),
            'email' => core_user::clean_field(trim((string) ($arguments['email'] ?? '')), 'email'),
            'auth' => trim((string) ($arguments['auth'] ?? 'manual')) ?: 'manual',
            'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id,
        ];

        foreach (['username', 'firstname', 'lastname', 'email'] as $field) {
            if ((string) $record->{$field} === '') {
                throw new tool_exception($field . ' is required.');
            }
        }
        if (!validate_email((string) $record->email)) {
            throw new tool_exception('email must be a valid email address.', ['email' => $record->email]);
        }
        // Only allow auth methods the admin has actually enabled (manual/nologin are
        // always enabled). Checking mere plugin existence would let a caller bypass
        // the site's account-creation policy.
        $enabledauths = \core\plugininfo\auth::get_enabled_plugins();
        if (!isset($enabledauths[(string) $record->auth])) {
            throw new tool_exception('auth plugin is not enabled.', ['auth' => $record->auth]);
        }
        if ($DB->record_exists('user', ['username' => $record->username, 'mnethostid' => $record->mnethostid])) {
            throw new tool_exception('username is already in use.', ['username' => $record->username]);
        }
        if ($DB->record_exists('user', ['email' => $record->email, 'mnethostid' => $record->mnethostid, 'deleted' => 0])) {
            throw new tool_exception('email is already in use.', ['email' => $record->email]);
        }

        foreach (['idnumber', 'city', 'country'] as $field) {
            if (array_key_exists($field, $arguments) && trim((string) $arguments[$field]) !== '') {
                $record->{$field} = trim((string) $arguments[$field]);
            }
        }
        if (!empty($arguments['password'])) {
            $record->password = (string) $arguments['password'];
        }

        $preview = [
            'username' => (string) $record->username,
            'firstname' => (string) $record->firstname,
            'lastname' => (string) $record->lastname,
            'email' => (string) $record->email,
            'auth' => (string) $record->auth,
        ];

        if (empty($arguments['confirm'])) {
            return [
                'created' => false,
                'requires_confirmation' => true,
                'user' => null,
                'preview' => $preview,
                'summary' => 'Ready to create Moodle user ' . $record->username . '. Call again with confirm=true to create.',
            ];
        }

        require_once($CFG->dirroot . '/user/lib.php');
        try {
            $userid = user_create_user($record, true, true);
            unset($record->password);
            $created = core_user::get_user($userid, '*', MUST_EXIST);
        } catch (Throwable $ex) {
            unset($record->password);
            throw new tool_exception('User creation failed: ' . $ex->getMessage(), $preview);
        }

        return [
            'created' => true,
            'requires_confirmation' => false,
            'user' => [
                'id' => (int) $created->id,
                'username' => (string) $created->username,
                'fullname' => fullname($created),
                'email' => (string) $created->email,
                'profile_url' => (new moodle_url('/user/profile.php', ['id' => (int) $created->id]))->out(false),
            ],
            'preview' => $preview,
            'summary' => 'Created Moodle user ' . fullname($created) . ' (' . $created->username . ').',
        ];
    }
}
