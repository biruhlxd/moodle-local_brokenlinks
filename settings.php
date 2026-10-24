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
 * Admin settings for local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig || has_capability('local/brokenlinks:viewall', context_system::instance())) {
    $ADMIN->add('reports', new admin_externalpage(
        'local_brokenlinks_overview',
        get_string('overview', 'local_brokenlinks'),
        new moodle_url('/local/brokenlinks/overview.php'),
        'local/brokenlinks:viewall'
    ));
}

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_brokenlinks', get_string('pluginname', 'local_brokenlinks'));
    $ADMIN->add('localplugins', $settings);

    if ($ADMIN->fulltree) {
        $settings->add(new admin_setting_configtext(
            'local_brokenlinks/timeout',
            get_string('timeout', 'local_brokenlinks'),
            get_string('timeout_desc', 'local_brokenlinks'),
            10,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_brokenlinks/cachehours',
            get_string('cachehours', 'local_brokenlinks'),
            get_string('cachehours_desc', 'local_brokenlinks'),
            24,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtext(
            'local_brokenlinks/maxlinks',
            get_string('maxlinks', 'local_brokenlinks'),
            get_string('maxlinks_desc', 'local_brokenlinks'),
            5000,
            PARAM_INT
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_brokenlinks/notfoundphrases',
            get_string('notfoundphrases', 'local_brokenlinks'),
            get_string('notfoundphrases_desc', 'local_brokenlinks'),
            implode("\n", \local_brokenlinks\local\url_checker::DEFAULT_NOT_FOUND_PHRASES),
            PARAM_RAW_TRIMMED
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_brokenlinks/ignoredhosts',
            get_string('ignoredhosts', 'local_brokenlinks'),
            get_string('ignoredhosts_desc', 'local_brokenlinks'),
            implode("\n", \local_brokenlinks\local\course_scanner::DEFAULT_IGNORED_HOSTS),
            PARAM_RAW_TRIMMED
        ));

        $settings->add(new admin_setting_configtextarea(
            'local_brokenlinks/scormignorepaths',
            get_string('scormignorepaths', 'local_brokenlinks'),
            get_string('scormignorepaths_desc', 'local_brokenlinks'),
            implode("\n", \local_brokenlinks\local\scorm_scanner::DEFAULT_IGNORED_PATHS),
            PARAM_RAW_TRIMMED
        ));

        $settings->add(new admin_setting_heading(
            'local_brokenlinks/notifications',
            get_string('notifications', 'local_brokenlinks'),
            get_string('notifications_desc', 'local_brokenlinks')
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_brokenlinks/notifyteachers',
            get_string('notifyteachers', 'local_brokenlinks'),
            get_string('notifyteachers_desc', 'local_brokenlinks'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_brokenlinks/notifyonlynew',
            get_string('notifyonlynew', 'local_brokenlinks'),
            get_string('notifyonlynew_desc', 'local_brokenlinks'),
            1
        ));

        $settings->add(new admin_setting_configcheckbox(
            'local_brokenlinks/adminsummary',
            get_string('adminsummary', 'local_brokenlinks'),
            get_string('adminsummary_desc', 'local_brokenlinks'),
            0
        ));
    }
}
