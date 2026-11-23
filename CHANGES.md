# Changelog

## Unreleased

- **Links outside courses**: the site home's report (linked from the overview as "Links outside courses") checks the whole site: the site home's summary, sections and activities, the custom menu and user menu, the additional HTML, the login instructions and the maintenance message, the settings of the site's theme and its parent themes (such as the footer), and the blocks outside courses: the Dashboard's, the site home's, and the pages made with Edwiser Page Builder (published pages only). "Check all courses" includes it.
- **Blocks**: the text of the blocks in a course and its activities is checked as part of the course, for the Text block and for blocks of other plugins.

## 1.1.0 (2026-11-05)

- **One row per link**: a link with the same problem in several places is one row of the report, with **Also in N other places** listing the rest. Authoring tools often repeat the same panel on every slide, which made one slow website fill the report. The download still has one row per place.
- **Slow websites are tried again**: after two time-outs in a row, a website's other links are not waited for; at the end of the check, after a pause, its links are tried once more. Time-outs are no longer kept in the results cache, so the next check asks again.
- Rise packages: the addresses of Articulate's own services in the player settings (its file server, the course update address, review and telemetry) are no longer checked or reported. They are not links in the course.
- The YouTube player script that Captivate's launch page loads to play videos is no longer checked or reported.
- Addresses typed without `http://` in Storyline and other slide data, such as `www.example.org`, are checked as websites; they were reported as files missing from the package.
- The check records where inside a package each link is (the Rise lesson and block, the Storyline scene, slide and layer, the Captivate slide). Other plugins can show it in the report, with a button that opens the package at that place, through the new `link_place` hook. New field `place` in `local_brokenlinks_link`.
- New hooks for other plugins: `link_place`, to describe where inside a package a link is, and `scan_finished`.

## 1.0.3 (2026-10-26)

Fixes from testing on sites with Rise courses:

- Files whose names contain apostrophes (such as Rise media "sam's studio (2).mp4") are no longer reported as missing from the package: Moodle stores and serves them without the apostrophe.
- Addresses in Rise course data that contain apostrophes are checked in full instead of being cut at the apostrophe.
- A URL activity with URL variables (data Moodle adds for each learner, such as their id) is a warning, not a broken link, when the website rejects the bare address (HTTP 400, 405 or 422).
- DOI links are checked with doi.org's own lookup service.
- Links rewritten by email security services (Barracuda, Microsoft Safe Links, Proofpoint) are warnings that give the original address.
- A "not found" link is a warning when the website's home page fails too: some websites answer "not found" for every page to servers in hosting centres.

## 1.0.2 (2026-10-25)

- **Check all courses** on the site overview now shows its progress (courses checked and still waiting) and says when every course has been checked, with a **See results** button.

## 1.0.1 (2026-10-25)

- While a link check is waiting or running, the report page now watches it and says when it has finished, with a **See results** button, instead of asking the user to reload the page.

## 1.0.0 (2026-10-25)

First public release. Earlier versions (0.1–0.5) were a private all-in-one plugin; its fixing and SCORM export tools are now a separate add-on.

- Checks every link in a course: summaries, activity text, book chapters, lesson pages, URL and file resources, embedded files, and links to deleted activities, courses, sections and files.
- Checks inside SCORM packages: external links, files the package needs (including wrong capitalisation), the manifest, Storyline slide data, Rise's encoded lesson data and Captivate's slide data and bundled images.
- Detects "soft 404" pages that load normally but say their content does not exist.
- Report per course with **Check again** for one link, **Not broken** to leave a link out of future checks, and **Download** as CSV, Excel, ODS, JSON, HTML or PDF.
- Site overview, scheduled checks of all courses, notifications to teachers and a weekly summary for administrators, and a command-line tool.
- Hooks for other plugins: buttons on report rows and at the top, sections below the report, and extra checks of SCORM packages.
- Privacy API: declares that the plugin requests the web addresses linked from courses and sends notifications.
- Moodle 5.0 to 5.3.
