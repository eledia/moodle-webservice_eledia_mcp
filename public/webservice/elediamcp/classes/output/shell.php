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

namespace webservice_elediamcp\output;

use html_writer;
use local_lernhive\output\plugin_page;
use local_lernhive\output\plugin_shell;
use moodle_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Shell wrapper for plugin-owned MCP pages.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class shell {
    /** @var bool Whether the LernHive Plugin Shell helper is available. */
    private static bool $usespluginshell = false;

    /**
     * Static-only helper.
     */
    private function __construct() {
    }

    /**
     * Queue shell CSS when the shared shell is installed.
     */
    public static function require_css(): void {
        global $PAGE, $CFG;

        if (file_exists($CFG->dirroot . '/local/lernhive/styles.css')) {
            $PAGE->requires->css(new moodle_url('/local/lernhive/styles.css'));
        }
    }

    /**
     * Open the page shell and content area.
     */
    public static function open(): void {
        self::$usespluginshell = class_exists(plugin_page::class) && class_exists(plugin_shell::class);
        if (self::$usespluginshell) {
            $configurationurl = new moodle_url('/webservice/elediamcp/configuration.php');
            $headerdata = [
                'name' => get_string('pluginname', 'webservice_elediamcp'),
                'tagline' => get_string('configuration_tagline', 'webservice_elediamcp'),
                'hint' => get_string('configuration_hint', 'webservice_elediamcp'),
                'hastags' => true,
                'tags' => [
                    [
                        'modifier' => 'active',
                        'faicon' => 'fa-plug',
                        'label' => get_string('configuration_tag_mcp', 'webservice_elediamcp'),
                    ],
                    [
                        'modifier' => 'info',
                        'faicon' => 'fa-shield',
                        'label' => get_string('configuration_tag_security', 'webservice_elediamcp'),
                    ],
                ],
            ] + plugin_shell::action_slots(
                'webservice_elediamcp',
                true,
                $configurationurl,
                settingsiscurrent: true
            );

            plugin_page::open($headerdata, plugin_page::MODIFIER_READING);
            plugin_shell::content_open();
            return;
        }

        echo html_writer::start_div('webservice-elediamcp-configuration');
        echo html_writer::tag('h2', get_string('configuration_heading', 'webservice_elediamcp'));
        echo html_writer::tag('p', get_string('configuration_hint', 'webservice_elediamcp'), ['class' => 'text-muted']);
    }

    /**
     * Close the content area and shell.
     */
    public static function close(): void {
        if (self::$usespluginshell) {
            plugin_shell::content_close();
            plugin_page::close();
            self::$usespluginshell = false;
            return;
        }

        echo html_writer::end_div();
    }
}
