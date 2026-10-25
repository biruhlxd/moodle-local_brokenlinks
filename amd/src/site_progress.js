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
 * Follows "Check all courses" on the overview page and says when every course has been checked.
 *
 * @module     local_brokenlinks/site_progress
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {getString} from 'core/str';

/** @var {number} How often to ask, in milliseconds. */
const INTERVAL = 10000;

/** @var {number} Stop asking after this long, in milliseconds; the page can still be reloaded by hand. */
const GIVE_UP_AFTER = 12 * 60 * 60 * 1000;

/**
 * Ask how far the check of all courses has got.
 *
 * @returns {Promise<Object>} queuing, pending, done and finished.
 */
const getProgress = () => fetchMany([{
    methodname: 'local_brokenlinks_get_site_progress',
    args: {},
}])[0];

/**
 * Show that every course has been checked, with a button to see the results.
 *
 * @param {HTMLElement} notice
 * @param {Object} progress
 */
const showDone = async(notice, progress) => {
    const [message, button] = await Promise.all([
        getString('runalldone', 'local_brokenlinks', progress),
        getString('seeresults', 'local_brokenlinks'),
    ]);
    notice.classList.remove('alert-info');
    notice.classList.add('alert-success');
    notice.textContent = message + ' ';
    const link = document.createElement('a');
    link.className = 'btn btn-primary btn-sm ms-2';
    link.href = window.location.href.replace(/[?&]page=\d+/, '');
    link.textContent = button;
    notice.appendChild(link);
    link.focus();
};

/**
 * Start following the check.
 *
 * @param {string} selector The notice that shows the progress.
 */
export const init = (selector) => {
    const notice = document.querySelector(selector);
    if (!notice) {
        return;
    }
    const started = Date.now();

    const check = async() => {
        let progress = null;
        try {
            progress = await getProgress();
        } catch (e) {
            // A lost connection or an expired session: try again later.
            progress = null;
        }
        if (progress && progress.finished) {
            await showDone(notice, progress);
            return;
        }
        if (progress) {
            notice.textContent = await getString(progress.queuing ? 'runallqueuing' : 'runallprogress',
                'local_brokenlinks', progress);
        }
        if (Date.now() - started < GIVE_UP_AFTER) {
            window.setTimeout(check, INTERVAL);
        }
    };
    window.setTimeout(check, INTERVAL);
};
