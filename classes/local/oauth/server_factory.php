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
 * Authorization server assembly.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../vendor-oauth2/autoload.php');

use DateInterval;
use Defuse\Crypto\Key;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use tool_oauthmcp\local\oauth\repositories\access_token_repository;
use tool_oauthmcp\local\oauth\repositories\auth_code_repository;
use tool_oauthmcp\local\oauth\repositories\client_repository;
use tool_oauthmcp\local\oauth\repositories\refresh_token_repository;
use tool_oauthmcp\local\oauth\repositories\scope_repository;

/**
 * Builds the league AuthorizationServer with exactly two grants:
 * authorization_code (+PKCE, 60s codes) and refresh_token (rotation).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class server_factory {
    /** @var int Auth code lifetime in seconds (FR-OAUTH-2). */
    public const AUTH_CODE_TTL = 60;

    /**
     * Assemble the authorization server.
     *
     * @return AuthorizationServer
     */
    public static function authorization_server(): AuthorizationServer {
        vendor_loader::load();

        $server = new AuthorizationServer(
            new client_repository(),
            new access_token_repository(),
            new scope_repository(),
            new CryptKey(key_manager::private_key_path(), null, false),
            Key::loadFromAsciiSafeString(key_manager::encryption_key())
        );

        $accessttl = new DateInterval('PT' . self::access_token_ttl() . 'S');
        $refreshttl = new DateInterval('PT' . self::refresh_token_ttl() . 'S');
        $refreshrepository = new refresh_token_repository();

        $authcodegrant = new AuthCodeGrant(
            new auth_code_repository(),
            $refreshrepository,
            new DateInterval('PT' . self::AUTH_CODE_TTL . 'S')
        );
        $authcodegrant->setRefreshTokenTTL($refreshttl);
        self::enforce_s256_only($authcodegrant);
        $server->enableGrantType($authcodegrant, $accessttl);

        $refreshgrant = new RefreshTokenGrant($refreshrepository);
        $refreshgrant->setRefreshTokenTTL($refreshttl);
        $server->enableGrantType($refreshgrant, $accessttl);

        return $server;
    }

    /**
     * Remove the "plain" PKCE code-challenge verifier from a grant.
     *
     * authorize.php already rejects any non-S256 challenge, but the library
     * registers a plain verifier by default and offers no public API to
     * drop it. Removing it here makes S256-only defence-in-depth: a plain
     * request is refused at the library's own authorization validation, not
     * only by our script guard.
     *
     * @param AuthCodeGrant $grant The grant to harden.
     * @return void
     */
    private static function enforce_s256_only(AuthCodeGrant $grant): void {
        try {
            $property = new \ReflectionProperty(AuthCodeGrant::class, 'codeChallengeVerifiers');
            $property->setAccessible(true);
            $verifiers = $property->getValue($grant);
            if (is_array($verifiers) && isset($verifiers['plain'])) {
                unset($verifiers['plain']);
                $property->setValue($grant, $verifiers);
            }
        } catch (\ReflectionException $e) {
            // A library version without this internal simply keeps the
            // script-level S256 guard; log for visibility, do not fail.
            debugging('tool_oauthmcp: could not remove plain PKCE verifier: ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Configured access token TTL in seconds.
     *
     * @return int
     */
    public static function access_token_ttl(): int {
        $ttl = (int)get_config('tool_oauthmcp', 'accesstokenttl');
        return $ttl > 0 ? $ttl : HOURSECS;
    }

    /**
     * Configured refresh token TTL in seconds.
     *
     * @return int
     */
    public static function refresh_token_ttl(): int {
        $ttl = (int)get_config('tool_oauthmcp', 'refreshtokenttl');
        return $ttl > 0 ? $ttl : 30 * DAYSECS;
    }
}
