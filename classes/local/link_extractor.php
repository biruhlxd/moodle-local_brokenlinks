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
 * Finds links in HTML, CSS, JavaScript and the data files written by e-learning authoring tools.
 *
 * Every extract method returns a list of ['url' => string, 'text' => string] arrays. Nothing is
 * resolved or checked here; that is the job of the scanners.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class link_extractor {
    /** @var array Element name => attributes that hold a link. */
    const HTML_ATTRIBUTES = [
        'a' => ['href'],
        'area' => ['href'],
        'img' => ['src'],
        'iframe' => ['src'],
        'frame' => ['src'],
        'embed' => ['src'],
        'object' => ['data'],
        'video' => ['src', 'poster'],
        'audio' => ['src'],
        'source' => ['src'],
        'track' => ['src'],
        'script' => ['src'],
        'link' => ['href'],
    ];

    /** @var string Regex matching an absolute http(s) URL in free text. */
    const URL_REGEX = '~https?://[^\s"\'<>\x60\\\\{}|^\[\]]+~i';

    /** @var int Smallest base64 string worth trying to decode as embedded course data. */
    const MIN_BASE64_LENGTH = 200;

    /**
     * Extract links from an HTML fragment or document.
     *
     * @param string $html
     * @return array
     */
    public function extract_from_html(string $html): array {
        if (trim($html) === '') {
            return [];
        }
        $links = [];
        $doc = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // The XML declaration makes DOMDocument read the input as UTF-8.
        $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        foreach (self::HTML_ATTRIBUTES as $tag => $attributes) {
            foreach ($doc->getElementsByTagName($tag) as $element) {
                foreach ($attributes as $attribute) {
                    $value = trim($element->getAttribute($attribute));
                    if ($value === '') {
                        continue;
                    }
                    $text = $tag === 'a' ? trim(preg_replace('/\s+/u', ' ', $element->textContent)) : '';
                    if ($text === '' && $element->getAttribute('alt') !== '') {
                        $text = $element->getAttribute('alt');
                    }
                    $links[] = ['url' => $value, 'text' => $text];
                }
                if ($tag === 'img' && $element->getAttribute('srcset') !== '') {
                    foreach (explode(',', $element->getAttribute('srcset')) as $candidate) {
                        $candidate = trim(preg_split('/\s+/', trim($candidate))[0] ?? '');
                        if ($candidate !== '') {
                            $links[] = ['url' => $candidate, 'text' => ''];
                        }
                    }
                }
            }
        }

        // Inline styles and style blocks can reference images and fonts.
        $xpath = new \DOMXPath($doc);
        foreach ($xpath->query('//*[@style]') as $element) {
            $links = array_merge($links, $this->extract_from_css($element->getAttribute('style')));
        }
        foreach ($doc->getElementsByTagName('style') as $element) {
            $links = array_merge($links, $this->extract_from_css($element->textContent));
        }

        // Plain-text URLs that Moodle's URL filter would turn into links.
        foreach ($xpath->query('//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]') as $node) {
            foreach ($this->extract_absolute_urls($node->nodeValue) as $url) {
                $links[] = ['url' => $url, 'text' => ''];
            }
        }

        return $links;
    }

    /**
     * Extract url() and @import references from CSS.
     *
     * @param string $css
     * @return array
     */
    public function extract_from_css(string $css): array {
        $links = [];
        preg_match_all('~url\(\s*([\'"]?)([^\'")]+)\1\s*\)~i', $css, $matches);
        foreach ($matches[2] as $url) {
            $links[] = ['url' => trim($url), 'text' => ''];
        }
        preg_match_all('~@import\s+([\'"])([^\'"]+)\1~i', $css, $matches);
        foreach ($matches[2] as $url) {
            $links[] = ['url' => trim($url), 'text' => ''];
        }
        return $links;
    }

    /**
     * Extract links from JavaScript, including Storyline slide data and encoded Rise course data.
     *
     * Absolute URLs are taken from anywhere in the script. Relative references are only taken from
     * JSON-style "url", "href" and "src" properties, because other strings in scripts are rarely file paths.
     *
     * @param string $js
     * @return array
     */
    public function extract_from_js(string $js): array {
        $links = [];
        foreach ($this->decode_embedded_data($js) as $data) {
            $links = array_merge($links, $this->extract_from_data($data));
        }

        // JSON inside JavaScript strings escapes slashes, e.g. https:\/\/example.com.
        $plain = str_replace(['\\\\/', '\\/', '\\u002F', '\\u002f'], '/', $js);
        foreach ($this->extract_absolute_urls($plain) as $url) {
            $links[] = ['url' => $url, 'text' => ''];
        }
        preg_match_all('~["\'](?:url|href|src)["\']\s*:\s*["\']([^"\'\\\\]+)["\']~i', $plain, $matches);
        foreach ($matches[1] as $value) {
            if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $value) && !str_starts_with($value, '//')) {
                $links[] = ['url' => $value, 'text' => ''];
            }
        }
        return $links;
    }

    /**
     * Whether the script holds encoded course data that could not be decoded.
     *
     * Rise packages keep all lesson content in one base64 string passed to deserialize(). If that
     * string is present but does not decode, the scan of the package is incomplete.
     *
     * @param string $js
     * @return bool
     */
    public function has_undecodable_data(string $js): bool {
        if (!preg_match('~deserialize\(\s*["\']([^"\']+)["\']\s*\)~', $js, $match)) {
            return false;
        }
        return $this->decode_base64_json($match[1]) === null;
    }

    /**
     * Find absolute http(s) URLs in free text.
     *
     * @param string $text
     * @return string[]
     */
    public function extract_absolute_urls(string $text): array {
        preg_match_all(self::URL_REGEX, $text, $matches);
        $urls = [];
        foreach ($matches[0] as $url) {
            // Sentence punctuation and closing brackets are rarely part of the URL.
            $url = rtrim($url, '.,;:!?)\'"');
            if (preg_match('~^https?://[^/]*[a-z0-9]~i', $url)) {
                $urls[] = $url;
            }
        }
        return $urls;
    }

    /**
     * Find JSON data that is base64 encoded inside a script, as Rise does.
     *
     * @param string $js
     * @return array Decoded JSON values.
     */
    protected function decode_embedded_data(string $js): array {
        $found = [];
        $pattern = '~["\']([A-Za-z0-9+/]{' . self::MIN_BASE64_LENGTH . ',}={0,2})["\']~';
        if (preg_match_all($pattern, $js, $matches)) {
            foreach ($matches[1] as $candidate) {
                $data = $this->decode_base64_json($candidate);
                if ($data !== null) {
                    $found[] = $data;
                }
            }
        }
        return $found;
    }

    /**
     * Decode a base64 string holding JSON.
     *
     * @param string $candidate
     * @return mixed|null The decoded JSON, or null if it is not base64 JSON.
     */
    protected function decode_base64_json(string $candidate) {
        $decoded = base64_decode($candidate, true);
        if ($decoded === false || !mb_check_encoding($decoded, 'UTF-8')) {
            return null;
        }
        $first = ltrim($decoded)[0] ?? '';
        if ($first !== '{' && $first !== '[') {
            return null;
        }
        $data = json_decode($decoded, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Walk decoded JSON and extract links from every string in it.
     *
     * @param mixed $data
     * @return array
     */
    protected function extract_from_data($data): array {
        $links = [];
        $strings = [];
        array_walk_recursive($data, function ($value) use (&$strings) {
            if (is_string($value) && $value !== '') {
                $strings[] = $value;
            }
        });
        foreach ($strings as $value) {
            if (str_contains($value, '<') && str_contains($value, '>')) {
                $links = array_merge($links, $this->extract_from_html($value));
            } else {
                foreach ($this->extract_absolute_urls($value) as $url) {
                    $links[] = ['url' => $url, 'text' => ''];
                }
            }
        }
        return $links;
    }
}
