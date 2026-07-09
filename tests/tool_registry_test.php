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
 * Tool registry and governance tests.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use context_system;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\registry\extfunc_tool_source;
use tool_oauthmcp\local\registry\tool_registry;
use tool_oauthmcp\local\registry\tool_source_interface;

/**
 * Tests governance (enable/disable, read-only classification), scope
 * filtering and audit events at the registry chokepoint.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\registry\tool_registry
 * @covers \tool_oauthmcp\local\registry\extfunc_tool_source
 */
final class tool_registry_test extends \advanced_testcase {
    /** @var string The external function used throughout. */
    private const TOOL = 'core_webservice_get_site_info';

    /**
     * Provision an exposed service and an acting user.
     *
     * @return int The acting user id.
     */
    private function setup_environment(): int {
        global $DB, $USER;

        $this->resetAfterTest();
        set_config('enablewebservices', 1);

        // The extfunc source reads the functions assigned to the plugin's dedicated
        // "MCP server (tool_oauthmcp)" service, so seed the tool there.
        $serviceid = \tool_oauthmcp\local\external_service_manager::ensure_service();
        $DB->insert_record('external_services_functions', (object)[
            'externalserviceid' => $serviceid,
            'functionname' => self::TOOL,
        ]);

        $user = $this->getDataGenerator()->create_user();
        $this->setUser($user);
        // The registry is normally reached through the bearer transport,
        // where the endpoint runs under WS_SERVER; outside of it the
        // external API applies its browser CSRF check, which has no
        // equivalent here.
        $USER->ignoresesskey = true;

        return (int)$user->id;
    }

    /**
     * Both scopes list the tool; a read-only bearer does not see write tools.
     *
     * @return void
     */
    public function test_scope_filtering(): void {
        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();

        $bothscopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];
        $names = array_column($registry->list_tools($userid, $contextid, $bothscopes), 'name');
        $this->assertContains(self::TOOL, $names);

        // Default classification is write, so a read-only bearer sees nothing.
        $names = array_column($registry->list_tools($userid, $contextid, [auth_result::SCOPE_READ]), 'name');
        $this->assertNotContains(self::TOOL, $names);

