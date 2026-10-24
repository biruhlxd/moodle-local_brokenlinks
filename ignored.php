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
 * Links marked as not broken in a course, with a way to check them again.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_brokenlinks\local\ignore_list;

$courseid = required_param('id', PARAM_INT);
$remove = optional_param('remove', 0, PARAM_INT);

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/brokenlinks:run', $context);

$url = new moodle_url('/local/brokenlinks/ignored.php', ['id' => $course->id]);
$reporturl = new moodle_url('/local/brokenlinks/index.php', ['id' => $course->id]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('ignoredlinks', 'local_brokenlinks'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->navbar->add(get_string('pluginname', 'local_brokenlinks'), $reporturl);
$PAGE->navbar->add(get_string('ignoredlinks', 'local_brokenlinks'));

if ($remove) {
    require_sesskey();
    ignore_list::remove((int)$course->id, $remove);
    redirect($url, get_string('unignored', 'local_brokenlinks'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('ignoredlinks', 'local_brokenlinks'));
echo html_writer::tag('p', get_string('ignoredlinksintro', 'local_brokenlinks'), ['class' => 'text-muted']);

$ignored = ignore_list::get_all((int)$course->id);
if (!$ignored) {
    echo $OUTPUT->notification(get_string('noignoredlinks', 'local_brokenlinks'), 'info', false);
} else {
    $table = new html_table();
    $table->head = [get_string('col_link', 'local_brokenlinks'), get_string('date'), ''];
    $table->attributes['class'] = 'generaltable table-striped';
    foreach ($ignored as $record) {
        $button = new single_button(
            new moodle_url($url, ['remove' => $record->id]),
            get_string('unignore', 'local_brokenlinks'),
            'post',
            single_button::BUTTON_SECONDARY
        );
        $table->data[] = [
            html_writer::tag('code', s($record->url), ['class' => 'text-break']),
            userdate($record->timecreated),
            $OUTPUT->render($button),
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::link($reporturl, get_string('backtoreport', 'local_brokenlinks'));
echo $OUTPUT->footer();
