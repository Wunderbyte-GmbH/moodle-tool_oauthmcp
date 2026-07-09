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
 * MCP protocol session persistence.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\mcp;

use cache;
use stdClass;

/**
 * Manages Mcp-Session-Id sessions: DB-backed with a MUC through-cache.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class session_manager {
    /** @var int Minimum seconds between lastseen writes for one session. */
    private const TOUCH_THROTTLE = 60;

    /**
     * Create a new session for an authenticated user.
     *
     * @param int $userid Authenticated user id.
     * @param string $authmode Auth mode the bearer used (wstoken/oauth).
     * @param string $protocolversion Negotiated MCP protocol version.
     * @return stdClass The session record including the generated sid.
     */
    public function create(int $userid, string $authmode, string $protocolversion): stdClass {
        global $DB;

        $record = new stdClass();
        $record->sid = bin2hex(random_bytes(32));
        $record->userid = $userid;
        $record->authmode = $authmode;
        $record->tokenid = null;
        $record->protocolversion = $protocolversion;
        $record->timecreated = time();
        $record->lastseen = time();
        $record->id = $DB->insert_record('tool_oauthmcp_session', $record);

        $this->cache()->set($record->sid, (array)$record);
        return $record;
    }

    /**
     * Look a session up by sid; expired or unknown sessions return null.
     *
     * @param string $sid Session id from the Mcp-Session-Id header.
     * @return stdClass|null
     */
    public function get(string $sid): ?stdClass {
        global $DB;

        if (!preg_match('/^[a-f0-9]{64}$/', $sid)) {
            return null;
        }

        $cached = $this->cache()->get($sid);
        if (is_array($cached)) {
            $session = (object)$cached;
        } else {
            $session = $DB->get_record('tool_oauthmcp_session', ['sid' => $sid]) ?: null;
            if ($session) {
                $this->cache()->set($sid, (array)$session);
            }
        }

        if ($session && (int)$session->lastseen + $this->ttl() < time()) {
            $this->delete($sid);
            return null;
        }

        return $session;
    }

    /**
     * Bump the session's lastseen timestamp (throttled).
     *
     * @param stdClass $session Session record from get().
     * @return void
     */
    public function touch(stdClass $session): void {
        global $DB;

        if ((int)$session->lastseen + self::TOUCH_THROTTLE > time()) {
            return;
        }
        $session->lastseen = time();
        $DB->set_field('tool_oauthmcp_session', 'lastseen', $session->lastseen, ['id' => $session->id]);
        $this->cache()->set($session->sid, (array)$session);
    }

    /**
     * Terminate a session.
     *
     * @param string $sid Session id.
     * @return void
     */
    public function delete(string $sid): void {
        global $DB;

        $DB->delete_records('tool_oauthmcp_session', ['sid' => $sid]);
        $this->cache()->delete($sid);
    }

    /**
     * The session cache.
     *
     * @return cache
     */
    private function cache(): cache {
        return cache::make('tool_oauthmcp', 'sessions');
    }

    /**
     * Configured idle TTL in seconds.
     *
     * @return int
     */
    private function ttl(): int {
        $ttl = (int)get_config('tool_oauthmcp', 'mcpsessionttl');
        return $ttl > 0 ? $ttl : DAYSECS;
    }
}
