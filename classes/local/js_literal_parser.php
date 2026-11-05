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
 * Reads a JavaScript object literal, such as the slide data Captivate writes, into PHP arrays.
 *
 * Nothing is executed. Strings, numbers, true, false, null, nested objects and arrays are read as
 * values. Anything else, such as a reference to a player variable (cp.fd) or a function call, is
 * read as its source text.
 *
 * @package    local_brokenlinks
 * @copyright  2026 Biruh Alemnew <biruh@biruh.org>
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class js_literal_parser {
    /** @var int Deepest nesting accepted, to stop runaway input. */
    const MAX_DEPTH = 64;

    /** @var string */
    protected $src;

    /** @var int */
    protected $pos;

    /** @var int */
    protected $len;

    /**
     * Find the object literal assigned by a marker such as "cp.D = cp.model.data =" and read it.
     *
     * @param string $src JavaScript source.
     * @param string $marker Text that comes just before the object.
     * @return array|null The object, or null if it is not there or cannot be read.
     */
    public static function parse_assignment(string $src, string $marker): ?array {
        $at = strpos($src, $marker);
        if ($at === false) {
            return null;
        }
        $start = strpos($src, '{', $at + strlen($marker));
        if ($start === false) {
            return null;
        }
        try {
            $value = (new self())->parse($src, $start);
        } catch (\moodle_exception | \UnexpectedValueException $e) {
            return null;
        }
        return is_array($value) ? $value : null;
    }

    /**
     * Read the value that starts at a position.
     *
     * @param string $src
     * @param int $start
     * @return mixed
     */
    public function parse(string $src, int $start = 0) {
        $this->src = $src;
        $this->pos = $start;
        $this->len = strlen($src);
        return $this->value(0);
    }

    /**
     * Read one value.
     *
     * @param int $depth
     * @return mixed
     */
    protected function value(int $depth) {
        if ($depth > self::MAX_DEPTH) {
            throw new \UnexpectedValueException('Too deeply nested');
        }
        $this->skip_space();
        $char = $this->src[$this->pos] ?? '';
        if ($char === '{') {
            return $this->object($depth);
        }
        if ($char === '[') {
            return $this->list($depth);
        }
        if ($char === "'" || $char === '"') {
            return $this->string();
        }
        return $this->bare();
    }

    /**
     * Read an object.
     *
     * @param int $depth
     * @return array
     */
    protected function object(int $depth): array {
        $result = [];
        $this->pos++;
        while (true) {
            $this->skip_space();
            $char = $this->src[$this->pos] ?? '';
            if ($char === '}') {
                $this->pos++;
                return $result;
            }
            if ($char === ',') {
                $this->pos++;
                continue;
            }
            if ($char === '') {
                throw new \UnexpectedValueException('Unterminated object');
            }
            if ($char === "'" || $char === '"') {
                $key = $this->string();
            } else {
                preg_match('~[A-Za-z0-9_$]+~A', $this->src, $match, 0, $this->pos);
                if (!$match) {
                    throw new \UnexpectedValueException('Bad key at ' . $this->pos);
                }
                $key = $match[0];
                $this->pos += strlen($key);
            }
            $this->skip_space();
            if (($this->src[$this->pos] ?? '') !== ':') {
                throw new \UnexpectedValueException('Missing colon at ' . $this->pos);
            }
            $this->pos++;
            $before = $this->pos;
            $result[$key] = $this->value($depth + 1);
            if ($this->pos === $before) {
                throw new \UnexpectedValueException('Missing value at ' . $this->pos);
            }
        }
    }

    /**
     * Read an array.
     *
     * @param int $depth
     * @return array
     */
    protected function list(int $depth): array {
        $result = [];
        $this->pos++;
        while (true) {
            $this->skip_space();
            $char = $this->src[$this->pos] ?? '';
            if ($char === ']') {
                $this->pos++;
                return $result;
            }
            if ($char === ',') {
                $this->pos++;
                continue;
            }
            if ($char === '') {
                throw new \UnexpectedValueException('Unterminated array');
            }
            $before = $this->pos;
            $result[] = $this->value($depth + 1);
            // A character no value can start with, such as a stray ")", would otherwise be read forever.
            if ($this->pos === $before) {
                throw new \UnexpectedValueException('Unexpected character at ' . $this->pos);
            }
        }
    }

    /**
     * Read a quoted string, decoding JavaScript escapes.
     *
     * @return string
     */
    protected function string(): string {
        $quote = $this->src[$this->pos];
        $this->pos++;
        $out = '';
        while ($this->pos < $this->len) {
            $char = $this->src[$this->pos];
            if ($char === $quote) {
                $this->pos++;
                return $out;
            }
            if ($char !== '\\') {
                // Copy a run of ordinary characters at once.
                $run = strcspn($this->src, $quote . '\\', $this->pos);
                $out .= substr($this->src, $this->pos, $run);
                $this->pos += $run;
                continue;
            }
            $next = $this->src[$this->pos + 1] ?? '';
            $this->pos += 2;
            switch ($next) {
                case 'n':
                    $out .= "\n";
                    break;
                case 't':
                    $out .= "\t";
                    break;
                case 'r':
                    $out .= "\r";
                    break;
                case 'b':
                case 'f':
                case 'v':
                    break;
                case "\r":
                case "\n":
                    // A backslash at the end of a line continues the string.
                    if ($next === "\r" && ($this->src[$this->pos] ?? '') === "\n") {
                        $this->pos++;
                    }
                    break;
                case 'u':
                    $hex = substr($this->src, $this->pos, 4);
                    if (preg_match('~^[0-9a-fA-F]{4}$~', $hex)) {
                        $out .= \core_text::code2utf8(hexdec($hex));
                        $this->pos += 4;
                    }
                    break;
                case 'x':
                    $hex = substr($this->src, $this->pos, 2);
                    if (preg_match('~^[0-9a-fA-F]{2}$~', $hex)) {
                        $out .= \core_text::code2utf8(hexdec($hex));
                        $this->pos += 2;
                    }
                    break;
                default:
                    $out .= $next;
            }
        }
        throw new \UnexpectedValueException('Unterminated string');
    }

    /**
     * Read a number, keyword or other unquoted value up to the next comma or closing bracket.
     *
     * @return mixed
     */
    protected function bare() {
        $start = $this->pos;
        $depth = 0;
        while ($this->pos < $this->len) {
            $char = $this->src[$this->pos];
            if ($char === "'" || $char === '"') {
                $this->string();
                continue;
            }
            if ($char === '(' || $char === '[' || $char === '{') {
                $depth++;
            } else if ($char === ')' || $char === ']' || $char === '}') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
            } else if ($char === ',' && $depth === 0) {
                break;
            }
            $this->pos++;
        }
        $text = trim(substr($this->src, $start, $this->pos - $start));
        if ($text === 'true') {
            return true;
        }
        if ($text === 'false') {
            return false;
        }
        if ($text === 'null' || $text === 'undefined' || $text === '') {
            return null;
        }
        if (is_numeric($text)) {
            return $text + 0;
        }
        return $text;
    }

    /**
     * Skip whitespace and comments.
     */
    protected function skip_space(): void {
        while ($this->pos < $this->len) {
            $this->pos += strspn($this->src, " \t\r\n", $this->pos);
            if (substr($this->src, $this->pos, 2) === '//') {
                $end = strpos($this->src, "\n", $this->pos);
                $this->pos = $end === false ? $this->len : $end + 1;
            } else if (substr($this->src, $this->pos, 2) === '/*') {
                $end = strpos($this->src, '*/', $this->pos + 2);
                $this->pos = $end === false ? $this->len : $end + 2;
            } else {
                return;
            }
        }
    }
}
