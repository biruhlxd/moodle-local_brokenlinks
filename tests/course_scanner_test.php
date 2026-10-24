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
