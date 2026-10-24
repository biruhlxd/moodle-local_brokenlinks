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

use local_brokenlinks\local\ignore_list;
use local_brokenlinks\local\scan_manager;
use local_brokenlinks\local\url_checker;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_url_checker.php');

/**
 * Tests for marking links as not broken and checking single links again.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(ignore_list::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
final class ignore_list_test extends \advanced_testcase {
    /** @var string */
    const BROKEN = 'https://broken.test/blocked-by-firewall';

    /** @var string */
    const OTHER = 'https://broken.test/really-gone';

    /** @var \stdClass */
    private $course;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->course = $this->getDataGenerator()->create_course([
            'summary' => '<a href="' . self::BROKEN . '">A</a> <a href="' . self::OTHER . '">B</a>',
        ]);
        $this->getDataGenerator()->create_module('page', [
            'course' => $this->course->id,
            'content' => '<a href="' . self::BROKEN . '">Again</a>',
        ]);
    }

    /**
     * Scan the course with the fake checker.
     *
     * @return array [scan record, number of rows per URL]
     */
    private function scan(): array {
        $scan = scan_manager::run(scan_manager::queue($this->course->id, false), new fake_url_checker());
        return [$scan, $this->rows($scan->id)];
    }

    /**
     * The number of report rows per URL.
     *
     * @param int $scanid
     * @return array
     */
    private function rows(int $scanid): array {
        global $DB;
        $rows = [];
        foreach ($DB->get_records('local_brokenlinks_link', ['scanid' => $scanid]) as $link) {
            $rows[$link->url] = ($rows[$link->url] ?? 0) + 1;
        }
        return $rows;
    }

    public function test_ignored_link_leaves_the_report_and_later_scans(): void {
        global $DB;
        [$scan, $rows] = $this->scan();
        $this->assertSame([self::BROKEN => 2, self::OTHER => 1], $rows);

        ignore_list::add($this->course->id, self::BROKEN);
        ignore_list::add($this->course->id, self::BROKEN);
        $this->assertCount(1, ignore_list::get_all($this->course->id), 'A link is only listed once');
        $this->assertSame([self::OTHER => 1], $this->rows($scan->id));
        $this->assertEquals(1, $DB->get_field('local_brokenlinks_scan', 'brokencount', ['id' => $scan->id]));

        [$scan, $rows] = $this->scan();
        $this->assertSame([self::OTHER => 1], $rows);
        $this->assertEquals(1, $scan->brokencount);

        // Other courses still report it.
        $other = $this->getDataGenerator()->create_course(['summary' => '<a href="' . self::BROKEN . '">A</a>']);
        $otherscan = scan_manager::run(scan_manager::queue($other->id, false), new fake_url_checker());
        $this->assertSame([self::BROKEN => 1], $this->rows($otherscan->id));

        // Taken off the list, it is checked again.
        $record = current(ignore_list::get_all($this->course->id));
        ignore_list::remove($this->course->id, $record->id);
        [, $rows] = $this->scan();
        $this->assertSame([self::BROKEN => 2, self::OTHER => 1], $rows);
    }

    public function test_deleted_course_loses_its_list(): void {
        ignore_list::add($this->course->id, self::BROKEN);
        delete_course($this->course, false);
        $this->assertSame([], ignore_list::get_all($this->course->id));
    }

    public function test_recheck_link_that_works_now(): void {
        global $DB;
        [$scan] = $this->scan();
        $repaired = new class extends fake_url_checker {
            /**
             * Every link works now.
             *
             * @param string $url
             * @return array
             */
            protected function request(string $url): array {
                return $this->classify(200, 0, '');
            }
        };
        $result = scan_manager::recheck($scan->id, self::BROKEN, $repaired);
        $this->assertSame(url_checker::OK, $result['severity']);
        $this->assertSame([self::OTHER => 1], $this->rows($scan->id));
        $this->assertEquals(1, $DB->get_field('local_brokenlinks_scan', 'brokencount', ['id' => $scan->id]));
    }

    public function test_recheck_link_that_still_fails(): void {
        global $DB;
        [$scan] = $this->scan();
        $down = new class extends fake_url_checker {
            /**
             * The website is still down.
             *
             * @param string $url
             * @return array
             */
            protected function request(string $url): array {
                return $this->classify(503, 0, '');
            }
        };
        $result = scan_manager::recheck($scan->id, self::BROKEN, $down);
        $this->assertNotSame(url_checker::OK, $result['severity']);
        $statuses = $DB->get_fieldset_select('local_brokenlinks_link', 'httpstatus', 'scanid = ? AND ' .
            $DB->sql_compare_text('url') . ' = ?', [$scan->id, self::BROKEN]);
        $this->assertEquals([503, 503], $statuses);
        $this->assertSame([self::BROKEN => 2, self::OTHER => 1], $this->rows($scan->id));
    }
}
