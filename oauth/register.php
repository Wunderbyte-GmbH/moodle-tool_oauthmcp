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
 * Dynamic client registration endpoint (RFC 7591, anonymous by design).
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
header('X-Content-Type-Options: nosniff');

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'invalid_request', 'error_description' => 'POST required']);
    die;
}

$metadata = json_decode(file_get_contents('php://input'), true);
if (!is_array($metadata)) {
    http_response_code(400);
    echo json_encode(['error' => 'invalid_client_metadata', 'error_description' => 'Body must be a JSON object']);
    die;
}

$result = (new \tool_oauthmcp\local\oauth\dcr_service())->register($metadata, getremoteaddr());
http_response_code($result['status']);
echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
