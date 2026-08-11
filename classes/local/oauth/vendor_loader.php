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
 * Autoloader bootstrap for the vendored OAuth libraries.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

/**
 * Loads the composer autoloader shipped in vendor-oauth2/ exactly once.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class vendor_loader {
    /**
     * Register the vendored autoloader.
     *
     * @return void
     */
    public static function load(): void {
        static $loaded = false;
        if (!$loaded) {
            require_once(__DIR__ . '/../../../vendor-oauth2/autoload.php');
            $loaded = true;
        }
        // Outside the once-guard on purpose: re-asserting an ini value is free, and callers
        // rely on load() guaranteeing the separator, not only the first caller in a request.
        self::fix_arg_separator();
    }

    /**
     * Undo Moodle's global HTML-oriented argument separator for this request.
     *
     * Moodle sets arg_separator.output to '&amp;' (lib/setup.php) so that URLs printed into
     * HTML are well-formed. PHP's documented default is '&', and league/oauth2-server builds
     * redirect URIs with a bare http_build_query() in two places:
     *
     *   Grant/AbstractAuthorizeGrant::makeRedirectUri()
     *   Exception/OAuthServerException::generateHttpResponse()
     *
     * Under Moodle's default those emit "?code=X&amp;state=Y" in an HTTP Location header,
     * where '&amp;' is never valid. The client then reads the parameter as "amp;state",
     * fails its state check and abandons the flow before reaching the token endpoint.
     *
     * Patching the vendored library would be lost on the next update, so the correction lives
     * here instead: every code path that touches the library comes through this loader. Our own
     * http_build_query() calls additionally pass '&' explicitly and do not depend on this.
     *
     * @return void
     */
    private static function fix_arg_separator(): void {
        ini_set('arg_separator.output', '&');
    }
}
