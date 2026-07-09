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
 * OAuth authorization endpoint: Moodle login + consent.
 *
 * Authentication is whatever the site uses (require_login inherits manual,
 * LDAP, SAML, OIDC, MFA). The consent POST round-trips the original query
 * string and re-validates the authorization request statelessly.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_oauthmcp\form\consent_form;
use tool_oauthmcp\local\oauth\server_factory;
use tool_oauthmcp\local\oauth\urls;
use tool_oauthmcp\local\oauth\vendor_loader;
use tool_oauthmcp\local\registry\tool_registry;

require(__DIR__ . '/../../../../config.php');

require_login(null, false);

$systemcontext = context_system::instance();
$PAGE->set_url(new moodle_url('/admin/tool/oauthmcp/oauth/authorize.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('consent_title', 'tool_oauthmcp'));
$PAGE->set_heading(get_string('consent_title', 'tool_oauthmcp'));

if (!get_config('tool_oauthmcp', 'enabled')) {
    throw new moodle_exception('mcp_disabled', 'tool_oauthmcp');
}
if (!has_capability('tool/oauthmcp:connect', $systemcontext)) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('consent_nocapability', 'tool_oauthmcp'), 'error');
    echo $OUTPUT->footer();
    die;
}

vendor_loader::load();

/**
 * Emit a PSR-7 response (redirects and OAuth error responses) and stop.
 *
 * @param \Psr\Http\Message\ResponseInterface $response The response.
 * @return void
 */
function tool_oauthmcp_emit(\Psr\Http\Message\ResponseInterface $response): void {
    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header($name . ': ' . $value, false);
        }
    }
    echo (string)$response->getBody();
    die;
}

/**
 * Redirect back to the client with an OAuth error (RFC 6749 §4.1.2.1).
 *
 * @param string $redirecturi Validated redirect URI.
 * @param string $error Error code.
 * @param string $description Error description.
 * @param string|null $state Client state.
 * @return void
 */
function tool_oauthmcp_error_redirect(string $redirecturi, string $error, string $description, ?string $state): void {
    $params = ['error' => $error, 'error_description' => $description];
    if ($state !== null && $state !== '') {
        $params['state'] = $state;
    }
    $separator = (strpos($redirecturi, '?') === false) ? '?' : '&';
    redirect($redirecturi . $separator . http_build_query($params));
}

$ispost = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
if ($ispost) {
    // Rebuild the original authorize request from the round-tripped query string.
    $encoded = required_param('authparams', PARAM_RAW);
    $querystring = base64_decode($encoded, true);
    if ($querystring === false) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    parse_str($querystring, $queryparams);
    $psrrequest = (new \GuzzleHttp\Psr7\ServerRequest('GET', urls::oauth('authorize')))
        ->withQueryParams($queryparams);
    $authparams = $encoded;
} else {
    $psrrequest = \GuzzleHttp\Psr7\ServerRequest::fromGlobals();
    $queryparams = $psrrequest->getQueryParams();
    $authparams = base64_encode(http_build_query($queryparams));
}

$server = server_factory::authorization_server();
try {
    $authrequest = $server->validateAuthorizationRequest($psrrequest);
} catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
    // Invalid client/redirect URI errors must never redirect; the library
    // includes the redirect only when it is safe to do so.
    tool_oauthmcp_emit($e->generateHttpResponse(new \GuzzleHttp\Psr7\Response()));
}

$client = $authrequest->getClient();
$redirecturi = $authrequest->getRedirectUri();
if ($redirecturi === null) {
    $registered = $client->getRedirectUri();
    $redirecturi = is_array($registered) ? (string)reset($registered) : (string)$registered;
}
$state = $authrequest->getState();

// FR-OAUTH-2 hardening beyond the library defaults: PKCE with S256 is
// mandatory for every client, confidential ones included.
if ($authrequest->getCodeChallenge() === null) {
    tool_oauthmcp_error_redirect($redirecturi, 'invalid_request', 'code_challenge is required', $state);
}
if ($authrequest->getCodeChallengeMethod() !== 'S256') {
    tool_oauthmcp_error_redirect($redirecturi, 'invalid_request', 'code_challenge_method must be S256', $state);
}

