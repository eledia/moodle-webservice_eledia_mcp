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
use moodle_url;
use stdClass;
use Throwable;
use webservice_elediamcp\local\ai\ai_tool;
use webservice_elediamcp\local\ai\tool_exception;

/**
 * Teacher tool: update fields of an existing course activity.
 *
 * Uses the canonical get_moduleinfo_data()/update_moduleinfo() path so
 * events, calendar entries, gradebook and caches stay consistent. For
 * assignments the current submission/feedback plugin configuration is
 * re-applied explicitly, because update_moduleinfo() would otherwise
 * treat missing plugin flags as "disable".
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class moodle_update_activity implements ai_tool {
    /** @var string[] Supported activity types. */
    private const TYPES = ['page', 'label', 'url', 'book', 'assign'];

    /**
     * Return the MCP tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'moodle_update_activity';
    }

    /**
     * Return the human-readable tool title.
     *
     * @return string
     */
    public static function title(): string {
        return 'Update a course activity';
    }

    /**
     * Return the tool description shown to MCP clients.
     *
     * @return string
     */
    public static function description(): string {
        return 'Updates fields of an existing activity (types: page, label, url, book, assign). '
            . 'Supported per type: name and visible for all; intro (HTML description, for label the '
            . 'displayed content) for all; content for page; external_url for url; duedate, '
            . 'allowsubmissionsfromdate and cutoffdate for assign. Book chapters cannot be edited '
            . 'here. Requires moodle/course:manageactivities. Two-step flow: first call returns a '
            . 'preview of the changes; call again with confirm=true. Note: changing intro replaces '
            . 'embedded intro files.';
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
            'required' => ['cmid'],
            'properties' => [
                'cmid' => ['type' => 'integer', 'minimum' => 1],
                'name' => ['type' => 'string', 'maxLength' => 255],
                'visible' => ['type' => 'boolean'],
                'intro' => ['type' => 'string', 'description' => 'New HTML description.'],
                'content' => ['type' => 'string', 'description' => 'page only: new HTML body.'],
                'external_url' => ['type' => 'string', 'description' => 'url only: new target URL.'],
                'duedate' => ['type' => 'integer', 'minimum' => 0, 'description' => 'assign only, 0 = none.'],
                'allowsubmissionsfromdate' => ['type' => 'integer', 'minimum' => 0, 'description' => 'assign only.'],
                'cutoffdate' => ['type' => 'integer', 'minimum' => 0, 'description' => 'assign only.'],
                'confirm' => ['type' => 'boolean', 'default' => false],
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
            'required' => ['updated', 'requires_confirmation', 'summary'],
            'properties' => [
                'updated' => ['type' => 'boolean'],
                'requires_confirmation' => ['type' => 'boolean'],
                'activity' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'cmid' => ['type' => 'integer'],
                        'type' => ['type' => 'string'],
                        'name' => ['type' => 'string'],
                        'visible' => ['type' => 'boolean'],
                        'url' => ['type' => 'string'],
                    ],
                ],
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'field' => ['type' => 'string'],
                            'old' => ['type' => 'string'],
                            'new' => ['type' => 'string'],
                        ],
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
            'idempotentHint' => true,
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
        global $CFG, $PAGE;

        $cmid = (int) ($arguments['cmid'] ?? 0);
        if ($cmid <= 0) {
            throw new tool_exception('cmid is required.');
        }
        $cm = get_coursemodule_from_id('', $cmid, 0, false, MUST_EXIST);
        $type = (string) $cm->modname;
        if (!in_array($type, self::TYPES, true)) {
            throw new tool_exception(
                'Activities of type ' . $type . ' cannot be updated with this tool. Supported: '
                . implode(', ', self::TYPES) . '.',
                ['type' => $type]
            );
        }
        $course = get_course((int) $cm->course);
        $coursecontext = context_course::instance((int) $course->id);
        require_capability('moodle/course:manageactivities', $coursecontext, $user->id);

        require_once($CFG->dirroot . '/course/modlib.php');
        require_once($CFG->libdir . '/resourcelib.php');
        $PAGE->set_context($coursecontext);

        [$cm, $context, $module, $data, $cw] = get_moduleinfo_data($cm, $course);

        $changes = self::apply_changes($type, $arguments, $data, $context);
        if ($changes === []) {
            throw new tool_exception(
                'No supported field to change was provided. Pass at least one of: name, visible, '
                . 'intro, content, external_url, duedate, allowsubmissionsfromdate, cutoffdate.'
            );
        }

        $activityurl = $type === 'label'
            ? (new moodle_url('/course/view.php', ['id' => (int) $course->id]))->out(false) . '#module-' . $cmid
            : (new moodle_url('/mod/' . $type . '/view.php', ['id' => $cmid]))->out(false);

        if (empty($arguments['confirm'])) {
            return [
                'updated' => false,
                'requires_confirmation' => true,
                'activity' => null,
                'changes' => $changes,
                'summary' => 'Ready to change ' . count($changes) . ' field(s) of ' . $type . ' "'
                    . $data->name . '". Call again with confirm=true to apply.',
            ];
        }

        self::prepare_type_data($type, $data, $context);

        try {
            update_moduleinfo($cm, $data, $course);
        } catch (Throwable $ex) {
            throw new tool_exception('Updating the activity failed: ' . $ex->getMessage());
        }

        $updatedcm = get_fast_modinfo((int) $course->id)->get_cm($cmid);
        return [
            'updated' => true,
            'requires_confirmation' => false,
            'activity' => [
                'cmid' => $cmid,
                'type' => $type,
                'name' => $updatedcm->get_formatted_name(),
                'visible' => (bool) $updatedcm->visible,
                'url' => $activityurl,
            ],
            'changes' => $changes,
            'summary' => 'Updated ' . $type . ' "' . $updatedcm->get_formatted_name() . '" ('
                . count($changes) . ' field(s) changed). Link: ' . $activityurl
                . ' — share this link with the user.',
        ];
    }

    /**
     * Apply requested changes onto the moduleinfo data and record a diff.
     *
     * @param string $type Activity type.
     * @param array<string, mixed> $arguments Tool arguments.
     * @param stdClass $data Moduleinfo data (modified in place).
     * @param \context_module $context Module context.
     * @return array<int, array{field: string, old: string, new: string}>
     */
    private static function apply_changes(string $type, array $arguments, stdClass $data, \context_module $context): array {
        $changes = [];

        if (array_key_exists('name', $arguments) && trim((string) $arguments['name']) !== '') {
            $new = trim((string) $arguments['name']);
            if ($new !== (string) $data->name) {
                $changes[] = ['field' => 'name', 'old' => (string) $data->name, 'new' => $new];
                $data->name = $new;
            }
        }
        if (array_key_exists('visible', $arguments)) {
            $new = (bool) $arguments['visible'];
            if ($new !== (bool) $data->visible) {
                $changes[] = ['field' => 'visible', 'old' => $data->visible ? 'true' : 'false',
                    'new' => $new ? 'true' : 'false'];
                $data->visible = $new ? 1 : 0;
            }
        }
        if (array_key_exists('intro', $arguments)) {
            $new = (string) $arguments['intro'];
            $changes[] = ['field' => 'intro',
                'old' => shorten_text(html_to_text((string) $data->intro, 0, false), 80),
                'new' => shorten_text(html_to_text($new, 0, false), 80)];
            $data->introeditor = ['text' => $new, 'format' => FORMAT_HTML, 'itemid' => 0];
            if ($type === 'label' && !array_key_exists('name', $arguments)) {
                // Let label_update_instance() re-derive the name from the new intro.
                $data->name = '';
            }
        }
        if ($type === 'page' && array_key_exists('content', $arguments)) {
            $new = (string) $arguments['content'];
            $changes[] = ['field' => 'content',
                'old' => shorten_text(html_to_text((string) ($data->content ?? ''), 0, false), 80),
                'new' => shorten_text(html_to_text($new, 0, false), 80)];
            // The page editor array is read unconditionally by page_update_instance().
            $data->page = ['text' => $new, 'format' => FORMAT_HTML, 'itemid' => 0];
        }
        if ($type === 'url' && array_key_exists('external_url', $arguments)) {
            global $CFG;
            require_once($CFG->dirroot . '/mod/url/locallib.php');
            $new = url_fix_submitted_url(trim((string) $arguments['external_url']));
            if (!url_appears_valid_url($new)) {
                throw new tool_exception('external_url is not a valid URL.');
            }
            $changes[] = ['field' => 'external_url', 'old' => (string) ($data->externalurl ?? ''), 'new' => $new];
            $data->externalurl = $new;
        }
        if ($type === 'assign') {
            foreach (['duedate', 'allowsubmissionsfromdate', 'cutoffdate'] as $field) {
                if (array_key_exists($field, $arguments)) {
                    $new = max(0, (int) $arguments[$field]);
                    if ($new !== (int) $data->{$field}) {
                        $changes[] = ['field' => $field, 'old' => (string) (int) $data->{$field},
                            'new' => (string) $new];
                        $data->{$field} = $new;
                    }
                }
            }
            $duedate = (int) $data->duedate;
            $allowfrom = (int) $data->allowsubmissionsfromdate;
            $cutoff = (int) $data->cutoffdate;
            if ($duedate > 0 && $allowfrom > 0 && $duedate < $allowfrom) {
                throw new tool_exception('duedate must not be before allowsubmissionsfromdate.');
            }
            if ($cutoff > 0 && $duedate > 0 && $cutoff < $duedate) {
                throw new tool_exception('cutoffdate must not be before duedate.');
            }
        }

        return $changes;
    }

    /**
     * Fill in the type-specific fields update_moduleinfo() reads unconditionally.
     *
     * @param string $type Activity type.
     * @param stdClass $data Moduleinfo data (modified in place).
     * @param \context_module $context Module context.
     */
    private static function prepare_type_data(string $type, stdClass $data, \context_module $context): void {
        global $CFG;

        if (!isset($data->introeditor)) {
            $data->introeditor = [
                'text' => (string) ($data->intro ?? ''),
                'format' => (int) ($data->introformat ?? FORMAT_HTML),
                'itemid' => 0,
            ];
        }

        if ($type === 'page' || $type === 'url') {
            $options = [];
            if (!empty($data->displayoptions)) {
                $options = (array) unserialize_array((string) $data->displayoptions);
            }
            $data->printintro = (int) ($options['printintro'] ?? ($type === 'url' ? 1 : 0));
            if ($type === 'page') {
                $data->printlastmodified = (int) ($options['printlastmodified'] ?? 1);
                if (!isset($data->page)) {
                    // Unchanged content must still travel through the editor array.
                    $data->page = [
                        'text' => (string) ($data->content ?? ''),
                        'format' => (int) ($data->contentformat ?? FORMAT_HTML),
                        'itemid' => 0,
                    ];
                }
            }
            $data->popupwidth = (int) ($options['popupwidth'] ?? 620);
            $data->popupheight = (int) ($options['popupheight'] ?? 450);
        }

        if ($type === 'assign') {
            require_once($CFG->dirroot . '/mod/assign/locallib.php');
            $assign = new \assign($context, null, null);
            foreach (array_merge($assign->get_submission_plugins(), $assign->get_feedback_plugins()) as $plugin) {
                if (!$plugin->is_visible()) {
                    continue;
                }
                $key = $plugin->get_subtype() . '_' . $plugin->get_type() . '_enabled';
                $data->{$key} = $plugin->is_enabled() ? 1 : 0;
            }
            $fileplugin = $assign->get_submission_plugin_by_type('file');
            if ($fileplugin && $fileplugin->is_enabled()) {
                $data->assignsubmission_file_maxfiles = (int) ($fileplugin->get_config('maxfilesubmissions') ?: 20);
                $data->assignsubmission_file_maxsizebytes = (int) ($fileplugin->get_config('maxsubmissionsizebytes') ?: 0);
            }
        }
    }
}
