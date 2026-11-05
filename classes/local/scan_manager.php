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
 * Queues, runs and looks up link scans.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_manager {
    /** @var int Waiting for the background task. */
    const STATUS_QUEUED = 0;
    /** @var int The background task is working on it. */
    const STATUS_RUNNING = 1;
    /** @var int Finished. */
    const STATUS_FINISHED = 2;
    /** @var int Stopped by an error. */
    const STATUS_FAILED = 3;

    /** @var int A queued or running scan older than this is treated as abandoned, in seconds. */
    const STALE_AFTER = DAYSECS;

    /**
     * Queue a scan of a course, unless one is already waiting or running.
     *
     * @param int $courseid
     * @param bool $background Queue a background task to run it. Pass false to call run() yourself.
     * @param bool $notify Tell the course teachers about new broken links when the scan finishes.
     * @return int The scan id.
     */
    public static function queue(int $courseid, bool $background = true, bool $notify = false): int {
        global $DB;
        // Scans that never finished, for example because the server restarted, are marked as failed.
        $DB->set_field_select(
            'local_brokenlinks_scan',
            'status',
            self::STATUS_FAILED,
            'courseid = :courseid AND status IN (:queued, :running) AND timequeued <= :since',
            ['courseid' => $courseid, 'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING,
            'since' => time() - self::STALE_AFTER]
        );
        if ($pending = self::get_pending($courseid)) {
            return (int)$pending->id;
        }
        $scanid = $DB->insert_record('local_brokenlinks_scan', (object)[
            'courseid' => $courseid,
            'status' => self::STATUS_QUEUED,
            'timequeued' => time(),
        ]);
        if (!$background) {
            return $scanid;
        }
        $task = new \local_brokenlinks\task\scan_course();
        $task->set_custom_data(['scanid' => $scanid, 'notify' => $notify]);
        \core\task\manager::queue_adhoc_task($task);
        return $scanid;
    }

    /**
     * Queue a scan of every course.
     *
     * @param bool $notify Tell the course teachers about new broken links.
     * @return int Number of courses.
     */
    public static function queue_all(bool $notify = false): int {
        global $DB;
        $courseids = $DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID]);
        foreach ($courseids as $courseid) {
            self::queue((int)$courseid, true, $notify);
        }
        return count($courseids);
    }

    /**
     * Progress of "Check all courses", counting the scans queued since it was started.
     *
     * @return array|null ['queuing' => bool, still adding courses; 'pending' => int, courses waiting or running;
     *     'done' => int, courses checked], or null if no check of all courses was started.
     */
    public static function site_progress(): ?array {
        global $DB;
        $since = (int)get_config('local_brokenlinks', 'runallstarted');
        if (!$since) {
            return null;
        }
        $queuing = (bool)\core\task\manager::get_adhoc_tasks('\local_brokenlinks\task\queue_all_courses');
        $pending = $DB->count_records_select(
            'local_brokenlinks_scan',
            'status IN (:queued, :running) AND timequeued >= :since AND timequeued > :stale',
            ['queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING, 'since' => $since,
                'stale' => time() - self::STALE_AFTER]
        );
        $done = $DB->count_records_select(
            'local_brokenlinks_scan',
            'status IN (:finished, :failed) AND timequeued >= :since',
            ['finished' => self::STATUS_FINISHED, 'failed' => self::STATUS_FAILED, 'since' => $since]
        );
        return ['queuing' => $queuing, 'pending' => $pending, 'done' => $done];
    }

    /**
     * Start checking every course in the background, and remember when, to follow its progress.
     */
    public static function start_site_check(): void {
        set_config('runallstarted', time(), 'local_brokenlinks');
        \core\task\manager::queue_adhoc_task(new \local_brokenlinks\task\queue_all_courses(), true);
    }

    /**
     * Run a queued scan. Older scans of the same course are removed when it finishes.
     *
     * @param int $scanid
     * @param url_checker|null $checker
     * @param bool $notify Tell the course teachers about broken links that the previous scan did not find.
     * @return \stdClass|null The updated scan record, or null if it no longer exists.
     */
    public static function run(int $scanid, ?url_checker $checker = null, bool $notify = false): ?\stdClass {
        global $DB;
        $scan = $DB->get_record('local_brokenlinks_scan', ['id' => $scanid]);
        if (!$scan) {
            return null;
        }
        if ($scan->status == self::STATUS_FINISHED || $scan->status == self::STATUS_FAILED) {
            return $scan;
        }
        if (!$DB->record_exists('course', ['id' => $scan->courseid])) {
            self::delete_course_scans((int)$scan->courseid);
            return null;
        }

        // A retried task starts again from scratch.
        $DB->delete_records('local_brokenlinks_link', ['scanid' => $scan->id]);
        $scan->status = self::STATUS_RUNNING;
        $scan->timestarted = time();
        $DB->update_record('local_brokenlinks_scan', $scan);

        try {
            $stats = (new course_scanner((int)$scan->courseid, (int)$scan->id, $checker))->run();
            foreach ($stats as $name => $value) {
                $scan->$name = $value;
            }
            $scan->status = self::STATUS_FINISHED;
            $scan->errormessage = null;
        } catch (\Throwable $e) {
            $scan->status = self::STATUS_FAILED;
            $scan->errormessage = $e->getMessage();
        }
        $scan->timefinished = time();
        $DB->update_record('local_brokenlinks_scan', $scan);

        if ($scan->status == self::STATUS_FINISHED) {
            $older = $DB->get_fieldset_select(
                'local_brokenlinks_scan',
                'id',
                'courseid = :courseid AND id < :id AND status IN (:finished, :failed)',
                ['courseid' => $scan->courseid, 'id' => $scan->id,
                'finished' => self::STATUS_FINISHED,
                'failed' => self::STATUS_FAILED]
            );
            if ($notify) {
                notifier::notify_course($scan, $older ? (int)max($older) : null);
            }
            self::delete_scans($older);
            \core\di::get(\core\hook\manager::class)->dispatch(new \local_brokenlinks\hook\scan_finished($scan));
        }
        return $scan;
    }

    /**
     * Remove links from a scan's results, for example after they were fixed or marked as not broken,
     * and correct the scan's counts.
     *
     * @param int $scanid
     * @param string[] $urls
     */
    public static function remove_links(int $scanid, array $urls): void {
        global $DB;
        foreach (array_unique($urls) as $url) {
            $select = 'scanid = :scanid AND ' . $DB->sql_compare_text('url', 1333) . ' = ' . $DB->sql_compare_text(':url', 1333);
            $DB->delete_records_select('local_brokenlinks_link', $select, ['scanid' => $scanid, 'url' => $url]);
        }
        self::recount($scanid);
    }

    /**
     * Check one external link of a scan again, without waiting for a new scan.
     *
     * A link that works now leaves the report; otherwise its rows get the new result.
     *
     * @param int $scanid
     * @param string $url
     * @param url_checker|null $checker
     * @return array The check result: severity, httpstatus, message.
     */
    public static function recheck(int $scanid, string $url, ?url_checker $checker = null): array {
        global $DB;
        // Never use the cached result: the point is to see whether the link was repaired.
        $result = ($checker ?? new url_checker(null, 0))->check(course_scanner::doi_lookup_url($url) ?? $url);
        if ($result['severity'] === url_checker::OK) {
            self::remove_links($scanid, [$url]);
            return $result;
        }
        $select = 'scanid = :scanid AND linktype = :linktype AND ' .
            $DB->sql_compare_text('url', 1333) . ' = ' . $DB->sql_compare_text(':url', 1333);
        $params = ['scanid' => $scanid, 'linktype' => 'external', 'url' => $url];
        foreach ($DB->get_records_select('local_brokenlinks_link', $select, $params, '', 'id') as $row) {
            $DB->update_record('local_brokenlinks_link', (object)[
                'id' => $row->id,
                'severity' => $result['severity'],
                'httpstatus' => $result['httpstatus'],
                'message' => \core_text::substr($result['message'], 0, 1333),
            ]);
        }
        self::recount($scanid);
        return $result;
    }

    /**
     * Recalculate the broken and warning counts of a scan from its results.
     *
     * @param int $scanid
     */
    public static function recount(int $scanid): void {
        global $DB;
        $DB->update_record('local_brokenlinks_scan', (object)[
            'id' => $scanid,
            'brokencount' => $DB->count_records(
                'local_brokenlinks_link',
                ['scanid' => $scanid, 'severity' => url_checker::BROKEN]
            ),
            'warningcount' => $DB->count_records(
                'local_brokenlinks_link',
                ['scanid' => $scanid, 'severity' => url_checker::WARNING]
            ),
        ]);
    }

    /**
     * The scan that is waiting or running for a course, if any.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get_pending(int $courseid): ?\stdClass {
        global $DB;
        $select = 'courseid = :courseid AND status IN (:queued, :running) AND timequeued > :since';
        $params = ['courseid' => $courseid, 'queued' => self::STATUS_QUEUED, 'running' => self::STATUS_RUNNING,
            'since' => time() - self::STALE_AFTER];
        $records = $DB->get_records_select('local_brokenlinks_scan', $select, $params, 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * The most recent finished or failed scan of a course, if any.
     *
     * @param int $courseid
     * @return \stdClass|null
     */
    public static function get_latest(int $courseid): ?\stdClass {
        global $DB;
        $select = 'courseid = :courseid AND status IN (:finished, :failed)';
        $params = ['courseid' => $courseid, 'finished' => self::STATUS_FINISHED, 'failed' => self::STATUS_FAILED];
        $records = $DB->get_records_select('local_brokenlinks_scan', $select, $params, 'id DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    /**
     * Delete every scan of a course.
     *
     * @param int $courseid
     */
    public static function delete_course_scans(int $courseid): void {
        global $DB;
        self::delete_scans($DB->get_fieldset_select('local_brokenlinks_scan', 'id', 'courseid = ?', [$courseid]));
    }

    /**
     * Delete scans and their results.
     *
     * @param int[] $scanids
     */
    protected static function delete_scans(array $scanids): void {
        global $DB;
        if (!$scanids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($scanids);
        $DB->delete_records_select('local_brokenlinks_link', "scanid $insql", $params);
        $DB->delete_records_select('local_brokenlinks_scan', "id $insql", $params);
    }
}
