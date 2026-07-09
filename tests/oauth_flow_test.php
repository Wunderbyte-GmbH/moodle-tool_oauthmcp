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
 * OAuth 2.1 flow tests: happy path and every abuse path.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use context_system;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\auth\oauth_authenticator;
use tool_oauthmcp\local\mcp\mcp_http_handler;
use tool_oauthmcp\local\oauth\dcr_service;
use tool_oauthmcp\local\oauth\entities\user_entity;
use tool_oauthmcp\local\oauth\revoke_endpoint;
use tool_oauthmcp\local\oauth\server_factory;
use tool_oauthmcp\local\oauth\token_endpoint;
use tool_oauthmcp\local\oauth\token_request_context;
use tool_oauthmcp\local\oauth\token_service;
use tool_oauthmcp\local\oauth\urls;
use tool_oauthmcp\local\oauth\vendor_loader;

/**
 * Drives the authorization server through the library and the PSR-7
 * endpoint handlers — no HTTP involved, every branch asserted.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\oauth\token_endpoint
 * @covers \tool_oauthmcp\local\oauth\dcr_service
 * @covers \tool_oauthmcp\local\oauth\revoke_endpoint
 * @covers \tool_oauthmcp\local\oauth\token_service
 * @covers \tool_oauthmcp\local\auth\oauth_authenticator
 * @covers \tool_oauthmcp\local\oauth\server_factory
 */
final class oauth_flow_test extends \advanced_testcase {
    /** @var string Redirect URI used by the test client. */
    private const REDIRECT = 'https://client.example/callback';

    /**
     * Common fixture: enabled plugin, permissioned user.
     *
     * @return \stdClass The acting user.
     */
    private function setup_environment(): \stdClass {
        $this->resetAfterTest();
        set_config('enabled', 1, 'tool_oauthmcp');
        set_config('authmode', 'both', 'tool_oauthmcp');
        token_request_context::reset();
        vendor_loader::load();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/oauthmcp:connect', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);
        return $user;
    }

    /**
     * Register a client via DCR and return its metadata.
     *
     * @param array $overrides RFC 7591 metadata overrides.
     * @return array Registration response body.
     */
    private function register_client(array $overrides = []): array {
        $metadata = array_merge([
            'client_name' => 'Test connector',
            'redirect_uris' => [self::REDIRECT],
        ], $overrides);
        $result = (new dcr_service())->register($metadata, '203.0.113.7');
        $this->assertSame(201, $result['status'], json_encode($result['body']));
        return $result['body'];
    }

    /**
     * Run the authorize step (library level) and return the auth code.
     *
     * @param string $clientid Client id.
     * @param int $userid Approving user id.
     * @param string $verifier PKCE verifier.
     * @param string $scope Requested scopes.
     * @return string The authorization code.
     */
    private function authorize(string $clientid, int $userid, string $verifier, string $scope = 'mcp:read mcp:write'): string {
        $server = server_factory::authorization_server();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $request = (new ServerRequest('GET', urls::oauth('authorize')))->withQueryParams([
            'response_type' => 'code',
            'client_id' => $clientid,
            'redirect_uri' => self::REDIRECT,
            'scope' => $scope,
            'state' => 'state123',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ]);
        $authrequest = $server->validateAuthorizationRequest($request);
        $authrequest->setUser(new user_entity($userid));
        $authrequest->setAuthorizationApproved(true);
        $response = $server->completeAuthorizationRequest($authrequest, new Response());

        $location = $response->getHeaderLine('Location');
        $this->assertStringContainsString('code=', $location);
        $this->assertStringContainsString('state=state123', $location);
        parse_str((string)parse_url($location, PHP_URL_QUERY), $params);
        return (string)$params['code'];
    }

