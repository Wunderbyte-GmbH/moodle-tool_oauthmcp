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
 * Admin settings for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('server', new admin_category('tooloauthmcp', get_string('pluginname', 'tool_oauthmcp')));

    $settings = new admin_settingpage('tool_oauthmcp_settings', get_string('settingspage', 'tool_oauthmcp'));
    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configcheckbox(
            'tool_oauthmcp/enabled',
            get_string('enabled', 'tool_oauthmcp'),
            get_string('enabled_desc', 'tool_oauthmcp'),
            0
        ));

        $settings->add(new admin_setting_configselect(
            'tool_oauthmcp/authmode',
            get_string('authmode', 'tool_oauthmcp'),
            get_string('authmode_desc', 'tool_oauthmcp'),
            'both',
            [
                'wstoken' => get_string('authmode_wstoken', 'tool_oauthmcp'),
                'oauth' => get_string('authmode_oauth', 'tool_oauthmcp'),
                'both' => get_string('authmode_both', 'tool_oauthmcp'),
            ]
        ));

        $settings->add(new admin_setting_configcheckbox(
            'tool_oauthmcp/wstokenanyservice',
            get_string('wstokenanyservice', 'tool_oauthmcp'),
            get_string('wstokenanyservice_desc', 'tool_oauthmcp'),
            0
        ));

        // Additional web-service functions are exposed the native Moodle way: by assigning them to
        // the plugin's dedicated "MCP server" service under External services. No separate picker -
        // this points the admin straight at that service's function list. (Plugin-native tools
        // contributed through the collect_tool_providers hook are exposed automatically and are not
        // managed here.)
        $mcpserviceid = \tool_oauthmcp\local\external_service_manager::ensure_service();
        $settings->add(new admin_setting_description(
            'tool_oauthmcp/exposedservices_info',
            get_string('exposedservices', 'tool_oauthmcp'),
            get_string(
                'exposedservices_info',
                'tool_oauthmcp',
                (new moodle_url('/admin/webservice/service_functions.php', ['id' => $mcpserviceid]))->out(false)
            )
        ));

        $settings->add(new admin_setting_configtextarea(
            'tool_oauthmcp/alloworigins',
            get_string('alloworigins', 'tool_oauthmcp'),
            get_string('alloworigins_desc', 'tool_oauthmcp'),
            '',
            PARAM_RAW
        ));

        $settings->add(new admin_setting_configduration(
            'tool_oauthmcp/mcpsessionttl',
            get_string('mcpsessionttl', 'tool_oauthmcp'),
            get_string('mcpsessionttl_desc', 'tool_oauthmcp'),
            DAYSECS
        ));

        $settings->add(new admin_setting_configtext(
            'tool_oauthmcp/ratelimittools',
            get_string('ratelimittools', 'tool_oauthmcp'),
            get_string('ratelimittools_desc', 'tool_oauthmcp'),
            60,
            PARAM_INT
        ));

        $settings->add(new admin_setting_heading(
            'tool_oauthmcp/oauthheading',
            get_string('oauthheading', 'tool_oauthmcp'),
            get_string('oauthheading_desc', 'tool_oauthmcp')
        ));

        $settings->add(new admin_setting_configduration(
            'tool_oauthmcp/accesstokenttl',
            get_string('accesstokenttl', 'tool_oauthmcp'),
            get_string('accesstokenttl_desc', 'tool_oauthmcp'),
            HOURSECS
        ));

        $settings->add(new admin_setting_configduration(
            'tool_oauthmcp/refreshtokenttl',
            get_string('refreshtokenttl', 'tool_oauthmcp'),
            get_string('refreshtokenttl_desc', 'tool_oauthmcp'),
            30 * DAYSECS
        ));

        $settings->add(new admin_setting_configselect(
            'tool_oauthmcp/consentpolicy',
            get_string('consentpolicy', 'tool_oauthmcp'),
            get_string('consentpolicy_desc', 'tool_oauthmcp'),
            'allow_remember',
            [
                'allow_remember' => get_string('consentpolicy_allow_remember', 'tool_oauthmcp'),
                'always_ask' => get_string('consentpolicy_always_ask', 'tool_oauthmcp'),
            ]
        ));

        $settings->add(new admin_setting_configcheckbox(
            'tool_oauthmcp/dcrenabled',
            get_string('dcrenabled', 'tool_oauthmcp'),
            get_string('dcrenabled_desc', 'tool_oauthmcp'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'tool_oauthmcp/dcrallowlocalhost',
            get_string('dcrallowlocalhost', 'tool_oauthmcp'),
            get_string('dcrallowlocalhost_desc', 'tool_oauthmcp'),
            1
        ));

        $settings->add(new admin_setting_configtext(
            'tool_oauthmcp/dcrquota',
            get_string('dcrquota', 'tool_oauthmcp'),
            get_string('dcrquota_desc', 'tool_oauthmcp'),
            100,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'tool_oauthmcp/ratelimittoken',
            get_string('ratelimittoken', 'tool_oauthmcp'),
            get_string('ratelimittoken_desc', 'tool_oauthmcp'),
            30,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'tool_oauthmcp/ratelimitregister',
            get_string('ratelimitregister', 'tool_oauthmcp'),
            get_string('ratelimitregister_desc', 'tool_oauthmcp'),
            5,
            PARAM_INT
        ));
    }
    $ADMIN->add('tooloauthmcp', $settings);

    $ADMIN->add('tooloauthmcp', new admin_externalpage(
        'tool_oauthmcp_tools',
        get_string('toolgovernance', 'tool_oauthmcp'),
        new moodle_url('/admin/tool/oauthmcp/tools.php')
    ));

    $ADMIN->add('tooloauthmcp', new admin_externalpage(
        'tool_oauthmcp_clients',
        get_string('clients', 'tool_oauthmcp'),
        new moodle_url('/admin/tool/oauthmcp/clients.php'),
        'tool/oauthmcp:manageclients'
    ));

    $ADMIN->add('tooloauthmcp', new admin_externalpage(
        'tool_oauthmcp_diagnostics',
        get_string('diagnostics', 'tool_oauthmcp'),
        new moodle_url('/admin/tool/oauthmcp/diagnostics.php')
    ));

    $ADMIN->add('tooloauthmcp', new admin_externalpage(
        'tool_oauthmcp_serversetup',
        get_string('serversetup', 'tool_oauthmcp'),
        new moodle_url('/admin/tool/oauthmcp/serversetup.php')
    ));
}
