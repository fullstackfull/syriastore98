<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * F158 (header) — the shared header's three contrast tokens, and the arithmetic that justifies each.
 * =================================================================================================
 *
 * MEASURED IN A REAL BROWSER FIRST (`docs/development/phase-2/tools/check-header-contrast-live.cjs`),
 * on the dashboard, in all four en/ltr x ar/rtl x light x dark configurations:
 *
 *   before : dark 28 of 30 text nodes below AA  |  light 2 of 30
 *   after  : dark  0 of 30                      |  light 0 of 30
 *
 * THE DROPDOWNS ARE WHY THE FIRST NUMBER IS 30 AND NOT 5. The header's menus are `display:none`
 * until opened, so the probe's first version read the visible bar and found five nodes. Opening
 * them found 23 more dark failures AND BOTH light failures -- the profile menu, the quick-add list,
 * the store switcher and eighteen language entries. A contrast measurement that does not open what
 * the user opens is measuring a different page.
 *
 * THIS TEST IS THE RATCHET, not the measurement: it pins each token in the source and recomputes
 * its WCAG ratio from the two colours, so a revert or a "tidy-up" fails here with the arithmetic
 * attached rather than silently restoring an inaccessible header.
 *
 * WHY THREE TOKENS AND NOT ONE. The header has three distinct failures, on three surfaces, and one
 * of them is not a colour this project may choose:
 *
 *   1. the header BAR's links  -- #808191 on rgb(41,42,51), 3.71:1 -> #9596a8, 5.03:1
 *   2. the DROPDOWN items     -- #808191 on rgb(38,47,63), 3.50:1 -> #9596a8, 4.75:1
 *   3. the brand AS TEXT      -- var(--theme-color) on white, 2.59:1 -> --ss-brand-ontext
 *
 * The third is the merchant's own brand colour -- ten presets plus a picker -- so there is no
 * single safe value and the owner's F158 condition protects it by name. It reuses P2-A-03's
 * existing `--ss-brand-ontext`, the merchant's colour darkened only until it reaches AA, computed
 * once in `layouts/app.blade.php`. No brand colour changes.
 *
 * AND THAT THIRD TOKEN IS LIGHT-MODE ONLY, which is a measured correction rather than caution:
 * `--ss-brand-ontext` is darkened against the LIGHT page, so on a dark surface it is the wrong
 * direction entirely -- applying it unconditionally took dark mode from 0 failures back to 2, at
 * 2.72:1. The bright brand colour is already compliant there (5.34:1), so dark mode must not get
 * the darkened variant.
 */
class HeaderContrastTokensTest extends TestCase
{
    private const DARK_SHEETS = [
        'public/assets/css/style-dark.css',
        'public/assets/css/rtl-style-dark.css',
    ];

    private const CUSTOM_SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    /** The dark header bar's own background, read from the running page. */
    private const BAR_BG = [41, 42, 51];

    /** The dark dropdown surface's background, read from the running page. */
    private const MENU_BG = [38, 47, 63];

    private const TOKEN = [149, 150, 168];   // #9596a8

    // ------------------------------------------------------------------ the arithmetic

