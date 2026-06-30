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
use context_user;
use core_message\api as message_api;
use core_user;
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * AI-native personal-message sender (write tool).
 *
 * Sends a one-to-one Moodle message from the authenticated user to a
 * recipient identified by id or username, using the modern conversation API.
 *
 * Safety:
 * - Requires the moodle/site:sendmessage capability on the sender.
 * - Honours the recipient's message-acceptance preferences via
 *   \core_message\api::can_send_message.
 * - Requires confirm: true on the second call; the first call returns a
 *   structured preview so the agent can ask the user for confirmation.
 * - Hard limit of 4000 characters on the message body.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_send_message implements ai_tool {
    /** @var int Max message length. */
    private const MAX_LENGTH = 4000;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_send_message';
    }

    /**
     * Tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Send a personal Moodle message';
    }

    /**
     * Tool description.
     *
     * @return string
     */
    public static function description(): string {
        return 'Sends a one-to-one personal Moodle message from the authenticated user. The '
            . 'recipient is identified, in order of precedence, by to_user_id (preferred, '
            . 'unambiguous), to_username (exact match), or to_query (fuzzy name match that '
            . 'must resolve to exactly one messageable user). When the user supplies only a '
            . 'first name or nickname, call moodle_find_user FIRST to obtain a concrete '
            . 'to_user_id. Two-step flow: the first call (confirm omitted or false) returns a '
            . 'preview with requires_confirmation=true so the agent can ask the user; the '
            . 'second call with confirm=true actually sends. Hard limit 4000 characters. '
            . 'Honours the recipient\'s message-acceptance preferences and the sender\'s '
            . 'moodle/site:sendmessage capability.';
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
            'required' => ['message'],
            'properties' => [
                'to_user_id' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'description' => 'Recipient user id. Takes precedence over to_username.',
                ],
                'to_username' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'Recipient username (exact match, alternative to to_user_id).',
                ],
                'to_query' => [
                    'type' => 'string',
                    'minLength' => 2,
                    'description' => 'Recipient name fragment. Resolved via the same lookup as '
                        . 'moodle_find_user; the call succeeds only when exactly one messageable '
                        . 'user matches. When zero or several users match, an error with a '
                        . 'candidates list is returned so the agent can ask the user to clarify.',
                ],
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MAX_LENGTH,
                    'description' => 'Plain-text message body (max 4000 characters).',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually send. Omit or set to false to '
                        . 'receive a preview without sending.',
                ],
            ],
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
            'required' => ['sent', 'summary'],
            'properties' => [
                'sent' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'recipient' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'username' => ['type' => 'string'],
                        'fullname' => ['type' => 'string'],
                        'profile_url' => ['type' => 'string'],
                    ],
                ],
                'sender' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'integer'],
                        'fullname' => ['type' => 'string'],
                    ],
                ],
                'message_id' => ['type' => ['integer', 'null']],
                'conversation_id' => ['type' => ['integer', 'null']],
                'message_preview' => ['type' => 'string'],
                'sent_iso' => ['type' => ['string', 'null']],
                'summary' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Tool annotations.
     *
     * Write tool: readOnlyHint=false, destructiveHint=false (message creation
     * is additive but has external side-effects), idempotentHint=false.
     *
     * @return array<string,mixed>
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
     * Execute the tool.
     *
     * @param array $arguments Validated arguments.
     * @param stdClass $user Authenticated user record.
     * @return array<string,mixed>
     */
    public static function execute(array $arguments, stdClass $user): array {
        $fromuserid = (int) $user->id;
        if ($fromuserid <= 0 || isguestuser($user)) {
            throw new tool_exception('Guest users cannot send messages.');
        }
        require_capability('moodle/site:sendmessage', context_system::instance(), $fromuserid);

        $message = isset($arguments['message']) ? trim((string) $arguments['message']) : '';
        if ($message === '') {
            throw new tool_exception('message is required.');
        }
        if (function_exists('mb_strlen')) {
            $len = mb_strlen($message);
        } else {
            $len = strlen($message);
        }
        if ($len > self::MAX_LENGTH) {
            throw new tool_exception(
                sprintf('Message is too long (%d chars); max is %d.', $len, self::MAX_LENGTH),
                ['length' => $len, 'max' => self::MAX_LENGTH]
            );
        }

        $touserid = isset($arguments['to_user_id']) ? (int) $arguments['to_user_id'] : 0;
        $tousername = isset($arguments['to_username']) ? trim((string) $arguments['to_username']) : '';
        $toquery = isset($arguments['to_query']) ? trim((string) $arguments['to_query']) : '';
        $recipient = self::resolve_recipient($fromuserid, $touserid, $tousername, $toquery);

        if ((int) $recipient->id === $fromuserid) {
            throw new tool_exception('Cannot send a message to yourself.');
        }

        // Capability + recipient-preference check.
        if (!message_api::can_send_message((int) $recipient->id, $fromuserid)) {
            throw new tool_exception(
                sprintf(
                    'You cannot send messages to %s (capability denied or recipient does not accept messages from you).',
                    fullname($recipient)
                ),
                ['recipient_id' => (int) $recipient->id]
            );
        }

        $confirm = !empty($arguments['confirm']);
        $preview = self::build_preview($message);

        $payload = [
            'sent' => false,
            'requires_confirmation' => !$confirm,
            'recipient' => [
                'id' => (int) $recipient->id,
                'username' => (string) $recipient->username,
                'fullname' => fullname($recipient),
                'profile_url' => (new moodle_url('/user/profile.php', ['id' => (int) $recipient->id]))->out(false),
            ],
            'sender' => [
                'id' => $fromuserid,
                'fullname' => fullname($user),
            ],
            'message_id' => null,
            'conversation_id' => null,
            'message_preview' => $preview,
            'sent_iso' => null,
            'summary' => '',
        ];

        if (!$confirm) {
            $payload['summary'] = sprintf(
                'Preview only. Set confirm=true to send "%s" to %s.',
                $preview,
                fullname($recipient)
            );
            return $payload;
        }

        try {
            $conversation = message_api::get_conversation_between_users([$fromuserid, (int) $recipient->id]);
            if (!$conversation) {
                $convobj = message_api::create_conversation(
                    message_api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                    [$fromuserid, (int) $recipient->id]
                );
                $conversationid = (int) $convobj->id;
            } else {
                $conversationid = (int) (is_object($conversation) ? $conversation->id : $conversation);
            }

            $result = message_api::send_message_to_conversation(
                $fromuserid,
                $conversationid,
                $message,
                (int) FORMAT_PLAIN
            );
        } catch (Throwable $ex) {
            throw new tool_exception(
                'Sending the message failed: ' . $ex->getMessage(),
                ['recipient_id' => (int) $recipient->id]
            );
        }

        $messageid = isset($result->id) ? (int) $result->id : null;
        $sentat = isset($result->timecreated) ? (int) $result->timecreated : time();

        $payload['sent'] = true;
        $payload['requires_confirmation'] = false;
        $payload['message_id'] = $messageid;
        $payload['conversation_id'] = $conversationid;
        $payload['sent_iso'] = gmdate('c', $sentat);
        $payload['summary'] = sprintf(
            'Message sent to %s (id %d). Message id %s.',
            fullname($recipient),
            (int) $recipient->id,
            $messageid !== null ? (string) $messageid : '?'
        );

        return $payload;
    }

    /**
     * Resolve the recipient user record from id, exact username or fuzzy query.
     *
     * Precedence: to_user_id > to_username > to_query. The fuzzy branch uses
     * the same messageable-users lookup as moodle_find_user, so anyone the
     * sender is not allowed to message is invisible here too.
     *
     * @param int $fromuserid Sender user id (needed for the fuzzy lookup).
     * @param int $touserid Recipient user id (0 to fall through).
     * @param string $tousername Recipient username ('' to fall through).
     * @param string $toquery Recipient fuzzy name fragment ('' to fall through).
     * @return stdClass User record.
     * @throws tool_exception When the recipient cannot be resolved unambiguously.
     */
    private static function resolve_recipient(
        int $fromuserid,
        int $touserid,
        string $tousername,
        string $toquery
    ): stdClass {
        global $DB;

        if ($touserid > 0) {
            $record = $DB->get_record('user', ['id' => $touserid, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$record) {
                throw new tool_exception(
                    "Recipient user id {$touserid} does not exist or is deleted.",
                    ['to_user_id' => $touserid]
                );
            }
            if (!empty($record->suspended)) {
                throw new tool_exception(
                    "Recipient user id {$touserid} is suspended.",
                    ['to_user_id' => $touserid]
                );
            }
            return $record;
        }

        if ($tousername !== '') {
            $record = $DB->get_record('user', ['username' => $tousername, 'deleted' => 0], '*', IGNORE_MISSING);
            if (!$record) {
                throw new tool_exception(
                    "Recipient username '{$tousername}' does not exist. If you only know the "
                        . "user's name, call moodle_find_user with query='{$tousername}' to look "
                        . "up the correct id, then retry with to_user_id.",
                    ['to_username' => $tousername]
                );
            }
            if (!empty($record->suspended)) {
                throw new tool_exception(
                    "Recipient username '{$tousername}' is suspended.",
                    ['to_username' => $tousername]
                );
            }
            return $record;
        }

        if ($toquery !== '') {
            return self::resolve_by_query($fromuserid, $toquery);
        }

        throw new tool_exception(
            'A recipient is required. Provide one of: to_user_id, to_username, to_query. When '
                . 'you only know the recipient by first name or nickname, call moodle_find_user '
                . 'first to obtain a concrete to_user_id.'
        );
    }

    /**
     * Resolve a fuzzy name fragment via the message_search_users API.
     *
     * Succeeds only when exactly one messageable user matches; on ambiguity
     * raises a tool_exception that includes the candidates so the agent can
     * ask the user to pick.
     *
     * @param int $fromuserid Sender user id.
     * @param string $query Fuzzy name fragment.
     * @return stdClass Full user record of the unique match.
     * @throws tool_exception When zero or multiple users match.
     */
    private static function resolve_by_query(int $fromuserid, string $query): stdClass {
        global $DB;

        // Cap the lookup at 6 results — more than that is always "ask the user".
        try {
            [$contacts, $noncontacts] = message_api::message_search_users($fromuserid, $query, 0, 6);
        } catch (Throwable $ex) {
            throw new tool_exception(
                'Recipient lookup failed: ' . $ex->getMessage(),
                ['to_query' => $query]
            );
        }

        $matches = array_merge((array) $contacts, (array) $noncontacts);
        $count = count($matches);

        if ($count === 0) {
            throw new tool_exception(
                "No messageable user matches '{$query}'. Try a different spelling, a longer "
                    . "fragment, or the surname. Site policy may also restrict messaging to "
                    . "users sharing at least one course.",
                ['to_query' => $query]
            );
        }

        if ($count > 1) {
            $candidates = [];
            foreach ($matches as $m) {
                $candidates[] = [
                    'id' => isset($m->id) ? (int) $m->id : 0,
                    'fullname' => isset($m->fullname) ? (string) $m->fullname : '',
                ];
            }
            throw new tool_exception(
                sprintf(
                    "%d messageable users match '%s'. Ask the user which one is intended, then "
                        . "retry with to_user_id set to the chosen id.",
                    $count,
                    $query
                ),
                ['to_query' => $query, 'candidates' => $candidates]
            );
        }

        $only = $matches[0];
        $uid = isset($only->id) ? (int) $only->id : 0;
        $record = $uid > 0
            ? $DB->get_record('user', ['id' => $uid, 'deleted' => 0], '*', IGNORE_MISSING)
            : null;
        if (!$record) {
            throw new tool_exception(
                "Resolved a match for '{$query}' but the user record disappeared.",
                ['to_query' => $query, 'resolved_id' => $uid]
            );
        }
        if (!empty($record->suspended)) {
            throw new tool_exception(
                "Resolved match for '{$query}' (id {$uid}) is suspended.",
                ['to_query' => $query, 'resolved_id' => $uid]
            );
        }
        return $record;
    }

    /**
     * Build a short preview of the message body.
     *
     * @param string $message Plain-text body.
     * @return string
     */
    private static function build_preview(string $message): string {
        $oneline = preg_replace('/\s+/', ' ', $message);
        if (\core_text::strlen($oneline) > 120) {
            return \core_text::substr($oneline, 0, 117) . '...';
        }
        return $oneline;
    }
}
