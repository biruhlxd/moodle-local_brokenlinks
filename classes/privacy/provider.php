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

namespace local_brokenlinks\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;

/**
 * Privacy provider for local_brokenlinks.
 *
 * The plugin stores link check results about course content, not about users. It does request the web
 * addresses linked from courses, and sends notifications through the Moodle messaging system.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe what the plugin sends elsewhere.
     *
     * @param collection $collection
     * @return collection
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_external_location_link('externalwebsites', [
            'url' => 'privacy:metadata:externalwebsites:url',
        ], 'privacy:metadata:externalwebsites');
        $collection->add_subsystem_link('core_message', [], 'privacy:metadata:core_message');
        return $collection;
    }

    /**
     * No user data is stored, so there are no contexts.
     *
     * @param int $userid
     * @return contextlist
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        return new contextlist();
    }

    /**
     * No user data is stored, so there are no users.
     *
     * @param userlist $userlist
     */
    public static function get_users_in_context(userlist $userlist) {
    }

    /**
     * No user data is stored, so there is nothing to export.
     *
     * @param approved_contextlist $contextlist
     */
    public static function export_user_data(approved_contextlist $contextlist) {
    }

    /**
     * No user data is stored, so there is nothing to delete.
     *
     * @param \context $context
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
    }

    /**
     * No user data is stored, so there is nothing to delete.
     *
     * @param approved_contextlist $contextlist
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
    }

    /**
     * No user data is stored, so there is nothing to delete.
     *
     * @param approved_userlist $userlist
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
    }
}
