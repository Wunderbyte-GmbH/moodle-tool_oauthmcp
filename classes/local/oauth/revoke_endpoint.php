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
 * Token revocation endpoint (RFC 7009).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../vendor-oauth2/autoload.php');

use core\context\system as system_context;
use Defuse\Crypto\Crypto;
use Defuse\Crypto\Key;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use tool_oauthmcp\event\token_revoked;
use tool_oauthmcp\local\oauth\entities\client_entity;
use tool_oauthmcp\local\ratelimit\sliding_window;

/**
 * Client-authenticated revocation of access and refresh tokens.
 *
 * Revoking a refresh token kills its whole rotation family including the
 * access tokens; revoking an access token affects only that token. Per
 * RFC 7009 unknown tokens still answer 200.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class revoke_endpoint {
    /**
     * Handle one revocation request, tagging every response against sniffing.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return $this->process($request)->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Produce the revocation response for one request.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return ResponseInterface
     */
    private function process(ServerRequestInterface $request): ResponseInterface {
        global $DB;

        vendor_loader::load();

        if (!get_config('tool_oauthmcp', 'enabled')) {
            return $this->json(503, ['error' => 'temporarily_unavailable']);
        }
        $limit = (int)get_config('tool_oauthmcp', 'ratelimittoken');
        $limit = $limit > 0 ? $limit : 30;
        if (!(new sliding_window())->check('oauthrevoke:' . getremoteaddr(), $limit, 60)) {
            return $this->json(429, ['error' => 'slow_down'], ['Retry-After' => '60']);
        }

        $body = (array)$request->getParsedBody();
        $client = $this->authenticate_client($request, $body);
        if ($client === null) {
            return $this->json(
                401,
                ['error' => 'invalid_client'],
                ['WWW-Authenticate' => 'Basic realm="tool_oauthmcp"']
            );
        }

        $token = trim((string)($body['token'] ?? ''));
        if ($token === '') {
            return $this->json(400, ['error' => 'invalid_request', 'error_description' => 'token is required']);
        }

        $this->revoke($token, $client);

        // RFC 7009: unknown or already-revoked tokens are not an error.
        return new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], '{}');
    }

    /**
     * Try to revoke the presented token for this client.
     *
     * @param string $token Presented token string.
     * @param stdClass $client Authenticated client record.
     * @return void
     */
    private function revoke(string $token, stdClass $client): void {
        global $DB;

        // Access token: opaque, hash lookup.
        if (strpos($token, token_service::OPAQUE_PREFIX) === 0) {
            $row = $DB->get_record('tool_oauthmcp_token', ['tokenhash' => token_service::hash($token)]);
            if ($row && (int)$row->clientdbid === (int)$client->id && empty($row->revoked)) {
                token_service::revoke_access_row($row);
                $this->trigger_revoked('access');
            }
            return;
        }

        // Refresh token: the library's encrypted payload carries the identifier.
        try {
            $key = Key::loadFromAsciiSafeString(key_manager::encryption_key());
            $payload = json_decode(Crypto::decrypt($token, $key), true);
        } catch (\Throwable $e) {
            return;
        }
        $refreshid = (string)($payload['refresh_token_id'] ?? '');
        $clientid = (string)($payload['client_id'] ?? '');
        if ($refreshid === '' || $clientid !== (string)$client->clientid) {
            return;
        }
        $row = $DB->get_record('tool_oauthmcp_refresh', ['identifier' => $refreshid]);
        if ($row && (int)$row->clientdbid === (int)$client->id) {
            token_service::revoke_family($row->familyid);
            $this->trigger_revoked('refresh');
        }
    }

    /**
     * Authenticate the calling client (Basic header or body params).
     *
     * Public clients authenticate by client_id alone per RFC 7009 practice;
     * confidential clients must present their secret.
     *
     * @param ServerRequestInterface $request The request.
     * @param array $body Parsed body.
     * @return stdClass|null The client record.
     */
    private function authenticate_client(ServerRequestInterface $request, array $body): ?stdClass {
        global $DB;

        $clientid = '';
        $secret = null;

        $authheader = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Basic\s+(\S+)$/i', $authheader, $matches)) {
            $decoded = base64_decode($matches[1], true);
            if ($decoded !== false && strpos($decoded, ':') !== false) {
                [$clientid, $secret] = explode(':', $decoded, 2);
                $clientid = urldecode($clientid);
                $secret = urldecode($secret);
            }
        }
        if ($clientid === '') {
            $clientid = trim((string)($body['client_id'] ?? ''));
            $secret = isset($body['client_secret']) ? (string)$body['client_secret'] : null;
        }
        if ($clientid === '') {
            return null;
        }

        $record = $DB->get_record('tool_oauthmcp_client', ['clientid' => $clientid, 'enabled' => 1]);
        if (!$record) {
            return null;
        }
        $entity = client_entity::from_record($record);
        if ($entity->isConfidential() && !$entity->verify_secret($secret)) {
            return null;
        }
        return $record;
    }

    /**
     * Trigger the token_revoked audit event.
     *
     * @param string $tokentype access or refresh.
     * @return void
     */
    private function trigger_revoked(string $tokentype): void {
        token_revoked::create([
            'context' => system_context::instance(),
            'other' => ['via' => 'rfc7009', 'tokentype' => $tokentype],
        ])->trigger();
    }

    /**
     * Build a JSON response.
     *
     * @param int $status HTTP status.
     * @param array $body Payload.
     * @param string[] $headers Extra headers.
     * @return ResponseInterface
     */
    private function json(int $status, array $body, array $headers = []): ResponseInterface {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        return new Response($status, $headers, (string)json_encode($body));
    }
}
