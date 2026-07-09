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
 * The plugin's dedicated external service.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local;

/**
 * Manages the "MCP server" external service the plugin creates on install.
 *
 * The service is the single anchor of the configuration: web service tokens
 * must belong to it (unless the admin opts into accepting any service), and
 * the functions an admin adds to it via the standard "Add functions" UI are
 * exactly the functions exposed as MCP tools (extfunc_tool_source reads them
 * straight off this service). It is deliberately created as a *custom* service
 * (component empty): built-in services have immutable function lists.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class external_service_manager {
    /** @var string Shortname of the dedicated external service. */
    public const SERVICE_SHORTNAME = 'tool_oauthmcp';

    /**
     * Create the dedicated service if missing and anchor the default config.
     *
     * Idempotent; called from install and from the upgrade step that
     * retrofits existing installations.
     *
     * @return int The service id.
     */
    public static function ensure_service(): int {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]);
        if ($service) {
            $serviceid = (int)$service->id;
        } else {
            $serviceid = (int)$DB->insert_record('external_services', (object)[
                'name' => 'MCP server (tool_oauthmcp)',
                'shortname' => self::SERVICE_SHORTNAME,
                'enabled' => 1,
                'restrictedusers' => 0,
                'downloadfiles' => 0,
                'uploadfiles' => 0,
                'timecreated' => time(),
            ]);
        }

        return $serviceid;
    }

    /**
     * The dedicated service record, or null when it was removed.
     *
     * @return \stdClass|null
     */
    public static function get_service(): ?\stdClass {
        global $DB;

        return $DB->get_record('external_services', ['shortname' => self::SERVICE_SHORTNAME]) ?: null;
    }
}
