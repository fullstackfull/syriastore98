<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-A-05 — `.text-dark` on a dark surface measured 1.11:1, on the order price.
 * =================================================================================================
 *
 * A VERIFIED BLOCKER from the Phase 2.5 inventory, upheld by adversarial review and then measured
 * in the running admin (dark theme, 1440×1000, merchant session) rather than argued from the CSS:
 *
 *     screen            .text-dark visible   below 4.5:1   inside a modal
 *     /order-grid               14                14             0
 *     /customer-grid             9                 9             0
 *     /dashboard, /order, /customer, /product, /category — none
 *
 *     foreground  rgb(28, 35, 47)    the Bootstrap utility; the host's dark sheets never redefine it
 *     background  rgb(41, 42, 51)    the host's dark card
 *     ratio       1.11:1
 *
 * On every order card that is the customer name and the line reading `Price : $148,00`. The
 * merchant sees a heading and a blank space where the money is.
 *
 * THE RULE IS THEME-SCOPED, NOT PER-SCREEN, because `.text-dark` *means* "dark text" and is wrong
 * on any dark surface — fixing two Blade files would leave the next screen that uses the utility
 * broken. **And the one place it is still right is guarded:** the host keeps `.modal-content`
 * white in dark mode (the measurement behind P2.5-A-01), so dark text inside a modal is correct
 * and the second, more specific rule keeps it. A dark-mode rule that forgets the white surfaces
 * trades one invisible text for another; that is the whole lesson of F126, and this file asserts
 * the guard rather than trusting it.
 */
class DarkTextUtilityContrastTest extends TestCase
{
    /** Measured in the browser, not read from a stylesheet. */
    private const DARK_CARD = [41, 42, 51];
    private const MODAL_BG = [255, 255, 255];

    private const GREY_100 = '#eeeff1';
    private const GREY_900 = '#1a1d23';

    /** The value the utility had, measured live. */
    private const BOOTSTRAP_TEXT_DARK = [28, 35, 47];

    private const SHEETS = [
        'public/css/custom.css',
        'public/css/rtl-custom.css',
    ];

