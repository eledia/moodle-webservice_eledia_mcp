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

use core_message\api as message_api;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native messageable-user finder.
 *
 * Returns the set of Moodle users that the authenticated user is allowed to
 * message and whose full name matches the supplied query fragment. Wraps
 * {@see \core_message\api::message_search_users()} which already enforces:
 *
 * - Moodle messaging-privacy rules ($CFG->messagingallusers + per-user prefs),
 * - course-enrolment scoping when site-wide messaging is disabled,
 * - exclusion of deleted, unconfirmed and guest accounts.
 *
 * Intended primary use: disambiguate a free-form recipient hint (e.g. "erika")
 * into a concrete user id before calling {@see moodle_send_message}. Safe to
 * call eagerly; no side effects.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_find_user implements ai_tool {
    /** @var int Minimum query length accepted. */
    private const MIN_QUERY_LENGTH = 2;

    /** @var int Default limit per bucket. */
    private const DEFAULT_LIMIT = 10;

    /** @var int Hard cap per bucket. */
    private const MAX_LIMIT = 25;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_find_user';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Find a Moodle user you can message';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Searches for Moodle users whose full name matches the query and whom the '
            . 'authenticated user is allowed to message. Use this BEFORE moodle_send_message '
            . 'whenever the user is identified only by first name, partial name or nickname. '
            . 'Results are split into two buckets: existing contacts and non-contacts that '
            . 'are still messageable under the site\'s messaging-privacy rules. Each match '
            . 'includes the user id you must pass to moodle_send_message as to_user_id. '
            . 'Read-only and safe to call eagerly.';
    }

    /**
     * Input schema.
     *
     * @return array<string, mixed>
     */
    public static function input_schema(): array {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['query'],
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'minLength' => self::MIN_QUERY_LENGTH,
                    'maxLength' => 100,
                    'description' => 'Name fragment to search for. Matched against the user\'s '
                        . 'full name (first + last). Case-insensitive. Minimum 2 characters.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => self::MAX_LIMIT,
                    'default' => self::DEFAULT_LIMIT,
                    'description' => 'Maximum number of matches to return per bucket (contacts '
                        . 'and non-contacts). Defaults to ' . self::DEFAULT_LIMIT . ', hard cap '
                        . self::MAX_LIMIT . '.',
                ],
            ],
        ];
    }

    /**
     * Output schema.
     *
     * @return array<string, mixed>
     */
    public static function output_schema(): array {
        $userentry = [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer'],
                'fullname' => ['type' => 'string'],
                'profile_url' => ['type' => 'string'],
                'profile_image_url' => ['type' => 'string'],
                'is_contact' => ['type' => 'boolean'],
                'is_online' => ['type' => ['boolean', 'null']],
                'is_blocked' => ['type' => 'boolean'],
            ],
            'required' => ['id', 'fullname'],
        ];

        return [
            'type' => 'object',
            'required' => ['query', 'total_matches', 'contacts', 'noncontacts', 'summary'],
            'properties' => [
                'query' => ['type' => 'string'],
                'total_matches' => ['type' => 'integer'],
                'contacts' => [
                    'type' => 'array',
                    'items' => $userentry,
                ],
                'noncontacts' => [
                    'type' => 'array',
                    'items' => $userentry,
                ],
                'summary' => ['type' => 'string'],
                'hint' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Tool annotations.
     *
     * @return array<string, mixed>
     */
    public static function annotations(): array {
        return [
            'title' => self::title(),
            'readOnlyHint' => true,
            'destructiveHint' => false,
            'idempotentHint' => true,
            'openWorldHint' => true,
        ];
    }

    /**
     * Execute the tool.
     *
     * @param array<string, mixed> $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string, mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        $userid = (int) $user->id;
        if ($userid <= 0 || isguestuser($user)) {
            throw new tool_exception('Guest users cannot search for messageable users.');
        }

        $query = isset($arguments['query']) ? trim((string) $arguments['query']) : '';
        if (function_exists('mb_strlen')) {
            $qlen = mb_strlen($query);
        } else {
            $qlen = strlen($query);
        }
        if ($qlen < self::MIN_QUERY_LENGTH) {
            throw new tool_exception(
                sprintf('query must be at least %d characters long.', self::MIN_QUERY_LENGTH),
                ['query' => $query]
            );
        }

        $limit = isset($arguments['limit']) ? (int) $arguments['limit'] : self::DEFAULT_LIMIT;
        if ($limit < 1) {
            $limit = self::DEFAULT_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            $limit = self::MAX_LIMIT;
        }

        try {
            [$contacts, $noncontacts] = message_api::message_search_users($userid, $query, 0, $limit);
        } catch (Throwable $ex) {
            throw new tool_exception(
                'User search failed: ' . $ex->getMessage(),
                ['query' => $query]
            );
        }

        $contactsout = array_map([self::class, 'normalise_match'], (array) $contacts);
        $noncontactsout = array_map([self::class, 'normalise_match'], (array) $noncontacts);

        $total = count($contactsout) + count($noncontactsout);

        $payload = [
            'query' => $query,
            'total_matches' => $total,
            'contacts' => $contactsout,
            'noncontacts' => $noncontactsout,
            'summary' => self::build_summary($query, $contactsout, $noncontactsout),
        ];

        if ($total === 0) {
            $payload['hint'] = 'No messageable users matched. Try a different spelling, a '
                . 'longer fragment, or the surname. Note that site policy may restrict messaging '
                . 'to users sharing at least one course.';
        } else if ($total === 1) {
            $only = $contactsout[0] ?? $noncontactsout[0];
            $payload['hint'] = sprintf(
                'Single match: pass to_user_id=%d to moodle_send_message.',
                (int) $only['id']
            );
        } else {
            $payload['hint'] = 'Multiple matches: ask the user which one is intended, then pass '
                . 'the chosen id as to_user_id to moodle_send_message.';
        }

        return $payload;
    }

    /**
     * Normalise a member record returned by helper::get_member_info.
     *
     * @param object|array $member
     * @return array<string, mixed>
     */
    private static function normalise_match($member): array {
        if (is_array($member)) {
            $member = (object) $member;
        }

        return [
            'id' => isset($member->id) ? (int) $member->id : 0,
            'fullname' => isset($member->fullname) ? (string) $member->fullname : '',
            'profile_url' => isset($member->profileurl) ? (string) $member->profileurl : '',
            'profile_image_url' => isset($member->profileimageurl)
                ? (string) $member->profileimageurl : '',
            'is_contact' => !empty($member->iscontact),
            'is_online' => isset($member->isonline) ? (bool) $member->isonline : null,
            'is_blocked' => !empty($member->isblocked),
        ];
    }

    /**
     * Build a one-line summary.
     *
     * @param string $query
     * @param array<int, array<string, mixed>> $contacts
     * @param array<int, array<string, mixed>> $noncontacts
     * @return string
     */
    private static function build_summary(string $query, array $contacts, array $noncontacts): string {
        $total = count($contacts) + count($noncontacts);
        if ($total === 0) {
            return sprintf('No messageable users matched "%s".', $query);
        }
        return sprintf(
            'Found %d messageable user(s) for "%s" (%d contact(s), %d non-contact(s)).',
            $total,
            $query,
            count($contacts),
            count($noncontacts)
        );
    }
}
