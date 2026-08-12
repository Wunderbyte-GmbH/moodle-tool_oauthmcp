<?php
// This file is part of Moodle - http://moodle.org/
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
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_oauthmcp;

use tool_oauthmcp\local\setup\htaccess_fixer;

/**
 * Tests for the pure .htaccess block logic of the automatic server setup.
 *
 * Only the string-level functions are tested here: they alone decide what ends up in the
 * admin's .htaccess, and a mistake there is exactly the "crashed their site" scenario the
 * feature promises to prevent. Filesystem writes and live probes are exercised manually.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \tool_oauthmcp\local\setup\htaccess_fixer
 */
final class htaccess_fixer_test extends \advanced_testcase {
    /**
     * Merging into an empty file yields exactly the managed block.
     *
     * @return void
     */
    public function test_merge_into_empty(): void {
        $merged = htaccess_fixer::merge('');
        $this->assertStringStartsWith(htaccess_fixer::BEGIN . "\n", $merged);
        $this->assertStringEndsWith(htaccess_fixer::END . "\n", $merged);
        $this->assertSame(1, substr_count($merged, htaccess_fixer::BEGIN));
    }

    /**
     * Existing content stays untouched, byte for byte, ahead of the appended block.
     *
     * @return void
     */
    public function test_merge_preserves_existing_content(): void {
        $existing = "FcgidWrapper \"/home/httpd/cgi-bin/php83-fcgi-starter.fcgi\" .php\n";
        $merged = htaccess_fixer::merge($existing);
        $this->assertStringStartsWith($existing, $merged);
        $this->assertStringContainsString(htaccess_fixer::BEGIN, $merged);
        $this->assertStringEndsWith(htaccess_fixer::END . "\n", $merged);
    }

    /**
     * Re-applying never duplicates the block (strip-then-append).
     *
     * @return void
     */
    public function test_merge_is_idempotent(): void {
        $once = htaccess_fixer::merge("# keep me\n");
        $twice = htaccess_fixer::merge($once);
        $this->assertSame($once, $twice);
        $this->assertSame(1, substr_count($twice, htaccess_fixer::BEGIN));
        $this->assertStringContainsString('# keep me', $twice);
    }

    /**
     * Stripping removes only the managed block; surrounding lines survive.
     *
     * @return void
     */
    public function test_strip_block_surgical(): void {
        $content = "before\n" . htaccess_fixer::build_block() . "\nafter\n";
        $stripped = htaccess_fixer::strip_block($content);
        $this->assertSame("before\nafter\n", $stripped);
    }

    /**
     * CRLF files (edited on Windows, uploaded via FTP) are handled too.
     *
     * @return void
     */
    public function test_strip_block_crlf(): void {
        $content = "before\r\n" . str_replace("\n", "\r\n", htaccess_fixer::build_block()) . "\r\nafter\r\n";
        $stripped = htaccess_fixer::strip_block($content);
        $this->assertSame("before\nafter\n", $stripped);
    }

    /**
     * An unterminated BEGIN (hand-mangled file) drops through to EOF rather than leaving
     * half a managed block behind.
     *
     * @return void
     */
    public function test_strip_unterminated_block(): void {
        $content = "keep\n" . htaccess_fixer::BEGIN . "\nRewriteEngine On\n";
        $this->assertSame("keep\n", htaccess_fixer::strip_block($content));
    }

    /**
     * Stripping content without any managed block is a no-op (modulo trailing newline).
     *
     * @return void
     */
    public function test_strip_without_block(): void {
        $this->assertSame("plain\nlines\n", htaccess_fixer::strip_block("plain\nlines\n"));
        $this->assertSame('', htaccess_fixer::strip_block(''));
    }

    /**
     * A subdirectory install is a hard precondition failure — the block would land in the
     * wrong folder entirely.
     *
     * @return void
     */
    public function test_status_blocks_subdirectory_install(): void {
        global $CFG;
        $this->resetAfterTest();
        $CFG->wwwroot = 'https://www.example.com/moodle';

        $status = (new htaccess_fixer())->status();
        $this->assertFalse($status['supported']);
        $this->assertContains('serversetup_reason_subdirectory', $status['reasons']);
    }
}
