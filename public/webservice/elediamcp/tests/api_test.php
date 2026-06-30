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

use PHPUnit\Framework\Attributes\CoversClass;
use advanced_testcase;
use moodle_exception;
use webservice_elediamcp\local\token_manager;

/**
 * Tests for the MCP internal token API facade.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\webservice_elediamcp\api::class)]
final class api_test extends advanced_testcase {
    /**
     * Reset state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Create and configure an MCP external service.
     *
     * @return int The service id.
     */
    protected function create_mcp_service(): int {
        global $DB;
        $serviceid = (int) $DB->insert_record('external_services', (object) [
            'name' => 'API test service',
            'enabled' => 1,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'mcp_api_test',
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ]);
        set_config('services', (string) $serviceid, 'webservice_elediamcp');
        return $serviceid;
    }

    /**
     * A valid first-party component can provision a token, which is stamped with
     * that component for later attribution.
     */
    public function test_create_token_records_component(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $user = $this->getDataGenerator()->create_user();

        $result = api::create_token('core', $user->id, $serviceid, 'Provisioned');

        $this->assertNotEmpty($result->token);
        $record = token_manager::get_token((int) $result->record->id);
        $this->assertEquals('core', $record->component);
        $this->assertEquals($user->id, $record->userid);
    }

    /**
     * An unknown component name is rejected — the trust boundary of the API.
     */
    public function test_create_token_rejects_unknown_component(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(moodle_exception::class);
        api::create_token('local_doesnotexist', $user->id, $serviceid, 'Bad');
    }

    /**
     * A component may revoke only the tokens it provisioned.
     */
    public function test_revoke_token_enforces_component_ownership(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $user = $this->getDataGenerator()->create_user();

        // A self-service token (no component) cannot be revoked through the API.
        $selfservice = token_manager::create_token($user->id, $serviceid, 'Self-service');

        $this->expectException(moodle_exception::class);
        api::revoke_token('core', (int) $selfservice->record->id);
    }

    /**
     * A component can revoke its own token.
     */
    public function test_revoke_token_owned(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $user = $this->getDataGenerator()->create_user();

        $result = api::create_token('core', $user->id, $serviceid, 'Owned');
        api::revoke_token('core', (int) $result->record->id);

        $record = token_manager::get_token((int) $result->record->id);
        $this->assertEquals(1, $record->revoked);
    }

    /**
     * Bulk component revocation only affects that component's tokens.
     */
    public function test_revoke_user_service_tokens_scoped_to_component(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $user = $this->getDataGenerator()->create_user();

        $componenttoken = api::create_token('core', $user->id, $serviceid, 'Component');
        $selftoken = token_manager::create_token($user->id, $serviceid, 'Self');

        $count = api::revoke_user_service_tokens('core', $user->id, $serviceid);
        $this->assertEquals(1, $count);

        $this->assertEquals(1, token_manager::get_token((int) $componenttoken->record->id)->revoked);
        $this->assertEquals(0, token_manager::get_token((int) $selftoken->record->id)->revoked);
    }

    /**
     * get_services exposes the configured MCP services.
     */
    public function test_get_services(): void {
        $this->setAdminUser();
        $serviceid = $this->create_mcp_service();
        $services = api::get_services();
        $this->assertArrayHasKey($serviceid, $services);
    }
}
