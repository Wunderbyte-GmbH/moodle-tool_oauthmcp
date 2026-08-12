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
 * MCP HTTP transport tests.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use context_system;
use GuzzleHttp\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use tool_oauthmcp\local\external_service_manager;
use tool_oauthmcp\local\mcp\mcp_http_handler;

/**
 * Drives mcp_http_handler with synthetic PSR-7 requests: transport guards,
 * bearer auth, session lifecycle, protocol negotiation and tool calls.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\mcp\mcp_http_handler
 * @covers \tool_oauthmcp\local\mcp\mcp_method_controller
 * @covers \tool_oauthmcp\local\mcp\session_manager
 * @covers \tool_oauthmcp\local\auth\wstoken_authenticator
 */
final class mcp_handler_test extends \advanced_testcase {
    /**
     * Provision an enabled MCP server, a permissioned user and a WS token.
     *
     * @param bool $grantcapability Whether to grant tool/oauthmcp:connect.
     * @return array [user record, token string, service record]
     */
    private function setup_environment(bool $grantcapability = true): array {
        global $DB;

        $this->resetAfterTest();
        set_config('enablewebservices', 1);
        set_config('enabled', 1, 'tool_oauthmcp');
        set_config('authmode', 'both', 'tool_oauthmcp');

        $user = $this->getDataGenerator()->create_user();
        if ($grantcapability) {
            $roleid = $this->getDataGenerator()->create_role();
            assign_capability('tool/oauthmcp:connect', CAP_ALLOW, $roleid, context_system::instance()->id);
            role_assign($roleid, $user->id, context_system::instance()->id);
        }

        // The dedicated service is created by the plugin's install hook.
        $service = $DB->get_record(
            'external_services',
            ['shortname' => external_service_manager::SERVICE_SHORTNAME],
            '*',
            MUST_EXIST
        );
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $service->id,
            'functionname' => 'core_webservice_get_site_info',
        ]);

        $token = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int)$user->id,
            context_system::instance()
        );

        return [$user, $token, $service];
    }

    /**
     * Build a PSR-7 request against the endpoint.
     *
     * @param array $headers HTTP headers.
     * @param array|null $body JSON body (encoded when not null).
     * @param string $method HTTP method.
     * @return ServerRequest
     */
    private function request(array $headers, ?array $body = null, string $method = 'POST'): ServerRequest {
        return new ServerRequest(
            $method,
            'https://www.example.com/admin/tool/oauthmcp/server.php',
            $headers,
            $body === null ? null : json_encode($body)
        );
    }

    /**
     * Run initialize and return [sid, decoded body].
     *
     * @param string $token Bearer token.
     * @param string $protocolversion Requested protocol version.
     * @return array
     */
    private function initialize(string $token, string $protocolversion = '2025-06-18'): array {
        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => $protocolversion,
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
            ]]
        ));
        $this->assertSame(200, $response->getStatusCode());
        $sid = $response->getHeaderLine('Mcp-Session-Id');
        $this->assertNotEmpty($sid);
        return [$sid, $this->decode($response)];
    }

    /**
     * Decode a JSON response body.
     *
     * @param ResponseInterface $response The response.
     * @return array
     */
    private function decode(ResponseInterface $response): array {
        return json_decode((string)$response->getBody(), true);
    }

    /**
     * Master switch off answers 503 before anything else.
     *
     * @return void
     */
    public function test_master_switch_off(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'tool_oauthmcp');

        $response = (new mcp_http_handler())->handle($this->request([]));
        $this->assertSame(503, $response->getStatusCode());
    }

    /**
     * Missing or malformed bearer yields 401 with a challenge.
     *
     * @return void
     */
    public function test_missing_bearer(): void {
        $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            [],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Bearer', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * A WS token in the X-Moodle-Token fallback header authenticates like a bearer.
     *
     * The fallback exists for hosting that strips the Authorization header before PHP
     * (FastCGI/CGI) — the whole point is that it works with no server configuration.
     *
     * @return void
     */
    public function test_token_header_fallback(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            [mcp_http_handler::TOKEN_HEADER => $token],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
                'protocolVersion' => '2025-06-18',
                'capabilities' => [],
                'clientInfo' => ['name' => 'phpunit', 'version' => '1'],
            ]]
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotEmpty($response->getHeaderLine('Mcp-Session-Id'));
    }

    /**
     * The fallback header is wstoken-only: with authmode oauth it must not authenticate.
     *
     * @return void
     */
    public function test_token_header_fallback_disabled_in_oauth_mode(): void {
        [, $token] = $this->setup_environment();
        set_config('authmode', 'oauth', 'tool_oauthmcp');

        $response = (new mcp_http_handler())->handle($this->request(
            [mcp_http_handler::TOKEN_HEADER => $token],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A present Authorization header wins: a garbage bearer is not rescued by a valid
     * fallback header, so a client cannot half-authenticate with mixed credentials.
     *
     * @return void
     */
    public function test_token_header_ignored_when_authorization_present(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . str_repeat('0', 32), mcp_http_handler::TOKEN_HEADER => $token],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A valid WS token without the connect capability is rejected.
     *
     * @return void
     */
    public function test_capability_required(): void {
        [, $token] = $this->setup_environment(false);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * Tokens of a foreign web service are rejected unless the admin opts in.
     *
     * @return void
     */
    public function test_foreign_service_token(): void {
        global $DB;

        [$user] = $this->setup_environment();

        $foreign = (object)[
            'name' => 'Foreign service',
            'shortname' => 'foreignsvc',
            'enabled' => 1,
            'restrictedusers' => 0,
            'downloadfiles' => 0,
            'uploadfiles' => 0,
            'timecreated' => time(),
        ];
        $foreign->id = $DB->insert_record('external_services', $foreign);
        $foreigntoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $foreign,
            (int)$user->id,
            context_system::instance()
        );

        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []];
        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $foreigntoken],
            $body
        ));
        $this->assertSame(401, $response->getStatusCode());

        set_config('wstokenanyservice', 1, 'tool_oauthmcp');
        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $foreigntoken],
            $body
        ));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * An Origin header not matching the site origin is refused.
     *
     * @return void
     */
    public function test_origin_mismatch(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Origin' => 'https://evil.example.org'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The site origin is accepted.
     *
     * @return void
     */
    public function test_origin_match(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Origin' => 'https://www.example.com'],
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []]
        ));
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * initialize negotiates supported versions and echoes the session id.
     *
     * @return void
     */
    public function test_initialize_and_ping(): void {
        [, $token] = $this->setup_environment();

        [$sid, $body] = $this->initialize($token);
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
        $this->assertArrayHasKey('tools', $body['result']['capabilities']);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->decode($response)['result']);
    }

    /**
     * Unknown requested versions negotiate down to the latest supported one.
     *
     * @return void
     */
    public function test_initialize_unknown_version(): void {
        [, $token] = $this->setup_environment();

        [, $body] = $this->initialize($token, '2099-01-01');
        $this->assertSame('2025-06-18', $body['result']['protocolVersion']);
    }

    /**
     * Requests without a known session yield 404 (client re-initialises).
     *
     * @return void
     */
    public function test_unknown_session(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => str_repeat('ab', 32)],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']
        ));
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * A session belongs to the authenticating user only.
     *
     * @return void
     */
    public function test_session_user_binding(): void {
        global $DB;

        [, $token, $service] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $other = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/oauthmcp:connect', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $other->id, context_system::instance()->id);
        $othertoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int)$other->id,
            context_system::instance()
        );

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $othertoken, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']
        ));
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Batching is rejected on 2025-06-18 and accepted on 2025-03-26.
     *
     * @return void
     */
    public function test_batching_per_version(): void {
        [, $token] = $this->setup_environment();

        [$sid] = $this->initialize($token, '2025-06-18');
        $batch = [
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'],
        ];
        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            $batch
        ));
        $this->assertSame(400, $response->getStatusCode());

        [$oldsid, $oldbody] = $this->initialize($token, '2025-03-26');
        $this->assertSame('2025-03-26', $oldbody['result']['protocolVersion']);
        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $oldsid],
            $batch
        ));
        $this->assertSame(200, $response->getStatusCode());
        $decoded = $this->decode($response);
        $this->assertCount(2, $decoded);
    }

    /**
     * A mismatching MCP-Protocol-Version header is refused.
     *
     * @return void
     */
    public function test_protocol_version_header_mismatch(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token, '2025-06-18');

        $response = (new mcp_http_handler())->handle($this->request(
            [
                'Authorization' => 'Bearer ' . $token,
                'Mcp-Session-Id' => $sid,
                'MCP-Protocol-Version' => '2025-03-26',
            ],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']
        ));
        $this->assertSame(400, $response->getStatusCode());
    }

    /**
     * Notifications get 202 with an empty body.
     *
     * @return void
     */
    public function test_notification_accepted(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']
        ));
        $this->assertSame(202, $response->getStatusCode());
        $this->assertSame('', (string)$response->getBody());
    }

    /**
     * tools/list serves the exposed external function; tools/call runs it.
     *
     * @return void
     */
    public function test_tools_list_and_call(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $headers = ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid];
        $response = (new mcp_http_handler())->handle($this->request(
            $headers,
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']
        ));
        $this->assertSame(200, $response->getStatusCode());
        $tools = $this->decode($response)['result']['tools'];
        $names = array_column($tools, 'name');
        $this->assertContains('core_webservice_get_site_info', $names);
        $tool = $tools[array_search('core_webservice_get_site_info', $names)];
        $this->assertSame('object', $tool['inputSchema']['type']);
        $this->assertArrayNotHasKey('scope', $tool);

        $response = (new mcp_http_handler())->handle($this->request(
            $headers,
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'core_webservice_get_site_info',
                'arguments' => [],
            ]]
        ));
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->decode($response)['result'];
        $this->assertFalse($result['isError']);
        $this->assertNotEmpty($result['content'][0]['text']);
        $this->assertArrayHasKey('sitename', $result['structuredContent']);
    }

    /**
     * Unknown tools produce an isError tool result, not a JSON-RPC error.
     *
     * @return void
     */
    public function test_unknown_tool_is_tool_error(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
                'name' => 'core_nope_does_not_exist',
                'arguments' => [],
            ]]
        ));
        $this->assertSame(200, $response->getStatusCode());
        $result = $this->decode($response)['result'];
        $this->assertTrue($result['isError']);
        $this->assertContains('TOOL_UNKNOWN', $result['structuredContent']['issue_codes']);
    }

    /**
     * The per-user tool call rate limit trips.
     *
     * @return void
     */
    public function test_rate_limit(): void {
        [, $token] = $this->setup_environment();
        set_config('ratelimittools', 1, 'tool_oauthmcp');
        [$sid] = $this->initialize($token);

        $headers = ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid];
        $call = static function (int $id) {
            return ['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => [
                'name' => 'core_webservice_get_site_info',
                'arguments' => [],
            ]];
        };

        $first = (new mcp_http_handler())->handle($this->request($headers, $call(2)));
        $this->assertFalse($this->decode($first)['result']['isError']);

        $second = (new mcp_http_handler())->handle($this->request($headers, $call(3)));
        $result = $this->decode($second)['result'];
        $this->assertTrue($result['isError']);
        $this->assertContains('MCP_RATE_LIMITED', $result['structuredContent']['issue_codes']);
    }

    /**
     * Unknown methods yield JSON-RPC method-not-found.
     *
     * @return void
     */
    public function test_method_not_found(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list']
        ));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(-32601, $this->decode($response)['error']['code']);
    }

    /**
     * DELETE terminates the session; subsequent use yields 404.
     *
     * @return void
     */
    public function test_delete_session(): void {
        [, $token] = $this->setup_environment();
        [$sid] = $this->initialize($token);

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            null,
            'DELETE'
        ));
        $this->assertSame(204, $response->getStatusCode());

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token, 'Mcp-Session-Id' => $sid],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping']
        ));
        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * Unsupported HTTP methods answer 405 with an Allow header.
     *
     * @return void
     */
    public function test_unsupported_http_method(): void {
        [, $token] = $this->setup_environment();

        $response = (new mcp_http_handler())->handle($this->request(
            ['Authorization' => 'Bearer ' . $token],
            null,
            'GET'
        ));
        $this->assertSame(405, $response->getStatusCode());
        $this->assertStringContainsString('POST', $response->getHeaderLine('Allow'));
    }

    /**
     * Malformed JSON bodies answer 400 with a JSON-RPC parse error.
     *
     * @return void
     */
    public function test_parse_error(): void {
        [, $token] = $this->setup_environment();

        $request = new ServerRequest(
            'POST',
            'https://www.example.com/admin/tool/oauthmcp/server.php',
            ['Authorization' => 'Bearer ' . $token],
            '{nope'
        );
        $response = (new mcp_http_handler())->handle($request);
        $this->assertSame(400, $response->getStatusCode());
        $this->assertSame(-32700, $this->decode($response)['error']['code']);
    }
}
