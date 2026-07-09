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
 * Manual OAuth client registration form.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\form;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

use tool_oauthmcp\local\oauth\dcr_service;

/**
 * Create/edit form for manually registered clients (FR-OAUTH-8).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class client_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        $mform->addElement('text', 'name', get_string('client_name', 'tool_oauthmcp'), ['size' => 60]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $mform->addElement(
            'textarea',
            'redirecturis',
            get_string('client_redirecturis', 'tool_oauthmcp'),
            ['rows' => 4, 'cols' => 80]
        );
        $mform->setType('redirecturis', PARAM_RAW_TRIMMED);
        $mform->addRule('redirecturis', null, 'required', null, 'client');
        $mform->addElement('static', 'redirecturis_help', '', get_string('client_redirecturis_desc', 'tool_oauthmcp'));

        $mform->addElement('select', 'authmethod', get_string('client_authmethod', 'tool_oauthmcp'), [
            'none' => 'none (public client, PKCE)',
            'client_secret_basic' => 'client_secret_basic',
            'client_secret_post' => 'client_secret_post',
        ]);
        $mform->addElement('static', 'authmethod_help', '', get_string('client_authmethod_desc', 'tool_oauthmcp'));

        $mform->addElement('advcheckbox', 'enabled', get_string('client_enabled', 'tool_oauthmcp'));
        $mform->setDefault('enabled', 1);

        $this->add_action_buttons();
    }

    /**
     * Validate redirect URIs with the same policy as dynamic registration.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $uris = array_filter(array_map('trim', preg_split('/\R+/', (string)$data['redirecturis']) ?: []));
        if (empty($uris)) {
            $errors['redirecturis'] = get_string('required');
        }
        foreach ($uris as $uri) {
            if (!dcr_service::is_valid_redirect_uri($uri)) {
                $errors['redirecturis'] = get_string('client_redirecturis_invalid', 'tool_oauthmcp', s($uri));
                break;
            }
        }
        return $errors;
    }
}
