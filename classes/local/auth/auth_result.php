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
 * Result of a successful bearer authentication.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\auth;

/**
 * Authenticated identity and granted scopes for one request.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auth_result {
    /** @var string The read scope. */
    public const SCOPE_READ = 'mcp:read';

    /** @var string The write scope. */
    public const SCOPE_WRITE = 'mcp:write';

    /** @var int Authenticated Moodle user id. */
    public $userid;

    /** @var string[] Granted scopes (subset of the SCOPE_* constants). */
    public $scopes;

    /** @var string Auth mode that produced this result (wstoken/oauth). */
    public $authmode;

    /**
     * Constructor.
     *
     * @param int $userid Authenticated user id.
     * @param string[] $scopes Granted scopes.
     * @param string $authmode Producing auth mode.
     */
    public function __construct(int $userid, array $scopes, string $authmode) {
        $this->userid = $userid;
        $this->scopes = $scopes;
        $this->authmode = $authmode;
    }
}
