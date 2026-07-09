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
 * OAuth client repository.
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
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use tool_oauthmcp\local\oauth\entities\client_entity;

/**
 * Loads and validates clients from tool_oauthmcp_client.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_repository implements ClientRepositoryInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    /**
     * Load an enabled client by its public identifier.
     *
     * @param string $clientidentifier Public client id.
     * @return ClientEntityInterface|null
     */
    public function getClientEntity(string $clientidentifier): ?ClientEntityInterface {
        $record = $this->get_record($clientidentifier);
        return $record ? client_entity::from_record($record) : null;
    }

    /**
     * Validate client credentials for the token endpoint.
     *
     * Public clients (auth method "none") pass without a secret — the grant
     * enforces PKCE for them; confidential clients must present their secret.
     *
     * @param string $clientidentifier Public client id.
     * @param string|null $clientsecret Presented secret.
     * @param string|null $granttype Requested grant type.
     * @return bool
     */
    public function validateClient(string $clientidentifier, ?string $clientsecret, ?string $granttype): bool {
        $record = $this->get_record($clientidentifier);
        if (!$record) {
            return false;
        }
        $entity = client_entity::from_record($record);
        if (!$entity->isConfidential()) {
            return true;
        }
        return $entity->verify_secret($clientsecret);
    }

    /**
     * Fetch the enabled client record.
     *
     * @param string $clientidentifier Public client id.
     * @return \stdClass|null
     */
    private function get_record(string $clientidentifier): ?\stdClass {
        global $DB;

        if ($clientidentifier === '' || strlen($clientidentifier) > 64) {
            return null;
        }
        $record = $DB->get_record('tool_oauthmcp_client', ['clientid' => $clientidentifier, 'enabled' => 1]);
        return $record ?: null;
    }
}
