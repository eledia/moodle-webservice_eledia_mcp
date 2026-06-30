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
use webservice_elediamcp\local\protocol;

/**
 * Tests for the MCP protocol negotiation helper.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[CoversClass(\webservice_elediamcp\local\protocol::class)]
final class protocol_test extends advanced_testcase {
    /**
     * Latest version is the newest in the SUPPORTED list.
     */
    public function test_latest_is_in_supported(): void {
        $this->assertContains(protocol::LATEST, protocol::SUPPORTED);
        $this->assertSame(protocol::LATEST, protocol::SUPPORTED[0]);
    }

    /**
     * Negotiation returns the requested version when it is supported.
     */
    public function test_negotiate_returns_supported_version(): void {
        $this->assertSame('2025-11-25', protocol::negotiate('2025-11-25'));
        $this->assertSame('2025-06-18', protocol::negotiate('2025-06-18'));
        $this->assertSame('2025-03-26', protocol::negotiate('2025-03-26'));
    }

    /**
     * Negotiation falls back to LATEST for unknown or missing versions.
     */
    public function test_negotiate_falls_back_to_latest(): void {
        $this->assertSame(protocol::LATEST, protocol::negotiate('1999-01-01'));
        $this->assertSame(protocol::LATEST, protocol::negotiate(null));
        $this->assertSame(protocol::LATEST, protocol::negotiate(''));
    }

    /**
     * is_supported recognises the SUPPORTED set.
     */
    public function test_is_supported(): void {
        $this->assertTrue(protocol::is_supported('2025-11-25'));
        $this->assertTrue(protocol::is_supported('2025-03-26'));
        $this->assertFalse(protocol::is_supported('2024-01-01'));
        $this->assertFalse(protocol::is_supported('garbage'));
    }

    /**
     * Only the legacy version uses the {result: ...} envelope.
     */
    public function test_uses_result_envelope(): void {
        $this->assertTrue(protocol::uses_result_envelope(protocol::LEGACY));
        $this->assertFalse(protocol::uses_result_envelope('2025-11-25'));
        $this->assertFalse(protocol::uses_result_envelope('2025-06-18'));
    }

    /**
     * Annotations and titles are supported on non-legacy versions only.
     */
    public function test_supports_annotations_and_title(): void {
        $this->assertFalse(protocol::supports_annotations(protocol::LEGACY));
        $this->assertTrue(protocol::supports_annotations('2025-11-25'));
        $this->assertFalse(protocol::supports_tool_title(protocol::LEGACY));
        $this->assertTrue(protocol::supports_tool_title('2025-11-25'));
    }
}
