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
 * Privacy provider tests.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use context_system;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use tool_oauthmcp\privacy\provider;

/**
 * Tests export and deletion of the personal data the plugin stores.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\privacy\provider
 */
final class privacy_provider_test extends \core_privacy\tests\provider_testcase {
    /**
     * Seed a client plus consent/token/refresh/session rows for a user.
     *
     * @param int $userid The user id.
     * @return int The client row id.
     */
    private function seed(int $userid): int {
        global $DB;

        $clientid = $DB->insert_record('tool_oauthmcp_client', (object)[
            'clientid' => 'cid' . $userid,
            'secrethash' => null,
            'name' => 'Test client ' . $userid,
            'redirecturis' => json_encode(['https://c.example/cb']),
            'scopes' => 'mcp:read mcp:write',
            'authmethod' => 'none',
            'dcr' => 1,
            'enabled' => 1,
            'createdby' => $userid,
            'registrationip' => '203.0.113.5',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('tool_oauthmcp_consent', (object)[
            'userid' => $userid, 'clientdbid' => $clientid, 'scopes' => 'mcp:read',
            'remembered' => 1, 'timecreated' => time(), 'timemodified' => time(),
        ]);
        $DB->insert_record('tool_oauthmcp_token', (object)[
            'tokenhash' => hash('sha256', 'tok' . $userid), 'identifier' => 'tid' . $userid,
            'clientdbid' => $clientid, 'userid' => $userid, 'scopes' => 'mcp:read',
            'resourceuri' => null, 'authcode' => null, 'expires' => time() + 3600,
            'revoked' => 0, 'timecreated' => time(), 'lastused' => time(),
        ]);
        $DB->insert_record('tool_oauthmcp_refresh', (object)[
            'identifier' => 'rid' . $userid, 'accesstokenid' => null, 'familyid' => 'fam' . $userid,
            'clientdbid' => $clientid, 'userid' => $userid, 'expires' => time() + 86400,
            'revoked' => 0, 'timecreated' => time(),
        ]);
        $DB->insert_record('tool_oauthmcp_session', (object)[
            'sid' => str_repeat((string)($userid % 10), 64), 'userid' => $userid, 'authmode' => 'oauth',
            'tokenid' => null, 'protocolversion' => '2025-06-18', 'timecreated' => time(), 'lastseen' => time(),
        ]);
        return $clientid;
    }

    /**
     * A user with data is found at the system context; a clean user is not.
     *
     * @return void
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $clean = $this->getDataGenerator()->create_user();
        $this->seed((int)$user->id);

        $contexts = provider::get_contexts_for_userid((int)$user->id)->get_contextids();
        $this->assertEquals([context_system::instance()->id], $contexts);
        $this->assertEmpty(provider::get_contexts_for_userid((int)$clean->id)->get_contextids());
    }

    /**
     * Export writes the user's consents, tokens and sessions.
     *
     * @return void
     */
    public function test_export_user_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->seed((int)$user->id);

        $this->export_context_data_for_user((int)$user->id, context_system::instance(), 'tool_oauthmcp');
        $writer = writer::with_context(context_system::instance());
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * Deleting all data in the system context clears the user tables.
     *
     * @return void
     */
    public function test_delete_all_in_context(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->seed((int)$user->id);

        provider::delete_data_for_all_users_in_context(context_system::instance());

        $this->assertSame(0, $DB->count_records('tool_oauthmcp_consent'));
        $this->assertSame(0, $DB->count_records('tool_oauthmcp_token'));
        $this->assertSame(0, $DB->count_records('tool_oauthmcp_refresh'));
        $this->assertSame(0, $DB->count_records('tool_oauthmcp_session'));
        // The client row survives with its creator link cleared.
        $this->assertSame(1, $DB->count_records('tool_oauthmcp_client'));
        $this->assertNull($DB->get_field('tool_oauthmcp_client', 'createdby', []));
    }

    /**
     * Deleting one user leaves other users' data intact.
     *
     * @return void
     */
    public function test_delete_for_user(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->seed((int)$user->id);
        $this->seed((int)$other->id);

        $contextlist = new approved_contextlist($user, 'tool_oauthmcp', [context_system::instance()->id]);
        provider::delete_data_for_user($contextlist);

        $this->assertSame(0, $DB->count_records('tool_oauthmcp_token', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('tool_oauthmcp_token', ['userid' => $other->id]));
    }

    /**
     * Userlist population and targeted deletion.
     *
     * @return void
     */
    public function test_userlist(): void {
        global $DB;

        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->seed((int)$user->id);
        $this->seed((int)$other->id);

        $userlist = new \core_privacy\local\request\userlist(context_system::instance(), 'tool_oauthmcp');
        provider::get_users_in_context($userlist);
        $this->assertEqualsCanonicalizing(
            [(int)$user->id, (int)$other->id],
            $userlist->get_userids()
        );

        $approved = new approved_userlist(context_system::instance(), 'tool_oauthmcp', [(int)$user->id]);
        provider::delete_data_for_users($approved);
        $this->assertSame(0, $DB->count_records('tool_oauthmcp_session', ['userid' => $user->id]));
        $this->assertSame(1, $DB->count_records('tool_oauthmcp_session', ['userid' => $other->id]));
    }
}
