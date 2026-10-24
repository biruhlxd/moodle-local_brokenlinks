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
 * Background task that checks the links of one course.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_course extends \core\task\adhoc_task {
    /**
     * Run the scan.
     */
    public function execute() {
        $data = $this->get_custom_data();
        $scan = scan_manager::run((int)$data->scanid, null, !empty($data->notify));
        if (!$scan) {
            return;
        }
        mtrace("Course {$scan->courseid}: {$scan->linkschecked} links checked, " .
            "{$scan->brokencount} broken, {$scan->warningcount} warnings.");
        if ($scan->status == scan_manager::STATUS_FAILED) {
            mtrace('Scan failed: ' . $scan->errormessage);
        }
    }
}
