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
 * Security-audit regression tests (AUDIT_SCOPE.md section 14 gaps).
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
use tool_oauthmcp\local\registry\tool_registry;
use tool_oauthmcp\task\purge_expired;

/**
 * Closes the unit-testable gaps the audit scope calls out: DCR abuse and
 * privilege escalation, cross-client revocation IDOR, refresh replay, token
 * hash-at-rest, the purge task, response headers, and per-tool capability
 * denial. Complements oauth_flow_test / mcp_handler_test.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\oauth\dcr_service
 * @covers \tool_oauthmcp\local\oauth\revoke_endpoint
 * @covers \tool_oauthmcp\local\oauth\token_endpoint
 * @covers \tool_oauthmcp\local\oauth\token_service
 * @covers \tool_oauthmcp\task\purge_expired
 */
final class security_audit_test extends \advanced_testcase {
    /** @var string Redirect URI used by test clients. */
    private const REDIRECT = 'https://client.example/callback';

    /**
     * Enable the server and return a permissioned user.
     *
     * @return \stdClass
     */
    private function setup_environment(): \stdClass {
        $this->resetAfterTest();
        set_config('enabled', 1, 'tool_oauthmcp');
        set_config('authmode', 'both', 'tool_oauthmcp');
        set_config('dcrenabled', 1, 'tool_oauthmcp');
        token_request_context::reset();
        vendor_loader::load();

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/oauthmcp:connect', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);
        return $user;
    }

    /**
     * Register a DCR client and return its metadata.
     *
     * @param array $overrides RFC 7591 overrides.
     * @param string $ip Requesting IP.
     * @return array
     */
    private function register_client(array $overrides = [], string $ip = '203.0.113.7'): array {
        $metadata = array_merge([
            'client_name' => 'Test connector',
            'redirect_uris' => [self::REDIRECT],
        ], $overrides);
        return (new dcr_service())->register($metadata, $ip);
    }

