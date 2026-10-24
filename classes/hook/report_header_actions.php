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
 * Lets other plugins add buttons next to "Check links" at the top of the broken links report.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Add buttons at the top of the broken links report of a course.')]
#[\core\attribute\tags('local_brokenlinks')]
final class report_header_actions {
    /** @var string[] HTML of the buttons added so far. */
    private array $actions = [];

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
     * Add a button or link. The HTML is output as it is, so it must be safe.
     *
     * @param string $html
     */
    public function add_action(string $html): void {
        $this->actions[] = $html;
    }

    /**
     * The buttons added.
     *
     * @return string[]
     */
    public function get_actions(): array {
        return $this->actions;
    }
}
