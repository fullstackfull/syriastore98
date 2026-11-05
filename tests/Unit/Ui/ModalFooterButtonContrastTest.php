<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-17a — the Cancel button in every AJAX modal, on the dark page.
 * ================================================================================================
 *
 * TWO EARLIER SLICES, EACH CORRECT IN ITS OWN SCOPE, COLLIDE ON THIS SURFACE.
 *
 *   P2.5-A-01/A-02 decided the modal STAYS WHITE in dark mode. Deliberate, not an oversight: the
 *   vendored style-dark.css keeps `--bs-modal-bg: #ffffff` (:6238, asserted below) and that slice
 *   chose to re-ink the modal's contents rather than re-theme a surface 70 modules share.
 *   DarkModalSurfaceContrastTest pins that decision with `MODAL_BG = [255,255,255]`.
 *
 *   P2.5-DESIGN-E gave `.btn-secondary` a dark-page ink -- `[data-ss-theme="dark"] .btn-secondary
 *   { color: var(--ss-fg-muted) }`, store-syria-ds.css -- measured against the dark PAGE, where
 *   the host's 25% grey tint composites DARK and muted grey clears AA comfortably.
 *
 * `data-ss-theme` sits on <html>, so the second rule reaches INSIDE the first slice's white modal.
 * There the same tint composites to rgb(218,221,223) and #a7abb2 lands at 1.69:1 -- while the very
 * same button on the LIGHT page measures 12.37:1. The dark theme makes this control worse than no
 * theming at all, which is exactly the shape of the WAVE-16c timestamp defect.
 *
 * MEASURED, not inferred: docs/development/phase-2.5/tools/measure-modal-footer-buttons.cjs opens
 * five real modals in four combinations and reads each button's own colour against its EFFECTIVE
 * composited background. The tint is translucent, so the stylesheet hex is not the rendered colour
 * and arithmetic on the sheet alone would answer the wrong question.
 *
 * WHY THE REPAIR MUST NAME THE MODAL AND NOT THE BUTTON -- the numbers below forbid the obvious
 * simplification of "just use the light-page ink everywhere in dark":
 *
 *                              white modal   dark card   dark page
 *     --ss-fg-muted #a7abb2        1.69  X      4.71 ok     5.09 ok     <- today
 *     --ss-grey-900 #1a1d23       12.37 ok      1.56  X     1.44  X     <- the repair, UNCONFINED
 *
 * THE FIGURES USE HALF-UP ROUNDING, which is what PHP's round() and the browser's Math.round()
 * both do when compositing the tint. A first draft carried figures from a Python helper, whose
 * round() is half-to-EVEN: it produced chip [218,220,222] where the browser paints [218,221,223],
 * and three ratios one hundredth out. The renderer is the authority, not the calculator.
 *
 * So DESIGN-E's choice is RIGHT where it was measured and the repair is right only inside the
 * modal. A rule that is not confined to `.modal-content` trades one failure for two worse ones.
 *
 * AND WHY A LAYER-1 PRIMITIVE. `--ss-fg` resolves to #1a1d23 in a light scope and #e8eaed in a
 * dark one, and the modal is a LIGHT surface inside a DARK scope -- so the semantic token resolves
 * to the dark value (1.13:1) exactly where the light value is wanted. A-02 recorded this trap in
 * its own docblock after it regressed ten views from 3.71:1 to 1.13:1. `--ss-grey-900` is a
 * primitive and never re-themes.
 */
class ModalFooterButtonContrastTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const HOST_DARK_SHEET = 'public/assets/css/style-dark.css';

    /** The host's secondary fill, style-dark.css:3546. A 25% tint, so it COMPOSITES. */
    private const CHIP_TINT = [108, 117, 125];
    private const CHIP_ALPHA = 0.25;

    private const MODAL_BG = [255, 255, 255]; // --bs-modal-bg, style-dark.css:6238
    private const CARD_BG  = [41, 42, 51];    // #292A33
    private const PAGE_BG  = [34, 36, 44];    // #22242C

    private const FG_MUTED_DARK = [167, 171, 178]; // --ss-fg-muted in the dark scope, #a7abb2
    private const FG_DARK       = [232, 234, 237]; // --ss-fg in the dark scope, #e8eaed
    private const GREY_900      = [26, 29, 35];    // --ss-grey-900 #1a1d23, a LAYER-1 primitive

    private const AA = 4.5; // 14px regular labels -- the large-text relief does not apply

    // =============================================================================================
    //  PREMISES — both halves of the collision are real and still in the sheets
    // =============================================================================================

    public function test_premise_the_host_still_keeps_the_modal_white_in_dark_mode(): void
    {
        $this->assertMatchesRegularExpression(
            '/--bs-modal-bg:\s*#ffffff\s*;/i',
            $this->read(self::HOST_DARK_SHEET),
            'The host no longer forces a white modal in dark mode. If the modal is now dark, this '
            . 'whole slice is redundant and should be DELETED rather than left painting a dead rule.');
    }

    public function test_premise_the_secondary_chip_is_a_translucent_tint(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.btn-secondary\s*\{[^}]*--bs-btn-bg:\s*rgba\(108,\s*117,\s*125,\s*0?\.25\)/i',
            $this->read(self::HOST_DARK_SHEET),
            'The secondary fill is no longer a 25% tint, so every composited figure in this file '
            . 'is stale. Re-measure with measure-modal-footer-buttons.cjs before editing the hexes.');
    }

    public function test_premise_design_e_still_inks_the_secondary_button_for_the_dark_page(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s+\.btn-secondary[^{]*\{[^}]*color:\s*var\(--ss-fg-muted\)/i',
            $this->read(self::DS_SHEET),
            'P2.5-DESIGN-E\'s rule is gone. If the secondary button is no longer re-inked for dark, '
            . 'the modal override below has nothing to correct.');
    }

    // =============================================================================================
    //  THE DEFECT, AND WHY EACH SIMPLER REPAIR IS WORSE
    // =============================================================================================

    /** 1.68:1 — and the dark theme is what caused it: the same button is 12.28:1 on the light page. */
    public function test_the_muted_ink_fails_aa_on_the_white_modal(): void
    {
        $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, self::MODAL_BG);

        $this->assertSame([218, 221, 223], $chip);
        $this->assertSame(1.69, round($this->ratio(self::FG_MUTED_DARK, $chip), 2));
        $this->assertLessThan(self::AA, $this->ratio(self::FG_MUTED_DARK, $chip));

        // the light page, untouched by DESIGN-E's dark arm, is fine -- so this is a regression the
        // dark theme introduced, not a defect the host shipped
        $this->assertSame(12.37, round($this->ratio(self::GREY_900, $chip), 2));
    }

    /** DESIGN-E is RIGHT where it was measured, so the repair must not widen onto these. */
    public function test_the_muted_ink_is_correct_on_the_two_dark_grounds(): void
    {
        foreach ([[self::CARD_BG, 4.71], [self::PAGE_BG, 5.09]] as [$ground, $expected]) {
            $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, $ground);
            $ratio = $this->ratio(self::FG_MUTED_DARK, $chip);

            $this->assertSame($expected, round($ratio, 2));
            $this->assertGreaterThanOrEqual(self::AA, $ratio);
        }
    }

    /**
     * THE TRAP. An unconfined `[data-ss-theme="dark"] .btn-secondary { color: var(--ss-grey-900) }`
     * fixes the modal and destroys both dark grounds. This is why the rule names `.modal-content`.
     */
    public function test_an_unconfined_repair_would_destroy_both_dark_grounds(): void
    {
        foreach ([[self::CARD_BG, 1.56], [self::PAGE_BG, 1.44]] as [$ground, $expected]) {
            $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, $ground);
            $ratio = $this->ratio(self::GREY_900, $chip);

            $this->assertSame($expected, round($ratio, 2));
            $this->assertLessThan(self::AA, $ratio);
        }
    }

    /**
     * THE OTHER TRAP, the one A-02 already paid for: a SEMANTIC token resolves to the DARK value
     * inside a dark scope, and the modal is a light surface inside one. 1.14:1.
     */
    public function test_the_semantic_token_would_be_worse_than_doing_nothing(): void
    {
        $chip = $this->composite(self::CHIP_TINT, self::CHIP_ALPHA, self::MODAL_BG);

        $this->assertSame(1.13, round($this->ratio(self::FG_DARK, $chip), 2));
        $this->assertLessThan(
            $this->ratio(self::FG_MUTED_DARK, $chip), $this->ratio(self::FG_DARK, $chip));
    }

    // =============================================================================================
    //  THE RULE ITSELF
    // =============================================================================================

    public function test_the_design_system_sheet_carries_the_confined_override(): void
    {
        $this->assertMatchesRegularExpression(
            '/\[data-ss-theme="dark"\]\s+\.modal-content\s+\.btn-secondary[^{]*\{'
            . '[^}]*color:\s*var\(--ss-grey-900\)\s*!important\s*;/i',
            $this->read(self::DS_SHEET),
            'The modal-scoped secondary override is missing, so the Cancel button in all 68 merchant '
            . 'modals reads at 1.69:1 on the dark page.');
    }

    /** It must name the SURFACE. A rule that only names the theme is the trap tested above. */
    public function test_the_override_is_confined_to_the_modal_surface(): void
    {
        foreach ($this->overrideSelectors() as $selector) {
            $this->assertStringContainsString('.modal-content', $selector,
                'A selector in the WAVE-17a block does not name the modal surface: ' . $selector);
            $this->assertStringContainsString('data-ss-theme="dark"', $selector,
                'A selector in the WAVE-17a block is not scoped to dark: ' . $selector);
        }

        $this->assertNotEmpty($this->overrideSelectors(),
            'The WAVE-17a block parsed to zero selectors, so the two assertions above proved nothing.');
    }

    /** A semantic token here re-themes to the wrong value — see the docblock and the trap test. */
    public function test_the_override_uses_no_token_that_re_themes(): void
    {
        $block = $this->overrideBlock();

        foreach (['--ss-fg', '--ss-fg-muted', '--ss-fg-subtle', '--ss-bg', '--ss-surface'] as $semantic) {
            $this->assertStringNotContainsString('var(' . $semantic . ')', $block,
                $semantic . ' re-themes with the nearest scope; the modal is a LIGHT surface inside '
                . 'a DARK one, so only a layer-1 primitive holds. A-02 paid for this lesson once.');
        }
    }

    // =============================================================================================

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** The WAVE-17a block, delimited by its own markers so a neighbouring rule cannot be read in. */
    private function overrideBlock(): string
    {
        $css = $this->read(self::DS_SHEET);

        $this->assertTrue(
            (bool) preg_match('/\/\* ---- P2\.5-WAVE-17a(.*?)\/\* end P2\.5-WAVE-17a/s', $css, $m),
            'The WAVE-17a markers are missing from ' . self::DS_SHEET . ', so this test cannot tell '
            . 'which rules belong to the slice.');

        return $m[1];
    }

    /** @return list<string> */
    private function overrideSelectors(): array
    {
        // The block capture starts INSIDE the opening marker comment, so its first characters are
        // prose with no `/*` to pair with. Dropping everything up to the first `*/` is what keeps
        // this parser from counting the rationale as a selector -- the "prose counted as code"
        // failure CLAUDE.md names, which this test hit on its first run.
        $block = $this->overrideBlock();
        if (($end = strpos($block, '*/')) !== false) {
            $block = substr($block, $end + 2);
        }

        preg_match_all('/([^{}]+)\{[^}]*\}/s', $block, $m);

        $out = [];
        foreach ($m[1] as $chunk) {
            // and strip any whole comment that sits between two rules
            $chunk = trim(preg_replace('/\/\*.*?\*\//s', '', $chunk));
            foreach (explode(',', $chunk) as $one) {
                $one = trim($one);
                if ($one !== '') {
                    $out[] = $one;
                }
            }
        }

        return $out;
    }

    private function composite(array $tint, float $alpha, array $ground): array
    {
        return array_map(
            static fn ($i) => (int) round($alpha * $tint[$i] + (1 - $alpha) * $ground[$i]),
            [0, 1, 2]);
    }

    private function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(array $a, array $b): float
    {
        $l1 = $this->luminance($a);
        $l2 = $this->luminance($b);

        return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
    }
}
