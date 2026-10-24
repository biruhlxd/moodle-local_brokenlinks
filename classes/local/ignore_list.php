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
 * Links that a teacher marked as not broken in a course. Scans leave them out of the report.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class ignore_list {
    /** @var string */
    const TABLE = 'local_brokenlinks_ignore';

    /**
     * Mark a link as not broken, and take it out of the latest report.
     *
     * @param int $courseid
     * @param string $url
     */
    public static function add(int $courseid, string $url): void {
        global $DB;
        if (!self::find($courseid, $url)) {
            $DB->insert_record(self::TABLE, (object)['courseid' => $courseid, 'url' => $url, 'timecreated' => time()]);
        }
        if ($scan = scan_manager::get_latest($courseid)) {
            scan_manager::remove_links((int)$scan->id, [$url]);
        }
    }

    /**
     * Check a link again in later scans.
     *
     * @param int $courseid
     * @param int $id
     */
    public static function remove(int $courseid, int $id): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['id' => $id, 'courseid' => $courseid]);
    }

    /**
     * The ignored links of a course.
     *
     * @param int $courseid
     * @return \stdClass[] Records with id, url and timecreated, newest first.
     */
    public static function get_all(int $courseid): array {
        global $DB;
        return $DB->get_records(self::TABLE, ['courseid' => $courseid], 'timecreated DESC, id DESC');
    }

    /**
     * The ignored URLs of a course, for quick lookups.
     *
     * @param int $courseid
     * @return array URL => true
     */
    public static function get_urls(int $courseid): array {
        global $DB;
        $urls = $DB->get_fieldset_select(self::TABLE, 'url', 'courseid = ?', [$courseid]);
        return array_fill_keys($urls, true);
    }

    /**
     * Delete the ignored links of a course.
     *
     * @param int $courseid
     */
    public static function delete_course(int $courseid): void {
        global $DB;
        $DB->delete_records(self::TABLE, ['courseid' => $courseid]);
    }

    /**
     * Find one ignored link.
     *
     * @param int $courseid
     * @param string $url
     * @return \stdClass|null
     */
    protected static function find(int $courseid, string $url): ?\stdClass {
        global $DB;
        $select = 'courseid = :courseid AND ' . $DB->sql_compare_text('url', 1333) . ' = ' . $DB->sql_compare_text(':url', 1333);
        $records = $DB->get_records_select(self::TABLE, $select, ['courseid' => $courseid, 'url' => $url], 'id', '*', 0, 1);
        return $records ? reset($records) : null;
    }
}
