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
 * Authorization code repository.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\repositories;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use tool_oauthmcp\local\oauth\entities\auth_code_entity;
use tool_oauthmcp\local\oauth\entities\client_entity;
use tool_oauthmcp\local\oauth\token_service;

/**
 * Tracks auth code identifiers for single use.
 *
 * The wire format is the library's encrypted payload; this table only
 * answers "was this code already redeemed" — and when yes, everything the
 * code ever issued is revoked (OAuth 2.1 replay mitigation).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_code_repository implements AuthCodeRepositoryInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    /**
     * Create a fresh code entity.
     *
     * @return AuthCodeEntityInterface
     */
    public function getNewAuthCode(): AuthCodeEntityInterface {
        return new auth_code_entity();
    }

    /**
     * Persist the code identifier for single-use tracking.
     *
     * @param AuthCodeEntityInterface $authcodeentity The issued code.
     * @return void
     */
    public function persistNewAuthCode(AuthCodeEntityInterface $authcodeentity): void {
        global $DB;

        $client = $authcodeentity->getClient();
        $record = new \stdClass();
        $record->identifier = $authcodeentity->getIdentifier();
        $record->clientdbid = $client instanceof client_entity ? $client->get_dbid() : 0;
        $record->userid = (int)$authcodeentity->getUserIdentifier();
        $record->expires = $authcodeentity->getExpiryDateTime()->getTimestamp();
        $record->revoked = 0;
        $record->timecreated = time();
        $DB->insert_record('tool_oauthmcp_authcode', $record);
    }

    /**
     * Mark a code as redeemed.
     *
     * @param string $codeid Code identifier.
     * @return void
     */
    public function revokeAuthCode(string $codeid): void {
        global $DB;

        $DB->set_field('tool_oauthmcp_authcode', 'revoked', 1, ['identifier' => $codeid]);
    }

    /**
     * Whether a code was already redeemed; replay kills the issued tokens.
     *
     * @param string $codeid Code identifier.
     * @return bool
     */
    public function isAuthCodeRevoked(string $codeid): bool {
        global $DB;

        $row = $DB->get_record('tool_oauthmcp_authcode', ['identifier' => $codeid]);
        if (!$row) {
            // Unknown identifier: fail closed.
            return true;
        }
        if (!empty($row->revoked)) {
            token_service::handle_auth_code_replay($codeid);
            return true;
        }
        return false;
    }
}
