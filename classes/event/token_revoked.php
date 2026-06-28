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

namespace webservice_elediamcp\event;

use core\event\base;

/**
 * Event fired when an MCP token is revoked.
 *
 * Revocation deletes the backing core token so it can no longer authenticate,
 * while the MCP metadata row is retained (flagged revoked) for audit. The raw
 * token value is never included.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_revoked extends base {
    /**
     * Initialise event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        $this->data['objecttable'] = 'webservice_elediamcp_token';
    }

    /**
     * Return the event name.
     *
     * @return string Localised event name.
     */
    public static function get_name(): string {
        return get_string('event_token_revoked', 'webservice_elediamcp');
    }

    /**
     * Return a human-readable description of the event.
     *
     * @return string
     */
    public function get_description(): string {
        $a = (object) [
            'userid' => $this->userid,
            'relateduserid' => $this->relateduserid,
            'service' => $this->other['service'] ?? '',
            'label' => $this->other['label'] ?? '',
        ];
        return get_string('event_token_revoked_desc', 'webservice_elediamcp', $a);
    }

    /**
     * Validate the event data before dispatch.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->objectid)) {
            throw new \coding_exception('token_revoked event requires objectid');
        }
        if (empty($this->relateduserid)) {
            throw new \coding_exception('token_revoked event requires relateduserid (the token owner)');
        }
    }
}
