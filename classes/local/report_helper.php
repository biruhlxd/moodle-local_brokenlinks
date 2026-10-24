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

namespace local_brokenlinks\local;

/**
 * Describes where a reported link was found, for the report page and its download.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class report_helper {
    /**
     * Where a link was found.
     *
     * @param \stdClass $link A local_brokenlinks_link record.
     * @param \stdClass $course
     * @param \course_modinfo $modinfo
     * @return array 'where' => name of the activity, section or course, 'whereurl' => link to it or '',
     *     'cm' => \cm_info|null, 'location' => the field or package file
     */
    public static function describe(\stdClass $link, \stdClass $course, \course_modinfo $modinfo): array {
        $where = get_string('source_course', 'local_brokenlinks');
        $whereurl = (new \moodle_url('/course/edit.php', ['id' => $course->id]))->out(false);
        $cm = null;
        $cms = $modinfo->get_cms();
        if ($link->cmid && isset($cms[$link->cmid])) {
            $cm = $cms[$link->cmid];
            $where = $cm->get_formatted_name(['escape' => false]);
            $whereurl = $cm->url ? $cm->url->out(false) : '';
        } else if ($link->sectionid) {
            try {
                $section = $modinfo->get_section_info_by_id($link->sectionid);
                $where = $section ? get_section_name($course, $section) : $where;
                $whereurl = $section ? course_get_url($course, $section->section)->out(false) : $whereurl;
            } catch (\Throwable $e) {
                // The section was deleted after the scan.
                $whereurl = '';
            }
        }

        $location = $link->location;
        if ($link->sourcetype === 'scorm') {
            $location = get_string('location_scormfile', 'local_brokenlinks', $link->location);
        } else if ($location === 'intro' || $location === 'summary') {
            $location = get_string('location_description', 'local_brokenlinks');
        } else if (get_string_manager()->string_exists('location_' . $location, 'local_brokenlinks')) {
            $location = get_string('location_' . $location, 'local_brokenlinks');
        }
        return ['where' => $where, 'whereurl' => $whereurl, 'cm' => $cm, 'location' => $location];
    }
}
