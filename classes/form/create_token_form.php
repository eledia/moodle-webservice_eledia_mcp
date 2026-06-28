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

/**
 * Self-service form for creating an MCP token.
 *
 * Expects a 'services' custom data entry mapping service id => display name. The
 * caller is responsible for restricting that list to configured MCP services the
 * user is permitted to use.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class create_token_form extends moodleform {
    /**
     * Define the form.
     *
     * @return void
     */
    protected function definition(): void {
        $mform = $this->_form;
        $services = $this->_customdata['services'] ?? [];

        $mform->addElement('text', 'name', get_string('token_label', 'webservice_elediamcp'), ['maxlength' => 255, 'size' => 48]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');
        $mform->addHelpButton('name', 'token_label', 'webservice_elediamcp');

        $mform->addElement('select', 'service', get_string('token_service', 'webservice_elediamcp'), $services);
        $mform->addRule('service', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('service', 'token_service', 'webservice_elediamcp');

        $mform->addElement(
            'date_selector',
            'validuntil',
            get_string('token_validuntil', 'webservice_elediamcp'),
            ['optional' => true]
        );
        $mform->addHelpButton('validuntil', 'token_validuntil', 'webservice_elediamcp');

        $this->add_action_buttons(true, get_string('token_create', 'webservice_elediamcp'));
    }

    /**
     * Validate the submitted data.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);

        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors['name'] = get_string('error_label_required', 'webservice_elediamcp');
        }
        if (!empty($data['validuntil']) && $data['validuntil'] < time()) {
            $errors['validuntil'] = get_string('error_expiry_in_past', 'webservice_elediamcp');
        }

        return $errors;
    }
}
