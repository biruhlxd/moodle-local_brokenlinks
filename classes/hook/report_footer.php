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

/**
 * Lets other plugins add sections below the broken links report of a course.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Add sections below the broken links report of a course.')]
#[\core\attribute\tags('local_brokenlinks')]
final class report_footer {
    /** @var string[] HTML added so far. */
    private array $sections = [];

    /**
     * Constructor.
     *
     * @param \stdClass $course
     * @param \context_course $context
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $course,
        /** @var \context_course */
        public readonly \context_course $context,
    ) {
    }

    /**
     * Add a section. The HTML is output as it is, so it must be safe.
     *
     * @param string $html
     */
    public function add_html(string $html): void {
        $this->sections[] = $html;
    }

    /**
     * The sections added.
     *
     * @return string[]
     */
    public function get_sections(): array {
        return $this->sections;
    }
}
