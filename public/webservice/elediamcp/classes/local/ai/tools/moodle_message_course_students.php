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

use context_course;
use core_message\api as message_api;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Teacher tool: message a targeted group of students in a course.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_message_course_students implements ai_tool {
    /** @var int Hard cap on recipients per call. */
    private const MAX_RECIPIENTS = 100;

    /** @var int Recipient names shown in the preview. */
    private const PREVIEW_NAME_CAP = 15;

    /** @var int Message length cap (matches moodle_send_message). */
    private const MESSAGE_CAP = 4000;

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_message_course_students';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Message course students';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Sends the same personal message to a targeted group of students in a course: all '
            . 'students, students who have not submitted a specific assignment (target=not_submitted '
            . 'with assign_cmid), or students inactive for N days (target=inactive). Requires '
            . 'moodle/course:manageactivities. Two-step flow: the first call returns the resolved '
            . 'recipient list as a preview — show it to the user before confirming. Hard cap of '
            . self::MAX_RECIPIENTS . ' recipients per call. Recipients whose preferences block the '
            . 'message are skipped and reported.';
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
            'required' => ['course_id', 'message', 'target'],
            'properties' => [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'message' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => self::MESSAGE_CAP,
                    'description' => 'Plain-text message sent to each recipient individually.',
                ],
                'target' => [
                    'type' => 'string',
                    'enum' => ['all', 'not_submitted', 'inactive'],
                ],
                'assign_cmid' => [
                    'type' => 'integer',
                    'minimum' => 0,
                    'default' => 0,
                    'description' => 'Required for target=not_submitted: the assignment course module id.',
                ],
                'inactive_days' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 365,
                    'default' => 14,
                    'description' => 'For target=inactive: no course access in this many days.',
                ],
                'confirm' => [
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Must be true to actually send. Show the recipient list to the user first.',
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
            'required' => ['sent', 'requires_confirmation', 'summary'],
            'properties' => [
                'sent' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'recipient_count' => ['type' => 'integer'],
                'recipients_preview' => [
                    'type' => 'array',
                    'description' => 'Capped list of recipient names.',
                    'items' => ['type' => 'string'],
                ],
                'sent_count' => ['type' => 'integer'],
                'skipped' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'fullname' => ['type' => 'string'],
                            'reason' => ['type' => 'string'],
                        ],
                    ],
                ],
                'message_preview' => ['type' => 'string'],
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
        $courseid = (int) ($arguments['course_id'] ?? 0);
        $message = trim((string) ($arguments['message'] ?? ''));
        $target = trim((string) ($arguments['target'] ?? ''));
        if ($courseid <= 0) {
            throw new tool_exception('course_id is required.');
        }
        if ($message === '' || \core_text::strlen($message) > self::MESSAGE_CAP) {
            throw new tool_exception('message is required and must be at most ' . self::MESSAGE_CAP . ' characters.');
        }
        if (!in_array($target, ['all', 'not_submitted', 'inactive'], true)) {
            throw new tool_exception('Invalid target. Allowed: all, not_submitted, inactive.');
        }

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        require_capability('moodle/course:manageactivities', $context, $user->id);
        require_capability('moodle/course:viewparticipants', $context, $user->id);

        $recipients = self::resolve_recipients($course, $target, $arguments);
        // Never message yourself.
        $recipients = array_values(array_filter(
            $recipients,
            static fn(stdClass $recipient): bool => (int) $recipient->id !== (int) $user->id
        ));

        if ($recipients === []) {
            return [
                'sent' => false,
                'requires_confirmation' => false,
                'recipient_count' => 0,
                'recipients_preview' => [],
                'sent_count' => 0,
                'skipped' => [],
                'message_preview' => shorten_text($message, 200),
                'summary' => 'No matching recipients found; nothing to send.',
            ];
        }
        if (count($recipients) > self::MAX_RECIPIENTS) {
            throw new tool_exception(
                count($recipients) . ' recipients exceed the cap of ' . self::MAX_RECIPIENTS
                . '. Narrow the target group.',
                ['recipient_count' => count($recipients)]
            );
        }

        $names = array_map(static fn(stdClass $recipient): string => fullname($recipient), $recipients);

        if (empty($arguments['confirm'])) {
            return [
                'sent' => false,
                'requires_confirmation' => true,
                'recipient_count' => count($recipients),
                'recipients_preview' => array_slice($names, 0, self::PREVIEW_NAME_CAP),
                'sent_count' => 0,
                'skipped' => [],
                'message_preview' => shorten_text($message, 200),
                'summary' => 'Ready to message ' . count($recipients) . ' student(s) in '
                    . format_string($course->fullname, true, ['context' => $context])
                    . '. Show the user the recipient list and the message, then call again with confirm=true.',
            ];
        }

        $fromuserid = (int) $user->id;
        $sentcount = 0;
        $skipped = [];
        foreach ($recipients as $recipient) {
            $recipientid = (int) $recipient->id;
            try {
                if (!message_api::can_send_message($recipientid, $fromuserid)) {
                    $skipped[] = ['fullname' => fullname($recipient), 'reason' => 'recipient does not accept messages'];
                    continue;
                }
                $conversation = message_api::get_conversation_between_users([$fromuserid, $recipientid]);
                if (!$conversation) {
                    $conversation = message_api::create_conversation(
                        message_api::MESSAGE_CONVERSATION_TYPE_INDIVIDUAL,
                        [$fromuserid, $recipientid]
                    );
                    $conversationid = (int) $conversation->id;
                } else {
                    $conversationid = (int) (is_object($conversation) ? $conversation->id : $conversation);
                }
                message_api::send_message_to_conversation($fromuserid, $conversationid, $message, (int) FORMAT_PLAIN);
                $sentcount++;
            } catch (Throwable $ex) {
                $skipped[] = ['fullname' => fullname($recipient), 'reason' => $ex->getMessage()];
            }
        }

        return [
            'sent' => true,
            'requires_confirmation' => false,
            'recipient_count' => count($recipients),
            'recipients_preview' => array_slice($names, 0, self::PREVIEW_NAME_CAP),
            'sent_count' => $sentcount,
            'skipped' => $skipped,
            'message_preview' => shorten_text($message, 200),
            'summary' => 'Sent the message to ' . $sentcount . ' of ' . count($recipients) . ' student(s).'
                . (count($skipped) > 0 ? ' ' . count($skipped) . ' skipped (see skipped list).' : ''),
        ];
    }

    /**
     * Resolve the recipient list for the chosen target.
     *
     * @param stdClass $course Course record.
     * @param string $target Target group key.
     * @param array<string, mixed> $arguments Tool arguments.
     * @return stdClass[]
     */
    private static function resolve_recipients(stdClass $course, string $target, array $arguments): array {
        global $DB;

        $context = context_course::instance((int) $course->id);
        $students = get_enrolled_users($context, 'mod/assign:submit', 0, 'u.*', null, 0, 0, true);

        if ($target === 'all') {
            return array_values($students);
        }

        if ($target === 'not_submitted') {
            $assigncmid = (int) ($arguments['assign_cmid'] ?? 0);
            if ($assigncmid <= 0) {
                throw new tool_exception('assign_cmid is required for target=not_submitted.');
            }
            $cm = get_coursemodule_from_id('assign', $assigncmid, (int) $course->id, false, MUST_EXIST);
            $submitted = $DB->get_records_sql_menu(
                "SELECT s.userid, 1
                   FROM {assign_submission} s
                  WHERE s.assignment = :assignment AND s.latest = 1 AND s.status = 'submitted'",
                ['assignment' => (int) $cm->instance]
            );
            return array_values(array_filter(
                $students,
                static fn(stdClass $student): bool => !isset($submitted[$student->id])
            ));
        }

        // Target inactive.
        $days = isset($arguments['inactive_days']) ? max(1, min(365, (int) $arguments['inactive_days'])) : 14;
        $cutoff = time() - $days * DAYSECS;
        $lastaccess = $DB->get_records_menu(
            'user_lastaccess',
            ['courseid' => (int) $course->id],
            '',
            'userid, timeaccess'
        );
        return array_values(array_filter(
            $students,
            static fn(stdClass $student): bool => (int) ($lastaccess[$student->id] ?? 0) < $cutoff
        ));
    }
}
