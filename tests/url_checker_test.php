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

use local_brokenlinks\local\url_checker;

/**
 * Tests for how URL check responses are classified.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
#[\PHPUnit\Framework\Attributes\CoversClass(url_checker::class)]
final class url_checker_test extends \advanced_testcase {
    /**
     * Responses and the severity they should get.
     *
     * @return array
     */
    public static function classify_provider(): array {
        return [
            'ok' => [200, 0, url_checker::OK],
            'redirect left over' => [302, 0, url_checker::OK],
            'not found' => [404, 0, url_checker::BROKEN],
            'gone' => [410, 0, url_checker::BROKEN],
            'server error' => [503, 0, url_checker::BROKEN],
            'forbidden is unconfirmed' => [403, 0, url_checker::WARNING],
            'rate limited is unconfirmed' => [429, 0, url_checker::WARNING],
            'timeout is unconfirmed' => [0, CURLE_OPERATION_TIMEOUTED, url_checker::WARNING],
            'no such domain' => [0, CURLE_COULDNT_RESOLVE_HOST, url_checker::BROKEN],
            'bad certificate' => [0, 60, url_checker::BROKEN],
            'connection refused' => [0, 7, url_checker::BROKEN],
        ];
    }

    /**
     * Each response gets the right severity.
     *
     * @param int $status
     * @param int $errno
     * @param int $severity
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('classify_provider')]
    public function test_classify(int $status, int $errno, int $severity): void {
        $this->resetAfterTest();
        $result = (new url_checker(5, 0))->classify($status, $errno, 'error');
        $this->assertSame($severity, $result['severity']);
        $this->assertSame($status, $result['httpstatus']);
        $this->assertSame($severity === url_checker::OK, $result['message'] === '');
    }

    public function test_soft_not_found_pages(): void {
        $phrases = url_checker::DEFAULT_NOT_FOUND_PHRASES;
        // A document library page that answers 200 OK for a document that is gone.
        $library = '<html><head><title>Document Info - Library</title><script>var x = "page not found";</script></head>' .
            '<body><nav>Home | Contact Us</nav><div class="content"><p>This document you tried to view is ' .
            'unavailable or does not exist.</p></div></body></html>';
        $this->assertSame(
            'This document you tried to view is unavailable or does not exist.',
            url_checker::find_not_found_text($library, $phrases)
        );
        $this->assertSame('404 Not Found', url_checker::find_not_found_text('<title>404 Not Found</title>', $phrases));
        // HTML-encoded characters are decoded before matching.
        $this->assertSame("Sorry, this page doesn't exist", url_checker::find_not_found_text(
            '<h1>Sorry, this page doesn&#39;t exist</h1>',
            $phrases
        ));
    }

    public function test_normal_pages_are_not_flagged(): void {
        $phrases = url_checker::DEFAULT_NOT_FOUND_PHRASES;
        $page = '<title>Document Info - Library</title><h1>Course Recipes</h1>';
        $this->assertNull(url_checker::find_not_found_text($page, $phrases));
        // Words in scripts, styles and comments are not what a visitor sees.
        $hidden = '<script>showError("page not found")</script><style>/* page not found */</style>' .
            '<!-- 404 not found --><p>Welcome</p>';
        $this->assertNull(url_checker::find_not_found_text($hidden, $phrases));
        $this->assertNull(url_checker::find_not_found_text('<p>Page not found</p>', []), 'The check can be turned off');
    }

    public function test_certificate_faults(): void {
        $this->assertTrue(url_checker::is_certificate_fault(
            'SSL certificate OpenSSL verify result: certificate has expired (10)'
        ));
        $this->assertTrue(url_checker::is_certificate_fault(
            'SSL certificate OpenSSL verify result: self-signed certificate (18)'
        ));
        $this->assertTrue(url_checker::is_certificate_fault(
            "SSL: no alternative certificate subject name matches target hostname 'a.test'"
        ));
        // A missing local certificate store is this server's problem, not the website's.
        $this->assertFalse(url_checker::is_certificate_fault(
            'SSL certificate OpenSSL verify result: unable to get local issuer certificate (20)'
        ));
        $this->assertFalse(url_checker::is_certificate_fault(
            'SSL certificate OpenSSL verify result: self-signed certificate in certificate chain (19)'
        ));
    }

    public function test_blocked_hosts_are_not_requested(): void {
        $this->resetAfterTest();
        set_config('curlsecurityblockedhosts', '127.0.0.1');
        $result = (new url_checker(5, 0))->check('http://127.0.0.1/page');
        $this->assertSame(url_checker::WARNING, $result['severity']);
        $this->assertSame(get_string('error_blockedbysecurity', 'local_brokenlinks'), $result['message']);
    }

    public function test_unknown_domains_are_broken(): void {
        $this->resetAfterTest();
        // Moodle's security helper blocks hosts it cannot resolve; those are reported as broken, not blocked.
        $result = (new url_checker(5, 0))->check('https://no-such-host.invalid/page');
        $this->assertSame(url_checker::BROKEN, $result['severity']);
        $this->assertSame(get_string('error_dns', 'local_brokenlinks'), $result['message']);
    }
}
