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
 * Scheduled purge of expired MCP artefacts.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\task;

use core\task\scheduled_task;

/**
 * Purges expired MCP sessions (and, from phase B on, OAuth codes and tokens).
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class purge_expired extends scheduled_task {
    /**
     * Task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_purge_expired', 'tool_oauthmcp');
    }

    /** @var int Days after which an unused DCR client without tokens is removed. */
    private const DCR_STALE_DAYS = 90;

    /**
     * Delete expired sessions, OAuth artefacts and stale DCR clients.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;

        $ttl = (int)get_config('tool_oauthmcp', 'mcpsessionttl');
        if ($ttl <= 0) {
            $ttl = DAYSECS;
        }
        $DB->delete_records_select('tool_oauthmcp_session', 'lastseen < :cutoff', ['cutoff' => time() - $ttl]);

        \tool_oauthmcp\local\oauth\token_service::purge_expired();

        // Dynamically registered clients that never obtained a live grant
        // and sat unused for a quarter free their quota slot again.
        $cutoff = time() - self::DCR_STALE_DAYS * DAYSECS;
        $stale = $DB->get_records_sql(
            "SELECT c.id
               FROM {tool_oauthmcp_client} c
              WHERE c.dcr = 1 AND c.timecreated < :cutoff
                AND NOT EXISTS (
                        SELECT 1 FROM {tool_oauthmcp_token} t
                         WHERE t.clientdbid = c.id AND t.expires > :now AND t.revoked = 0
                    )",
            ['cutoff' => $cutoff, 'now' => time()]
        );
        foreach ($stale as $client) {
            $DB->delete_records('tool_oauthmcp_consent', ['clientdbid' => $client->id]);
            $DB->delete_records('tool_oauthmcp_refresh', ['clientdbid' => $client->id]);
            $DB->delete_records('tool_oauthmcp_token', ['clientdbid' => $client->id]);
            $DB->delete_records('tool_oauthmcp_authcode', ['clientdbid' => $client->id]);
            $DB->delete_records('tool_oauthmcp_client', ['id' => $client->id]);
        }
    }
}
