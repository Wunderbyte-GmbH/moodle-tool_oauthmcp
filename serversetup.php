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
 * Automatic server setup: write the OAuth discovery rewrites into the site's .htaccess.
 *
 * The dangerous work (backup, atomic write, live self-test, automatic rollback) lives in
 * {@see \tool_oauthmcp\local\setup\htaccess_fixer}; this page is the guarded UI around it —
 * site administrators only, sesskey on every action, an explicit confirm step with the full
 * consequences spelled out, and a manual recovery recipe shown BEFORE anything is changed.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use tool_oauthmcp\local\setup\htaccess_fixer;

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

admin_externalpage_setup('tool_oauthmcp_serversetup');

$action = optional_param('action', '', PARAM_ALPHA);
$confirm = optional_param('confirm', 0, PARAM_INT);

$fixer = new htaccess_fixer();
$status = $fixer->status();

/**
 * Render one self-test row.
 *
 * @param array $check Check row from the fixer report.
 * @return string HTML.
 */
function tool_oauthmcp_serversetup_check_row(array $check): string {
    $icon = $check['ok']
        ? '<i class="fa fa-check-square text-success" aria-hidden="true"></i>'
        : (!empty($check['informational'])
            ? '<i class="fa fa-exclamation-triangle text-warning" aria-hidden="true"></i>'
            : '<i class="fa fa-times text-danger" aria-hidden="true"></i>');
    return html_writer::tag(
        'li',
        $icon . ' ' . s(get_string($check['label'], 'tool_oauthmcp')) . ' — ' . s($check['detail']),
        ['class' => 'mb-1']
    );
}

$report = null;
if ($action === 'apply' && $confirm && confirm_sesskey()) {
    $report = $fixer->apply();
    $status = $fixer->status();
} else if ($action === 'revert' && $confirm && confirm_sesskey()) {
    $report = ['revert' => true] + $fixer->revert();
    $status = $fixer->status();
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('serversetup', 'tool_oauthmcp'));

// Confirm step: nothing has been changed yet; spell out exactly what will happen.
if (($action === 'apply' || $action === 'revert') && !$confirm) {
    $confirmstring = $action === 'apply'
        ? get_string('serversetup_apply_confirm', 'tool_oauthmcp', $status['path'])
        : get_string('serversetup_revert_confirm', 'tool_oauthmcp', $status['path']);
    echo $OUTPUT->confirm(
        $confirmstring,
        new moodle_url($PAGE->url, ['action' => $action, 'confirm' => 1, 'sesskey' => sesskey()]),
        $PAGE->url
    );
    echo $OUTPUT->footer();
    die;
}

// Result report of an apply/revert that just ran.
if ($report !== null) {
    if (!empty($report['revert'])) {
        echo $OUTPUT->notification(
            get_string($report['success'] ? 'serversetup_reverted' : 'serversetup_revertfailed', 'tool_oauthmcp'),
            $report['success'] ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    } else if ($report['success']) {
        echo $OUTPUT->notification(
            get_string('serversetup_result_success', 'tool_oauthmcp'),
            \core\output\notification::NOTIFY_SUCCESS
        );
    } else if ($report['rolledback']) {
        echo $OUTPUT->notification(
            get_string('serversetup_result_rolledback', 'tool_oauthmcp'),
            \core\output\notification::NOTIFY_ERROR
        );
    } else {
        echo $OUTPUT->notification(
            get_string($report['error'] ?: 'serversetup_reason_writefailed', 'tool_oauthmcp'),
            \core\output\notification::NOTIFY_ERROR
        );
    }
    if (!empty($report['checks'])) {
        echo html_writer::tag('p', get_string('serversetup_checksheading', 'tool_oauthmcp'), ['class' => 'mb-1 mt-3']);
        echo html_writer::tag('ul', implode('', array_map('tool_oauthmcp_serversetup_check_row', $report['checks'])),
            ['class' => 'list-unstyled']);
    }
    if (!empty($report['backupfile'])) {
        echo html_writer::tag('p',
            get_string('serversetup_backupfile', 'tool_oauthmcp', s($report['backupfile'])),
            ['class' => 'small text-muted']);
    }
    echo html_writer::empty_tag('hr');
}

echo html_writer::tag('p', get_string('serversetup_intro', 'tool_oauthmcp'));
echo html_writer::tag('p',
    get_string('serversetup_file', 'tool_oauthmcp') . ' ' . html_writer::tag('code', s($status['path'])));

// What will happen, before any button is pressed.
echo html_writer::start_tag('div', ['class' => 'card mb-3']);
echo html_writer::start_tag('div', ['class' => 'card-body']);
echo html_writer::tag('h5', get_string('serversetup_whatwillhappen', 'tool_oauthmcp'), ['class' => 'card-title']);
echo html_writer::tag('ol', implode('', [
    html_writer::tag('li', get_string('serversetup_step_backup', 'tool_oauthmcp')),
    html_writer::tag('li', get_string('serversetup_step_write', 'tool_oauthmcp')),
    html_writer::tag('li', get_string('serversetup_step_verify', 'tool_oauthmcp')),
    html_writer::tag('li', get_string('serversetup_step_rollback', 'tool_oauthmcp')),
]));
echo html_writer::tag('p', get_string('serversetup_blockpreview', 'tool_oauthmcp'), ['class' => 'mb-1']);
echo html_writer::tag('pre', s(htaccess_fixer::build_block()),
    ['class' => 'small p-2 bg-light border rounded mb-0']);
echo html_writer::end_tag('div');
echo html_writer::end_tag('div');

// The blunt warning: what this file controls and how to recover WITHOUT Moodle.
echo $OUTPUT->notification(get_string('serversetup_warning', 'tool_oauthmcp'),
    \core\output\notification::NOTIFY_WARNING, false);

foreach ($status['warnings'] as $warning) {
    echo $OUTPUT->notification(get_string($warning, 'tool_oauthmcp'),
        \core\output\notification::NOTIFY_WARNING, false);
}

if ($status['applied']) {
    echo $OUTPUT->notification(get_string('serversetup_applied_status', 'tool_oauthmcp'),
        \core\output\notification::NOTIFY_INFO, false);
}

if (!$status['supported']) {
    echo html_writer::tag('p', get_string('serversetup_notsupported', 'tool_oauthmcp'),
        ['class' => 'font-weight-bold mb-1']);
    echo html_writer::tag('ul', implode('', array_map(
        fn(string $reason): string => html_writer::tag('li', get_string($reason, 'tool_oauthmcp')),
        $status['reasons']
    )));
    echo html_writer::tag('p', get_string('serversetup_manualfallback', 'tool_oauthmcp'));
} else {
    $applylabel = $status['applied']
        ? get_string('serversetup_reapply', 'tool_oauthmcp')
        : get_string('serversetup_apply', 'tool_oauthmcp');
    echo $OUTPUT->single_button(
        new moodle_url($PAGE->url, ['action' => 'apply']),
        $applylabel,
        'get',
        ['type' => 'primary']
    );
    if ($status['applied']) {
        echo $OUTPUT->single_button(
            new moodle_url($PAGE->url, ['action' => 'revert']),
            get_string('serversetup_revert', 'tool_oauthmcp'),
            'get'
        );
    }
}

echo $OUTPUT->footer();
