<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-33 — THE `/public/assets` PATH DOUBLING, FOR THE THIRD TIME.
 * ================================================================================================
 *
 * In Laravel `public/` IS the document root, so a url built as `asset('public/x')` — or written by
 * hand as `site_url + '/public/x'` — resolves to `/public/x` and 404s, while `/x` answers 200 for
 * the very same file on disk. This project has now met that one mistake three times:
 *
 *   P2-A-01  `asset('public/assets/css/rtl-style-dark.css')`     the layout stylesheet. An Arabic
 *            (resources/views/layouts/app.blade.php:168-180)     merchant in dark mode loaded NO
 *                                                                stylesheet at all.
 *   F16      three `asset('public/...')` script tags             `Dropzone is not defined` and
 *            (resources/views/partision/footerlink.blade.php)    `.select2 is not a function` on
 *                                                                EVERY admin page.
 *   WAVE-33  the toast icons, 8 string literals in 3 files       every success and every error
 *                                                                toast painted a BROKEN 48x48 img.
 *
 * The third one was found by watching the network while rendering 46 merchant pages that the
 * coverage matrix had never had rendered: `/public/assets/images/notification/high_priority-48.png`
 * answered 404 on `/dashboard` and `/profile`, and the file is on disk at
 * `public/assets/images/notification/high_priority-48.png`.
 *
 * IT IS VISIBLE, which is why this is a defect and not a wasted request. Read from the plugin's
 * own source rather than assumed: `public/assets/js/plugins/notifier.js:34-42` creates
 * `<img class="img" src=icon>` and appends it when `icon.length > 0`, and
 * `public/assets/css/plugins/notifier.css` sizes `.img` at 48x48 inside a floated `.notifier-img`.
 * Nothing hides it. A merchant whose save failed saw a broken-image glyph beside the reason.
 *
 * WHY A COMMENT STRIP IS NOT OPTIONAL. Two of the files in the tree carry this exact string
 * INSIDE `{{-- --}}` blocks, because that is where the first two repairs recorded their
 * measurements. A `str_contains` over the raw bytes therefore reports the documentation as the
 * defect — the same way a mutation of this phase's ARIA-role assertion once passed because the
 * string it looked for was present in a `//` comment. So comments are stripped before the scan,
 * and the strip is asserted to work by test_the_comment_strip_removes_the_documented_occurrences.
 */
