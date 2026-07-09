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
 * MCP method dispatch (ping, tools/list, tools/call).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

use cache;
use context_system;
use stdClass;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\ratelimit\sliding_window;
use tool_oauthmcp\local\registry\tool_registry;

/**
 * Handles the per-session MCP methods after initialisation.
 *
 * Protocol faults use JSON-RPC error responses; tool failures use tool
 * results with isError=true (FR-MCP-5).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mcp_method_controller {
    /** @var tool_registry */
    private $registry;

    /**
     * Constructor.
     *
     * @param tool_registry|null $registry Registry override for tests.
     */
    public function __construct(?tool_registry $registry = null) {
        $this->registry = $registry ?? tool_registry::create();
    }

    /**
     * Dispatch one JSON-RPC request.
     *
     * @param rpc_request $request Parsed request (has an id).
     * @param stdClass $session The MCP session.
     * @param auth_result $auth The authenticated bearer.
     * @return array JSON-RPC response array.
     */
    public function dispatch(rpc_request $request, stdClass $session, auth_result $auth): array {
        switch ($request->method) {
            case 'ping':
                return json_rpc::result($request->id, new stdClass());
            case 'tools/list':
                return $this->tools_list($request, $auth);
            case 'tools/call':
                return $this->tools_call($request, $session, $auth);
            case 'initialize':
                return json_rpc::error(
                    $request->id,
                    json_rpc::INVALID_REQUEST,
                    'Session already initialised'
                );
            default:
                return json_rpc::error(
                    $request->id,
                    json_rpc::METHOD_NOT_FOUND,
                    'Method not found: ' . clean_param($request->method, PARAM_NOTAGS)
                );
        }
    }

    /**
     * Handle a notification (no response expected).
     *
     * Only notifications/initialized is meaningful to us (as a no-op);
     * unknown notifications are ignored per spec.
     *
     * @param rpc_request $request Parsed notification.
     * @return void
     */
    public function notify(rpc_request $request): void {
        // Intentionally empty.
    }

    /**
     * tools/list: serve the cached tool catalog for this user and scope set.
     *
     * @param rpc_request $request The request.
     * @param auth_result $auth The authenticated bearer.
     * @return array JSON-RPC response.
     */
    private function tools_list(rpc_request $request, auth_result $auth): array {
        $contextid = context_system::instance()->id;
        $cache = cache::make('tool_oauthmcp', 'toollist');
        $cachekey = md5($auth->userid . ':' . $contextid . ':' . implode(',', $auth->scopes));

        $tools = $cache->get($cachekey);
        if (!is_array($tools)) {
            $tools = $this->registry->list_tools($auth->userid, $contextid, $auth->scopes);
            $cache->set($cachekey, $tools);
        }

        // The catalog is a single page; a supplied cursor is accepted and ignored.
        return json_rpc::result($request->id, ['tools' => $tools]);
    }

    /**
     * tools/call: rate limit, then execute through the registry.
     *
     * @param rpc_request $request The request.
     * @param stdClass $session The MCP session.
     * @param auth_result $auth The authenticated bearer.
     * @return array JSON-RPC response.
     */
    private function tools_call(rpc_request $request, stdClass $session, auth_result $auth): array {
        $name = $request->params['name'] ?? null;
        if (!is_string($name) || $name === '') {
            return json_rpc::error($request->id, json_rpc::INVALID_PARAMS, 'tools/call requires a tool name');
        }
        $args = $request->params['arguments'] ?? [];
        if (!is_array($args)) {
            return json_rpc::error($request->id, json_rpc::INVALID_PARAMS, 'tools/call arguments must be an object');
        }

        $limit = (int)get_config('tool_oauthmcp', 'ratelimittools');
        if ($limit > 0 && !(new sliding_window())->check('tools:' . $auth->userid, $limit, 60)) {
            $this->trigger_rate_limit_event($auth);
            return json_rpc::result($request->id, [
                'content' => [['type' => 'text', 'text' => get_string('mcp_error_rate_limited', 'tool_oauthmcp')]],
                'structuredContent' => ['issue_codes' => ['MCP_RATE_LIMITED']],
                'isError' => true,
            ]);
        }

        $contextid = context_system::instance()->id;
        $result = $this->registry->call_tool(
            $name,
            $args,
            $auth->userid,
            $contextid,
            $auth->scopes,
            $this->idempotency_key($session, $request),
            (string)($session->sid ?? '')
        );

        $mcpresult = [
            'content' => $result['content'] ?? [],
            'isError' => !empty($result['isError']),
        ];
        if (!empty($result['structuredContent'])) {
            $mcpresult['structuredContent'] = $result['structuredContent'];
        }
        return json_rpc::result($request->id, $mcpresult);
    }

    /**
     * Derive a stable idempotency key for this request.
     *
     * JSON-RPC ids are unique per session, so a client retry of the same
     * request yields the same key; sources with run bookkeeping (hook
     * providers) get replay protection for free.
     *
     * @param stdClass $session The MCP session.
     * @param rpc_request $request The request.
     * @return string
     */
    private function idempotency_key(stdClass $session, rpc_request $request): string {
        if ($request->id === null) {
            return bin2hex(random_bytes(32));
        }
        return hash('sha256', $session->sid . ':' . var_export($request->id, true));
    }

    /**
     * Audit a rate-limit rejection.
     *
     * @param auth_result $auth The authenticated bearer.
     * @return void
     */
    private function trigger_rate_limit_event(auth_result $auth): void {
        \tool_oauthmcp\event\tool_called::create([
            'context' => context_system::instance(),
            'other' => [
                'source' => '',
                'tool' => '',
                'status' => 'rate_limited',
            ],
        ])->trigger();
    }
}
