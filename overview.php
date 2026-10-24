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
 * Site-wide overview of the latest link check of every course.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_brokenlinks\local\scan_manager;

$action = optional_param('action', '', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

admin_externalpage_setup('local_brokenlinks_overview');
$url = new moodle_url('/local/brokenlinks/overview.php');

if ($action === 'runall' && confirm_sesskey()) {
    require_capability('moodle/site:config', context_system::instance());
    \core\task\manager::queue_adhoc_task(new \local_brokenlinks\task\queue_all_courses(), true);
    redirect($url, get_string('allscansqueued', 'local_brokenlinks'), null, \core\output\notification::NOTIFY_INFO);
}

$latest = "SELECT MAX(id) FROM {local_brokenlinks_scan} WHERE status IN (:finished, :failed) GROUP BY courseid";
$params = ['finished' => scan_manager::STATUS_FINISHED, 'failed' => scan_manager::STATUS_FAILED];
$total = $DB->count_records_sql("SELECT COUNT(1) FROM ($latest) latest", $params);
$sql = "SELECT s.*, c.fullname, c.shortname
          FROM {local_brokenlinks_scan} s
          JOIN {course} c ON c.id = s.courseid
         WHERE s.id IN ($latest)
      ORDER BY s.brokencount DESC, s.warningcount DESC, c.fullname";
$scans = $DB->get_records_sql($sql, $params, $page * $perpage, $perpage);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('overview', 'local_brokenlinks'));
echo html_writer::tag('p', get_string('overviewintro', 'local_brokenlinks'), ['class' => 'text-muted']);

if (has_capability('moodle/site:config', context_system::instance())) {
    echo $OUTPUT->single_button(
        new moodle_url($url, ['action' => 'runall']),
        get_string('runall', 'local_brokenlinks'),
        'post',
        ['type' => single_button::BUTTON_PRIMARY]
    );
}

if (!$scans) {
    echo $OUTPUT->notification(get_string('noscans', 'local_brokenlinks'), 'info', false);
} else {
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('lastchecked', 'local_brokenlinks'),
        get_string('linkschecked', 'local_brokenlinks'),
        get_string('brokenlinks', 'local_brokenlinks'),
        get_string('warnings', 'local_brokenlinks'),
    ];
    $table->attributes['class'] = 'generaltable table-striped';
    foreach ($scans as $scan) {
        $coursecontext = context_course::instance($scan->courseid);
        $name = format_string($scan->fullname, true, ['context' => $coursecontext]);
        $link = html_writer::link(new moodle_url('/local/brokenlinks/index.php', ['id' => $scan->courseid]), $name);
        $broken = (int)$scan->brokencount;
        if ($scan->status == scan_manager::STATUS_FAILED) {
            $broken = html_writer::span(get_string('failed', 'local_brokenlinks'), 'badge bg-danger');
        } else if ($broken) {
            $broken = html_writer::span($broken, 'text-danger fw-bold');
        }
        $table->data[] = [$link, userdate($scan->timefinished), (int)$scan->linkschecked, $broken, (int)$scan->warningcount];
    }
    echo html_writer::table($table);
    echo $OUTPUT->paging_bar($total, $page, $perpage, $url);
}

echo $OUTPUT->footer();