    private function sheet(string $relative): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $relative);
    }

    /** @return array{0:int,1:int,2:int} */
    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    private function luminance(array $rgb): float
    {
        $channel = static function (int $v): float {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel($rgb[0]) + 0.7152 * $channel($rgb[1]) + 0.0722 * $channel($rgb[2]);
    }

    private function contrast(array $a, array $b): float
    {
        $la = $this->luminance($a);
        $lb = $this->luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** The block this slice owns, bounded by its own markers. */
    private function block(string $relative): string
    {
        $css = $this->sheet($relative);

        $marker = strpos($css, 'P2.5-A-05');
        $this->assertNotFalse($marker, "$relative has no P2.5-A-05 block");

        // BACK UP TO THE COMMENT OPENER. The marker sits INSIDE the banner comment, so slicing
        // from it gives a fragment with no comment opener at all — and the comment-stripping
        // regex below then matches nothing, handing every sentence of prose above the rules to
        // the selector parser as if it were CSS. The first draft of this file failed that way,
        // and the second failed to say so because the explanation itself closed the comment.
        $start = strrpos(substr($css, 0, $marker), '/*');
        $this->assertNotFalse($start, "$relative has no comment opener before the P2.5-A-05 marker");

        $end = strpos($css, 'end P2.5-A-05', $start);
        $this->assertNotFalse($end, "$relative has no P2.5-A-05 end marker, so the block is unbounded");

        return substr($css, $start, $end - $start);
    }

    // =============================================================================================
    //  THE PREMISE — the defect was real, and the repair clears the thresholds
    // =============================================================================================

    public function test_premise_the_utility_really_was_invisible_on_the_dark_card(): void
    {
        $ratio = $this->contrast(self::BOOTSTRAP_TEXT_DARK, self::DARK_CARD);

        $this->assertLessThan(1.5, $ratio, sprintf(
            'the measured pairing was %.2f:1; if the host ever fixes its own utility this slice '
            . 'is redundant and should be removed rather than left asserting a dead rule', $ratio));
    }

    public function test_the_replacement_clears_aa_on_the_dark_card(): void
    {
        $ratio = $this->contrast($this->rgb(self::GREY_100), self::DARK_CARD);

        $this->assertGreaterThanOrEqual(4.5, $ratio,
            sprintf('--ss-grey-100 measures %.2f:1 on the host dark card', $ratio));
    }

    public function test_the_modal_guard_keeps_the_white_surface_legible(): void
    {
        $ratio = $this->contrast($this->rgb(self::GREY_900), self::MODAL_BG);

        $this->assertGreaterThanOrEqual(4.5, $ratio,
            sprintf('--ss-grey-900 measures %.2f:1 on the white modal', $ratio));
    }

    // =============================================================================================
    //  BOTH STYLESHEETS — the Arabic admin loads rtl-custom.css INSTEAD of custom.css
    // =============================================================================================

    /**
     * A fix in only one of the two reaches only half the merchants. This has already cost this
     * project a round, so it is asserted rather than remembered.
     */
    public function test_both_stylesheets_carry_the_rule(): void
    {
        foreach (self::SHEETS as $sheet) {
            $block = $this->block($sheet);

            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.text-dark\s*\{/', $block,
                "$sheet does not lift .text-dark in dark mode");

            $this->assertMatchesRegularExpression(
                '/html\[data-ss-theme="dark"\]\s+body\s+\.modal-content\s+\.text-dark\s*\{/', $block,
                "$sheet lifts .text-dark without the white-modal guard, so modal text goes "
                . 'light-on-white — the F126 defect in mirror image');
        }
    }

    public function test_the_two_directions_are_byte_identical(): void
    {
        $this->assertSame($this->block(self::SHEETS[0]), $this->block(self::SHEETS[1]),
            'custom.css and rtl-custom.css have drifted inside the P2.5-A-05 block');
    }

    // =============================================================================================
    //  LIGHT MODE IS UNREACHABLE BY CONSTRUCTION
    // =============================================================================================

    /**
     * Every selector in the block must begin with the dark scope. A rule that reached light mode
     * would paint `.text-dark` near-white on white — the exact inverse of the defect being fixed,
     * which is how P2.5-A-02's first draft went from 3.71:1 to 1.13:1.
     */
    public function test_no_selector_in_the_block_can_match_in_light_mode(): void
    {
        $block = $this->block(self::SHEETS[0]);

        // Strip comments first; the prose above the rules is full of selector-shaped text.
        $rules = preg_replace('#/\*.*?\*/#s', '', $block);

        preg_match_all('/([^{}]+)\{/', $rules, $matches);
        $selectors = array_filter(array_map('trim', $matches[1]));

        $this->assertNotEmpty($selectors, 'premise: the block contains rules at all');

        foreach ($selectors as $selector) {
            foreach (explode(',', $selector) as $one) {
                $one = trim($one);
                if ($one === '') {
                    continue;
                }
                $this->assertStringStartsWith('html[data-ss-theme="dark"]', $one,
                    "`$one` is not scoped to the dark theme, so it also applies in light mode");
            }
        }
    }

    /**
     * AND THE GUARD MUST BE MORE SPECIFIC THAN THE RULE IT GUARDS, or the cascade decides by
     * source order and a later edit that reorders them breaks it silently.
     */
    public function test_the_modal_guard_is_the_more_specific_rule(): void
    {
        $block = preg_replace('#/\*.*?\*/#s', '', $this->block(self::SHEETS[0]));

        $general = strpos($block, 'body .text-dark');
        $guard = strpos($block, 'body .modal-content .text-dark');

        $this->assertNotFalse($general);
        $this->assertNotFalse($guard);
        $this->assertGreaterThan($general, $guard,
            'the modal guard is declared before the rule it overrides; it wins on specificity '
            . 'today, but the order is the thing a reader checks first');
    }
}
