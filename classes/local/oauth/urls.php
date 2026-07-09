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
 * Canonical URLs of the OAuth/MCP surface.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

/**
 * One place for every endpoint URL and the resource-identifier comparison.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class urls {
    /**
     * The RFC 8414 issuer (= wwwroot, keeping well-known path insertion predictable).
     *
     * @return string
     */
    public static function issuer(): string {
        global $CFG;

        return rtrim($CFG->wwwroot, '/');
    }

    /**
     * The protected resource: the MCP endpoint.
     *
     * @return string
     */
    public static function resource(): string {
        return self::issuer() . '/admin/tool/oauthmcp/server.php';
    }

    /**
     * URL of an OAuth endpoint script.
     *
     * @param string $script Script name without extension (authorize, token, register, revoke, prm, asmeta).
     * @return string
     */
    public static function oauth(string $script): string {
        return self::issuer() . '/admin/tool/oauthmcp/oauth/' . $script . '.php';
    }

    /**
     * Whether an RFC 8707 resource parameter denotes this server.
     *
     * @param string $resource Client-supplied resource indicator.
     * @return bool
     */
    public static function is_our_resource(string $resource): bool {
        return self::normalise($resource) === self::normalise(self::resource());
    }

    /**
     * Normalise a URL for comparison (scheme/host lowercased, no trailing slash).
     *
     * @param string $url Any URL.
     * @return string
     */
    public static function normalise(string $url): string {
        $parts = parse_url(trim($url));
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $normalised = strtolower($parts['scheme']) . '://' . strtolower($parts['host']);
        if (!empty($parts['port'])) {
            $normalised .= ':' . $parts['port'];
        }
        $normalised .= rtrim($parts['path'] ?? '', '/');
        return $normalised;
    }
}
