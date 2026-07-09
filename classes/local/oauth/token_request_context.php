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
 * Per-request side channel between token endpoint and repositories.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\oauth;

/**
 * Carries request-scoped facts the library's repository interfaces cannot
 * transport: the RFC 8707 resource parameter (validated by the token
 * endpoint, bound onto the token row at persist time) and the redeemed
 * auth-code id (delivered to finalizeScopes, needed at persist time for
 * code-replay revocation).
 *
 * One HTTP request per PHP process; the endpoint resets the context before
 * dispatching. Tests must call reset() too.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class token_request_context {
    /** @var string|null Validated RFC 8707 resource for the current request. */
    private static $resource = null;

    /** @var string|null Auth code identifier being redeemed in the current request. */
    private static $authcodeid = null;

    /**
     * Clear the context (start of every token request).
     *
     * @return void
     */
    public static function reset(): void {
        self::$resource = null;
        self::$authcodeid = null;
    }

    /**
     * Bind the validated resource for this request.
     *
     * @param string|null $resource Canonical resource URI or null.
     * @return void
     */
    public static function set_resource(?string $resource): void {
        self::$resource = $resource;
    }

    /**
     * The validated resource for this request.
     *
     * @return string|null
     */
    public static function get_resource(): ?string {
        return self::$resource;
    }

    /**
     * Record the auth code being redeemed.
     *
     * @param string|null $authcodeid Auth code identifier.
     * @return void
     */
    public static function set_authcodeid(?string $authcodeid): void {
        self::$authcodeid = $authcodeid;
    }

    /**
     * The auth code being redeemed, if any.
     *
     * @return string|null
     */
    public static function get_authcodeid(): ?string {
        return self::$authcodeid;
    }
}