class PublicAssetPathDoublingTest extends TestCase
{
    /** Repository root. */
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** Every first-party file that can build a url. Vendored trees are excluded by construction. */
    private static function sources(): array
    {
        $root = self::root();
        $files = [];

        foreach ([
            $root . '/resources/views',
            $root . '/app',
        ] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile() && preg_match('/\.(blade\.php|php|js)$/', $f->getFilename())) {
                    $files[] = $f->getPathname();
                }
            }
        }

        // `public/js/*.js` TOP LEVEL ONLY. `public/assets/js/plugins/` is the host's and is pinned
        // by hash elsewhere; scanning it would ratchet code this project must not edit.
        foreach (glob($root . '/public/js/*.js') as $f) {
            $files[] = $f;
        }

        return $files;
    }

    /** Blade and block comments removed; what is left is code that really runs. */
    private static function strip(string $src): string
    {
        $src = preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;
        $src = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;

        // A line-comment strip cannot be a blind `//`-to-end-of-line: that would eat the rest of
        // any line containing `http://`. Only a line whose first non-space characters open a
        // comment is dropped, which is what the real cases look like.
        $out = [];
        foreach (explode("\n", $src) as $line) {
            $t = ltrim($line);
            if (str_starts_with($t, '//') || str_starts_with($t, '*') || str_starts_with($t, '#')) {
                continue;
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * SURFACES PHASE 2.5 DOES NOT AUTHORISE, AND WHY THE COUNT IS PINNED RATHER THAN FIXED.
     *
     * The same mistake sits 81 more times in 18 files, and every one of them is on a surface this
     * phase must not touch: the storefront theme layout and `front_end/` (Phase 03), `marketplace/`
     * (Phase 06), and `payment/` + `plans/` + `DPO/`, which are the payment gateway and
     * subscription hand-off documents. CLAUDE.md puts payments among the changes that need an owner
     * decision, so they are DECLARED here, with their counts, instead of being quietly fixed or
     * quietly allowed. A new occurrence anywhere -- in scope or out -- reddens CI.
     */
    private const OUT_OF_SCOPE_PREFIXES = [
        'resources/views/front_end/',
        'resources/views/marketplace/',
        'resources/views/payment/',
        'resources/views/plans/',
        'resources/views/DPO/',
        'resources/views/layouts/theme_layout.blade.php',
    ];

    /** Measured 2026-11-06, not inferred: 81 occurrences in 18 files. */
    private const OUT_OF_SCOPE_OCCURRENCES = 81;
    private const OUT_OF_SCOPE_FILES = 18;

    private static function isOutOfScope(string $rel): bool
    {
        foreach (self::OUT_OF_SCOPE_PREFIXES as $prefix) {
            if (str_starts_with($rel, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /** @return array{0: array<string>, 1: array<string, int>} in-scope offenders, out-of-scope counts */
    private static function scan(): array
    {
        $offenders = [];
        $outOfScope = [];

        foreach (self::sources() as $file) {
            $rel = str_replace(self::root() . '/', '', $file);
            $code = self::strip((string) file_get_contents($file));

            foreach (explode("\n", $code) as $n => $line) {
                if (! preg_match("#(/public/assets/|asset\\(\\s*['\"]public/)#", $line)) {
                    continue;
                }
                if (self::isOutOfScope($rel)) {
                    $outOfScope[$rel] = ($outOfScope[$rel] ?? 0) + 1;
                    continue;
                }
                $offenders[] = $rel . ':' . ($n + 1) . '  ' . trim(substr($line, 0, 110));
            }
        }

        return [$offenders, $outOfScope];
    }

    public function test_no_merchant_admin_source_doubles_the_public_segment_in_an_asset_url(): void
    {
        [$offenders] = self::scan();

        $this->assertSame([], $offenders, "A url that doubles the `public/` segment 404s; the docroot already IS public/.\n"
            . implode("\n", $offenders));
    }

    public function test_the_out_of_scope_occurrences_are_declared_and_do_not_grow(): void
    {
        [, $outOfScope] = self::scan();

        $total = array_sum($outOfScope);
        $report = '';
        arsort($outOfScope);
        foreach ($outOfScope as $rel => $n) {
            $report .= sprintf("\n  %3d  %s", $n, $rel);
        }

        $this->assertSame(self::OUT_OF_SCOPE_FILES, count($outOfScope), 'files carrying the defect changed:' . $report);
        $this->assertSame(self::OUT_OF_SCOPE_OCCURRENCES, $total,
            'The declared out-of-scope count moved. If it went DOWN, delete the surplus from the two'
            . ' constants above. If it went UP, a new 404 was introduced.' . $report);
    }

    /**
     * ANTI-VACUITY. The assertion above also passes if somebody deletes the icon argument, or
     * renames the files out from under it. These two pin the thing the repair must keep working.
     */
    public function test_the_toast_icons_exist_at_the_corrected_path(): void
    {
        foreach (['ok-48.png', 'high_priority-48.png'] as $icon) {
            $this->assertFileExists(
                self::root() . '/public/assets/images/notification/' . $icon,
                'The toast still asks for this file, so it has to be here.'
            );
        }
    }

    public function test_the_admin_toast_still_passes_an_icon_url(): void
    {
        $js = (string) file_get_contents(self::root() . '/public/js/custom.js');
        $js = self::strip($js);

        $this->assertStringContainsString("site_url + '/assets/images/notification/ok-48.png'", $js);
        $this->assertStringContainsString("site_url + '/assets/images/notification/high_priority-48.png'", $js);
    }

    public function test_the_comment_strip_removes_the_documented_occurrences(): void
    {
        // Both files really do carry the string, inside `{{-- --}}`, as the record of the first two
        // repairs. If the strip ever stops working these turn into false offenders.
        foreach ([
            'resources/views/layouts/app.blade.php',
            'resources/views/partision/footerlink.blade.php',
        ] as $rel) {
            $raw = (string) file_get_contents(self::root() . '/' . $rel);
            $this->assertStringContainsString('/public/assets/', $raw, "$rel should still document the defect");
            $this->assertStringNotContainsString('/public/assets/', self::strip($raw), "the strip should remove $rel's comment");
        }
    }
}
