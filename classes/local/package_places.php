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
 * Finds the Storyline or Captivate slide a link in a SCORM package is on.
 *
 * Storyline keeps each slide in its own file (html5/data/js/<slide id>.js), listed with its scene in
 * html5/data/js/data.js, and the player's menu in html5/data/js/frame.js. A slide's layers are in its
 * file; Storyline does not publish their names, so a layer is named by its number and first text.
 * Captivate keeps all slides in assets/js/CPM.js; each web object has its own folder (wor/wo_1/), used
 * by one or more slides. Rise lessons are found by link_extractor instead.
 *
 * Places are arrays stored with the link, see report_helper::describe_place():
 * ['tool' => 'storyline', 'scene', 'slide', 'slidetitle', 'slideid' => '<scene id>.<slide id>', 'menu' => titles
 * from the menu down to the slide, 'launch', and for a link on a layer 'layer' => number, 'layerid', 'layertext'] or
 * ['tool' => 'captivate', 'slide', 'slidetitle', 'others' => other slides, 'everyslide' => bool, 'launch'].
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class package_places {
    /** @var array Storyline slide file path => place. */
    protected $storyline = [];

    /** @var \stored_file[] Storyline slide file path => file, read when a link is found in it. */
    protected $storylinefiles = [];

    /** @var array Storyline slide file path => [layer number => [id, text, JSON]], for the slides read so far. */
    protected $storylinelayers = [];

    /** @var array Captivate projects: ['root', 'cpm', 'launch', 'slides' => [['number', 'title', 'json', 'master']], 'webobjects'] */
    protected $captivate = [];

    /**
     * Read the slides of the Storyline and Captivate projects in a package.
     *
     * @param \stored_file[] $files Path relative to the package root => file.
     * @return self
     */
    public static function from_files(array $files): self {
        $places = new self();
        foreach ($files as $path => $file) {
            if ($path === 'html5/data/js/data.js' || str_ends_with($path, '/html5/data/js/data.js')) {
                $places->read_storyline(substr($path, 0, -strlen('html5/data/js/data.js')), $file->get_content(), $files);
            } else if ($path === 'assets/js/CPM.js' || str_ends_with($path, '/assets/js/CPM.js')) {
                $places->read_captivate(substr($path, 0, -strlen('assets/js/CPM.js')), $file->get_content(), $files);
            }
        }
        return $places;
    }

    /**
     * The slide a link is on.
     *
     * @param string $path Path of the file the link is in, relative to the package root.
     * @param string $url The link, or the media path, as found in the file.
     * @return array|null The place, or null if the file is not part of a slide.
     */
    public function find(string $path, string $url): ?array {
        if (isset($this->storyline[$path])) {
            return $this->storyline[$path] + $this->storyline_layer($path, $url);
        }
        foreach ($this->captivate as $project) {
            if ($path === $project['cpm']) {
                $slides = [];
                foreach ($project['slides'] as $slide) {
                    if (str_contains($slide['json'], $url)) {
                        $slides[] = $slide;
                    }
                }
                return self::captivate_place($project, $slides);
            }
            foreach ($project['webobjects'] as $folder => $slides) {
                if (str_starts_with($path, $folder)) {
                    return self::captivate_place($project, $slides);
                }
            }
        }
        return null;
    }

    /**
     * The place of a Captivate slide, by its number.
     *
     * @param string $path Path of CPM.js, relative to the package root.
     * @param int $number
     * @return array|null
     */
    public function captivate_slide(string $path, int $number): ?array {
        foreach ($this->captivate as $project) {
            if ($path === $project['cpm'] && isset($project['slides'][$number - 1])) {
                return self::captivate_place($project, [$project['slides'][$number - 1]]);
            }
        }
        return null;
    }

    /**
     * Read Storyline's list of scenes and slides.
     *
     * @param string $root Folder of the Storyline project, '' or ending with '/'.
     * @param string $js Contents of html5/data/js/data.js.
     * @param \stored_file[] $files
     */
    protected function read_storyline(string $root, string $js, array $files): void {
        $data = self::storyline_data($js);
        if (!$data || empty($data['scenes'])) {
            return;
        }
        $launch = '';
        foreach (['story.html', 'story_html5.html', 'index_lms.html', 'index_lms_html5.html'] as $page) {
            if (isset($files[$root . $page])) {
                $launch = $root . $page;
                break;
            }
        }
        // The menu learners see, which authors arrange into their own sections: '_player.<scene>.<slide>' => titles.
        $menu = [];
        $frame = isset($files[$root . 'html5/data/js/frame.js'])
            ? self::storyline_data($files[$root . 'html5/data/js/frame.js']->get_content()) : null;
        self::read_menu($frame['navData'] ?? [], [], $menu);

        foreach ((array)$data['scenes'] as $index => $scene) {
            if (!is_array($scene) || !empty($scene['isMessageScene']) || empty($scene['slides'])) {
                continue;
            }
            $number = (int)($scene['sceneNumber'] ?? 0) ?: $index + 1;
            foreach (array_values((array)$scene['slides']) as $slideindex => $slide) {
                if (!is_array($slide) || !is_string($slide['html5url'] ?? null)) {
                    continue;
                }
                $id = (string)($scene['id'] ?? '') . '.' . (string)($slide['id'] ?? '');
                $path = $root . $slide['html5url'];
                $this->storyline[$path] = [
                    'tool' => 'storyline',
                    'scene' => $number,
                    'slide' => $slideindex + 1,
                    'slidetitle' => self::clean_title($slide['title'] ?? ''),
                    'slideid' => preg_match('~^\w+\.\w+$~', $id) ? $id : '',
                    'menu' => $menu['_player.' . $id] ?? [],
                    'launch' => $launch,
                ];
                if (isset($files[$path])) {
                    $this->storylinefiles[$path] = $files[$path];
                }
            }
        }
    }

    /**
     * Read Storyline's menu into the titles leading to each slide.
     *
     * @param mixed $entries navData, or the children of a menu entry.
     * @param string[] $titles Titles of the entries above.
     * @param array $menu Receives '_player.<scene id>.<slide id>' => titles.
     */
    protected static function read_menu($entries, array $titles, array &$menu): void {
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            if (($entry['kind'] ?? '') !== 'slidelink') {
                self::read_menu($entry, $titles, $menu);
                continue;
            }
            $path = $titles;
            $title = self::clean_title($entry['displaytext'] ?? '');
            if ($title !== '') {
                $path[] = $title;
            }
            if (is_string($entry['slideid'] ?? null) && !isset($menu[$entry['slideid']])) {
                $menu[$entry['slideid']] = $path;
            }
            self::read_menu($entry['links'] ?? [], $path, $menu);
        }
    }

    /**
     * The layer of a Storyline slide a link is on, if it is not on the base layer.
     *
     * @param string $path Path of the slide file.
     * @param string $url
     * @return array ['layer', 'layerid', 'layertext'], or [] for the base layer.
     */
    protected function storyline_layer(string $path, string $url): array {
        if (!isset($this->storylinelayers[$path])) {
            $this->storylinelayers[$path] = [];
            $slide = isset($this->storylinefiles[$path]) ? self::storyline_data($this->storylinefiles[$path]->get_content()) : null;
            foreach ((array)($slide['slideLayers'] ?? []) as $number => $layer) {
                if ($number === 0 || !is_array($layer) || !empty($layer['isBaseLayer'])) {
                    continue;
                }
                $this->storylinelayers[$path][$number] = [
                    'id' => preg_match('~^\w+$~', (string)($layer['id'] ?? '')) ? (string)$layer['id'] : '',
                    'text' => self::first_text($layer['objects'] ?? []),
                    'json' => json_encode($layer, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ];
            }
        }
        $escaped = substr(json_encode($url, JSON_UNESCAPED_UNICODE), 1, -1);
        foreach ($this->storylinelayers[$path] as $number => $layer) {
            if (str_contains($layer['json'], $url) || str_contains($layer['json'], $escaped)) {
                return ['layer' => $number, 'layerid' => $layer['id'], 'layertext' => $layer['text']];
            }
        }
        return [];
    }

    /**
     * A heading for a Storyline layer: its first short text, or else the start of its first text.
     *
     * Default names of shapes, such as "Rectangle 1", and addresses are left out.
     *
     * @param mixed $objects The layer's objects.
     * @return string
     */
    protected static function first_text($objects): string {
        $texts = [];
        self::collect_texts($objects, $texts);
        foreach ($texts as $text) {
            if (\core_text::strlen($text) <= 60) {
                return $text;
            }
        }
        if (!$texts) {
            return '';
        }
        return rtrim(\core_text::substr($texts[0], 0, 59)) . '…';
    }

    /**
     * Collect the texts of Storyline objects in order, with groups' objects in place.
     *
     * Storyline keeps an object's text as its accessible text: altText, or data.vectorData.altText in
     * newer versions.
     *
     * @param mixed $objects
     * @param string[] $texts
     */
    protected static function collect_texts($objects, array &$texts): void {
        if (!is_array($objects)) {
            return;
        }
        foreach ($objects as $object) {
            if (!is_array($object)) {
                continue;
            }
            $text = self::clean_title($object['data']['vectorData']['altText'] ?? $object['altText'] ?? '');
            // Default names end in a number; a heading has at least two words.
            if (preg_match('~\S\s+\S~u', $text) && !preg_match('~(^|\s)\d+$~', $text) && !preg_match('~^https?://~i', $text)) {
                $texts[] = $text;
            }
            self::collect_texts($object['objects'] ?? [], $texts);
        }
    }

    /**
     * Read the JSON that Storyline passes to globalProvideData() as a JavaScript string.
     *
     * @param string $js
     * @return array|null
     */
    public static function storyline_data(string $js): ?array {
        if (!preg_match("~globalProvideData\\(\\s*'\\w+'\\s*,\\s*'~", $js, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $start = $match[0][1] + strlen($match[0][0]);
        $end = strrpos($js, "'");
        if ($end === false || $end <= $start) {
            return null;
        }
        // Undo the JavaScript string escapes; the JSON's own escapes stay.
        $json = preg_replace_callback(
            '~\\\\(.)~s',
            fn($m) => in_array($m[1], ["'", '\\'], true) ? $m[1] : $m[0],
            substr($js, $start, $end - $start)
        );
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Read the slides of a Captivate project.
     *
     * @param string $root Folder of the Captivate project, '' or ending with '/'.
     * @param string $js Contents of assets/js/CPM.js.
     * @param \stored_file[] $files
     */
    protected function read_captivate(string $root, string $js, array $files): void {
        $data = js_literal_parser::parse_assignment($js, 'cp.D = cp.model.data =')
            ?? js_literal_parser::parse_assignment($js, 'cp.model.data =');
        if (!$data || empty($data['project_main']['slides'])) {
            return;
        }
        $launch = '';
        if (isset($files[$root . 'project.txt'])) {
            $project = json_decode($files[$root . 'project.txt']->get_content(), true);
            $page = is_array($project) ? (string)($project['metadata']['launchFile'] ?? '') : '';
            if ($page !== '' && isset($files[$root . $page])) {
                $launch = $root . $page;
            }
        }
        foreach (['index_scorm.html', 'index.html', 'multiscreen.html'] as $page) {
            if ($launch === '' && isset($files[$root . $page])) {
                $launch = $root . $page;
            }
        }

        $slides = [];
        $webobjects = [];
        $names = array_values(array_filter(explode(',', (string)$data['project_main']['slides'])));
        foreach ($names as $index => $name) {
            $slide = is_array($data[$name] ?? null) ? $data[$name] : [];
            $entry = ['number' => $index + 1, 'title' => self::clean_title($slide['lb'] ?? ''), 'master' => false];
            $parts = [$slide];
            $own = false;
            foreach ((array)($slide['si'] ?? []) as $ref) {
                $item = is_array($ref) ? ($data[(string)($ref['n'] ?? '')] ?? null) : null;
                if (!is_array($item)) {
                    continue;
                }
                $companion = $data[$item['mdi'] ?? ''] ?? [];
                $parts[] = $item;
                $parts[] = $companion;
                if (is_array($companion) && is_string($companion['wou'] ?? null)) {
                    $folder = $root . dirname($companion['wou']) . '/';
                    $webobjects[$folder][] = ['master' => !empty($item['immo'])] + $entry;
                }
                $own = $own || empty($item['immo']);
            }
            $entry['json'] = json_encode($parts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $slides[] = $entry;
        }
        $this->captivate[] = [
            'cpm' => $root . 'assets/js/CPM.js',
            'launch' => $launch,
            'slides' => $slides,
            'webobjects' => $webobjects,
        ];
    }

    /**
     * The place of a link found on some slides of a Captivate project.
     *
     * Items on the master slide, or copied onto at least half of the slides (and at least three),
     * are on every slide, like captivate_project::get_slides() lists them.
     *
     * @param array $project
     * @param array $slides The slides the link is on.
     * @return array|null
     */
    protected static function captivate_place(array $project, array $slides): ?array {
        if (!$slides) {
            return null;
        }
        $threshold = max(3, (int)ceil(count($project['slides']) / 2));
        $everyslide = count($slides) >= $threshold || !empty(array_filter(array_column($slides, 'master')));
        return [
            'tool' => 'captivate',
            'slide' => $everyslide ? 1 : $slides[0]['number'],
            'slidetitle' => $everyslide ? '' : $slides[0]['title'],
            'others' => $everyslide ? 0 : count($slides) - 1,
            'everyslide' => $everyslide,
            'launch' => $project['launch'],
        ];
    }

    /**
     * A slide title as plain text; Storyline titles may be HTML.
     *
     * @param mixed $title
     * @return string
     */
    protected static function clean_title($title): string {
        $text = html_entity_decode(strip_tags(is_string($title) ? $title : ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim(preg_replace('~\s+~u', ' ', $text));
    }
}
