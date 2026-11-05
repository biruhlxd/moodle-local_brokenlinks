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
 * Lets other plugins say where inside a SCORM package a reported link is, such as the Rise lesson and block, and give a
 * page that opens the package there.
 *
 * The scan stores the place as JSON in the link's place field, see local_brokenlinks\local\package_places.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\core\attribute\label('Say where inside a SCORM package a reported link is, and give a page that opens it there.')]
#[\core\attribute\tags('local_brokenlinks')]
final class link_place {
    /** @var string Where inside the package the link is, or ''. */
    private string $description = '';

    /** @var string A page that opens the package there, or ''. */
    private string $previewurl = '';

    /**
     * Constructor.
     *
     * @param \stdClass $link A local_brokenlinks_link record, or a record with its fields.
     * @param \cm_info|null $cm The activity the link is in.
     */
    public function __construct(
        /** @var \stdClass */
        public readonly \stdClass $link,
        /** @var \cm_info|null */
        public readonly ?\cm_info $cm,
    ) {
    }

    /**
     * Set the place.
     *
     * @param string $description Plain text, such as "Lesson 4: Safety basics, block 12".
     * @param string $previewurl A page that opens the package there, or ''.
     */
    public function set_place(string $description, string $previewurl = ''): void {
        $this->description = $description;
        $this->previewurl = $previewurl;
    }

    /**
     * Where inside the package the link is.
     *
     * @return string Plain text, or ''.
     */
    public function get_description(): string {
        return $this->description;
    }

    /**
     * A page that opens the package there.
     *
     * @return string
     */
    public function get_previewurl(): string {
        return $this->previewurl;
    }
}
