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

/**
 * Automatic .htaccess setup for the OAuth discovery rewrites and Authorization pass-through.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace tool_oauthmcp\local\setup;

/**
 * Writes (and removes) a marker-delimited block in the site's .htaccess so admins on managed
 * hosting can enable OAuth discovery without any server knowledge.
 *
 * The safety contract, in order:
 *  1. Hard preconditions are re-checked at apply() time, not only in the UI: Moodle must be
 *     served at the domain root (otherwise the block belongs to a docroot we cannot see),
 *     the webserver must not be nginx (which ignores .htaccess entirely), and the target
 *     must be writable.
 *  2. The previous state is preserved twice before writing: the full prior content in plugin
 *     config (survives even deletion of the file) and, when a file existed, a timestamped
 *     backup file next to it.
 *  3. The write is atomic (temp file + rename), so a crash mid-write cannot leave a torn file.
 *  4. After writing, the site is probed over real HTTP: front page still answers, both
 *     discovery documents answer 200 with the right JSON, and the MCP endpoint still issues
 *     its 401 challenge. If ANY of those fail, the previous state is restored immediately and
 *     the report says so — the admin can never be left with a broken site by this class.
 *
 * The Authorization-header probe is deliberately informational only: when a host strips the
 * header even with the rewrite pair in place, the discovery rewrites are still worth keeping
 * (OAuth needs them) and token clients can fall back to the X-Moodle-Token header.
 *
 * @package    tool_oauthmcp
 * @copyright  2026 Wunderbyte GmbH <info@wunderbyte.at>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class htaccess_fixer {
    /** @var string First line of the managed block; everything between the markers is ours. */
    public const BEGIN = '# BEGIN tool_oauthmcp';

    /** @var string Last line of the managed block. */
    public const END = '# END tool_oauthmcp';

    /**
     * The managed block, markers included.
     *
     * Content mirrors .well-known-snippets/apache.conf.example in per-directory form: patterns
     * lose the leading slash, and the RewriteCond/RewriteRule pair re-injects the Authorization
     * header on FastCGI hosting where SetEnvIf alone is not honoured.
     *
     * @return string
     */
    public static function build_block(): string {
        return implode("\n", [
            self::BEGIN,
            '# Written by the Moodle plugin tool_oauthmcp (server setup page). Do not edit inside',
            '# this block - remove it from that page, or delete the whole block including markers.',
            'SetEnvIfNoCase ^Authorization$ "(.+)" HTTP_AUTHORIZATION=$1',
            '<IfModule mod_rewrite.c>',
            'RewriteEngine On',
            'RewriteCond %{HTTP:Authorization} .',
            'RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            'RewriteRule ^\.well-known/oauth-authorization-server/?$ /admin/tool/oauthmcp/oauth/asmeta.php [L]',
            'RewriteRule ^\.well-known/oauth-protected-resource/?$ /admin/tool/oauthmcp/oauth/prm.php [L]',
            '</IfModule>',
            self::END,
        ]);
    }

    /**
     * Remove any previously managed block from .htaccess content.
     *
     * Line-based and CRLF-tolerant; everything from a BEGIN line to the next END line
     * (inclusive) is dropped. An unterminated BEGIN drops through to the end of the file —
     * safer than leaving half a managed block behind.
     *
     * @param string $content Current file content.
     * @return string Content without the managed block.
     */
    public static function strip_block(string $content): string {
        $lines = preg_split("/\r\n|\n/", $content);
        $kept = [];
        $inside = false;
        foreach ($lines as $line) {
            if (!$inside && trim($line) === self::BEGIN) {
                $inside = true;
                continue;
            }
            if ($inside) {
                if (trim($line) === self::END) {
                    $inside = false;
                }
                continue;
            }
            $kept[] = $line;
        }
        return rtrim(implode("\n", $kept), "\n") === '' ? '' : rtrim(implode("\n", $kept), "\n") . "\n";
    }

    /**
     * Merge the managed block into existing content (idempotent: strips any old copy first).
     *
     * @param string $content Current file content.
     * @return string New file content.
     */
    public static function merge(string $content): string {
        $stripped = self::strip_block($content);
        $separator = ($stripped === '') ? '' : "\n";
        return $stripped . $separator . self::build_block() . "\n";
    }

    /**
     * Absolute path of the managed file.
     *
     * @return string
     */
    public function target_path(): string {
        global $CFG;
        return $CFG->dirroot . '/.htaccess';
    }

    /**
     * Current state and whether automatic setup can run here.
     *
     * @return array{supported:bool,reasons:string[],warnings:string[],applied:bool,
     *               fileexists:bool,path:string,backupfile:string}
     */
    public function status(): array {
        global $CFG;

        $path = $this->target_path();
        $fileexists = file_exists($path);
        $content = $fileexists ? (string)file_get_contents($path) : '';

        $reasons = [];
        $warnings = [];

        // Moodle in a subdirectory means /.well-known/ lives in a docroot we cannot locate
        // reliably from PHP - manual setup territory.
        if (rtrim((string)parse_url($CFG->wwwroot, PHP_URL_PATH), '/') !== '') {
            $reasons[] = 'serversetup_reason_subdirectory';
        }

        // On nginx a .htaccess is ignored entirely; writing one would silently do nothing.
        $software = (string)($_SERVER['SERVER_SOFTWARE'] ?? '');
        if (stripos($software, 'nginx') !== false) {
            $reasons[] = 'serversetup_reason_nginx';
        } else if ($software !== '' && stripos($software, 'apache') === false
                && stripos($software, 'litespeed') === false) {
            $warnings[] = 'serversetup_warning_unknownserver';
        }

        if ($fileexists ? !is_writable($path) : !is_writable(dirname($path))) {
            $reasons[] = 'serversetup_reason_notwritable';
        }

        return [
            'supported' => empty($reasons),
            'reasons' => $reasons,
            'warnings' => $warnings,
            'applied' => strpos($content, self::BEGIN) !== false,
            'fileexists' => $fileexists,
            'path' => $path,
            'backupfile' => (string)get_config('tool_oauthmcp', 'htaccess_backupfile'),
        ];
    }

    /**
     * Apply the managed block: backup, write atomically, self-test, roll back on any failure.
     *
     * @return array{success:bool,rolledback:bool,error:string,checks:array,backupfile:string}
     */
    public function apply(): array {
        $status = $this->status();
        if (!$status['supported']) {
            return [
                'success' => false,
                'rolledback' => false,
                'error' => (string)reset($status['reasons']),
                'checks' => [],
                'backupfile' => '',
            ];
        }

        $path = $this->target_path();
        $hadfile = $status['fileexists'];
        $old = $hadfile ? (string)file_get_contents($path) : '';

        // Preserve the previous state twice BEFORE touching anything: in config (survives file
        // deletion, powers revert()) and as a sibling file the admin can restore over FTP/SSH
        // even if Moodle itself becomes unreachable.
        set_config('htaccess_backup', $old, 'tool_oauthmcp');
        set_config('htaccess_hadfile', $hadfile ? '1' : '0', 'tool_oauthmcp');
        $backupfile = '';
        if ($hadfile) {
            $backupfile = $path . '.oauthmcp-backup-' . date('Ymd-His');
            if (@copy($path, $backupfile) === false) {
                return [
                    'success' => false,
                    'rolledback' => false,
                    'error' => 'serversetup_reason_backupfailed',
                    'checks' => [],
                    'backupfile' => '',
                ];
            }
        }
        set_config('htaccess_backupfile', $backupfile, 'tool_oauthmcp');

        if (!$this->write_atomically($path, self::merge($old))) {
            return [
                'success' => false,
                'rolledback' => false,
                'error' => 'serversetup_reason_writefailed',
                'checks' => [],
                'backupfile' => $backupfile,
            ];
        }

        [$allok, $checks] = $this->selftest();
        if (!$allok) {
            // The promise: either it works, or everything is put back exactly as it was.
            $this->restore($path, $old, $hadfile);
            [, $afterchecks] = $this->selftest(true);
            return [
                'success' => false,
                'rolledback' => true,
                'error' => '',
                'checks' => array_merge($checks, $afterchecks),
                'backupfile' => $backupfile,
            ];
        }

        set_config('htaccess_applied', (string)time(), 'tool_oauthmcp');
        return [
            'success' => true,
            'rolledback' => false,
            'error' => '',
            'checks' => $checks,
            'backupfile' => $backupfile,
        ];
    }

    /**
     * Remove the managed block again (or restore the pre-apply state).
     *
     * @return array{success:bool,checks:array}
     */
    public function revert(): array {
        $path = $this->target_path();
        $content = file_exists($path) ? (string)file_get_contents($path) : '';
        $stripped = self::strip_block($content);
        $hadfile = (string)get_config('tool_oauthmcp', 'htaccess_hadfile') !== '0';

        $ok = true;
        if ($stripped === '' && !$hadfile) {
            // We created the file; removing our block leaves nothing worth keeping.
            if (file_exists($path)) {
                $ok = @unlink($path);
            }
        } else {
            $ok = $this->write_atomically($path, $stripped);
        }

        unset_config('htaccess_applied', 'tool_oauthmcp');
        [, $checks] = $this->selftest(true);
        return ['success' => $ok, 'checks' => $checks];
    }

    /**
     * Probe the live site after a change.
     *
     * @param bool $homeonly Only verify the front page still answers (used after restore/revert).
     * @return array{0:bool,1:array} [all critical checks passed, check rows]
     */
    private function selftest(bool $homeonly = false): array {
        global $CFG;

        $checks = [];
        $home = $this->probe($CFG->wwwroot . '/');
        $homeok = $home['code'] >= 200 && $home['code'] < 500;
        $checks[] = [
            'label' => $homeonly ? 'serversetup_check_home_after' : 'serversetup_check_home',
            'ok' => $homeok,
            'detail' => 'HTTP ' . $home['code'],
        ];
        if ($homeonly) {
            return [$homeok, $checks];
        }

        $host = preg_replace('#^(https?://[^/]+).*$#', '$1', $CFG->wwwroot);
        $asmeta = $this->probe($host . '/.well-known/oauth-authorization-server');
        $asdecoded = json_decode($asmeta['body'], true);
        $asok = $asmeta['code'] === 200 && is_array($asdecoded) && !empty($asdecoded['issuer']);
        $checks[] = ['label' => 'serversetup_check_asmeta', 'ok' => $asok, 'detail' => 'HTTP ' . $asmeta['code']];

        $prm = $this->probe($host . '/.well-known/oauth-protected-resource');
        $prmdecoded = json_decode($prm['body'], true);
        $prmok = $prm['code'] === 200 && is_array($prmdecoded) && !empty($prmdecoded['resource']);
        $checks[] = ['label' => 'serversetup_check_prm', 'ok' => $prmok, 'detail' => 'HTTP ' . $prm['code']];

        $server = $this->probe(
            \tool_oauthmcp\local\oauth\urls::resource(),
            '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{}}',
            ['Content-Type: application/json']
        );
        $serverok = $server['code'] === 401
            && preg_match('/WWW-Authenticate:.*resource_metadata=/i', $server['headers']) === 1;
        $checks[] = ['label' => 'serversetup_check_challenge', 'ok' => $serverok, 'detail' => 'HTTP ' . $server['code']];

        // Informational only - see the class docblock.
        $headerprobe = $this->probe(
            \tool_oauthmcp\local\oauth\urls::oauth('headerprobe'),
            null,
            ['Authorization: Bearer serversetup-probe']
        );
        $headerdecoded = json_decode($headerprobe['body'], true);
        $headerok = is_array($headerdecoded) && !empty($headerdecoded['authheader']);
        $checks[] = [
            'label' => 'serversetup_check_authheader',
            'ok' => $headerok,
            'detail' => 'HTTP ' . $headerprobe['code'],
            'informational' => true,
        ];

        return [$homeok && $asok && $prmok && $serverok, $checks];
    }

    /**
     * Restore the pre-apply state of the file.
     *
     * @param string $path Target path.
     * @param string $old Previous content.
     * @param bool $hadfile Whether the file existed before apply().
     * @return void
     */
    private function restore(string $path, string $old, bool $hadfile): void {
        if ($hadfile) {
            $this->write_atomically($path, $old);
        } else if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Write a file via temp-and-rename so a crash cannot leave a torn file.
     *
     * @param string $path Target path.
     * @param string $content New content.
     * @return bool
     */
    private function write_atomically(string $path, string $content): bool {
        $tmp = $path . '.oauthmcp-tmp';
        if (@file_put_contents($tmp, $content) === false) {
            return false;
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            return false;
        }
        return true;
    }

    /**
     * Fetch a URL server-side, headers included, tolerating self-signed dev certificates.
     *
     * ignoresecurity is safe here: every probed URL is derived from wwwroot, never from input.
     *
     * @param string $url The URL.
     * @param string|null $postbody When set, POST this raw body instead of GET.
     * @param string[] $requestheaders Extra request headers.
     * @return array{code:int,headers:string,body:string}
     */
    private function probe(string $url, ?string $postbody = null, array $requestheaders = []): array {
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');

        $curl = new \curl(['ignoresecurity' => true]);
        if (!empty($requestheaders)) {
            $curl->setHeader($requestheaders);
        }
        $options = [
            'CURLOPT_SSL_VERIFYPEER' => 0,
            'CURLOPT_SSL_VERIFYHOST' => 0,
            'CURLOPT_TIMEOUT' => 8,
            'CURLOPT_CONNECTTIMEOUT' => 8,
            'CURLOPT_FOLLOWLOCATION' => 0,
            'CURLOPT_HEADER' => 1,
        ];
        $body = $postbody === null
            ? (string)$curl->get($url, [], $options)
            : (string)$curl->post($url, $postbody, $options);
        $info = $curl->get_info();
        $headersize = (int)($info['header_size'] ?? 0);

        return [
            'code' => (int)($info['http_code'] ?? 0),
            'headers' => substr($body, 0, $headersize),
            'body' => substr($body, $headersize),
        ];
    }
}
