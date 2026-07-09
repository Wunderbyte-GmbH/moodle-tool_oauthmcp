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
 * Refresh token repository.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\repositories;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use tool_oauthmcp\local\oauth\entities\client_entity;
use tool_oauthmcp\local\oauth\entities\refresh_token_entity;
use tool_oauthmcp\local\oauth\token_service;

/**
 * Persists refresh tokens with rotation families.
 *
 * Within one refresh-grant request the library first checks the presented
 * token via isRefreshTokenRevoked() (where we detect reuse and kill the
 * family), then revokes it (rotation) and persists the successor — which
 * inherits the family id captured during the check.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class refresh_token_repository implements RefreshTokenRepositoryInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    /** @var string|null Family of the refresh token presented in this request. */
    private $currentfamily = null;

    /**
     * Create a fresh refresh token entity.
     *
     * @return RefreshTokenEntityInterface|null
     */
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface {
        return new refresh_token_entity();
    }

    /**
     * Persist the refresh token row.
     *
     * @param RefreshTokenEntityInterface $refreshtokenentity The issued token.
     * @return void
     */
    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshtokenentity): void {
        global $DB;

        $accesstoken = $refreshtokenentity->getAccessToken();
        $client = $accesstoken->getClient();
        $accessrow = $DB->get_record('tool_oauthmcp_token', ['identifier' => $accesstoken->getIdentifier()]);

        $record = new \stdClass();
        $record->identifier = $refreshtokenentity->getIdentifier();
        $record->accesstokenid = $accessrow ? (int)$accessrow->id : null;
        $record->familyid = $this->currentfamily ?? bin2hex(random_bytes(16));
        $record->clientdbid = $client instanceof client_entity ? $client->get_dbid() : 0;
        $record->userid = (int)$accesstoken->getUserIdentifier();
        $record->expires = $refreshtokenentity->getExpiryDateTime()->getTimestamp();
        $record->revoked = 0;
        $record->timecreated = time();
        $DB->insert_record('tool_oauthmcp_refresh', $record);
    }

    /**
     * Mark one refresh token as used (rotation).
     *
     * @param string $tokenid Refresh token identifier.
     * @return void
     */
    public function revokeRefreshToken(string $tokenid): void {
        global $DB;

        $DB->set_field('tool_oauthmcp_refresh', 'revoked', 1, ['identifier' => $tokenid]);
    }

    /**
     * Whether a refresh token is revoked; reuse kills the whole family.
     *
     * @param string $tokenid Refresh token identifier.
     * @return bool
     */
    public function isRefreshTokenRevoked(string $tokenid): bool {
        global $DB;

        $row = $DB->get_record('tool_oauthmcp_refresh', ['identifier' => $tokenid]);
        if (!$row) {
            // Unknown identifier: fail closed.
            return true;
        }
        if (!empty($row->revoked)) {
            // A rotated-out token was presented again: replay. Revoke the
            // entire family including its access tokens (RFC 6749 §10.4).
            token_service::revoke_family($row->familyid);
            return true;
        }
        $this->currentfamily = $row->familyid;
        return false;
    }
}
