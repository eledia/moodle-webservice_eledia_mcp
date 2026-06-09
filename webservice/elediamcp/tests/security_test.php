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
use webservice_elediamcp\local\security;

/**
 * Tests for the MCP security helper.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\security
 */
final class security_test extends advanced_testcase {
    /**
     * Setup: ensure a clean cache state before each test.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest(true);
    }

    /**
     * Same-origin and missing-origin requests are allowed by default.
     */
    public function test_origin_same_origin_allowed(): void {
        global $CFG;
        $this->assertTrue(security::is_origin_allowed(null));
        $this->assertTrue(security::is_origin_allowed(''));
        $this->assertTrue(security::is_origin_allowed($CFG->wwwroot));
    }

    /**
     * Cross-origin requests are blocked unless they appear on the allow-list.
     */
    public function test_origin_cross_origin_requires_allow_list(): void {
        $this->assertFalse(security::is_origin_allowed('https://evil.example.com'));

        set_config('allowed_origins', "https://app.example.com\nhttps://other.example.com",
            'webservice_elediamcp');

        $this->assertTrue(security::is_origin_allowed('https://app.example.com'));
        $this->assertTrue(security::is_origin_allowed('https://other.example.com'));
        $this->assertFalse(security::is_origin_allowed('https://evil.example.com'));
    }

    /**
     * Wildcard "*" allows any cross-origin request.
     */
    public function test_origin_wildcard(): void {
        set_config('allowed_origins', '*', 'webservice_elediamcp');
        $this->assertTrue(security::is_origin_allowed('https://anywhere.example.org'));
        $this->assertSame(
            'https://anywhere.example.org',
            security::resolve_cors_origin('https://anywhere.example.org')
        );
    }

    /**
     * Rate limiting denies requests after the per-minute quota is exceeded.
     */
    public function test_rate_limit_per_minute_enforced(): void {
        set_config('rate_limit_per_minute', 3, 'webservice_elediamcp');
        set_config('rate_limit_per_hour', 999, 'webservice_elediamcp');

        $key = 'tok_' . bin2hex(random_bytes(8));
        for ($i = 1; $i <= 3; $i++) {
            $result = security::enforce_rate_limit($key);
            $this->assertTrue($result['allowed'], "Request {$i} should still be allowed.");
        }
        $blocked = security::enforce_rate_limit($key);
        $this->assertFalse($blocked['allowed']);
        $this->assertGreaterThan(0, $blocked['retry_after']);
    }

    /**
     * Configuration toggles take effect.
     */
    public function test_config_helpers(): void {
        set_config('allow_token_in_query', 0, 'webservice_elediamcp');
        $this->assertFalse(security::allow_token_in_query());
        set_config('allow_token_in_query', 1, 'webservice_elediamcp');
        $this->assertTrue(security::allow_token_in_query());

        set_config('expose_raw_functions', 0, 'webservice_elediamcp');
        $this->assertFalse(security::expose_raw_functions());
        set_config('expose_raw_functions', 1, 'webservice_elediamcp');
        $this->assertTrue(security::expose_raw_functions());

        set_config('emergency_disable', 1, 'webservice_elediamcp');
        $this->assertTrue(security::is_emergency_disabled());
        set_config('emergency_disable', 0, 'webservice_elediamcp');
        $this->assertFalse(security::is_emergency_disabled());
    }

    /**
     * The page size has a sane minimum and reads from config when set.
     */
    public function test_tools_page_size(): void {
        $this->assertGreaterThanOrEqual(1, security::tools_page_size());
        set_config('tools_page_size', 25, 'webservice_elediamcp');
        $this->assertSame(25, security::tools_page_size());
        set_config('tools_page_size', 0, 'webservice_elediamcp');
        $this->assertSame(1, security::tools_page_size());
    }
}
