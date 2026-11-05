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
 * Broken links report for one course.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');

use local_brokenlinks\local\ignore_list;
use local_brokenlinks\local\report_helper;
use local_brokenlinks\local\scan_manager;
use local_brokenlinks\local\url_checker;

$courseid = required_param('id', PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);
$filter = optional_param('filter', 'all', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 50;

$course = get_course($courseid);
require_login($course);
$context = context_course::instance($course->id);
require_capability('local/brokenlinks:view', $context);
$canrun = has_capability('local/brokenlinks:run', $context);

$url = new moodle_url('/local/brokenlinks/index.php', ['id' => $course->id]);
$PAGE->set_url($url, ['filter' => $filter]);
$PAGE->set_context($context);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('reporttitle', 'local_brokenlinks', format_string($course->shortname)));
$PAGE->set_heading(format_string($course->fullname));

if ($action === 'run' && $canrun && confirm_sesskey()) {
    scan_manager::queue((int)$course->id);
    redirect($url, get_string('scanqueued', 'local_brokenlinks'), null, \core\output\notification::NOTIFY_INFO);
}

$pending = scan_manager::get_pending((int)$course->id);
$scan = scan_manager::get_latest((int)$course->id);

// Actions on one reported link.
if (($action === 'ignore' || $action === 'recheck') && $canrun && $scan && confirm_sesskey()) {
    $link = $DB->get_record('local_brokenlinks_link', ['id' => required_param('linkid', PARAM_INT), 'scanid' => $scan->id]);
    $back = new moodle_url($url, ['filter' => $filter, 'page' => $page]);
    if (!$link) {
        // Already removed, for example by a fix in another tab.
        redirect($back);
    }
    if ($action === 'ignore') {
        ignore_list::add((int)$course->id, $link->url);
        redirect($back, get_string('linkignored', 'local_brokenlinks'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    if ($link->linktype !== 'external') {
        redirect($back);
    }
    $result = scan_manager::recheck((int)$scan->id, $link->url);
    if ($result['severity'] === url_checker::OK) {
        redirect($back, get_string('recheckok', 'local_brokenlinks'), null, \core\output\notification::NOTIFY_SUCCESS);
    }
    redirect(
        $back,
        get_string('recheckstillbroken', 'local_brokenlinks', s($result['message'])),
        null,
        \core\output\notification::NOTIFY_WARNING
    );
}

$template = [
    'canrun' => $canrun && !$pending,
    'runurl' => (new moodle_url($url, ['action' => 'run']))->out(false),
    'actionurl' => (new moodle_url($url, ['filter' => $filter, 'page' => $page]))->out(false),
    'ignoredurl' => $canrun ? (new moodle_url('/local/brokenlinks/ignored.php', ['id' => $course->id]))->out(false) : '',
    'ignoredcount' => $canrun ? $DB->count_records('local_brokenlinks_ignore', ['courseid' => $course->id]) : 0,
    'sesskey' => sesskey(),
    'courseid' => $course->id,
    'pending' => (bool)$pending,
    'pendingrunning' => $pending && $pending->status == scan_manager::STATUS_RUNNING,
    'hasscan' => (bool)$scan,
    'rows' => [],
];

// Buttons and sections added by other plugins.
$hooks = \core\di::get(\core\hook\manager::class);
$header = $hooks->dispatch(new \local_brokenlinks\hook\report_header_actions($course, $context));
$template['headeractions'] = implode('', $header->get_actions());
$footer = $hooks->dispatch(new \local_brokenlinks\hook\report_footer($course, $context));
$template['footer'] = implode('', $footer->get_sections());

if ($scan) {
    $template['failed'] = $scan->status == scan_manager::STATUS_FAILED;
    // Error details can mention server paths or database errors, so only administrators see them.
    $template['errormessage'] = has_capability('moodle/site:config', context_system::instance())
        ? (string)$scan->errormessage : '';
    $template['timefinished'] = userdate($scan->timefinished);
    $template['linkschecked'] = (int)$scan->linkschecked;
    $template['brokencount'] = (int)$scan->brokencount;
    $template['warningcount'] = (int)$scan->warningcount;
    $template['truncated'] = (bool)$scan->truncated;

    $filters = [
        'all' => get_string('filter_all', 'local_brokenlinks'),
        'broken' => get_string('filter_broken', 'local_brokenlinks', (int)$scan->brokencount),
        'warning' => get_string('filter_warning', 'local_brokenlinks', (int)$scan->warningcount),
    ];
    if (!isset($filters[$filter])) {
        $filter = 'all';
    }
    foreach ($filters as $key => $label) {
        $template['filters'][] = [
            'label' => $label,
            'url' => (new moodle_url($url, ['filter' => $key]))->out(false),
            'active' => $key === $filter,
        ];
    }

    $conditions = ['scanid' => $scan->id];
    if ($filter === 'broken') {
        $conditions['severity'] = url_checker::BROKEN;
    } else if ($filter === 'warning') {
        $conditions['severity'] = url_checker::WARNING;
    }
    // The same link with the same problem is one row, with the other places it is in listed under it:
    // Captivate courses, for example, often have a copy of the same panel on every slide.
    $groups = report_helper::group_links(
        $DB->get_records('local_brokenlinks_link', $conditions, 'severity DESC, cmid, sectionid, sourcetype, location, id')
    );
    $total = count($groups);
    $groups = array_slice($groups, $page * $perpage, $perpage);

    $modinfo = get_fast_modinfo($course);
    foreach ($groups as $group) {
        $link = array_shift($group);
        $others = [];
        foreach ($group as $other) {
            $othersource = report_helper::describe($other, $course, $modinfo);
            $others[] = [
                'where' => $othersource['where'],
                'whereurl' => $othersource['whereurl'],
                'location' => $othersource['location'],
                'place' => $othersource['place'],
                'previewurl' => $othersource['previewurl'],
            ];
        }
        $source = report_helper::describe($link, $course, $modinfo);
        $isweb = (bool)preg_match('~^https?://~i', $link->url);
        $actions = $hooks->dispatch(new \local_brokenlinks\hook\report_row_actions(
            $course,
            $context,
            $link,
            new moodle_url($url, ['filter' => $filter, 'page' => $page])
        ));
        $template['rows'][] = [
            'id' => $link->id,
            'broken' => $link->severity == url_checker::BROKEN,
            'where' => $source['where'],
            'whereurl' => $source['whereurl'],
            'icon' => $source['cm'] ? $OUTPUT->image_url('monologo', $source['cm']->modname)->out(false) : '',
            'location' => $source['location'],
            'place' => $source['place'],
            'previewurl' => $source['previewurl'],
            'others' => $others,
            'otherslabel' => $others ? get_string(
                count($others) === 1 ? 'otherplace' : 'otherplaces',
                'local_brokenlinks',
                count($others)
            ) : '',
            'url' => $link->url,
            'isweb' => $isweb,
            'canrecheck' => $canrun && $link->linktype === 'external',
            'canignore' => $canrun,
            'extraactions' => implode('', $actions->get_actions()),
            'displayurl' => shorten_text($link->url, 90, true),
            'linktext' => $link->linktext !== '' && $link->linktext !== $link->url ? $link->linktext : '',
            'httpstatus' => $link->httpstatus ?: '',
            'message' => $link->message,
        ];
    }
    $template['hasrows'] = !empty($template['rows']);
    if ($total) {
        $template['downloadselector'] = $OUTPUT->download_dataformat_selector(
            get_string('downloadreport', 'local_brokenlinks'),
            'download.php',
            'dataformat',
            ['id' => $course->id, 'filter' => $filter]
        );
    }
    $template['pagingbar'] = $OUTPUT->paging_bar($total, $page, $perpage, new moodle_url($url, ['filter' => $filter]));
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_brokenlinks/report', $template);
echo $OUTPUT->footer();
