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
 * OAuth access token repository.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\repositories;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use core\context\system as system_context;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use tool_oauthmcp\event\token_issued;
use tool_oauthmcp\local\oauth\entities\access_token_entity;
use tool_oauthmcp\local\oauth\entities\client_entity;
use tool_oauthmcp\local\oauth\token_request_context;
use tool_oauthmcp\local\oauth\token_service;

/**
 * Persists opaque access tokens hash-first: the plaintext bearer exists
 * only in the HTTP response, the row stores sha256 plus metadata.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_token_repository implements AccessTokenRepositoryInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    /**
     * Create a fresh token entity.
     *
     * @param ClientEntityInterface $cliententity The client.
     * @param \League\OAuth2\Server\Entities\ScopeEntityInterface[] $scopes Finalised scopes.
     * @param string|null $useridentifier Resource owner id.
     * @return AccessTokenEntityInterface
     */
    public function getNewToken(
        ClientEntityInterface $cliententity,
        array $scopes,
        ?string $useridentifier = null
    ): AccessTokenEntityInterface {
        $token = new access_token_entity();
        $token->setClient($cliententity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($useridentifier !== null) {
            $token->setUserIdentifier($useridentifier);
        }
        return $token;
    }

    /**
     * Persist the token row and trigger the token_issued audit event.
     *
     * @param AccessTokenEntityInterface $accesstokenentity The issued token.
     * @return void
     */
    public function persistNewAccessToken(AccessTokenEntityInterface $accesstokenentity): void {
        global $DB;

        $client = $accesstokenentity->getClient();
        $clientdbid = $client instanceof client_entity ? $client->get_dbid() : 0;
        $scopes = array_map(static function ($scope) {
            return $scope->getIdentifier();
        }, $accesstokenentity->getScopes());

        $record = new \stdClass();
        $record->tokenhash = token_service::hash($accesstokenentity->toString());
        $record->identifier = $accesstokenentity->getIdentifier();
        $record->clientdbid = $clientdbid;
        $record->userid = (int)$accesstokenentity->getUserIdentifier();
        $record->scopes = implode(' ', $scopes);
        $record->resourceuri = token_request_context::get_resource();
        $record->authcode = token_request_context::get_authcodeid();
        $record->expires = $accesstokenentity->getExpiryDateTime()->getTimestamp();
        $record->revoked = 0;
        $record->timecreated = time();
        $record->lastused = 0;
        $tokenid = $DB->insert_record('tool_oauthmcp_token', $record);

        token_issued::create([
            'context' => system_context::instance(),
            'objectid' => $tokenid,
            'relateduserid' => $record->userid,
            'other' => [
                'clientid' => $client->getIdentifier(),
                'scopes' => $record->scopes,
            ],
        ])->trigger();
    }

    /**
     * Revoke a token by identifier.
     *
     * @param string $tokenid Token identifier.
     * @return void
     */
    public function revokeAccessToken(string $tokenid): void {
        token_service::revoke_access_by_identifier($tokenid);
    }

    /**
     * Whether a token identifier is revoked (fail closed on unknown ids).
     *
     * @param string $tokenid Token identifier.
     * @return bool
     */
    public function isAccessTokenRevoked(string $tokenid): bool {
        global $DB;

        $row = $DB->get_record('tool_oauthmcp_token', ['identifier' => $tokenid]);
        return !$row || !empty($row->revoked);
    }
}
