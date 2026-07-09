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
 * Bearer authentication with Moodle web service tokens.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\auth;

use context_system;
use tool_oauthmcp\local\external_service_manager;

/**
 * Accepts a Moodle web service token as bearer token.
 *
 * Delegates every token/service/user policy check to core
 * webservice::authenticate_user() (token validity, IP restriction, service
 * enabled, restricted users, suspension, auth policy — including the core
 * webservice_login_failed audit events), then additionally requires the
 * plugin's connect capability. By default only tokens of the plugin's
 * dedicated "MCP server" service are accepted, so a token minted for e.g.
 * the mobile app never opens the MCP surface; the wstokenanyservice setting
 * lifts that restriction. WS tokens are an admin-minted instrument, so
 * they deliberately carry both scopes; scope granularity is an OAuth
 * feature (see implementation plan WP-A3).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class wstoken_authenticator implements bearer_authenticator_interface {
    /**
     * Try to authenticate the bearer as a Moodle WS token.
     *
     * @param string $bearertoken The raw bearer token.
     * @return auth_result|null
     */
    public function authenticate(string $bearertoken): ?auth_result {
        global $CFG;

        // Web service tokens are 32 hex chars; anything else is not ours.
        if (!preg_match('/^[a-f0-9]{32}$/i', $bearertoken)) {
            return null;
        }

        require_once($CFG->dirroot . '/webservice/lib.php');
        try {
            $authinfo = (new \webservice())->authenticate_user(strtolower($bearertoken));
        } catch (\Throwable $e) {
            return null;
        }

        $service = $authinfo['service'] ?? null;
        $dedicatedonly = empty(get_config('tool_oauthmcp', 'wstokenanyservice'));
        if ($dedicatedonly && (!$service || $service->shortname !== external_service_manager::SERVICE_SHORTNAME)) {
            return null;
        }

        $user = $authinfo['user'] ?? null;
        if (!$user || !has_capability('tool/oauthmcp:connect', context_system::instance(), $user)) {
            return null;
        }

        return new auth_result(
            (int)$user->id,
            [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE],
            'wstoken'
        );
    }
}
