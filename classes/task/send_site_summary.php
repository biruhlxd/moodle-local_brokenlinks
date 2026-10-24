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

use local_brokenlinks\local\notifier;

/**
 * Scheduled task that sends site administrators a weekly summary of broken links.
 *
 * It does nothing unless the summary is turned on in the plugin settings.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class send_site_summary extends \core\task\scheduled_task {
    /**
     * Name shown on the scheduled tasks page.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_sendsitesummary', 'local_brokenlinks');
    }

    /**
     * Send the summary of the last week's checks.
     */
    public function execute() {
        $sent = notifier::send_site_summary(time() - WEEKSECS);
        mtrace("Sent the broken links summary to $sent administrators.");
    }
}
