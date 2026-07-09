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
 * JSON-RPC 2.0 message parsing and response building.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

/**
 * Stateless JSON-RPC 2.0 helper.
 *
 * The reserved error codes are used strictly for protocol faults; tool
 * failures are reported as MCP tool results with isError=true instead
 * (FR-MCP-5 in the requirements).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class json_rpc {
    /** @var int Invalid JSON was received. */
    public const PARSE_ERROR = -32700;

    /** @var int The JSON sent is not a valid request object. */
    public const INVALID_REQUEST = -32600;

    /** @var int The method does not exist. */
    public const METHOD_NOT_FOUND = -32601;

    /** @var int Invalid method parameters. */
    public const INVALID_PARAMS = -32602;

    /** @var int Internal JSON-RPC error. */
    public const INTERNAL_ERROR = -32603;

    /** @var int Maximum number of requests accepted in one JSON-RPC batch. */
    public const MAX_BATCH_SIZE = 50;

    /**
     * Parse a request body into rpc_request objects.
     *
     * @param string $body Raw HTTP body.
     * @return array ['batch' => bool, 'requests' => rpc_request[]]
     * @throws json_rpc_exception On malformed JSON or invalid request objects.
     */
    public static function parse_body(string $body): array {
        $decoded = json_decode($body, true);
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new json_rpc_exception(self::PARSE_ERROR, 'Parse error: ' . json_last_error_msg());
        }
        if (!is_array($decoded)) {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: expected an object or array');
        }

        $batch = array_is_list($decoded);
        $items = $batch ? $decoded : [$decoded];
        if ($batch && empty($items)) {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: empty batch');
        }
        if ($batch && count($items) > self::MAX_BATCH_SIZE) {
            // Bound batch fan-out so a single request cannot exhaust resources.
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: batch too large');
        }

        $requests = [];
        foreach ($items as $item) {
            $requests[] = self::parse_item($item);
        }

        return ['batch' => $batch, 'requests' => $requests];
    }

    /**
     * Build a JSON-RPC success response.
     *
     * @param int|string|null $id Request id.
     * @param array|\stdClass $result Result payload.
     * @return array
     */
    public static function result($id, $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    /**
     * Build a JSON-RPC error response.
     *
     * @param int|string|null $id Request id (null when unknown).
     * @param int $code Error code.
     * @param string $message Error message.
     * @return array
     */
    public static function error($id, int $code, string $message): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    /**
     * Validate one decoded message and turn it into an rpc_request.
     *
     * @param mixed $item Decoded JSON value.
     * @return rpc_request
     * @throws json_rpc_exception When the message violates JSON-RPC 2.0.
     */
    private static function parse_item($item): rpc_request {
        if (!is_array($item) || array_is_list($item)) {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: message must be an object');
        }
        if (($item['jsonrpc'] ?? '') !== '2.0') {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: jsonrpc must be "2.0"');
        }
        $method = $item['method'] ?? null;
        if (!is_string($method) || $method === '') {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: method must be a non-empty string');
        }

        $hasid = array_key_exists('id', $item);
        $id = $item['id'] ?? null;
        if ($hasid && !is_int($id) && !is_string($id) && $id !== null) {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: id must be a string, number or null');
        }

        $params = $item['params'] ?? [];
        if (!is_array($params)) {
            throw new json_rpc_exception(self::INVALID_REQUEST, 'Invalid request: params must be structured');
        }

        return new rpc_request($id, $hasid, $method, $params);
    }
}
