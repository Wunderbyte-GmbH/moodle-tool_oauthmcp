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
 * One parsed JSON-RPC request or notification.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

/**
 * Immutable value object for a JSON-RPC 2.0 request.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rpc_request {
    /** @var int|string|null Request id; only meaningful when $hasid is true. */
    public $id;

    /** @var bool Whether the message carried an id (false = notification). */
    public $hasid;

    /** @var string Method name. */
    public $method;

    /** @var array Decoded params (named or positional); empty when absent. */
    public $params;

    /**
     * Constructor.
     *
     * @param int|string|null $id Request id.
     * @param bool $hasid Whether an id was present.
     * @param string $method Method name.
     * @param array $params Request params.
     */
    public function __construct($id, bool $hasid, string $method, array $params) {
        $this->id = $id;
        $this->hasid = $hasid;
        $this->method = $method;
        $this->params = $params;
    }
}
