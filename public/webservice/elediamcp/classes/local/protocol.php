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

namespace webservice_elediamcp\local;

/**
 * MCP protocol version negotiation helper.
 *
 * Holds the list of versions implemented by this server, ordered newest first,
 * and exposes helpers used during the initialize handshake and on every
 * subsequent request via the MCP-Protocol-Version header.
 *
 * Supported versions:
 * - 2025-11-25 (current as of release): full feature set including tool annotations.
 * - 2025-06-18 (intermediate): tool annotations, structured content, no envelope.
 * - 2025-03-26 (legacy): retained because existing clients (e.g. Langflow) wrap
 *   structuredContent in a {"result": ...} envelope.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class protocol {
    /** @var string Latest specification implemented by this server. */
    public const LATEST = '2025-11-25';

    /** @var string Legacy version retained for backwards compatibility. */
    public const LEGACY = '2025-03-26';

    /**
     * @var string[] Supported protocol versions, newest first.
     */
    public const SUPPORTED = [
        '2025-11-25',
        '2025-06-18',
        '2025-03-26',
    ];

    /**
     * Negotiate a protocol version from the client's requested value.
     *
     * Mirrors the MCP rule: if the requested version is supported, use it;
     * otherwise respond with the latest version we implement and let the
     * client decide whether to continue.
     *
     * @param string|null $requested Version requested by the client.
     * @return string Negotiated version.
     */
    public static function negotiate(?string $requested): string {
        if ($requested !== null && in_array($requested, self::SUPPORTED, true)) {
            return $requested;
        }
        return self::LATEST;
    }

    /**
     * Determine whether the given version is supported.
     *
     * @param string $version Version identifier.
     * @return bool
     */
    public static function is_supported(string $version): bool {
        return in_array($version, self::SUPPORTED, true);
    }

    /**
     * Determine whether the given version expects structuredContent to be
     * wrapped in a {"result": ...} envelope.
     *
     * The original 2025-03-26 specification was ambiguous and several clients
     * (including Langflow up to 1.0) wrap the value. Newer versions consume
     * the canonical shape directly.
     *
     * @param string $version Version identifier.
     * @return bool
     */
    public static function uses_result_envelope(string $version): bool {
        return $version === self::LEGACY;
    }

    /**
     * Determine whether the given version supports rich tool annotations.
     *
     * @param string $version Version identifier.
     * @return bool
     */
    public static function supports_annotations(string $version): bool {
        return $version !== self::LEGACY;
    }

    /**
     * Determine whether the given version supports the title field on tools.
     *
     * @param string $version Version identifier.
     * @return bool
     */
    public static function supports_tool_title(string $version): bool {
        return $version !== self::LEGACY;
    }
}
