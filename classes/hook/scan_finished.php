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
 * Tells other plugins that a link check of a course has finished, with its results stored.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('A link check of a course has finished.')]
#[\core\attribute\tags('local_brokenlinks')]
final class scan_finished {
    /**
     * Constructor.
     *
     * @param \stdClass $scan The local_brokenlinks_scan record.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $scan,
    ) {
    }
}
