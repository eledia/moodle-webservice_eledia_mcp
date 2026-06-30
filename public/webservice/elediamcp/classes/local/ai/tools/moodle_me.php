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

use moodle_url;
use stdClass;
use webservice_elediamcp\local\ai\ai_tool;

/**
 * AI-native identity probe.
 *
 * Returns a compact, denormalised snapshot of "who am I, on which site, in
 * what language" — typically the first call an AI agent makes after
 * initialization. This is intentionally small (a few hundred bytes) so it
 * can be called on every conversation turn without context bloat.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_me implements ai_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_me';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Who am I on this Moodle site?';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Returns identity information about the authenticated Moodle user: id, full name, '
            . 'email, preferred language, timezone, and site metadata. Call this first to confirm '
            . 'the MCP token is valid and to learn the user\'s display name and language for '
            . 'localised replies. Cheap and safe to call on every conversation turn.';
    }

    /**
     * Input schema.
     *
     * @return array<string,mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => new stdClass(),
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string,mixed>
     */
    public static function output_schema(): array {
        return [
            'type' => 'object',
            'required' => ['user', 'site'],
            'properties' => [
                'user' => [
                    'type' => 'object',
                    'required' => ['id', 'username', 'fullname'],
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'username' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'firstname' => ['type' => 'string'],
                        'lastname' => ['type' => 'string'],
                        'email' => ['type' => 'string'],
                        'lang' => ['type' => 'string'],
                        'timezone' => ['type' => 'string'],
                        'profile_url' => ['type' => 'string'],
                        'is_admin' => ['type' => 'boolean'],
                    ],
                ],
                'site' => [
                    'type' => 'object',
                    'required' => ['name', 'url'],
                    'properties' => [
                        'name' => ['type' => 'string'],
                        'url' => ['type' => 'string'],
                        'moodle_version' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Tool annotations.
     *
     * @return array<string,mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => false,
        ];
    }

    /**
     * Execute the tool.
     *
     * @param array $arguments Ignored.
     * @param stdClass $user Authenticated user record.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        global $CFG, $SITE;

        $userid = (int) $user->id;
        $fullname = fullname($user);
        $profileurl = (new moodle_url('/user/profile.php', ['id' => $userid]))->out(false);
        $stringopts = ['context' => \context_system::instance(), 'filter' => false, 'escape' => true];
        $email = (int) ($user->maildisplay ?? 2) >= 1 ? (string) ($user->email ?? '') : '';

        return [
            'user' => [
                'id' => $userid,
                'username' => (string) $user->username,
                'fullname' => $fullname,
                'firstname' => (string) ($user->firstname ?? ''),
                'lastname' => (string) ($user->lastname ?? ''),
                'email' => $email,
                'lang' => (string) ($user->lang ?: ($CFG->lang ?? 'en')),
                'timezone' => (string) \core_date::get_user_timezone($user),
                'profile_url' => $profileurl,
                'is_admin' => is_siteadmin($user),
            ],
            'site' => [
                'name' => format_string(
                    (string) ($SITE->fullname ?? $SITE->shortname ?? 'Moodle'),
                    true,
                    $stringopts
                ),
                'url' => (string) $CFG->wwwroot,
                'moodle_version' => (string) ($CFG->release ?? ''),
            ],
        ];
    }
}
