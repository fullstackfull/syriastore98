<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-PKG-A-06 / F-DESIGN-13 — the merchant header stays on screen.
 * ================================================================================================
 *
 * THE DEFECT, measured on /dashboard by scrolling 1100px and reading the header's box, in both
 * directions, over six viewports:
 *
 *     1440  1200  1024  768  576    position: absolute    y = -1085    GONE
 *                            390    position: sticky      y =     0    pinned
 *
 * Ten readings gone, five widths times two directions, and the PHONE is the only one that keeps
 * it. Seven to eight controls leave with it — notifications, the store switcher, Quick Add, the
 * language switcher, the profile menu, the storefront link, POS — on pages two to five screens
 * tall, so a merchant who wants any of them scrolls back to the top first. That the one width
 * where space is scarcest is the one width that keeps the header is the evidence this was never
 * a decision.
 *
 * `fixed`, NOT `sticky`, AND THAT CHOICE IS THE WHOLE FIX. Sticky was written, applied and
 * measured first. It pinned the header and it also moved it from x=255 w=1185 to x=0 w=1440,
 * putting "Hi, Amina Haddad!" at [20-222] behind a sidebar whose right edge is 255. The host
 * positions this header with
 *
 *     LTR   .dash-header { position: absolute; left: 255px;  right: 0 }     style.css:16725
 *     RTL   .dash-header { position: absolute; right: 255px; left: 0 }      style-rtl.css
 *
 * and `absolute` and `fixed` both treat `left`/`right` as a POSITION, while `sticky` treats them
 * as offset constraints and leaves the box at its static place. So `fixed` inherits the geometry
 * the host already computes — the RTL mirror included, and the breakpoints where the sidebar goes
 * off-canvas and those same rules already resolve to x=0 — and changes only whether the box
 * scrolls away.
 *
 * MEASURED AFTER: 24 checks, 0 failed, and every geometry number identical to the baseline row
 * for row — x, width and the gap under the header unchanged at all six widths in both directions.
 * Only `position` and the after-scroll result moved. That is the property this rule is for.
 *
 * IT MUST NOT TOUCH THE 70px EITHER. `.dash-container { position: relative; top: 70px }` exists
 * to clear an out-of-flow header; `fixed` is out of flow, like `absolute`, so that offset stays
 * as correct as it was. The sticky attempt put the header back IN flow and doubled it into a 90px
 * dead band, which is why this test also asserts the design system does not re-point that offset.
 *
 * AND IT MUST NOT REACH THE PHONE. `custom.css` makes the header sticky-in-flow at
 * `max-width: 575.98px` and zeroes the container offset there, and its own comment records why:
 * the icon strip wraps at phone width, so the header's height is NOT a constant and any fixed
 * offset is wrong for some merchant's permission set. This rule is scoped to the exact complement
 * of that query.
 */
class MerchantHeaderStaysOnScreenTest extends TestCase
{
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const HOST_SHEET = 'public/css/custom.css';

    public function test_the_header_is_fixed_above_the_phone_breakpoint(): void
    {
        $block = $this->declarations();

        $this->assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*576px\)\s*\{\s*\[data-ss-admin\]\s*\.dash-header\s*\{'
            . '[^}]*position:\s*fixed/s',
            $block,
            'Without this the header scrolls away at every width from 576 up, taking seven to '
            . 'eight controls with it on pages two to five screens tall.'
        );
    }

    public function test_it_is_not_sticky_because_sticky_was_measured_and_hides_a_control(): void
    {
        $block = $this->declarations();

        $this->assertStringNotContainsString(
            'sticky',
            $block,
            '`position: sticky` pins the header AND moves it to x=0 w=1440, because sticky treats '
            . "the host's `left: 255px` as an offset constraint rather than a position. Measured: "
            . 'the profile control lands at [20-222], behind a sidebar whose right edge is 255.'
        );
    }

