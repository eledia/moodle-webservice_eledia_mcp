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

// phpcs:ignore moodle.Files.RequireLogin.Missing
require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat step definitions for the MCP web service plugin.
 *
 * @package     webservice_elediamcp
 * @category    test
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_webservice_elediamcp extends behat_base {
    /**
     * Register an existing external service (by shortname) as the configured MCP
     * token service, so the self-service UI and internal API may use it.
     *
     * @Given /^the MCP token service is "(?P<shortname_string>[^"]*)"$/
     * @param string $shortname The external service shortname.
     */
    public function the_mcp_token_service_is(string $shortname): void {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => $shortname], 'id', MUST_EXIST);
        set_config('services', (string) $service->id, 'webservice_elediamcp');
    }
}
