<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-33 — A `.ss-btn` WITH NO COLOUR VARIANT PAINTS NOTHING.
 * ================================================================================================
 *
 * `.ss-btn` is deliberately an EXPLICIT RESET, and `store-syria-ds.css` says so in its own words
 * at the rule: "these components must not inherit Bootstrap's or custom.css's button metrics,
 * because both are loaded on admin pages". It therefore declares `background: transparent` and
 * `border: 1px solid transparent`, and the fill lives entirely on the colour variants --
 * `.ss-btn--primary`, `--secondary`, `--ghost`, `--danger`, `--danger-quiet`.
 *
 * `--sm`, `--lg` and `--icon` are SIZE modifiers. They paint nothing. A class list that carries
 * one of those and no colour variant is still an unpainted button.
 *
 * THE DEFECT THIS PINS, measured on the running application at 1440x1000:
 *
 *     /product/create, LIGHT    "Save"  #ffffff on #ffffff  = 1.00:1
 *     /product/create, DARK     "Save"  #ffffff on #262b33  = 14.23:1
 *
 * The markup was `class="btn btn-primary ss-btn"` -- Bootstrap's variant, not the design system's.
 * `store-syria-ds.css` loads LAST of the five admin sheets, so `.ss-btn`'s reset wiped
 * `.btn-primary`'s fill while `.btn-primary`'s white `color` survived. The sticky action bar is
 * `--ss-surface`, which is #ffffff in light and #262b33 in dark, so the primary SAVE action on the
 * product editor was WHITE ON WHITE in the default theme and perfectly legible in the other one.
 * That asymmetry is why reading the stylesheet could not find it and rendering both themes could.
 *
 * It was exactly two sites out of the 238 `.ss-btn` renderings -- `product/create.blade.php` and
 * `product/edit.blade.php`, the two views that use `<x-ss.actionbar>` with a Save proxy. Every
 * other site already carried a variant, so the house form was never in doubt.
 */
class SsButtonVariantTest extends TestCase
{
    /** Variants that actually paint. Size modifiers are deliberately NOT in this list. */
    private const COLOUR_VARIANTS = ['primary', 'secondary', 'ghost', 'danger-quiet', 'danger'];

    /** Measured 2026-11-06: 238 class lists in resources/views carry `ss-btn`. */
    private const RENDERINGS_FLOOR = 200;

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * BLADE COMMENTS ARE STRIPPED FIRST, and this is not hypothetical tidiness: the fix for this
     * very defect records the OLD class attribute verbatim in a `{{-- --}}` block beside the new
     * markup, so the first run of this test after the fix still failed -- on its own documentation.
     * PublicAssetPathDoublingTest met the same thing from the other direction, and a mutation of
     * this phase's ARIA-role assertion once passed because the string it looked for was sitting in
     * a `//` comment. A scan over raw bytes cannot tell code from the record of a repair.
     */
    private static function strip(string $src): string
    {
        return preg_replace('/\{\{--.*?--\}\}/s', '', $src) ?? $src;
    }

    /** @return array<string> "<relative path>:<line>  <class attribute>" */
    private static function classLists(): array
    {
        $found = [];
        $dir = self::root() . '/resources/views';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $f) {
            if (! $f->isFile() || ! str_ends_with($f->getFilename(), '.blade.php')) {
                continue;
            }
            $rel = str_replace(self::root() . '/', '', $f->getPathname());
            foreach (explode("\n", self::strip((string) file_get_contents($f->getPathname()))) as $n => $line) {
                if (! preg_match_all('/class="([^"]*\bss-btn\b[^"]*)"/', $line, $m)) {
                    continue;
                }
                foreach ($m[1] as $classList) {
                    $found[] = $rel . ':' . ($n + 1) . '  ' . $classList;
                }
            }
        }

        return $found;
    }

    public function test_every_ss_btn_carries_a_colour_variant_that_paints_it(): void
    {
        $unpainted = [];

        foreach (self::classLists() as $entry) {
            $classList = substr($entry, (int) strpos($entry, '  ') + 2);
            $painted = false;
            foreach (self::COLOUR_VARIANTS as $variant) {
                if (preg_match('/\bss-btn--' . preg_quote($variant, '/') . '\b/', $classList)) {
                    $painted = true;
                    break;
                }
            }
            if (! $painted) {
                $unpainted[] = $entry;
            }
        }

        $this->assertSame([], $unpainted,
            "`.ss-btn` declares `background: transparent`, so a class list with no colour variant\n"
            . "renders an unpainted button -- and its inherited text colour can land on a surface of\n"
            . "the same colour. Add one of: ss-btn--" . implode(', ss-btn--', self::COLOUR_VARIANTS) . "\n"
            . implode("\n", $unpainted));
    }

    /**
     * ANTI-VACUITY, TWO WAYS. The scan must really be finding the buttons, and the premise -- that
     * the base paints nothing -- must still hold. If somebody gives `.ss-btn` a default fill, the
     * assertion above stops being the thing that protects these buttons and this test should be
     * rewritten rather than left passing for a reason that no longer applies.
     */
    public function test_the_scan_finds_the_buttons(): void
    {
        $this->assertGreaterThanOrEqual(self::RENDERINGS_FLOOR, count(self::classLists()),
            'The scan found almost no `.ss-btn` class lists, so it is probably broken rather than clean.');
    }

    public function test_the_comment_strip_removes_the_recorded_old_markup(): void
    {
        // The two repaired views carry the old class attribute inside `{{-- --}}`. Without the
        // strip they are reported as offenders, which is how this test first failed after its fix.
        foreach (['resources/views/product/create.blade.php', 'resources/views/product/edit.blade.php'] as $rel) {
            $raw = (string) file_get_contents(self::root() . '/' . $rel);
            $this->assertStringContainsString('class="btn btn-primary ss-btn"', $raw,
                "$rel should still record what the markup used to be");
            $this->assertStringNotContainsString('class="btn btn-primary ss-btn"', self::strip($raw),
                "the strip should remove $rel's comment");
        }
    }

    public function test_the_ss_btn_base_still_paints_nothing_on_its_own(): void
    {
        $css = (string) file_get_contents(self::root() . '/public/css/store-syria-ds.css');

        $open = strpos($css, "\n.ss-btn {");
        $this->assertNotFalse($open, 'the `.ss-btn` base rule has moved or been renamed');
        $block = substr($css, $open, (int) strpos($css, '}', $open) - $open);

        $this->assertStringContainsString('background: transparent', $block,
            'the base no longer resets the background; re-read this test before trusting it');
        $this->assertStringContainsString('border: 1px solid transparent', $block,
            'the base no longer resets the border; re-read this test before trusting it');
    }
}
