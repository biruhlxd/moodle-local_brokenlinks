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

namespace local_brokenlinks;

/**
 * URL checker that answers without network access: URLs containing "broken" return 404, "slow" times out.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class fake_url_checker extends \local_brokenlinks\local\url_checker {
    /** @var string[] Every URL that was requested. */
    public $requests = [];

    /**
     * Constructor: no caching, so each test sees its own requests.
     */
    public function __construct() {
        parent::__construct(5, 0);
    }

    /**
     * Pretend to request the URL.
     *
     * @param string $url
     * @return array
     */
    protected function request(string $url): array {
        $this->requests[] = $url;
        // Home pages work, unless the website refuses this server.
        if (in_array((string)parse_url($url, PHP_URL_PATH), ['', '/'])) {
            return str_contains($url, 'refusing') ? $this->classify(404, 0, '') : $this->classify(200, 0, '');
        }
        if (str_contains($url, 'broken') || str_contains($url, 'refusing')) {
            return $this->classify(404, 0, '');
        }
        if (str_contains($url, 'badrequest')) {
            return $this->classify(400, 0, '');
        }
        if (str_contains($url, 'slow')) {
            return $this->classify(0, CURLE_OPERATION_TIMEOUTED, 'timed out');
        }
        return $this->classify(200, 0, '');
    }
}