    public function test_the_token_clears_aa_on_the_dark_header_bar(): void
    {
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->ratio(self::TOKEN, self::BAR_BG),
            'the header bar token must clear AA for 14px text'
        );
    }

    public function test_the_token_clears_aa_on_the_dark_dropdown_surface(): void
    {
        $this->assertGreaterThanOrEqual(
            4.5,
            $this->ratio(self::TOKEN, self::MENU_BG),
            'the same token has to clear AA on the dropdown surface too, which is darker'
        );
    }

    /**
     * THE PREMISE, so the two cases above cannot be satisfied by a colour that was always fine:
     * the value being replaced really does fail on both surfaces.
     */
    public function test_the_replaced_colour_really_was_below_aa_on_both_surfaces(): void
    {
        $old = [128, 129, 145];   // #808191

        $this->assertLessThan(4.5, $this->ratio($old, self::BAR_BG));
        $this->assertLessThan(4.5, $this->ratio($old, self::MENU_BG));
    }

    // ------------------------------------------------------------------ the source

    public function test_both_dark_stylesheets_carry_the_header_bar_token(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertMatchesRegularExpression(
                '/\.dash-header\s+\.dash-head-link\s*\{[^}]*color:\s*#9596a8/i',
                $css,
                $sheet . ' no longer sets the AA-compliant colour on the header bar links'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\.dash-header\s+\.dash-head-link\s*\{[^}]*color:\s*#808191/i',
                $css,
                $sheet . ' has gone back to the 3.71:1 grey on the header bar links'
            );
        }
    }

    public function test_both_dark_stylesheets_carry_the_dropdown_token_confined_to_the_header(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertMatchesRegularExpression(
                '/\.dash-header\s+\.dropdown-item\s*\{[^}]*color:\s*#9596a8/i',
                $css,
                $sheet . ' no longer sets the AA-compliant colour on the header dropdown items'
            );
        }
    }

    /**
     * CONFINEMENT IS THE POINT OF THAT SELECTOR, not a stylistic choice. A bare `.dropdown-item`
     * rule would restyle every dropdown in the application, which the owner's F158 condition
     * forbids, and `.dash-h-dropdown` would reach eight vendored WorkDo Blade views. `.dash-header`
     * appears in three first-party views and in no WorkDo view at all.
     */
    public function test_the_dropdown_token_is_not_applied_application_wide(): void
    {
        foreach (self::DARK_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertDoesNotMatchRegularExpression(
                '/(^|\n)\s*\.dropdown-item\s*\{[^}]*color:\s*#9596a8/i',
                $css,
                $sheet . ' applies the header token to EVERY dropdown in the application'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\.dash-h-dropdown\s+\.dropdown-item\s*\{[^}]*color:\s*#9596a8/i',
                $css,
                $sheet . ' anchors the token on .dash-h-dropdown, which eight vendored WorkDo '
                . 'Blade views also carry'
            );
        }
    }

    public function test_the_brand_as_text_rule_reuses_the_existing_derived_property(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $css = $this->read($sheet);

            $this->assertStringContainsString(
                'body .dash-header .text-primary',
                $css,
                $sheet . ' no longer carries the header brand-as-text rule'
            );
            $this->assertMatchesRegularExpression(
                '/\.dash-header\s+\.text-primary\s*\{[^}]*var\(--ss-brand-ontext/',
                $css,
                $sheet . ' must reuse P2-A-03\'s derived property rather than hard-coding a colour: '
                . 'the brand is the merchant\'s, with ten presets and a picker'
            );
        }
    }

    /**
     * AND IT MUST STAY LIGHT-MODE ONLY. Without the guard, dark mode went from 0 failures to 2 at
     * 2.72:1, because the derived property is darkened against the light page.
     */
    public function test_the_brand_as_text_rule_is_scoped_to_light_mode(): void
    {
        foreach (self::CUSTOM_SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/html:not\(\[data-ss-theme="dark"\]\)\s+body\s+\.dash-header\s+\.text-primary/',
                $this->read($sheet),
                $sheet . ' applies the light-mode brand-text colour in dark mode too, where the '
                . 'bright brand colour already measures 5.34:1 and the darkened one measures 2.72:1'
            );
        }
    }

    /** The derived property is still produced, or every rule above falls back to the brand colour. */
    public function test_the_derived_property_is_still_computed_in_the_layout(): void
    {
        $layout = $this->read('resources/views/layouts/app.blade.php');

        $this->assertStringContainsString(
            "'--ss-brand-ontext'",
            $layout,
            'the layout no longer computes --ss-brand-ontext, so the header rule silently falls '
            . 'back to the 2.59:1 brand colour'
        );
    }

    // ------------------------------------------------------------------ helpers

    private function read(string $relative): string
    {
        $path = __DIR__ . '/../../../' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** WCAG 2.x relative luminance and contrast ratio, the same arithmetic the layout script uses. */
    private function ratio(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }
}
