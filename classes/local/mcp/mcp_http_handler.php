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
 * Streamable-HTTP transport handler for the MCP endpoint.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

use core_user;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use stdClass;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\auth\wstoken_authenticator;

/**
 * PSR-7 in, PSR-7 out: transport guards, bearer auth, session handling and
 * JSON-RPC dispatch. server.php is a thin shell around this class so the
 * whole transport is PHPUnit-drivable without HTTP.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_http_handler {
    /** @var string The session id header per MCP streamable HTTP. */
    private const SESSION_HEADER = 'Mcp-Session-Id';

    /** @var string The protocol version header (2025-06-18). */
    private const VERSION_HEADER = 'MCP-Protocol-Version';

    /**
     * @var string Fallback header carrying a web-service token for hosting that strips the
     * Authorization header before it reaches PHP (FastCGI/CGI). Custom X- headers survive
     * every webserver untouched, so this needs no server configuration at all.
     */
    public const TOKEN_HEADER = 'X-Moodle-Token';

    /** @var session_manager */
    private $sessions;

    /** @var mcp_method_controller */
    private $controller;

    /**
     * Constructor.
     *
     * @param session_manager|null $sessions Session manager override for tests.
     * @param mcp_method_controller|null $controller Controller override for tests.
     */
    public function __construct(?session_manager $sessions = null, ?mcp_method_controller $controller = null) {
        $this->sessions = $sessions ?? new session_manager();
        $this->controller = $controller ?? new mcp_method_controller();
    }

    /**
     * Handle one HTTP request against the MCP endpoint.
     *
     * @param ServerRequestInterface $request The incoming request.
     * @return ResponseInterface
     */
    public function handle(ServerRequestInterface $request): ResponseInterface {
        global $CFG;

        if (!get_config('tool_oauthmcp', 'enabled')) {
            return $this->json_response(503, ['error' => 'MCP server is disabled']);
        }

        // FR-MCP-4: HTTPS only, unless the developer flag is set in config.php.
        if (!is_https() && empty($CFG->tool_oauthmcp_allowhttp)) {
            return $this->json_response(403, ['error' => 'HTTPS is required']);
        }

        if (!$this->origin_allowed($request)) {
            return $this->json_response(403, ['error' => 'Origin not allowed']);
        }

        $method = strtoupper($request->getMethod());
        if ($method !== 'POST' && $method !== 'DELETE') {
            return $this->json_response(405, ['error' => 'Method not allowed'], ['Allow' => 'POST, DELETE']);
        }

        $auth = $this->authenticate($request);
        if ($auth === null) {
            // A presented-but-rejected credential is a security-relevant event
            // (brute force, stale token); a bare probe without any credential
            // is not worth logging.
            $presented = preg_match('/^Bearer\s+\S/i', trim($request->getHeaderLine('Authorization')))
                || trim($request->getHeaderLine(self::TOKEN_HEADER)) !== '';
            if ($presented) {
                \tool_oauthmcp\event\auth_failed::create([
                    'context' => \core\context\system::instance(),
                    'other' => ['ip' => getremoteaddr()],
                ])->trigger();
            }
            // RFC 9728: the challenge points clients at the protected-resource
            // metadata, which names the authorization server.
            $prm = \tool_oauthmcp\local\oauth\urls::oauth('prm');
            return $this->json_response(
                401,
                ['error' => 'Unauthorized'],
                ['WWW-Authenticate' => 'Bearer realm="tool_oauthmcp", resource_metadata="' . $prm . '"']
            );
        }
        $this->bind_moodle_user($auth);

        if ($method === 'DELETE') {
            return $this->handle_delete($request, $auth);
        }
        return $this->handle_post($request, $auth);
    }

    /**
     * Handle session termination (DELETE with Mcp-Session-Id).
     *
     * @param ServerRequestInterface $request The request.
     * @param auth_result $auth Authenticated bearer.
     * @return ResponseInterface
     */
    private function handle_delete(ServerRequestInterface $request, auth_result $auth): ResponseInterface {
        $sid = trim($request->getHeaderLine(self::SESSION_HEADER));
        $session = $sid === '' ? null : $this->sessions->get($sid);
        if (!$session || (int)$session->userid !== $auth->userid) {
            return $this->json_response(404, ['error' => 'Unknown session']);
        }
        $this->sessions->delete($sid);
        return new Response(204);
    }

    /**
     * Handle a JSON-RPC POST.
     *
     * @param ServerRequestInterface $request The request.
     * @param auth_result $auth Authenticated bearer.
     * @return ResponseInterface
     */
    private function handle_post(ServerRequestInterface $request, auth_result $auth): ResponseInterface {
        try {
            $parsed = json_rpc::parse_body((string)$request->getBody());
        } catch (json_rpc_exception $e) {
            return $this->json_response(400, json_rpc::error(null, $e->getCode(), $e->getMessage()));
        }

        $requests = $parsed['requests'];
        $isinitialize = !$parsed['batch']
            && count($requests) === 1
            && $requests[0]->method === 'initialize';
        if ($isinitialize) {
            return $this->handle_initialize($requests[0], $auth);
        }

        $sid = trim($request->getHeaderLine(self::SESSION_HEADER));
        if ($sid === '') {
            return $this->json_response(
                400,
                json_rpc::error(null, json_rpc::INVALID_REQUEST, 'Missing Mcp-Session-Id header')
            );
        }
        $session = $this->sessions->get($sid);
        if (!$session || (int)$session->userid !== $auth->userid) {
            // Per spec the client re-initialises on 404.
            return $this->json_response(404, json_rpc::error(null, json_rpc::INVALID_REQUEST, 'Unknown session'));
        }
        $this->sessions->touch($session);

        $versionheader = trim($request->getHeaderLine(self::VERSION_HEADER));
        if ($versionheader !== '' && $versionheader !== $session->protocolversion) {
            return $this->json_response(
                400,
                json_rpc::error(null, json_rpc::INVALID_REQUEST, 'MCP-Protocol-Version does not match the session')
            );
        }

        // JSON-RPC batching exists in 2025-03-26 only.
        if ($parsed['batch'] && $session->protocolversion !== protocol::VERSION_2025_03_26) {
            return $this->json_response(
                400,
                json_rpc::error(null, json_rpc::INVALID_REQUEST, 'Batching is not supported by this protocol version')
            );
        }

        $responses = [];
        foreach ($requests as $rpcrequest) {
            if (!$rpcrequest->hasid) {
                $this->controller->notify($rpcrequest);
                continue;
            }
            $responses[] = $this->dispatch_safe($rpcrequest, $session, $auth);
        }

        if (empty($responses)) {
            // Notifications only: accepted, nothing to answer.
            return new Response(202);
        }

        $body = $parsed['batch'] ? $responses : $responses[0];
        return $this->json_response(200, $body);
    }

    /**
     * Handle initialize: negotiate the protocol version, mint a session.
     *
     * @param rpc_request $request The initialize request.
     * @param auth_result $auth Authenticated bearer.
     * @return ResponseInterface
     */
    private function handle_initialize(rpc_request $request, auth_result $auth): ResponseInterface {
        $requested = (string)($request->params['protocolVersion'] ?? '');
        $version = protocol::negotiate($requested);
        $session = $this->sessions->create($auth->userid, $auth->authmode, $version);

        $result = [
            'protocolVersion' => $version,
            'capabilities' => [
                'tools' => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name' => 'Moodle (tool_oauthmcp)',
                'version' => (string)get_config('tool_oauthmcp', 'version'),
            ],
            'instructions' => get_string('mcp_instructions', 'tool_oauthmcp'),
        ];

        if (!$request->hasid) {
            // An initialize notification is a protocol violation; still issue
            // nothing and accept, the client will fail on the missing result.
            return new Response(202, [self::SESSION_HEADER => $session->sid]);
        }

        return $this->json_response(
            200,
            json_rpc::result($request->id, $result),
            [self::SESSION_HEADER => $session->sid]
        );
    }

    /**
     * Dispatch one request, converting any throwable into a JSON-RPC error.
     *
     * @param rpc_request $request The request.
     * @param stdClass $session The session.
     * @param auth_result $auth Authenticated bearer.
     * @return array JSON-RPC response array.
     */
    private function dispatch_safe(rpc_request $request, stdClass $session, auth_result $auth): array {
        try {
            return $this->controller->dispatch($request, $session, $auth);
        } catch (\Throwable $e) {
            debugging('tool_oauthmcp: internal error during dispatch: ' . $e->getMessage(), DEBUG_DEVELOPER);
            return json_rpc::error($request->id, json_rpc::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * Run the bearer through the configured authenticator chain.
     *
     * @param ServerRequestInterface $request The request.
     * @return auth_result|null
     */
    private function authenticate(ServerRequestInterface $request): ?auth_result {
        $header = trim($request->getHeaderLine('Authorization'));
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            $bearer = $matches[1];
            foreach ($this->authenticators() as $authenticator) {
                $result = $authenticator->authenticate($bearer);
                if ($result !== null) {
                    return $result;
                }
            }
            return null;
        }

        // No Authorization header arrived. Accept a web-service token in the fallback header,
        // deliberately wstoken-only: OAuth access tokens are protocol-bound to the Authorization
        // header (RFC 6750) and OAuth clients cannot be told to send custom headers anyway. The
        // token passes the exact same validation chain, so nothing is weaker — only the envelope
        // differs.
        $alt = trim($request->getHeaderLine(self::TOKEN_HEADER));
        if ($alt !== '' && $this->wstoken_enabled()) {
            return (new wstoken_authenticator())->authenticate($alt);
        }
        return null;
    }

    /**
     * Build the authenticator chain from the authmode setting.
     *
     * @return \tool_oauthmcp\local\auth\bearer_authenticator_interface[]
     */
    private function authenticators(): array {
        $mode = (string)get_config('tool_oauthmcp', 'authmode');
        $chain = [];
        if ($mode === 'oauth' || $mode === 'both' || $mode === '') {
            $chain[] = new \tool_oauthmcp\local\auth\oauth_authenticator();
        }
        if ($this->wstoken_enabled()) {
            $chain[] = new wstoken_authenticator();
        }
        return $chain;
    }

    /**
     * Whether the authmode setting allows web-service token authentication.
     *
     * @return bool
     */
    private function wstoken_enabled(): bool {
        $mode = (string)get_config('tool_oauthmcp', 'authmode');
        return $mode === 'wstoken' || $mode === 'both' || $mode === '';
    }

    /**
     * Bind the authenticated user to the request globals.
     *
     * The bearer transport carries no cookies, so the sesskey CSRF check is
     * meaningless here — the production endpoint runs under WS_SERVER like
     * the core web service servers; ignoresesskey covers non-WS_SERVER
     * contexts (PHPUnit) the same way.
     *
     * @param auth_result $auth Authenticated bearer.
     * @return void
     */
    private function bind_moodle_user(auth_result $auth): void {
        global $USER;

        if ((int)$USER->id !== $auth->userid) {
            $user = core_user::get_user($auth->userid, '*', MUST_EXIST);
            \core\session\manager::set_user($user);
        }
        $USER->ignoresesskey = true;
    }

    /**
     * Whether the request's Origin header (if any) is acceptable.
     *
     * @param ServerRequestInterface $request The request.
     * @return bool
     */
    private function origin_allowed(ServerRequestInterface $request): bool {
        global $CFG;

        $origin = trim($request->getHeaderLine('Origin'));
        if ($origin === '') {
            return true;
        }

        $allowed = [self::origin_of($CFG->wwwroot)];
        $configured = (string)get_config('tool_oauthmcp', 'alloworigins');
        foreach (preg_split('/\R+/', $configured) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $allowed[] = self::origin_of($line);
            }
        }

        return in_array(self::origin_of($origin), array_filter($allowed), true);
    }

    /**
     * Normalise a URL to its origin (scheme://host[:port]).
     *
     * @param string $url Any URL.
     * @return string Empty string when unparsable.
     */
    private static function origin_of(string $url): string {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $origin = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }
        return $origin;
    }

    /**
     * Build a JSON response.
     *
     * @param int $status HTTP status code.
     * @param array|\stdClass $body Payload to encode.
     * @param string[] $headers Extra headers.
     * @return ResponseInterface
     */
    private function json_response(int $status, $body, array $headers = []): ResponseInterface {
        $headers['Content-Type'] = 'application/json; charset=utf-8';
        // The endpoint only ever returns JSON; forbid content-type sniffing.
        $headers['X-Content-Type-Options'] = 'nosniff';
        $encoded = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return new Response($status, $headers, (string)$encoded);
    }
}
