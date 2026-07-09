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
 * OAuth client entity.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\entities;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\Traits\ClientTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use stdClass;

/**
 * Wraps a tool_oauthmcp_client record for the library.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_entity implements ClientEntityInterface {
    use ClientTrait;
    use EntityTrait;

    /** @var int Row id in tool_oauthmcp_client. */
    private $dbid;

    /** @var string[] Scopes the client may request. */
    private $allowedscopes;

    /** @var string Token endpoint auth method (none/client_secret_basic/client_secret_post). */
    private $authmethod;

    /** @var string|null Stored secret hash (null for public clients). */
    private $secrethash;

    /**
     * Build the entity from a DB record.
     *
     * @param stdClass $record Row from tool_oauthmcp_client.
     * @return self
     */
    public static function from_record(stdClass $record): self {
        $entity = new self();
        $entity->setIdentifier($record->clientid);
        $entity->name = $record->name;
        $uris = json_decode((string)$record->redirecturis, true);
        $entity->redirectUri = is_array($uris) ? array_values(array_map('strval', $uris)) : [];
        $entity->authmethod = (string)$record->authmethod;
        $entity->isConfidential = $entity->authmethod !== 'none';
        $entity->secrethash = $record->secrethash !== null ? (string)$record->secrethash : null;
        $entity->allowedscopes = array_values(array_filter(explode(' ', (string)$record->scopes)));
        $entity->dbid = (int)$record->id;
        return $entity;
    }

    /**
     * Row id in tool_oauthmcp_client.
     *
     * @return int
     */
    public function get_dbid(): int {
        return $this->dbid;
    }

    /**
     * Scopes the client is allowed to request.
     *
     * @return string[]
     */
    public function get_allowed_scopes(): array {
        return $this->allowedscopes;
    }

    /**
     * Token endpoint auth method.
     *
     * @return string
     */
    public function get_authmethod(): string {
        return $this->authmethod;
    }

    /**
     * Verify a presented client secret.
     *
     * @param string|null $secret Presented secret.
     * @return bool
     */
    public function verify_secret(?string $secret): bool {
        if ($this->secrethash === null || $secret === null || $secret === '') {
            return false;
        }
        return password_verify($secret, $this->secrethash);
    }
}