    public function test_it_inherits_the_hosts_geometry_instead_of_restating_it(): void
    {
        $block = $this->declarations();

        // The point of choosing `fixed` is that nothing here needs to know a sidebar width. A
        // restated offset would be wrong in one direction or at one breakpoint, which is exactly
        // the trap that made the first attempt fail.
        // A PROPERTY, NOT A SUBSTRING. The first version of this loop looked for the literal
        // "width:" and matched the rule's own `@media (min-width: 576px)`. Each pattern below
        // requires the property to start a declaration, so `min-width` and `max-width` cannot
        // trip it.
        foreach ([
            'left' => '/(?<![\w-])left\s*:/',
            'right' => '/(?<![\w-])right\s*:/',
            'inset-inline' => '/(?<![\w-])inset-inline[\w-]*\s*:/',
            'inset-block' => '/(?<![\w-])inset-block[\w-]*\s*:/',
            'width' => '/(?<![\w-])width\s*:/',
            'margin-inline' => '/(?<![\w-])margin-inline[\w-]*\s*:/',
        ] as $geometry => $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, $block),
                "This rule must not set `{$geometry}`. The host already positions the header, "
                . 'mirrored for Arabic and re-resolved at every breakpoint; `fixed` honours all '
                . 'of it. Restating any of it hard-codes a sidebar width that is not constant.'
            );
        }
    }

    public function test_it_does_not_re_point_the_containers_offset(): void
    {
        // DECLARATIONS ONLY. This file's own commentary quotes `.dash-container { position:
        // relative; top: 70px }` verbatim while explaining why it is left alone, so a search over
        // the raw text matches the explanation and reports the thing it forbids. That is the
        // fourth time in this phase a detector has matched its author's prose.
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $this->read(self::DS_SHEET));

        $this->assertSame(
            0,
            preg_match('/\.dash-container\s*\{[^}]*(inset-block-start|top)\s*:/s', $css),
            'The design system must not move `.dash-container`. Its `top: 70px` clears an '
            . 'out-of-flow header and `fixed` is out of flow, so it is still exactly right. The '
            . 'sticky attempt put the header back in flow and had to zero this; measured, '
            . 'leaving both produced a 90px dead band under the header.'
        );
    }

    public function test_the_phone_arm_is_untouched(): void
    {
        $host = $this->read(self::HOST_SHEET);

        $this->assertMatchesRegularExpression(
            '/@media\s*\(max-width:\s*575\.98px\)\s*\{.*?\.dash-header\s*\{\s*position:\s*sticky;'
            . '\s*inset-block-start:\s*0;\s*\}.*?\.dash-container\s*\{\s*top:\s*0;\s*\}/s',
            $host,
            'Below 576px the header is sticky IN FLOW and the container offset is zero, because '
            . "the icon strip wraps there and the header's height is not a constant. That arm is "
            . 'a previous wave\'s measured decision and this slice does not reach it.'
        );
    }

    public function test_the_block_is_present_and_this_test_is_not_vacuous(): void
    {
        $css = $this->read(self::DS_SHEET);

        $this->assertGreaterThan(200_000, strlen($css));
        $this->assertSame(1, substr_count($css, 'P2.5-PKG-A-06 — THE HEADER STAYS ON SCREEN'));
        $this->assertSame(1, substr_count($css, '/* end P2.5-PKG-A-06 */'));

        $block = $this->declarations();
        $this->assertGreaterThan(60, strlen($block));

        // Exactly one rule, inside exactly one media query.
        $this->assertSame(1, substr_count($block, '@media'));
        $this->assertSame(1, substr_count($block, '.dash-header'));
    }

    /** The region's declarations, with comments stripped: its prose quotes the host rules it answers. */
    private function declarations(): string
    {
        $css = $this->read(self::DS_SHEET);

        $open = strpos($css, 'P2.5-PKG-A-06 — THE HEADER STAYS ON SCREEN');
        $this->assertNotFalse($open, 'The P2.5-PKG-A-06 region opener is missing.');

        $end = strpos($css, '/* end P2.5-PKG-A-06 */', (int) $open);
        $this->assertNotFalse($end, 'The P2.5-PKG-A-06 close marker is missing.');

        $block = substr($css, (int) $open, (int) $end - (int) $open);

        $firstClose = strpos($block, '*/');
        $firstOpen = strpos($block, '/*');
        if ($firstClose !== false && ($firstOpen === false || $firstOpen > $firstClose)) {
            $block = substr($block, $firstClose + 2);
        }

        return trim((string) preg_replace('#/\*.*?\*/#s', '', $block));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
