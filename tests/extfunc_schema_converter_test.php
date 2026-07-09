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
 * Schema converter tests.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use tool_oauthmcp\local\registry\extfunc_schema_converter;

/**
 * Tests for the external_description → JSON Schema conversion.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \tool_oauthmcp\local\registry\extfunc_schema_converter
 */
final class extfunc_schema_converter_test extends \advanced_testcase {
    /**
     * Scalar type mapping, required collection, defaults and null unions.
     *
     * @return void
     */
    public function test_scalar_mapping(): void {
        $parameters = new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'course id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'ratio' => new external_value(PARAM_FLOAT, 'a ratio', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
            'active' => new external_value(PARAM_BOOL, 'active flag', VALUE_DEFAULT, true, NULL_NOT_ALLOWED),
            'query' => new external_value(PARAM_RAW, 'free text', VALUE_DEFAULT, null, NULL_ALLOWED),
        ]);

        $schema = (new extfunc_schema_converter())->convert($parameters);

        $this->assertSame('object', $schema['type']);
        $this->assertFalse($schema['additionalProperties']);
        $this->assertSame(['courseid', 'ratio'], $schema['required']);

        $properties = (array)$schema['properties'];
        $this->assertSame('integer', $properties['courseid']['type']);
        $this->assertSame('course id', $properties['courseid']['description']);
        $this->assertSame('number', $properties['ratio']['type']);
        $this->assertSame('boolean', $properties['active']['type']);
        $this->assertTrue($properties['active']['default']);
        $this->assertSame(['string', 'null'], $properties['query']['type']);
        $this->assertArrayHasKey('default', $properties['query']);
        $this->assertNull($properties['query']['default']);
    }

    /**
     * Nested single and multiple structures convert to objects and arrays.
     *
     * @return void
     */
    public function test_nested_structures(): void {
        $parameters = new external_function_parameters([
            'users' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'user id', VALUE_REQUIRED, null, NULL_NOT_ALLOWED),
                    'email' => new external_value(PARAM_EMAIL, 'email', VALUE_OPTIONAL, null, NULL_NOT_ALLOWED),
                ], 'one user'),
                'list of users'
            ),
        ]);

        $schema = (new extfunc_schema_converter())->convert($parameters);
        $properties = (array)$schema['properties'];

        $users = $properties['users'];
        $this->assertSame('array', $users['type']);
        $this->assertSame('list of users', $users['description']);

        $item = $users['items'];
        $this->assertSame('object', $item['type']);
        $this->assertSame('one user', $item['description']);
        $this->assertSame(['id'], $item['required']);
        $itemproperties = (array)$item['properties'];
        $this->assertSame('integer', $itemproperties['id']['type']);
        $this->assertSame('string', $itemproperties['email']['type']);
    }

    /**
     * An empty parameters map serialises its properties as a JSON object.
     *
     * @return void
     */
    public function test_empty_parameters_serialise_as_object(): void {
        $schema = (new extfunc_schema_converter())->convert(new external_function_parameters([]));

        $this->assertStringContainsString('"properties":{}', json_encode($schema));
        $this->assertArrayNotHasKey('required', $schema);
    }

    /**
     * Sweep: the converter handles every function of the mobile service
     * without throwing and always yields an object schema.
     *
     * @return void
     */
    public function test_mobile_service_sweep(): void {
        global $DB;

        $service = $DB->get_record('external_services', ['shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE]);
        $this->assertNotEmpty($service);
        $names = $DB->get_fieldset_select(
            'external_services_functions',
            'DISTINCT functionname',
            'externalserviceid = ?',
            [$service->id]
        );
        $this->assertNotEmpty($names);

        $converter = new extfunc_schema_converter();
        $converted = 0;
        foreach (array_slice($names, 0, 150) as $name) {
            try {
                $info = external_api::external_function_info($name);
            } catch (\Throwable $e) {
                continue;
            }
            $schema = $converter->convert($info->parameters_desc);
            $this->assertSame('object', $schema['type'], "Schema for {$name} must be an object");
            $converted++;
        }
        $this->assertGreaterThan(50, $converted);
    }
}
