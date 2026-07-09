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
 * Upgrade steps for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin database and configuration.
 *
 * @param int $oldversion Version we are upgrading from.
 * @return bool
 */
function xmldb_tool_oauthmcp_upgrade(int $oldversion): bool {
    global $DB;

    if ($oldversion < 2026070801) {
        // Retrofit the dedicated external service for installations that
        // predate the install hook.
        \tool_oauthmcp\local\external_service_manager::ensure_service();
        upgrade_plugin_savepoint(true, 2026070801, 'tool', 'oauthmcp');
    }

    if ($oldversion < 2026070802) {
        // OAuth 2.1 authorization server tables (phase B).
        $dbman = $DB->get_manager();
        $tables = [
            'tool_oauthmcp_client',
            'tool_oauthmcp_authcode',
            'tool_oauthmcp_token',
            'tool_oauthmcp_refresh',
            'tool_oauthmcp_consent',
        ];
        foreach ($tables as $table) {
            if (!$dbman->table_exists($table)) {
                $dbman->install_one_table_from_xmldb_file(__DIR__ . '/install.xml', $table);
            }
        }
        upgrade_plugin_savepoint(true, 2026070802, 'tool', 'oauthmcp');
    }

    if ($oldversion < 2026071000) {
        // The exposedservices multi-select is replaced by managing the dedicated MCP service's
        // functions directly (native External services UI). Migrate any functions from
        // previously-selected OTHER services into the dedicated service so nothing stops being
        // exposed, then drop the setting.
        $serviceid = \tool_oauthmcp\local\external_service_manager::ensure_service();
        $config = (string)get_config('tool_oauthmcp', 'exposedservices');
        $selectedids = array_filter(array_map('intval', explode(',', $config)));
        foreach ($selectedids as $sid) {
            if ($sid === $serviceid) {
                continue;
            }
            $functions = $DB->get_fieldset_select(
                'external_services_functions',
                'functionname',
                'externalserviceid = ?',
                [$sid]
            );
            foreach ($functions as $fname) {
                $exists = $DB->record_exists('external_services_functions', [
                    'externalserviceid' => $serviceid,
                    'functionname' => $fname,
                ]);
                if (!$exists) {
                    $DB->insert_record('external_services_functions', (object)[
                        'externalserviceid' => $serviceid,
                        'functionname' => $fname,
                    ]);
                }
            }
        }
        unset_config('exposedservices', 'tool_oauthmcp');
        \tool_oauthmcp\local\registry\tool_registry::purge_tool_list_cache();
        upgrade_plugin_savepoint(true, 2026071000, 'tool', 'oauthmcp');
    }

    if ($oldversion < 2026071001) {
        // Belt and braces: the 2026071000 step unset exposedservices, but the then-current
        // ensure_service() re-seeded it afterwards. The setting is now fully unused (extfunc
        // reads the dedicated service directly and ensure_service() no longer writes it), so
        // drop any leftover value unconditionally.
        unset_config('exposedservices', 'tool_oauthmcp');
        \tool_oauthmcp\local\registry\tool_registry::purge_tool_list_cache();
        upgrade_plugin_savepoint(true, 2026071001, 'tool', 'oauthmcp');
    }

    return true;
}
