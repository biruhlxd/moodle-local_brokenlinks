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
 * Scans the files of a SCORM package for broken links.
 *
 * Moodle extracts every uploaded package into the mod_scorm content file area, so the files are
 * read from there. External links are checked over HTTP, relative links are checked against the
 * other files in the package.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scorm_scanner {
    /** @var int Files larger than this are not read, in bytes. */
    const MAX_FILE_SIZE = 30 * 1024 * 1024;

    /** @var string[] Default ignored package files: player and SCORM driver libraries of authoring tools. */
    const DEFAULT_IGNORED_PATHS = [
        'lms/*',
        'scormdriver/*',
        'html5/lib/*',
        'mobile/*',
        'lib/*',
        'scormcontent/lib/*',
        '*.min.js',
        '*.xsd',
        'scormdriver.js',
        'browsersniff.js',
        'analytics-frame.html',
        'scorm_api*.js',
        'apiwrapper*.js',
        'SCORM_utilities.js',
        'Utilities.js',
        'CPXHRLoader.js',
    ];

    /**
     * @var string Regex for media paths in Captivate slide data, such as 'dr/image.png' or 'wor/wo_1/index.html'.
     *
     * Only single-quoted paths are matched: the project data uses single quotes, while the player code in the
     * same file uses double quotes for optional files such as "ar/KeyClick.mp3", which is only published when used.
     */
    const CAPTIVATE_MEDIA = <<<'REGEX'
~'((?:dr|ar|vr|wor)/[^'"\\<>]+?\.[a-z0-9]{2,5})'~i
REGEX;

    /** @var course_scanner */
    protected $scanner;

    /** @var link_extractor */
    protected $extractor;

    /** @var string[] Path patterns of package files that are not scanned, such as player libraries. */
    protected $ignoredpaths;

    /** @var array Exact path => true for every file in the package. */
    protected $paths = [];

    /** @var array Lowercase path => real path, to spot links whose capitalisation is wrong. */
    protected $lowerpaths = [];

    /** @var array File name => true for every file in the package. */
    protected $basenames = [];

    /** @var string Folder of the launch file, relative to the package root. */
    protected $launchdir = '';

    /** @var array Captivate images bundled into JSON files: image path => path of the JSON file that holds it. */
    protected $bundledimages = [];

    /** @var package_places|null The Storyline and Captivate slides of the package. */
    protected $places = null;

    /**
     * Constructor.
     *
     * @param course_scanner $scanner Receives the results.
     * @param link_extractor $extractor
     */
    public function __construct(course_scanner $scanner, link_extractor $extractor) {
        $this->scanner = $scanner;
        $this->extractor = $extractor;
        $this->ignoredpaths = course_scanner::parse_list(get_config('local_brokenlinks', 'scormignorepaths') ?: '');
    }

    /**
     * Scan one SCORM activity.
     *
     * @param \cm_info $cm
     * @param \stdClass $scorm The scorm table record.
     * @param \context_module $context
     */
    public function scan(\cm_info $cm, \stdClass $scorm, \context_module $context): void {
        $source = ['sourcetype' => 'scorm', 'cmid' => $cm->id];

        // Packages loaded from a URL break when that URL stops working.
        if (in_array($scorm->scormtype, ['external', 'aiccurl', 'localsync']) && !empty($scorm->reference)) {
            $this->scanner->check_link($source + ['location' => 'imsmanifest.xml'], $scorm->reference, '');
            if ($scorm->scormtype !== 'localsync') {
                return;
            }
        }

        $files = get_file_storage()->get_area_files($context->id, 'mod_scorm', 'content', 0, 'filepath, filename', false);
        if (!$files) {
            $this->scanner->record(
                $source + ['location' => '/'],
                '',
                '',
                'notice',
                url_checker::BROKEN,
                0,
                get_string('error_scormempty', 'local_brokenlinks')
            );
            return;
        }

        $this->paths = [];
        $this->lowerpaths = [];
        $this->basenames = [];
        $bypath = [];
        foreach ($files as $file) {
            $path = ltrim($file->get_filepath() . $file->get_filename(), '/');
            $this->paths[$path] = true;
            $this->lowerpaths[\core_text::strtolower($path)] = $path;
            $this->basenames[$file->get_filename()] = true;
            $bypath[$path] = $file;
        }
        $this->launchdir = $this->find_launch_dir($files);
        $this->bundledimages = $this->find_captivate_bundles($files);
        $this->places = package_places::from_files($bypath);

        $hasflash = false;
        $bypath = [];
        foreach ($files as $file) {
            $path = ltrim($file->get_filepath() . $file->get_filename(), '/');
            $bypath[$path] = $file;
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension === 'swf') {
                $hasflash = true;
            }
            if ($this->is_ignored_path($path) || $file->get_filesize() > self::MAX_FILE_SIZE) {
                continue;
            }
            $this->scan_file($file, $path, $extension, $source + ['location' => $path]);
        }

        // Other plugins can check the package for more problems.
        \core\di::get(\core\hook\manager::class)->dispatch(
            new \local_brokenlinks\hook\scorm_package_scanned($this->scanner, $cm, $source, $bypath)
        );

        if ($hasflash) {
            $this->scanner->record(
                $source + ['location' => '/'],
                '',
                '',
                'notice',
                url_checker::WARNING,
                0,
                get_string('error_scormflash', 'local_brokenlinks')
            );
        }
    }

    /**
     * Scan one file of the package.
     *
     * @param \stored_file $file
     * @param string $path Path relative to the package root.
     * @param string $extension
     * @param array $source
     */
    protected function scan_file(\stored_file $file, string $path, string $extension, array $source): void {
        $links = [];
        $fromscript = [];
        switch ($extension) {
            case 'html':
            case 'htm':
            case 'xhtml':
                $content = $file->get_content();
                $links = $this->extractor->extract_from_html($content);
                // Inline scripts, including the encoded course data of Rise packages.
                $fromscript = $this->extractor->extract_from_js($content);
                if ($this->extractor->has_undecodable_data($content)) {
                    $this->scanner->record(
                        $source,
                        '',
                        '',
                        'notice',
                        url_checker::WARNING,
                        0,
                        get_string('error_scormpartial', 'local_brokenlinks')
                    );
                }
                break;
            case 'js':
            case 'json':
                $content = $file->get_content();
                $fromscript = $this->extractor->extract_from_js($content);
                if (basename($path) === 'CPM.js') {
                    $this->check_captivate_media($content, $source);
                }
                break;
            case 'css':
                $links = $this->extractor->extract_from_css($file->get_content());
                break;
            case 'xml':
                if (strtolower(basename($path)) === 'imsmanifest.xml') {
                    $this->check_manifest($file->get_content(), $source);
                }
                return;
            default:
                return;
        }

        foreach ($links as $link) {
            $this->check_package_link($this->with_place($source, $link['url']), $path, $link['url'], $link['text'], false);
        }
        foreach ($fromscript as $link) {
            // Links in Rise course data say which lesson and block they are in.
            $linksource = isset($link['place']) ? $source + ['place' => $link['place']] : $this->with_place($source, $link['url']);
            $this->check_package_link($linksource, $path, $link['url'], $link['text'], true);
        }
    }

    /**
     * Add the Storyline or Captivate slide a link is on to its source.
     *
     * @param array $source With the location of the file the link is in.
     * @param string $url The link, or media path, as found in the file.
     * @return array
     */
    protected function with_place(array $source, string $url): array {
        $place = $this->places ? $this->places->find($source['location'], $url) : null;
        return $place ? $source + ['place' => $place] : $source;
    }

    /**
     * Check the images, audio, video and web objects of an Adobe Captivate project.
     *
     * Captivate's slide data (assets/js/CPM.js) refers to its media by paths such as ip:'dr/image.png'
     * or wou:'wor/wo_1/index.html', relative to the launch page. Images may also be bundled into a JSON
     * file in dr/, listed in dr/imgmd.json, instead of being separate files.
     *
     * @param string $js
     * @param array $source
     */
    protected function check_captivate_media(string $js, array $source): void {
        preg_match_all(self::CAPTIVATE_MEDIA, $js, $matches);
        $cpmsource = $source;
        foreach (array_unique($matches[1]) as $path) {
            $this->scanner->count_checked();
            $source = $this->with_place($cpmsource, $path);
            $candidates = array_unique(array_filter([
                self::normalise_path($this->launchdir === '' ? $path : $this->launchdir . '/' . $path),
                self::normalise_path($path),
            ], fn($candidate) => $candidate !== null));
            foreach ($candidates as $candidate) {
                if (isset($this->paths[$candidate])) {
                    continue 2;
                }
            }
            foreach ($candidates as $candidate) {
                if (isset($this->bundledimages[$candidate])) {
                    if (!isset($this->paths[$this->bundledimages[$candidate]])) {
                        $this->scanner->record(
                            $source,
                            $path,
                            '',
                            'package',
                            url_checker::BROKEN,
                            0,
                            get_string('error_captivatebundle', 'local_brokenlinks', $this->bundledimages[$candidate])
                        );
                    }
                    continue 2;
                }
            }
            foreach ($candidates as $candidate) {
                $lower = \core_text::strtolower($candidate);
                if (isset($this->lowerpaths[$lower])) {
                    $this->scanner->record(
                        $source,
                        $path,
                        '',
                        'package',
                        url_checker::BROKEN,
                        0,
                        get_string('error_scormcase', 'local_brokenlinks', $this->lowerpaths[$lower])
                    );
                    continue 2;
                }
            }
            $this->scanner->record(
                $source,
                $path,
                '',
                'package',
                url_checker::BROKEN,
                0,
                get_string('error_scormmissing', 'local_brokenlinks')
            );
        }
    }

    /**
     * Read the map of Captivate images that are bundled into JSON files.
     *
     * dr/imgmd.json holds JavaScript like cp.imageToJSONPathMap={"dr/a.png": "img1.json", ...}.
     *
     * @param \stored_file[] $files
     * @return array Image path => path of the JSON file that holds it, both relative to the package root.
     */
    protected function find_captivate_bundles(array $files): array {
        $bundles = [];
        foreach ($files as $file) {
            if ($file->get_filename() !== 'imgmd.json') {
                continue;
            }
            $content = $file->get_content();
            $start = strpos($content, '{');
            $end = strrpos($content, '}');
            $map = $start === false || $end === false ? null : json_decode(substr($content, $start, $end - $start + 1), true);
            if (!is_array($map)) {
                continue;
            }
            $dir = trim($file->get_filepath(), '/');
            $root = dirname($dir) === '.' ? '' : dirname($dir) . '/';
            foreach ($map as $image => $jsonfile) {
                if (is_string($image) && is_string($jsonfile)) {
                    $bundles[$root . $image] = ($dir === '' ? '' : $dir . '/') . $jsonfile;
                }
            }
        }
        return $bundles;
    }

    /**
     * Check that the files listed in imsmanifest.xml exist.
     *
     * @param string $xml
     * @param array $source
     */
    protected function check_manifest(string $xml, array $source): void {
        preg_match_all('~<(?:[a-z0-9]+:)?(?:resource|file)\b[^>]*\shref\s*=\s*["\']([^"\']+)["\']~i', $xml, $matches);
        foreach (array_unique($matches[1]) as $href) {
            $href = html_entity_decode($href, ENT_QUOTES | ENT_XML1, 'UTF-8');
            if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) {
                $this->scanner->check_link($source, $href, '');
                continue;
            }
            $this->check_relative($source, '', $href, '', false);
        }
    }

    /**
     * Check a link found inside a package file.
     *
     * @param array $source
     * @param string $filepath Path of the file the link is in.
     * @param string $url
     * @param string $text
     * @param bool $fromscript Whether the link came from script data rather than HTML or CSS.
     */
    protected function check_package_link(array $source, string $filepath, string $url, string $text, bool $fromscript): void {
        $url = trim(html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (course_scanner::is_skipped($url)) {
            return;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } else if ($fromscript && preg_match('~^www\.[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}([/?#]|$)~i', $url)) {
            // Authoring tools open an address typed without http://, such as www.example.org, as a website.
            $url = 'http://' . $url;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            if (preg_match('~^https?://~i', $url)) {
                $this->scanner->check_absolute($source, $url, $text);
            }
            return;
        }
        if (str_starts_with($url, '/')) {
            // Relative to the web server, not the package, so it cannot be resolved here.
            return;
        }
        $this->check_relative($source, dirname($filepath), $url, $text, $fromscript);
    }

    /**
     * Check that a relative link points to a file in the package.
     *
     * Scripts load files relative to the page that runs them, not to the script file, so links
     * from scripts are also looked up from the package root and from the launch folder.
     *
     * @param array $source
     * @param string $basedir Folder the link is relative to.
     * @param string $url
     * @param string $text
     * @param bool $fromscript
     */
    protected function check_relative(array $source, string $basedir, string $url, string $text, bool $fromscript): void {
        $relative = rawurldecode(preg_replace('~[?#].*$~', '', $url));
        // Only links to files are checked; values without an extension are usually not paths.
        if ($relative === '' || str_ends_with($relative, '/') || !preg_match('~\.[a-z0-9]{1,5}$~i', $relative)) {
            return;
        }

        $bases = $fromscript ? array_unique([$basedir, '', $this->launchdir]) : [$basedir];
        $candidates = [];
        foreach ($bases as $base) {
            $resolved = self::normalise_path(($base === '' || $base === '.') ? $relative : $base . '/' . $relative);
            if ($resolved !== null) {
                $candidates[] = $resolved;
            }
        }

        $this->scanner->count_checked();
        if (!$candidates) {
            $this->scanner->record(
                $source,
                $url,
                $text,
                'package',
                url_checker::BROKEN,
                0,
                get_string('error_scormoutside', 'local_brokenlinks')
            );
            return;
        }
        foreach ($candidates as $candidate) {
            if (isset($this->paths[$candidate])) {
                return;
            }
        }
        // Players such as Storyline remap the paths in their data at runtime, for example from story_content/
        // to mobile/, so a script reference is fine when a file of that name exists anywhere in the package.
        if ($fromscript && isset($this->basenames[self::stored_name(basename($relative))])) {
            return;
        }
        foreach ($candidates as $candidate) {
            $lower = \core_text::strtolower($candidate);
            if (isset($this->lowerpaths[$lower])) {
                $this->scanner->record(
                    $source,
                    $url,
                    $text,
                    'package',
                    url_checker::BROKEN,
                    0,
                    get_string('error_scormcase', 'local_brokenlinks', $this->lowerpaths[$lower])
                );
                return;
            }
        }
        $this->scanner->record(
            $source,
            $url,
            $text,
            'package',
            url_checker::BROKEN,
            0,
            get_string('error_scormmissing', 'local_brokenlinks')
        );
    }

    /**
     * Resolve . and .. segments in a path relative to the package root.
     *
     * @param string $path
     * @return string|null The normalised path, or null if it points outside the package.
     */
    public static function normalise_path(string $path): ?string {
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if (!$parts) {
                    return null;
                }
                array_pop($parts);
                continue;
            }
            $parts[] = self::stored_name($segment);
        }
        return implode('/', $parts);
    }

    /**
     * The name Moodle gives a file or folder when it unpacks a package.
     *
     * Moodle removes some characters from file names, such as apostrophes: "sam's video.mp4" is stored as
     * "sams video.mp4". It cleans the names in requests for package files the same way, so learners still get
     * the file; a link has to be compared with the stored name.
     *
     * @param string $name
     * @return string
     */
    public static function stored_name(string $name): string {
        $cleaned = clean_param($name, PARAM_FILE);
        return $cleaned === '' ? $name : $cleaned;
    }

    /**
     * Whether a package file matches one of the ignored path patterns.
     *
     * @param string $path
     * @return bool
     */
    protected function is_ignored_path(string $path): bool {
        $lower = \core_text::strtolower($path);
        foreach ($this->ignoredpaths as $pattern) {
            if (fnmatch($pattern, $lower, FNM_NOESCAPE) || fnmatch($pattern, basename($lower), FNM_NOESCAPE)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Find the folder of the first launchable resource in imsmanifest.xml.
     *
     * @param \stored_file[] $files
     * @return string
     */
    protected function find_launch_dir(array $files): string {
        foreach ($files as $file) {
            if ($file->get_filepath() === '/' && strtolower($file->get_filename()) === 'imsmanifest.xml') {
                if (
                    preg_match(
                        '~<(?:[a-z0-9]+:)?resource\b[^>]*\shref\s*=\s*["\']([^"\']+)["\']~i',
                        $file->get_content(),
                        $match
                    )
                ) {
                    $dir = dirname(preg_replace('~[?#].*$~', '', $match[1]));
                    return $dir === '.' ? '' : $dir;
                }
            }
        }
        return '';
    }
}
