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

namespace local_brokenlinks\task;

use local_brokenlinks\local\scan_manager;

/**
 * Background task that queues a link check for every course, started from the overview page.
 *
 * Queueing thousands of courses takes too long for a web request, so the page queues this task instead.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class queue_all_courses extends \core\task\adhoc_task {
    /**
     * Queue a check of each course.
     */
    public function execute() {
        $count = scan_manager::queue_all();
        mtrace("Courses queued for a link check: $count");
    }
}
