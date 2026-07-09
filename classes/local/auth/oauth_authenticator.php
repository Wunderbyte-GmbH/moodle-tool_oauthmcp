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
 * Bearer authentication with plugin-issued OAuth access tokens.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\auth;

use context_system;
use core_user;
use tool_oauthmcp\local\oauth\token_service;
use tool_oauthmcp\local\oauth\urls;

/**
 * Validates opaque OAuth bearer tokens: hashed DB lookup through a 60s MUC
 * cache (NFR-5: at most one extra read), then revocation/expiry, RFC 8707
 * audience binding, client and user liveness and the connect capability.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_authenticator implements bearer_authenticator_interface {
    /**
     * Try to authenticate an opaque OAuth bearer token.
     *
     * @param string $bearertoken The raw bearer token.
     * @return auth_result|null
     */
    public function authenticate(string $bearertoken): ?auth_result {
        global $DB;

        if (strpos($bearertoken, token_service::OPAQUE_PREFIX) !== 0) {
            return null;
        }

        $row = token_service::lookup_valid($bearertoken);
        if (!$row) {
            return null;
        }

        // RFC 8707 audience binding: a resource-bound token only works here.
        if (!empty($row->resourceuri) && $row->resourceuri !== urls::normalise(urls::resource())) {
            return null;
        }

        if (!$DB->record_exists('tool_oauthmcp_client', ['id' => $row->clientdbid, 'enabled' => 1])) {
            return null;
        }

        $user = core_user::get_user((int)$row->userid);
        $userunusable = !$user || !empty($user->deleted) || !empty($user->suspended)
            || empty($user->confirmed) || $user->auth === 'nologin';
        if ($userunusable) {
            return null;
        }
        if (!has_capability('tool/oauthmcp:connect', context_system::instance(), $user)) {
            return null;
        }

        token_service::touch($row);

        $scopes = array_values(array_intersect(
            array_filter(explode(' ', (string)$row->scopes)),
            [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE]
        ));
        return new auth_result((int)$row->userid, $scopes, 'oauth');
    }
}
