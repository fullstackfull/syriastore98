<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * SEVEN STATUS CHIPS, ONE BACKGROUND, NO DISTRIBUTION.  P2.5-WAVE-3b
 * =================================================================================================
 *
 * MEASURED on /dashboard at 1440px, dark:
 *
 *     'Pending (55)'         85x32   bg rgb(33,37,44)   fg rgb(120,148,198)
 *     'Confirmed Order (2)'  103x32  bg rgb(33,37,44)   fg rgb(120,148,198)
 *     'Order Shipped (1)'    129x32  bg rgb(33,37,44)   fg rgb(120,148,198)
 *     'Picked Up (0)'        105x32  bg rgb(33,37,44)   fg rgb(195,198,204)
 *     ...
 *     DISTINCT chip backgrounds across all seven: ONE.
 *
 * So "Pending (55)" and "Order Shipped (1)" are pixel-for-pixel identical apart from their text,
 * though they are the extremes of the distribution. The only visual distinction the card makes is
 * zero versus non-zero. A card titled "Orders by status" showed no distribution at all -- the
 * merchant has to read seven numbers and rank them in their head.
 *
 * THE BAR IS UNDER THE CHIP, NOT BEHIND IT, AND THAT IS A CONTRAST DECISION. A proportional
 * BACKGROUND fill is the obvious treatment and it puts the label on two different surfaces at
 * once -- part of the text over the fill, part over the base -- so the chip's text contrast would
 * have to pass against BOTH, in both themes, for every tint. A 3px meter along the bottom edge
 * leaves every text/background pair exactly as measured and verified in earlier packages, and
 * still answers "which of these is big" before a single number is read.
 *
 * IT IS NOT DECORATION, so WCAG 2.2 SC 1.4.11 applies: the bar carries information, and it must
 * reach 3:1 against the chip surface it sits on. Both themes are asserted below against the
 * measured surfaces.
 *
 * THE NUMBERS STAY. The bar is redundant encoding, not a replacement -- SC 1.4.1 and the
 * directive's own rule that colour must never be the only carrier. Removing "(55)" and leaving a
 * bar would be a regression wearing a chart.
 */
class StatusChipMagnitudeTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';
    private const CHIP = 'resources/views/components/ss/chip.blade.php';
    private const OVERVIEW = 'resources/views/dashboard/overview.blade.php';
    private const OPENER = '/* ---- P2.5-WAVE-3b: the status chip carries its magnitude ----';

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    private function decls(): string
    {
        $css = $this->read(self::SHEET);
        $at = strpos($css, self::OPENER);
        $this->assertNotFalse($at, 'the P2.5-WAVE-3b section opener is missing');
        $next = strpos($css, '/* ---- ', $at + strlen(self::OPENER));
        $block = $next === false ? substr($css, $at) : substr($css, $at, $next - $at);

        return (string) preg_replace('#/\*.*?\*/#s', '', $block);
    }

    /** sRGB relative luminance, WCAG 2.x. */
    private function lum(string $hex): float
    {
        $hex = ltrim($hex, '#');
        $c = [];
        foreach ([0, 2, 4] as $i) {
            $v = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(string $a, string $b): float
    {
        $x = $this->lum($a);
        $y = $this->lum($b);
        [$hi, $lo] = $x > $y ? [$x, $y] : [$y, $x];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    public function test_the_section_carries_declarations_not_only_prose(): void
    {
        $this->assertGreaterThan(8, substr_count($this->decls(), ';'), 'the WAVE-3b section is all comment');
    }

    /** The component can carry a share, and only renders the meter when one is given. */
    public function test_the_chip_accepts_a_share_and_stays_unchanged_without_one(): void
    {
        $chip = $this->read(self::CHIP);

        $this->assertMatchesRegularExpression(
            "/'share'\s*=>\s*null/",
            $chip,
            'the chip has no share prop, so nothing can express magnitude'
        );
        $this->assertStringContainsString(
            '--ss-chip-share',
            $chip,
            'the share never reaches CSS'
        );
        // THE HOOK IS THE STYLE ATTRIBUTE, NOT A CLASS (WAVE-9b). WAVE-3b first used an
        // `ss-chip--metered` class and it broke two Feature tests that match the chip's exact
        // class string -- a contract status-badge.blade.php documents. The meter is now driven
        // by a `--ss-chip-share` custom property emitted only when a share is passed, so a
        // share-less chip is byte-identical to before and the class string is untouched.
        $this->assertStringContainsString(
            "is_null(\$ssShare) ? '' :",
            $chip,
            'a chip with no share must emit no style attribute, or every chip in the product '
                . 'gets a (0%) meter including the filter toggles'
        );

        // AGAINST THE CODE, NOT THE COMMENT. The chip's own doc-comment explains the WAVE-9b
        // history by NAMING `ss-chip--metered`, so a raw not-contains check fails on the prose
        // describing the very thing it forbids -- the sixth time in this project a detector has
        // counted documentation as code. Strip the Blade `{{-- --}}` and PHP `/* */` comments
        // first; what is left is the markup and the @php logic.
        $chipCode = (string) preg_replace(['/\{\{--.*?--\}\}/s', '~/\*.*?\*/~s'], '', $chip);
        $this->assertStringNotContainsString(
            'ss-chip--metered',
            $chipCode,
            'the meter is back to a class, which changes the chip class string the dashboard and '
                . 'finance Feature tests match exactly'
        );
    }

    /** The dashboard actually passes it, or the component change is dead code. */
    public function test_the_dashboard_passes_a_share_for_every_fulfilment_chip(): void
    {
        $overview = $this->read(self::OVERVIEW);

        $this->assertMatchesRegularExpression(
            '/:share=/',
            $overview,
            'the overview renders chips without a share, so the meter never appears'
        );
    }

    /** The meter is a bottom bar, not a background fill that would move the text onto two surfaces. */
    public function test_the_meter_is_an_edge_bar_and_not_a_background_fill(): void
    {
        $decls = $this->decls();

        // TOLERATES A FALLBACK. The first version required `var(--ss-chip-share)` with the
        // closing paren immediately after the name, and failed against
        // `var(--ss-chip-share, 0%)` -- which is the BETTER declaration, since a metered chip
        // whose style attribute is stripped then renders no bar rather than an auto-width one.
        $this->assertMatchesRegularExpression(
            '/\.ss-chip\[style\*="--ss-chip-share"\]::after[^{]*\{[^}]*width:\s*var\(--ss-chip-share[,)]/s',
            $decls,
            'the meter does not take its width from the share'
        );
        $this->assertMatchesRegularExpression(
            '/\.ss-chip\[style\*="--ss-chip-share"\]::after[^{]*\{[^}]*height:\s*\d/s',
            $decls,
            'the meter has no explicit height, so it is not an edge bar'
        );
        // A background fill on the chip itself is the treatment this deliberately avoids.
        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-chip\[style\*="--ss-chip-share"\]\s*\{[^}]*linear-gradient/s',
            $decls,
            'the chip is filled with a gradient, which puts its label on two surfaces at once'
        );
    }

    /** It mirrors. `left` would strand every meter on the wrong edge in Arabic. */
    public function test_the_meter_is_anchored_logically_so_it_mirrors(): void
    {
        $decls = $this->decls();

        $this->assertStringContainsString(
            'inset-inline-start',
            $decls,
            'the meter is anchored with a physical property, so it grows from the wrong edge in RTL'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-chip\[style\*="--ss-chip-share"\]::after[^{]*\{[^}]*[^-]left:/s',
            $decls,
            'a physical `left` on the meter hard-codes LTR'
        );
    }

    /**
     * SC 1.4.11: the bar carries information, so it needs 3:1 against the surface it sits on.
     *
     * SURFACES MEASURED, NOT ASSUMED. The first version of this file asserted against a light
     * chip surface of #f1f3f8 -- a plausible guess that was simply wrong. `--ss-neutral-bg`
     * resolves to `var(--ss-grey-100)`, and read out of the running page it is **#eeeff1** in
     * light and **#21252c** in dark. A contrast assertion against an invented surface measures
     * nothing, however exact its arithmetic looks.
     */
    public function test_the_meter_reaches_three_to_one_against_the_chip_in_both_themes(): void
    {
        $decls = $this->decls();

        preg_match('/--ss-chip-meter:\s*(#[0-9a-fA-F]{6})/', $decls, $light);
        $this->assertNotEmpty($light, 'the light meter colour is not defined as a token');

        preg_match(
            '/\[data-ss-theme="dark"\][^{]*\{[^}]*--ss-chip-meter:\s*(#[0-9a-fA-F]{6})/s',
            $decls,
            $dark
        );
        $this->assertNotEmpty($dark, 'the dark meter colour is not defined, so it keeps the light value');

        $lightRatio = $this->ratio($light[1], '#eeeff1');
        $darkRatio = $this->ratio($dark[1], '#21252c');

        $this->assertGreaterThanOrEqual(
            3.0,
            $lightRatio,
            sprintf('the light meter measures %.2f:1 against the chip, below SC 1.4.11\'s 3:1', $lightRatio)
        );
        $this->assertGreaterThanOrEqual(
            3.0,
            $darkRatio,
            sprintf('the dark meter measures %.2f:1 against the chip, below SC 1.4.11\'s 3:1', $darkRatio)
        );
    }

    /** The count stays. A bar replacing the number would be colour as the only carrier. */
    public function test_the_numeric_count_is_still_rendered(): void
    {
        $chip = $this->read(self::CHIP);

        $this->assertStringContainsString(
            '{{ $count }}',
            $chip,
            'the numeric count is gone, leaving the bar as the only carrier of magnitude'
        );
    }
}
