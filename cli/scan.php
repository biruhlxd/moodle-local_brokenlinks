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
 * Check the links of a course from the command line, without waiting for cron.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_brokenlinks\local\scan_manager;

[$options, $unrecognised] = cli_get_params(
    ['course' => 0, 'all' => false, 'notify' => false, 'help' => false],
    ['c' => 'course', 'a' => 'all', 'n' => 'notify', 'h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognised)));
}

if ($options['help'] || (!$options['course'] && !$options['all'])) {
    echo "Check a course for broken links, including links inside SCORM packages.

Options:
  -c, --course=ID   Check one course.
  -a, --all         Check every course.
  -n, --notify      Notify the course teachers, as the scheduled check does.
  -h, --help        Print this help.

Example:
  php local/brokenlinks/cli/scan.php --course=2
";
    exit(0);
}

\core\cron::setup_user();

$courseids = $options['all']
    ? $DB->get_fieldset_select('course', 'id', 'id <> ?', [SITEID])
    : [(int)$options['course']];

foreach ($courseids as $courseid) {
    if (!$DB->record_exists('course', ['id' => $courseid])) {
        cli_error("Course $courseid does not exist.");
    }
    $start = microtime(true);
    $scan = scan_manager::run(scan_manager::queue((int)$courseid, false), null, (bool)$options['notify']);
    $seconds = round(microtime(true) - $start, 1);
    if ($scan->status == scan_manager::STATUS_FAILED) {
        cli_writeln("Course $courseid: failed after {$seconds}s: {$scan->errormessage}");
        continue;
    }
    cli_writeln("Course $courseid: {$scan->linkschecked} links checked, {$scan->brokencount} broken, " .
        "{$scan->warningcount} warnings ({$seconds}s).");
    foreach ($DB->get_records('local_brokenlinks_link', ['scanid' => $scan->id], 'severity DESC, id') as $link) {
        $label = $link->severity == \local_brokenlinks\local\url_checker::BROKEN ? 'BROKEN ' : 'WARNING';
        cli_writeln("  $label {$link->sourcetype} cm={$link->cmid} {$link->location}");
        cli_writeln("          {$link->url} -> {$link->message}");
    }
}
