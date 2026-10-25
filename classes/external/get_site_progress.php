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

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_brokenlinks\local\scan_manager;

/**
 * Progress of "Check all courses", so the overview page can say when it is done.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_site_progress extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * How many courses have been checked, and how many are still waiting or running.
     *
     * @return array
     */
    public static function execute(): array {
        $context = \context_system::instance();
        self::validate_context($context);
        require_capability('local/brokenlinks:viewall', $context);
        $progress = scan_manager::site_progress() ?? ['queuing' => false, 'pending' => 0, 'done' => 0];
        $progress['finished'] = !$progress['queuing'] && $progress['pending'] === 0;
        return $progress;
    }

    /**
     * Return value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'queuing' => new external_value(PARAM_BOOL, 'Courses are still being added to the queue'),
            'pending' => new external_value(PARAM_INT, 'Courses waiting to be checked or being checked'),
            'done' => new external_value(PARAM_INT, 'Courses checked'),
            'finished' => new external_value(PARAM_BOOL, 'Every course has been checked'),
        ]);
    }
}
