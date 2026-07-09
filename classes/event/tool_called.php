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
 * MCP tool call audit event.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\event;

use core\event\base;

/**
 * Triggered for every MCP tools/call, including denied ones.
 *
 * The 'other' payload carries source, tool and status
 * (executed | error | denied_disabled | denied_scope | denied_unknown |
 * rate_limited). CRUD is 'u' as a safe upper bound: external-function tools
 * carry no machine-readable read/write flag.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_called extends base {
    /**
     * Initialise event metadata.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_tool_called', 'tool_oauthmcp');
    }

    /**
     * Human-readable description for the log report.
     *
     * @return string
     */
    public function get_description() {
        $tool = $this->other['tool'] ?? '';
        $status = $this->other['status'] ?? '';
        $source = $this->other['source'] ?? '';
        return "The user with id '{$this->userid}' called the MCP tool '{$tool}' "
            . "(source '{$source}') with status '{$status}'.";
    }
}
