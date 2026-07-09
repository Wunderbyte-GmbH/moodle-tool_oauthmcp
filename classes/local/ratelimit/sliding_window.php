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
 * Best-effort sliding-window rate limiter.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\ratelimit;

use cache;

/**
 * MUC-backed sliding window counter.
 *
 * Best-effort by design: on multi-node cache splits the count may undershoot.
 * That is acceptable for abuse braking (documented in the implementation
 * plan, WP-B9); hard guarantees would need DB counters.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class sliding_window {
    /**
     * Record one event and report whether the limit is still respected.
     *
     * @param string $key Counter key (e.g. "tools:<userid>").
     * @param int $limit Maximum events per window.
     * @param int $windowseconds Window length in seconds.
     * @return bool True when the event is within the limit.
     */
    public function check(string $key, int $limit, int $windowseconds): bool {
        $cache = cache::make('tool_oauthmcp', 'ratelimit');
        $now = time();
        $cutoff = $now - $windowseconds;

        $stamps = $cache->get(md5($key));
        if (!is_array($stamps)) {
            $stamps = [];
        }
        $stamps = array_values(array_filter($stamps, static function ($stamp) use ($cutoff) {
            return (int)$stamp > $cutoff;
        }));

        if (count($stamps) >= $limit) {
            $cache->set(md5($key), $stamps);
            return false;
        }

        $stamps[] = $now;
        $cache->set(md5($key), $stamps);
        return true;
    }
}
