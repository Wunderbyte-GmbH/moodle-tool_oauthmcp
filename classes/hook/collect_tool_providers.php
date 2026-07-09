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
 * Hook: collect MCP tool providers from other plugins.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\hook;

use tool_oauthmcp\local\registry\tool_source_interface;

/**
 * Dispatched while the registry is assembled so any plugin can publish its own
 * tools as a first-class source (with native, individually-schema'd MCP tools),
 * instead of going through the generic external-function mapping.
 *
 * A listener adds a {@see tool_source_interface} via {@see self::add_provider()}.
 * Each provider keeps its own source id, so governance and audit events stay
 * attributed per provider.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Allows plugins to publish their own MCP tool sources to the tool_oauthmcp server.')]
#[\core\attribute\tags('tool_oauthmcp', 'mcp')]
class collect_tool_providers {
    /** @var tool_source_interface[] Providers contributed by listeners. */
    private array $providers = [];

    /**
     * Register a tool source.
     *
     * @param tool_source_interface $provider The provider to add.
     * @return void
     */
    public function add_provider(tool_source_interface $provider): void {
        $this->providers[] = $provider;
    }

    /**
     * All providers contributed by listeners.
     *
     * @return tool_source_interface[]
     */
    public function get_providers(): array {
        return $this->providers;
    }
}
