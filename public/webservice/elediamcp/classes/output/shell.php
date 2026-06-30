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

    /**
     * Optional eLeDia.ai Tutor shell page classes, newest component name first.
     *
     * @var string[]
     */
    private const TUTOR_PAGE_CLASSES = [
        '\\block_eledia_aitutor\\output\\plugin_page',
        '\\block_elediaaitutor\\output\\plugin_page',
    ];

    /**
     * Optional eLeDia.ai Tutor shell helper classes, newest component name first.
     *
     * @var string[]
     */
    private const TUTOR_PLUGIN_SHELL_CLASSES = [
        '\\block_eledia_aitutor\\output\\plugin_shell',
        '\\block_elediaaitutor\\output\\plugin_shell',
    ];

    /**
     * Optional eLeDia.ai Tutor shell classes, newest component name first.
     *
     * @var string[]
     */
    private const TUTOR_SHELL_CLASSES = [
        '\\block_eledia_aitutor\\output\\shell',
        '\\block_elediaaitutor\\output\\shell',
    ];

    /**
     * Optional eLeDia.ai Tutor language components, newest component name first.
     *
     * @var string[]
     */
    private const TUTOR_COMPONENTS = [
        'block_eledia_aitutor',
        'block_elediaaitutor',
    ];

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

        $PAGE->add_body_class('lh-plugin-shell-page');
        $PAGE->add_body_class('path-webservice-elediamcp');

        if (file_exists($CFG->dirroot . '/local/lernhive/styles.css')) {
            $PAGE->requires->css(new moodle_url('/local/lernhive/styles.css'));
        }
        if (file_exists($CFG->dirroot . '/blocks/eledia_aitutor/styles.css')) {
            $PAGE->requires->css(new moodle_url('/blocks/eledia_aitutor/styles.css'));
        } else if (file_exists($CFG->dirroot . '/blocks/elediaaitutor/styles.css')) {
            $PAGE->requires->css(new moodle_url('/blocks/elediaaitutor/styles.css'));
        }
        if (file_exists($CFG->dirroot . '/webservice/elediamcp/styles.css')) {
            $PAGE->requires->css(new moodle_url('/webservice/elediamcp/styles.css'));
        }
    }

    /**
     * Open the page shell and content area.
     *
     * @param string $active Active navigation slot identifier.
     * @param string|null $fallbackheading Heading shown when the Plugin Shell is unavailable.
     * @param string|null $fallbackhint Hint text shown when the Plugin Shell is unavailable.
     * @return void
     */
    public static function open(
        string $active = self::ACTIVE_ELEDIAMCP,
        ?string $fallbackheading = null,
        ?string $fallbackhint = null
    ): void {
        $pluginpage = self::tutor_page_class();
        $pluginshell = self::tutor_plugin_shell_class();
        self::$usespluginshell = $pluginpage !== null && $pluginshell !== null;
        if (self::$usespluginshell) {
            $canconfig = has_capability('moodle/site:config', \core\context\system::instance());
            $configurationurl = new moodle_url('/webservice/elediamcp/configuration.php');
            $tutorcomponent = self::tutor_component();
            $actions = $pluginshell::action_slots(
                'webservice_elediamcp',
                $canconfig,
                $configurationurl,
                get_string('shell_help_label', 'webservice_elediamcp'),
                get_string('shell_settings_label', 'webservice_elediamcp'),
                true
            );
            // The help page is the admin configuration guide (site:config). Only link it
            // for users who can open it, so non-admins (e.g. a teacher on the token page)
            // do not hit an "access denied" — and the settings cog is gated the same way.
            if ($canconfig) {
                $actions['helpurl'] = (new moodle_url('/webservice/elediamcp/help.php'))->out(false);
            }

            $headerdata = [
                'name' => $tutorcomponent !== null
                    ? get_string('pluginname', $tutorcomponent)
                    : get_string('pluginname', 'webservice_elediamcp'),
                'tagline' => $tutorcomponent !== null
                    ? get_string('nav_elediamcp', $tutorcomponent)
                    : get_string('configuration_tagline', 'webservice_elediamcp'),
                'subtitle' => '',
                'sectionnav' => self::sectionnav($active),
            ] + $actions;

            $pluginpage::open($headerdata, $pluginpage::MODIFIER_READING);
            $pluginshell::content_open();
            return;
        }

        echo html_writer::start_div('webservice-elediamcp-configuration');

        // Fallback header with optional help action (mirrors Plugin Shell behaviour).
        $heading = $fallbackheading ?? get_string('configuration_heading', 'webservice_elediamcp');
        $headinghtml = html_writer::tag('h2', $heading, ['class' => 'webservice-elediamcp-page-title']);

        if (has_capability('moodle/site:config', \core\context\system::instance())) {
            $helpurl = new moodle_url('/webservice/elediamcp/help.php');
            $helphtml = html_writer::link(
                $helpurl,
                html_writer::tag('i', '', ['class' => 'fa fa-question-circle', 'aria-hidden' => 'true'])
                    . ' ' . get_string('shell_help_label', 'webservice_elediamcp'),
                ['class' => 'webservice-elediamcp-fallback-help']
            );
            echo html_writer::div($headinghtml . $helphtml, 'webservice-elediamcp-fallback-header');
        } else {
            echo $headinghtml;
        }

        $hint = $fallbackhint ?? get_string('configuration_hint', 'webservice_elediamcp');
        if ($hint !== '') {
            echo html_writer::tag('p', $hint, ['class' => 'text-muted']);
        }
    }

    /**
     * Build the shared AI Tutor/MCP Plugin Shell section navigation.
     *
     * @param string $active Active navigation slot identifier.
     * @return string Raw HTML for the Plugin Shell `sectionnav` slot.
     */
    private static function sectionnav(string $active): string {
        $syscontext = \core\context\system::instance();
        $canconfig = has_capability('moodle/site:config', $syscontext);

        // Admins get the unified cross-plugin tutor navigation (when the block renders
        // it); non-admins (e.g. a teacher on the token page) fall through to an
        // MCP-scoped menu with no admin links.
        $tutorshell = self::tutor_shell_class();
        if ($canconfig && $tutorshell !== null) {
            $nav = $tutorshell::sectionnav(self::ACTIVE_ELEDIAMCP);
            if (trim($nav) !== '') {
                return $nav;
            }
        }

        $tutorcomponent = self::tutor_component();
        $items = [];

        if ($canconfig && $tutorcomponent !== null) {
            $items = [
                [
                    'key' => 'configuration',
                    'icon' => 'fa-th-large',
                    'label' => get_string('nav_configuration', $tutorcomponent),
                    'url' => new moodle_url(self::tutor_path('/configuration.php')),
                ],
                [
                    'key' => 'settings',
                    'icon' => 'fa-sliders',
                    'label' => get_string('configuration_admin_settings_title', $tutorcomponent),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'blocksetting' . substr($tutorcomponent, 6)]),
                ],
                [
                    'key' => 'tutors',
                    'icon' => 'fa-paint-brush',
                    'label' => get_string('nav_tutors', $tutorcomponent),
                    'url' => new moodle_url(self::tutor_path('/manage_tutors.php')),
                ],
                [
                    'key' => 'preview',
                    'icon' => 'fa-comments',
                    'label' => get_string('nav_preview', $tutorcomponent),
                    'url' => new moodle_url(self::tutor_path('/view.php')),
                ],
            ];

            $integrations = [
                'local_literag' => [
                    'key' => 'literag',
                    'icon' => 'fa-database',
                    'label' => get_string('nav_literag', $tutorcomponent),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'local_literag']),
                ],
                'local_ragingest' => [
                    'key' => 'ragingest',
                    'icon' => 'fa-upload',
                    'label' => get_string('nav_ragingest', $tutorcomponent),
                    'url' => new moodle_url('/admin/settings.php', ['section' => 'local_ragingest_settings']),
                ],
            ];
            foreach ($integrations as $plugin => $item) {
                if (\core_component::get_plugin_directory(...explode('_', $plugin, 2)) !== null) {
                    $items[] = $item;
                }
            }
        }

        if ($canconfig) {
            $items[] = [
                'key' => self::ACTIVE_ELEDIAMCP,
                'icon' => 'fa-plug',
                'label' => $tutorcomponent !== null
                    ? get_string('nav_elediamcp', $tutorcomponent)
                    : get_string('pluginname', 'webservice_elediamcp'),
                'url' => new moodle_url('/webservice/elediamcp/configuration.php'),
            ];
        }

        // The viewer's own MCP token preferences — anyone who may manage tokens
        // (so the token page keeps a usable menu instead of an empty/admin-only one).
        if (has_capability('webservice/elediamcp:managetokens', $syscontext)) {
            $items[] = [
                'key' => self::ACTIVE_TOKENS,
                'icon' => 'fa-key',
                'label' => get_string('tokens_heading', 'webservice_elediamcp'),
                'url' => new moodle_url('/webservice/elediamcp/token/index.php'),
            ];
        }

        if (empty($items)) {
            return '';
        }

        $html = html_writer::start_tag('nav', [
            'class' => 'lh-plugin-section-nav',
            'aria-label' => $tutorcomponent !== null
                ? get_string('nav_label', $tutorcomponent)
                : get_string('pluginname', 'webservice_elediamcp'),
        ]);

        foreach ($items as $item) {
            $attrs = [
                'class' => 'lh-plugin-section-nav__item',
                'href' => $item['url']->out(false),
            ];
            if ($item['key'] === $active) {
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
     * Resolve the optional eLeDia.ai Tutor shell page class.
     *
     * @return string|null Fully qualified class name, or null when unavailable.
     */
    private static function tutor_page_class(): ?string {
        foreach (self::TUTOR_PAGE_CLASSES as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Resolve the optional eLeDia.ai Tutor shell helper class.
     *
     * @return string|null Fully qualified class name, or null when unavailable.
     */
    private static function tutor_plugin_shell_class(): ?string {
        foreach (self::TUTOR_PLUGIN_SHELL_CLASSES as $class) {
            if (class_exists($class)) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Resolve the optional eLeDia.ai Tutor shell class.
     *
     * @return string|null Fully qualified class name, or null when unavailable.
     */
    private static function tutor_shell_class(): ?string {
        foreach (self::TUTOR_SHELL_CLASSES as $class) {
            if (class_exists($class) && method_exists($class, 'sectionnav')) {
                return $class;
            }
        }
        return null;
    }

    /**
     * Resolve the optional eLeDia.ai Tutor language component.
     *
     * @return string|null Frankenstyle component name, or null when unavailable.
     */
    private static function tutor_component(): ?string {
        foreach (self::TUTOR_COMPONENTS as $component) {
            [$type, $name] = explode('_', $component, 2);
            if (\core_component::get_plugin_directory($type, $name) !== null) {
                return $component;
            }
        }
        return null;
    }

    /**
     * Build a URL path into the installed eLeDia.ai Tutor block.
     *
     * @param string $script Script path inside the block directory.
     * @return string Moodle URL path.
     */
    private static function tutor_path(string $script): string {
        $component = self::tutor_component();
        $blockname = $component !== null ? substr($component, 6) : 'eledia_aitutor';
        return '/blocks/' . $blockname . $script;
    }

    /**
     * Close the content area and shell.
     */
    public static function close(): void {
        if (self::$usespluginshell) {
            $pluginpage = self::tutor_page_class();
            $pluginshell = self::tutor_plugin_shell_class();
            if ($pluginpage === null || $pluginshell === null) {
                self::$usespluginshell = false;
                echo html_writer::end_div();
                return;
            }
            $pluginshell::content_close();
            $pluginpage::close();
            self::$usespluginshell = false;
            return;
        }

        echo html_writer::end_div();
    }
}
