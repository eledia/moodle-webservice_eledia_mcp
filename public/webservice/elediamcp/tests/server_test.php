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

namespace webservice_elediamcp;

use advanced_testcase;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use moodle_exception;
use Exception;
use ReflectionClass;
use webservice_elediamcp\local\request;
use webservice_elediamcp\local\server;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/webservice/lib.php');

/**
 * Tests for MCP server class.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2025 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\server
 */
final class server_test extends advanced_testcase {
    /**
     * Test server instantiation.
     */
    public function test_server_instantiation(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $this->assertInstanceOf(server::class, $server);
    }

    /**
     * Test is_tool_call method with tools/call request.
     */
    public function test_is_tool_call_true(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertTrue($result);
    }

    /**
     * Test is_tool_call method with non-tools/call request.
     */
    public function test_is_tool_call_false(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to access private method.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        // Create a mock request.
        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'id' => 1,
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        $method = $reflection->getMethod('is_tool_call');
        $method->setAccessible(true);

        $result = $method->invoke($server);

        $this->assertFalse($result);
    }

    /**
     * Test extract_tool_call with valid request.
     */
    public function test_extract_tool_call_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [
                'name' => 'test_function',
                'arguments' => ['param1' => 'value1'],
            ],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Call extract_tool_call.
        $server->extract_tool_call();

        // Verify functionname was set.
        $functionnameprop = $reflection->getProperty('functionname');
        $functionnameprop->setAccessible(true);
        $functionname = $functionnameprop->getValue($server);

        $this->assertEquals('test_function', $functionname);

        // Verify parameters were set.
        $parametersprop = $reflection->getProperty('parameters');
        $parametersprop->setAccessible(true);
        $parameters = $parametersprop->getValue($server);