    /**
     * Call the token endpoint handler.
     *
     * @param array $body Form body.
     * @return array [status, decoded body]
     */
    private function token_request(array $body): array {
        $request = (new ServerRequest('POST', urls::oauth('token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody($body);
        $response = (new token_endpoint())->handle($request);
        return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
    }

    /**
     * Redeem an auth code for tokens.
     *
     * @param string $clientid Client id.
     * @param string $code Auth code.
     * @param string $verifier PKCE verifier.
     * @param array $extra Extra body params.
     * @return array [status, decoded body]
     */
    private function redeem(string $clientid, string $code, string $verifier, array $extra = []): array {
        return $this->token_request(array_merge([
            'grant_type' => 'authorization_code',
            'client_id' => $clientid,
            'redirect_uri' => self::REDIRECT,
            'code' => $code,
            'code_verifier' => $verifier,
        ], $extra));
    }

    /**
     * Full happy path: DCR, authorize, redeem, use, refresh, detect reuse.
     *
     * @return void
     */
    public function test_full_flow_public_client(): void {
        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $tokens] = $this->redeem($client['client_id'], $code, $verifier);
        $this->assertSame(200, $status, json_encode($tokens));
        $this->assertStringStartsWith(token_service::OPAQUE_PREFIX, $tokens['access_token']);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertGreaterThan(0, $tokens['expires_in']);

        // The opaque bearer authenticates with both scopes.
        $auth = (new oauth_authenticator())->authenticate($tokens['access_token']);
        $this->assertNotNull($auth);
        $this->assertSame((int)$user->id, $auth->userid);
        $this->assertEqualsCanonicalizing([auth_result::SCOPE_READ, auth_result::SCOPE_WRITE], $auth->scopes);
        $this->assertSame('oauth', $auth->authmode);

        // End to end through the MCP transport.
        $response = (new mcp_http_handler())->handle(new ServerRequest(
            'POST',
            urls::resource(),
            ['Authorization' => 'Bearer ' . $tokens['access_token'], 'Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ));
        $this->assertSame(200, $response->getStatusCode());

        // Refresh rotates the pair.
        [$status, $refreshed] = $this->token_request([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertSame(200, $status, json_encode($refreshed));
        $this->assertNotSame($tokens['access_token'], $refreshed['access_token']);

        // Reusing the rotated-out refresh token kills the whole family.
        [$status] = $this->token_request([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertNull((new oauth_authenticator())->authenticate($refreshed['access_token']));
    }

    /**
     * A wrong PKCE verifier is rejected.
     *
     * @return void
     */
    public function test_pkce_verifier_mismatch(): void {
        $user = $this->setup_environment();
        $client = $this->register_client();

        $code = $this->authorize($client['client_id'], (int)$user->id, str_repeat('v', 43));
        [$status, $body] = $this->redeem($client['client_id'], $code, str_repeat('w', 43));
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertNotEmpty($body['error']);
    }

    /**
     * Redeeming a code twice revokes everything issued from it.
     *
     * @return void
     */
    public function test_code_replay_revokes_tokens(): void {
        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $tokens] = $this->redeem($client['client_id'], $code, $verifier);
        $this->assertSame(200, $status);
        $this->assertNotNull((new oauth_authenticator())->authenticate($tokens['access_token']));

        [$status] = $this->redeem($client['client_id'], $code, $verifier);
        $this->assertGreaterThanOrEqual(400, $status);
        $this->assertNull((new oauth_authenticator())->authenticate($tokens['access_token']));
    }

    /**
     * A redirect URI not registered for the client is refused.
     *
     * @return void
     */
    public function test_redirect_uri_mismatch(): void {
        $user = $this->setup_environment();
        $client = $this->register_client();
        vendor_loader::load();

        $server = server_factory::authorization_server();
        $request = (new ServerRequest('GET', urls::oauth('authorize')))->withQueryParams([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => 'https://evil.example/callback',
            'code_challenge' => str_repeat('c', 43),
            'code_challenge_method' => 'S256',
        ]);
        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $server->validateAuthorizationRequest($request);
    }

    /**
     * RFC 8707: foreign resource indicators are refused, ours is bound.
     *
     * @return void
     */
    public function test_resource_indicator(): void {
        global $DB;

        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $body] = $this->redeem($client['client_id'], $code, $verifier, [
            'resource' => 'https://other.example/mcp',
        ]);
        $this->assertSame(400, $status);
        $this->assertSame('invalid_target', $body['error']);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $tokens] = $this->redeem($client['client_id'], $code, $verifier, [
            'resource' => urls::resource(),
        ]);
        $this->assertSame(200, $status);
        $row = $DB->get_record(
            'tool_oauthmcp_token',
            ['tokenhash' => token_service::hash($tokens['access_token'])],
            '*',
            MUST_EXIST
        );
        $this->assertSame(urls::normalise(urls::resource()), $row->resourceuri);
        $this->assertNotNull((new oauth_authenticator())->authenticate($tokens['access_token']));
    }

    /**
     * The client's registered scopes cap what a grant can request.
     *
     * @return void
     */
    public function test_scope_ceiling(): void {
        $user = $this->setup_environment();
        $client = $this->register_client(['scope' => auth_result::SCOPE_READ]);
        $verifier = str_repeat('v', 43);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $tokens] = $this->redeem($client['client_id'], $code, $verifier);
        $this->assertSame(200, $status);

        $auth = (new oauth_authenticator())->authenticate($tokens['access_token']);
        $this->assertSame([auth_result::SCOPE_READ], $auth->scopes);
    }

    /**
     * DCR validation: schemes, fragments, quota, rate limit, localhost toggle.
     *
     * @return void
     */
    public function test_dcr_validation(): void {
        $this->setup_environment();
        $service = new dcr_service();

        $bad = $service->register(['client_name' => 'x', 'redirect_uris' => ['ftp://x.example/cb']], '203.0.113.8');
        $this->assertSame(400, $bad['status']);
        $this->assertSame('invalid_redirect_uri', $bad['body']['error']);

        $bad = $service->register(['client_name' => 'x', 'redirect_uris' => ['https://x.example/cb#frag']], '203.0.113.8');
        $this->assertSame(400, $bad['status']);

        $bad = $service->register(['client_name' => '', 'redirect_uris' => ['https://x.example/cb']], '203.0.113.8');
        $this->assertSame(400, $bad['status']);
        $this->assertSame('invalid_client_metadata', $bad['body']['error']);

        // Localhost toggle.
        set_config('dcrallowlocalhost', 0, 'tool_oauthmcp');
        $bad = $service->register(['client_name' => 'x', 'redirect_uris' => ['http://localhost:1455/cb']], '203.0.113.8');
        $this->assertSame(400, $bad['status']);
        set_config('dcrallowlocalhost', 1, 'tool_oauthmcp');
        $ok = $service->register(['client_name' => 'x', 'redirect_uris' => ['http://localhost:1455/cb']], '203.0.113.8');
        $this->assertSame(201, $ok['status']);

        // Quota: one live DCR client already exists from above.
        set_config('dcrquota', 1, 'tool_oauthmcp');
        $full = $service->register(['client_name' => 'y', 'redirect_uris' => [self::REDIRECT]], '203.0.113.9');
        $this->assertSame(503, $full['status']);
        set_config('dcrquota', 100, 'tool_oauthmcp');

        // Per-IP rate limit.
        set_config('ratelimitregister', 1, 'tool_oauthmcp');
        $limited = $service->register(['client_name' => 'z', 'redirect_uris' => [self::REDIRECT]], '203.0.113.8');
        $this->assertSame(429, $limited['status']);

        // DCR disabled entirely.
        set_config('dcrenabled', 0, 'tool_oauthmcp');
        $off = $service->register(['client_name' => 'x', 'redirect_uris' => [self::REDIRECT]], '203.0.113.10');
        $this->assertSame(403, $off['status']);
    }

    /**
     * Confidential clients get a secret and must present it at the token endpoint.
     *
     * @return void
     */
    public function test_confidential_client(): void {
        $user = $this->setup_environment();
        $client = $this->register_client(['token_endpoint_auth_method' => 'client_secret_post']);
        $this->assertNotEmpty($client['client_secret']);
        $verifier = str_repeat('v', 43);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status] = $this->redeem($client['client_id'], $code, $verifier);
        $this->assertGreaterThanOrEqual(400, $status);

        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [$status, $tokens] = $this->redeem($client['client_id'], $code, $verifier, [
            'client_secret' => $client['client_secret'],
        ]);
        $this->assertSame(200, $status);
        $this->assertNotEmpty($tokens['access_token']);
    }

    /**
     * RFC 7009 revocation for both token types.
     *
     * @return void
     */
    public function test_revocation_endpoint(): void {
        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);
        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [, $tokens] = $this->redeem($client['client_id'], $code, $verifier);

        $revoke = static function (array $body): int {
            $request = (new ServerRequest('POST', urls::oauth('revoke'), [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]))->withParsedBody($body);
            return (new revoke_endpoint())->handle($request)->getStatusCode();
        };

        // Unknown token: still 200 per RFC.
        $this->assertSame(200, $revoke([
            'client_id' => $client['client_id'],
            'token' => token_service::OPAQUE_PREFIX . str_repeat('0', 80),
        ]));

        // Access token revocation.
        $this->assertSame(200, $revoke([
            'client_id' => $client['client_id'],
            'token' => $tokens['access_token'],
        ]));
        $this->assertNull((new oauth_authenticator())->authenticate($tokens['access_token']));

        // Refresh token revocation kills the family: refreshing fails afterwards.
        $this->assertSame(200, $revoke([
            'client_id' => $client['client_id'],
            'token' => $tokens['refresh_token'],
        ]));
        [$status] = $this->token_request([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertGreaterThanOrEqual(400, $status);

        // Missing client auth.
        $this->assertSame(401, $revoke(['token' => 'whatever']));
    }

    /**
     * Expired and revoked tokens, suspended users: authenticator refuses.
     *
     * @return void
     */
    public function test_token_liveness(): void {
        global $DB;

        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);
        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [, $tokens] = $this->redeem($client['client_id'], $code, $verifier);
        $bearer = $tokens['access_token'];
        $authenticator = new oauth_authenticator();

        $this->assertNotNull($authenticator->authenticate($bearer));

        // Expiry.
        $DB->set_field(
            'tool_oauthmcp_token',
            'expires',
            time() - 10,
            ['tokenhash' => token_service::hash($bearer)]
        );
        \cache::make('tool_oauthmcp', 'oauthtokens')->purge();
        $this->assertNull($authenticator->authenticate($bearer));
        $DB->set_field(
            'tool_oauthmcp_token',
            'expires',
            time() + HOURSECS,
            ['tokenhash' => token_service::hash($bearer)]
        );

        // Suspended user.
        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        $this->assertNull($authenticator->authenticate($bearer));
        $DB->set_field('user', 'suspended', 0, ['id' => $user->id]);

        // Disabled client.
        $DB->set_field('tool_oauthmcp_client', 'enabled', 0, ['clientid' => $client['client_id']]);
        $this->assertNull($authenticator->authenticate($bearer));
    }

    /**
     * Self-service revocation removes every live grant of a user+client.
     *
     * @return void
     */
    public function test_selfservice_revocation(): void {
        global $DB;

        $user = $this->setup_environment();
        $client = $this->register_client();
        $verifier = str_repeat('v', 43);
        $code = $this->authorize($client['client_id'], (int)$user->id, $verifier);
        [, $tokens] = $this->redeem($client['client_id'], $code, $verifier);

        $clientrow = $DB->get_record('tool_oauthmcp_client', ['clientid' => $client['client_id']], '*', MUST_EXIST);
        token_service::revoke_all_for_user_client((int)$user->id, (int)$clientrow->id);

        $this->assertNull((new oauth_authenticator())->authenticate($tokens['access_token']));
        [$status] = $this->token_request([
            'grant_type' => 'refresh_token',
            'client_id' => $client['client_id'],
            'refresh_token' => $tokens['refresh_token'],
        ]);
        $this->assertGreaterThanOrEqual(400, $status);
    }

    /**
     * Token endpoint governance: disabled plugin and per-IP rate limit.
     *
     * @return void
     */
    public function test_token_endpoint_guards(): void {
        $this->setup_environment();

        set_config('ratelimittoken', 1, 'tool_oauthmcp');
        [$status] = $this->token_request(['grant_type' => 'authorization_code']);
        $this->assertNotSame(429, $status);
        [$status] = $this->token_request(['grant_type' => 'authorization_code']);
        $this->assertSame(429, $status);
        set_config('ratelimittoken', 0, 'tool_oauthmcp');

        set_config('enabled', 0, 'tool_oauthmcp');
        [$status, $body] = $this->token_request(['grant_type' => 'authorization_code']);
        $this->assertSame(503, $status);
        $this->assertSame('temporarily_unavailable', $body['error']);
    }

    /**
     * authmode=oauth removes the WS-token path from the MCP transport.
     *
     * @return void
     */
    public function test_authmode_oauth_only(): void {
        global $DB;

        $user = $this->setup_environment();
        set_config('enablewebservices', 1);
        set_config('authmode', 'oauth', 'tool_oauthmcp');

        $service = $DB->get_record(
            'external_services',
            ['shortname' => local\external_service_manager::SERVICE_SHORTNAME],
            '*',
            MUST_EXIST
        );
        $wstoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            (int)$user->id,
            context_system::instance()
        );

        $response = (new mcp_http_handler())->handle(new ServerRequest(
            'POST',
            urls::resource(),
            ['Authorization' => 'Bearer ' . $wstoken, 'Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ));
        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('resource_metadata', $response->getHeaderLine('WWW-Authenticate'));
    }

    /**
     * Discovery documents carry the required fields.
     *
     * @return void
     */
    public function test_discovery_metadata(): void {
        $this->setup_environment();

        $asmeta = local\oauth\metadata::authorization_server();
        $this->assertSame(urls::issuer(), $asmeta['issuer']);
        $this->assertSame(['code'], $asmeta['response_types_supported']);
        $this->assertSame(['S256'], $asmeta['code_challenge_methods_supported']);
        $this->assertArrayHasKey('registration_endpoint', $asmeta);
        set_config('dcrenabled', 0, 'tool_oauthmcp');
        $this->assertArrayNotHasKey(
            'registration_endpoint',
            local\oauth\metadata::authorization_server()
        );

        $prm = local\oauth\metadata::protected_resource();
        $this->assertSame(urls::resource(), $prm['resource']);
        $this->assertSame([urls::issuer()], $prm['authorization_servers']);
        $this->assertSame(['header'], $prm['bearer_methods_supported']);
    }
}
