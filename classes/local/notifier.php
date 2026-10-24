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
 * Sends notifications about broken links to course teachers and site administrators.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notifier {
    /** @var int Number of broken links listed in a course notification. */
    const LINKS_IN_MESSAGE = 5;

    /** @var int Number of courses listed in the site summary. */
    const COURSES_IN_SUMMARY = 50;

    /**
     * Notify the teachers of a course about its broken links after a scan.
     *
     * @param \stdClass $scan The finished scan.
     * @param int|null $previousscanid The scan before it, to report only links that are newly broken.
     * @return int Number of users notified.
     */
    public static function notify_course(\stdClass $scan, ?int $previousscanid): int {
        global $DB;
        if (!get_config('local_brokenlinks', 'notifyteachers')) {
            return 0;
        }
        $broken = $DB->get_records(
            'local_brokenlinks_link',
            ['scanid' => $scan->id, 'severity' => url_checker::BROKEN],
            'cmid, sectionid, id'
        );
        $onlynew = (bool)get_config('local_brokenlinks', 'notifyonlynew');
        if ($onlynew && $previousscanid) {
            $known = [];
            foreach ($DB->get_records('local_brokenlinks_link', ['scanid' => $previousscanid]) as $old) {
                $known[self::link_key($old)] = true;
            }
            $broken = array_filter($broken, fn($link) => !isset($known[self::link_key($link)]));
        }
        if (!$broken) {
            return 0;
        }

        $course = get_course($scan->courseid);
        $context = \context_course::instance($course->id);
        $users = get_enrolled_users($context, 'local/brokenlinks:receivenotifications', 0, 'u.*', null, 0, 0, true);
        if (!$users) {
            return 0;
        }

        $reporturl = new \moodle_url('/local/brokenlinks/index.php', ['id' => $course->id, 'filter' => 'broken']);
        $modinfo = get_fast_modinfo($course, -1);
        $cms = $modinfo->get_cms();
        $sent = 0;
        foreach ($users as $user) {
            // Write each message in the language of the person reading it.
            $previouslang = force_current_language($user->lang);
            try {
                $a = (object)[
                    'course' => format_string($course->fullname, true, ['context' => $context, 'escape' => false]),
                    'count' => count($broken),
                ];
                $lines = [];
                foreach (array_slice($broken, 0, self::LINKS_IN_MESSAGE) as $link) {
                    $lines[] = (object)[
                        'where' => self::describe_source($link, $course, $cms),
                        'url' => $link->url !== '' ? $link->url : $link->message,
                        'message' => $link->message,
                    ];
                }
                $more = count($broken) - count($lines);

                $intro = get_string($onlynew && $previousscanid ? 'notification_new' : 'notification_all', 'local_brokenlinks', $a);
                $text = $intro . "\n\n";
                $html = \html_writer::tag('p', s($intro)) . '<ul>';
                foreach ($lines as $line) {
                    $text .= "- {$line->where}: {$line->url} ({$line->message})\n";
                    $html .= \html_writer::tag('li', \html_writer::tag('strong', s($line->where)) . ': ' .
                        \html_writer::tag('code', s($line->url)) . ' ' . s('(' . $line->message . ')'));
                }
                $html .= '</ul>';
                if ($more > 0) {
                    $andmore = get_string('notification_more', 'local_brokenlinks', $more);
                    $text .= $andmore . "\n";
                    $html .= \html_writer::tag('p', s($andmore));
                }
                $viewreport = get_string('notification_viewreport', 'local_brokenlinks');
                $text .= "\n" . $viewreport . ': ' . $reporturl->out(false) . "\n";
                $html .= \html_writer::tag('p', \html_writer::link($reporturl, s($viewreport)));

                $message = new \core\message\message();
                $message->component = 'local_brokenlinks';
                $message->name = 'brokenlinksfound';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $user;
                $message->subject = get_string('notification_subject', 'local_brokenlinks', $a);
                $message->fullmessage = $text;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = $html;
                $message->smallmessage = $intro;
                $message->notification = 1;
                $message->courseid = $course->id;
                $message->contexturl = $reporturl->out(false);
                $message->contexturlname = $viewreport;
                if (message_send($message)) {
                    $sent++;
                }
            } finally {
                // Restore the language even if sending fails.
                force_current_language($previouslang);
            }
        }
        return $sent;
    }

    /**
     * Send site administrators a summary of the courses whose recent checks found broken links.
     *
     * @param int $since Only include checks that finished after this time.
     * @return int Number of administrators notified.
     */
    public static function send_site_summary(int $since): int {
        global $DB;
        if (!get_config('local_brokenlinks', 'adminsummary')) {
            return 0;
        }
        $sql = "SELECT s.*, c.fullname
                  FROM {local_brokenlinks_scan} s
                  JOIN {course} c ON c.id = s.courseid
                 WHERE s.status = :finished AND s.brokencount > 0 AND s.timefinished > :since
              ORDER BY s.brokencount DESC, c.fullname";
        $scans = $DB->get_records_sql($sql, ['finished' => scan_manager::STATUS_FINISHED, 'since' => $since]);
        if (!$scans) {
            return 0;
        }

        $overviewurl = new \moodle_url('/local/brokenlinks/overview.php');
        $sent = 0;
        foreach (get_admins() as $admin) {
            $previouslang = force_current_language($admin->lang);
            try {
                $intro = get_string('summary_intro', 'local_brokenlinks', count($scans));
                $text = $intro . "\n\n";
                $html = \html_writer::tag('p', s($intro)) . '<ul>';
                foreach (array_slice($scans, 0, self::COURSES_IN_SUMMARY) as $scan) {
                    $context = \context_course::instance($scan->courseid);
                    $a = (object)[
                        'course' => format_string($scan->fullname, true, ['context' => $context, 'escape' => false]),
                        'broken' => (int)$scan->brokencount,
                        'warnings' => (int)$scan->warningcount,
                    ];
                    $line = get_string('summary_line', 'local_brokenlinks', $a);
                    $url = new \moodle_url('/local/brokenlinks/index.php', ['id' => $scan->courseid]);
                    $text .= "- $line: " . $url->out(false) . "\n";
                    $html .= \html_writer::tag('li', \html_writer::link($url, s($line)));
                }
                $html .= '</ul>';
                $viewoverview = get_string('summary_viewoverview', 'local_brokenlinks');
                $text .= "\n" . $viewoverview . ': ' . $overviewurl->out(false) . "\n";
                $html .= \html_writer::tag('p', \html_writer::link($overviewurl, s($viewoverview)));

                $message = new \core\message\message();
                $message->component = 'local_brokenlinks';
                $message->name = 'sitesummary';
                $message->userfrom = \core_user::get_noreply_user();
                $message->userto = $admin;
                $message->subject = get_string('summary_subject', 'local_brokenlinks', count($scans));
                $message->fullmessage = $text;
                $message->fullmessageformat = FORMAT_PLAIN;
                $message->fullmessagehtml = $html;
                $message->smallmessage = $intro;
                $message->notification = 1;
                $message->courseid = SITEID;
                $message->contexturl = $overviewurl->out(false);
                $message->contexturlname = $viewoverview;
                if (message_send($message)) {
                    $sent++;
                }
            } finally {
                // Restore the language even if sending fails.
                force_current_language($previouslang);
            }
        }
        return $sent;
    }

    /**
     * A key that identifies the same problem across scans.
     *
     * @param \stdClass $link
     * @return string
     */
    protected static function link_key(\stdClass $link): string {
        return implode('|', [$link->sourcetype, (int)$link->cmid, (int)$link->sectionid, $link->location, $link->url]);
    }

    /**
     * Where a link was found, in words.
     *
     * @param \stdClass $link
     * @param \stdClass $course
     * @param \cm_info[] $cms
     * @return string
     */
    protected static function describe_source(\stdClass $link, \stdClass $course, array $cms): string {
        if ($link->cmid && isset($cms[$link->cmid])) {
            $name = $cms[$link->cmid]->get_formatted_name(['escape' => false]);
            return $link->sourcetype === 'scorm' ? $name . ' (' . $link->location . ')' : $name;
        }
        if ($link->sectionid) {
            $section = get_fast_modinfo($course, -1)->get_section_info_by_id($link->sectionid);
            if ($section) {
                return get_section_name($course, $section);
            }
        }
        return get_string('source_course', 'local_brokenlinks');
    }
}
