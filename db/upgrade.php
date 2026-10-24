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
 * Upgrade steps for local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Upgrade the plugin.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_local_brokenlinks_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2026102003) {
        // Add the Adobe Captivate player files and time server to the default ignore lists,
        // but only where an administrator has not changed the lists.
        $added = [
            'ignoredhosts' => ['time.akamai.com'],
            'scormignorepaths' => ['SCORM_utilities.js', 'Utilities.js', 'CPXHRLoader.js'],
        ];
        // The defaults as they were in 0.3.2, written out so later changes to the defaults cannot change this step.
        $defaults = [
            'ignoredhosts' => ['w3.org', 'adlnet.gov', 'adlnet.org', 'imsglobal.org', 'imsproject.org', 'ltsc.ieee.org',
                'purl.org', 'w3id.org', 'id.tincanapi.com', 'xmlns.com', 'schemas.xmlsoap.org', 'schemas.microsoft.com',
                'localhost', 'example.com', 'metrics.articulate.com', 'ipc.articulate.com', 'time.akamai.com'],
            'scormignorepaths' => ['lms/*', 'scormdriver/*', 'html5/lib/*', 'mobile/*', 'lib/*', 'scormcontent/lib/*',
                '*.min.js', '*.xsd', 'scormdriver.js', 'browsersniff.js', 'analytics-frame.html', 'scorm_api*.js',
                'apiwrapper*.js', 'SCORM_utilities.js', 'Utilities.js', 'CPXHRLoader.js'],
        ];
        foreach ($defaults as $name => $default) {
            $olddefault = implode("\n", array_values(array_diff($default, $added[$name])));
            $current = get_config('local_brokenlinks', $name);
            if ($current !== false && trim(str_replace("\r", '', $current)) === $olddefault) {
                set_config($name, implode("\n", $default), 'local_brokenlinks');
            }
        }
        upgrade_plugin_savepoint(true, 2026102003, 'local', 'brokenlinks');
    }

    if ($oldversion < 2026102400) {
        // Links that teachers marked as not broken.
        $table = new xmldb_table('local_brokenlinks_ignore');
        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('url', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102400, 'local', 'brokenlinks');
    }

    if ($oldversion < 2026102500) {
        // Link fixing moved to the separate Pro plugin. Its history is kept for the Pro plugin to take over
        // when it is installed; an empty history table is removed.
        $table = new xmldb_table('local_brokenlinks_fix');
        if ($dbman->table_exists($table) && !$DB->record_exists('local_brokenlinks_fix', [])) {
            $dbman->drop_table($table);
        }
        upgrade_plugin_savepoint(true, 2026102500, 'local', 'brokenlinks');
    }

    return true;
}
