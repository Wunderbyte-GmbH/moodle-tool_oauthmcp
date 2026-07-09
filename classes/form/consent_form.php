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
 * OAuth consent form.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

/**
 * Approve/deny screen shown after login during the authorization flow.
 *
 * The original authorize query string is round-tripped in a hidden field,
 * so the POST re-validates the authorization request statelessly instead
 * of trusting a session-stored object.
 *
 * Custom data: clientname (string), scopes (string[] localised lines),
 * tools (string[] tool names the grant unlocks), allowremember (bool),
 * authparams (base64 of the original query string).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class consent_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $data = (array)$this->_customdata;

        $mform->addElement('hidden', 'authparams', $data['authparams']);
        $mform->setType('authparams', PARAM_RAW);

        $mform->addElement('html', \html_writer::tag(
            'p',
            get_string('consent_intro', 'tool_oauthmcp', s((string)$data['clientname']))
        ));

        $scopeitems = '';
        foreach ((array)($data['scopes'] ?? []) as $scopeline) {
            $scopeitems .= \html_writer::tag('li', s($scopeline));
        }
        $mform->addElement('html', \html_writer::tag('ul', $scopeitems));

        $tools = (array)($data['tools'] ?? []);
        if (!empty($tools)) {
            $toolitems = '';
            foreach ($tools as $tool) {
                $toolitems .= \html_writer::tag('li', \html_writer::tag('code', s((string)$tool)));
            }
            $mform->addElement('html', \html_writer::tag(
                'p',
                get_string('consent_tools', 'tool_oauthmcp', count($tools))
            ));
            $mform->addElement('html', \html_writer::tag('ul', $toolitems, ['class' => 'small']));
        }

        if (!empty($data['allowremember'])) {
            $mform->addElement('checkbox', 'remember', get_string('consent_remember', 'tool_oauthmcp'));
        }

        $buttons = [
            $mform->createElement('submit', 'approve', get_string('consent_approve', 'tool_oauthmcp')),
            $mform->createElement('submit', 'deny', get_string('consent_deny', 'tool_oauthmcp')),
        ];
        $mform->addGroup($buttons, 'decision', '', ' ', false);
    }
}
