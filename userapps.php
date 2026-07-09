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
 * Self-service: list and revoke the user's connected MCP applications.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_oauthmcp\local\oauth\token_service;

require(__DIR__ . '/../../../config.php');

require_login(null, false);

$systemcontext = context_system::instance();
$PAGE->set_url(new moodle_url('/admin/tool/oauthmcp/userapps.php'));
$PAGE->set_context($systemcontext);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('userapps', 'tool_oauthmcp'));
$PAGE->set_heading(get_string('userapps', 'tool_oauthmcp'));

$revoke = optional_param('revoke', 0, PARAM_INT);
if ($revoke) {
    require_sesskey();
    $consent = $DB->get_record('tool_oauthmcp_consent', ['id' => $revoke, 'userid' => $USER->id], '*', MUST_EXIST);
    token_service::revoke_all_for_user_client((int)$USER->id, (int)$consent->clientdbid);
    $DB->delete_records('tool_oauthmcp_consent', ['id' => $consent->id]);
    \tool_oauthmcp\event\token_revoked::create([
        'context' => $systemcontext,
        'other' => ['via' => 'selfservice', 'tokentype' => 'grant'],
    ])->trigger();
    redirect(
        $PAGE->url,
        get_string('userapps_revoked', 'tool_oauthmcp'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();

$sql = "SELECT co.id, co.scopes, co.timecreated, c.name,
               (SELECT MAX(t.lastused) FROM {tool_oauthmcp_token} t
                 WHERE t.userid = co.userid AND t.clientdbid = co.clientdbid) AS lastused
          FROM {tool_oauthmcp_consent} co
          JOIN {tool_oauthmcp_client} c ON c.id = co.clientdbid
         WHERE co.userid = :userid
      ORDER BY c.name ASC";
$grants = $DB->get_records_sql($sql, ['userid' => $USER->id]);

if (empty($grants)) {
    echo $OUTPUT->notification(get_string('userapps_none', 'tool_oauthmcp'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('userapps_client', 'tool_oauthmcp'),
        get_string('userapps_scopes', 'tool_oauthmcp'),
        get_string('userapps_first', 'tool_oauthmcp'),
        get_string('userapps_lastused', 'tool_oauthmcp'),
        '',
    ];
    foreach ($grants as $grant) {
        $revokeurl = new moodle_url($PAGE->url, ['revoke' => $grant->id, 'sesskey' => sesskey()]);
        $table->data[] = [
            s($grant->name),
            s($grant->scopes),
            userdate((int)$grant->timecreated),
            $grant->lastused ? userdate((int)$grant->lastused) : '-',
            html_writer::link(
                $revokeurl,
                get_string('userapps_revoke', 'tool_oauthmcp'),
                ['class' => 'btn btn-secondary btn-sm']
            ),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();
