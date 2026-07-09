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
 * MCP tool governance: per-tool enable/disable and read-only classification.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_oauthmcp\local\registry\extfunc_tool_source;
use tool_oauthmcp\local\registry\tool_registry;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_oauthmcp_tools');

$action = optional_param('action', '', PARAM_ALPHA);
if ($action !== '') {
    require_sesskey();
    $toolid = required_param('toolid', PARAM_INT);
    $record = $DB->get_record('tool_oauthmcp_tool', ['id' => $toolid], '*', MUST_EXIST);
    if ($action === 'toggleenabled') {
        $record->enabled = empty($record->enabled) ? 1 : 0;
    } else if ($action === 'togglereadonly' && $record->source === extfunc_tool_source::SOURCE_ID) {
        $record->isreadonly = empty($record->isreadonly) ? 1 : 0;
    }
    $record->timemodified = time();
    $DB->update_record('tool_oauthmcp_tool', $record);
    tool_registry::purge_tool_list_cache();
    redirect(
        $PAGE->url,
        get_string('toolgovernance_updated', 'tool_oauthmcp'),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('toolgovernance', 'tool_oauthmcp'));
echo html_writer::tag('p', get_string('toolgovernance_desc', 'tool_oauthmcp'));

$registry = tool_registry::create();
$inventory = $registry->get_inventory($USER->id, context_system::instance()->id);

$table = new html_table();
$table->head = [
    get_string('toolgovernance_source', 'tool_oauthmcp'),
    get_string('toolgovernance_name', 'tool_oauthmcp'),
    get_string('toolgovernance_readonly', 'tool_oauthmcp'),
    get_string('toolgovernance_enabled', 'tool_oauthmcp'),
];
$table->data = [];

foreach ($inventory as $row) {
    $record = $row['record'];
    $definition = $row['definition'];

    $enabledlabel = empty($record->enabled) ? get_string('no') : get_string('yes');
    $enabledurl = new moodle_url($PAGE->url, [
        'action' => 'toggleenabled',
        'toolid' => $record->id,
        'sesskey' => sesskey(),
    ]);
    $enabledcell = html_writer::link($enabledurl, $enabledlabel);

    if ($row['source'] === extfunc_tool_source::SOURCE_ID) {
        $readonlylabel = empty($record->isreadonly) ? get_string('no') : get_string('yes');
        $readonlyurl = new moodle_url($PAGE->url, [
            'action' => 'togglereadonly',
            'toolid' => $record->id,
            'sesskey' => sesskey(),
        ]);
        $readonlycell = html_writer::link($readonlyurl, $readonlylabel);
    } else {
        // Hook tools carry their own scope label; show it read-only.
        $readonly = empty($definition['annotations']['readOnlyHint']) ? get_string('no') : get_string('yes');
        $readonlycell = $readonly;
    }

    $namecell = html_writer::tag('code', s((string)$definition['name']))
        . html_writer::tag(
            'div',
            s(shorten_text((string)($definition['description'] ?? ''), 140)),
            ['class' => 'small text-muted']
        );

    $table->data[] = [s($row['source']), $namecell, $readonlycell, $enabledcell];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
