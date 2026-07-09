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
 * Uninstall hook for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Remove the dedicated external service (including its tokens and
 * function assignments) when the plugin is uninstalled.
 *
 * @return bool
 */
function xmldb_tool_oauthmcp_uninstall(): bool {
    global $CFG;

    require_once($CFG->dirroot . '/webservice/lib.php');

    $service = \tool_oauthmcp\local\external_service_manager::get_service();
    if ($service) {
        (new webservice())->delete_service((int)$service->id);
    }
    return true;
}
