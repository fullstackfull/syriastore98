<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE PINNED ACTIONS COLUMN COVERS THE HEADING BESIDE IT, AND NOTHING SAYS SO.  P2.5-DESIGN-I
 * =================================================================================================
 *
 * MEASURED at 1440px, light, LTR
 * (docs/development/phase-2.5/tools/measure-pinned-column-overlap.cjs):
 *
 *     /customer   table 1132 in a 1095 port   pinned "Action" covers "Status"       by  7px
 *     /order      table 1199 in a 1095 port   pinned "Action" covers "Order Status" by 23px
 *
 * The merchant reads "STATU" and "ORDER STATU". In RTL both tables fit (1095 in 1095) and nothing
 * is covered, which is why an English-only check would have called this screen clean.
 *
 * THREE THINGS THIS IS NOT, each checked rather than assumed:
 *
 *   NOT a broken pin. `.table-responsive` IS a scrollport — `overflow-x: auto`, and it really
 *   scrolls: driving it to the end moves 37px on /customer and 104px on /order, and the covered
 *   text comes fully clear (coverage +7 -> -30, +23 -> -81). The sticky column is behaving
 *   exactly as specified.
 *
 *   NOT something to un-pin. The pin was added in P2-03-1 on measured evidence: at 390px, 848 of
 *   the Arabic table's 1168 pixels are off-screen, and WITH THE CLASS REMOVED a real click on
 *   Edit at the table's starting scroll position navigated nowhere — in all eight configurations,
 *   1440px included. Removing the pin would trade a cosmetic overlap for an unreachable control.
 *
 *   NOT fixable by narrowing the gutter, and this is where a guess was caught. The sortable
 *   headers carry `padding-right: 30px`, which looks like 10px of slack across five columns —
 *   50px against 37px of overflow. Measured, the sort indicator is two absolutely-positioned
 *   pseudo-elements, `::before` at `right: 13px` and `::after` at `right: 6.5px`, each 11.7px
 *   wide, so the ink runs to `right: 24.7px`. Real slack is 5.3px per header, 25px in total —
 *   LESS than the 37px needed, and a 20px gutter would have clipped the arrows.
 *
 * SO THE FIX IS THE AFFORDANCE, NOT THE GEOMETRY. The column genuinely floats above content that
 * genuinely continues underneath; it was drawn with a 1px hairline, which reads as a table rule
 * rather than as an edge. A soft outward cast is the standard data-grid signal for exactly this,
 * and `box-shadow` changes no layout, so the geometry this test guards cannot move.
 */
class PinnedColumnEdgeAffordanceTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The token is defined in EVERY theme block, including the `prefers-color-scheme` arm.
     *
     * A shadow is the one thing that cannot be carried by a single value: the light sheet casts a
     * tinted near-black at low alpha, and the same value on a near-black page is invisible. The
     * design system already keeps three arms for exactly this reason and the token must exist in
     * all three, or one theme loses the affordance silently.
     */
    public function test_the_edge_token_exists_in_every_theme_arm(): void
    {
        $css = $this->css();

        $this->assertSame(
            3,
            preg_match_all('/--ss-pinned-edge\s*:/', $css),
            'the pinned-edge token must be declared in all three theme arms — `:root`, '
            . '`[data-ss-theme="dark"]` and the `prefers-color-scheme: dark` arm. A shadow tuned '
            . 'for a white page is invisible on a near-black one.'
        );
    }

    /**
     * IT MUST CAST OUTWARD, over the content it covers. An `inset` shadow draws inside the cell
     * and tells the reader nothing about what is underneath it — that is the hairline this
     * replaces, and keeping only the hairline is the failure mode here.
     */
    public function test_the_pinned_column_casts_an_outward_edge_in_both_directions(): void
    {
        $css = $this->css();

        preg_match('/\n\[dir="rtl"\][^{]*\.ss-col-pinned[^{]*\{([^}]*)\}/s', $css, $rtl);
        $this->assertCount(2, $rtl, 'premise: the RTL override for the pinned edge exists');

        // The LTR rule is the one that is NOT the rtl override.
        $ltrAt = strpos($css, '.ss-col-pinned,');
        $this->assertNotFalse($ltrAt, 'premise: the shared pinned-edge rule exists');

        preg_match('/box-shadow:([^;]*);/', substr($css, $ltrAt, 400), $ltr);
        $this->assertCount(2, $ltr, 'premise: that rule sets box-shadow');

        $this->assertStringContainsString(
            'var(--ss-pinned-edge)',
            $ltr[1],
            'the LTR pinned edge must use the token, not a literal shadow.'
        );
        $this->assertStringContainsString(
            'var(--ss-pinned-edge-rtl)',
            $rtl[1],
            '`box-shadow` has NO logical form, so the inline-start cast has to be mirrored '
            . 'explicitly for RTL — the same reason the hairline beside it already has an '
            . 'override. Reusing the LTR token would cast the shadow into the scrolled-away side.'
        );

        // and the hairline is KEPT: it aligns the column, the shadow only signals depth.
        $this->assertStringContainsString('inset', $ltr[1], 'the 1px inset hairline is kept.');
    }

    /** The two tokens must point opposite ways, or the mirror is decorative rather than real. */
    public function test_the_two_edge_tokens_cast_opposite_ways(): void
    {
        $css = $this->css();

        preg_match('/--ss-pinned-edge\s*:\s*(-?[\d.]+)px/', $css, $a);
        preg_match('/--ss-pinned-edge-rtl\s*:\s*(-?[\d.]+)px/', $css, $b);

        $this->assertCount(2, $a, 'premise: the LTR token declares an x offset');
        $this->assertCount(2, $b, 'premise: the RTL token declares an x offset');

        $this->assertLessThan(0, (float) $a[1], 'in LTR the pinned column sits at the trailing edge, so it casts towards the START.');
        $this->assertGreaterThan(0, (float) $b[1], 'in RTL the pinned column sits at the leading edge, so it casts the other way.');
    }

    /**
     * AND NOTHING IN THIS CHANGE MAY MOVE THE TABLE. `box-shadow` is outside the box model, which
     * is the whole reason it is the right tool here — the design system's own note beside this
     * rule records that a BORDER on a `border-collapse: separate` table shifts the cell and knocks
     * the column a pixel out of line with its heading. A geometry property creeping into this rule
     * would do exactly that, silently.
     */
    public function test_the_pinned_edge_rule_sets_nothing_that_affects_layout(): void
    {
        $css = $this->css();
        $at = strpos($css, '.ss-col-pinned,');
        $this->assertNotFalse($at);

        $block = substr($css, $at, 700);
        $rules = (string) preg_replace('#/\*.*?\*/#s', '', $block);

        foreach (['border-width', 'border-left', 'border-right', 'padding', 'width', 'margin', 'inset-inline'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden . ':',
                $rules,
                "the pinned-edge rule must not set `{$forbidden}`. It is an affordance, and the "
                . 'geometry it sits on is measured elsewhere.'
            );
        }
    }
}
