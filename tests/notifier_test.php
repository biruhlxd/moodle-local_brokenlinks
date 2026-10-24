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

use local_brokenlinks\local\notifier;
use local_brokenlinks\local\scan_manager;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_url_checker.php');

/**
 * Tests for broken link notifications.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(notifier::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
final class notifier_test extends \advanced_testcase {
    /** @var \stdClass */
    private $course;

    /** @var \stdClass */
    private $teacher;

    /** @var \stdClass */
    private $student;

    /** @var \stdClass */
    private $page;

    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['fullname' => 'Antenatal care']);
        $this->page = $generator->create_module('page', [
            'course' => $this->course->id,
            'content' => '<a href="https://broken.test/one">One</a> <a href="https://works.test/">Fine</a>',
        ]);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->student = $generator->create_and_enrol($this->course, 'student');
    }

    /**
     * Run a scan of the test course.
     *
     * @param bool $notify
     * @return \stdClass
     */
    private function scan(bool $notify): \stdClass {
        return scan_manager::run(scan_manager::queue($this->course->id, false), new fake_url_checker(), $notify);
    }

    public function test_teachers_are_notified(): void {
        $sink = $this->redirectMessages();
        $this->scan(true);
        $messages = $sink->get_messages_by_component('local_brokenlinks');

        $this->assertCount(1, $messages, 'Only the teacher is notified, not the student');
        $message = reset($messages);
        $this->assertEquals($this->teacher->id, $message->useridto);
        $this->assertSame('brokenlinksfound', $message->eventtype);
        $this->assertStringContainsString('Antenatal care', $message->subject);
        $this->assertStringContainsString('https://broken.test/one', $message->fullmessage);
        $this->assertStringNotContainsString('works.test', $message->fullmessage);
        $this->assertStringContainsString('/local/brokenlinks/index.php?id=' . $this->course->id, $message->fullmessage);
    }

    public function test_only_new_links_are_notified(): void {
        global $DB;
        $sink = $this->redirectMessages();
        $this->scan(true);
        $this->assertCount(1, $sink->get_messages_by_component('local_brokenlinks'));
        $sink->clear();

        // Nothing changed, so the second check sends nothing.
        $this->scan(true);
        $this->assertCount(0, $sink->get_messages_by_component('local_brokenlinks'));

        // A newly broken link is reported on its own.
        $DB->set_field(
            'page',
            'content',
            '<a href="https://broken.test/one">One</a> <a href="https://broken.test/two">Two</a>',
            ['id' => $this->page->id]
        );
        $this->scan(true);
        $messages = $sink->get_messages_by_component('local_brokenlinks');
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertStringContainsString('https://broken.test/two', $message->fullmessage);
        $this->assertStringNotContainsString('https://broken.test/one', $message->fullmessage);
        $this->assertStringContainsString('New broken links found in Antenatal care: 1.', $message->fullmessage);
    }

    public function test_all_links_when_only_new_is_off(): void {
        set_config('notifyonlynew', 0, 'local_brokenlinks');
        $sink = $this->redirectMessages();
        $this->scan(true);
        $sink->clear();
        $this->scan(true);
        $this->assertCount(1, $sink->get_messages_by_component('local_brokenlinks'));
    }

    public function test_no_notification_when_turned_off_or_started_by_hand(): void {
        $sink = $this->redirectMessages();
        // A check that a teacher starts from the report page does not notify.
        $this->scan(false);
        $this->assertCount(0, $sink->get_messages_by_component('local_brokenlinks'));

        set_config('notifyteachers', 0, 'local_brokenlinks');
        $this->scan(true);
        $this->assertCount(0, $sink->get_messages_by_component('local_brokenlinks'));
    }

    public function test_scheduled_task_queues_notifying_scans(): void {
        $this->expectOutputString("Courses queued for a link check: 1\n");
        (new task\scan_all_courses())->execute();
        $tasks = \core\task\manager::get_adhoc_tasks('\local_brokenlinks\task\scan_course');
        $this->assertNotEmpty($tasks);
        foreach ($tasks as $task) {
            $this->assertTrue($task->get_custom_data()->notify);
        }
    }

    public function test_site_summary(): void {
        $this->scan(false);
        $sink = $this->redirectMessages();

        // The summary is off by default.
        $this->assertSame(0, notifier::send_site_summary(time() - WEEKSECS));

        set_config('adminsummary', 1, 'local_brokenlinks');
        $this->assertSame(1, notifier::send_site_summary(time() - WEEKSECS));
        $messages = $sink->get_messages_by_component('local_brokenlinks');
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertEquals(get_admin()->id, $message->useridto);
        $this->assertSame('sitesummary', $message->eventtype);
        $this->assertStringContainsString('Antenatal care: 1 broken', $message->fullmessage);

        // Checks older than the period are left out.
        $sink->clear();
        $this->assertSame(0, notifier::send_site_summary(time() + 10));
    }
}
