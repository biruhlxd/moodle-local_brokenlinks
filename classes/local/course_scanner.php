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

namespace local_brokenlinks\local;

/**
 * Scans one course for broken links and stores the problems it finds.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class course_scanner {
    /** @var array Text columns of activity tables that never contain HTML links. */
    const SKIP_COLUMNS = ['displayoptions', 'parameters', 'options', 'config', 'manifest', 'sha1hash', 'md5hash'];

    /** @var string[] Default ignored hosts: XML namespace, standards and player sites that are not real links. */
    const DEFAULT_IGNORED_HOSTS = [
        'w3.org',
        'adlnet.gov',
        'adlnet.org',
        'imsglobal.org',
        'imsproject.org',
        'ltsc.ieee.org',
        'purl.org',
        'w3id.org',
        'id.tincanapi.com',
        'xmlns.com',
        'schemas.xmlsoap.org',
        'schemas.microsoft.com',
        'localhost',
        'example.com',
        'metrics.articulate.com',
        'ipc.articulate.com',
        'time.akamai.com',
    ];

    /** @var array Schemes and prefixes that are not checked. */
    const SKIP_PREFIXES = ['#', 'mailto:', 'tel:', 'sms:', 'javascript:', 'data:', 'about:', 'blob:', 'file:', 'chrome:'];

    /** @var int */
    protected $courseid;

    /** @var int */
    protected $scanid;

    /** @var url_checker */
    protected $checker;

    /** @var link_extractor */
    protected $extractor;

    /** @var \file_storage */
    protected $fs;

    /** @var array Results of external checks in this scan, keyed by URL. */
    protected $checked = [];

    /** @var array Problems already recorded, to avoid duplicates. */
    protected $recorded = [];

    /** @var string[] Hosts that are never requested. */
    protected $ignoredhosts;

    /** @var array Links the teachers marked as not broken, URL => true. */
    protected $ignoredurls;

    /** @var int */
    protected $maxlinks;

    /** @var array Counters written back to the scan record. */
    protected $stats = ['linkschecked' => 0, 'brokencount' => 0, 'warningcount' => 0, 'truncated' => 0];

    /**
     * Constructor.
     *
     * @param int $courseid
     * @param int $scanid
     * @param url_checker|null $checker
     */
    public function __construct(int $courseid, int $scanid, ?url_checker $checker = null) {
        $this->courseid = $courseid;
        $this->scanid = $scanid;
        $this->checker = $checker ?? new url_checker();
        $this->extractor = new link_extractor();
        $this->fs = get_file_storage();
        $config = get_config('local_brokenlinks');
        $this->ignoredhosts = self::parse_list($config->ignoredhosts ?? '');
        $this->maxlinks = (int)($config->maxlinks ?? 5000);
        $this->ignoredurls = ignore_list::get_urls($courseid);
    }

    /**
     * Scan the whole course.
     *
     * @return array Counters: linkschecked, brokencount, warningcount, truncated.
     */
    public function run(): array {
        $course = get_course($this->courseid);
        $coursecontext = \context_course::instance($course->id);

        $this->scan_html($course->summary ?? '', [
            'sourcetype' => 'course',
            'location' => 'summary',
            'contextid' => $coursecontext->id,
            'component' => 'course',
            'filearea' => 'summary',
            'itemid' => 0,
        ]);

        $modinfo = get_fast_modinfo($course, -1);
        foreach ($modinfo->get_section_info_all() as $section) {
            $this->scan_html($section->summary ?? '', [
                'sourcetype' => 'section',
                'sectionid' => $section->id,
                'location' => 'summary',
                'contextid' => $coursecontext->id,
                'component' => 'course',
                'filearea' => 'section',
                'itemid' => $section->id,
            ]);
        }

        foreach ($modinfo->get_cms() as $cm) {
            if (!empty($cm->deletioninprogress)) {
                continue;
            }
            $this->scan_activity($cm);
        }

        return $this->stats;
    }

    /**
     * Scan one activity.
     *
     * @param \cm_info $cm
     */
    protected function scan_activity(\cm_info $cm): void {
        global $DB;
        $record = $DB->get_record($cm->modname, ['id' => $cm->instance]);
        if (!$record) {
            return;
        }
        $context = \context_module::instance($cm->id);
        $component = 'mod_' . $cm->modname;
        $base = ['sourcetype' => 'activity', 'cmid' => $cm->id, 'contextid' => $context->id, 'component' => $component];

        // Every long text column of the activity table: intro, page content and similar.
        foreach ($DB->get_columns($cm->modname) as $name => $column) {
            if (
                $column->meta_type !== 'X' || in_array($name, self::SKIP_COLUMNS) || empty($record->$name)
                    || ($cm->modname === 'url' && $name === 'externalurl')
            ) {
                continue;
            }
            $known = self::known_file_area($cm->modname, $name);
            $this->scan_html((string)$record->$name, $base + [
                'location' => $name,
                'filearea' => $known,
                'itemid' => $known === null ? null : 0,
            ]);
        }

        switch ($cm->modname) {
            case 'url':
                $this->check_link($base + ['location' => 'externalurl'], trim((string)$record->externalurl), '');
                break;

            case 'resource':
                if (!$this->fs->get_area_files($context->id, 'mod_resource', 'content', 0, 'id', false)) {
                    $this->record(
                        $base + ['location' => 'content'],
                        '',
                        '',
                        'notice',
                        url_checker::BROKEN,
                        0,
                        get_string('error_resourcenofile', 'local_brokenlinks')
                    );
                }
                break;

            case 'book':
                $chapters = $DB->get_records('book_chapters', ['bookid' => $record->id], 'pagenum', 'id, title, content');
                foreach ($chapters as $chapter) {
                    $this->scan_html((string)$chapter->content, $base + [
                        'location' => get_string(
                            'location_chapter',
                            'local_brokenlinks',
                            format_string($chapter->title, true, ['escape' => false])
                        ),
                        'filearea' => 'chapter',
                        'itemid' => $chapter->id,
                    ]);
                }
                break;

            case 'lesson':
                $pages = $DB->get_records('lesson_pages', ['lessonid' => $record->id], 'id', 'id, title, contents');
                foreach ($pages as $page) {
                    $this->scan_html((string)$page->contents, $base + [
                        'location' => get_string(
                            'location_lessonpage',
                            'local_brokenlinks',
                            format_string($page->title, true, ['escape' => false])
                        ),
                        'filearea' => 'page_contents',
                        'itemid' => $page->id,
                    ]);
                }
                break;

            case 'scorm':
                (new scorm_scanner($this, $this->extractor))->scan($cm, $record, $context);
                break;
        }
    }

    /**
     * The file area that holds embedded files for an activity text column, if known.
     *
     * @param string $modname
     * @param string $column
     * @return string|null
     */
    protected static function known_file_area(string $modname, string $column): ?string {
        if ($column === 'intro') {
            return 'intro';
        }
        $areas = [
            'page' => ['content' => 'content'],
            'assign' => ['activity' => 'activityattachment'],
        ];
        return $areas[$modname][$column] ?? null;
    }

    /**
     * Extract and check all links in a piece of HTML.
     *
     * @param string $html
     * @param array $source Where the HTML came from.
     */
    public function scan_html(string $html, array $source): void {
        if (trim($html) === '') {
            return;
        }
        foreach ($this->extractor->extract_from_html($html) as $link) {
            $this->check_link($source, $link['url'], $link['text']);
        }
    }

    /**
     * Check one link found in Moodle content.
     *
     * @param array $source
     * @param string $url
     * @param string $text
     */
    public function check_link(array $source, string $url, string $text): void {
        $url = trim($url);
        if (self::is_skipped($url)) {
            return;
        }
        if (stripos($url, '@@PLUGINFILE@@') === 0) {
            $this->check_pluginfile_placeholder($source, $url, $text);
            return;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } else if (str_starts_with($url, '/')) {
            $url = self::site_origin() . $url;
        }
        if (preg_match('~^https?://~i', $url)) {
            $this->check_absolute($source, $url, $text);
        }
    }

    /**
     * Whether a link is of a kind that is never checked.
     *
     * @param string $url
     * @return bool
     */
    public static function is_skipped(string $url): bool {
        if ($url === '') {
            return true;
        }
        $lower = strtolower($url);
        foreach (self::SKIP_PREFIXES as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                return true;
            }
        }
        // Template placeholders such as {{url}}, ${path} or %%WWWROOT%% are filled in at runtime.
        return (bool)preg_match('~\{\{|\$\{|%%|\{[a-z_]+\}~i', $url);
    }

    /**
     * Check an absolute http(s) URL.
     *
     * Links to this Moodle site are checked against the database, other links over HTTP.
     *
     * @param array $source
     * @param string $url
     * @param string $text
     */
    public function check_absolute(array $source, string $url, string $text): void {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            return;
        }
        if ($this->is_own_site($url)) {
            $this->check_moodle_url($source, $url, $text);
            return;
        }
        // Hosts without a dot, such as Storyline course ids written as URLs, are not real websites.
        if (!str_contains($host, '.') && !str_contains($host, ':')) {
            return;
        }
        if ($this->is_ignored_host($host)) {
            return;
        }
        // The part after # is never sent to the server.
        $url = preg_replace('~#.*$~', '', $url);
        if (isset($this->ignoredurls[$url])) {
            $this->stats['linkschecked']++;
            return;
        }

        if (!array_key_exists($url, $this->checked)) {
            if (count($this->checked) >= $this->maxlinks) {
                $this->stats['truncated'] = 1;
                return;
            }
            $this->checked[$url] = $this->checker->check($url);
        }
        $this->stats['linkschecked']++;
        $result = $this->checked[$url];
        if ($result['severity'] !== url_checker::OK) {
            $this->record($source, $url, $text, 'external', $result['severity'], $result['httpstatus'], $result['message']);
        }
    }

    /**
     * Check a link to this Moodle site: deleted activities, courses, sections and files.
     *
     * Other pages on the site need a logged-in session and are not checked.
     *
     * @param array $source
     * @param string $url
     * @param string $text
     */
    protected function check_moodle_url(array $source, string $url, string $text): void {
        global $CFG, $DB;
        $path = (string)parse_url($url, PHP_URL_PATH);
        $sitepath = rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/');
        if ($sitepath !== '' && str_starts_with($path, $sitepath)) {
            $path = substr($path, strlen($sitepath));
        }
        parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
        $id = isset($query['id']) && is_numeric($query['id']) ? (int)$query['id'] : null;
        $message = null;

        if (preg_match('~^/mod/([a-z0-9_]+)/view\.php$~', $path, $match) && $id) {
            $sql = "SELECT cm.id, cm.deletioninprogress
                      FROM {course_modules} cm
                      JOIN {modules} m ON m.id = cm.module
                     WHERE cm.id = :id AND m.name = :modname";
            $cm = $DB->get_record_sql($sql, ['id' => $id, 'modname' => $match[1]]);
            if (!$cm || $cm->deletioninprogress) {
                $message = get_string('error_moodleactivity', 'local_brokenlinks');
            }
        } else if ($path === '/course/view.php' && $id) {
            if (!$DB->record_exists('course', ['id' => $id])) {
                $message = get_string('error_moodlecourse', 'local_brokenlinks');
            }
        } else if ($path === '/course/section.php' && $id) {
            if (!$DB->record_exists('course_sections', ['id' => $id])) {
                $message = get_string('error_moodlesection', 'local_brokenlinks');
            }
        } else if (preg_match('~^/(?:webservice/|token)?pluginfile\.php/(\d+)/([a-z0-9_]+)/([a-z0-9_]+)/(.+)$~', $path, $m)) {
            if (!$this->pluginfile_exists((int)$m[1], $m[2], $m[3], rawurldecode($m[4]))) {
                $message = get_string('error_moodlefile', 'local_brokenlinks');
            }
        } else {
            return;
        }

        $this->stats['linkschecked']++;
        if ($message !== null) {
            $this->record($source, $url, $text, 'moodle', url_checker::BROKEN, 0, $message);
        }
    }

    /**
     * Whether a pluginfile.php path points to an existing file.
     *
     * The path after the file area may or may not start with an item id or a revision number,
     * so a file matches when the requested path ends with its file path and name.
     *
     * @param int $contextid
     * @param string $component
     * @param string $filearea
     * @param string $rest
     * @return bool
     */
    protected function pluginfile_exists(int $contextid, string $component, string $filearea, string $rest): bool {
        $rest = '/' . ltrim($rest, '/');
        foreach ($this->fs->get_area_files($contextid, $component, $filearea, false, 'id', false) as $file) {
            if (str_ends_with($rest, $file->get_filepath() . $file->get_filename())) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check an @@PLUGINFILE@@ link against the file area of the text it came from.
     *
     * @param array $source
     * @param string $url
     * @param string $text
     */
    protected function check_pluginfile_placeholder(array $source, string $url, string $text): void {
        if (empty($source['filearea']) || !isset($source['itemid'])) {
            return;
        }
        $path = preg_replace('~[?#].*$~', '', substr($url, strlen('@@PLUGINFILE@@')));
        $path = '/' . ltrim(rawurldecode($path), '/');
        $filename = basename($path);
        $filepath = rtrim(dirname($path), '/') . '/';
        $this->stats['linkschecked']++;
        if (
            !$this->fs->file_exists(
                $source['contextid'],
                $source['component'],
                $source['filearea'],
                $source['itemid'],
                $filepath,
                $filename
            )
        ) {
            $this->record(
                $source,
                $filename,
                $text,
                'file',
                url_checker::BROKEN,
                0,
                get_string('error_embeddedfile', 'local_brokenlinks')
            );
        }
    }

    /**
     * Count a link that was checked by another scanner, such as a file inside a SCORM package.
     */
    public function count_checked(): void {
        $this->stats['linkschecked']++;
    }

    /**
     * Store a problem.
     *
     * @param array $source
     * @param string $url
     * @param string $text
     * @param string $linktype external, moodle, file, package or notice.
     * @param int $severity
     * @param int $httpstatus
     * @param string $message
     */
    public function record(
        array $source,
        string $url,
        string $text,
        string $linktype,
        int $severity,
        int $httpstatus,
        string $message
    ): void {
        global $DB;
        $key = implode('|', [$source['sourcetype'], $source['cmid'] ?? '', $source['sectionid'] ?? '',
            $source['location'], $url]);
        if (isset($this->recorded[$key]) || isset($this->ignoredurls[$url])) {
            return;
        }
        $this->recorded[$key] = true;
        $this->stats[$severity === url_checker::BROKEN ? 'brokencount' : 'warningcount']++;

        $DB->insert_record('local_brokenlinks_link', (object)[
            'scanid' => $this->scanid,
            'courseid' => $this->courseid,
            'cmid' => $source['cmid'] ?? null,
            'sectionid' => $source['sectionid'] ?? null,
            'sourcetype' => $source['sourcetype'],
            'location' => \core_text::substr($source['location'], 0, 1333),
            'url' => $url,
            'linktext' => \core_text::substr(trim($text), 0, 255),
            'linktype' => $linktype,
            'severity' => $severity,
            'httpstatus' => $httpstatus,
            'message' => \core_text::substr($message, 0, 1333),
        ]);
    }

    /**
     * Whether the URL points to this Moodle site.
     *
     * @param string $url
     * @return bool
     */
    protected function is_own_site(string $url): bool {
        global $CFG;
        $site = parse_url($CFG->wwwroot);
        $link = parse_url($url);
        return strtolower($site['host'] ?? '') === strtolower($link['host'] ?? '')
            && ($site['port'] ?? null) === ($link['port'] ?? null);
    }

    /**
     * Whether a host, or a domain it belongs to, is on the ignore list.
     *
     * @param string $host
     * @return bool
     */
    protected function is_ignored_host(string $host): bool {
        foreach ($this->ignoredhosts as $ignored) {
            if ($host === $ignored || str_ends_with($host, '.' . $ignored)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Scheme, host and port of this site, without its path.
     *
     * @return string
     */
    protected static function site_origin(): string {
        global $CFG;
        $parts = parse_url($CFG->wwwroot);
        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * Split a setting with one entry per line or comma into a list.
     *
     * @param string $value
     * @return string[]
     */
    public static function parse_list(string $value): array {
        $items = preg_split('~[\r\n,]+~', strtolower($value));
        return array_values(array_filter(array_map('trim', $items), fn($item) => $item !== ''));
    }
}
