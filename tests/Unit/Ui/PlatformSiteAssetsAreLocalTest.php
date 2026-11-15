<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M5 — THE OFFICIAL SITE FETCHES NOTHING FROM ANOTHER ORIGIN.
 * =================================================================================================
 *
 * The same ratchet as `ThemeEditorAssetsAreLocalTest`, aimed at the surface M5 owns, and for the
 * same measured reason: `resources/views/theme_customize/order.blade.php:89` loaded jQuery UI from
 * the vendor's PUBLIC DEMO SITE into authenticated merchant sessions, and it read as ordinary
 * markup. A review does not catch that; a ratchet does.
 *
 * The platform site raises the bar from "no third-party script" to "no off-origin request at all",
 * which is cheap to hold here and is not cheap elsewhere: these are seven static pages with two
 * local stylesheets, no web font, no icon pack and no analytics. A visitor to the company's own
 * website should not be announced to a third party by the act of reading it.
 *
 * WHAT IT CHECKS, STATED SO A LATER READER CAN TELL WHAT IT DOES NOT.
 *   - `src=` / `href=` to an absolute http(s) URL in a platform view.
 *   - `@import` and `url(...)` to an absolute http(s) URL in the site stylesheet.
 * It does NOT check the design system stylesheet, which the admin also loads and which is not this
 * milestone's to constrain, nor the LandingPage module that still owns `/`.
 */
class PlatformSiteAssetsAreLocalTest extends TestCase
{
    private const VIEWS = 'resources/views/platform';
    private const CSS   = 'public/css/store-syria-platform.css';

    #[Test]
    public function test_no_platform_site_view_loads_an_asset_from_an_external_host(): void
    {
        $dir = base_path(self::VIEWS);
        $this->assertDirectoryExists($dir, 'PREMISE: the platform site views moved.');

        /* RECURSIVE. The documentation centre added `platform/docs/`, and a glob that only looked
           at the top level would have stopped covering the views M6 wrote while still passing. */
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
        sort($files);

        $this->assertNotEmpty($files, 'PREMISE: no platform views found, so this would pass vacuously.');
        $this->assertGreaterThanOrEqual(15, count($files),
            'PREMISE: the site is seven pages, a layout and the documentation centre; '
            . 'fewer means the scan is wrong.');

        $offenders = [];

        foreach ($files as $file) {
            foreach (file($file) as $n => $line) {
                /* `{{ asset(...) }}` never matches, which is the point. */
                if (preg_match('~(?:src|href)\s*=\s*["\']https?://~i', $line)) {
                    $offenders[] = str_replace(base_path() . '/', '', $file)
                        . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            "A platform site view loads an asset from another origin:\n  " . implode("\n  ", $offenders));
    }

    #[Test]
    public function test_the_platform_stylesheet_imports_nothing_and_fetches_nothing(): void
    {
        $path = base_path(self::CSS);
        $this->assertFileExists($path, 'PREMISE: the platform stylesheet moved.');

        /* Comments are stripped first. The file's own header documents this rule in prose, and
           the word "@import" inside that sentence is not a declaration -- the first draft of this
           test failed against its own documentation. */
        $css = preg_replace('~/\*.*?\*/~s', '', file_get_contents($path));

        $this->assertDoesNotMatchRegularExpression('~@import~i', $css,
            'The platform stylesheet must not @import anything, local or remote.');
        $this->assertDoesNotMatchRegularExpression('~url\(\s*["\']?https?://~i', $css,
            'The platform stylesheet must not fetch from another origin.');
    }

    /**
     * EVERY COLOUR IS A TOKEN, SO DARK MODE CANNOT DRIFT.
     *
     * `store-syria-ds.css` redefines its semantic tokens under `[data-ss-theme="dark"]`. A literal
     * colour in the site stylesheet would be correct in one theme and wrong in the other, and the
     * failure is invisible until somebody looks at the page in the other mode — which is how M2
     * shipped 18 contrast failures on greentic.
     */
    #[Test]
    public function test_the_platform_stylesheet_declares_no_literal_colour(): void
    {
        $css = file_get_contents(base_path(self::CSS));

        /* Strip comments first: the file's header prose mentions colours in English and a hash in
           a sentence is not a declaration. */
        $body = preg_replace('~/\*.*?\*/~s', '', $css);

        $literals = [];
        if (preg_match_all('~#[0-9a-fA-F]{3,8}\b~', $body, $m)) {
            $literals = array_merge($literals, $m[0]);
        }
        if (preg_match_all('~\b(?:rgba?|hsla?)\s*\(~i', $body, $m)) {
            $literals = array_merge($literals, $m[0]);
        }

        $this->assertSame([], $literals,
            'The platform stylesheet must take every colour from a --ss-* token so dark mode '
            . 'cannot drift from the design system. Found: ' . implode(', ', $literals));
    }

    /**
     * EVERY TOKEN THE SITE USES IS ONE THE DESIGN SYSTEM ACTUALLY DEFINES.
     *
     * A `var(--ss-typo)` resolves to nothing and the property silently falls back to its initial
     * value — black text, no padding, no radius — with no error anywhere. Measured during M5: the
     * first draft referenced `--ss-success`, which does not exist; the token is `--ss-success-fg`.
     */
    #[Test]
    public function test_every_design_system_token_the_site_references_is_defined(): void
    {
        $site = file_get_contents(base_path(self::CSS));
        $ds   = file_get_contents(base_path('public/css/store-syria-ds.css'));

        preg_match_all('~--ss-[a-z0-9-]+~', $site, $m);
        $referenced = array_unique($m[0]);

        $this->assertNotEmpty($referenced, 'PREMISE: no tokens referenced, so this would pass vacuously.');

        $undefined = array_values(array_filter(
            $referenced,
            static fn (string $t) => preg_match('~^\s*' . preg_quote($t, '~') . '\s*:~m', $ds) !== 1
        ));

        $this->assertSame([], $undefined,
            'The platform stylesheet references design-system tokens that are not defined: '
            . implode(', ', $undefined));
    }
}
