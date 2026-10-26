<?php

namespace Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * F48 / ledger R2 -- reading the environment from a Blade view breaks on a cached deployment.
 *
 * WHY THIS IS A DEFECT AND NOT A STYLE POINT. `php artisan config:cache` is the normal production
 * posture, and once config is cached Laravel does not load `.env` on subsequent requests. So every
 * `env()` call outside `config/` answers with its DEFAULT -- `null` where none is given. The failure
 * is invisible in development and appears only in production, which is exactly how it survived:
 * P2-04-6 found the order page's copy-link button emitting `/<slug>/order/<cipher>` with NO HOST,
 * and only on a cached deployment. Measured for this slice, with the flags set in `.env`:
 *
 *     uncached   env('IS_MOBILE') = 'yes'   config('platform.is_mobile') = 'yes'
 *     CACHED     env('IS_MOBILE') = NULL    config('platform.is_mobile') = 'yes'
 *
 * WHAT THIS RATCHETS. Zero environment reads in first-party Blade views.
 *
 * IT SCANS TWO ROOTS, because the application renders from both. `ViewServiceProvider:101,128` sets
 * `view.paths` to `[resource_path('views'), base_path('themes/<theme_id>/views')]`, so the three
 * committed themes are first-party rendered views too -- and a theme blade is precisely where
 * somebody would reach for `env('APP_URL')` to build an asset URL. `resources/views/vendor/` (seven
 * published packages) is deliberately IN scope: a published view is first-party once published, and
 * if `vendor:publish` ever lands an `env()` call there it should fail here rather than ship.
 *
 * `packages/workdo/` is NOT scanned. It is vendored, CLAUDE.md forbids editing it as a shortcut, and
 * its 45 Blade `env()` calls are recorded in the ledger instead.
 *
 * IT MATCHES MORE THAN `env(`, because `env()` is not the only way to read the environment and the
 * others are worse. `getenv('APP_URL')` returns `false` rather than null with `.env` unloaded, and
 * `\Illuminate\Support\Env::get()` is what somebody who has been told "no `env()` in views" would
 * reach for next. Both are currently absent; the pattern exists so they stay absent.
 *
 * BLADE COMMENTS ARE BLANKED, NOT DELETED, and that distinction is load-bearing twice over.
 * `order/view.blade.php` documents this very fix in a `{{-- --}}` comment that necessarily contains
 * the string `env('APP_URL')`, so a scanner that counted it would make the comment unwritable and
 * the first person to hit that would delete the explanation rather than the call. And deleting the
 * comment text would shift every line number after it -- measured at 56 lines of drift in that one
 * file -- so a failure message would send the reader to the wrong place. Replacing each comment with
 * its own newline count keeps both properties.
 */
class NoEnvCallsInFirstPartyBladeTest extends TestCase
{
    /**
     * Roots the application actually renders from. Relative to the repository root; a root that does
     * not exist is skipped rather than fatal, so this does not break on a checkout without themes.
     */
    private const VIEW_ROOTS = ['resources/views', 'themes'];

    /**
     * `env(` and `getenv(` share one alternation so `\b` still rejects `my_env(`; `Env::get(` and the
     * superglobals are separate because they are not function calls.
     */
    private const ENV_READ = '/\b(?:env|getenv)\s*\(|Env::get\s*\(|\$_(?:ENV|SERVER)\s*\[/';

    /** @return list<string> */
    private function bladeFiles(): array
    {
        $root = dirname(__DIR__, 3);
        $found = [];

        foreach (self::VIEW_ROOTS as $rel) {
            $dir = $root . '/' . $rel;

            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                    $found[] = $file->getPathname();
                }
            }
        }

        sort($found);

        return $found;
    }

    /**
     * Replace each Blade comment with the same number of newlines it contained, so offsets after it
     * still map to the right line.
     */
    private function blankBladeComments(string $source): string
    {
        return (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source
        );
    }

    public function test_the_scan_really_reaches_every_view_root(): void
    {
        $root = dirname(__DIR__, 3);
        $files = $this->bladeFiles();

        // A census that finds nothing passes vacuously. Both roots are asserted by a known file
        // rather than only by a count, so a moved or renamed root fails here and not silently.
        $this->assertFileExists($root . '/resources/views/layouts/app.blade.php');
        $this->assertDirectoryExists($root . '/resources/views/vendor');

        $fromThemes = array_filter($files, static fn (string $f): bool => str_contains($f, '/themes/'));

        $this->assertNotEmpty(
            $fromThemes,
            'No theme blade was scanned. ViewServiceProvider:101,128 renders from themes/<id>/views, '
            . 'so if that directory moved this ratchet silently stopped covering the storefront.'
        );

        $this->assertGreaterThan(
            500,
            count($files),
            'The Blade scan found ' . count($files) . ' files. The real tree is ~591 across both roots '
            . '(547 under resources/views, 44 under themes/), so this is too few to be the whole tree '
            . 'and the ratchet below would be weaker than it reads.'
        );
    }

    public function test_the_comment_blanker_removes_comments_and_preserves_line_numbers(): void
    {
        // Exercised against the real file that documents this defect, so the stripper is not
        // untested code that a later edit could break silently.
        $documented = dirname(__DIR__, 3) . '/resources/views/order/view.blade.php';
        $this->assertFileExists($documented);

        $raw = (string) file_get_contents($documented);

        $this->assertStringContainsString(
            "env('APP_URL')",
            $raw,
            'order/view.blade.php is expected to DOCUMENT this defect in a Blade comment. If that '
            . 'comment was removed, this case no longer proves the blanker works -- point it at '
            . 'another file that mentions an env read in a comment, or drop it deliberately.'
        );

        $blanked = $this->blankBladeComments($raw);

        $this->assertStringNotContainsString(
            "env('APP_URL')",
            $blanked,
            'The Blade-comment blanker did not remove a comment that mentions an env read.'
        );

        $this->assertSame(
            substr_count($raw, "\n"),
            substr_count($blanked, "\n"),
            'Blanking changed the line count, so the line numbers this test reports would be wrong. '
            . 'It must replace each comment with its own newlines, not delete them.'
        );
    }

    public function test_no_first_party_blade_view_reads_the_environment(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];

        foreach ($this->bladeFiles() as $file) {
            // Scanned as ONE string, not line by line, so a call split across lines is still caught.
            $source = $this->blankBladeComments((string) file_get_contents($file));

            if (! preg_match_all(self::ENV_READ, $source, $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($m[0] as [$match, $offset]) {
                $line = substr_count($source, "\n", 0, $offset) + 1;
                $offenders[] = ltrim(str_replace($root, '', $file), '/') . ':' . $line . '  ' . trim($match);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Reading the environment from a Blade view returns NULL (or false, for getenv) under\n"
            . "`php artisan config:cache`, so these read as unset in production while working in\n"
            . "development. Use config() with a key declared in config/, which is the only place env()\n"
            . "is safe to read. F48 / ledger R2.\n\n"
            . implode("\n", $offenders) . "\n"
        );
    }
}
