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

use cache;

/**
 * Security helper for the MCP web service.
 *
 * Centralises CORS / Origin validation, rate limiting, and configuration
 * lookups. Keeping these concerns outside the JSON-RPC server simplifies
 * unit testing and audit reviews.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class security {
    /** @var string Configuration component name. */
    public const CONFIG_COMPONENT = 'webservice_elediamcp';

    /**
     * Return a plugin configuration value with a default fallback.
     *
     * @param string $name Configuration setting name.
     * @param mixed $default Default value returned if the setting is unset.
     * @return mixed
     */
    public static function get_config(string $name, mixed $default = null): mixed {
        $value = get_config(self::CONFIG_COMPONENT, $name);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        return $value;
    }

    /**
     * Determine whether the MCP service is administratively disabled.
     *
     * @return bool
     */
    public static function is_emergency_disabled(): bool {
        return (int) self::get_config('emergency_disable', 0) === 1;
    }

    /**
     * Determine whether the token may be supplied through the wstoken query parameter.
     *
     * @return bool
     */
    public static function allow_token_in_query(): bool {
        return (int) self::get_config('allow_token_in_query', 0) === 1;
    }

    /**
     * Determine whether raw Moodle Web Service functions are exposed alongside AI tools.
     *
     * @return bool
     */
    public static function expose_raw_functions(): bool {
        return (int) self::get_config('expose_raw_functions', 0) === 1;
    }

    /**
     * Determine whether the MCP endpoint only accepts tokens that belong to a
     * configured MCP external service.
     *
     * Enabled by default so the admin-curated MCP service list is the access
     * boundary for the endpoint, not just for token issuance. Can be disabled for
     * transitional setups that present tokens minted for other web services.
     *
     * @return bool
     */
    public static function enforce_mcp_service(): bool {
        return (int) self::get_config('enforce_mcp_service', 1) === 1;
    }

    /**
     * Return the configured list of allowed CORS origins.
     *
     * @return string[] Lower-cased origin URLs ("*" for wildcard).
     */
    public static function allowed_origins(): array {
        $raw = (string) self::get_config('allowed_origins', '');
        $origins = preg_split('/[\r\n,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $origins = array_map(static fn(string $o): string => strtolower(trim($o)), $origins);
        return array_values(array_filter($origins, static fn(string $o): bool => $o !== ''));
    }

    /**
     * Determine the Origin header that should be reflected back to the client.
     *
     * @param string|null $origin The incoming Origin header value.
     * @return string|null The origin to reflect, or null if none allowed.
     */
    public static function resolve_cors_origin(?string $origin): ?string {
        if ($origin === null || $origin === '') {
            return null;
        }
        $allowed = self::allowed_origins();
        $normalised = strtolower(rtrim($origin, '/'));
        foreach ($allowed as $entry) {
            if ($entry === '*') {
                return $origin;
            }
            if ($entry === $normalised) {
                return $origin;
            }
        }
        return null;
    }

    /**
     * Determine whether credentialed CORS should be permitted for an origin.
     *
     * Wildcard CORS intentionally does not allow credentials, even though the
     * origin is reflected back to keep non-credentialed browser clients working.
     *
     * @param string|null $origin The incoming Origin header value.
     * @return bool True if Access-Control-Allow-Credentials may be emitted.
     */
    public static function cors_allows_credentials(?string $origin): bool {
        if ($origin === null || $origin === '') {
            return false;
        }
        $normalised = strtolower(rtrim($origin, '/'));
        foreach (self::allowed_origins() as $entry) {
            if ($entry === $normalised) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check whether the incoming Origin header satisfies site policy.
     *
     * Same-origin and missing Origin (e.g. server-to-server clients) are always
     * accepted. Cross-origin requests are only accepted if the origin appears
     * on the allow-list.
     *
     * @param string|null $origin Origin header.
     * @return bool True if the origin is acceptable.
     */
    public static function is_origin_allowed(?string $origin): bool {
        if ($origin === null || $origin === '') {
            // Server-to-server or curl: no Origin sent.
            return true;
        }
        global $CFG;
        $site = strtolower(rtrim($CFG->wwwroot, '/'));
        if (strtolower(rtrim($origin, '/')) === $site) {
            return true;
        }
        return self::resolve_cors_origin($origin) !== null;
    }

    /**
     * Return a stable identifier for rate-limiting purposes.
     *
     * Prefers a sha256 of the token (so rotation invalidates the bucket) and
     * falls back to the client IP when no token is available.
     *
     * @param string|null $token Authenticated token, if any.
     * @return string
     */
    public static function rate_limit_key(?string $token): string {
        if (!empty($token)) {
            return 'tok_' . substr(hash('sha256', $token), 0, 32);
        }
        $ip = getremoteaddr() ?: '0.0.0.0';
        return 'ip_' . preg_replace('/[^a-zA-Z0-9]/', '_', $ip);
    }

    /**
     * Apply rate limits and return the remaining quota information.
     *
     * Returns a snapshot containing whether the request is allowed and the
     * number of seconds until the window resets. Counters are kept in the
     * Moodle Application cache (configurable as a Redis/Memcached store in
     * production deployments). The read-increment-write is serialised through the
     * MUC lock for the bucket, so the counter is atomic even on cache stores that
     * are not natively atomic; a shared store such as Redis is still recommended
     * for performance under load. Rejected requests still advance the current
     * window counters.
     *
     * @param string $key Stable identifier (see rate_limit_key()).
     * @return array{allowed: bool, retry_after: int, remaining_minute: int, remaining_hour: int}
     */
    public static function enforce_rate_limit(string $key): array {
        $perminute = max(0, (int) self::get_config('rate_limit_per_minute', 60));
        $perhour = max(0, (int) self::get_config('rate_limit_per_hour', 600));

        if ($perminute === 0 && $perhour === 0) {
            return ['allowed' => true, 'retry_after' => 0, 'remaining_minute' => -1, 'remaining_hour' => -1];
        }

        $cache = cache::make('webservice_elediamcp', 'rate_limit');
        $now = time();
        $minutewindow = (int) floor($now / 60);
        $hourwindow = (int) floor($now / 3600);

        $minutekey = $key . '_m_' . $minutewindow;
        $hourkey = $key . '_h_' . $hourwindow;

        // Serialise the read-increment-write through the MUC lock for this bucket so
        // the counter is atomic on cache stores that are not natively atomic. If the
        // lock cannot be taken we still count (best-effort) rather than fail the call.
        $lockkey = 'rl_lock_' . $key;
        $haslock = false;
        try {
            $haslock = (bool) $cache->acquire_lock($lockkey);
        } catch (\Throwable $ex) {
            $haslock = false;
        }
        try {
            $minutecount = ((int) ($cache->get($minutekey) ?: 0)) + 1;
            $hourcount = ((int) ($cache->get($hourkey) ?: 0)) + 1;

            $cache->set($minutekey, $minutecount);
            $cache->set($hourkey, $hourcount);
        } finally {
            if ($haslock) {
                $cache->release_lock($lockkey);
            }
        }

        if ($perminute > 0 && $minutecount > $perminute) {
            return [
                'allowed' => false,
                'retry_after' => 60 - ($now % 60),
                'remaining_minute' => 0,
                'remaining_hour' => max(0, $perhour - $hourcount),
            ];
        }
        if ($perhour > 0 && $hourcount > $perhour) {
            return [
                'allowed' => false,
                'retry_after' => 3600 - ($now % 3600),
                'remaining_minute' => max(0, $perminute - $minutecount),
                'remaining_hour' => 0,
            ];
        }

        return [
            'allowed' => true,
            'retry_after' => 0,
            'remaining_minute' => $perminute > 0 ? max(0, $perminute - $minutecount) : -1,
            'remaining_hour' => $perhour > 0 ? max(0, $perhour - $hourcount) : -1,
        ];
    }

    /**
     * Maximum accepted request body size, in bytes.
     *
     * @return int
     */
    public static function max_request_size(): int {
        return max(1024, (int) self::get_config('max_request_size', 1048576));
    }

    /**
     * Default tools/list page size.
     *
     * @return int
     */
    public static function tools_page_size(): int {
        return max(1, (int) self::get_config('tools_page_size', 50));
    }
}
