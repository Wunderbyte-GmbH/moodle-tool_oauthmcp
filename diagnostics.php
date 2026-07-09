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
 * MCP connectivity self-check: live-fetches the discovery URLs a remote
 * client would use and reports what works.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_oauthmcp\local\oauth\urls;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->libdir . '/filelib.php');

admin_externalpage_setup('tool_oauthmcp_diagnostics');

/**
 * Fetch a URL server-side (tolerating self-signed dev certificates).
 *
 * @param string $url The URL.
 * @return array ['code' => int, 'body' => string, 'headers' => string]
 */
function tool_oauthmcp_diag_fetch(string $url): array {
    $curl = new curl();
    $body = (string)$curl->get($url, [], [
        'CURLOPT_SSL_VERIFYPEER' => 0,
        'CURLOPT_SSL_VERIFYHOST' => 0,
        'CURLOPT_TIMEOUT' => 10,
        'CURLOPT_FOLLOWLOCATION' => 0,
        'CURLOPT_HEADER' => 1,
    ]);
    $info = $curl->get_info();
    $headersize = (int)($info['header_size'] ?? 0);
    return [
        'code' => (int)($info['http_code'] ?? 0),
        'headers' => substr($body, 0, $headersize),
        'body' => substr($body, $headersize),
    ];
}

$rows = [];

$enabled = (bool)get_config('tool_oauthmcp', 'enabled');
$rows[] = ['label' => get_string('diag_enabled', 'tool_oauthmcp'), 'ok' => $enabled, 'detail' => ''];

$https = is_https();
$rows[] = ['label' => get_string('diag_https', 'tool_oauthmcp'), 'ok' => $https, 'detail' => $CFG->wwwroot];

// The MCP endpoint must challenge with resource metadata.
$server = tool_oauthmcp_diag_fetch(urls::resource());
$challenge = preg_match('/WWW-Authenticate:.*resource_metadata=/i', $server['headers']) === 1;
$rows[] = [
    'label' => get_string('diag_server401', 'tool_oauthmcp'),
    'ok' => $server['code'] === 401 && $challenge,
    'detail' => 'HTTP ' . $server['code'],
];

// Webroot well-known aliases (RFC 8414 path from the issuer).
$host = preg_replace('#^(https?://[^/]+).*$#', '$1', $CFG->wwwroot);
$issuerpath = rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
foreach (
    [
    'diag_wellknown_as' => $host . '/.well-known/oauth-authorization-server' . $issuerpath,
    'diag_wellknown_prm' => $host . '/.well-known/oauth-protected-resource' . $issuerpath,
    ] as $label => $url
) {
    $result = tool_oauthmcp_diag_fetch($url);
    $decoded = json_decode($result['body'], true);
    $ok = $result['code'] === 200 && is_array($decoded)
        && (($decoded['issuer'] ?? $decoded['resource'] ?? '') !== '');
    $rows[] = [
        'label' => get_string($label, 'tool_oauthmcp'),
        'ok' => $ok,
        'detail' => $url . ' (HTTP ' . $result['code'] . ')',
        'warn' => !$ok,
    ];
}

// DCR endpoint reachable (405 on GET is the healthy answer).
$dcr = tool_oauthmcp_diag_fetch(urls::oauth('register'));
$rows[] = [
    'label' => get_string('diag_dcr', 'tool_oauthmcp'),
    'ok' => in_array($dcr['code'], [405, 403], true),
    'detail' => 'HTTP ' . $dcr['code'],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('diagnostics', 'tool_oauthmcp'));

$table = new html_table();
$table->head = ['', ''];
foreach ($rows as $row) {
    if (!empty($row['ok'])) {
        $status = html_writer::span(get_string('diag_ok', 'tool_oauthmcp'), 'badge badge-success');
    } else if (!empty($row['warn'])) {
        $status = html_writer::span(get_string('diag_warn', 'tool_oauthmcp'), 'badge badge-warning');
    } else {
        $status = html_writer::span(get_string('diag_fail', 'tool_oauthmcp'), 'badge badge-danger');
    }
    $table->data[] = [
        s($row['label']) . html_writer::tag('div', s((string)$row['detail']), ['class' => 'small text-muted']),
        $status,
    ];
}
echo html_writer::table($table);
echo $OUTPUT->notification(get_string('diag_wellknown_hint', 'tool_oauthmcp'), 'info');
echo $OUTPUT->footer();
