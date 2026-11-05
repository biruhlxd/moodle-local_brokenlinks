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

use local_brokenlinks\local\scan_manager;
use local_brokenlinks\local\url_checker;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/fake_url_checker.php');

/**
 * Tests for scanning courses and SCORM packages.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_brokenlinks\local\course_scanner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_brokenlinks\local\scorm_scanner::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_brokenlinks\local\report_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(scan_manager::class)]
final class course_scanner_test extends \advanced_testcase {
    /**
     * Run a scan of a course with the fake checker.
     *
     * @param int $courseid
     * @param fake_url_checker $checker
     * @return array [scan record, problems keyed by URL]
     */
    private function scan(int $courseid, fake_url_checker $checker): array {
        global $DB;
        $scan = scan_manager::run(scan_manager::queue($courseid, false), $checker);
        $this->assertEquals(scan_manager::STATUS_FINISHED, $scan->status, (string)$scan->errormessage);
        $problems = [];
        foreach ($DB->get_records('local_brokenlinks_link', ['scanid' => $scan->id]) as $link) {
            $problems[$link->url] = $link;
        }
        return [$scan, $problems];
    }

    /**
     * Add a file to the extracted content of a SCORM activity.
     *
     * @param \stdClass $scorm
     * @param string $path
     * @param string $content
     */
    private function add_package_file(\stdClass $scorm, string $path, string $content): void {
        $context = \context_module::instance($scorm->cmid);
        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_scorm',
            'filearea' => 'content',
            'itemid' => 0,
            'filepath' => '/' . (dirname($path) === '.' ? '' : dirname($path) . '/'),
            'filename' => basename($path),
        ], $content);
    }

    public function test_course_content(): void {
        global $CFG, $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' => '<a href="https://broken.test/summary">Old site</a>']);
        $target = $generator->create_module('forum', ['course' => $course->id]);
        $deleted = $generator->create_module('forum', ['course' => $course->id]);
        $deletedcmid = $deleted->cmid;
        // An activity in the recycle bin is still in the table, flagged for deletion.
        $DB->set_field('course_modules', 'deletioninprogress', 1, ['id' => $deletedcmid]);
        rebuild_course_cache($course->id, true);

        $page = $generator->create_module('page', [
            'course' => $course->id,
            'intro' => '<a href="https://works.test/">Fine</a>',
            'content' => '<p><a href="https://broken.test/page">Gone</a> <a href="https://slow.test/">Slow</a>
                <img src="@@PLUGINFILE@@/missing%20image.png" alt="Missing">
                <a href="' . $CFG->wwwroot . '/mod/forum/view.php?id=' . $target->cmid . '">Forum</a>
                <a href="' . $CFG->wwwroot . '/mod/forum/view.php?id=' . $deletedcmid . '">Deleted forum</a>
                <a href="mailto:someone@example.com">Mail</a> <a href="https://www.w3.org/TR/html">Spec</a></p>',
        ]);
        $url = $generator->create_module('url', ['course' => $course->id, 'externalurl' => 'https://broken.test/url']);
        $generator->create_module('resource', ['course' => $course->id]);

        $checker = new fake_url_checker();
        [$scan, $problems] = $this->scan($course->id, $checker);

        $this->assertArrayHasKey('https://broken.test/summary', $problems);
        $this->assertSame('course', $problems['https://broken.test/summary']->sourcetype);

        $this->assertArrayHasKey('https://broken.test/page', $problems);
        $this->assertEquals($page->cmid, $problems['https://broken.test/page']->cmid);
        $this->assertSame('content', $problems['https://broken.test/page']->location);
        $this->assertEquals(404, $problems['https://broken.test/page']->httpstatus);

        $this->assertEquals(url_checker::WARNING, $problems['https://slow.test/']->severity);
        $this->assertArrayHasKey('missing image.png', $problems);
        $this->assertSame('file', $problems['missing image.png']->linktype);

        $deletedurl = $CFG->wwwroot . '/mod/forum/view.php?id=' . $deletedcmid;
        $this->assertArrayHasKey($deletedurl, $problems);
        $this->assertSame('moodle', $problems[$deletedurl]->linktype);
        $this->assertArrayNotHasKey($CFG->wwwroot . '/mod/forum/view.php?id=' . $target->cmid, $problems);

        $this->assertEquals($url->cmid, $problems['https://broken.test/url']->cmid);

        // The generated file resource has a file, so it is not reported.
        $this->assertArrayNotHasKey('', $problems);

        // Ignored hosts, mail links and links to this site are never requested.
        $this->assertNotContains('https://www.w3.org/TR/html', $checker->requests);
        $this->assertEmpty(array_filter($checker->requests, fn($u) => str_starts_with($u, $CFG->wwwroot)));

        $this->assertEquals(5, $scan->brokencount);
        $this->assertEquals(1, $scan->warningcount);
        $this->assertCount(6, $problems);
    }

    public function test_scorm_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $scorm = $generator->create_module('scorm', ['course' => $course->id]);

        // A Storyline-style package.
        $this->add_package_file(
            $scorm,
            'story.html',
            '<html><head><script src="story_content/missing.js"></script><link href="html5/data/css/output.min.css"
            rel="stylesheet"></head><body><a href="https://works.test/">ok</a><img src="Images/Logo.PNG"></body></html>'
        );
        $this->add_package_file($scorm, 'images/logo.png', 'png');
        $this->add_package_file($scorm, 'html5/data/css/output.min.css', '.a{background:url(../../../story_content/bg.jpg)}');
        $this->add_package_file($scorm, 'story_content/bg.jpg', 'jpg');
        $this->add_package_file(
            $scorm,
            'html5/data/js/slide1.js',
            "window.globalProvideData('slide', '{\"actions\":[{\"kind\":\"open_url\"," .
            "\"url\":\"https:\\/\\/broken.test\\/storyline\"},{\"kind\":\"open_url\"," .
            "\"url\":\"story_content/external_files/handout.pdf\"},{\"kind\":\"open_url\"," .
            "\"url\":\"story_content/bg.jpg\"}]}');"
        );
        // Player libraries are ignored by default, however many links they contain.
        $this->add_package_file($scorm, 'lms/scormdriver.js', 'var docs = "https://broken.test/library";');
        $this->add_package_file($scorm, 'html5/lib/scripts/bootstrapper.min.js', 'x="https://broken.test/library2"');

        // A Rise-style package keeps its lessons in encoded JSON.
        $data = base64_encode(json_encode(['course' => ['lessons' => [
            ['items' => [['paragraph' => '<p><a href="https://broken.test/rise">Study</a></p>']]],
            ['items' => [['paragraph' => '<p>' . str_repeat('Filler text. ', 30) . '</p>']]],
        ]]]));
        $this->add_package_file(
            $scorm,
            'scormcontent/index.html',
            '<html><body><script>window.courseData = deserialize("' . $data . '");</script></body></html>'
        );
        $this->add_package_file($scorm, 'old/intro.swf', 'swf');

        $checker = new fake_url_checker();
        [$scan, $problems] = $this->scan($course->id, $checker);

        $this->assertArrayHasKey('story_content/missing.js', $problems);
        $this->assertSame('story.html', $problems['story_content/missing.js']->location);
        $this->assertSame('package', $problems['story_content/missing.js']->linktype);
        $this->assertEquals($scorm->cmid, $problems['story_content/missing.js']->cmid);

        $this->assertArrayHasKey('Images/Logo.PNG', $problems);
        $this->assertStringContainsString('images/logo.png', $problems['Images/Logo.PNG']->message);

        $this->assertArrayHasKey('https://broken.test/storyline', $problems);
        $this->assertSame('html5/data/js/slide1.js', $problems['https://broken.test/storyline']->location);
        $this->assertArrayHasKey('story_content/external_files/handout.pdf', $problems);
        $this->assertArrayHasKey('https://broken.test/rise', $problems);
        $this->assertSame('scormcontent/index.html', $problems['https://broken.test/rise']->location);

        $notices = array_filter($problems, fn($p) => $p->linktype === 'notice');
        $this->assertCount(1, $notices);
        $this->assertEquals(url_checker::WARNING, reset($notices)->severity);

        $this->assertNotContains('https://broken.test/library', $checker->requests);
        $this->assertNotContains('https://broken.test/library2', $checker->requests);
        $this->assertContains('https://works.test/', $checker->requests);

        // Nothing else: the files of the standard test package and the links that resolve are fine.
        $this->assertCount(6, $problems, implode("\n", array_keys($problems)));
    }

    public function test_captivate_package(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        // Laid out like an Adobe Captivate 2019 HTML5 package: slide data in assets/js/CPM.js,
        // images in dr/ (some bundled into dr/img1.json), audio in ar/, web objects in wor/.
        $this->add_package_file($scorm, 'index_scorm.html', '<html><body><div id="project"></div></body></html>');
        $this->add_package_file($scorm, 'assets/js/CPM.js', "cp.D=cp.model.data={" .
            "Image_1:{ip:'dr/present.png'},Image_2:{ip:'dr/bundled.png'},Image_3:{ip:'dr/missing.png'}," .
            "Image_4:{ip:'dr/orphan.png'},Web_1:{wou:'wor/wo_1/index.html'},Web_2:{wou:'wor/wo_2/index.html'}," .
            "Audio_1:{src:'ar/Click.mp3'},Button_1:{oca:'cp.openURL(\\\"https://broken.test/captivate\\\",\\\"_blank\\\");'}};" .
            // Player code refers to an optional sound that is only published when the project uses it.
            'a.movie.playKeyTap&&(this.keyTap=new a.AudioObject(this,"pkt","ar/KeyClick.mp3",-1,-1,void 0));');
        $this->add_package_file($scorm, 'dr/present.png', 'png');
        $this->add_package_file($scorm, 'dr/imgmd.json', 'cp.imagesJSONCacheForLocal=true;cp.imageToJSONPathMap={' .
            '"dr/bundled.png": "img1.json", "dr/orphan.png": "img9.json"}');
        $this->add_package_file($scorm, 'dr/img1.json', 'cp.imagesJSONCache001={"dr/bundled.png": "iVBORw0KGgo="}');
        $this->add_package_file($scorm, 'wor/wo_1/index.html', '<a href="https://broken.test/webobject">Resource</a>');
        $this->add_package_file($scorm, 'ar/Click.mp3', 'mp3');
        // Captivate's SCORM wrapper is a player file and is not scanned.
        $this->add_package_file($scorm, 'SCORM_utilities.js', 'var flash = "https://broken.test/driver";');

        $checker = new fake_url_checker();
        [, $problems] = $this->scan($course->id, $checker);

        $this->assertArrayHasKey('dr/missing.png', $problems);
        $this->assertSame('assets/js/CPM.js', $problems['dr/missing.png']->location);
        $this->assertSame(get_string('error_scormmissing', 'local_brokenlinks'), $problems['dr/missing.png']->message);
        $this->assertArrayHasKey('dr/orphan.png', $problems);
        $this->assertStringContainsString('dr/img9.json', $problems['dr/orphan.png']->message);
        $this->assertArrayHasKey('wor/wo_2/index.html', $problems);
        $this->assertArrayHasKey('https://broken.test/captivate', $problems);
        $this->assertArrayHasKey('https://broken.test/webobject', $problems);
        $this->assertSame('wor/wo_1/index.html', $problems['https://broken.test/webobject']->location);
        $this->assertNotContains('https://broken.test/driver', $checker->requests);

        // The separate image, the bundled image, the web object and the audio that exist are not reported.
        $this->assertCount(5, $problems, implode("\n", array_keys($problems)));
    }

    public function test_other_plugins_can_check_packages(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);
        $this->add_package_file($scorm, 'assets/js/CPM.js', 'cp.D={};');

        $seen = [];
        $this->redirectHook(
            \local_brokenlinks\hook\scorm_package_scanned::class,
            function (\local_brokenlinks\hook\scorm_package_scanned $hook) use (&$seen): void {
                $seen[] = $hook->cm->id;
                if (isset($hook->files['assets/js/CPM.js'])) {
                    $hook->scanner->record(
                        $hook->source + ['location' => 'assets/js/CPM.js'],
                        'Slide 1: Image_1',
                        '',
                        'quality',
                        url_checker::WARNING,
                        0,
                        'Covers a web object'
                    );
                }
            }
        );
        [$scan, $problems] = $this->scan($course->id, new fake_url_checker());

        $this->assertSame([(int)$scorm->cmid], array_map('intval', $seen));
        $this->assertSame('quality', $problems['Slide 1: Image_1']->linktype);
        $this->assertEquals($scorm->cmid, $problems['Slide 1: Image_1']->cmid);
        $this->assertEquals(1, $scan->warningcount);
    }

    public function test_rise_names_with_apostrophes(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        // Moodle stores "sam's video.mp4" without the apostrophe when it unpacks the package.
        $this->add_package_file($scorm, 'scormcontent/assets/sams video (2).mp4', 'mp4');
        // Replace the manifest of the generator's package.
        $context = \context_module::instance($scorm->cmid);
        get_file_storage()->get_file($context->id, 'mod_scorm', 'content', 0, '/', 'imsmanifest.xml')->delete();
        $this->add_package_file($scorm, 'imsmanifest.xml', '<manifest><resources><resource href="scormcontent/index.html">' .
            '<file href="scormcontent/assets/sam%27s%20video%20%282%29.mp4"/>' .
            '<file href="scormcontent/assets/gone%27s.mp4"/></resource></resources></manifest>');
        $data = ['lessons' => [['items' => [['media' => ['video' => [
            'key' => "sam's video (2).mp4",
            'thumbnail' => "https://images.example.test/rise/transcoded-sam's_studio%2520(2).0000000.jpg",
        ]]], ['pad' => str_repeat('x', 300)]]]]];
        $this->add_package_file(
            $scorm,
            'scormcontent/index.html',
            '<script>window.courseData = deserialize("' . base64_encode(json_encode($data)) . '");</script>'
        );

        $checker = new fake_url_checker();
        [, $problems] = $this->scan($course->id, $checker);

        $this->assertArrayNotHasKey('scormcontent/assets/sam%27s%20video%20%282%29.mp4', $problems);
        $this->assertArrayHasKey('scormcontent/assets/gone%27s.mp4', $problems, 'A file that is really missing');
        $this->assertContains(
            "https://images.example.test/rise/transcoded-sam's_studio%2520(2).0000000.jpg",
            $checker->requests,
            'The whole address is checked, apostrophe included'
        );
    }

    public function test_rise_lesson_and_block(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        // Current Rise packages load their lessons from runtime-data.js. The same link is in two blocks.
        $data = ['course' => ['lessons' => [
            ['id' => 'intro', 'title' => 'Welcome', 'items' => [['id' => 'b1', 'paragraph' => str_repeat('Text. ', 50)]]],
            ['id' => 'abc-1', 'title' => 'Safety equipment', 'items' => [
                ['id' => 'x1', 'paragraph' => '<a href="https://broken.test/rise">Study</a>'],
                ['id' => 'x2', 'paragraph' => 'None'],
                ['id' => 'x 3', 'paragraph' => '<a href="https://broken.test/rise">Again</a>'],
            ]],
        ]]];
        $this->add_package_file($scorm, 'scormcontent/index.html', '<script type="application/json" id="__CONFIG__">' .
            '{"loadOnlyInLms":true}</script><script src="lib/main.js"></script>');
        $this->add_package_file(
            $scorm,
            'scormcontent/runtime-data.js',
            '__jsonp("runtime-data.js","' . base64_encode(json_encode($data)) . '");'
        );

        $scan = $this->scan($course->id, new fake_url_checker())[0];
        $links = array_values($DB->get_records_select('local_brokenlinks_link', 'scanid = ? AND ' .
            $DB->sql_compare_text('url') . ' = ?', [$scan->id, 'https://broken.test/rise'], 'id'));
        $this->assertCount(2, $links, 'Each block is reported');
        $this->assertSame('scormcontent/runtime-data.js', $links[0]->location);
        $this->assertSame(['tool' => 'rise', 'lesson' => 2, 'lessonid' => 'abc-1', 'lessontitle' => 'Safety equipment',
            'block' => 1, 'blockid' => 'x1'], json_decode($links[0]->place, true));

        $this->assertSame(3, json_decode($links[1]->place, true)['block']);
        $this->assertSame('x 3', json_decode($links[1]->place, true)['blockid']);
        // Without an add-on that describes places, the report shows the file only.
        $source = \local_brokenlinks\local\report_helper::describe($links[1], $course, get_fast_modinfo($course));
        $this->assertSame(['', ''], [$source['place'], $source['previewurl']]);
    }

    public function test_player_scripts_are_skipped(): void {
        $scanner = \local_brokenlinks\local\course_scanner::class;
        $this->assertTrue($scanner::is_skipped('https://www.youtube.com/iframe_api'));
        $this->assertTrue($scanner::is_skipped('//www.youtube.com/player_api?v=2'));
        $this->assertFalse($scanner::is_skipped('https://www.youtube.com/watch?v=abc'));
        $this->assertFalse($scanner::is_skipped('https://www.youtube.com/iframe_api_guide'));
    }

    public function test_same_link_is_one_row(): void {
        $row = fn($id, $url, $location, $message = 'Page not found.') => (object)['id' => $id, 'severity' => 2,
            'linktype' => 'external', 'url' => $url, 'message' => $message, 'location' => $location];
        $groups = \local_brokenlinks\local\report_helper::group_links([
            $row(1, 'https://broken.test/a', 'wor/wo_1/index.html'),
            $row(2, 'https://broken.test/b', 'wor/wo_1/index.html'),
            $row(3, 'https://broken.test/a', 'wor/wo_2/index.html'),
            $row(4, 'https://broken.test/a', 'wor/wo_3/index.html', 'Server error.'),
            $row(5, '', '/'),
            $row(6, '', '/'),
        ]);
        $this->assertSame([[1, 3], [2], [4], [5], [6]], array_map(fn($group) => array_column($group, 'id'), $groups));
    }

    public function test_timeouts_are_tried_again(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' =>
            '<a href="https://slow.test/a">A</a> <a href="https://slow.test/b">B</a> <a href="https://slow.test/c">C</a> ' .
            '<a href="https://flaky.test/page">Flaky</a>']);

        $checker = new fake_url_checker();
        [$scan, $problems] = $this->scan($course->id, $checker);

        // The website that was slow for a moment works when it is asked again.
        $this->assertArrayNotHasKey('https://flaky.test/page', $problems);
        $this->assertCount(2, array_keys($checker->requests, 'https://flaky.test/page'));
        // After two time-outs in a row, the website's third link waits; it is tried again only if the
        // website answers, and this one still does not.
        $this->assertCount(3, array_filter(array_keys($problems), fn($url) => str_starts_with($url, 'https://slow.test/')));
        $this->assertCount(2, array_keys($checker->requests, 'https://slow.test/a'));
        $this->assertCount(1, array_keys($checker->requests, 'https://slow.test/b'));
        $this->assertNotContains('https://slow.test/c', $checker->requests);
        $this->assertEquals(3, $DB->get_field('local_brokenlinks_scan', 'warningcount', ['id' => $scan->id]));
        $this->assertEquals(0, $DB->get_field('local_brokenlinks_scan', 'brokencount', ['id' => $scan->id]));
    }

    public function test_storyline_slide(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        // Storyline writes JSON in a JavaScript string, escaping its apostrophes.
        $data = ['scenes' => [
            ['kind' => 'scene', 'id' => 'Sc1', 'sceneNumber' => 2, 'slides' => [
                ['kind' => 'slide', 'id' => 'Intro1', 'title' => 'Welcome', 'html5url' => 'html5/data/js/Intro1.js'],
                ['kind' => 'slide', 'id' => 'Pads2', 'title' => "<p dir='ltr'>Sam's notes</p>",
                    'html5url' => 'html5/data/js/Pads2.js'],
            ]],
            ['kind' => 'scene', 'id' => 'PromptScene', 'isMessageScene' => true, 'slides' => [
                ['kind' => 'slide', 'id' => 'Resume', 'title' => 'Resume'],
            ]],
        ]];
        $js = fn($name, $value) => "window.globalProvideData('$name', '" .
            str_replace("'", "\\'", json_encode($value, JSON_UNESCAPED_SLASHES)) . "');";
        $this->add_package_file($scorm, 'story.html', '<html><head></head></html>');
        $this->add_package_file($scorm, 'html5/data/js/data.js', $js('data', $data));
        // An address typed without http://, which Storyline opens as a website.
        $this->add_package_file($scorm, 'html5/data/js/Intro1.js', $js('slide', ['title' => 'Welcome',
            'slideLayers' => [['objects' => [['kind' => 'open_url', 'url' => 'www.broken.test/typed']]]]]));
        $this->add_package_file($scorm, 'html5/data/js/Pads2.js', $js('slide', ['title' => "Sam's notes",
            'slideLayers' => [['objects' => [['url' => 'https://broken.test/storyline']]]]]));

        $scan = $this->scan($course->id, new fake_url_checker())[0];
        $link = $DB->get_record_select(
            'local_brokenlinks_link',
            'scanid = ? AND ' . $DB->sql_compare_text('url') . ' = ?',
            [$scan->id, 'https://broken.test/storyline'],
            '*',
            MUST_EXIST
        );
        $this->assertSame('html5/data/js/Pads2.js', $link->location);
        $typed = $DB->get_records('local_brokenlinks_link', ['scanid' => $scan->id, 'location' => 'html5/data/js/Intro1.js']);
        $this->assertCount(1, $typed);
        $this->assertSame('http://www.broken.test/typed', reset($typed)->url);
        $this->assertSame('external', reset($typed)->linktype);
        $this->assertSame(['tool' => 'storyline', 'scene' => 2, 'slide' => 2, 'slidetitle' => "Sam's notes",
            'slideid' => 'Sc1.Pads2', 'menu' => [], 'launch' => 'story.html'], json_decode($link->place, true));
    }

    public function test_storyline_menu_and_layer(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        $js = fn($name, $value) => "window.globalProvideData('$name', '" .
            str_replace("'", "\\'", json_encode($value, JSON_UNESCAPED_SLASHES)) . "');";
        $this->add_package_file($scorm, 'story.html', '<html><head></head></html>');
        $this->add_package_file($scorm, 'html5/data/js/data.js', $js('data', ['scenes' => [
            ['kind' => 'scene', 'id' => 'Sc14', 'sceneNumber' => 14, 'slides' => [
                ['kind' => 'slide', 'id' => 'Scen3', 'title' => 'Scenario', 'html5url' => 'html5/data/js/Scen3.js'],
            ]],
        ]]));
        // Authors arrange the menu into their own sections.
        $this->add_package_file($scorm, 'html5/data/js/frame.js', $js('frame', ['navData' => ['outline' => ['links' => [
            ['kind' => 'slidelink', 'slideid' => '_player.Sc14', 'displaytext' => 'Section 3: Online Portal', 'links' => [
                ['kind' => 'slidelink', 'slideid' => '_player.Sc14.Scen3', 'displaytext' => 'Scenario'],
            ]],
        ]]]]));
        // The link is on a layer, whose objects are named by default except for its heading.
        $this->add_package_file($scorm, 'html5/data/js/Scen3.js', $js('slide', ['title' => 'Scenario', 'slideLayers' => [
            ['kind' => 'slidelayer', 'isBaseLayer' => true, 'objects' => [['altText' => 'https://base.test/']]],
            ['kind' => 'slidelayer', 'id' => 'Lay6', 'objects' => [
                ['kind' => 'vectorshape', 'data' => ['vectorData' => ['altText' => 'Rectangle 1']]],
                ['kind' => 'vectorshape', 'data' => ['vectorData' => ['altText' => 'Review the Links List with Alex.']]],
                ['kind' => 'vectorshape', 'data' => ['vectorData' => ['altText' => 'https://broken.test/layer']],
                    'url' => 'https://broken.test/layer'],
            ]],
        ]]));

        $scan = $this->scan($course->id, new fake_url_checker())[0];
        $link = $DB->get_record_select(
            'local_brokenlinks_link',
            'scanid = ? AND ' . $DB->sql_compare_text('url') . ' = ?',
            [$scan->id, 'https://broken.test/layer'],
            '*',
            MUST_EXIST
        );
        $place = json_decode($link->place, true);
        $this->assertSame(['Section 3: Online Portal', 'Scenario'], $place['menu']);
        $layer = [$place['layer'], $place['layerid'], $place['layertext'], $place['slideid']];
        $this->assertSame([1, 'Lay6', 'Review the Links List with Alex.', 'Sc14.Scen3'], $layer);
    }

    public function test_captivate_slides(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();
        $scorm = $this->getDataGenerator()->create_module('scorm', ['course' => $course->id]);

        // Four slides. Slide 2 has a web object and an Open URL button, and slide 3, which has no title,
        // its own copy of a Help panel. Every slide has a Resources panel that uses the same web object.
        $slides = [];
        $items = [];
        foreach ([1 => 'Welcome', 2 => 'Forms', 3 => '', 4 => 'Summary'] as $n => $title) {
            $slides[] = "Slide$n";
            $si = ["{n:'Res$n'}"];
            $items[] = "Res$n:{mdi:'Res{$n}c',type:652},Res{$n}c:{wou:'wor/res/index.html'}";
            if ($n === 3) {
                $si[] = "{n:'Help3'}";
                $items[] = "Help3:{mdi:'Help3c',type:652},Help3c:{wou:'wor/help_3/index.html'}";
            }
            if ($n === 4) {
                // The web object of slide 2 again.
                $si[] = "{n:'Web4'}";
                $items[] = "Web4:{mdi:'Web4c',type:652},Web4c:{wou:'wor/wo_2/index.html'}";
            }
            if ($n === 2) {
                $si[] = "{n:'Web2'}";
                $si[] = "{n:'Button2'}";
                $items[] = "Web2:{mdi:'Web2c',type:652},Web2c:{wou:'wor/wo_2/index.html'}";
                $items[] = "Button2:{mdi:'Button2c',type:177,oca:'cp.openURL(\"https://broken.test/button\",\"_blank\")'}," .
                    "Button2c:{}";
            }
            $items[] = "Slide$n:{lb:'$title',si:[" . implode(',', $si) . "]}";
        }
        $this->add_package_file($scorm, 'index_scorm.html', '<html><head></head></html>');
        $this->add_package_file($scorm, 'project.txt', json_encode(['metadata' => ['launchFile' => 'index_scorm.html']]));
        $this->add_package_file($scorm, 'assets/js/CPM.js', "cp.D = cp.model.data = {project_main:{slides:'" .
            implode(',', $slides) . "'}," . implode(',', $items) . '};');
        $this->add_package_file($scorm, 'wor/wo_2/index.html', '<a href="https://broken.test/webobject">Form</a>');
        $this->add_package_file($scorm, 'wor/res/index.html', '<a href="https://broken.test/resources">Resources</a>');
        $this->add_package_file($scorm, 'wor/help_3/index.html', '<a href="https://broken.test/help">Help</a>');

        $scan = $this->scan($course->id, new fake_url_checker())[0];
        $describe = function (string $url) use ($DB, $scan, $course): array {
            $links = $DB->get_records_select(
                'local_brokenlinks_link',
                'scanid = ? AND ' . $DB->sql_compare_text('url') . ' = ?',
                [$scan->id, $url],
                'location'
            );
            $this->assertNotEmpty($links, $url);
            return array_map(fn($link) => json_decode($link->place, true), array_values($links));
        };

        $webobject = $describe('https://broken.test/webobject')[0];
        $slide = [$webobject['slide'], $webobject['slidetitle'], $webobject['others'], $webobject['launch']];
        $this->assertSame([2, 'Forms', 1, 'index_scorm.html'], $slide);
        $button = $describe('https://broken.test/button')[0];
        $this->assertSame([2, 'Forms'], [$button['slide'], $button['slidetitle']]);
        $this->assertSame(3, $describe('https://broken.test/help')[0]['slide']);
        $resources = $describe('https://broken.test/resources');
        $this->assertCount(1, $resources);
        $this->assertTrue($resources[0]['everyslide']);
    }

    public function test_url_variables_dois_and_wrapped_links(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['summary' =>
            '<a href="https://doi.org/10.1234/article.5">Paper</a> <a href="https://doi.org/10.1234/broken.6">Gone</a> ' .
            '<a href="https://linkprotect.cudasvc.com/url?a=http%3a%2f%2fdoi.org%2f10.1097%2fNMC.370&amp;c=E,1,x">Wrapped</a>']);
        $withvariables = $generator->create_module('url', ['course' => $course->id,
            'externalurl' => 'https://receiver.test/badrequest.php']);
        $DB->set_field('url', 'parameters', serialize(['user' => 'userid']), ['id' => $withvariables->id]);
        $generator->create_module('url', ['course' => $course->id, 'externalurl' => 'https://plain.test/badrequest.php']);

        $checker = new fake_url_checker();
        [, $problems] = $this->scan($course->id, $checker);

        // A URL activity that adds learner data is a warning; the same error without it is a broken link.
        $this->assertCount(1, array_filter($problems, fn($p) => $p->url === 'https://receiver.test/badrequest.php'));
        $this->assertEquals(url_checker::WARNING, $problems['https://receiver.test/badrequest.php']->severity);
        $this->assertSame(
            get_string('error_urlvariables', 'local_brokenlinks'),
            $problems['https://receiver.test/badrequest.php']->message
        );
        $this->assertEquals(url_checker::BROKEN, $problems['https://plain.test/badrequest.php']->severity);

        // DOIs are checked with doi.org's lookup service.
        $this->assertContains('https://doi.org/api/handles/10.1234/article.5', $checker->requests);
        $this->assertArrayNotHasKey('https://doi.org/10.1234/article.5', $problems);
        $this->assertArrayHasKey('https://doi.org/10.1234/broken.6', $problems);

        // A link wrapped by an email security service is a warning that gives the original address.
        $wrapped = 'https://linkprotect.cudasvc.com/url?a=http%3a%2f%2fdoi.org%2f10.1097%2fNMC.370&c=E,1,x';
        $this->assertEquals(url_checker::WARNING, $problems[$wrapped]->severity);
        $this->assertStringContainsString('http://doi.org/10.1097/NMC.370', $problems[$wrapped]->message);
        $this->assertNotContains($wrapped, $checker->requests);
    }

    public function test_website_that_refuses_this_server(): void {
        $this->resetAfterTest();
        $checker = new fake_url_checker();

        // Every page of the website, home page included, answers "not found" to this server.
        $result = $checker->check('https://refusing.test/pubs/old-page/');
        $this->assertSame(url_checker::WARNING, $result['severity']);
        $this->assertSame(get_string('error_siterefuses', 'local_brokenlinks'), $result['message']);
        $checker->check('https://refusing.test/another/page');
        $this->assertCount(1, array_keys($checker->requests, 'https://refusing.test/'), 'The home page is checked once');

        // A missing page on a website that works is still broken, and so is a home page that fails.
        $this->assertSame(url_checker::BROKEN, $checker->check('https://broken.test/pubs/old-page/')['severity']);
        $this->assertSame(url_checker::BROKEN, $checker->check('https://refusing.test/')['severity']);
    }

    public function test_upgrade_adds_captivate_defaults(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/upgradelib.php');
        require_once($CFG->dirroot . '/local/brokenlinks/db/upgrade.php');
        $hosts = \local_brokenlinks\local\course_scanner::DEFAULT_IGNORED_HOSTS;
        $paths = \local_brokenlinks\local\scorm_scanner::DEFAULT_IGNORED_PATHS;

        // An untouched list from 0.3.2 gets the new entries; a customised list is left alone.
        set_config('ignoredhosts', implode("\n", array_diff($hosts, ['time.akamai.com'])), 'local_brokenlinks');
        set_config('scormignorepaths', "lms/*\nmy-own-library/*", 'local_brokenlinks');
        // Pretend the site is on 0.3.2, so the upgrade step can run.
        set_config('version', 2026102002, 'local_brokenlinks');
        xmldb_local_brokenlinks_upgrade(2026102002);

        $this->assertSame(implode("\n", $hosts), get_config('local_brokenlinks', 'ignoredhosts'));
        $this->assertSame("lms/*\nmy-own-library/*", get_config('local_brokenlinks', 'scormignorepaths'));
        $this->assertContains('CPXHRLoader.js', $paths);
    }

    public function test_scan_lifecycle(): void {
        global $DB;
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['summary' => '<a href="https://broken.test/">x</a>']);

        $first = scan_manager::queue($course->id);
        $this->assertSame($first, scan_manager::queue($course->id), 'A course is only queued once at a time');
        $this->assertCount(1, \core\task\manager::get_adhoc_tasks('\local_brokenlinks\task\scan_course'));

        scan_manager::run($first, new fake_url_checker());
        $this->assertNull(scan_manager::get_pending($course->id));
        $second = scan_manager::queue($course->id, false);
        scan_manager::run($second, new fake_url_checker());

        $this->assertEquals($second, scan_manager::get_latest($course->id)->id);
        $this->assertFalse($DB->record_exists('local_brokenlinks_scan', ['id' => $first]), 'Older scans are removed');
        $this->assertFalse($DB->record_exists('local_brokenlinks_link', ['scanid' => $first]));

        delete_course($course, false);
        $this->assertFalse($DB->record_exists('local_brokenlinks_scan', ['courseid' => $course->id]));
        $this->assertFalse($DB->record_exists('local_brokenlinks_link', ['courseid' => $course->id]));
    }
}
