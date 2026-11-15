<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * PHASE 03 M4-9 — NO THEME-EDITOR VIEW MAY LOAD AN ASSET FROM A THIRD PARTY.
 * =================================================================================================
 *
 * `resources/views/theme_customize/order.blade.php:89` loaded jQuery UI from
 * `https://demo.workdo.io/vcardgo-saas/custom/libs/jquery-ui/jquery-ui.js` -- the VENDOR'S PUBLIC
 * DEMO SITE, serving executable JavaScript into an authenticated merchant's admin page on every
 * view of that screen. Whoever controls that host controls what runs in the session of every
 * merchant who opens section ordering.
 *
 * It was not even necessary: the identical library ships at `public/js/jquery-ui.min.js`, and the
 * sibling view in the SAME DIRECTORY -- `customize-script.blade.php:1` -- already loads it with
 * `asset('js/jquery-ui.min.js')`. One file out of step, which is exactly the kind of thing a
 * ratchet is for and a review is not: it reads as ordinary markup.
 *
 * SCOPE, DELIBERATELY NARROW. This asserts only the theme-editor views, which M4 owns. The wider
 * admin legitimately loads from payment gateways, Google Fonts and CDNs -- measured: 22 `workdo.io`
 * references and a dozen gateway hosts across `resources/views/` -- and a blanket rule would be a
 * much larger change reaching into payment surfaces, which is not this milestone's business. The
 * precedent is `ClaudeConfigurationTest`'s host ratchet for the design skills: pin the surface you
 * own, so a new host fails CI and somebody reads it.
 */
class ThemeEditorAssetsAreLocalTest extends TestCase
{
    private const DIR = 'resources/views/theme_customize';

    #[Test]
    public function test_no_theme_editor_view_loads_an_asset_from_an_external_host(): void
    {
        $dir = base_path(self::DIR);

        $this->assertDirectoryExists($dir, 'PREMISE: the theme editor views moved.');

        $files = glob($dir . '/*.blade.php') ?: [];

        $this->assertNotEmpty($files,
            'PREMISE: no theme editor views were found, so this test would pass vacuously.');

        $offenders = [];

        foreach ($files as $file) {
            foreach (file($file) as $n => $line) {
                /* `src=` or `href=` pointing at an absolute http(s) URL. A Blade expression such as
                 * `{{ asset(...) }}` never matches, which is the point. */
                if (preg_match('~(?:src|href)\s*=\s*["\']https?://~i', $line)) {
                    $offenders[] = basename($file) . ':' . ($n + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertSame([], $offenders,
            "A theme-editor view loads an asset from a third-party host:\n  "
            . implode("\n  ", $offenders)
            . "\n\nWhoever controls that host controls what runs in an authenticated merchant's "
            . 'session. Vendor the file under public/ and reference it with asset(), as '
            . 'customize-script.blade.php already does.');
    }

    /** The replacement must actually be there, or the test above passes by the file being empty. */
    #[Test]
    public function test_the_order_screen_still_loads_jquery_ui_locally(): void
    {
        $order = file_get_contents(base_path(self::DIR . '/order.blade.php'));

        $this->assertStringContainsString("asset('js/jquery-ui.min.js')", $order,
            'The order screen no longer loads jQuery UI at all. The sortable list depends on it, '
            . 'so removing the external script without vendoring the local one breaks the feature '
            . 'rather than securing it.');

        $this->assertFileExists(public_path('js/jquery-ui.min.js'),
            'The local jQuery UI referenced by the order screen is not present.');
    }
}
