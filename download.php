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
 * Download the broken links report of a course as CSV, Excel and the other data formats.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_brokenlinks\local\report_helper;
use local_brokenlinks\local\scan_manager;
use local_brokenlinks\local\url_checker;

$courseid = required_param('id', PARAM_INT);
$dataformat = required_param('dataformat', PARAM_ALPHA);
$filter = optional_param('filter', 'all', PARAM_ALPHA);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/brokenlinks:view', $context);

$scan = scan_manager::get_latest((int)$course->id);
if (!$scan) {
    redirect(new moodle_url('/local/brokenlinks/index.php', ['id' => $course->id]));
}

$conditions = ['scanid' => $scan->id];
if ($filter === 'broken') {
    $conditions['severity'] = url_checker::BROKEN;
} else if ($filter === 'warning') {
    $conditions['severity'] = url_checker::WARNING;
}
$links = $DB->get_recordset('local_brokenlinks_link', $conditions, 'severity DESC, cmid, sectionid, sourcetype, location, id');

$columns = [
    'status' => get_string('col_status', 'local_brokenlinks'),
    'where' => get_string('col_where', 'local_brokenlinks'),
    'location' => get_string('col_location', 'local_brokenlinks'),
    'url' => get_string('col_link', 'local_brokenlinks'),
    'linktext' => get_string('col_linktext', 'local_brokenlinks'),
    'httpstatus' => get_string('col_httpstatus', 'local_brokenlinks'),
    'problem' => get_string('col_problem', 'local_brokenlinks'),
    'page' => get_string('col_page', 'local_brokenlinks'),
];
$modinfo = get_fast_modinfo($course);
$broken = get_string('severity_broken', 'local_brokenlinks');
$warning = get_string('severity_warning', 'local_brokenlinks');
$filename = clean_filename(get_string('downloadfilename', 'local_brokenlinks', $course->shortname) . '_' .
    userdate($scan->timefinished, '%Y%m%d'));

\core\dataformat::download_data(
    $filename,
    $dataformat,
    $columns,
    $links,
    function (\stdClass $link) use ($course, $modinfo, $broken, $warning): array {
        $source = report_helper::describe($link, $course, $modinfo);
        $row = [
            'status' => $link->severity == url_checker::BROKEN ? $broken : $warning,
            'where' => $source['where'],
            'location' => $source['location'],
            'url' => $link->url,
            'linktext' => (string)$link->linktext,
            'httpstatus' => $link->httpstatus ?: '',
            'problem' => (string)$link->message,
            'page' => $source['whereurl'],
        ];
        // Text from course content must not run as a formula when the file is opened in a spreadsheet.
        return array_map(fn($value) => is_string($value) && preg_match('~^[=+\-@\t\r]~', $value) ? "'" . $value : $value, $row);
    }
);
$links->close();
