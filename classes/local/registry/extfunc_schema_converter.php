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
 * External function parameter structures to JSON Schema.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\registry;

use core_external\external_description;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use stdClass;

/**
 * Converts external_function_parameters trees into MCP inputSchema objects.
 *
 * The schema advertises shape, not rules: PARAM_* cleaning and full
 * validation stay with validate_parameters() at call time. Re-implementation
 * per requirements decision 3; prior art: onbirdev/moodle-webservice_mcp.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class extfunc_schema_converter {
    /**
     * Convert a function's parameter description to a JSON Schema object.
     *
     * @param external_function_parameters $parameters The function's parameters_desc.
     * @return array JSON Schema (type object).
     */
    public function convert(external_function_parameters $parameters): array {
        return $this->convert_node($parameters);
    }

    /**
     * Convert one node of the external_description tree.
     *
     * @param external_description $node Any external description node.
     * @return array JSON Schema fragment.
     */
    private function convert_node(external_description $node): array {
        if ($node instanceof external_single_structure) {
            return $this->convert_single($node);
        }
        if ($node instanceof external_multiple_structure) {
            $schema = [
                'type' => 'array',
                'items' => $this->convert_node($node->content),
            ];
            return $this->add_common($schema, $node);
        }
        if ($node instanceof external_value) {
            return $this->convert_value($node);
        }

        // Unknown description subtype: advertise a permissive string.
        return $this->add_common(['type' => 'string'], $node);
    }

    /**
     * Convert an external_value leaf.
     *
     * @param external_value $node The value description.
     * @return array JSON Schema fragment.
     */
    private function convert_value(external_value $node): array {
        switch ($node->type) {
            case PARAM_INT:
                $type = 'integer';
                break;
            case PARAM_FLOAT:
            case PARAM_LOCALISEDFLOAT:
                $type = 'number';
                break;
            case PARAM_BOOL:
                $type = 'boolean';
                break;
            default:
                $type = 'string';
        }

        $schema = ['type' => !empty($node->allownull) ? [$type, 'null'] : $type];
        return $this->add_common($schema, $node);
    }

    /**
     * Convert an external_single_structure (including function parameters).
     *
     * @param external_single_structure $node The structure description.
     * @return array JSON Schema fragment.
     */
    private function convert_single(external_single_structure $node): array {
        $properties = new stdClass();
        $required = [];
        foreach ($node->keys as $name => $sub) {
            if (!($sub instanceof external_description)) {
                continue;
            }
            $properties->{$name} = $this->convert_node($sub);
            if ($sub->required == VALUE_REQUIRED) {
                $required[] = $name;
            }
        }

        $schema = [
            'type' => 'object',
            // An stdClass so an empty properties map serialises as {} instead of [].
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if (!empty($required)) {
            $schema['required'] = $required;
        }
        return $this->add_common($schema, $node);
    }

    /**
     * Attach description and default shared by all node types.
     *
     * @param array $schema Schema fragment built so far.
     * @param external_description $node Source node.
     * @return array
     */
    private function add_common(array $schema, external_description $node): array {
        $description = trim((string)($node->desc ?? ''));
        if ($description !== '') {
            $schema['description'] = $description;
        }
        if ($node->required == VALUE_DEFAULT && (is_scalar($node->default) || $node->default === null)) {
            $schema['default'] = $node->default;
        }
        return $schema;
    }
}
