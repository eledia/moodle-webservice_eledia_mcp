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

namespace webservice_elediamcp\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Plugin-owned configuration form for the MCP web service.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class configuration_form extends moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $services = $this->_customdata['services'] ?? [];

        $mform->addElement('header', 'serviceshdr', get_string('configuration_services_heading', 'webservice_elediamcp'));
        $servicecount = max(4, min(12, count($services)));
        $serviceelement = $mform->addElement(
            'select',
            'services',
            get_string('setting_services', 'webservice_elediamcp'),
            $services,
            ['size' => $servicecount]
        );
        $serviceelement->setMultiple(true);
        $mform->addElement('static', 'services_desc', '', get_string('setting_services_desc', 'webservice_elediamcp'));

        $mform->addElement('text', 'token_retention_days',
            get_string('setting_token_retention_days', 'webservice_elediamcp'), ['size' => 8]);
        $mform->setType('token_retention_days', PARAM_INT);
        $mform->addElement('static', 'token_retention_days_desc', '',
            get_string('setting_token_retention_days_desc', 'webservice_elediamcp'));

        $mform->addElement('header', 'securityhdr', get_string('configuration_security_heading', 'webservice_elediamcp'));
        $mform->addElement('textarea', 'allowed_origins',
            get_string('setting_allowed_origins', 'webservice_elediamcp'), ['rows' => 5, 'cols' => 60]);
        $mform->setType('allowed_origins', PARAM_RAW);
        $mform->addElement('static', 'allowed_origins_desc', '',
            get_string('setting_allowed_origins_desc', 'webservice_elediamcp'));

        $mform->addElement('advcheckbox', 'allow_token_in_query',
            get_string('setting_allow_token_in_query', 'webservice_elediamcp'),
            get_string('setting_allow_token_in_query_desc', 'webservice_elediamcp'), null, [0, 1]);

        $mform->addElement('text', 'rate_limit_per_minute',
            get_string('setting_rate_limit_per_minute', 'webservice_elediamcp'), ['size' => 8]);
        $mform->setType('rate_limit_per_minute', PARAM_INT);
        $mform->addElement('static', 'rate_limit_per_minute_desc', '',
            get_string('setting_rate_limit_per_minute_desc', 'webservice_elediamcp'));

        $mform->addElement('text', 'rate_limit_per_hour',
            get_string('setting_rate_limit_per_hour', 'webservice_elediamcp'), ['size' => 8]);
        $mform->setType('rate_limit_per_hour', PARAM_INT);
        $mform->addElement('static', 'rate_limit_per_hour_desc', '',
            get_string('setting_rate_limit_per_hour_desc', 'webservice_elediamcp'));

        $mform->addElement('text', 'max_request_size',
            get_string('setting_max_request_size', 'webservice_elediamcp'), ['size' => 12]);
        $mform->setType('max_request_size', PARAM_INT);
        $mform->addElement('static', 'max_request_size_desc', '',
            get_string('setting_max_request_size_desc', 'webservice_elediamcp'));

        $mform->addElement('advcheckbox', 'emergency_disable',
            get_string('setting_emergency_disable', 'webservice_elediamcp'),
            get_string('setting_emergency_disable_desc', 'webservice_elediamcp'), null, [0, 1]);

        $mform->addElement('header', 'toolshdr', get_string('configuration_tools_heading', 'webservice_elediamcp'));
        $mform->addElement('advcheckbox', 'expose_raw_functions',
            get_string('setting_expose_raw_functions', 'webservice_elediamcp'),
            get_string('setting_expose_raw_functions_desc', 'webservice_elediamcp'), null, [0, 1]);

        $mform->addElement('text', 'tools_page_size',
            get_string('setting_tools_page_size', 'webservice_elediamcp'), ['size' => 8]);
        $mform->setType('tools_page_size', PARAM_INT);
        $mform->addElement('static', 'tools_page_size_desc', '',
            get_string('setting_tools_page_size_desc', 'webservice_elediamcp'));

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Validate numeric limits.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array<string, string>
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        foreach (['token_retention_days', 'rate_limit_per_minute', 'rate_limit_per_hour',
                'max_request_size', 'tools_page_size'] as $field) {
            if ((int) ($data[$field] ?? 0) < 0) {
                $errors[$field] = get_string('configuration_error_nonnegative', 'webservice_elediamcp');
            }
        }
        return $errors;
    }
}
