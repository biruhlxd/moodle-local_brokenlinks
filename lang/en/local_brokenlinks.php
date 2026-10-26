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
 * English strings for local_brokenlinks.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['adminsummary'] = 'Weekly summary for administrators';
$string['adminsummary_desc'] = 'Every Monday morning, send site administrators a list of the courses whose checks in the past week found broken links.';
$string['allscansqueued'] = 'Link checks have been queued for every course. They run in the background.';
$string['backtoreport'] = 'Back to the broken links report';
$string['brokenlinks'] = 'Broken links';
$string['brokenlinks:receivenotifications'] = 'Receive notifications about broken links in a course';
$string['brokenlinks:run'] = 'Run a broken link check on a course';
$string['brokenlinks:view'] = 'View the broken links report of a course';
$string['brokenlinks:viewall'] = 'View the broken links overview of all courses';
$string['cachedef_urlstatus'] = 'Results of external link checks';
$string['cachehours'] = 'Reuse results for (hours)';
$string['cachehours_desc'] = 'An external URL that was checked within this many hours is not requested again. This keeps repeated scans fast and avoids hitting the same sites over and over. Set to 0 to always check.';
$string['col_httpstatus'] = 'HTTP status';
$string['col_link'] = 'Link';
$string['col_linktext'] = 'Link text';
$string['col_location'] = 'Location';
$string['col_page'] = 'Page address';
$string['col_problem'] = 'Problem';
$string['col_status'] = 'Status';
$string['col_where'] = 'Where';
$string['downloadfilename'] = 'broken_links_{$a}';
$string['downloadreport'] = 'Download the report';
$string['error_blockedbysecurity'] = 'Not checked: this address is blocked by the site\'s HTTP security settings.';
$string['error_captivatebundle'] = 'Captivate lists this image as bundled in {$a}, but that file is missing from the package.';
$string['error_dns'] = 'The website does not exist (domain name not found).';
$string['error_embeddedfile'] = 'Embedded file is missing.';
$string['error_http'] = 'The website returned an error (HTTP {$a}).';
$string['error_moodleactivity'] = 'Links to an activity that no longer exists.';
$string['error_moodlecourse'] = 'Links to a course that no longer exists.';
$string['error_moodlefile'] = 'Links to a file on this site that no longer exists.';
$string['error_moodlesection'] = 'Links to a course section that no longer exists.';
$string['error_noresponse'] = 'The website did not respond ({$a}).';
$string['error_notfound'] = 'Page not found.';
$string['error_resourcenofile'] = 'This file resource has no file.';
$string['error_restricted'] = 'Could not be confirmed: the website refused the automated check. Open the link to check it by hand.';
$string['error_scormcase'] = 'File not found. A file with different capitalisation exists ({$a}); links must match exactly.';
$string['error_scormempty'] = 'The SCORM package has no content. It may have failed to unpack; try uploading it again.';
$string['error_scormflash'] = 'The package contains Flash (.swf) files, which no longer play in any browser.';
$string['error_scormmissing'] = 'File missing from the SCORM package.';
$string['error_scormoutside'] = 'Points outside the SCORM package.';
$string['error_scormpartial'] = 'This file holds encoded course content that could not be read, so links in it were not checked.';
$string['error_server'] = 'The website had a server error.';
$string['error_siterefuses'] = 'Could not be confirmed: the website refuses requests from this server, even for its home page, while visitors may still get the page. Open the link to check it by hand.';
$string['error_softnotfound'] = 'The page loads, but says: "{$a}"';
$string['error_ssl'] = 'The website\'s security certificate is invalid ({$a}).';
$string['error_timeout'] = 'The website did not respond (gave up after {$a} seconds). It may be slow or down.';
$string['error_urlvariables'] = 'The website rejected the link on its own. This URL activity adds data for each learner when they open it (URL variables, such as their id), which the website may need. Open the activity as a learner to check it.';
$string['error_wrappedlink'] = 'This link goes through an email security service, which can expire or block it. Link to the original address instead.';
$string['error_wrappedlinkoriginal'] = 'This link goes through an email security service, which can expire or block it. Link to the original address instead: {$a}';
$string['failed'] = 'Failed';
$string['filter_all'] = 'All problems';
$string['filter_broken'] = 'Broken ({$a})';
$string['filter_warning'] = 'Warnings ({$a})';
$string['ignoredhosts'] = 'Ignored websites';
$string['ignoredhosts_desc'] = 'Links to these domains are never checked, one per line. Subdomains are included. The defaults are XML namespace and standards sites that appear in every SCORM package but are not real links.';
$string['ignoredlinks'] = 'Links marked as not broken';
$string['ignoredlinkscount'] = 'Not broken ({$a})';
$string['ignoredlinksintro'] = 'These links are left out of the report of this course, even if the check fails. Some websites refuse automated checks but work in a browser. Remove a link from this list to check it again in the next scan.';
$string['ignorelink'] = 'Not broken';
$string['ignorelink_help'] = 'Leave this link out of the report of this course from now on';
$string['lastchecked'] = 'Last checked';
$string['linkignored'] = 'The link is marked as not broken and will be left out of future scans of this course.';
$string['linkschecked'] = 'Links checked';
$string['location_chapter'] = 'Chapter: {$a}';
$string['location_content'] = 'Content';
$string['location_description'] = 'Description';
$string['location_externalurl'] = 'URL';
$string['location_lessonpage'] = 'Lesson page: {$a}';
$string['location_scormfile'] = 'File in package: {$a}';
$string['maxlinks'] = 'Maximum external links per course';
$string['maxlinks_desc'] = 'A scan stops checking external links after this many different URLs, so a very large course cannot run for hours.';
$string['messageprovider:brokenlinksfound'] = 'Broken links found in a course you teach';
$string['messageprovider:sitesummary'] = 'Weekly summary of broken links on the site';
$string['navlabel'] = 'Link Scanner';
$string['noignoredlinks'] = 'No links are marked as not broken in this course.';
$string['noproblems'] = 'No broken links found.';
$string['noscans'] = 'No course has been checked yet.';
$string['noscanyet'] = 'This course has not been checked yet.';
$string['notfoundphrases'] = 'Pages that say their content is missing';
$string['notfoundphrases_desc'] = 'Some websites answer a dead link with a normal page that only says the content does not exist. A page that loads but contains one of these phrases, one per line, is reported as broken, with the sentence it contains. Leave empty to turn this check off.';
$string['notification_all'] = 'Broken links found in {$a->course}: {$a->count}.';
$string['notification_more'] = 'And {$a} more.';
$string['notification_new'] = 'New broken links found in {$a->course}: {$a->count}.';
$string['notification_subject'] = 'Broken links found in {$a->course}';
$string['notification_viewreport'] = 'View the broken links report';
$string['notifications'] = 'Notifications';
$string['notifications_desc'] = 'Notifications are sent after the scheduled check of all courses, which must be turned on under Site administration > Server > Scheduled tasks. Checks that teachers start themselves do not send notifications. Each person can turn these notifications on or off in their notification preferences.';
$string['notifyonlynew'] = 'Only notify about new broken links';
$string['notifyonlynew_desc'] = 'Leave out broken links that the previous check already found, so teachers are not sent the same links every week.';
$string['notifyteachers'] = 'Notify teachers';
$string['notifyteachers_desc'] = 'Send the editing teachers of a course a notification when the scheduled check finds broken links in it.';
$string['overview'] = 'Broken links overview';
$string['overviewintro'] = 'The latest broken link check of each course, worst first.';
$string['pluginname'] = 'Broken Link Scanner, Including SCORMs';
$string['privacy:metadata:core_message'] = 'The plugin sends notifications about broken links to course teachers and site administrators through the Moodle messaging system.';
$string['privacy:metadata:externalwebsites'] = 'To check that links work, the plugin requests each web address that course content links to, from the Moodle server. No information about users is sent. The plugin itself stores only the results of the checks, which are about course content, not users.';
$string['privacy:metadata:externalwebsites:url'] = 'The web address found in course content.';
$string['recheck'] = 'Check again';
$string['recheckok'] = 'The link works now and was removed from the report.';
$string['recheckstillbroken'] = 'The link still does not work: {$a}';
$string['reportintro'] = 'Checks every link in this course: activity descriptions and content, section summaries, book chapters, lesson pages, URL and file resources, and every file inside SCORM packages. Checks run in the background and can take a few minutes.';
$string['reporttitle'] = '{$a}: Broken links';
$string['runagain'] = 'Check all links again';
$string['runall'] = 'Check all courses';
$string['runalldone'] = 'All courses have been checked ({$a->done}).';
$string['runallprogress'] = 'Checking all courses: {$a->done} checked, {$a->pending} still waiting or running. This page will tell you when every course has been checked.';
$string['runallqueuing'] = 'Adding every course to the queue of link checks. This page will tell you when every course has been checked.';
$string['runcheck'] = 'Check links';
$string['scanfailed'] = 'The last check stopped because of an error.';
$string['scanfinished'] = 'The link check has finished.';
$string['scanqueued'] = 'The link check has been queued. Results appear here when it has finished.';
$string['scanrunning'] = 'A link check is running now. This page will tell you when the results are ready.';
$string['scanstopped'] = 'The link check stopped because of an error.';
$string['scantruncated'] = 'This course has more external links than the limit set by the administrator, so not all of them were checked.';
$string['scanwaiting'] = 'A link check is waiting to start. It will run the next time background tasks run, and this page will tell you when the results are ready.';
$string['scormignorepaths'] = 'Ignored SCORM package files';
$string['scormignorepaths_desc'] = 'Files in SCORM packages that match these patterns are not scanned, one per line. Use * as a wildcard. The defaults skip the player and SCORM driver libraries of Articulate and other tools, which contain hundreds of links to documentation sites that are not part of the course.';
$string['seeresults'] = 'See results';
$string['severity_broken'] = 'Broken';
$string['severity_warning'] = 'Warning';
$string['source_course'] = 'Course summary';
$string['summary_intro'] = 'Courses where the past week\'s checks found broken links: {$a}.';
$string['summary_line'] = '{$a->course}: {$a->broken} broken, {$a->warnings} warnings';
$string['summary_subject'] = 'Broken links summary: {$a} courses need attention';
$string['summary_viewoverview'] = 'View the broken links overview';
$string['task_scanallcourses'] = 'Check all courses for broken links';
$string['task_sendsitesummary'] = 'Send the weekly broken links summary to administrators';
$string['timeout'] = 'Request timeout (seconds)';
$string['timeout_desc'] = 'How long to wait for a website to respond before reporting it as not responding.';
$string['unignore'] = 'Check again in scans';
$string['unignored'] = 'The link will be checked again in the next scan.';
$string['warnings'] = 'Warnings';
