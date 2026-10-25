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

namespace local_brokenlinks\external;

use local_brokenlinks\fake_url_checker;
use local_brokenlinks\local\scan_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/fake_url_checker.php');

/**
 * Tests for the web service that reports whether a link check is still running.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_scan_status::class)]
final class get_scan_status_test extends \core_external\tests\externallib_testcase {
    /**
     * The status as the report page receives it.
     *
     * @param int $courseid
     * @return string
     */
    private function scan_status(int $courseid): string {
        $result = get_scan_status::execute($courseid);
        return \core_external\external_api::clean_returnvalue(get_scan_status::execute_returns(), $result)['status'];
    }

    public function test_status_follows_the_check(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->setUser($teacher);

        $this->assertSame('none', $this->scan_status($course->id));
        $scanid = scan_manager::queue($course->id, false);
        $this->assertSame('queued', $this->scan_status($course->id));
        $DB->set_field('local_brokenlinks_scan', 'status', scan_manager::STATUS_RUNNING, ['id' => $scanid]);
        $this->assertSame('running', $this->scan_status($course->id));
        $DB->set_field('local_brokenlinks_scan', 'status', scan_manager::STATUS_QUEUED, ['id' => $scanid]);
        scan_manager::run($scanid, new fake_url_checker());
        $this->assertSame('finished', $this->scan_status($course->id));
    }

    public function test_students_cannot_ask(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'student'));
        $this->expectException(\required_capability_exception::class);
        get_scan_status::execute($course->id);
    }
}
