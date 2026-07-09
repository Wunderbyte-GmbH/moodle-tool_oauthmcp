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
 * Tool source contract.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\registry;

/**
 * A provider of MCP tools.
 *
 * Tool definitions are arrays with the MCP wire keys (name, description,
 * inputSchema, annotations) plus an internal 'scope' key (mcp:read or
 * mcp:write) that the registry strips before serving.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface tool_source_interface {
    /**
     * Stable identifier of this source (used in governance rows and events).
     *
     * @return string
     */
    public function get_source_id(): string;

    /**
     * All tool definitions this source offers to the given user.
     *
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array Tool definition arrays.
     */
    public function list_tools(int $userid, int $contextid): array;

    /**
     * One tool definition, or null when this source does not own the tool.
     *
     * @param string $toolname Tool name.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @return array|null
     */
    public function get_tool(string $toolname, int $userid, int $contextid): ?array;

    /**
     * Execute a tool call and return an MCP-shaped result
     * (content / structuredContent / isError).
     *
     * @param string $toolname Tool name.
     * @param array $args Tool arguments.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @param string $idempotencykey Per-request key for replay protection.
     * @param string $sessionid MCP session id (Mcp-Session-Id); lets a source isolate per-session
     *                          state such as pending confirmations. Empty when the transport has
     *                          no session (sources may ignore it).
     * @return array
     */
    public function call_tool(
        string $toolname,
        array $args,
        int $userid,
        int $contextid,
        string $idempotencykey,
        string $sessionid = ''
    ): array;
}
