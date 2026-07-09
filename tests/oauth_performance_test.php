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
 * Performance guard: token validation stays cheap on the hot path.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use cache;
use context_system;
use tool_oauthmcp\local\auth\oauth_authenticator;
use tool_oauthmcp\local\oauth\token_service;

/**
 * NFR-5: an OAuth bearer validation costs at most one extra DB read cold,
 * and zero once the MUC entry is warm.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\oauth\token_service
 * @covers \tool_oauthmcp\local\auth\oauth_authenticator
 */
final class oauth_performance_test extends \advanced_testcase {
    /**
     * Seed a live token for a permissioned user and return its bearer string.
     *
     * @return string The opaque bearer.
     */
    private function seed_token(): string {
        global $DB;

        $user = $this->getDataGenerator()->create_user();
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability('tool/oauthmcp:connect', CAP_ALLOW, $roleid, context_system::instance()->id);
        role_assign($roleid, $user->id, context_system::instance()->id);

        $clientid = $DB->insert_record('tool_oauthmcp_client', (object)[
            'clientid' => 'perfclient',
            'secrethash' => null,
            'name' => 'Perf client',
            'redirecturis' => json_encode(['https://c.example/cb']),
            'scopes' => 'mcp:read mcp:write',
            'authmethod' => 'none',
            'dcr' => 1,
            'enabled' => 1,
            'createdby' => null,
            'registrationip' => '203.0.113.1',
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $bearer = token_service::OPAQUE_PREFIX . bin2hex(random_bytes(40));
        $DB->insert_record('tool_oauthmcp_token', (object)[
            'tokenhash' => token_service::hash($bearer),
            'identifier' => 'perftoken',
            'clientdbid' => $clientid,
            'userid' => $user->id,
            'scopes' => 'mcp:read mcp:write',
            'resourceuri' => null,
            'authcode' => null,
            'expires' => time() + HOURSECS,
            'revoked' => 0,
            'timecreated' => time(),
            'lastused' => time(),
        ]);
        return $bearer;
    }

    /**
     * NFR-5: the token lookup costs one DB read cold and zero warm.
     *
     * This measures the plugin's own hot-path contribution
     * (token_service::lookup_valid); the surrounding authenticate() also
     * loads the user and checks a capability, which are Moodle-standard
     * per-request costs shared with every other access path.
     *
     * @return void
     */
    public function test_token_lookup_read_budget(): void {
        global $DB;

        $this->resetAfterTest();
        $bearer = $this->seed_token();
        cache::make('tool_oauthmcp', 'oauthtokens')->purge();

        // Cold: exactly one indexed read, then the row is cached.
        $before = $DB->perf_get_reads();
        $this->assertNotNull(token_service::lookup_valid($bearer));
        $coldreads = $DB->perf_get_reads() - $before;
        $this->assertLessThanOrEqual(1, $coldreads, "Cold token lookup did {$coldreads} reads");

        // Warm: served entirely from MUC, no reads.
        $before = $DB->perf_get_reads();
        $this->assertNotNull(token_service::lookup_valid($bearer));
        $warmreads = $DB->perf_get_reads() - $before;
        $this->assertSame(0, $warmreads, "Warm token lookup did {$warmreads} reads");
    }

    /**
     * The full authenticator resolves a warm token to the acting user.
     *
     * @return void
     */
    public function test_authenticator_warm(): void {
        $this->resetAfterTest();
        $bearer = $this->seed_token();
        $authenticator = new oauth_authenticator();

        $this->assertNotNull($authenticator->authenticate($bearer));
        $this->assertNotNull($authenticator->authenticate($bearer));
    }

    /**
     * Revoking a token purges its cache entry immediately (AC-5 within TTL).
     *
     * @return void
     */
    public function test_revocation_invalidates_cache(): void {
        global $DB;

        $this->resetAfterTest();
        $bearer = $this->seed_token();
        $authenticator = new oauth_authenticator();
        $this->assertNotNull($authenticator->authenticate($bearer));

        $row = $DB->get_record('tool_oauthmcp_token', ['tokenhash' => token_service::hash($bearer)]);
        token_service::revoke_access_row($row);

        $this->assertNull($authenticator->authenticate($bearer));
        $this->assertFalse(cache::make('tool_oauthmcp', 'oauthtokens')->get(token_service::hash($bearer)));
    }
}
