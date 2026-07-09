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
 * JSON-RPC layer tests.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use tool_oauthmcp\local\mcp\json_rpc;
use tool_oauthmcp\local\mcp\json_rpc_exception;

/**
 * Tests for the JSON-RPC 2.0 parser and response builders.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\mcp\json_rpc
 */
final class json_rpc_test extends \basic_testcase {
    /**
     * A single valid request parses into one rpc_request.
     *
     * @return void
     */
    public function test_parse_single_request(): void {
        $parsed = json_rpc::parse_body('{"jsonrpc":"2.0","id":1,"method":"ping","params":{"a":1}}');

        $this->assertFalse($parsed['batch']);
        $this->assertCount(1, $parsed['requests']);
        $request = $parsed['requests'][0];
        $this->assertSame(1, $request->id);
        $this->assertTrue($request->hasid);
        $this->assertSame('ping', $request->method);
        $this->assertSame(['a' => 1], $request->params);
    }

    /**
     * A message without id is a notification.
     *
     * @return void
     */
    public function test_parse_notification(): void {
        $parsed = json_rpc::parse_body('{"jsonrpc":"2.0","method":"notifications/initialized"}');

        $request = $parsed['requests'][0];
        $this->assertFalse($request->hasid);
        $this->assertNull($request->id);
        $this->assertSame([], $request->params);
    }

    /**
     * A JSON array parses as a batch.
     *
     * @return void
     */
    public function test_parse_batch(): void {
        $parsed = json_rpc::parse_body(
            '[{"jsonrpc":"2.0","id":"a","method":"ping"},{"jsonrpc":"2.0","id":"b","method":"ping"}]'
        );

        $this->assertTrue($parsed['batch']);
        $this->assertCount(2, $parsed['requests']);
        $this->assertSame('a', $parsed['requests'][0]->id);
        $this->assertSame('b', $parsed['requests'][1]->id);
    }

    /**
     * A batch larger than the cap is refused.
     *
     * @return void
     */
    public function test_batch_size_cap(): void {
        $one = '{"jsonrpc":"2.0","id":1,"method":"ping"}';

        // A batch exactly at the cap parses.
        $atcap = '[' . implode(',', array_fill(0, json_rpc::MAX_BATCH_SIZE, $one)) . ']';
        $parsed = json_rpc::parse_body($atcap);
        $this->assertCount(json_rpc::MAX_BATCH_SIZE, $parsed['requests']);

        // One over the cap is rejected as an invalid request.
        $over = '[' . implode(',', array_fill(0, json_rpc::MAX_BATCH_SIZE + 1, $one)) . ']';
        try {
            json_rpc::parse_body($over);
            $this->fail('Expected json_rpc_exception');
        } catch (json_rpc_exception $e) {
            $this->assertSame(json_rpc::INVALID_REQUEST, $e->getCode());
        }
    }

    /**
     * Malformed JSON raises PARSE_ERROR.
     *
     * @return void
     */
    public function test_parse_error(): void {
        try {
            json_rpc::parse_body('{nope');
            $this->fail('Expected json_rpc_exception');
        } catch (json_rpc_exception $e) {
            $this->assertSame(json_rpc::PARSE_ERROR, $e->getCode());
        }
    }

    /**
     * Invalid request objects raise INVALID_REQUEST.
     *
     * @param string $body Offending body.
     * @return void
     * @dataProvider invalid_request_provider
     */
    public function test_invalid_requests(string $body): void {
        try {
            json_rpc::parse_body($body);
            $this->fail('Expected json_rpc_exception');
        } catch (json_rpc_exception $e) {
            $this->assertSame(json_rpc::INVALID_REQUEST, $e->getCode());
        }
    }

    /**
     * Data provider for test_invalid_requests.
     *
     * @return array
     */
    public static function invalid_request_provider(): array {
        return [
            'scalar' => ['42'],
            'missing jsonrpc' => ['{"id":1,"method":"ping"}'],
            'wrong version' => ['{"jsonrpc":"1.0","id":1,"method":"ping"}'],
            'missing method' => ['{"jsonrpc":"2.0","id":1}'],
            'boolean id' => ['{"jsonrpc":"2.0","id":true,"method":"ping"}'],
            'scalar params' => ['{"jsonrpc":"2.0","id":1,"method":"ping","params":3}'],
            'empty batch' => ['[]'],
        ];
    }

    /**
     * Response builders emit the JSON-RPC 2.0 envelope.
     *
     * @return void
     */
    public function test_response_builders(): void {
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => 7, 'result' => ['ok' => true]],
            json_rpc::result(7, ['ok' => true])
        );
        $this->assertSame(
            ['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32601, 'message' => 'nope']],
            json_rpc::error(null, json_rpc::METHOD_NOT_FOUND, 'nope')
        );
    }
}
