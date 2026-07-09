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

/**
 * Opaque token storage, lookup and revocation.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

use cache;
use stdClass;

/**
 * All access to the token/refresh tables goes through here: hashed lookup
 * with a short MUC through-cache (the AC-5 "revocation within one minute"
 * bound is this cache's TTL), refresh-token rotation families, code-replay
 * revocation and the purge used by the scheduled task.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_service {
    /** @var string Prefix of every opaque access token (enables leaked-secret scanning). */
    public const OPAQUE_PREFIX = 'moamcp_';

    /** @var int Grace window (seconds) before expired token rows are purged. */
    private const PURGE_GRACE = 7 * DAYSECS;

    /** @var int Minimum seconds between lastused writes for one token. */
    private const LASTUSED_THROTTLE = 300;

    /**
     * Hash a bearer string for storage/lookup.
     *
     * @param string $bearer Opaque bearer string.
     * @return string
     */
    public static function hash(string $bearer): string {
        return hash('sha256', $bearer);
    }

    /**
     * Look up a live access token row by its bearer string.
     *
     * Served from the MUC through-cache; expiry and revocation are still
     * evaluated on every call so a cached row never outlives its validity.
     *
     * @param string $bearer Opaque bearer string.
     * @return stdClass|null Token row, or null when unknown/revoked/expired.
     */
    public static function lookup_valid(string $bearer): ?stdClass {
        global $DB;

        $hash = self::hash($bearer);
        $cachestore = self::cache();
        $cached = $cachestore->get($hash);
        $row = is_array($cached) ? (object)$cached
            : ($DB->get_record('tool_oauthmcp_token', ['tokenhash' => $hash]) ?: null);

        if (!$row || !empty($row->revoked) || (int)$row->expires < time()) {
            // Never keep invalid rows in the cache — a revoked or expired
            // token should not linger and cannot be revalidated.
            $cachestore->delete($hash);
            return null;
        }

        // Only valid rows are cached, so the warm hot path is a pure cache hit.
        if (!is_array($cached)) {
            $cachestore->set($hash, (array)$row);
        }
        return $row;
    }

    /**
     * Bump the lastused timestamp (throttled, keeps the hot path read-only).
     *
     * @param stdClass $row Token row from lookup_valid().
     * @return void
     */
    public static function touch(stdClass $row): void {
        global $DB;

        if ((int)$row->lastused + self::LASTUSED_THROTTLE > time()) {
            return;
        }
        $row->lastused = time();
        $DB->set_field('tool_oauthmcp_token', 'lastused', $row->lastused, ['id' => $row->id]);
        self::cache()->set($row->tokenhash, (array)$row);
    }

    /**
     * Revoke one access token row (cache purged immediately).
     *
     * @param stdClass $row Token row.
     * @return void
     */
    public static function revoke_access_row(stdClass $row): void {
        global $DB;

        $DB->set_field('tool_oauthmcp_token', 'revoked', 1, ['id' => $row->id]);
        self::cache()->delete($row->tokenhash);
    }

    /**
     * Revoke an access token by its library identifier.
     *
     * @param string $identifier Token identifier.
     * @return void
     */
    public static function revoke_access_by_identifier(string $identifier): void {
        global $DB;

        $row = $DB->get_record('tool_oauthmcp_token', ['identifier' => $identifier]);
        if ($row) {
            self::revoke_access_row($row);
        }
    }

    /**
     * Revoke a whole refresh-token family and its access tokens.
     *
     * Called on refresh-token reuse (RFC 6749 §10.4 replay detection) and
     * on RFC 7009 revocation of a refresh token.
     *
     * @param string $familyid Rotation family id.
     * @return void
     */
    public static function revoke_family(string $familyid): void {
        global $DB;

        $refreshrows = $DB->get_records('tool_oauthmcp_refresh', ['familyid' => $familyid]);
        foreach ($refreshrows as $refresh) {
            if (empty($refresh->revoked)) {
                $DB->set_field('tool_oauthmcp_refresh', 'revoked', 1, ['id' => $refresh->id]);
            }
            if (!empty($refresh->accesstokenid)) {
                $token = $DB->get_record('tool_oauthmcp_token', ['id' => $refresh->accesstokenid]);
                if ($token && empty($token->revoked)) {
                    self::revoke_access_row($token);
                }
            }
        }
    }

    /**
     * Handle an authorization-code replay: kill everything issued from it.
     *
     * @param string $codeidentifier Auth code identifier.
     * @return void
     */
    public static function handle_auth_code_replay(string $codeidentifier): void {
        global $DB;

        $tokens = $DB->get_records('tool_oauthmcp_token', ['authcode' => $codeidentifier]);
        foreach ($tokens as $token) {
            if (empty($token->revoked)) {
                self::revoke_access_row($token);
            }
            $refresh = $DB->get_record('tool_oauthmcp_refresh', ['accesstokenid' => $token->id]);
            if ($refresh) {
                self::revoke_family($refresh->familyid);
            }
        }
    }

    /**
     * Revoke every live token and refresh family of one user+client grant.
     *
     * Used by the self-service "Connected MCP apps" page.
     *
     * @param int $userid Moodle user id.
     * @param int $clientdbid Row id in tool_oauthmcp_client.
     * @return void
     */
    public static function revoke_all_for_user_client(int $userid, int $clientdbid): void {
        global $DB;

        $refreshrows = $DB->get_records('tool_oauthmcp_refresh', ['userid' => $userid, 'clientdbid' => $clientdbid]);
        foreach ($refreshrows as $refresh) {
            self::revoke_family($refresh->familyid);
        }
        $tokens = $DB->get_records('tool_oauthmcp_token', ['userid' => $userid, 'clientdbid' => $clientdbid, 'revoked' => 0]);
        foreach ($tokens as $token) {
            self::revoke_access_row($token);
        }
    }

    /**
     * Purge expired artefacts (scheduled task).
     *
     * Auth codes go immediately after expiry; token and refresh rows keep a
     * grace window so recent activity stays inspectable.
     *
     * @return void
     */
    public static function purge_expired(): void {
        global $DB;

        $now = time();
        $DB->delete_records_select('tool_oauthmcp_authcode', 'expires < :cutoff', ['cutoff' => $now]);
        $DB->delete_records_select('tool_oauthmcp_token', 'expires < :cutoff', ['cutoff' => $now - self::PURGE_GRACE]);
        $DB->delete_records_select('tool_oauthmcp_refresh', 'expires < :cutoff', ['cutoff' => $now - self::PURGE_GRACE]);
    }

    /**
     * The token validation cache.
     *
     * @return cache
     */
    private static function cache(): cache {
        return cache::make('tool_oauthmcp', 'oauthtokens');
    }
}
