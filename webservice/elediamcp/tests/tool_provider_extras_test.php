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
use webservice_elediamcp\local\protocol;
use webservice_elediamcp\local\tool_provider;

/**
 * Tests for tools/list pagination and annotation inference.
 *
 * @package     webservice_elediamcp
 * @author      Christopher Reimann <christopher.reimann@eledia.de>
 * @copyright   2026 eLeDia GmbH, Berlin
 * @link        https://eledia.de
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers      \webservice_elediamcp\local\tool_provider
 */
final class tool_provider_extras_test extends advanced_testcase {
    /**
     * Pagination slices a tool list and emits a stable opaque cursor.
     */
    public function test_paginate_slices_and_emits_cursor(): void {
        $this->resetAfterTest(true);

        $tools = [];
        for ($i = 0; $i < 12; $i++) {
            $tools[] = ['name' => 'tool_' . $i];
        }

        $first = tool_provider::paginate($tools, null, 5);
        $this->assertCount(5, $first['tools']);
        $this->assertSame('tool_0', $first['tools'][0]['name']);
        $this->assertNotNull($first['nextCursor']);

        $second = tool_provider::paginate($tools, $first['nextCursor'], 5);
        $this->assertCount(5, $second['tools']);
        $this->assertSame('tool_5', $second['tools'][0]['name']);
        $this->assertNotNull($second['nextCursor']);

        $third = tool_provider::paginate($tools, $second['nextCursor'], 5);
        $this->assertCount(2, $third['tools']);
        $this->assertSame('tool_10', $third['tools'][0]['name']);
        $this->assertNull($third['nextCursor']);
    }

    /**
     * Invalid cursors are treated as the start of the list.
     */
    public function test_paginate_ignores_invalid_cursor(): void {
        $this->resetAfterTest(true);

        $tools = [['name' => 'a'], ['name' => 'b']];
        $page = tool_provider::paginate($tools, '!!!not-base64!!!', 5);

        $this->assertCount(2, $page['tools']);
        $this->assertNull($page['nextCursor']);
    }

    /**
     * Annotation inference correctly classifies read-only, destructive and write functions.
     */
    public function test_infer_annotations(): void {
        $cases = [
            'core_course_get_contents' => ['readOnlyHint' => true, 'destructiveHint' => false],
            'core_enrol_get_users_courses' => ['readOnlyHint' => true, 'destructiveHint' => false],
            'core_course_delete_courses' => ['readOnlyHint' => false, 'destructiveHint' => true],
            'mod_assign_submit_for_grading' => ['readOnlyHint' => false, 'destructiveHint' => false],
            'core_message_send_instant_messages' => ['readOnlyHint' => false, 'destructiveHint' => false],
        ];

        foreach ($cases as $name => $expected) {
            $info = (object) ['name' => $name, 'description' => 'desc'];
            $annot = tool_provider::infer_annotations($info);
            $this->assertSame(
                $expected['readOnlyHint'],
                $annot['readOnlyHint'],
                "readOnlyHint for {$name} should match expected value."
            );
            $this->assertSame(
                $expected['destructiveHint'],
                $annot['destructiveHint'],
                "destructiveHint for {$name} should match expected value."
            );
        }
    }

    /**
     * The AI-native catalogue is always present and uses the canonical output shape.
     */
    public function test_ai_tools_in_catalogue(): void {
        $this->resetAfterTest(true);

        $tools = tool_provider::get_ai_tools(protocol::LATEST);
        $names = array_column($tools, 'name');

        $this->assertContains('moodle_me', $names);
        $this->assertContains('moodle_verify_user_context', $names);

        foreach ($tools as $tool) {
            $this->assertArrayHasKey('inputSchema', $tool);
            $this->assertArrayHasKey('outputSchema', $tool);
            $this->assertArrayHasKey('annotations', $tool);
            $this->assertArrayHasKey('title', $tool);

            // outputSchema must NOT have the legacy {result:...} wrapper.
            if (!empty($tool['outputSchema']['properties'])
                && is_array($tool['outputSchema']['properties'])) {
                $this->assertArrayNotHasKey('result', $tool['outputSchema']['properties']);
            }
        }
    }
}
