<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * SEVEN OF EIGHT TIMELINE PILLS FAILED AA, IN BOTH THEMES.  P2.5-WAVE-16b
 * =================================================================================================
 *
 * `customer/timeline.blade.php` tags every event with a coloured pill carrying WHITE text -- a
 * date, a product name, a variant. `custom.css` gave each a hard-coded `background: ... !important`
 * and `.my-custom-timeline .tl-box .end-date` set `color: #ffffff`. MEASURED on the running page,
 * white on those grounds:
 *
 *     .add-cart-timeline       #FFC107   1.63:1      .order-timeline        #2196F3   3.12:1
 *     .wishlist-timeline       #32d7a2   1.85:1      .end-date (base)       #FF3A6E   3.45:1
 *     .dlt-wishlist-timeline   #9E9E9E   2.68:1      .last-login-timeline   #607D8B   4.37:1
 *     .register-timeline       #4CAF50   2.78:1      .dlt-cart-timeline     #795548   6.55:1  (only pass)
 *
 * IDENTICAL IN BOTH THEMES, because `!important` hard-codes them -- the dark page changed nothing.
 * The worst, white on amber at 1.63:1, is below even the 3:1 floor WCAG allows for non-text.
 *
 * MEASURING IT AT ALL REQUIRED FIXTURE DATA. `activity_logs` held 0 rows, so no pill rendered and a
 * live reading returned no elements; the seven event types were seeded first (seed-p2dev.php), and
 * only then could this be read rather than guessed.
 *
 * EACH HUE WAS DARKENED UNTIL WHITE PASSES 4.5:1, scaling toward black so the hue family survives
 * and the categories stay distinguishable -- a merchant who learned "green = registered" still
 * reads it. The replacements were COMPUTED, not chosen by eye, and re-measured on the page
 * afterwards: 4.53:1 to 6.55:1, all eight.
 *
 * This test recomputes the ratio from the stylesheet, so a future edit that lightens any pill back
 * fails here rather than on someone's screen.
 */
class TimelinePillContrastTest extends TestCase
{
    private const SHEET = 'public/css/custom.css';
    private const WHITE = [255, 255, 255];

    /** The pills, and the selector each colour must be read from. */
    private const PILLS = [
        '.register-timeline',
        '.last-login-timeline',
        '.wishlist-timeline',
        '.dlt-wishlist-timeline',
        '.add-cart-timeline',
        '.dlt-cart-timeline',
        '.order-timeline',
    ];

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return array{0:int,1:int,2:int} */
    private function hex(string $h): array
    {
        $h = ltrim($h, '#');

        return [hexdec(substr($h, 0, 2)), hexdec(substr($h, 2, 2)), hexdec(substr($h, 4, 2))];
    }

    private function lum(array $rgb): float
    {
        $c = [];
        foreach ($rgb as $v) {
            $v /= 255;
            $c[] = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function ratio(array $a, array $b): float
    {
        $x = $this->lum($a);
        $y = $this->lum($b);
        [$hi, $lo] = $x > $y ? [$x, $y] : [$y, $x];

        return ($hi + 0.05) / ($lo + 0.05);
    }

    /** PREMISE. The pills must still be declared here, or this measures nothing. */
    public function test_every_pill_declares_a_background(): void
    {
        $css = $this->css();

        foreach (self::PILLS as $cls) {
            $this->assertMatchesRegularExpression(
                '/' . preg_quote($cls, '/') . '\s*\{[^}]*background:\s*#[0-9a-fA-F]{6}/',
                $css,
                "premise: {$cls} no longer declares a hex background, so its contrast is not read here"
            );
        }
    }

    /** White text on every pill reaches AA. */
    public function test_white_text_on_every_pill_reaches_aa(): void
    {
        $css = $this->css();

        foreach (self::PILLS as $cls) {
            preg_match('/' . preg_quote($cls, '/') . '\s*\{[^}]*background:\s*(#[0-9a-fA-F]{6})/', $css, $m);
            $ratio = $this->ratio(self::WHITE, $this->hex($m[1]));

            $this->assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf('%s is %s, which gives white text %.2f:1 -- below AA. The pill carries a '
                    . 'date or a product name, so this is TEXT contrast, not decoration.', $cls, $m[1], $ratio)
            );
        }
    }

    /**
     * THE SLAB THE CARDS SIT ON NEEDS A DARK ARM TOO.  P2.5-WAVE-16c
     *
     * Measured on the running page before the fix: in DARK, `.custom-timeline-inner` computed to
     * rgb(249,249,249) -- unchanged from light -- while `.tl-box` correctly computed #292A33 and
     * the body #22242C. A near-white slab on a dark page with dark cards floating on it.
     *
     * The rule must NOT be scoped under `.my-custom-timeline`: that class is conditional on
     * `count($logs) > 0`, so in the empty state the container has no such ancestor and a scoped
     * rule would miss exactly the case P2.5-WAVE-16 added.
     */
    public function test_the_timeline_container_has_a_dark_arm(): void
    {
        $css = $this->css();

        $this->assertMatchesRegularExpression(
            '/html\[data-ss-theme="dark"\]\s+body\s+\.custom-timeline-inner\s*\{[^}]*background:/s',
            $css,
            'the timeline container has no dark arm, so it keeps its near-white #F9F9F9 on the '
                . 'dark page while the cards inside it are dark'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/html\[data-ss-theme="dark"\][^{]*\.my-custom-timeline\s+\.custom-timeline-inner\s*\{/s',
            $css,
            'the container dark arm is scoped under .my-custom-timeline, which is conditional on '
                . 'the log count -- it would miss the empty state entirely'
        );
    }

    /** And the base pill, which every unmodified `.end-date` falls back to. */
    public function test_the_base_end_date_pill_reaches_aa(): void
    {
        preg_match(
            '/\.my-custom-timeline \.tl-box \.end-date \{[^}]*background:\s*(#[0-9a-fA-F]{6})/',
            $this->css(),
            $m
        );
        $this->assertNotEmpty($m, 'premise: the base .end-date background is no longer a hex value');

        $ratio = $this->ratio(self::WHITE, $this->hex($m[1]));
        $this->assertGreaterThanOrEqual(
            4.5,
            $ratio,
            sprintf('the base .end-date pill is %s, giving white %.2f:1. Three product-name pills '
                . 'in the cart events carry no modifier and fall back to exactly this.', $m[1], $ratio)
        );
    }
}
