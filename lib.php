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
 * Plugin callbacks for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the "Connected MCP apps" link to the user profile page.
 *
 * @param \core_user\output\myprofile\tree $tree Profile tree.
 * @param stdClass $user The profile owner.
 * @param bool $iscurrentuser Whether the viewer is the owner.
 * @param stdClass|null $course Course context or null.
 * @return bool
 */
function tool_oauthmcp_myprofile_navigation(\core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course): bool {
    global $DB;

    if (!$iscurrentuser || !get_config('tool_oauthmcp', 'enabled')) {
        return false;
    }
    if (
        !$DB->record_exists('tool_oauthmcp_consent', ['userid' => $user->id])
            && !has_capability('tool/oauthmcp:connect', context_system::instance())
    ) {
        return false;
    }

    $node = new \core_user\output\myprofile\node(
        'miscellaneous',
        'tooloauthmcpapps',
        get_string('userapps', 'tool_oauthmcp'),
        null,
        new moodle_url('/admin/tool/oauthmcp/userapps.php')
    );
    $tree->add_node($node);
    return true;
}
