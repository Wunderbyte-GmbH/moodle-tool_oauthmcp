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
 * OAuth scope repository.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\repositories;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\ScopeEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\oauth\entities\client_entity;
use tool_oauthmcp\local\oauth\entities\scope_entity;
use tool_oauthmcp\local\oauth\token_request_context;

/**
 * Knows exactly the two MCP scopes and finalises grants against the
 * client's registered scope ceiling.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scope_repository implements ScopeRepositoryInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    /** @var string[] All scopes this server issues. */
    public const VALID_SCOPES = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];

    /**
     * Resolve a scope identifier.
     *
     * @param string $identifier Scope identifier.
     * @return ScopeEntityInterface|null
     */
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface {
        if (!in_array($identifier, self::VALID_SCOPES, true)) {
            return null;
        }
        return new scope_entity($identifier);
    }

    /**
     * Finalise the scopes bound into the issued token.
     *
     * Requested ∩ client-registered; an empty request defaults to read-only.
     * The auth-code id is stashed for the persist step (replay revocation).
     *
     * @param ScopeEntityInterface[] $scopes Requested scopes.
     * @param string $granttype Grant type id.
     * @param ClientEntityInterface $cliententity The client.
     * @param string|null $useridentifier Resource owner id.
     * @param string|null $authcodeid Redeemed auth code id.
     * @return ScopeEntityInterface[]
     */
    public function finalizeScopes(
        array $scopes,
        string $granttype,
        ClientEntityInterface $cliententity,
        ?string $useridentifier = null,
        ?string $authcodeid = null
    ): array {
        token_request_context::set_authcodeid($authcodeid);

        $ceiling = $cliententity instanceof client_entity
            ? $cliententity->get_allowed_scopes()
            : self::VALID_SCOPES;

        $final = [];
        foreach ($scopes as $scope) {
            $identifier = $scope->getIdentifier();
            if (in_array($identifier, self::VALID_SCOPES, true) && in_array($identifier, $ceiling, true)) {
                $final[$identifier] = new scope_entity($identifier);
            }
        }

        if (empty($final)) {
            $final[auth_result::SCOPE_READ] = new scope_entity(auth_result::SCOPE_READ);
        }

        return array_values($final);
    }
}
