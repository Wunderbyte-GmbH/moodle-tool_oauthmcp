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
 * OAuth client administration: list, create, toggle, delete.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use core\context\system as system_context;
use tool_oauthmcp\event\client_registered;
use tool_oauthmcp\form\client_form;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_oauthmcp_clients');
require_capability('tool/oauthmcp:manageclients', context_system::instance());

$action = optional_param('action', '', PARAM_ALPHA);
$clientid = optional_param('clientid', 0, PARAM_INT);

if ($action === 'toggle' && $clientid) {
    require_sesskey();
    $client = $DB->get_record('tool_oauthmcp_client', ['id' => $clientid], '*', MUST_EXIST);
    $client->enabled = empty($client->enabled) ? 1 : 0;
    $client->timemodified = time();
    $DB->update_record('tool_oauthmcp_client', $client);
    redirect($PAGE->url);
}

if ($action === 'delete' && $clientid) {
    require_sesskey();
    $client = $DB->get_record('tool_oauthmcp_client', ['id' => $clientid], '*', MUST_EXIST);
    $confirm = optional_param('confirm', 0, PARAM_INT);
    if (!$confirm) {
        echo $OUTPUT->header();
        echo $OUTPUT->confirm(
            get_string('clients_delete_confirm', 'tool_oauthmcp'),
            new moodle_url($PAGE->url, [
                'action' => 'delete',
                'clientid' => $clientid,
                'confirm' => 1,
                'sesskey' => sesskey(),
            ]),
            $PAGE->url
        );
        echo $OUTPUT->footer();
        die;
    }
    $DB->delete_records('tool_oauthmcp_consent', ['clientdbid' => $client->id]);
    $DB->delete_records('tool_oauthmcp_refresh', ['clientdbid' => $client->id]);
    $DB->delete_records('tool_oauthmcp_token', ['clientdbid' => $client->id]);
    $DB->delete_records('tool_oauthmcp_authcode', ['clientdbid' => $client->id]);
    $DB->delete_records('tool_oauthmcp_client', ['id' => $client->id]);
    redirect($PAGE->url);
}

$form = new client_form(new moodle_url('/admin/tool/oauthmcp/clients.php', ['action' => 'add']));
$secretnotice = '';
if ($action === 'add') {
    if ($form->is_cancelled()) {
        redirect($PAGE->url);
    }
    if ($data = $form->get_data()) {
        $uris = array_values(array_filter(array_map('trim', preg_split('/\R+/', (string)$data->redirecturis))));
        $record = new stdClass();
        $record->clientid = bin2hex(random_bytes(16));
        $secret = null;
        if ($data->authmethod !== 'none') {
            $secret = bin2hex(random_bytes(32));
            $record->secrethash = password_hash($secret, PASSWORD_DEFAULT);
        } else {
            $record->secrethash = null;
        }
        $record->name = $data->name;
        $record->redirecturis = json_encode($uris);
        $record->scopes = 'mcp:read mcp:write';
        $record->authmethod = $data->authmethod;
        $record->dcr = 0;
        $record->enabled = (int)$data->enabled;
        $record->createdby = $USER->id;
        $record->registrationip = getremoteaddr();
        $record->timecreated = time();
        $record->timemodified = time();
        $record->id = $DB->insert_record('tool_oauthmcp_client', $record);

        client_registered::create([
            'context' => system_context::instance(),
            'objectid' => $record->id,
            'other' => ['name' => $record->name, 'dcr' => 0, 'ip' => getremoteaddr()],
        ])->trigger();

        $notice = 'Client ID: ' . $record->clientid;
        if ($secret !== null) {
            $notice .= html_writer::empty_tag('br')
                . get_string('client_secret_notice', 'tool_oauthmcp', $secret);
        }
        $secretnotice = $notice;
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('clients', 'tool_oauthmcp'));
echo html_writer::tag('p', get_string('clients_desc', 'tool_oauthmcp'));
if ($secretnotice !== '') {
    echo $OUTPUT->notification($secretnotice, 'success', false);
}

$clients = $DB->get_records('tool_oauthmcp_client', null, 'name ASC');
if ($clients) {
    $table = new html_table();
    $table->head = [
        get_string('client_name', 'tool_oauthmcp'),
        'client_id',
        get_string('clients_type', 'tool_oauthmcp'),
        get_string('clients_dcr', 'tool_oauthmcp'),
        get_string('clients_tokens', 'tool_oauthmcp'),
        get_string('clients_created', 'tool_oauthmcp'),
        get_string('client_enabled', 'tool_oauthmcp'),
        '',
    ];
    foreach ($clients as $client) {
        $livetokens = $DB->count_records_select(
            'tool_oauthmcp_token',
            'clientdbid = :clientdbid AND revoked = 0 AND expires > :now',
            ['clientdbid' => $client->id, 'now' => time()]
        );
        $toggleurl = new moodle_url($PAGE->url, ['action' => 'toggle', 'clientid' => $client->id, 'sesskey' => sesskey()]);
        $deleteurl = new moodle_url($PAGE->url, ['action' => 'delete', 'clientid' => $client->id, 'sesskey' => sesskey()]);
        $table->data[] = [
            s($client->name),
            html_writer::tag('code', s($client->clientid)),
            $client->authmethod === 'none'
                ? get_string('clients_type_public', 'tool_oauthmcp')
                : get_string('clients_type_confidential', 'tool_oauthmcp'),
            empty($client->dcr) ? get_string('no') : get_string('yes'),
            (string)$livetokens,
            userdate((int)$client->timecreated, get_string('strftimedate', 'langconfig')),
            html_writer::link($toggleurl, empty($client->enabled) ? get_string('no') : get_string('yes')),
            html_writer::link($deleteurl, get_string('delete')),
        ];
    }
    echo html_writer::table($table);
}

echo $OUTPUT->heading(get_string('client_add', 'tool_oauthmcp'), 3);
$form->display();
echo $OUTPUT->footer();