    /**
     * Issue an access token to a user for a client via the full grant.
     *
     * @param string $clientid Client id.
     * @param int $userid User id.
     * @return array Decoded token response.
     */
    private function issue_token(string $clientid, int $userid): array {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $server = server_factory::authorization_server();
        $authrequest = $server->validateAuthorizationRequest(
            (new ServerRequest('GET', urls::oauth('authorize')))->withQueryParams([
                'response_type' => 'code',
                'client_id' => $clientid,
                'redirect_uri' => self::REDIRECT,
                'scope' => 'mcp:read mcp:write',
                'state' => 's',
                'code_challenge' => $challenge,
                'code_challenge_method' => 'S256',
            ])
        );
        $authrequest->setUser(new user_entity($userid));
        $authrequest->setAuthorizationApproved(true);
        $response = $server->completeAuthorizationRequest($authrequest, new Response());
        parse_str((string)parse_url($response->getHeaderLine('Location'), PHP_URL_QUERY), $params);

        $tokenrequest = (new ServerRequest('POST', urls::oauth('token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody([
            'grant_type' => 'authorization_code',
            'client_id' => $clientid,
            'redirect_uri' => self::REDIRECT,
            'code' => $params['code'],
            'code_verifier' => $verifier,
        ]);
        $tokenresponse = (new token_endpoint())->handle($tokenrequest);
        return json_decode((string)$tokenresponse->getBody(), true);
    }

    /**
     * §3 — anonymous DCR is quota-capped against client-flood DoS.
     *
     * @return void
     */
    public function test_dcr_quota_cap(): void {
        $this->setup_environment();
        set_config('dcrquota', 2, 'tool_oauthmcp');

        $this->assertSame(201, $this->register_client([], '203.0.113.1')['status']);
        $this->assertSame(201, $this->register_client([], '203.0.113.2')['status']);
        $third = $this->register_client([], '203.0.113.3');
        $this->assertSame(503, $third['status']);
    }

    /**
     * §3 — anonymous DCR is per-IP rate limited.
     *
     * @return void
     */
    public function test_dcr_rate_limit(): void {
        $this->setup_environment();
        set_config('ratelimitregister', 2, 'tool_oauthmcp');

        $this->assertSame(201, $this->register_client([], '198.51.100.9')['status']);
        $this->assertSame(201, $this->register_client([], '198.51.100.9')['status']);
        $this->assertSame(429, $this->register_client([], '198.51.100.9')['status']);
    }

    /**
     * §3 — a client cannot register itself elevated metadata (scope, grant
     * types, auth method, or a non-https redirect).
     *
     * @return void
     */
    public function test_dcr_privilege_escalation_rejected(): void {
        $this->setup_environment();

        $this->assertSame(400, $this->register_client(['scope' => 'mcp:read mcp:write admin'])['status']);
        $this->assertSame(400, $this->register_client(['grant_types' => ['client_credentials']])['status']);
        $this->assertSame(400, $this->register_client(['grant_types' => ['password']])['status']);
        $this->assertSame(400, $this->register_client(['token_endpoint_auth_method' => 'private_key_jwt'])['status']);
        $this->assertSame(400, $this->register_client(['redirect_uris' => ['http://evil.example/cb']])['status']);
    }

    /**
     * §4/§9 — a client may only revoke its own tokens (cross-client IDOR).
     *
     * @return void
     */
    public function test_revoke_cross_client_idor(): void {
        $user = $this->setup_environment();
        $clienta = $this->register_client([], '203.0.113.20')['body'];
        $clientb = $this->register_client(['client_name' => 'Other'], '203.0.113.21')['body'];
        $tokens = $this->issue_token($clienta['client_id'], (int)$user->id);

        // Client B tries to revoke client A's access token.
        $request = (new ServerRequest('POST', urls::oauth('revoke'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody([
            'client_id' => $clientb['client_id'],
            'token' => $tokens['access_token'],
        ]);
        $status = (new revoke_endpoint())->handle($request)->getStatusCode();

        // RFC 7009 still answers 200, but the foreign token must stay valid.
        $this->assertSame(200, $status);
        $this->assertNotNull((new oauth_authenticator())->authenticate($tokens['access_token']));

        // The owning client can revoke it.
        $request = (new ServerRequest('POST', urls::oauth('revoke'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody([
            'client_id' => $clienta['client_id'],
            'token' => $tokens['access_token'],
        ]);
        (new revoke_endpoint())->handle($request);
        $this->assertNull((new oauth_authenticator())->authenticate($tokens['access_token']));
    }

    /**
     * §2 — redeeming a rotated-out refresh token revokes the family.
     *
     * @return void
     */
    public function test_refresh_replay_revokes_family(): void {
        $user = $this->setup_environment();
        $client = $this->register_client([], '203.0.113.30')['body'];
        $tokens = $this->issue_token($client['client_id'], (int)$user->id);

        $refresh = static function (string $token) use ($client) {
            $request = (new ServerRequest('POST', urls::oauth('token'), [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]))->withParsedBody([
                'grant_type' => 'refresh_token',
                'client_id' => $client['client_id'],
                'refresh_token' => $token,
            ]);
            $response = (new token_endpoint())->handle($request);
            return [$response->getStatusCode(), json_decode((string)$response->getBody(), true)];
        };

        [$status, $rotated] = $refresh($tokens['refresh_token']);
        $this->assertSame(200, $status);

        // Replaying the original (now rotated-out) refresh token fails...
        [$replaystatus] = $refresh($tokens['refresh_token']);
        $this->assertGreaterThanOrEqual(400, $replaystatus);

        // ...and it has killed the whole family, so the rotated successor dies too.
        $this->assertNull((new oauth_authenticator())->authenticate($rotated['access_token']));
    }

    /**
     * §6 — access tokens are stored hashed; the plaintext bearer never lands
     * in any column of the token row.
     *
     * @return void
     */
    public function test_token_hash_at_rest(): void {
        global $DB;

        $user = $this->setup_environment();
        $client = $this->register_client([], '203.0.113.40')['body'];
        $tokens = $this->issue_token($client['client_id'], (int)$user->id);
        $bearer = $tokens['access_token'];

        $row = $DB->get_record('tool_oauthmcp_token', ['tokenhash' => token_service::hash($bearer)], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $bearer), $row->tokenhash);
        foreach ((array)$row as $value) {
            $this->assertStringNotContainsString(
                $bearer,
                (string)$value,
                'The plaintext bearer must not be stored in any token column'
            );
        }

        // High-entropy opaque format: prefix + hex identifier.
        $this->assertStringStartsWith(token_service::OPAQUE_PREFIX, $bearer);
        $this->assertGreaterThanOrEqual(40, strlen(substr($bearer, strlen(token_service::OPAQUE_PREFIX))));
    }

    /**
     * §2 — the token endpoint accepts only the two configured grant types.
     *
     * @return void
     */
    public function test_grant_type_whitelist(): void {
        $this->setup_environment();

        foreach (['client_credentials', 'password', 'implicit'] as $granttype) {
            $request = (new ServerRequest('POST', urls::oauth('token'), [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ]))->withParsedBody(['grant_type' => $granttype]);
            $status = (new token_endpoint())->handle($request)->getStatusCode();
            $this->assertGreaterThanOrEqual(400, $status, "grant_type {$granttype} must be rejected");
        }
    }

    /**
     * §6/§11 — the purge task removes expired artefacts and keeps live ones.
     *
     * @return void
     */
    public function test_purge_expired_task(): void {
        global $DB;

        $user = $this->setup_environment();
        $now = time();

        // Expired session, expired auth code, long-expired token; and live peers.
        $DB->insert_record('tool_oauthmcp_session', (object)[
            'sid' => str_repeat('a', 64), 'userid' => $user->id, 'authmode' => 'oauth',
            'tokenid' => null, 'protocolversion' => '2025-06-18',
            'timecreated' => $now - 3 * DAYSECS, 'lastseen' => $now - 3 * DAYSECS,
        ]);
        $DB->insert_record('tool_oauthmcp_session', (object)[
            'sid' => str_repeat('b', 64), 'userid' => $user->id, 'authmode' => 'oauth',
            'tokenid' => null, 'protocolversion' => '2025-06-18',
            'timecreated' => $now, 'lastseen' => $now,
        ]);
        $DB->insert_record('tool_oauthmcp_authcode', (object)[
            'identifier' => 'expiredcode', 'clientdbid' => 0, 'userid' => $user->id,
            'expires' => $now - 120, 'revoked' => 0, 'timecreated' => $now - 300,
        ]);
        $DB->insert_record('tool_oauthmcp_token', (object)[
            'tokenhash' => str_repeat('c', 64), 'identifier' => 'oldtoken', 'clientdbid' => 0,
            'userid' => $user->id, 'scopes' => 'mcp:read', 'resourceuri' => null, 'authcode' => null,
            'expires' => $now - 30 * DAYSECS, 'revoked' => 0, 'timecreated' => $now - 31 * DAYSECS, 'lastused' => 0,
        ]);
        $DB->insert_record('tool_oauthmcp_token', (object)[
            'tokenhash' => str_repeat('d', 64), 'identifier' => 'livetoken', 'clientdbid' => 0,
            'userid' => $user->id, 'scopes' => 'mcp:read', 'resourceuri' => null, 'authcode' => null,
            'expires' => $now + HOURSECS, 'revoked' => 0, 'timecreated' => $now, 'lastused' => 0,
        ]);

        (new purge_expired())->execute();

        $this->assertFalse($DB->record_exists('tool_oauthmcp_session', ['sid' => str_repeat('a', 64)]));
        $this->assertTrue($DB->record_exists('tool_oauthmcp_session', ['sid' => str_repeat('b', 64)]));
        $this->assertFalse($DB->record_exists('tool_oauthmcp_authcode', ['identifier' => 'expiredcode']));
        $this->assertFalse($DB->record_exists('tool_oauthmcp_token', ['identifier' => 'oldtoken']));
        $this->assertTrue($DB->record_exists('tool_oauthmcp_token', ['identifier' => 'livetoken']));
    }

    /**
     * §5/§7 — the 401 challenge carries resource_metadata and responses forbid
     * content-type sniffing.
     *
     * @return void
     */
    public function test_response_security_headers(): void {
        $this->setup_environment();

        $response = (new mcp_http_handler())->handle(new ServerRequest(
            'POST',
            urls::resource(),
            ['Authorization' => 'Bearer moamcp_bogus', 'Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('resource_metadata', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
    }

    /**
     * §8 — a user lacking a tool's native capability is refused execution,
     * as an isError result rather than a silent run.
     *
     * @return void
     */
    public function test_low_privilege_tool_denied(): void {
        global $DB, $USER;

        $this->setup_environment();
        set_config('enablewebservices', 1);

        // Expose a function that requires capabilities the plain user lacks.
        $service = $DB->get_record(
            'external_services',
            ['shortname' => local\external_service_manager::SERVICE_SHORTNAME],
            '*',
            MUST_EXIST
        );
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $service->id, 'functionname' => 'core_user_create_users',
        ]);

        $lowuser = $this->getDataGenerator()->create_user();
        $this->setUser($lowuser);
        $USER->ignoresesskey = true;

        $result = tool_registry::create()->call_tool(
            'core_user_create_users',
            ['users' => [['username' => 'x', 'password' => 'Aa1!aaaa', 'firstname' => 'x',
                'lastname' => 'y', 'email' => 'x@example.com']]],
            (int)$lowuser->id,
            context_system::instance()->id,
            [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE],
            'k'
        );

        $this->assertTrue($result['isError']);
    }

    /**
     * §7/§11 — the endpoint refuses plain HTTP unless the dev flag is set.
     *
     * @return void
     */
    public function test_https_enforced(): void {
        global $CFG;

        $this->setup_environment();
        $CFG->wwwroot = str_replace('https://', 'http://', $CFG->wwwroot);

        $request = new ServerRequest(
            'POST',
            $CFG->wwwroot . '/admin/tool/oauthmcp/server.php',
            ['Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        );
        $this->assertSame(403, (new mcp_http_handler())->handle($request)->getStatusCode());

        // The documented developer override lifts the guard.
        $CFG->tool_oauthmcp_allowhttp = 1;
        $this->assertNotSame(403, (new mcp_http_handler())->handle($request)->getStatusCode());
    }

    /**
     * §13 — a presented-but-rejected bearer triggers the auth_failed event;
     * a bare probe without a bearer does not.
     *
     * @return void
     */
    public function test_failed_auth_is_audited(): void {
        $this->setup_environment();

        $sink = $this->redirectEvents();
        (new mcp_http_handler())->handle(new ServerRequest(
            'POST',
            urls::resource(),
            ['Authorization' => 'Bearer moamcp_bogus', 'Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ));
        // A probe with no Authorization header must not be logged.
        (new mcp_http_handler())->handle(new ServerRequest(
            'POST',
            urls::resource(),
            ['Content-Type' => 'application/json'],
            json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => []])
        ));
        $events = array_values(array_filter($sink->get_events(), static function ($event) {
            return $event instanceof \tool_oauthmcp\event\auth_failed;
        }));
        $sink->close();

        $this->assertCount(1, $events);
        // The rejected token must never be recorded in the event.
        $this->assertStringNotContainsString('moamcp_bogus', json_encode($events[0]->get_data()));
    }

    /**
     * §1 — PKCE "plain" is refused at the library grant level, not only by
     * the authorize script (defence in depth).
     *
     * @return void
     */
    public function test_pkce_plain_rejected_at_grant(): void {
        $user = $this->setup_environment();
        $client = $this->register_client([], '203.0.113.50')['body'];

        $server = server_factory::authorization_server();
        $request = (new ServerRequest('GET', urls::oauth('authorize')))->withQueryParams([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => self::REDIRECT,
            'scope' => 'mcp:read',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'plain',
        ]);

        $this->expectException(\League\OAuth2\Server\Exception\OAuthServerException::class);
        $server->validateAuthorizationRequest($request);
    }

    /**
     * §9/§11 — the OAuth JSON endpoints forbid content-type sniffing.
     *
     * @return void
     */
    public function test_oauth_endpoints_nosniff(): void {
        $this->setup_environment();

        // Token endpoint: an invalid grant still carries the header.
        $tokenrequest = (new ServerRequest('POST', urls::oauth('token'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody(['grant_type' => 'authorization_code']);
        $this->assertSame(
            'nosniff',
            (new token_endpoint())->handle($tokenrequest)->getHeaderLine('X-Content-Type-Options')
        );

        // Revoke endpoint: an unauthenticated request still carries the header.
        $revokerequest = (new ServerRequest('POST', urls::oauth('revoke'), [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ]))->withParsedBody(['token' => 'whatever']);
        $this->assertSame(
            'nosniff',
            (new revoke_endpoint())->handle($revokerequest)->getHeaderLine('X-Content-Type-Options')
        );
    }
}
