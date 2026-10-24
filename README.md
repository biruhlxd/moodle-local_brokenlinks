# Broken Link Scanner, Including SCORMs (local_brokenlinks)

A Moodle 5.x plugin that finds broken links in courses, **including links inside SCORM packages** made with Articulate Storyline, Rise, Adobe Captivate and other tools.

## What it checks

**Course content**
- Course summary and section summaries
- The description and every text field of every activity (pages, labels, assignments, quizzes, forums and any other activity type)
- Book chapters and lesson pages
- URL activities, and file resources that have no file
- Embedded files (`@@PLUGINFILE@@`) that are missing from the file area
- Links to this Moodle site that point to a deleted activity, course, section or file

**SCORM packages** (read from the files Moodle extracted when the package was uploaded)
- Every HTML, JavaScript, CSS and JSON file in the package
- **External links** are checked over HTTP
- **Internal links** (images, scripts, pages and attachments) are checked against the files in the package, including links whose capitalisation doesn't match the file name, which fail on Moodle's file storage
- Files listed in `imsmanifest.xml` that are missing
- **Articulate Storyline**: links in slide data (`html5/data/js/*.js`), such as "Open URL" triggers and attached files
- **Articulate Rise**: the lesson content Rise stores as encoded data in `scormcontent/index.html` is decoded and checked
- **Adobe Captivate**: the images, audio, video and web objects listed in the slide data (`assets/js/CPM.js`) are checked, including images Captivate bundles into `dr/img*.json`; links in web objects and "Open URL" actions are checked too
- Packages loaded from an external URL, empty packages that failed to unpack, and Flash content

## How results are graded

| Result | Meaning |
|---|---|
| **Broken** | 404 or 410, server error, domain doesn't exist, invalid certificate, a page that loads but says its content doesn't exist, missing file, deleted activity |
| **Warning** | Couldn't be confirmed: the site refused automated checks (401, 403, 429), timed out, or is blocked by Moodle's HTTP security settings |

## Using it

- **Teachers and managers:** Course → More → **Link Scanner** → *Check links*. The check runs as a background task, so cron must be running. Results replace the previous check.
- **Site admins:** Site administration → Reports → **Broken links overview** lists every checked course, worst first, and has a *Check all courses* button.
- **Scheduled checks:** enable *Check all courses for broken links* under Site administration → Server → Scheduled tasks. It's disabled by default and set to Sundays at 02:00.
- **Command line** (runs straight away, no cron needed):
  ```
  php public/local/brokenlinks/cli/scan.php --course=2
  php public/local/brokenlinks/cli/scan.php --all
  php public/local/brokenlinks/cli/scan.php --course=2 --notify   # also send the teacher notification
  ```

In the report:
- **Check again** checks one web link straight away, without the cache. If it works now it leaves the report, so a repaired website can be confirmed without a full check.
- **Not broken** leaves a link out of this course's report from now on, for websites that refuse automated checks but work in a browser. The **Not broken (n)** button lists these links; remove one to check it again.
- **Download the report** as CSV, Excel, ODS, JSON, HTML or PDF, with where each link is, its link text, the problem and a link to the page.

## Notifications

Notifications are sent through Moodle's own messaging (web notification and email by default). Each person can switch them on or off in their notification preferences.

- **Teachers:** after the scheduled check, the editing teachers of each course are notified about its broken links. The message lists the first 5 with where they are and links to the report. By default only links that the previous check didn't already find are included, so nobody gets the same links every week. Checks that teachers start themselves don't send notifications, because they'll be looking at the report.
- **Administrators (optional):** every Monday at 07:00, site administrators get a summary of the courses whose checks in the past week found broken links. Turn on *Weekly summary for administrators* in the plugin settings.

## Settings

Site administration → Plugins → Local plugins → Broken Link Scanner, Including SCORMs

- **Request timeout**: seconds to wait for a website (default 10)
- **Reuse results for**: hours before the same external URL is requested again (default 24)
- **Pages that say their content is missing**: phrases such as "page not found" or "unavailable or does not exist". A link whose page loads but contains one is reported as broken, with the sentence the page shows (empty turns this off)
- **Maximum external links per course**: safety limit (default 5000)
- **Ignored websites**: XML namespace and standards domains that appear in every package but are not real links
- **Ignored SCORM package files**: player and SCORM driver libraries (`lms/*`, `html5/lib/*`, `scormdriver/*`, `*.min.js`, Captivate's `SCORM_utilities.js`). These contain hundreds of documentation links that are not part of the course.
- **Notify teachers** (on), **Only notify about new broken links** (on), **Weekly summary for administrators** (off)

## Capabilities

| Capability | Default | Purpose |
|---|---|---|
| `local/brokenlinks:view` | Editing teacher, manager | View the report of a course |
| `local/brokenlinks:run` | Editing teacher, manager | Start a check, check a link again, mark links as not broken |
| `local/brokenlinks:receivenotifications` | Editing teacher | Get notified about broken links in courses they're enrolled in |
| `local/brokenlinks:viewall` | Manager | Site-wide overview |

## Extending the report

Other plugins can add to the report through Moodle's Hooks API (`db/hooks.php`):

| Hook | Use |
|---|---|
| `\local_brokenlinks\hook\report_row_actions` | Add buttons to a row, for example to fix the link |
| `\local_brokenlinks\hook\report_header_actions` | Add buttons next to *Check links* |
| `\local_brokenlinks\hook\report_footer` | Add sections below the report |
| `\local_brokenlinks\hook\scorm_package_scanned` | Check the files of each SCORM package for more problems while a course is scanned, and add them to the report |

## Limitations

- Links that a package builds in JavaScript while it runs (for example by joining strings) can't be found by reading the files.
- Pages on this site other than activities, courses, sections and files need a logged-in session, so they are not checked.
- Question text in question banks and user posts (forum posts, glossary entries) are not scanned.
- Some websites block automated checks. These are reported as warnings, so they can be confirmed by hand and marked as **Not broken**.

## Tests

```
vendor/bin/phpunit --testsuite local_brokenlinks_testsuite
```

## Author

Biruh Alemnew <biruh@biruh.org>

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