        $result = $registry->call_tool(self::TOOL, [], $userid, $contextid, [auth_result::SCOPE_READ], 'k1');
        $this->assertTrue($result['isError']);
        $this->assertContains('TOOL_SCOPE_DENIED', $result['structuredContent']['issue_codes']);
    }

    /**
     * The admin read-only classification moves the tool to the read scope
     * and flips the annotations.
     *
     * @return void
     */
    public function test_readonly_classification(): void {
        global $DB;

        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();

        // Lazily create the governance row, then classify read-only.
        $registry->list_tools($userid, $contextid, [auth_result::SCOPE_WRITE]);
        $DB->set_field(
            'tool_oauthmcp_tool',
            'isreadonly',
            1,
            ['source' => extfunc_tool_source::SOURCE_ID, 'toolname' => self::TOOL]
        );

        $tools = $registry->list_tools($userid, $contextid, [auth_result::SCOPE_READ]);
        $names = array_column($tools, 'name');
        $this->assertContains(self::TOOL, $names);
        $tool = $tools[array_search(self::TOOL, $names)];
        $this->assertTrue($tool['annotations']['readOnlyHint']);
        $this->assertFalse($tool['annotations']['destructiveHint']);

        $result = $registry->call_tool(self::TOOL, [], $userid, $contextid, [auth_result::SCOPE_READ], 'k2');
        $this->assertFalse($result['isError']);
    }

    /**
     * Disabled tools disappear from listings and refuse calls.
     *
     * @return void
     */
    public function test_disabled_tool(): void {
        global $DB;

        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();
        $scopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];

        $registry->list_tools($userid, $contextid, $scopes);
        $DB->set_field(
            'tool_oauthmcp_tool',
            'enabled',
            0,
            ['source' => extfunc_tool_source::SOURCE_ID, 'toolname' => self::TOOL]
        );

        $names = array_column($registry->list_tools($userid, $contextid, $scopes), 'name');
        $this->assertNotContains(self::TOOL, $names);

        $result = $registry->call_tool(self::TOOL, [], $userid, $contextid, $scopes, 'k3');
        $this->assertTrue($result['isError']);
        $this->assertContains('TOOL_DISABLED', $result['structuredContent']['issue_codes']);
    }

    /**
     * Every call outcome triggers the audit event with its status.
     *
     * @return void
     */
    public function test_audit_events(): void {
        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();
        $scopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];

        $sink = $this->redirectEvents();
        $registry->call_tool(self::TOOL, [], $userid, $contextid, $scopes, 'k4');
        $registry->call_tool('core_nope_missing', [], $userid, $contextid, $scopes, 'k5');
        $events = array_values(array_filter($sink->get_events(), static function ($event) {
            return $event instanceof \tool_oauthmcp\event\tool_called;
        }));
        $sink->close();

        $this->assertCount(2, $events);
        $this->assertSame('executed', $events[0]->other['status']);
        $this->assertSame(self::TOOL, $events[0]->other['tool']);
        $this->assertSame('denied_unknown', $events[1]->other['status']);
    }

    /**
     * Execution failures inside the function surface as isError results.
     *
     * @return void
     */
    public function test_execution_failure_is_tool_error(): void {
        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();
        $scopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];

        // Invalid parameter shape trips validate_parameters inside the call.
        $result = $registry->call_tool(
            self::TOOL,
            ['serviceshortnames' => 'notanarray'],
            $userid,
            $contextid,
            $scopes,
            'k6'
        );
        $this->assertTrue($result['isError']);
        $this->assertArrayHasKey('errorcode', $result['structuredContent']);
    }

    /**
     * The governance inventory lists every tool with its record.
     *
     * @return void
     */
    public function test_inventory(): void {
        $userid = $this->setup_environment();
        $contextid = context_system::instance()->id;
        $registry = tool_registry::create();

        $inventory = $registry->get_inventory($userid, $contextid);
        $this->assertNotEmpty($inventory);
        $row = $inventory[0];
        $this->assertSame(extfunc_tool_source::SOURCE_ID, $row['source']);
        $this->assertSame(self::TOOL, $row['definition']['name']);
        $this->assertEquals(1, $row['record']->enabled);
    }

    /**
     * The registry forwards the MCP session id through to the source's call_tool.
     *
     * @return void
     */
    public function test_session_id_is_forwarded_to_source(): void {
        $this->resetAfterTest();
        $userid = (int)$this->getDataGenerator()->create_user()->id;
        $this->setUser($userid);
        $contextid = (int)context_system::instance()->id;

        $source = new class implements tool_source_interface {
            /** @var string Captured session id from the last call_tool. */
            public string $captured = '__unset__';

            /**
             * Source id.
             *
             * @return string
             */
            public function get_source_id(): string {
                return 'stub';
            }

            /**
             * List the single stub tool.
             *
             * @param int $userid
             * @param int $contextid
             * @return array
             */
            public function list_tools(int $userid, int $contextid): array {
                return [$this->definition()];
            }

            /**
             * Return the stub tool definition by name.
             *
             * @param string $toolname
             * @param int $userid
             * @param int $contextid
             * @return array|null
             */
            public function get_tool(string $toolname, int $userid, int $contextid): ?array {
                return $toolname === 'stub_tool' ? $this->definition() : null;
            }

            /**
             * Capture the session id and return a benign result.
             *
             * @param string $toolname
             * @param array $args
             * @param int $userid
             * @param int $contextid
             * @param string $idempotencykey
             * @param string $sessionid
             * @return array
             */
            public function call_tool(
                string $toolname,
                array $args,
                int $userid,
                int $contextid,
                string $idempotencykey,
                string $sessionid = ''
            ): array {
                $this->captured = $sessionid;
                return ['content' => [], 'structuredContent' => [], 'isError' => false];
            }

            /**
             * The stub tool definition.
             *
             * @return array
             */
            private function definition(): array {
                return [
                    'name' => 'stub_tool',
                    'description' => 'Stub',
                    'inputSchema' => ['type' => 'object'],
                    'scope' => auth_result::SCOPE_READ,
                ];
            }
        };

        $registry = new tool_registry([$source]);
        $scopes = [auth_result::SCOPE_READ, auth_result::SCOPE_WRITE];
        // Lazily create the (enabled) governance row for the stub tool.
        $registry->list_tools($userid, $contextid, $scopes);

        $registry->call_tool('stub_tool', [], $userid, $contextid, $scopes, 'idem-1', 'session-xyz');
        $this->assertSame('session-xyz', $source->captured);
    }
}
