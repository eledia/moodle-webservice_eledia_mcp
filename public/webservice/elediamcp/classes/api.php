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

namespace webservice_elediamcp;

use core_component;
use moodle_exception;
use stdClass;
use webservice_elediamcp\local\token_manager;

/**
 * Internal Moodle PHP API for provisioning MCP tokens.
 *
 * Trusted first-party Moodle plugins use this facade to create and revoke
 * user-scoped MCP tokens for specific, admin-configured MCP external services —
 * for example to hand a learner's AI tutor a ready-to-use credential without
 * sending them through the self-service UI.
 *
 * Every call is attributed to the calling component (a frankenstyle name that
 * must resolve to installed Moodle code) so that programmatic tokens are
 * auditable and so revocation is scoped to the component that owns them. Tokens
 * created here carry that component; self-service tokens created through the UI
 * have no component and are not affected by component-scoped revocation.
 *
 * Usage:
 * <code>
 * $result = \webservice_elediamcp\api::create_token('local_aitutor', $userid, $serviceid, 'AI tutor');
 * // Show $result->token to the user once, then discard it.
 * </code>
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class api {
    /**
     * Validate that the caller is a real installed Moodle component.
     *
     * This is the trust boundary for the internal API: only code that ships as
     * part of the Moodle tree (core or an installed plugin) can name itself.
     *
     * @param string $component Frankenstyle component name.
     * @return string The validated component name.
     * @throws moodle_exception When the component is unknown.
     */
    protected static function validate_component(string $component): string {
        $component = trim($component);
        if ($component === 'core' || core_component::get_component_directory($component) !== null) {
            return $component;
        }
        throw new moodle_exception('error_invalid_component', 'webservice_elediamcp', '', $component);
    }

    /**
     * Create a user-scoped MCP token on behalf of a first-party plugin.
     *
     * The returned object exposes the plain token value as {@see stdClass::$token}.
     * It is available only here — surface it to the user immediately and never
     * persist it. The accompanying {@see stdClass::$record} holds metadata only.
     *
     * @param string $component Frankenstyle name of the calling plugin.
     * @param int $userid Owner the token authenticates as.
     * @param int $serviceid Configured MCP external service id.
     * @param string $label Human-readable token label.
     * @param int $validuntil Expiry timestamp, or 0 for no expiry.
     * @return stdClass {token: string, record: stdClass}
     * @throws moodle_exception On validation failure (see {@see token_manager::create_token()}).
     */
    public static function create_token(
        string $component,
        int $userid,
        int $serviceid,
        string $label,
        int $validuntil = 0
    ): stdClass {
        $component = self::validate_component($component);
        return token_manager::create_token($userid, $serviceid, $label, $validuntil, $component, $userid);
    }

    /**
     * Revoke a single token previously created by the calling component.
     *
     * @param string $component Frankenstyle name of the calling plugin.
     * @param int $tokenid {webservice_elediamcp_token}.id
     * @return void
     * @throws moodle_exception When the token is unknown or owned by another component.
     */
    public static function revoke_token(string $component, int $tokenid): void {
        $component = self::validate_component($component);

        $record = token_manager::get_token($tokenid);
        if ($record === null) {
            throw new moodle_exception('error_token_not_found', 'webservice_elediamcp');
        }
        if ($record->component !== $component) {
            throw new moodle_exception('error_token_not_owned_by_component', 'webservice_elediamcp');
        }
        token_manager::revoke_token($tokenid, null);
    }

    /**
     * Revoke every active token the component provisioned for a user/service pair.
     *
     * @param string $component Frankenstyle name of the calling plugin.
     * @param int $userid Token owner.
     * @param int $serviceid External service id.
     * @return int Number of tokens revoked.
     * @throws moodle_exception When the component is unknown.
     */
    public static function revoke_user_service_tokens(string $component, int $userid, int $serviceid): int {
        global $DB;

        $component = self::validate_component($component);

        $tokens = $DB->get_records('webservice_elediamcp_token', [
            'userid' => $userid,
            'externalserviceid' => $serviceid,
            'component' => $component,
            'revoked' => 0,
        ]);
        foreach ($tokens as $token) {
            token_manager::revoke_token((int) $token->id, null);
        }
        return count($tokens);
    }

    /**
     * List a user's token metadata (no secrets).
     *
     * Intended for first-party plugins that surface a user's tokens in their own
     * UI. Returns the same display-ready rows as the self-service page.
     *
     * @param int $userid Token owner.
     * @param bool $includerevoked Whether to include revoked tokens.
     * @return stdClass[]
     */
    public static function get_user_tokens(int $userid, bool $includerevoked = true): array {
        return token_manager::get_user_tokens($userid, $includerevoked);
    }

    /**
     * Return the enabled, admin-configured MCP external services.
     *
     * @return stdClass[] Keyed by service id.
     */
    public static function get_services(): array {
        return token_manager::get_mcp_services();
    }
}
