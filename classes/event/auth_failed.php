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
 * MCP bearer authentication failure audit event.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\event;

use core\event\base;

/**
 * Triggered when a bearer token is presented to the MCP endpoint but no
 * authenticator accepts it. The token itself is never recorded — only that
 * a presented credential was rejected, so the standard log surfaces
 * brute-force or stale-token activity (AUDIT_SCOPE section 13).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_failed extends base {
    /**
     * Initialise event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_auth_failed', 'tool_oauthmcp');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description() {
        $ip = $this->other['ip'] ?? '';
        return "A bearer token presented to the MCP endpoint was rejected (ip '{$ip}').";
    }
}
