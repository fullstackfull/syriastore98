<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * P2.5-WAVE-23 — no first-party handler reads `$_SERVER['SERVER_ADDR']` unguarded.
 * ================================================================================================
 *
 * MEASURED: /app-setting returned HTTP 500 with `Undefined array key "SERVER_ADDR"`, found by a
 * phone-width sweep of every destination the merchant's sidebar actually offers. It was one of
 * TWO screens of twenty-one returning 500; the other (/top-all-reports) is a different animal and
 * is deliberately NOT covered here -- see the note at the bottom.
 *
 * WHAT IS AND IS NOT CLAIMED. `SERVER_ADDR` is set by Apache and by nginx + PHP-FPM, so this is
 * not a crash every deployment would see, and it is not claimed to be. It is absent under the PHP
 * built-in server and under the CLI SAPI, so:
 *   - the screen cannot be opened in development at all, and
 *   - any artisan command or queued job that reaches the same code path fatals.
 *
 * THE FIX CANNOT CHANGE BEHAVIOUR WHERE THE KEY EXISTS. `request()->server('SERVER_ADDR')` reads
 * the same `$_SERVER` bag and returns the same value; it returns null instead of raising when the
 * key is missing. AppSettingController::index already handled a null address at the end of the
 * method (`if (empty($serverIp)) { $serverIp = request()->ip(); }`) and already used the safe
 * accessor on one of its five sites -- the code knew the answer and four sites had not been
 * brought into line.
 *
 * COMMENTS ARE STRIPPED BEFORE THE ASSERTION. The docblocks of the two repaired call sites quote
 * the expression they removed, and a byte comparison would fail on that prose. Three tests in this
 * project have now failed on their own explanations; this one is written knowing it.
 *
 * NOT COVERED, and recorded so the gap is visible rather than forgotten: /top-all-reports also
 * returns 500 in this environment, with `no such function: SUBSTRING_INDEX`. That is MySQL-only
 * SQL against a SQLite development database. Production is MySQL, so it is a limit of the
 * measuring environment and NOT a defect -- rewriting a reports query to satisfy a dev database
 * would be a behaviour change bought with nothing. The consequence is recorded instead: that
 * screen cannot be rendered or verified here, so it stays UNVERIFIED rather than claimed.
 */
class ServerAddressGuardTest extends TestCase
{
    public function test_no_first_party_php_reads_the_server_address_unguarded(): void
    {
        $offenders = [];

        foreach ($this->phpFiles(dirname(__DIR__, 3) . '/app') as $path) {
            $code = $this->withoutComments((string) file_get_contents($path));

            if (preg_match('/\$_SERVER\s*\[\s*([\'"])SERVER_ADDR\1\s*\]/', $code)) {
                $offenders[] = str_replace(dirname(__DIR__, 3) . '/', '', $path);
            }
        }

        sort($offenders);

        $this->assertSame([], $offenders,
            "A handler reads \$_SERVER['SERVER_ADDR'] directly. The key is absent under the CLI "
            . "SAPI and under the PHP built-in server, so the read raises instead of returning "
            . "null -- MEASURED as HTTP 500 on /app-setting. Use request()->server('SERVER_ADDR'), "
            . "which reads the same bag and returns null when the key is missing.\n  "
            . implode("\n  ", $offenders));
    }

    /** The repaired sites must still RESOLVE an address, not merely stop crashing. */
    public function test_both_repaired_controllers_still_resolve_the_address(): void
    {
        foreach ([
            'app/Http/Controllers/AppSettingController.php',
            'app/Http/Controllers/StoreController.php',
        ] as $relative) {
            $code = $this->withoutComments(
                (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative));

            $this->assertStringContainsString("request()->server('SERVER_ADDR')", $code,
                $relative . ' no longer resolves the server address at all. The point was to read '
                . 'it safely, not to stop reading it.');
        }
    }

    /** And the null path still ends somewhere usable, which is what makes the screen render. */
    public function test_the_app_setting_screen_still_falls_back_to_the_request_ip(): void
    {
        $code = $this->withoutComments((string) file_get_contents(
            dirname(__DIR__, 3) . '/app/Http/Controllers/AppSettingController.php'));

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*empty\(\s*\$serverIp\s*\)\s*\)\s*\{\s*\$serverIp\s*=\s*request\(\)->ip\(\)/s',
            $code,
            'The final fallback is gone. With it, an unresolved SERVER_ADDR leaves $serverIp null '
            . 'and the view renders an empty address; without it the screen is back to relying on '
            . 'a key that may not exist.');
    }

    private function withoutComments(string $code): string
    {
        $out = '';

        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($token) ? $token[1] : $token;
        }

        return $out;
    }

    /** @return iterable<string> */
    private function phpFiles(string $root): iterable
    {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
