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
 * Web service functions of local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_brokenlinks_get_scan_status' => [
        'classname' => \local_brokenlinks\external\get_scan_status::class,
        'description' => 'Whether the link check of a course is still waiting or running.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/brokenlinks:view',
    ],
    'local_brokenlinks_get_site_progress' => [
        'classname' => \local_brokenlinks\external\get_site_progress::class,
        'description' => 'Progress of the check of all courses started from the overview page.',
        'type' => 'read',
        'ajax' => true,
        'capabilities' => 'local/brokenlinks:viewall',
    ],
];
