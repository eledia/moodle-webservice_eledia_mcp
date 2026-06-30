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

use context_system;
use core_external\util as external_util;
use core_user;
use moodle_exception;
use stdClass;
use webservice;
use webservice_elediamcp\event\token_created;
use webservice_elediamcp\event\token_revoked;

/**
 * Lifecycle manager for MCP web service tokens.
 *
 * Tokens are scoped to a Moodle user and a configured MCP external service,
 * support expiry, are revocable, and are auditable. Authentication is delegated
 * to Moodle's core external_tokens machinery; this class owns the MCP-specific
 * metadata row in {webservice_elediamcp_token}, which survives revocation (and the
 * deletion of the backing core token) so the audit trail is preserved.
 *
 * The full token value is returned exactly once, by {@see self::create_token()}.
 * Every other read path returns metadata only.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_manager {
    /** @var string Shortname of the default MCP external service. */
    public const DEFAULT_SERVICE_SHORTNAME = 'elediamcp';

    /** @var string Human-readable name of the default MCP external service. */
    public const DEFAULT_SERVICE_NAME = 'MCP Service';

    /** @var string Token is live and usable. */
    public const STATUS_ACTIVE = 'active';

    /** @var string Token has been revoked. */
    public const STATUS_REVOKED = 'revoked';

    /** @var string Token has passed its expiry date. */
    public const STATUS_EXPIRED = 'expired';

    /**
     * Read the admin-configured set of MCP external service ids.
     *
     * Token creation is restricted to these services. When the setting is empty,
     * no service is treated as an MCP service and token creation is refused.
     *
     * @return int[] Configured external service ids.
     */
    public static function get_configured_service_ids(): array {
        $raw = (string) get_config('webservice_elediamcp', 'services');
        if ($raw === '') {
            return [];
        }
        $ids = array_filter(array_map('intval', explode(',', $raw)));
        return array_values(array_unique($ids));
    }

    /**
     * Whether the given external service is a configured MCP service.
     *
     * @param int $serviceid External service id.
     * @return bool
     */
    public static function is_mcp_service(int $serviceid): bool {
        return in_array($serviceid, self::get_configured_service_ids(), true);
    }

    /**
     * Return the enabled, configured MCP external services as records.
     *
     * @return stdClass[] Keyed by service id.
     */
    public static function get_mcp_services(): array {
        global $DB;

        $ids = self::get_configured_service_ids();
        if (empty($ids)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $records = $DB->get_records_select(
            'external_services',
            "id $insql AND enabled = 1",
            $params,
            'name ASC'
        );
        return $records;
    }

    /**
     * Create or repair the default MCP external service and activate the protocol.
     *
     * This is intentionally conservative: it creates a dedicated service for MCP
     * token issuance and does not assign raw external functions to that service.
     * The curated MCP tools are served by the MCP endpoint itself.
     *
     * @return int The configured external service id.
     */
    public static function ensure_default_service_configured(): int {
        global $CFG, $DB;

        set_config('enablewebservices', 1);

        $protocols = empty($CFG->webserviceprotocols) ? [] : explode(',', (string) $CFG->webserviceprotocols);
        $protocols = array_values(array_unique(array_filter(array_map('trim', $protocols))));
        if (!in_array('elediamcp', $protocols, true)) {
            $protocols[] = 'elediamcp';
            set_config('webserviceprotocols', implode(',', $protocols));
        }

        $now = time();
        $service = $DB->get_record('external_services', ['shortname' => self::DEFAULT_SERVICE_SHORTNAME]);
        if ($service) {
            $service->name = self::DEFAULT_SERVICE_NAME;
            $service->enabled = 1;
            $service->requiredcapability = 'webservice/elediamcp:use';
            $service->restrictedusers = 0;
            $service->component = 'webservice_elediamcp';
            $service->timemodified = $now;
            $service->downloadfiles = 0;
            $service->uploadfiles = 0;
            $DB->update_record('external_services', $service);
            $serviceid = (int) $service->id;
        } else {
            $serviceid = (int) $DB->insert_record('external_services', (object) [
                'name' => self::DEFAULT_SERVICE_NAME,
                'enabled' => 1,
                'requiredcapability' => 'webservice/elediamcp:use',
                'restrictedusers' => 0,
                'component' => 'webservice_elediamcp',
                'timecreated' => $now,
                'timemodified' => $now,
                'shortname' => self::DEFAULT_SERVICE_SHORTNAME,
                'downloadfiles' => 0,
                'uploadfiles' => 0,
            ]);
        }

        $configured = self::get_configured_service_ids();
        $configured[] = $serviceid;
        $configured = array_values(array_unique(array_filter(array_map('intval', $configured))));
        set_config('services', implode(',', $configured), 'webservice_elediamcp');

        return $serviceid;
    }

    /**
     * Create a user- and service-scoped MCP token.
     *
     * Validation enforced here (applies to every caller, UI or internal API):
     *  - the service must be a configured, enabled MCP service;
     *  - the target user must be a real, active account;
     *  - the user must satisfy the service's required capability and, for
     *    restricted services, be an authorised user;
     *  - the expiry, if given, must be in the future.
     *
     * The returned object exposes the plain token value as {@see stdClass::$token}.
     * This is the only time the value is available — callers must surface it to
     * the user immediately and never persist it.
     *
     * @param int $userid Owner the token authenticates as.
     * @param int $serviceid Configured MCP external service id.
     * @param string $label Human-readable token label.
     * @param int $validuntil Expiry timestamp, or 0 for no expiry.
     * @param string|null $component Frankenstyle component when created by a first-party plugin, else null.
     * @param int|null $creatorid Acting user; defaults to the current $USER.
     * @return stdClass {token: string, record: stdClass}
     * @throws moodle_exception On any validation failure.
     */
    public static function create_token(
        int $userid,
        int $serviceid,
        string $label,
        int $validuntil = 0,
        ?string $component = null,
        ?int $creatorid = null
    ): stdClass {
        global $DB, $USER;

        $label = trim($label);
        if ($label === '') {
            throw new moodle_exception('error_label_required', 'webservice_elediamcp');
        }

        if (!self::is_mcp_service($serviceid)) {
            throw new moodle_exception('error_service_not_mcp', 'webservice_elediamcp');
        }

        $service = $DB->get_record('external_services', ['id' => $serviceid], '*', MUST_EXIST);
        if (empty($service->enabled)) {
            throw new moodle_exception('error_service_disabled', 'webservice_elediamcp');
        }

        $user = core_user::get_user($userid, '*', MUST_EXIST);
        core_user::require_active_user($user);

        if ($validuntil > 0 && $validuntil < time()) {
            throw new moodle_exception('error_expiry_in_past', 'webservice_elediamcp');
        }

        // Respect service membership for restricted services.
        if (!empty($service->restrictedusers)) {
            global $CFG;
            require_once($CFG->dirroot . '/webservice/lib.php');
            $manager = new webservice();
            if (empty($manager->get_ws_authorised_user($serviceid, $userid))) {
                throw new moodle_exception('usernotallowed', 'webservice', '', $service->name);
            }
        }

        // Delegate token generation to core. This also enforces the service's
        // requiredcapability against the target user and throws on failure.
        $plaintoken = external_util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $userid,
            context_system::instance(),
            $validuntil,
            '',
            $label
        );

        $coretoken = $DB->get_record('external_tokens', ['token' => $plaintoken], '*', MUST_EXIST);

        $record = (object) [
            'externaltokenid' => $coretoken->id,
            'tokenhash' => hash('sha256', $plaintoken),
            'userid' => $userid,
            'externalserviceid' => $serviceid,
            'name' => $label,
            'creatorid' => $creatorid ?? (int) $USER->id,
            'component' => $component,
            'validuntil' => $validuntil ?: null,
            'timecreated' => time(),
            'lastaccess' => null,
            'revoked' => 0,
            'timerevoked' => null,
            'revokedby' => null,
        ];
        $record->id = $DB->insert_record('webservice_elediamcp_token', $record);

        token_created::create([
            'context' => context_system::instance(),
            'objectid' => $record->id,
            'relateduserid' => $userid,
            'other' => [
                'service' => $service->name,
                'label' => $label,
                'component' => $component,
            ],
        ])->trigger();

        return (object) [
            'token' => $plaintoken,
            'record' => $record,
        ];
    }

    /**
     * Revoke a token by its MCP metadata id.
     *
     * Deletes the backing core token (so it can no longer authenticate) and flags
     * the metadata row revoked, snapshotting the last-used timestamp so it remains
     * visible afterwards. Revoking an already-revoked token is a no-op.
     *
     * @param int $tokenid {webservice_elediamcp_token}.id
     * @param int|null $revokedby Acting user, or null when revoked programmatically.
     * @return void
     * @throws moodle_exception When the token does not exist.
     */
    public static function revoke_token(int $tokenid, ?int $revokedby = null): void {
        global $DB;

        $record = $DB->get_record('webservice_elediamcp_token', ['id' => $tokenid], '*', MUST_EXIST);
        if (!empty($record->revoked)) {
            return;
        }

        $service = $DB->get_record('external_services', ['id' => $record->externalserviceid]);

        // Snapshot the live last-access before the core row goes away.
        $lastaccess = $record->lastaccess;
        if (!empty($record->externaltokenid)) {
            $coretoken = $DB->get_record('external_tokens', ['id' => $record->externaltokenid]);
            if ($coretoken) {
                if (!empty($coretoken->lastaccess)) {
                    $lastaccess = (int) $coretoken->lastaccess;
                }
                $DB->delete_records('external_tokens', ['id' => $coretoken->id]);
            }
        }

        $DB->update_record('webservice_elediamcp_token', (object) [
            'id' => $record->id,
            'externaltokenid' => null,
            'lastaccess' => $lastaccess,
            'revoked' => 1,
            'timerevoked' => time(),
            'revokedby' => $revokedby,
        ]);

        token_revoked::create([
            'context' => context_system::instance(),
            'objectid' => $record->id,
            'relateduserid' => $record->userid,
            'other' => [
                'service' => $service->name ?? '',
                'label' => $record->name,
            ],
        ])->trigger();
    }

    /**
     * Revoke every active token a user holds for a given service.
     *
     * @param int $userid Token owner.
     * @param int $serviceid External service id.
     * @param int|null $revokedby Acting user, or null when revoked programmatically.
     * @return int Number of tokens revoked.
     */
    public static function revoke_user_service_tokens(int $userid, int $serviceid, ?int $revokedby = null): int {
        global $DB;

        $tokens = $DB->get_records('webservice_elediamcp_token', [
            'userid' => $userid,
            'externalserviceid' => $serviceid,
            'revoked' => 0,
        ]);
        foreach ($tokens as $token) {
            self::revoke_token((int) $token->id, $revokedby);
        }
        return count($tokens);
    }

    /**
     * Delete the audit records of long-revoked tokens.
     *
     * Connector-provisioned tokens are re-minted regularly (each mint revokes the
     * previous one), so revoked metadata rows accumulate. This prunes rows that
     * have been revoked for longer than the retention window. Only metadata is
     * removed; the backing core token was already deleted at revocation, and
     * active tokens are never touched.
     *
     * @param int $retentiondays Days to keep a revoked record. 0 (or less) disables pruning.
     * @return int Number of records deleted.
     */
    public static function prune_revoked_tokens(int $retentiondays): int {
        global $DB;

        if ($retentiondays <= 0) {
            return 0;
        }

        $cutoff = time() - ($retentiondays * DAYSECS);
        // Fall back to the creation time when a revoked row has no revocation
        // timestamp, so legacy rows are still eligible once old enough.
        $select = 'revoked = 1 AND COALESCE(timerevoked, timecreated) < :cutoff';
        $params = ['cutoff' => $cutoff];

        $count = $DB->count_records_select('webservice_elediamcp_token', $select, $params);
        if ($count > 0) {
            $DB->delete_records_select('webservice_elediamcp_token', $select, $params);
        }
        return $count;
    }

    /**
     * Fetch a single token metadata record (no secret).
     *
     * @param int $tokenid {webservice_elediamcp_token}.id
     * @return stdClass|null
     */
    public static function get_token(int $tokenid): ?stdClass {
        global $DB;
        $record = $DB->get_record('webservice_elediamcp_token', ['id' => $tokenid]);
        return $record ?: null;
    }

    /**
     * List a user's tokens as display-ready metadata rows.
     *
     * Each row carries the service name/shortname, a live last-access timestamp
     * (read from the backing core token while the token is active) and a computed
     * status. The token value is never included.
     *
     * @param int $userid Token owner.
     * @param bool $includerevoked Whether to include revoked tokens.
     * @param bool $selfserviceonly Whether to restrict to self-service tokens only.
     * @return stdClass[] Ordered newest first.
     */
    public static function get_user_tokens(
        int $userid,
        bool $includerevoked = true,
        bool $selfserviceonly = false
    ): array {
        global $DB;

        $where = 'mt.userid = :userid';
        $params = ['userid' => $userid];
        if (!$includerevoked) {
            $where .= ' AND mt.revoked = 0';
        }
        if ($selfserviceonly) {
            // Self-service tokens have no owning component; programmatic tokens
            // provisioned by a first-party plugin (e.g. the eLeDia.ai Tutor
            // connector) are managed automatically and are hidden from the
            // user's personal token page.
            $where .= ' AND mt.component IS NULL';
        }

        $sql = "SELECT mt.*,
                       es.name AS servicename,
                       es.shortname AS serviceshortname,
                       et.lastaccess AS livelastaccess,
                       et.validuntil AS livevaliduntil
                  FROM {webservice_elediamcp_token} mt
             LEFT JOIN {external_services} es ON es.id = mt.externalserviceid
             LEFT JOIN {external_tokens} et ON et.id = mt.externaltokenid
                 WHERE $where
              ORDER BY mt.timecreated DESC, mt.id DESC";

        $rows = $DB->get_records_sql($sql, $params);
        foreach ($rows as $row) {
            $row->validuntil = !empty($row->livevaliduntil) ? (int) $row->livevaliduntil : $row->validuntil;
            $row->lastaccess = !empty($row->livelastaccess) ? (int) $row->livelastaccess : $row->lastaccess;
            $row->status = self::compute_status($row);
            unset($row->livelastaccess, $row->livevaliduntil);
        }
        return array_values($rows);
    }

    /**
     * Compute the effective status of a token record.
     *
     * @param stdClass $record A {webservice_elediamcp_token} row.
     * @return string One of the STATUS_* constants.
     */
    public static function compute_status(stdClass $record): string {
        if (!empty($record->revoked)) {
            return self::STATUS_REVOKED;
        }
        if (!empty($record->validuntil) && (int) $record->validuntil < time()) {
            return self::STATUS_EXPIRED;
        }
        return self::STATUS_ACTIVE;
    }
}
