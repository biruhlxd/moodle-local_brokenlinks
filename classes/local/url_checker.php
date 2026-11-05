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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/filelib.php');

/**
 * Checks whether external URLs respond.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class url_checker {
    /** @var int The URL works. */
    const OK = 0;
    /** @var int The URL could not be confirmed, for example because the site blocks automated requests. */
    const WARNING = 1;
    /** @var int The URL is broken. */
    const BROKEN = 2;

    /** @var int Minimum gap between two requests to the same host, in microseconds. */
    const HOST_DELAY = 250000;

    /** @var int How much of a page is read when looking for "not found" text, in bytes. */
    const MAX_PAGE_BYTES = 262144;

    /** @var string[] Default phrases that mean a page loaded but its content does not exist. */
    const DEFAULT_NOT_FOUND_PHRASES = [
        'unavailable or does not exist',
        'page not found',
        '404 not found',
        'page cannot be found',
        'page could not be found',
        'page does not exist',
        "page doesn't exist",
        'page no longer exists',
        'page is no longer available',
        'document is no longer available',
        'document does not exist',
    ];

    /** @var string[] Phrases that mean a page loaded but its content does not exist. */
    protected $notfoundphrases = [];

    /** @var bool[] Home page address => whether that website refuses this server, for this run. */
    protected $homes = [];

    /** @var int Request timeout in seconds. */
    protected $timeout;

    /** @var int How long a result is reused, in seconds. */
    protected $cachettl;

    /** @var array Time of the last request to each host. */
    protected $lastrequest = [];

    /** @var float Seconds the last request took, for the time-out message. */
    protected $elapsed = 0.0;

    /** @var int Time-outs in a row after which a website's other links are not requested until it is tried again. */
    const HOST_TIMEOUTS = 2;

    /** @var int[] Host => time-outs in a row, for this run. */
    protected $hosttimeouts = [];

    /** @var bool[] URLs whose last check timed out or was put off because their website was not answering. */
    protected $timedout = [];

    /** @var bool Whether a request in the current check timed out. */
    protected $requesttimedout = false;

    /** @var \cache_application|null */
    protected $cache;

    /**
     * Constructor.
     *
     * @param int|null $timeout Request timeout in seconds, defaults to the plugin setting.
     * @param int|null $cachettl Seconds to reuse a result, defaults to the plugin setting.
     */
    public function __construct(?int $timeout = null, ?int $cachettl = null) {
        $config = get_config('local_brokenlinks');
        $this->timeout = max(1, $timeout ?? (int)($config->timeout ?? 10));
        $this->cachettl = $cachettl ?? (int)($config->cachehours ?? 24) * HOURSECS;
        $phrases = $config->notfoundphrases ?? implode("\n", self::DEFAULT_NOT_FOUND_PHRASES);
        $this->notfoundphrases = array_values(array_filter(array_map('trim', preg_split('~\R~', (string)$phrases))));
        $this->cache = \cache::make('local_brokenlinks', 'urlstatus');
    }

    /**
     * Check a URL.
     *
     * A website that has not answered several requests in a row is not asked again in this run until
     * recheck() is called; its links are reported as timed out, and timed_out() says so.
     *
     * @param string $url An absolute http or https URL.
     * @return array ['severity' => int, 'httpstatus' => int, 'message' => string]
     */
    public function check(string $url): array {
        $key = sha1($url);
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        unset($this->timedout[$url]);
        if (($this->hosttimeouts[$host] ?? 0) >= self::HOST_TIMEOUTS) {
            $this->timedout[$url] = true;
            return $this->result(self::WARNING, 0, get_string('error_timeout', 'local_brokenlinks', $this->timeout));
        }
        if ($this->cachettl > 0) {
            $cached = $this->cache->get($key);
            if ($cached && $cached['time'] > time() - $this->cachettl) {
                return $cached['result'];
            }
        }
        $this->requesttimedout = false;
        $result = $this->request($url);
        if ($this->requesttimedout && $result['httpstatus'] === 0) {
            // Time-outs are often passing, so they are tried again at the end of the run and not kept.
            $this->hosttimeouts[$host] = ($this->hosttimeouts[$host] ?? 0) + 1;
            $this->timedout[$url] = true;
            return $result;
        }
        $this->hosttimeouts[$host] = 0;
        // Some websites refuse servers in hosting centres by answering "not found" for every page, home page included,
        // while visitors get the page. A missing page on a website whose home page also fails is not a broken link.
        if ($result['severity'] === self::BROKEN && in_array($result['httpstatus'], [404, 410]) && $this->site_refuses($url)) {
            $result = $this->result(self::WARNING, $result['httpstatus'], get_string('error_siterefuses', 'local_brokenlinks'));
        }
        if ($this->cachettl > 0) {
            $this->cache->set($key, ['time' => time(), 'result' => $result]);
        }
        return $result;
    }

    /**
     * Whether the last check of a URL timed out, or was put off because its website was not answering.
     *
     * @param string $url
     * @return bool
     */
    public function timed_out(string $url): bool {
        return isset($this->timedout[$url]);
    }

    /**
     * Check a URL again after it timed out, asking its website even if it has not been answering.
     *
     * @param string $url
     * @return array As check().
     */
    public function recheck(string $url): array {
        unset($this->hosttimeouts[strtolower((string)parse_url($url, PHP_URL_HOST))]);
        return $this->check($url);
    }

    /**
     * Wait before links that timed out are tried again.
     *
     * @param int $seconds
     */
    public function pause(int $seconds): void {
        sleep($seconds);
    }

    /**
     * Whether the home page of a link's website fails too, as when the website refuses this server.
     *
     * @param string $url
     * @return bool False for a link to the home page itself.
     */
    protected function site_refuses(string $url): bool {
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $home = strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '') . '/';
        if (rtrim($url, '/') === rtrim($home, '/')) {
            return false;
        }
        if (!array_key_exists($home, $this->homes)) {
            $answer = $this->request($home);
            $this->homes[$home] = $answer['severity'] !== self::OK && $answer['httpstatus'] >= 400;
        }
        return $this->homes[$home];
    }

    /**
     * Request the URL and classify the response.
     *
     * @param string $url
     * @return array
     */
    protected function request(string $url): array {
        $curl = $this->make_curl();
        if ($curl->get_security()->url_is_blocked($url)) {
            // The security helper also blocks hosts it cannot resolve, and those links are simply broken.
            $host = (string)parse_url($url, PHP_URL_HOST);
            if (!filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) && gethostbynamel($host) === false) {
                return $this->result(self::BROKEN, 0, get_string('error_dns', 'local_brokenlinks'));
            }
            return $this->result(self::WARNING, 0, get_string('error_blockedbysecurity', 'local_brokenlinks'));
        }

        $this->wait_for_host($url);
        $curl->head($url);
        [$status, $errno, $error] = $this->response_details($curl);

        // A certificate that cannot be verified is only reported when it is certainly wrong. Other
        // verification failures usually mean this server's own certificate store is incomplete.
        $verify = true;
        if ($errno === CURLE_SSL_CACERT && !self::is_certificate_fault($error)) {
            $verify = false;
            $curl = $this->make_curl(false);
            $this->wait_for_host($url);
            $curl->head($url);
            [$status, $errno, $error] = $this->response_details($curl);
        }

        // Many servers answer HEAD wrongly, so confirm failures with a small GET request.
        if ($errno !== CURLE_OPERATION_TIMEOUTED && $errno !== CURLE_SSL_CACERT && ($status === 0 || $status >= 400)) {
            $curl = $this->make_curl($verify);
            $curl->setopt(['CURLOPT_RANGE' => '0-2047']);
            $this->wait_for_host($url);
            $curl->get($url);
            [$status, $errno, $error] = $this->response_details($curl);
        }

        $result = $this->classify($status, $errno, $error);

        // Some sites answer 200 OK with a page that says the content does not exist (a "soft 404").
        $type = strtolower((string)($curl->get_info()['content_type'] ?? ''));
        if ($result['severity'] === self::OK && $status < 300 && str_contains($type, 'text/html') && $this->notfoundphrases) {
            $curl = $this->make_curl($verify);
            $curl->setopt(['CURLOPT_RANGE' => '0-' . (self::MAX_PAGE_BYTES - 1)]);
            $this->wait_for_host($url);
            $body = (string)$curl->get($url);
            [$status, $errno] = $this->response_details($curl);
            $said = $errno === 0 && $status < 300 ? self::find_not_found_text($body, $this->notfoundphrases) : null;
            if ($said !== null) {
                return $this->result(self::BROKEN, $status, get_string('error_softnotfound', 'local_brokenlinks', $said));
            }
        }
        return $result;
    }

    /**
     * Find text in a web page saying that its content does not exist.
     *
     * Only text a visitor sees is searched: scripts, styles and comments are removed first.
     *
     * @param string $html
     * @param string[] $phrases Phrases to look for, matched without regard to case.
     * @return string|null The sentence that contains the phrase, or null if none is found.
     */
    public static function find_not_found_text(string $html, array $phrases): ?string {
        $html = substr($html, 0, self::MAX_PAGE_BYTES);
        $html = preg_replace('~<(script|style|noscript|template)\b.*?</\1>|<!--.*?-->~is', ' ', $html);
        $html = preg_replace('~<(br|p|div|h[1-6]|li|td|title)\b[^>]*>~i', "\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        foreach (preg_split('~\n+~', $text) as $line) {
            $line = trim(preg_replace('~\s+~u', ' ', $line));
            foreach ($phrases as $phrase) {
                if ($phrase !== '' && $line !== '' && mb_stripos($line, $phrase) !== false) {
                    return \core_text::substr($line, 0, 200);
                }
            }
        }
        return null;
    }

    /**
     * Whether a certificate verification error is a fault of the website rather than of this server.
     *
     * @param string $error The cURL error message.
     * @return bool True for expired, self-signed and wrong-domain certificates.
     */
    public static function is_certificate_fault(string $error): bool {
        return (bool)preg_match('~certificate has expired|self.signed certificate \(18\)|subject name|' .
            'does not match target host|certificate is not yet valid~i', $error);
    }

    /**
     * Turn an HTTP status or cURL error into a result.
     *
     * @param int $status
     * @param int $errno
     * @param string $error
     * @return array
     */
    public function classify(int $status, int $errno, string $error): array {
        if ($status === 0) {
            if ($errno === CURLE_OPERATION_TIMEOUTED) {
                $this->requesttimedout = true;
                // Report how long was actually waited; the system can give up before the configured timeout.
                $waited = $this->elapsed > 0 ? (int)round($this->elapsed) : $this->timeout;
                return $this->result(self::WARNING, 0, get_string('error_timeout', 'local_brokenlinks', $waited));
            }
            if ($errno === CURLE_COULDNT_RESOLVE_HOST) {
                return $this->result(self::BROKEN, 0, get_string('error_dns', 'local_brokenlinks'));
            }
            // SSL connect error, peer verification failed, bad client certificate, unknown CA.
            if (in_array($errno, [35, 51, 58, 60])) {
                return $this->result(self::BROKEN, 0, get_string('error_ssl', 'local_brokenlinks', $error));
            }
            return $this->result(
                self::BROKEN,
                0,
                get_string('error_noresponse', 'local_brokenlinks', $error !== '' ? $error : '-')
            );
        }
        if ($status < 400) {
            return $this->result(self::OK, $status, '');
        }
        // These codes usually mean the site refuses automated checks, not that the page is gone.
        if (in_array($status, [401, 403, 429, 999])) {
            return $this->result(self::WARNING, $status, get_string('error_restricted', 'local_brokenlinks'));
        }
        if ($status === 404 || $status === 410) {
            return $this->result(self::BROKEN, $status, get_string('error_notfound', 'local_brokenlinks'));
        }
        if ($status >= 500) {
            return $this->result(self::BROKEN, $status, get_string('error_server', 'local_brokenlinks'));
        }
        return $this->result(self::BROKEN, $status, get_string('error_http', 'local_brokenlinks', $status));
    }

    /**
     * Create a configured cURL wrapper.
     *
     * @param bool $verify Whether to verify the website's certificate.
     * @return \curl
     */
    protected function make_curl(bool $verify = true): \curl {
        global $CFG;
        $curl = new \curl(['ignoresecurity' => false]);
        $curl->setopt([
            'CURLOPT_TIMEOUT' => $this->timeout,
            'CURLOPT_CONNECTTIMEOUT' => $this->timeout,
            'CURLOPT_FOLLOWLOCATION' => true,
            'CURLOPT_MAXREDIRS' => 5,
            // Moodle does not verify certificates by default, but an expired certificate breaks the link for learners.
            'CURLOPT_SSL_VERIFYPEER' => $verify,
            'CURLOPT_USERAGENT' => 'Mozilla/5.0 (compatible; MoodleBrokenLinks/1.0; +' . $CFG->wwwroot . ')',
        ]);
        return $curl;
    }

    /**
     * Read the status, error number and error message of the last request.
     *
     * @param \curl $curl
     * @return array
     */
    protected function response_details(\curl $curl): array {
        $info = $curl->get_info();
        $this->elapsed = (float)($info['total_time'] ?? 0);
        return [(int)($info['http_code'] ?? 0), (int)$curl->get_errno(), (string)$curl->error];
    }

    /**
     * Pause so the same host is not hit by many requests at once.
     *
     * @param string $url
     */
    protected function wait_for_host(string $url): void {
        $host = strtolower((string)parse_url($url, PHP_URL_HOST));
        $now = microtime(true);
        if (isset($this->lastrequest[$host])) {
            $wait = self::HOST_DELAY - (int)(($now - $this->lastrequest[$host]) * 1000000);
            if ($wait > 0) {
                usleep($wait);
            }
        }
        $this->lastrequest[$host] = microtime(true);
    }

    /**
     * Build a result array.
     *
     * @param int $severity
     * @param int $status
     * @param string $message
     * @return array
     */
    protected function result(int $severity, int $status, string $message): array {
        return ['severity' => $severity, 'httpstatus' => $status, 'message' => $message];
    }
}
