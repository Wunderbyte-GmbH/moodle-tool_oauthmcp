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
 * Language strings for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['accesstokenttl'] = 'Access token lifetime';
$string['accesstokenttl_desc'] = 'Lifetime of issued OAuth access tokens.';
$string['alloworigins'] = 'Additional allowed origins';
$string['alloworigins_desc'] = 'One origin per line (scheme://host[:port]). Requests carrying an Origin header must match the site origin or one of these. Leave empty to allow only the site origin.';
$string['authmode'] = 'Authentication mode';
$string['authmode_both'] = 'Both';
$string['authmode_desc'] = 'How MCP clients authenticate against the endpoint. "Web service token" accepts Moodle web service tokens as bearer tokens. "OAuth 2.1" accepts tokens issued by this plugin\'s authorization server. "Both" accepts either.';
$string['authmode_oauth'] = 'OAuth 2.1';
$string['authmode_wstoken'] = 'Web service token';
$string['client_add'] = 'Register a client manually';
$string['client_authmethod'] = 'Token endpoint authentication';
$string['client_authmethod_desc'] = '"None" registers a public client (PKCE only, the normal case for MCP clients); the secret methods register a confidential client.';
$string['client_enabled'] = 'Enabled';
$string['client_name'] = 'Client name';
$string['client_redirecturis'] = 'Redirect URIs';
$string['client_redirecturis_desc'] = 'One exact URI per line. Only https URIs are accepted (plus http on localhost while allowed for dynamic registration).';
$string['client_redirecturis_invalid'] = 'Invalid redirect URI: {$a}';
$string['client_secret_notice'] = 'Client secret (shown only once, store it now): {$a}';
$string['clients'] = 'OAuth clients';
$string['clients_created'] = 'Created';
$string['clients_dcr'] = 'DCR';
$string['clients_delete_confirm'] = 'Delete this client including all of its tokens and consents?';
$string['clients_desc'] = 'OAuth clients registered dynamically (RFC 7591) or manually. Disabling a client immediately blocks all of its tokens.';
$string['clients_tokens'] = 'Live tokens';
$string['clients_type'] = 'Type';
$string['clients_type_confidential'] = 'Confidential';
$string['clients_type_public'] = 'Public';
$string['consent_approve'] = 'Allow access';
$string['consent_deny'] = 'Deny';
$string['consent_intro'] = 'The application "{$a}" requests access to this site in your name with the following permissions:';
$string['consent_nocapability'] = 'Your account is not enabled for MCP access on this site. Ask your administrator for the "Connect to this site via MCP" capability.';
$string['consent_remember'] = 'Remember this decision for this application';
$string['consent_title'] = 'Authorise application access';
$string['consent_tools'] = 'This grant currently unlocks {$a} tools:';
$string['consentpolicy'] = 'Consent policy';
$string['consentpolicy_allow_remember'] = 'Users may remember their decision';
$string['consentpolicy_always_ask'] = 'Always show the consent screen';
$string['consentpolicy_desc'] = 'Whether users can skip the consent screen for applications they already approved.';
$string['dcrallowlocalhost'] = 'Allow localhost redirect URIs';
$string['dcrallowlocalhost_desc'] = 'Accept http://localhost and http://127.0.0.1 redirect URIs in dynamic client registration. Required by local clients such as Claude Code, which listen on a loopback port for the OAuth callback.';
$string['dcrenabled'] = 'Dynamic client registration';
$string['dcrenabled_desc'] = 'Allow anonymous client registration per RFC 7591. Required for claude.ai custom connectors. Registrations are rate-limited per IP and capped by the quota below.';
$string['dcrquota'] = 'Dynamic registration quota';
$string['dcrquota_desc'] = 'Maximum number of enabled dynamically registered clients. Further registrations are refused until stale ones are purged.';
$string['diag_dcr'] = 'Dynamic client registration endpoint';
$string['diag_enabled'] = 'MCP server enabled';
$string['diag_fail'] = 'FAIL';
$string['diag_https'] = 'Site served over HTTPS';
$string['diag_ok'] = 'OK';
$string['diag_server401'] = 'MCP endpoint issues an RFC 9728 challenge';
$string['diag_warn'] = 'CHECK';
$string['diag_wellknown_as'] = 'Webroot alias /.well-known/oauth-authorization-server';
$string['diag_wellknown_hint'] = 'The webroot aliases need rewrite rules outside Moodle; see the snippets shipped in .well-known-snippets/ and the README. Without them claude.ai custom connectors cannot discover the authorization server; header-authenticated clients are unaffected.';
$string['diag_wellknown_prm'] = 'Webroot alias /.well-known/oauth-protected-resource';
$string['diagnostics'] = 'MCP connectivity check';
$string['enabled'] = 'Enable MCP server';
$string['enabled_desc'] = 'Master switch. While disabled, the MCP endpoint answers every request with HTTP 503.';
$string['event_auth_failed'] = 'MCP authentication failed';
$string['event_client_registered'] = 'OAuth client registered';
$string['event_consent_given'] = 'OAuth consent given';
$string['event_token_issued'] = 'OAuth token issued';
$string['event_token_refused'] = 'OAuth token request refused';
$string['event_token_revoked'] = 'OAuth token revoked';
$string['event_tool_called'] = 'MCP tool called';
$string['exposedservices'] = 'Exposed web-service functions';
$string['exposedservices_info'] = 'The external functions assigned to the dedicated <strong>MCP server</strong> web service are published as MCP tools. To expose more functions, add them to that service the native way, under External services: <a href="{$a}">manage the MCP server functions</a>. Every exposed function is still gated by its own required capabilities when called, and individual tools can be disabled on the tool governance page. Tools that plugins contribute natively (for example agent skills) are exposed automatically and are not listed here.';
$string['mcp_disabled'] = 'The MCP server is disabled on this site.';
$string['mcp_error_rate_limited'] = 'Rate limit exceeded. Try again later.';
$string['mcp_error_scope_denied'] = 'The tool "{$a}" requires the mcp:write scope, which this token does not carry.';
$string['mcp_error_tool_disabled'] = 'The tool "{$a}" has been disabled by the site administrator.';
$string['mcp_error_tool_failed'] = 'Tool execution failed: {$a}';
$string['mcp_error_unknown_tool'] = 'Unknown tool: {$a}';
$string['mcp_instructions'] = 'This server exposes Moodle site functionality as tools. All calls run as the authenticated Moodle user with their normal permissions. Tools marked as destructive change site data; call them only after the user has confirmed the concrete action.';
$string['mcpsessionttl'] = 'MCP session lifetime';
$string['mcpsessionttl_desc'] = 'Idle MCP protocol sessions are discarded after this period and the client has to reinitialise.';
$string['oauthheading'] = 'OAuth 2.1 authorization server';
$string['oauthheading_desc'] = 'Settings of the built-in authorization server that lets remote MCP clients (including claude.ai custom connectors) connect via OAuth. Users authenticate with the normal Moodle login; tokens are opaque and can be revoked on the "Connected MCP apps" profile page.';
$string['oauthmcp:connect'] = 'Connect to this site via MCP';
$string['oauthmcp:manageclients'] = 'Manage OAuth/MCP client registrations';
$string['pluginname'] = 'MCP server (OAuth 2.1)';
$string['privacy:metadata:core_event'] = 'The plugin writes OAuth and tool-call audit events to the standard log store.';
$string['privacy:metadata:tool_oauthmcp_authcode'] = 'Short-lived OAuth authorization codes issued to the user during the login flow.';
$string['privacy:metadata:tool_oauthmcp_authcode:clientdbid'] = 'The client the code was issued to.';
$string['privacy:metadata:tool_oauthmcp_authcode:timecreated'] = 'When the code was issued.';
$string['privacy:metadata:tool_oauthmcp_authcode:userid'] = 'The user the code was issued for.';
$string['privacy:metadata:tool_oauthmcp_client'] = 'OAuth clients created manually by an administrator (the creator is recorded).';
$string['privacy:metadata:tool_oauthmcp_client:createdby'] = 'The administrator who created the client.';
$string['privacy:metadata:tool_oauthmcp_client:name'] = 'The client name.';
$string['privacy:metadata:tool_oauthmcp_client:registrationip'] = 'The IP address the client was registered from.';
$string['privacy:metadata:tool_oauthmcp_client:timecreated'] = 'When the client was created.';
$string['privacy:metadata:tool_oauthmcp_consent'] = 'Records that a user granted an OAuth client access to the site in their name.';
$string['privacy:metadata:tool_oauthmcp_consent:clientdbid'] = 'The client the consent was granted to.';
$string['privacy:metadata:tool_oauthmcp_consent:scopes'] = 'The scopes the user consented to.';
$string['privacy:metadata:tool_oauthmcp_consent:timecreated'] = 'When consent was first granted.';
$string['privacy:metadata:tool_oauthmcp_consent:userid'] = 'The user who granted consent.';
$string['privacy:metadata:tool_oauthmcp_refresh'] = 'OAuth refresh tokens issued to the user.';
$string['privacy:metadata:tool_oauthmcp_refresh:clientdbid'] = 'The client the token was issued to.';
$string['privacy:metadata:tool_oauthmcp_refresh:timecreated'] = 'When the token was issued.';
$string['privacy:metadata:tool_oauthmcp_refresh:userid'] = 'The user the token was issued for.';
$string['privacy:metadata:tool_oauthmcp_session'] = 'Active MCP protocol sessions for the user.';
$string['privacy:metadata:tool_oauthmcp_session:lastseen'] = 'When the session was last used.';
$string['privacy:metadata:tool_oauthmcp_session:timecreated'] = 'When the session was opened.';
$string['privacy:metadata:tool_oauthmcp_session:userid'] = 'The user the session belongs to.';
$string['privacy:metadata:tool_oauthmcp_token'] = 'OAuth access tokens issued to the user (stored hashed).';
$string['privacy:metadata:tool_oauthmcp_token:clientdbid'] = 'The client the token was issued to.';
$string['privacy:metadata:tool_oauthmcp_token:lastused'] = 'When the token was last used.';
$string['privacy:metadata:tool_oauthmcp_token:scopes'] = 'The scopes the token carries.';
$string['privacy:metadata:tool_oauthmcp_token:timecreated'] = 'When the token was issued.';
$string['privacy:metadata:tool_oauthmcp_token:userid'] = 'The user the token was issued for.';
$string['ratelimitregister'] = 'Registration rate limit';
$string['ratelimitregister_desc'] = 'Maximum dynamic client registrations per IP per hour.';
$string['ratelimittoken'] = 'Token endpoint rate limit';
$string['ratelimittoken_desc'] = 'Maximum token requests per IP per minute.';
$string['ratelimittools'] = 'Tool call rate limit';
$string['ratelimittools_desc'] = 'Maximum number of MCP tool calls per user per minute. 0 disables the limit.';
$string['refreshtokenttl'] = 'Refresh token lifetime';
$string['refreshtokenttl_desc'] = 'Lifetime of issued OAuth refresh tokens. Refresh tokens rotate on every use; reuse of a rotated-out token revokes the whole grant.';
$string['scope_read_desc'] = 'Read information from the site in your name (mcp:read)';
$string['scope_write_desc'] = 'Change site data in your name (mcp:write)';
$string['settingspage'] = 'MCP server settings';
$string['task_purge_expired'] = 'Purge expired MCP sessions and tokens';
$string['toolgovernance'] = 'MCP tool governance';
$string['toolgovernance_desc'] = 'Every tool the MCP server currently publishes. Disabled tools disappear from listings and refuse calls. Tools marked read-only are available to tokens carrying only the mcp:read scope.';
$string['toolgovernance_enabled'] = 'Enabled';
$string['toolgovernance_name'] = 'Tool';
$string['toolgovernance_readonly'] = 'Read-only';
$string['toolgovernance_source'] = 'Source';
$string['toolgovernance_updated'] = 'Tool settings updated.';
$string['userapps'] = 'Connected MCP apps';
$string['userapps_client'] = 'Application';
$string['userapps_first'] = 'First authorised';
$string['userapps_lastused'] = 'Last used';
$string['userapps_none'] = 'No applications are connected to your account.';
$string['userapps_revoke'] = 'Revoke access';
$string['userapps_revoked'] = 'Access revoked.';
$string['userapps_scopes'] = 'Permissions';
$string['wstokenanyservice'] = 'Accept tokens of any web service';
$string['wstokenanyservice_desc'] = 'By default only tokens issued for the plugin\'s own "MCP server (tool_oauthmcp)" service are accepted as bearer tokens. Enable this to accept valid tokens of any enabled web service (the connect capability is still required).';
