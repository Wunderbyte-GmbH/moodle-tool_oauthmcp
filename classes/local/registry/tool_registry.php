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
 * Tool registry: aggregates sources, applies governance and scopes.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\registry;

use cache;
use core\context;
use stdClass;
use tool_oauthmcp\event\tool_called;

/**
 * The single chokepoint every tools/list and tools/call goes through.
 *
 * Applies, in order: source aggregation with collision guard, per-tool
 * admin governance (enable/disable, read-only classification), scope
 * filtering, and audit events for every call including denials.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_registry {
    /** @var tool_source_interface[] */
    private $sources;

    /**
     * Constructor.
     *
     * @param tool_source_interface[] $sources Ordered tool sources.
     */
    public function __construct(array $sources) {
        $this->sources = $sources;
    }

    /**
     * Build the registry with the default source set.
     *
     * Sources are the built-in external-function mapping plus any source a plugin
     * contributes through the collect_tool_providers hook (its skills as native,
     * individually-schema'd MCP tools).
     *
     * @return self
     */
    public static function create(): self {
        $sources = [new extfunc_tool_source()];
        $hook = new \tool_oauthmcp\hook\collect_tool_providers();
        \core\di::get(\core\hook\manager::class)->dispatch($hook);
        foreach ($hook->get_providers() as $provider) {
            $sources[] = $provider;
        }
        return new self($sources);
    }

    /**
     * MCP tool definitions visible to this user with these scopes.
     *
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @param string[] $scopes Scopes granted to the bearer.
     * @return array
     */
    public function list_tools(int $userid, int $contextid, array $scopes): array {
        $tools = [];
        $seen = [];
        foreach ($this->sources as $source) {
            $sourceid = $source->get_source_id();
            $governance = $this->governance_records($sourceid);
            foreach ($source->list_tools($userid, $contextid) as $definition) {
                $name = (string)($definition['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                if (isset($seen[$name])) {
                    // Two sources colliding on one tool name would silently
                    // shadow each other — skip the later one loudly instead.
                    debugging("tool_oauthmcp: tool name collision for {$name}", DEBUG_DEVELOPER);
                    continue;
                }
                $seen[$name] = true;

                $record = $governance[$name] ?? $this->governance_record($sourceid, $name);
                if (empty($record->enabled)) {
                    continue;
                }
                $definition = $this->apply_governance($sourceid, $definition, $record);
                if (!in_array($definition['scope'], $scopes, true)) {
                    continue;
                }
                unset($definition['scope']);
                $tools[] = $definition;
            }
        }
        return $tools;
    }

    /**
     * Execute one tool call, enforcing governance and scopes.
     *
     * @param string $toolname Tool name.
     * @param array $args Tool arguments.
     * @param int $userid Acting user id.
     * @param int $contextid Ambient context id.
     * @param string[] $scopes Scopes granted to the bearer.
     * @param string $idempotencykey Per-request key for replay protection.
     * @return array MCP-shaped result (content / structuredContent / isError).
     */
    public function call_tool(
        string $toolname,
        array $args,
        int $userid,
        int $contextid,
        array $scopes,
        string $idempotencykey,
        string $sessionid = ''
    ): array {
        foreach ($this->sources as $source) {
            $definition = $source->get_tool($toolname, $userid, $contextid);
            if ($definition === null) {
                continue;
            }
            $sourceid = $source->get_source_id();

            $record = $this->governance_record($sourceid, $toolname);
            if (empty($record->enabled)) {
                $this->trigger_event($sourceid, $toolname, 'denied_disabled', $contextid);
                return $this->error_result(
                    get_string('mcp_error_tool_disabled', 'tool_oauthmcp', s($toolname)),
                    ['TOOL_DISABLED']
                );
            }

            $definition = $this->apply_governance($sourceid, $definition, $record);
            if (!in_array($definition['scope'], $scopes, true)) {
                $this->trigger_event($sourceid, $toolname, 'denied_scope', $contextid);
                return $this->error_result(
                    get_string('mcp_error_scope_denied', 'tool_oauthmcp', s($toolname)),
                    ['TOOL_SCOPE_DENIED']
                );
            }

            try {
                $result = $source->call_tool($toolname, $args, $userid, $contextid, $idempotencykey, $sessionid);
            } catch (\Throwable $e) {
                $this->trigger_event($sourceid, $toolname, 'error', $contextid);
                return $this->error_result(
                    get_string('mcp_error_tool_failed', 'tool_oauthmcp', s($e->getMessage())),
                    ['TOOL_EXECUTION_FAILED']
                );
            }

            $this->trigger_event($sourceid, $toolname, empty($result['isError']) ? 'executed' : 'error', $contextid);
            return $result;
        }

        $this->trigger_event('unknown', $toolname, 'denied_unknown', $contextid);
        return $this->error_result(
            get_string('mcp_error_unknown_tool', 'tool_oauthmcp', s($toolname)),
            ['TOOL_UNKNOWN']
        );
    }

    /**
     * Full inventory for the governance page: every tool with its record.
     *
     * @param int $userid Acting user id (used for source listing only).
     * @param int $contextid Ambient context id.
     * @return array Rows of ['source' => string, 'definition' => array, 'record' => stdClass].
     */
    public function get_inventory(int $userid, int $contextid): array {
        $rows = [];
        foreach ($this->sources as $source) {
            $sourceid = $source->get_source_id();
            $governance = $this->governance_records($sourceid);
            foreach ($source->list_tools($userid, $contextid) as $definition) {
                $name = (string)($definition['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $record = $governance[$name] ?? $this->governance_record($sourceid, $name);
                $rows[] = [
                    'source' => $sourceid,
                    'definition' => $this->apply_governance($sourceid, $definition, $record),
                    'record' => $record,
                ];
            }
        }
        return $rows;
    }

    /**
     * Invalidate cached tools/list results after governance changes.
     *
     * @return void
     */
    public static function purge_tool_list_cache(): void {
        cache::make('tool_oauthmcp', 'toollist')->purge();
    }

    /**
     * All governance records of one source keyed by tool name.
     *
     * @param string $source Source id.
     * @return stdClass[]
     */
    private function governance_records(string $source): array {
        global $DB;

        return $DB->get_records(
            'tool_oauthmcp_tool',
            ['source' => $source],
            '',
            'toolname, id, source, enabled, isreadonly, timemodified'
        );
    }

    /**
     * Fetch or lazily create the governance record for one tool.
     *
     * @param string $source Source id.
     * @param string $toolname Tool name.
     * @return stdClass
     */
    private function governance_record(string $source, string $toolname): stdClass {
        global $DB;

        $record = $DB->get_record('tool_oauthmcp_tool', ['source' => $source, 'toolname' => $toolname]);
        if ($record) {
            return $record;
        }

        $record = new stdClass();
        $record->source = $source;
        $record->toolname = $toolname;
        $record->enabled = 1;
        $record->isreadonly = 0;
        $record->timemodified = time();
        try {
            $record->id = $DB->insert_record('tool_oauthmcp_tool', $record);
        } catch (\dml_exception $e) {
            // Concurrent lazy creation lost the race on the unique index.
            $record = $DB->get_record('tool_oauthmcp_tool', ['source' => $source, 'toolname' => $toolname], '*', MUST_EXIST);
        }
        return $record;
    }

    /**
     * Apply the admin's read-only classification to an extfunc definition.
     *
     * Hook-provided tools (phase C) carry their own scope label and are not
     * reclassified here.
     *
     * @param string $source Source id.
     * @param array $definition Tool definition.
     * @param stdClass $record Governance record.
     * @return array
     */
    private function apply_governance(string $source, array $definition, stdClass $record): array {
        if ($source === extfunc_tool_source::SOURCE_ID) {
            $readonly = !empty($record->isreadonly);
            $definition['scope'] = $readonly
                ? \tool_oauthmcp\local\auth\auth_result::SCOPE_READ
                : \tool_oauthmcp\local\auth\auth_result::SCOPE_WRITE;
            $definition['annotations']['readOnlyHint'] = $readonly;
            $definition['annotations']['destructiveHint'] = !$readonly;
        }
        return $definition;
    }

    /**
     * Build an MCP error tool-result.
     *
     * @param string $message Human/model-facing message.
     * @param string[] $issuecodes Machine-readable issue codes.
     * @return array
     */
    private function error_result(string $message, array $issuecodes): array {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'structuredContent' => ['issue_codes' => $issuecodes],
            'isError' => true,
        ];
    }

    /**
     * Trigger the audit event for one call outcome.
     *
     * @param string $source Source id.
     * @param string $toolname Tool name.
     * @param string $status executed | error | denied_disabled | denied_scope | denied_unknown.
     * @param int $contextid Ambient context id.
     * @return void
     */
    private function trigger_event(string $source, string $toolname, string $status, int $contextid): void {
        tool_called::create([
            'context' => context::instance_by_id($contextid),
            'other' => [
                'source' => $source,
                'tool' => clean_param($toolname, PARAM_NOTAGS),
                'status' => $status,
            ],
        ])->trigger();
    }
}
