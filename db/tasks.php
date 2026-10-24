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

/**
 * Scheduled tasks for local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_brokenlinks\task\scan_all_courses',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '2',
        'day' => '*',
        'dayofweek' => '0',
        'month' => '*',
        'disabled' => 1,
    ],
    [
        // Runs on Monday morning, after the weekly check of all courses on Sunday night.
        'classname' => '\local_brokenlinks\task\send_site_summary',
        'blocking' => 0,
        'minute' => '0',
        'hour' => '7',
        'day' => '*',
        'dayofweek' => '1',
        'month' => '*',
    ],
];
