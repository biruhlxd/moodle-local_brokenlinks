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
 * Tests for the web service that follows the check of all courses.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(get_site_progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
final class get_site_progress_test extends \core_external\tests\externallib_testcase {
    /**
     * The progress as the overview page receives it.
     *
     * @return array
     */
    private function progress(): array {
        return \core_external\external_api::clean_returnvalue(
            get_site_progress::execute_returns(),
            get_site_progress::execute()
        );
    }

    public function test_progress_follows_the_check_of_all_courses(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->create_course();
        $this->assertTrue($this->progress()['finished'], 'Nothing was started');

        scan_manager::start_site_check();
        $this->assertSame(['queuing' => true, 'pending' => 0, 'done' => 0, 'finished' => false], $this->progress());

        // The background task adds every course to the queue.
        ob_start();
        $this->runAdhocTasks('\local_brokenlinks\task\queue_all_courses');
        ob_end_clean();
        $this->assertSame(['queuing' => false, 'pending' => 2, 'done' => 0, 'finished' => false], $this->progress());

        // Each course is checked.
        $scanids = $DB->get_fieldset_select('local_brokenlinks_scan', 'id', 'status = ?', [scan_manager::STATUS_QUEUED]);
        scan_manager::run((int)$scanids[0], new fake_url_checker());
        $this->assertSame(['queuing' => false, 'pending' => 1, 'done' => 1, 'finished' => false], $this->progress());
        scan_manager::run((int)$scanids[1], new fake_url_checker());
        $this->assertSame(['queuing' => false, 'pending' => 0, 'done' => 2, 'finished' => true], $this->progress());
    }

    public function test_only_site_managers_can_ask(): void {
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $this->expectException(\required_capability_exception::class);
        get_site_progress::execute();
    }
}
