<?php

namespace Tests\Unit\Security;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * P1-SEC-40 INPUT — THE STOREFRONT DEPENDS ON THE MISCONFIGURATION THAT CAUSES THE EXPOSURE.
 *
 * Found while measuring the Phase 03 M1 baseline. Every one of the 210 captures carried exactly one
 * console 404: `/public/js/flipdown.js`. The file EXISTS, at `public/js/flipdown.js`. The reference
 * is `resources/views/front_end/jQueryRoute.blade.php:178`:
 *
 *     <script src="{{ asset('public/js/flipdown.js') }}"></script>
 *
 * `asset()` builds a URL from the document root, so this emits `/public/js/flipdown.js`. Under
 * `php artisan serve` the root is `public/` and that resolves to `public/public/js/…` — absent,
 * hence the 404. On the LIVE HOST the document root is the REPOSITORY ROOT, measured in P1-SEC-40,
 * so the same URL resolves to `<root>/public/js/…`, which exists, and Apache serves it directly
 * (the front-controller rewrite is guarded by `RewriteCond %{REQUEST_FILENAME} !-f`).
 *
 * So the 404 is a development artefact. THE COUPLING IS NOT.
 *
 * The document root being the repository root is exactly WHY the live host serves `/.git/HEAD`,
 * `/.git/config`, `/composer.lock` and `storage/logs/laravel.log`. Moving it to `public/` — the
 * standard Laravel remediation — would break every `asset('public/…')` reference at the same moment
 * it closed the exposure.
 *
 * THIS TEST EXISTS SO THAT WHOEVER CLOSES P1-SEC-40 FINDS OUT BEFORE THEY SHIP IT, and so the count
 * cannot quietly grow in the meantime. It asserts nothing about whether the exposure should be
 * closed; that is deferred to the end-of-Phase-06 review (#120, #137). It is a ratchet and a
 * warning, not a position.
 */
class PublicAssetPrefixCouplingTest extends TestCase
{
    /**
     * The measured count on 2026-11-12. A ratchet: new `asset('public/…')` references deepen the
     * coupling, so adding one must be a deliberate, visible act.
     */
    private const MEASURED = 76;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return list<array{0: string, 1: string}> [file, target] */
    private static function references(): array
    {
        $out = [];

        foreach (['resources/views', 'themes'] as $tree) {
            $dir = self::root() . '/' . $tree;

            if (! is_dir($dir)) {
                continue;
            }

            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));

            foreach ($it as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                    continue;
                }

                $body = file_get_contents($file->getPathname());

                // BLADE COMMENTS ARE STRIPPED FIRST, and five matches were inside them. Three view
                // files carry `{{-- ... asset('public/...') ... --}}` notes DESCRIBING this very
                // pattern (P2-A-01 / F16), so a naive scan counts the documentation as code and
                // reports targets like `public/...` as missing files. Third time this session that
                // a check matched its own explanatory prose.
                $body = preg_replace('{\{\{--.*?--\}\}}s', '', $body);

                if (preg_match_all('{asset\([\'"]public/([^\'"]+)[\'"]}', $body, $m) > 0) {
                    foreach ($m[1] as $target) {
                        $out[] = [str_replace(self::root() . '/', '', $file->getPathname()), $target];
                    }
                }
            }
        }

        return $out;
    }

    /** The premise. A scan that finds nothing would make every assertion below vacuous. */
    #[Test]
    public function test_the_scan_finds_the_references_it_is_about(): void
    {
        $refs = self::references();

        $this->assertNotEmpty($refs,
            "No asset('public/…') reference was found at all. Either they are gone — in which case "
            . 'delete this test and say so in the P1-SEC-40 notes — or the scan is broken.');
    }

    /**
     * THE RATCHET. The count must not grow. If it falls, that is progress and the constant should
     * be lowered in the same change that earned it.
     */
    #[Test]
    public function test_the_public_prefixed_asset_count_does_not_grow(): void
    {
        $refs = self::references();

        $this->assertLessThanOrEqual(self::MEASURED, count($refs),
            'A new asset(\'public/…\') reference was added. Each one resolves ONLY because the live '
            . "document root is the repository root — which is the P1-SEC-40 exposure itself. Use "
            . "asset() without the public/ prefix, or state in the commit why this one has to "
            . 'deepen the coupling. Measured ' . self::MEASURED . ', now ' . count($refs) . '.');

        if (count($refs) < self::MEASURED) {
            $this->assertTrue(true,
                'Fewer references than measured — lower MEASURED in the change that earned it.');
        }
    }

    /**
     * Each target must exist under `public/`. One that does not is a plain broken asset in BOTH
     * environments, not a doc-root artefact, and belongs in the defect inventory.
     */
    #[Test]
    public function test_every_public_prefixed_target_exists_under_public(): void
    {
        // KNOWN ABSENT, MEASURED, AND BOTH OUTSIDE THE STOREFRONT -- so they are recorded rather
        // than fixed here. They 404 wherever the document root is, which makes them ordinary
        // broken assets and not instances of the doubling described in this file's docblock:
        //
        //   public/js/theme-custom.js   -- resources/views/layouts/theme_layout.blade.php:207,
        //                                  reached from the MERCHANT layout, not the storefront
        //   public/images/pos_image1.png -- resources/views/marketplace/landing.blade.php:1093,
        //                                  the marketplace page, not a tenant storefront
        $knownAbsent = ['js/theme-custom.js', 'images/pos_image1.png'];

        $missing = [];

        foreach (self::references() as [$file, $target]) {
            // Blade interpolation cannot be resolved statically. Nor can a path CONCATENATED
            // outside the call -- `asset('…/dash-banner')}}-image.png` names a file that exists as
            // `dash-banner-image.png`, and reporting it as missing was a false positive.
            if (str_contains($target, '{{') || str_contains($target, '$')
                || ! preg_match('{\.[a-z0-9]{2,5}$}i', $target)) {
                continue;
            }

            if (in_array($target, $knownAbsent, true)) {
                continue;
            }

            if (! is_file(self::root() . '/public/' . $target)) {
                $missing[] = "{$file} -> public/{$target}";
            }
        }

        $this->assertSame([], $missing,
            "These asset('public/…') targets do not exist under public/, so they 404 wherever the "
            . "document root is — an ordinary broken asset, not the doubling this file is about: \n  "
            . implode("\n  ", array_slice($missing, 0, 12)));

        // And the known-absent pair must STAY absent, or the list above is stale.
        foreach ($knownAbsent as $target) {
            $this->assertFileDoesNotExist(self::root() . '/public/' . $target,
                "public/{$target} now exists, so remove it from \$knownAbsent.");
        }
    }

    /**
     * And the one the M1 baseline actually tripped over, named so the trail from the console 404 to
     * this test is not lost.
     */
    #[Test]
    public function test_the_flipdown_reference_that_surfaced_this_is_still_the_shape_described(): void
    {
        $blade = file_get_contents(self::root() . '/resources/views/front_end/jQueryRoute.blade.php');

        $this->assertStringContainsString("asset('public/js/flipdown.js')", $blade,
            'The reference changed. If it was corrected to asset(\'js/flipdown.js\'), lower '
            . 'MEASURED and re-read this docblock — the 404 in the M1 evidence then needs a note.');

        $this->assertFileExists(self::root() . '/public/js/flipdown.js',
            'The file is gone, so this is now a real broken asset in production too, not a '
            . 'development artefact.');
    }
}
