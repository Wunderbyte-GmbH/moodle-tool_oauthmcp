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
 * OAuth discovery documents.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

use tool_oauthmcp\local\auth\auth_result;

/**
 * Builds the RFC 8414 authorization-server metadata and the RFC 9728
 * protected-resource metadata documents.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class metadata {
    /**
     * RFC 8414 authorization server metadata.
     *
     * @return array
     */
    public static function authorization_server(): array {
        $document = [
            'issuer' => urls::issuer(),
            'authorization_endpoint' => urls::oauth('authorize'),
            'token_endpoint' => urls::oauth('token'),
            'revocation_endpoint' => urls::oauth('revoke'),
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none', 'client_secret_basic', 'client_secret_post'],
            'scopes_supported' => [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE],
        ];
        if (!empty(get_config('tool_oauthmcp', 'dcrenabled'))) {
            $document['registration_endpoint'] = urls::oauth('register');
        }
        return $document;
    }

    /**
     * RFC 9728 protected resource metadata (the MCP endpoint).
     *
     * @return array
     */
    public static function protected_resource(): array {
        return [
            'resource' => urls::resource(),
            'authorization_servers' => [urls::issuer()],
            'scopes_supported' => [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE],
            'bearer_methods_supported' => ['header'],
        ];
    }
}
