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
 * Tool source backed by Moodle external functions.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\registry;

use core_external\external_api;
use tool_oauthmcp\local\auth\auth_result;
use tool_oauthmcp\local\external_service_manager;

/**
 * Publishes the external functions of admin-selected web service definitions
 * as MCP tools.
 *
 * Tool names are the frankenstyle external function names, which are already
 * globally namespaced and MCP-name-safe. Every tool defaults to the write
 * scope: Moodle external functions carry no machine-readable read/write flag,
 * so read classification is an explicit admin act on the governance page.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extfunc_tool_source implements tool_source_interface {
    /** @var string Source id used in governance rows and events. */
    public const SOURCE_ID = 'extfunc';

    /** @var string[]|null Cached exposed function names for this request. */
    private $functionnames = null;

    /** @var extfunc_schema_converter */
    private $converter;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->converter = new extfunc_schema_converter();
    }

    /**
     * Source id.
     *
     * @return string
     */
    public function get_source_id(): string {
        return self::SOURCE_ID;
    }

    /**
     * Definitions for all exposed external functions.
     *
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array
     */
    public function list_tools(int $userid, int $contextid): array {
        $tools = [];
        foreach ($this->get_function_names() as $name) {
            $definition = $this->build_definition($name);
            if ($definition !== null) {
                $tools[] = $definition;
            }
        }
        return $tools;
    }

    /**
     * Definition for one exposed function.
     *
     * @param string $toolname Tool name.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array|null
     */
    public function get_tool(string $toolname, int $userid, int $contextid): ?array {
        if (!in_array($toolname, $this->get_function_names(), true)) {
            return null;
        }
        return $this->build_definition($toolname);
    }

    /**
     * Execute an external function as the current user.
     *
     * @param string $toolname Tool name (external function name).
     * @param array $args Named arguments.
     * @param int $userid Acting user id (already the session user).
     * @param int $contextid Ambient context id.
     * @param string $idempotencykey Unused: external functions have no run bookkeeping.
     * @return array MCP-shaped result.
     */
    public function call_tool(
        string $toolname,
        array $args,
        int $userid,
        int $contextid,
        string $idempotencykey,
        string $sessionid = ''
    ): array {
        // External functions are stateless per call — no session-scoped thread to isolate.
        $response = external_api::call_external_function($toolname, $args, false);

        if (!empty($response['error'])) {
            $exception = $response['exception'] ?? null;
            $message = trim((string)($exception->message ?? ''));
            if ($message === '') {
                $message = get_string('mcp_error_tool_failed', 'tool_oauthmcp', $toolname);
            }
            $structured = ['errorcode' => (string)($exception->errorcode ?? 'unknown')];
            // Core already strips debuginfo unless the site runs in developer
            // debug mode; gate it explicitly so it never reaches a client on a
            // production site regardless of upstream behaviour.
            if (!empty($exception->debuginfo) && debugging('', DEBUG_DEVELOPER)) {
                $structured['debuginfo'] = (string)$exception->debuginfo;
            }
            return [
                'content' => [['type' => 'text', 'text' => $message]],
                'structuredContent' => $structured,
                'isError' => true,
            ];
        }

        $data = $response['data'] ?? null;
        $text = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return [
            'content' => [['type' => 'text', 'text' => (string)$text]],
            'structuredContent' => $this->wrap_structured($data),
            'isError' => false,
        ];
    }

    /**
     * structuredContent must be a JSON object: wrap scalars and lists.
     *
     * @param mixed $data Cleaned external function return value.
     * @return array
     */
    private function wrap_structured($data): array {
        if (is_object($data)) {
            $data = (array)$data;
        }
        if (is_array($data) && !empty($data) && !array_is_list($data)) {
            return $data;
        }
        return ['result' => $data];
    }

    /**
     * The external function names exposed as MCP tools: the functions assigned to the plugin's
     * dedicated "MCP server" web service.
     *
     * There is deliberately no separate service picker — admins expose additional functions the
     * native Moodle way, by adding them to this one service under External services. Plugin-native
     * tools (skills) arrive on a different registry source via the collect_tool_providers hook and
     * are not affected by this list.
     *
     * @return string[]
     */
    private function get_function_names(): array {
        global $DB;

        if ($this->functionnames !== null) {
            return $this->functionnames;
        }

        $service = external_service_manager::get_service();
        if ($service === null) {
            return $this->functionnames = [];
        }

        $names = $DB->get_fieldset_select(
            'external_services_functions',
            'DISTINCT functionname',
            'externalserviceid = ?',
            [(int)$service->id]
        );
        sort($names);
        return $this->functionnames = $names;
    }

    /**
     * Build the MCP tool definition for one external function.
     *
     * @param string $name External function name.
     * @return array|null Null when the function's implementation is unavailable.
     */
    private function build_definition(string $name): ?array {
        try {
            $info = external_api::external_function_info($name);
        } catch (\Throwable $e) {
            debugging("tool_oauthmcp: cannot describe external function {$name}: " . $e->getMessage(), DEBUG_DEVELOPER);
            return null;
        }
        if (!$info) {
            return null;
        }

        $description = trim((string)($info->description ?? ''));
        if (!empty($info->capabilities)) {
            $description .= ' Requires capabilities: ' . $info->capabilities . '.';
        }

        return [
            'name' => $name,
            'description' => trim($description),
            'inputSchema' => $this->converter->convert($info->parameters_desc),
            'annotations' => [
                'title' => $name,
                'readOnlyHint' => false,
                'destructiveHint' => true,
            ],
            'scope' => auth_result::SCOPE_WRITE,
        ];
    }
}