        $this->assertEquals(['param1' => 'value1'], $parameters);
    }

    /**
     * Test extract_tool_call with missing tool name.
     */
    public function test_extract_tool_call_missing_name(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        // Use reflection to set up the request.
        $reflection = new ReflectionClass($server);
        $mcprequestprop = $reflection->getProperty('mcprequest');
        $mcprequestprop->setAccessible(true);

        $requestdata = [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'id' => 1,
            'params' => [],
        ];
        $request = new request($requestdata);
        $mcprequestprop->setValue($server, $request);

        // Expect exception.
        $this->expectException(moodle_exception::class);
        $this->expectExceptionMessage(get_string('err_missing_tool_name', 'webservice_elediamcp'));

        $server->extract_tool_call();
    }

    /**
     * Test extract_token from URL parameter.
     */
    public function test_extract_token_from_url(): void {
        $this->resetAfterTest(true);

        // Opt in: query-string tokens are off by default in 0.5.0+.
        set_config('allow_token_in_query', 1, 'webservice_elediamcp');

        $_GET['wstoken'] = 'test_token_123';

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('extract_token');
        $method->setAccessible(true);

        $token = $method->invoke($server);

        $this->assertEquals('test_token_123', $token);

        unset($_GET['wstoken']);
    }

    /**
     * Test extract_token returns null when query tokens are disabled.
     */
    public function test_extract_token_query_disabled_by_default(): void {
        $this->resetAfterTest(true);

        $_GET['wstoken'] = 'test_token_disabled';

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('extract_token');
        $method->setAccessible(true);

        $token = $method->invoke($server);

        $this->assertNull($token);

        unset($_GET['wstoken']);
    }

    /**
     * Test extract_token from POST parameter.
     */
    public function test_extract_token_from_post(): void {
        $this->resetAfterTest(true);

        set_config('allow_token_in_query', 1, 'webservice_elediamcp');

        $_POST['wstoken'] = 'test_token_456';

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('extract_token');
        $method->setAccessible(true);

        $token = $method->invoke($server);

        $this->assertEquals('test_token_456', $token);

        unset($_POST['wstoken']);
    }

    /**
     * Test generate_error method.
     */
    public function test_generate_error(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('generate_error');
        $method->setAccessible(true);

        $exception = new Exception('Test error message');
        $error = $method->invoke($server, $exception);

        $this->assertIsArray($error);
        $this->assertArrayHasKey('jsonrpc', $error);
        $this->assertArrayHasKey('error', $error);
        $this->assertArrayHasKey('id', $error);

        $this->assertEquals('2.0', $error['jsonrpc']);
        $this->assertArrayHasKey('code', $error['error']);
        $this->assertArrayHasKey('message', $error['error']);
    }

    /**
     * Test safe_json_encode with valid data.
     */
    public function test_safe_json_encode_valid(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['key' => 'value', 'number' => 123];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test safe_json_encode with unicode characters.
     */
    public function test_safe_json_encode_unicode(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('safe_json_encode');
        $method->setAccessible(true);

        $data = ['text' => 'Hello 世界 🌍'];
        $json = $method->invoke($server, $data);

        $this->assertIsString($json);
        $this->assertStringContainsString('Hello', $json);
        $decoded = json_decode($json, true);
        $this->assertEquals($data, $decoded);
    }

    /**
     * Test server constants are defined.
     */
    public function test_server_constants(): void {
        $this->resetAfterTest(true);

        $reflection = new ReflectionClass(server::class);

        $this->assertTrue($reflection->hasConstant('SERVER_NAME'));
        $this->assertTrue($reflection->hasConstant('SERVER_VERSION'));

        $servername = $reflection->getConstant('SERVER_NAME');
        $serverversion = $reflection->getConstant('SERVER_VERSION');

        $this->assertIsString($servername);
        $this->assertIsString($serverversion);
        $this->assertEquals('Moodle MCP Server', $servername);
        // Advertised server version must be a semantic version string.
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $serverversion);

        // Protocol versions are now exposed through the protocol class.
        $this->assertSame('2025-11-25', \webservice_elediamcp\local\protocol::LATEST);
        $this->assertSame('2025-03-26', \webservice_elediamcp\local\protocol::LEGACY);
        $this->assertContains('2025-11-25', \webservice_elediamcp\local\protocol::SUPPORTED);
        $this->assertContains('2025-03-26', \webservice_elediamcp\local\protocol::SUPPORTED);
    }

    /**
     * Test coerce_value converts empty string to empty array for external_multiple_structure.
     */
    public function test_coerce_value_empty_string_to_array(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_value');
        $method->setAccessible(true);

        $desc = new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Item')
        );

        // Empty string should become empty array.
        $result = $method->invoke($server, '', $desc);
        $this->assertEquals([], $result);

        // Null should become empty array.
        $result = $method->invoke($server, null, $desc);
        $this->assertEquals([], $result);

        // Whitespace-only string should become empty array.
        $result = $method->invoke($server, '   ', $desc);
        $this->assertEquals([], $result);
    }

    /**
     * Test coerce_value preserves valid arrays for external_multiple_structure.
     */
    public function test_coerce_value_valid_array_preserved(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_value');
        $method->setAccessible(true);

        $desc = new external_multiple_structure(
            new external_value(PARAM_TEXT, 'Item')
        );

        $result = $method->invoke($server, ['item1', 'item2'], $desc);
        $this->assertEquals(['item1', 'item2'], $result);
    }

    /**
     * Test coerce_value converts non-array to empty array for external_single_structure.
     */
    public function test_coerce_value_non_array_to_object(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_value');
        $method->setAccessible(true);

        $desc = new external_single_structure([
            'name' => new external_value(PARAM_TEXT, 'Name'),
        ]);

        // Non-array string should become empty array.
        $result = $method->invoke($server, 'invalid', $desc);
        $this->assertEquals([], $result);
    }

    /**
     * Test coerce_value recursively coerces nested structures.
     */
    public function test_coerce_value_recursive(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_value');
        $method->setAccessible(true);

        $desc = new external_single_structure([
            'courseid' => new external_value(PARAM_INT, 'Course ID'),
            'options' => new external_multiple_structure(
                new external_single_structure([
                    'name' => new external_value(PARAM_TEXT, 'Option name'),
                    'value' => new external_value(PARAM_TEXT, 'Option value'),
                ])
            ),
        ]);

        // Simulate LLM sending empty string for optional array param.
        $input = ['courseid' => '5', 'options' => ''];
        $result = $method->invoke($server, $input, $desc);

        $this->assertEquals(5, $result['courseid']);
        $this->assertEquals([], $result['options']);
    }

    /**
     * Test coerce_scalar converts numeric string to int for PARAM_INT.
     */
    public function test_coerce_scalar_int(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_scalar');
        $method->setAccessible(true);

        $desc = new external_value(PARAM_INT, 'Test int');

        $this->assertSame(42, $method->invoke($server, '42', $desc));
        $this->assertSame(0, $method->invoke($server, '0', $desc));
        $this->assertEquals('not_a_number', $method->invoke($server, 'not_a_number', $desc));
    }

    /**
     * Test coerce_scalar converts numeric string to float for PARAM_FLOAT.
     */
    public function test_coerce_scalar_float(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_scalar');
        $method->setAccessible(true);

        $desc = new external_value(PARAM_FLOAT, 'Test float');

        $this->assertSame(3.14, $method->invoke($server, '3.14', $desc));
        $this->assertSame(0.0, $method->invoke($server, '0', $desc));
    }

    /**
     * Test coerce_scalar converts string booleans for PARAM_BOOL.
     */
    public function test_coerce_scalar_bool(): void {
        $this->resetAfterTest(true);

        $server = new server(WEBSERVICE_AUTHMETHOD_PERMANENT_TOKEN);

        $reflection = new ReflectionClass($server);
        $method = $reflection->getMethod('coerce_scalar');
        $method->setAccessible(true);

        $desc = new external_value(PARAM_BOOL, 'Test bool');

        $this->assertTrue($method->invoke($server, 'true', $desc));
        $this->assertTrue($method->invoke($server, '1', $desc));
        $this->assertTrue($method->invoke($server, 'yes', $desc));
        $this->assertFalse($method->invoke($server, 'false', $desc));
        $this->assertFalse($method->invoke($server, '0', $desc));
        $this->assertFalse($method->invoke($server, 'no', $desc));
        $this->assertFalse($method->invoke($server, '', $desc));
    }
}
