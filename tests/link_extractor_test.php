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

namespace local_brokenlinks;

use local_brokenlinks\local\link_extractor;

/**
 * Tests for the link extractor.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(link_extractor::class)]
final class link_extractor_test extends \basic_testcase {
    /**
     * Just the URLs from a list of extracted links.
     *
     * @param array $links
     * @return string[]
     */
    private function urls(array $links): array {
        return array_values(array_unique(array_column($links, 'url')));
    }

    public function test_html_links_and_embeds(): void {
        $html = '<p>See <a href="https://one.test/a">the <b>first</b> page</a>,
            <a href=\'https://two.test/b\'>second</a> and <a href=https://three.test/c>third</a>.</p>
            <img src="@@PLUGINFILE@@/pic.png" alt="A picture" srcset="small.png 1x, large.png 2x">
            <iframe src="https://video.test/embed/1"></iframe>
            <div style="background: url(\'bg.jpg\')">Visit https://plain.test/page. Thanks</div>
            <a href="#top">Top</a>';
        $links = (new link_extractor())->extract_from_html($html);
        $urls = $this->urls($links);

        foreach (
            ['https://one.test/a', 'https://two.test/b', 'https://three.test/c', '@@PLUGINFILE@@/pic.png',
                'small.png', 'large.png', 'https://video.test/embed/1', 'bg.jpg', 'https://plain.test/page', '#top'] as $url
        ) {
            $this->assertContains($url, $urls);
        }
        $first = array_values(array_filter($links, fn($l) => $l['url'] === 'https://one.test/a'))[0];
        $this->assertSame('the first page', $first['text']);
        $picture = array_values(array_filter($links, fn($l) => $l['url'] === '@@PLUGINFILE@@/pic.png'))[0];
        $this->assertSame('A picture', $picture['text']);
    }

    public function test_css(): void {
        $css = '@import "theme.css"; .a { background: url(img/a.png) } .b { src: url("../fonts/f.woff2") }';
        $urls = $this->urls((new link_extractor())->extract_from_css($css));
        $this->assertEqualsCanonicalizing(['img/a.png', '../fonts/f.woff2', 'theme.css'], $urls);
    }

    public function test_storyline_slide_data(): void {
        // Storyline writes slide data as JSON inside a JavaScript string, often with escaped slashes.
        $js = "window.globalProvideData('slide', '{\"events\":[{\"kind\":\"open_url\"," .
            "\"url\":\"https:\\/\\/courses.test\\/mod\\/scorm\\/view.php?id=12\",\"window\":\"_blank\"}," .
            "{\"kind\":\"open_url\",\"url\":\"story_content/external_files/handout.pdf\"}," .
            "{\"kind\":\"show\",\"url\":\"_player.6YsXLn6DGu7\"}]}');";
        $urls = $this->urls((new link_extractor())->extract_from_js($js));
        $this->assertContains('https://courses.test/mod/scorm/view.php?id=12', $urls);
        $this->assertContains('story_content/external_files/handout.pdf', $urls);
    }

    public function test_rise_encoded_course_data(): void {
        $data = ['course' => ['lessons' => [['items' => [
            ['paragraph' => '<p>Read <a href="https://www.cdc.test/report.pdf">the report</a></p>'],
            ['media' => ['url' => 'https://youtu.test/abc']],
        ]]]]];
        $encoded = base64_encode(json_encode($data) . str_repeat(' ', 200));
        $html = '<html><body><script>window.courseData = deserialize("' . $encoded . '");</script></body></html>';

        $extractor = new link_extractor();
        $urls = $this->urls($extractor->extract_from_js($html));
        $this->assertContains('https://www.cdc.test/report.pdf', $urls);
        $this->assertContains('https://youtu.test/abc', $urls);
        $this->assertFalse($extractor->has_undecodable_data($html));

        $broken = '<script>deserialize("' . str_repeat('A', 300) . '!!");</script>';
        $this->assertTrue($extractor->has_undecodable_data($broken));
    }

    public function test_absolute_urls_trim_punctuation(): void {
        $urls = (new link_extractor())->extract_absolute_urls('Go to https://a.test/x. Or (https://b.test/y), "https://c.test/z"');
        $this->assertSame(['https://a.test/x', 'https://b.test/y', 'https://c.test/z'], $urls);
    }
}
