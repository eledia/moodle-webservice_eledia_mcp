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

namespace webservice_elediamcp;

use advanced_testcase;
use context_system;
use webservice_elediamcp\local\token_manager;
use webservice_elediamcp\privacy\provider;

/**
 * Tests for the MCP privacy provider.
 *
 * @package     webservice_elediamcp
 * @copyright   2026 eLeDia GmbH, Berlin
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\privacy\provider
 */
final class privacy_provider_test extends advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create an external service row for testing.
     *
     * @return int The service id.
     */
    protected function create_service(): int {
        global $DB;
        static $counter = 0;
        $counter++;

        return (int) $DB->insert_record('external_services', (object) [
            'name' => 'MCP privacy service ' . $counter,
            'enabled' => 1,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'mcp_privacy_' . $counter,
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);
    }

    /**
     * Privacy erasure removes plugin token metadata and its backing core token.
     */
    public function test_delete_data_for_all_users_in_context_removes_backing_core_tokens(): void {
        global $DB;
        $this->setAdminUser();

        $serviceid = $this->create_service();
        set_config('services', (string) $serviceid, 'webservice_elediamcp');
        $user = $this->getDataGenerator()->create_user();
        $token = token_manager::create_token($user->id, $serviceid, 'Privacy cleanup');
        $externaltokenid = (int) $token->record->externaltokenid;

        provider::delete_data_for_all_users_in_context(context_system::instance());

        $this->assertFalse($DB->record_exists('webservice_elediamcp_token', ['id' => $token->record->id]));
        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $externaltokenid]));
    }
}
