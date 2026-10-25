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
 * Watches a waiting or running link check and says when its results are ready.
 *
 * @module     local_brokenlinks/scan_status
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as fetchMany} from 'core/ajax';
import {getString} from 'core/str';

/** @var {number} How often to ask, in milliseconds. */
const INTERVAL = 10000;

/** @var {number} Stop asking after this long, in milliseconds; the page can still be reloaded by hand. */
const GIVE_UP_AFTER = 2 * 60 * 60 * 1000;

/**
 * Ask whether the check is still waiting or running.
 *
 * @param {number} courseId
 * @returns {Promise<string>} queued, running, finished, failed or none.
 */
const getStatus = (courseId) => fetchMany([{
    methodname: 'local_brokenlinks_get_scan_status',
    args: {courseid: courseId},
}])[0].then((result) => result.status);

/**
 * Show that the check has ended, with a button to see the results.
 *
 * @param {HTMLElement} notice
 * @param {string} status
 */
const showDone = async(notice, status) => {
    const [message, button] = await Promise.all([
        getString(status === 'failed' ? 'scanstopped' : 'scanfinished', 'local_brokenlinks'),
        getString('seeresults', 'local_brokenlinks'),
    ]);
    notice.classList.remove('alert-info');
    notice.classList.add(status === 'failed' ? 'alert-danger' : 'alert-success');
    notice.textContent = message + ' ';
    const link = document.createElement('a');
    link.className = 'btn btn-primary btn-sm ms-2';
    link.href = window.location.href;
    link.textContent = button;
    notice.appendChild(link);
    link.focus();
};

/**
 * Show that a waiting check has started.
 *
 * @param {HTMLElement} notice
 */
const showRunning = async(notice) => {
    notice.textContent = await getString('scanrunning', 'local_brokenlinks');
};

/**
 * Start watching.
 *
 * @param {number} courseId
 * @param {string} selector The notice that shows the state of the check.
 */
export const init = (courseId, selector) => {
    const notice = document.querySelector(selector);
    if (!notice) {
        return;
    }
    const started = Date.now();
    let running = notice.dataset.status === 'running';

    const check = async() => {
        let status;
        try {
            status = await getStatus(courseId);
        } catch (e) {
            // A lost connection or an expired session: try again later.
            status = null;
        }
        if (status === 'queued' || status === 'running' || status === null) {
            if (status === 'running' && !running) {
                running = true;
                await showRunning(notice);
            }
            if (Date.now() - started < GIVE_UP_AFTER) {
                window.setTimeout(check, INTERVAL);
            }
            return;
        }
        await showDone(notice, status);
    };
    window.setTimeout(check, INTERVAL);
};
