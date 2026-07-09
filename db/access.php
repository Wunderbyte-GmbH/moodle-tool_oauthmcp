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
 * Capability definitions for tool_oauthmcp.
 *
 * Both capabilities are deliberately granted to no archetype: connecting an
 * external MCP client to a Moodle account is an explicit admin decision, and
 * archetype changes would never reach existing sites anyway.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Who may use the MCP surface at all (bearer of any auth mode).
    'tool/oauthmcp:connect' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_PERSONAL,
    ],

    // Who may manage OAuth client registrations (phase B admin UI).
    'tool/oauthmcp:manageclients' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [],
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL | RISK_DATALOSS,
    ],
];
