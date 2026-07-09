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
 * RFC 9728 protected resource metadata document (anonymous).
 *
 * Referenced from the MCP endpoint's WWW-Authenticate challenge; the
 * webroot alias /.well-known/oauth-protected-resource should rewrite here
 * as belt and braces for clients that probe it directly.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:disable moodle.Files.RequireLogin.Missing

define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../../../config.php');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');

if (!get_config('tool_oauthmcp', 'enabled')) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    die;
}

echo json_encode(\tool_oauthmcp\local\oauth\metadata::protected_resource(), JSON_UNESCAPED_SLASHES);
