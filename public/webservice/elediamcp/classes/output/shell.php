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
    /** @var string Active section key for this plugin page. */
    public const ACTIVE_ELEDIAMCP = 'elediamcp';

    /** @var string Active section key for the self-service token page. */
    public const ACTIVE_TOKENS = 'tokens';

    /** @var bool Whether the eLeDia.ai Tutor Plugin Shell helper is available. */
    private static bool $usespluginshell = false;

    /** @var string Optional eLeDia.ai Tutor shell page class. */
    private const PLUGIN_PAGE_CLASS = '\\block_elediaaitutor\\output\\plugin_page';

    /** @var string Optional eLeDia.ai Tutor shell helper class. */
    private const PLUGIN_SHELL_CLASS = '\\block_elediaaitutor\\output\\plugin_shell';

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

        if (file_exists($CFG->dirroot . '/blocks/elediaaitutor/styles.css')) {
            $PAGE->requires->css(new moodle_url('/blocks/elediaaitutor/styles.css'));
        }
        if (file_exists($CFG->dirroot . '/webservice/elediamcp/styles.css')) {
            $PAGE->requires->css(new moodle_url('/webservice/elediamcp/styles.css'));
        }
    }

    /**
     * Open the page shell and content area.
     */
    public static function open(
        string $active = self::ACTIVE_ELEDIAMCP,
        ?string $fallbackheading = null,
        ?string $fallbackhint = null
    ): void {
        $pluginpage = self::PLUGIN_PAGE_CLASS;
        $pluginshell = self::PLUGIN_SHELL_CLASS;
        self::$usespluginshell = class_exists($pluginpage) && class_exists($pluginshell);
        if (self::$usespluginshell) {
            $configurationurl = new moodle_url('/webservice/elediamcp/configuration.php');
            $headerdata = [
                'name' => get_string('pluginname', 'block_elediaaitutor'),
                'tagline' => get_string('nav_elediamcp', 'block_elediaaitutor'),
                'subtitle' => '',
                'sectionnav' => self::sectionnav($active),
            ] + $pluginshell::action_slots(
                'block_elediaaitutor',
                true,
                $configurationurl,
                get_string('shell_help_label', 'block_elediaaitutor'),
                get_string('shell_settings_label', 'block_elediaaitutor'),
                true
            );

            $pluginpage::open($headerdata, $pluginpage::MODIFIER_READING);
            $pluginshell::content_open();
            return;
        }

        echo html_writer::start_div('webservice-elediamcp-configuration');
        echo html_writer::tag(
            'h2',
            $fallbackheading ?? get_string('configuration_heading', 'webservice_elediamcp')
        );
        $hint = $fallbackhint ?? get_string('configuration_hint', 'webservice_elediamcp');
        if ($hint !== '') {
            echo html_writer::tag('p', $hint, ['class' => 'text-muted']);
        }
    }

    /**
     * Build the shared AI Tutor/MCP Plugin Shell section navigation.
     *
     * @return string Raw HTML for the Plugin Shell `sectionnav` slot.
     */
    private static function sectionnav(string $active): string {
        $items = [];

        if (class_exists('\block_elediaaitutor\output\shell')) {
            $items = [
                [
                    'key' => 'configuration',
                    'icon' => 'fa-th-large',
                    'label' => get_string('nav_configuration', 'block_elediaaitutor'),
                    'url' => new moodle_url('/blocks/elediaaitutor/configuration.php'),
                ],
                [
                    'key' => 'settings',
                    'icon' => 'fa-sliders',
                    'label' => get_string('configuration_admin_settings_title', 'block_elediaaitutor'),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'blocksettingelediaaitutor']),
                ],
                [
                    'key' => 'tutors',
                    'icon' => 'fa-paint-brush',
                    'label' => get_string('nav_tutors', 'block_elediaaitutor'),
                    'url' => new moodle_url('/blocks/elediaaitutor/manage_tutors.php'),
                ],
                [
                    'key' => 'preview',
                    'icon' => 'fa-comments',
                    'label' => get_string('nav_preview', 'block_elediaaitutor'),
                    'url' => new moodle_url('/blocks/elediaaitutor/view.php'),
                ],
            ];

            $integrations = [
                'local_literag' => [
                    'key' => 'literag',
                    'icon' => 'fa-database',
                    'label' => get_string('nav_literag', 'block_elediaaitutor'),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'local_literag']),
                ],
                'local_ragingest' => [
                    'key' => 'ragingest',
                    'icon' => 'fa-upload',
                    'label' => get_string('nav_ragingest', 'block_elediaaitutor'),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'local_ragingest_settings']),
                ],
            ];
            foreach ($integrations as $plugin => $item) {
                if (\core_component::get_plugin_directory(...explode('_', $plugin, 2)) !== null) {
                    $items[] = $item;
                }
            }
        }

        $items[] = [
            'key' => self::ACTIVE_ELEDIAMCP,
            'icon' => 'fa-plug',
            'label' => 'MCP',
            'url' => new moodle_url('/webservice/elediamcp/configuration.php'),
        ];

        $html = html_writer::start_tag('nav', [
            'class' => 'lh-plugin-section-nav',
            'aria-label' => class_exists('\block_elediaaitutor\output\shell')
                ? get_string('nav_label', 'block_elediaaitutor')
                : get_string('pluginname', 'webservice_elediamcp'),
        ]);

        foreach ($items as $item) {
            $attrs = [
                'class' => 'lh-plugin-section-nav__item',
                'href' => $item['url']->out(false),
            ];
            if ($item['key'] === self::ACTIVE_ELEDIAMCP
                    && in_array($active, [self::ACTIVE_ELEDIAMCP, self::ACTIVE_TOKENS], true)) {
                $attrs['aria-current'] = 'page';
            }
            $label = html_writer::tag('i', '', [
                'class' => 'fa ' . $item['icon'],
                'aria-hidden' => 'true',
            ]) . ' ' . s($item['label']);
            $html .= html_writer::tag('a', $label, $attrs);
        }

        $html .= html_writer::end_tag('nav');
        return $html;
    }

    /**
     * Close the content area and shell.
     */
    public static function close(): void {
        if (self::$usespluginshell) {
            $pluginpage = self::PLUGIN_PAGE_CLASS;
            $pluginshell = self::PLUGIN_SHELL_CLASS;
            $pluginshell::content_close();
            $pluginpage::close();
            self::$usespluginshell = false;
            return;
        }

        echo html_writer::end_div();
    }
}
