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
 * Privacy provider for tool_oauthmcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\privacy;

use context;
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Exports and deletes the personal data the MCP server stores: OAuth
 * consents, issued tokens, authorization codes, MCP sessions and the
 * "created by" link on manually registered clients. All rows live at the
 * system context.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table('tool_oauthmcp_consent', [
            'userid' => 'privacy:metadata:tool_oauthmcp_consent:userid',
            'clientdbid' => 'privacy:metadata:tool_oauthmcp_consent:clientdbid',
            'scopes' => 'privacy:metadata:tool_oauthmcp_consent:scopes',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_consent:timecreated',
        ], 'privacy:metadata:tool_oauthmcp_consent');

        $collection->add_database_table('tool_oauthmcp_token', [
            'userid' => 'privacy:metadata:tool_oauthmcp_token:userid',
            'clientdbid' => 'privacy:metadata:tool_oauthmcp_token:clientdbid',
            'scopes' => 'privacy:metadata:tool_oauthmcp_token:scopes',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_token:timecreated',
            'lastused' => 'privacy:metadata:tool_oauthmcp_token:lastused',
        ], 'privacy:metadata:tool_oauthmcp_token');

        $collection->add_database_table('tool_oauthmcp_refresh', [
            'userid' => 'privacy:metadata:tool_oauthmcp_refresh:userid',
            'clientdbid' => 'privacy:metadata:tool_oauthmcp_refresh:clientdbid',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_refresh:timecreated',
        ], 'privacy:metadata:tool_oauthmcp_refresh');

        $collection->add_database_table('tool_oauthmcp_authcode', [
            'userid' => 'privacy:metadata:tool_oauthmcp_authcode:userid',
            'clientdbid' => 'privacy:metadata:tool_oauthmcp_authcode:clientdbid',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_authcode:timecreated',
        ], 'privacy:metadata:tool_oauthmcp_authcode');

        $collection->add_database_table('tool_oauthmcp_session', [
            'userid' => 'privacy:metadata:tool_oauthmcp_session:userid',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_session:timecreated',
            'lastseen' => 'privacy:metadata:tool_oauthmcp_session:lastseen',
        ], 'privacy:metadata:tool_oauthmcp_session');

        $collection->add_database_table('tool_oauthmcp_client', [
            'createdby' => 'privacy:metadata:tool_oauthmcp_client:createdby',
            'name' => 'privacy:metadata:tool_oauthmcp_client:name',
            'registrationip' => 'privacy:metadata:tool_oauthmcp_client:registrationip',
            'timecreated' => 'privacy:metadata:tool_oauthmcp_client:timecreated',
        ], 'privacy:metadata:tool_oauthmcp_client');

        // The token/tool call audit events reach the standard log store.
        $collection->add_subsystem_link('core_event', [], 'privacy:metadata:core_event');

        return $collection;
    }

    /**
     * All contexts (only ever the system context) holding data for a user.
     *
     * @param int $userid The user id.
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        global $DB;

        $contextlist = new contextlist();
        $tables = [
            'tool_oauthmcp_consent',
            'tool_oauthmcp_token',
            'tool_oauthmcp_refresh',
            'tool_oauthmcp_authcode',
            'tool_oauthmcp_session',
        ];
        foreach ($tables as $table) {
            if ($DB->record_exists($table, ['userid' => $userid])) {
                $contextlist->add_system_context();
                return $contextlist;
            }
        }
        if ($DB->record_exists('tool_oauthmcp_client', ['createdby' => $userid])) {
            $contextlist->add_system_context();
        }
        return $contextlist;
    }

    /**
     * All users with data in a context (only the system context applies).
     *
     * @param userlist $userlist The userlist to populate.
     * @return void
     */
    public static function get_users_in_context(userlist $userlist): void {
        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        foreach (
            [
            'tool_oauthmcp_consent',
            'tool_oauthmcp_token',
            'tool_oauthmcp_refresh',
            'tool_oauthmcp_authcode',
            'tool_oauthmcp_session',
            ] as $table
        ) {
            $userlist->add_from_sql('userid', "SELECT userid FROM {{$table}}", []);
        }
        $userlist->add_from_sql(
            'createdby',
            "SELECT createdby FROM {tool_oauthmcp_client} WHERE createdby IS NOT NULL",
            []
        );
    }

    /**
     * Export all stored data for the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function export_user_data(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist->get_contexts())) {
            return;
        }
        $context = context_system::instance();
        $userid = $contextlist->get_user()->id;
        $subcontext = [get_string('pluginname', 'tool_oauthmcp')];

        $consents = $DB->get_records('tool_oauthmcp_consent', ['userid' => $userid]);
        if ($consents) {
            $rows = array_map(static function ($c) use ($DB) {
                return [
                    'client' => self::client_name($DB, (int)$c->clientdbid),
                    'scopes' => $c->scopes,
                    'timecreated' => transform::datetime($c->timecreated),
                ];
            }, array_values($consents));
            writer::with_context($context)->export_data(
                array_merge($subcontext, [get_string('userapps', 'tool_oauthmcp')]),
                (object)['consents' => $rows]
            );
        }

        $tokens = $DB->get_records('tool_oauthmcp_token', ['userid' => $userid]);
        if ($tokens) {
            $rows = array_map(static function ($t) use ($DB) {
                return [
                    'client' => self::client_name($DB, (int)$t->clientdbid),
                    'scopes' => $t->scopes,
                    'timecreated' => transform::datetime($t->timecreated),
                    'lastused' => $t->lastused ? transform::datetime($t->lastused) : null,
                ];
            }, array_values($tokens));
            writer::with_context($context)->export_data(
                array_merge($subcontext, ['tokens']),
                (object)['tokens' => $rows]
            );
        }

        $sessions = $DB->get_records('tool_oauthmcp_session', ['userid' => $userid]);
        if ($sessions) {
            $rows = array_map(static function ($s) {
                return [
                    'authmode' => $s->authmode,
                    'timecreated' => transform::datetime($s->timecreated),
                    'lastseen' => transform::datetime($s->lastseen),
                ];
            }, array_values($sessions));
            writer::with_context($context)->export_data(
                array_merge($subcontext, ['sessions']),
                (object)['sessions' => $rows]
            );
        }
    }

    /**
     * Delete all users' data in a context.
     *
     * @param context $context The context.
     * @return void
     */
    public static function delete_data_for_all_users_in_context(context $context): void {
        global $DB;

        if (!$context instanceof context_system) {
            return;
        }
        foreach (
            [
            'tool_oauthmcp_consent',
            'tool_oauthmcp_token',
            'tool_oauthmcp_refresh',
            'tool_oauthmcp_authcode',
            'tool_oauthmcp_session',
            ] as $table
        ) {
            $DB->delete_records($table);
        }
        $DB->set_field('tool_oauthmcp_client', 'createdby', null, []);
    }

    /**
     * Delete one user's data across the approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts.
     * @return void
     */
    public static function delete_data_for_user(approved_contextlist $contextlist): void {
        global $DB;

        if (!self::has_system_context($contextlist->get_contexts())) {
            return;
        }
        self::delete_for_userids($DB, [$contextlist->get_user()->id]);
    }

    /**
     * Delete data for the approved users in a context.
     *
     * @param approved_userlist $userlist The approved users.
     * @return void
     */
    public static function delete_data_for_users(approved_userlist $userlist): void {
        global $DB;

        if (!$userlist->get_context() instanceof context_system) {
            return;
        }
        self::delete_for_userids($DB, $userlist->get_userids());
    }

    /**
     * Delete every user-linked row for the given user ids.
     *
     * @param \moodle_database $db The database.
     * @param int[] $userids User ids.
     * @return void
     */
    private static function delete_for_userids(\moodle_database $db, array $userids): void {
        if (empty($userids)) {
            return;
        }
        [$insql, $params] = $db->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        foreach (
            [
            'tool_oauthmcp_consent',
            'tool_oauthmcp_token',
            'tool_oauthmcp_refresh',
            'tool_oauthmcp_authcode',
            'tool_oauthmcp_session',
            ] as $table
        ) {
            $db->delete_records_select($table, "userid {$insql}", $params);
        }
        $db->set_field_select('tool_oauthmcp_client', 'createdby', null, "createdby {$insql}", $params);
    }

    /**
     * Whether the given contexts include the system context.
     *
     * @param context[] $contexts Contexts to check.
     * @return bool
     */
    private static function has_system_context(array $contexts): bool {
        foreach ($contexts as $context) {
            if ($context instanceof context_system) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a client display name from its row id.
     *
     * @param \moodle_database $db The database.
     * @param int $clientdbid Client row id.
     * @return string
     */
    private static function client_name(\moodle_database $db, int $clientdbid): string {
        $name = $db->get_field('tool_oauthmcp_client', 'name', ['id' => $clientdbid]);
        return $name !== false ? (string)$name : (string)$clientdbid;
    }
}
