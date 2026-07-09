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
 * Opaque access token entity.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth\entities;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../vendor-oauth2/autoload.php');

use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\Traits\AccessTokenTrait;
use League\OAuth2\Server\Entities\Traits\EntityTrait;
use League\OAuth2\Server\Entities\Traits\TokenEntityTrait;

/**
 * Access token serialised as an opaque string instead of a JWT.
 *
 * FR-OAUTH-4: the wire format is a prefixed random identifier; validation
 * is a hashed DB lookup (see token_service), no signed material ever leaves
 * the server. The prefix makes leaked-secret scanning possible.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class access_token_entity implements AccessTokenEntityInterface {
    // Method names below are fixed by the league/oauth2-server interfaces.
    // phpcs:disable moodle.NamingConventions.ValidFunctionName.LowercaseMethod
    use AccessTokenTrait;
    use EntityTrait;
    use TokenEntityTrait;

    /**
     * The opaque wire representation of this token.
     *
     * @return string
     */
    public function toString(): string {
        return \tool_oauthmcp\local\oauth\token_service::OPAQUE_PREFIX . $this->getIdentifier();
    }
}