// RFC 8707: a resource indicator, when present, must denote this server.
$resource = trim((string)($queryparams['resource'] ?? ''));
if ($resource !== '' && !urls::is_our_resource($resource)) {
    tool_oauthmcp_error_redirect($redirecturi, 'invalid_target', 'Unknown resource indicator', $state);
}

$requestedscopes = array_map(static function ($scope) {
    return $scope->getIdentifier();
}, $authrequest->getScopes());
$clientdbid = $client instanceof \tool_oauthmcp\local\oauth\entities\client_entity ? $client->get_dbid() : 0;

$policy = (string)get_config('tool_oauthmcp', 'consentpolicy');
$allowremember = $policy !== 'always_ask';
$consent = $DB->get_record('tool_oauthmcp_consent', ['userid' => $USER->id, 'clientdbid' => $clientdbid]);
$covered = $consent && !empty($consent->remembered)
    && empty(array_diff($requestedscopes, array_filter(explode(' ', (string)$consent->scopes))));

$scopelines = [];
foreach ($requestedscopes as $scopename) {
    $scopelines[] = $scopename === \tool_oauthmcp\local\auth\auth_result::SCOPE_WRITE
        ? get_string('scope_write_desc', 'tool_oauthmcp')
        : get_string('scope_read_desc', 'tool_oauthmcp');
}
$toolnames = array_column(
    tool_registry::create()->list_tools((int)$USER->id, $systemcontext->id, $requestedscopes),
    'name'
);

$customdata = [
    'clientname' => $client->getName(),
    'scopes' => $scopelines,
    'tools' => array_slice($toolnames, 0, 30),
    'allowremember' => $allowremember,
    'authparams' => $authparams,
];
$form = new consent_form(new moodle_url('/admin/tool/oauthmcp/oauth/authorize.php'), $customdata);

$approved = null;
$remember = false;
if ($allowremember && $covered && !$ispost) {
    // Remembered consent: skip the screen.
    $approved = true;
    $remember = true;
} else if ($ispost) {
    $data = $form->get_data();
    if ($data === null) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    $approved = !isset($data->deny);
    $remember = $allowremember && !empty($data->remember);
}

if ($approved === null) {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('consent_title', 'tool_oauthmcp'));
    $form->display();
    echo $OUTPUT->footer();
    die;
}

if ($approved) {
    $now = time();
    if ($consent) {
        $consent->scopes = implode(' ', array_unique(array_merge(
            array_filter(explode(' ', (string)$consent->scopes)),
            $requestedscopes
        )));
        $consent->remembered = $remember ? 1 : (int)$consent->remembered;
        $consent->timemodified = $now;
        $DB->update_record('tool_oauthmcp_consent', $consent);
    } else {
        $consent = (object)[
            'userid' => $USER->id,
            'clientdbid' => $clientdbid,
            'scopes' => implode(' ', $requestedscopes),
            'remembered' => $remember ? 1 : 0,
            'timecreated' => $now,
            'timemodified' => $now,
        ];
        $consent->id = $DB->insert_record('tool_oauthmcp_consent', $consent);
    }
    \tool_oauthmcp\event\consent_given::create([
        'context' => $systemcontext,
        'objectid' => $consent->id,
        'other' => [
            'clientname' => $client->getName(),
            'scopes' => implode(' ', $requestedscopes),
        ],
    ])->trigger();
}

$authrequest->setUser(new \tool_oauthmcp\local\oauth\entities\user_entity((int)$USER->id));
$authrequest->setAuthorizationApproved((bool)$approved);

try {
    $response = $server->completeAuthorizationRequest($authrequest, new \GuzzleHttp\Psr7\Response());
} catch (\League\OAuth2\Server\Exception\OAuthServerException $e) {
    // Denied consent lands here as access_denied with a safe redirect.
    $response = $e->generateHttpResponse(new \GuzzleHttp\Psr7\Response());
}
tool_oauthmcp_emit($response);
