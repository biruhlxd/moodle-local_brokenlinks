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

namespace local_brokenlinks\hook;

use local_brokenlinks\local\course_scanner;

/**
 * Lets other plugins check a SCORM package for more problems while a course is scanned.
 *
 * Problems are added to the report with $hook->scanner->record($hook->source + ['location' => $path], ...).
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Check the files of a SCORM package for more problems while a course is scanned.')]
#[\core\attribute\tags('local_brokenlinks')]
final class scorm_package_scanned {
    /**
     * Constructor.
     *
     * @param course_scanner $scanner The scan, to record problems with.
     * @param \cm_info $cm The SCORM activity.
     * @param array $source Where problems in this package are reported: sourcetype, cmid and file area.
     * @param \stored_file[] $files The unpacked files of the package, keyed by path relative to the package root.
     */
    public function __construct(
        /** @var course_scanner */
        public readonly course_scanner $scanner,
        /** @var \cm_info */
        public readonly \cm_info $cm,
        /** @var array */
        public readonly array $source,
        /** @var \stored_file[] */
        public readonly array $files,
    ) {
    }
}
