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
 * Event fired when an MCP tool that performs a write action is invoked.
 *
 * Emitted in addition to tool_invoked, so that audit consumers can filter on
 * a clearly defined "state-changing" event class for compliance reporting.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class write_performed extends base {
    /**
     * Initialise event data.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Return the event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_write_performed', 'webservice_elediamcp');
    }

    /**
     * Return a human-readable description.
     *
     * @return string
     */
    public function get_description(): string {
        $a = (object) [
            'userid' => $this->userid,
            'toolname' => $this->other['toolname'] ?? 'unknown',
        ];
        return get_string('event_write_performed_desc', 'webservice_elediamcp', $a);
    }

    /**
     * Validate event data.
     *
     * @return void
     */
    protected function validate_data(): void {
        parent::validate_data();
        if (empty($this->other['toolname'])) {
            throw new \coding_exception('write_performed event requires other.toolname');
        }
    }
}
