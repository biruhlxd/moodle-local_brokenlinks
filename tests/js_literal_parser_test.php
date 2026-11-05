<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_brokenlinks;

use local_brokenlinks\local\js_literal_parser;

/**
 * Tests for reading JavaScript object literals.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(js_literal_parser::class)]
final class js_literal_parser_test extends \basic_testcase {
    public function test_values(): void {
        $js = "if(!window.cp)window.cp={};cp.D = cp.model.data = {pref:{acc:1,rkt:0},'quoted key':\"two\"," .
            "list:[1,-2.5,true,false,null,undefined],Image_2c:{sr:cp.fd,ip:'dr/a.png',dn:'Image_2'}," .
            "text:'It\\'s \\\"fine\\\"\\nnext\\u00e9',empty:{},arr:[{n:'si1',t:15},{n:'si2',t:19}]," .
            "// a comment\n call:cp.ho('x',2),last:1};cp.other={};";
        $data = js_literal_parser::parse_assignment($js, 'cp.D = cp.model.data =');

        $this->assertSame(['acc' => 1, 'rkt' => 0], $data['pref']);
        $this->assertSame('two', $data['quoted key']);
        $this->assertSame([1, -2.5, true, false, null, null], $data['list']);
        $this->assertSame('cp.fd', $data['Image_2c']['sr'], 'References to player variables are kept as text');
        $this->assertSame('dr/a.png', $data['Image_2c']['ip']);
        $this->assertSame("It's \"fine\"\nnexté", $data['text']);
        $this->assertSame([], $data['empty']);
        $this->assertSame([['n' => 'si1', 't' => 15], ['n' => 'si2', 't' => 19]], $data['arr']);
        $this->assertSame("cp.ho('x',2)", $data['call']);
        $this->assertSame(1, $data['last']);
    }

    public function test_line_continuation(): void {
        $data = js_literal_parser::parse_assignment("x = {oca:'first\\\nsecond'};", 'x =');
        $this->assertSame('firstsecond', $data['oca']);
    }

    public function test_bad_input(): void {
        $this->assertNull(js_literal_parser::parse_assignment('cp.D = cp.model.data = {a:1,b:\'unterminated', 'cp.D'));
        $this->assertNull(js_literal_parser::parse_assignment('nothing here', 'cp.D'));
        $this->assertNull(js_literal_parser::parse_assignment(str_repeat('{a:', 100) . '1' . str_repeat('}', 100), ''));
        // A stray bracket must stop the parser, not make it read the same character for ever.
        $this->assertNull(js_literal_parser::parse_assignment('x = {a:[1, )]};', 'x ='));
        $this->assertNull(js_literal_parser::parse_assignment('x = {a:)};', 'x ='));
    }
}
