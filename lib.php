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
 * Callbacks for local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Add the broken links report to the course navigation.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context $context
 */
function local_brokenlinks_extend_navigation_course(navigation_node $navigation, stdClass $course, context $context) {
    if (!has_capability('local/brokenlinks:view', $context)) {
        return;
    }
    $navigation->add(
        get_string('navlabel', 'local_brokenlinks'),
        new moodle_url('/local/brokenlinks/index.php', ['id' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_brokenlinks',
        new pix_icon('i/report', '')
    );
}
