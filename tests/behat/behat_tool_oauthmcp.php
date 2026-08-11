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
 * Behat step definitions for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// NOTE: no MOODLE_INTERNAL check here, this is a Behat step definition file.

require_once(__DIR__ . '/../../../../../lib/behat/behat_base.php');

/**
 * Steps that seed OAuth clients and grants and drive the authorization flow.
 *
 * @package    tool_oauthmcp
 * @category   test
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_tool_oauthmcp extends behat_base {
    /**
     * Enable the MCP server with OAuth and a permissive consent policy.
     *
     * @Given /^the MCP server is enabled$/
     * @return void
     */
    public function the_mcp_server_is_enabled(): void {
        set_config('enabled', 1, 'tool_oauthmcp');
        set_config('authmode', 'both', 'tool_oauthmcp');
        set_config('dcrenabled', 1, 'tool_oauthmcp');
        set_config('consentpolicy', 'allow_remember', 'tool_oauthmcp');
    }

    /**
     * Create a public OAuth client whose redirect URI points back at the site,
     * so the authorize flow can complete within Behat.
     *
     * @Given /^a public MCP OAuth client "(?P<name_string>[^"]*)" exists$/
     * @param string $name Client name.
     * @return void
     */
    public function a_public_mcp_oauth_client_exists(string $name): void {
        global $DB, $CFG;

        $DB->insert_record('tool_oauthmcp_client', (object)[
            'clientid' => 'behat_' . clean_param($name, PARAM_ALPHANUMEXT),
            'secrethash' => null,
            'name' => $name,
            'redirecturis' => json_encode([$CFG->wwwroot . '/']),
            'scopes' => 'mcp:read mcp:write',
            'authmethod' => 'none',
            'dcr' => 1,
            'enabled' => 1,
            'createdby' => null,
            'registrationip' => '203.0.113.1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Seed a consent plus a live access token for a user and client.
     *
     * @Given /^the user "(?P<user_string>[^"]*)" has an MCP grant for client "(?P<name_string>[^"]*)"$/
     * @param string $username The username.
     * @param string $name The client name.
     * @return void
     */
    public function the_user_has_an_mcp_grant_for_client(string $username, string $name): void {
        global $DB;

        $userid = (int)$DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        $client = $DB->get_record('tool_oauthmcp_client', ['name' => $name], '*', MUST_EXIST);

        $DB->insert_record('tool_oauthmcp_consent', (object)[
            'userid' => $userid, 'clientdbid' => $client->id, 'scopes' => 'mcp:read mcp:write',
            'remembered' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('tool_oauthmcp_token', (object)[
            'tokenhash' => hash('sha256', 'behattok' . $userid . $client->id),
            'identifier' => 'behattid' . $userid . $client->id,
            'clientdbid' => $client->id, 'userid' => $userid, 'scopes' => 'mcp:read mcp:write',
            'resourceuri' => null, 'authcode' => null, 'expires' => time() + 3600,
            'revoked' => 0, 'timecreated' => time(), 'lastused' => time(),
        ]);
    }

    /**
     * Visit the authorization endpoint with a valid PKCE request for a client.
     *
     * @When /^I open the MCP authorization page for client "(?P<name_string>[^"]*)"$/
     * @param string $name The client name.
     * @return void
     */
    public function i_open_the_mcp_authorization_page_for_client(string $name): void {
        global $DB, $CFG;

        $client = $DB->get_record('tool_oauthmcp_client', ['name' => $name], '*', MUST_EXIST);
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $params = http_build_query([
            'response_type' => 'code',
            'client_id' => $client->clientid,
            'redirect_uri' => $CFG->wwwroot . '/',
            'scope' => 'mcp:read mcp:write',
            'state' => 'behatstate',
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&');
        $this->execute(
            'behat_general::i_visit',
            ['/admin/tool/oauthmcp/oauth/authorize.php?' . $params]
        );
    }
}
