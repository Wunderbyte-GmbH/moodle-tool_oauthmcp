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
 * OAuth token endpoint handler.
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
use GuzzleHttp\Psr7\Response;
use League\OAuth2\Server\Exception\OAuthServerException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use tool_oauthmcp\event\token_refused;
use tool_oauthmcp\local\ratelimit\sliding_window;

/**
 * PSR-7 handler behind oauth/token.php: rate limit, RFC 8707 resource
 * validation, then the library's grant processing (authorization_code
 * with PKCE, refresh_token with rotation).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_endpoint {
    /**
     * Handle one token request, tagging every response against sniffing.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface {
        return $this->process($request)->withHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * Produce the token response for one request.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return ResponseInterface
     */
    private function process(ServerRequestInterface $request): ResponseInterface {
        vendor_loader::load();

        if (!get_config('tool_oauthmcp', 'enabled')) {
            return $this->json_error(503, 'temporarily_unavailable', 'MCP server is disabled');
        }

        $limit = (int)get_config('tool_oauthmcp', 'ratelimittoken');
        $limit = $limit > 0 ? $limit : 30;
        if (!(new sliding_window())->check('oauthtoken:' . getremoteaddr(), $limit, 60)) {
            return $this->json_error(429, 'slow_down', 'Too many token requests', ['Retry-After' => '60']);
        }

        token_request_context::reset();
        $body = (array)$request->getParsedBody();
        $resource = trim((string)($body['resource'] ?? ''));
        if ($resource !== '') {
            if (!urls::is_our_resource($resource)) {
                $this->trigger_refused('invalid_target');
                return $this->json_error(400, 'invalid_target', 'Unknown resource indicator');
            }
            token_request_context::set_resource(urls::normalise($resource));
        }

        try {
            return server_factory::authorization_server()->respondToAccessTokenRequest($request, new Response());
        } catch (OAuthServerException $e) {
            $this->trigger_refused($e->getErrorType());
            return $e->generateHttpResponse(new Response());
        } catch (\Throwable $e) {
            debugging('tool_oauthmcp: token endpoint failure: ' . $e->getMessage(), DEBUG_DEVELOPER);
            $this->trigger_refused('server_error');
            return $this->json_error(500, 'server_error', 'Internal error');
        }
    }

    /**
     * Trigger the token_refused audit event.
     *
     * @param string $error RFC 6749 error type.
     * @return void
     */
    private function trigger_refused(string $error): void {
        token_refused::create([
            'context' => system_context::instance(),
            'other' => ['error' => $error, 'ip' => getremoteaddr()],
        ])->trigger();
    }

    /**
     * Build an RFC 6749-style JSON error response.
     *
     * @param int $status HTTP status.
     * @param string $error Error code.
     * @param string $description Human-readable detail.
     * @param string[] $headers Extra headers.
     * @return ResponseInterface
     */
    private function json_error(int $status, string $error, string $description, array $headers = []): ResponseInterface {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        $headers['Cache-Control'] = 'no-store';
        return new Response($status, $headers, (string)json_encode([
            'error' => $error,
            'error_description' => $description,
        ]));
    }
}
