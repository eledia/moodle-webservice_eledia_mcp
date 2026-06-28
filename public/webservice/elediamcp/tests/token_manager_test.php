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
use moodle_exception;
use webservice_elediamcp\local\token_manager;

/**
 * Tests for the MCP token lifecycle manager.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\token_manager
 */
final class token_manager_test extends advanced_testcase {
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
     * @param array $overrides Field overrides.
     * @return int The service id.
     */
    protected function create_service(array $overrides = []): int {
        global $DB;
        static $counter = 0;
        $counter++;
        $record = (object) array_merge([
            'name' => 'MCP test service ' . $counter,
            'enabled' => 1,
            'requiredcapability' => '',
            'restrictedusers' => 0,
            'component' => null,
            'timecreated' => time(),
            'timemodified' => time(),
            'shortname' => 'mcp_test_' . $counter,
            'downloadfiles' => 0,
            'uploadfiles' => 0,
        ], $overrides);
        return (int) $DB->insert_record('external_services', $record);
    }

    /**
     * Register a service as a configured MCP service.
     *
     * @param int ...$serviceids Service ids.
     * @return void
     */
    protected function configure_mcp(int ...$serviceids): void {
        set_config('services', implode(',', $serviceids), 'webservice_elediamcp');
    }

    /**
     * A token can be created for a configured MCP service, and the plaintext
     * value is returned exactly once while a metadata row and a backing core
     * token are persisted.
     */
    public function test_create_token_happy_path(): void {
        global $DB;
        $this->setAdminUser();

        $serviceid = $this->create_service();
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();

        $result = token_manager::create_token($user->id, $serviceid, 'My laptop');

        $this->assertNotEmpty($result->token);
        $this->assertIsString($result->token);

        // Metadata row persisted, secret not stored, hash matches.
        $record = $DB->get_record('webservice_elediamcp_token', ['id' => $result->record->id], '*', MUST_EXIST);
        $this->assertEquals($user->id, $record->userid);
        $this->assertEquals($serviceid, $record->externalserviceid);
        $this->assertEquals('My laptop', $record->name);
        $this->assertEquals(0, $record->revoked);
        $this->assertEquals(hash('sha256', $result->token), $record->tokenhash);
        $this->assertFalse(property_exists($record, 'token'));

        // Backing core token exists and authenticates this user/service.
        $core = $DB->get_record('external_tokens', ['id' => $record->externaltokenid], '*', MUST_EXIST);
        $this->assertEquals($result->token, $core->token);
        $this->assertEquals($user->id, $core->userid);
        $this->assertEquals($serviceid, $core->externalserviceid);

        $this->assertEquals(token_manager::STATUS_ACTIVE, token_manager::compute_status($record));
    }

    /**
     * Creating a token for a service that is not configured as an MCP service is
     * refused, even if the service exists and is enabled.
     */
    public function test_create_token_rejects_non_mcp_service(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service();
        // Deliberately not configured.
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(moodle_exception::class);
        token_manager::create_token($user->id, $serviceid, 'Nope');
    }

    /**
     * An expiry date in the past is rejected.
     */
    public function test_create_token_rejects_past_expiry(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service();
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(moodle_exception::class);
        token_manager::create_token($user->id, $serviceid, 'Expired', time() - HOURSECS);
    }

    /**
     * An empty label is rejected.
     */
    public function test_create_token_requires_label(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service();
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(moodle_exception::class);
        token_manager::create_token($user->id, $serviceid, '   ');
    }

    /**
     * Revoking a token deletes the backing core token (so it can no longer
     * authenticate) but keeps the metadata row, flagged revoked, for audit.
     */
    public function test_revoke_token(): void {
        global $DB;
        $this->setAdminUser();

        $serviceid = $this->create_service();
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();
        $result = token_manager::create_token($user->id, $serviceid, 'Revoke me');
        $coreid = $result->record->externaltokenid;

        // Simulate prior use so the last-access snapshot has something to keep.
        $DB->set_field('external_tokens', 'lastaccess', 1234567890, ['id' => $coreid]);

        token_manager::revoke_token((int) $result->record->id, $user->id);

        $this->assertFalse($DB->record_exists('external_tokens', ['id' => $coreid]));

        $record = $DB->get_record('webservice_elediamcp_token', ['id' => $result->record->id], '*', MUST_EXIST);
        $this->assertEquals(1, $record->revoked);
        $this->assertNull($record->externaltokenid);
        $this->assertEquals($user->id, $record->revokedby);
        $this->assertEquals(1234567890, $record->lastaccess);
        $this->assertNotEmpty($record->timerevoked);
        $this->assertEquals(token_manager::STATUS_REVOKED, token_manager::compute_status($record));
    }

