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
 * MCP protocol version constants.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

/**
 * The MCP protocol versions this server implements.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class protocol {
    /** @var string Protocol revision 2025-03-26 (allows JSON-RPC batching). */
    public const VERSION_2025_03_26 = '2025-03-26';

    /** @var string Protocol revision 2025-06-18 (batching removed, MCP-Protocol-Version header). */
    public const VERSION_2025_06_18 = '2025-06-18';

    /** @var string[] Supported versions, newest first. */
    public const SUPPORTED_VERSIONS = [self::VERSION_2025_06_18, self::VERSION_2025_03_26];

    /** @var string The version offered when the client requests an unknown one. */
    public const LATEST = self::VERSION_2025_06_18;

    /**
     * Negotiate the protocol version for a session.
     *
     * Per spec: echo the requested version when supported, otherwise answer
     * with the latest version this server speaks (the client disconnects if
     * it cannot use it).
     *
     * @param string $requested Version requested by the client.
     * @return string
     */
    public static function negotiate(string $requested): string {
        if (in_array($requested, self::SUPPORTED_VERSIONS, true)) {
            return $requested;
        }
        return self::LATEST;
    }
}
