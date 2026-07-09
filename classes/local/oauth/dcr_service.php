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
 * Dynamic client registration (RFC 7591).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

use core\context\system as system_context;
use stdClass;
use tool_oauthmcp\event\client_registered;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\ratelimit\sliding_window;

/**
 * Validates RFC 7591 registration requests and creates client rows.
 *
 * claude.ai custom connectors depend on anonymous DCR; abuse is contained
 * by a per-IP rate limit and a global quota of live DCR clients.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dcr_service {
    /** @var string[] Grant types a client may register. */
    private const ALLOWED_GRANTS = ['authorization_code', 'refresh_token'];

    /** @var string[] Token endpoint auth methods a client may register. */
    private const ALLOWED_AUTH_METHODS = ['none', 'client_secret_basic', 'client_secret_post'];

    /**
     * Process one registration request.
     *
     * @param array $metadata Decoded RFC 7591 client metadata.
     * @param string $ip Requesting IP (rate limiting + audit).
     * @return array ['status' => int, 'body' => array]
     */
    public function register(array $metadata, string $ip): array {
        global $DB;

        if (!get_config('tool_oauthmcp', 'enabled') || empty(get_config('tool_oauthmcp', 'dcrenabled'))) {
            return $this->error(403, 'access_denied', 'Dynamic client registration is disabled');
        }

        $limit = (int)get_config('tool_oauthmcp', 'ratelimitregister');
        $limit = $limit > 0 ? $limit : 5;
        if (!(new sliding_window())->check('oauthdcr:' . $ip, $limit, HOURSECS)) {
            return $this->error(429, 'access_denied', 'Too many registration requests');
        }

        $quota = (int)get_config('tool_oauthmcp', 'dcrquota');
        $quota = $quota > 0 ? $quota : 100;
        if ($DB->count_records('tool_oauthmcp_client', ['dcr' => 1, 'enabled' => 1]) >= $quota) {
            return $this->error(503, 'access_denied', 'Registration quota exhausted');
        }

        $name = trim(clean_param((string)($metadata['client_name'] ?? ''), PARAM_TEXT));
        if ($name === '' || \core_text::strlen($name) > 255) {
            return $this->error(400, 'invalid_client_metadata', 'client_name is required');
        }

        $uris = $metadata['redirect_uris'] ?? null;
        if (!is_array($uris) || empty($uris)) {
            return $this->error(400, 'invalid_redirect_uri', 'redirect_uris is required');
        }
        $validuris = [];
        foreach ($uris as $uri) {
            if (!is_string($uri) || !self::is_valid_redirect_uri($uri)) {
                return $this->error(400, 'invalid_redirect_uri', 'Invalid redirect URI: ' . clean_param((string)$uri, PARAM_TEXT));
            }
            $validuris[] = $uri;
        }

        $granttypes = $metadata['grant_types'] ?? ['authorization_code'];
        if (!is_array($granttypes) || array_diff($granttypes, self::ALLOWED_GRANTS)) {
            return $this->error(400, 'invalid_client_metadata', 'Unsupported grant type');
        }
        $responsetypes = $metadata['response_types'] ?? ['code'];
        if (!is_array($responsetypes) || array_diff($responsetypes, ['code'])) {
            return $this->error(400, 'invalid_client_metadata', 'Unsupported response type');
        }
        $authmethod = (string)($metadata['token_endpoint_auth_method'] ?? 'none');
        if (!in_array($authmethod, self::ALLOWED_AUTH_METHODS, true)) {
            return $this->error(400, 'invalid_client_metadata', 'Unsupported token endpoint auth method');
        }

        $validscopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];
        $scopes = $validscopes;
        if (isset($metadata['scope']) && is_string($metadata['scope']) && trim($metadata['scope']) !== '') {
            $requested = array_filter(explode(' ', $metadata['scope']));
            if (array_diff($requested, $validscopes)) {
                return $this->error(400, 'invalid_client_metadata', 'Unsupported scope');
            }
            $scopes = array_values($requested);
        }

        $record = new stdClass();
        $record->clientid = bin2hex(random_bytes(16));
        $secret = null;
        if ($authmethod !== 'none') {
            $secret = bin2hex(random_bytes(32));
            $record->secrethash = password_hash($secret, PASSWORD_DEFAULT);
        } else {
            $record->secrethash = null;
        }
        $record->name = $name;
        $record->redirecturis = json_encode($validuris);
        $record->scopes = implode(' ', $scopes);
        $record->authmethod = $authmethod;
        $record->dcr = 1;
        $record->enabled = 1;
        $record->createdby = null;
        $record->registrationip = $ip;
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('tool_oauthmcp_client', $record);

        client_registered::create([
            'context' => system_context::instance(),
            'objectid' => $record->id,
            'other' => ['name' => $name, 'dcr' => 1, 'ip' => $ip],
        ])->trigger();

        $body = [
            'client_id' => $record->clientid,
            'client_id_issued_at' => $record->timecreated,
            'client_name' => $name,
            'redirect_uris' => $validuris,
            'grant_types' => array_values($granttypes),
            'response_types' => array_values($responsetypes),
            'token_endpoint_auth_method' => $authmethod,
            'scope' => $record->scopes,
        ];
        if ($secret !== null) {
            $body['client_secret'] = $secret;
            $body['client_secret_expires_at'] = 0;
        }

        return ['status' => 201, 'body' => $body];
    }

    /**
     * Redirect URI policy: absolute, no fragment, https only —
     * loopback http allowed while dcrallowlocalhost is on (Claude Code
     * and other local clients use loopback callbacks).
     *
     * @param string $uri Candidate redirect URI.
     * @return bool
     */
    public static function is_valid_redirect_uri(string $uri): bool {
        if (\core_text::strlen($uri) > 255 || strpos($uri, '#') !== false) {
            return false;
        }
        $parts = parse_url($uri);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme === 'https') {
            return true;
        }
        if ($scheme === 'http' && !empty(get_config('tool_oauthmcp', 'dcrallowlocalhost'))) {
            $host = strtolower($parts['host']);
            return in_array($host, ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        }
        return false;
    }

    /**
     * Build an RFC 7591 error result.
     *
     * @param int $status HTTP status.
     * @param string $error Error code.
     * @param string $description Detail.
     * @return array
     */
    private function error(int $status, string $error, string $description): array {
        return ['status' => $status, 'body' => ['error' => $error, 'error_description' => $description]];
    }
}
