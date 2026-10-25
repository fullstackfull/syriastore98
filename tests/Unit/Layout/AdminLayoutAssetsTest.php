<?php

namespace Tests\Unit\Layout;

use Tests\TestCase;

/**
 * P2-A-01 -- every stylesheet the admin layout can choose actually exists where it points.
 * =============================================================================================
 *
 * WHAT THIS CAUGHT. `layouts/app.blade.php` picks `#main-style-link` from four branches, one per
 * (RTL x dark) combination. The Arabic+dark branch asked for
 * `asset('public/assets/css/rtl-style-dark.css')` while the other three used the unprefixed
 * form -- and `asset()` prepends the application url, whose docroot ALREADY IS `public/`, so the
 * segment was doubled and the request 404d. MEASURED against the running application: that url
 * answered 404, `/assets/css/rtl-style-dark.css` answered 200, and the file is on disk.
 *
 * The consequence was not subtle and had shipped: an Arabic merchant with dark mode on got the
 * admin with NO layout stylesheet -- no sidebar, the language menu as a bare bulleted list,
 * every link default blue and underlined. It survived because nobody had rendered that one
 * combination; three of the four were fine.
 *
 * SO THIS IS KEYED ON THE FILESYSTEM, NOT ON THE STRING. A test asserting "the href does not
 * start with public/" would pass for a path that is merely misspelled. This resolves each
 * declared path the way the webserver would and asserts a readable file is there, which is the
 * question that actually matters.
 */
class AdminLayoutAssetsTest extends TestCase
{
    private function layout(): string
    {
        $path = base_path('resources/views/layouts/app.blade.php');
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * Every `asset('...')` in the admin layout, with comment lines stripped first -- a docblock
     * quoting a path would otherwise be scanned as if it were a declaration, which is the
     * comment-proofing rule this project applies to every source-scanning assertion.
     *
     * @return list<string>
     */
    private function declaredAssets(): array
    {
        /*
         * BLOCK comments, not comment-PREFIXED LINES. A line-prefix filter is what this method
         * did first, and it failed immediately: the Blade comment explaining this very defect
         * contains the literal `asset('public/assets/css/rtl-style-dark.css')` in its prose, and
         * its middle lines start with ordinary words rather than a marker. So the test reported
         * the fixed layout as still broken -- prose satisfying a source scan, which is the same
         * failure mode in reverse that this project's ratchets are comment-proofed against.
         *
         * `{{-- --}}` and `<!-- -->` are removed as spans, then any remaining PHP comment lines.
         */
        $source = $this->layout();
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
        $source = (string) preg_replace('/<!--.*?-->/s', '', $source);

        $kept = [];
        foreach (explode("\n", $source) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '/*')) {
                continue;
            }
            $kept[] = $line;
        }

        preg_match_all("/asset\(\s*'([^']+)'/", implode("\n", $kept), $m);

        // Only local paths: an absolute url is somebody else's server.
        return array_values(array_filter(
            array_unique($m[1]),
            static fn (string $p) => ! preg_match('#^https?://#i', $p)
        ));
    }

    public function test_every_stylesheet_the_admin_layout_declares_exists_on_disk(): void
    {
        $declared = $this->declaredAssets();

        // REACHABILITY: an empty list would satisfy the loop vacuously, and the layout has
        // dozens of these.
        $this->assertGreaterThan(20, count($declared),
            'Found only ' . count($declared) . ' asset() paths in the admin layout. The scan has '
            . 'probably stopped matching, which would make this test pass over anything.');

        $missing = [];
        foreach ($declared as $relative) {
            // `asset()` serves from public/, so this is where the webserver looks.
            if (! is_file(public_path($relative))) {
                $missing[] = $relative;
            }
        }

        $this->assertSame([], $missing,
            "The admin layout points at files that are not under public/:\n  - "
            . implode("\n  - ", $missing)
            . "\n\nA `public/` prefix is the usual cause: asset() already serves from public/, so "
            . "`asset('public/x')` resolves to /public/x and 404s. That is what made the Arabic "
            . 'dark admin load no stylesheet at all.');
    }

    /**
     * AND THE FOUR LAYOUT-STYLESHEET BRANCHES SPECIFICALLY, by name. The assertion above would
     * also catch these, but naming them says which four combinations are being promised -- and
     * a future branch added without its file lands here with the combination spelled out.
     */
    public function test_all_four_layout_stylesheet_combinations_resolve(): void
    {
        $combinations = [
            'LTR light' => 'assets/css/style.css',
            'LTR dark' => 'assets/css/style-dark.css',
            'RTL light' => 'assets/css/style-rtl.css',
            'RTL dark' => 'assets/css/rtl-style-dark.css',
        ];

        $layout = $this->layout();

        foreach ($combinations as $label => $relative) {
            $this->assertFileExists(public_path($relative), "{$label}: the stylesheet is missing from public/");

            $this->assertStringContainsString(
                "asset('{$relative}')",
                $layout,
                "{$label}: the admin layout does not declare {$relative} in the unprefixed form. "
                . 'If the path changed, change it here too and say which combination it serves.'
            );
        }
    }
}