    /**
     * Revoking an already-revoked token is a no-op (idempotent).
     */
    public function test_revoke_token_idempotent(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service();
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();
        $result = token_manager::create_token($user->id, $serviceid, 'Once');

        token_manager::revoke_token((int) $result->record->id);
        // Second call must not throw.
        token_manager::revoke_token((int) $result->record->id);
        $this->assertTrue(true);
    }

    /**
     * Bulk revocation clears every active token for a user/service pair.
     */
    public function test_revoke_user_service_tokens(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service();
        $other = $this->create_service();
        $this->configure_mcp($serviceid, $other);
        $user = $this->getDataGenerator()->create_user();

        token_manager::create_token($user->id, $serviceid, 'A');
        token_manager::create_token($user->id, $serviceid, 'B');
        $keep = token_manager::create_token($user->id, $other, 'Different service');

        $count = token_manager::revoke_user_service_tokens($user->id, $serviceid);
        $this->assertEquals(2, $count);

        // The token on the other service is untouched.
        $kept = token_manager::get_token((int) $keep->record->id);
        $this->assertEquals(0, $kept->revoked);
    }

    /**
     * The listing returns display-ready metadata, newest first, including the
     * live last-access timestamp and computed status, and never the secret.
     */
    public function test_get_user_tokens(): void {
        global $DB;
        $this->setAdminUser();
        $serviceid = $this->create_service(['name' => 'Listing service']);
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();

        $first = token_manager::create_token($user->id, $serviceid, 'First');
        $second = token_manager::create_token($user->id, $serviceid, 'Second');
        $DB->set_field(
            'external_tokens',
            'lastaccess',
            1700000000,
            ['id' => $second->record->externaltokenid]
        );

        $tokens = token_manager::get_user_tokens($user->id);
        $this->assertCount(2, $tokens);
        $this->assertEquals('Second', $tokens[0]->name);
        $this->assertEquals('Listing service', $tokens[0]->servicename);
        $this->assertEquals(1700000000, $tokens[0]->lastaccess);
        $this->assertEquals(token_manager::STATUS_ACTIVE, $tokens[0]->status);
        foreach ($tokens as $token) {
            $this->assertFalse(property_exists($token, 'token'));
        }
    }

    /**
     * Expiry in the future yields an active status; once passed it reads expired.
     */
    public function test_compute_status_expiry(): void {
        $active = (object) ['revoked' => 0, 'validuntil' => time() + DAYSECS];
        $this->assertEquals(token_manager::STATUS_ACTIVE, token_manager::compute_status($active));

        $expired = (object) ['revoked' => 0, 'validuntil' => time() - DAYSECS];
        $this->assertEquals(token_manager::STATUS_EXPIRED, token_manager::compute_status($expired));

        $revoked = (object) ['revoked' => 1, 'validuntil' => time() + DAYSECS];
        $this->assertEquals(token_manager::STATUS_REVOKED, token_manager::compute_status($revoked));
    }

    /**
     * Restricted services refuse tokens for users who are not authorised.
     */
    public function test_create_token_restricted_service_blocks_unauthorised_user(): void {
        $this->setAdminUser();
        $serviceid = $this->create_service(['restrictedusers' => 1]);
        $this->configure_mcp($serviceid);
        $user = $this->getDataGenerator()->create_user();

        $this->expectException(moodle_exception::class);
        token_manager::create_token($user->id, $serviceid, 'Restricted');
    }

    /**
     * get_mcp_services only returns enabled, configured services.
     */
    public function test_get_mcp_services_filters_disabled(): void {
        $enabled = $this->create_service(['enabled' => 1]);
        $disabled = $this->create_service(['enabled' => 0]);
        $this->configure_mcp($enabled, $disabled);

        $services = token_manager::get_mcp_services();
        $this->assertArrayHasKey($enabled, $services);
        $this->assertArrayNotHasKey($disabled, $services);
    }
}
