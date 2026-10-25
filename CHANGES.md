# Changelog

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
