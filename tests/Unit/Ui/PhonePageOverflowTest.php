<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-23 — no merchant list screen scrolls the PAGE sideways at 320px.
 * ================================================================================================
 *
 * Wave 21 drew the distinction this rests on: a TABLE scrolling inside its own container is
 * Bootstrap behaving as designed, while the DOCUMENT being wider than the window is always a
 * defect. Wave 21 measured four screens and found none. A sweep of all twenty-four destinations
 * the merchant's sidebar actually offers found FIVE:
 *
 *     /product-brand                 scrollWidth 550   against a 320px viewport
 *     /category-order-sale-reports               354
 *     /Brand-order-sale-reports                  354
 *     /product-order-sale-reports                354
 *     /product-label                             342
 *
 * FOUR HYPOTHESES, THREE OF THEM REFUTED BY MEASURING. Written down because each looked right:
 *
 *   1. the empty `.notifier-container`  -- `position: fixed`, `width: 400px; max-width: 98%`,
 *      ZERO children and 8px tall on every screen, resolving its percentage against a containing
 *      block that tracks the document. Plausible, and hiding it changed scrollWidth by nothing.
 *      A clamp for it was written and then REMOVED: ToastErrorPersistenceContractTest asserts
 *      that no stylesheet styles the notifier, because a toast restyle is a separate sequencing
 *      item needing a dark variant too. The observation is handed to that slice instead -- with
 *      a toast actually present, a 400px container in a 320px window puts part of it off-screen.
 *   2. the chart chips -- `button.ss-chip.chart-data`, four of them at left=55 width=299. Real
 *      overflow, and clamping them left scrollWidth at 354 EXACTLY. A passenger.
 *   3. the pinned actions column -- `.ss-col-locked` is `position: sticky`, and sticky boxes do
 *      contribute scrollable overflow from their unshifted position. `position: static
 *      !important` on them left /product-brand at 550.
 *   4. WHAT IT ACTUALLY WAS, twice over:
 *        - the reports screens: `.ss-field` rendering 299px wide inside a 250px `.ss-toolbar`
 *          with `overflow-x: visible`. A flex item defaults to `min-width: auto` and the field
 *          holds a date input with an intrinsic width, so it refused to shrink.
 *        - /product-brand and /product-label: scrollable overflow escaping `.table-responsive`
 *          with NOT ONE visible box past the edge -- the notifier was 304 wide inside the window,
 *          the scroller was scrolling internally (clientWidth 250, scrollWidth 634), body and
 *          footer were both 320. `contain: paint` on the scroller took 550 -> 320 and 342 -> 320
 *          and left /product, which never overflowed, at 320.
 *
 * AFTER: twenty of twenty list screens report no page overflow, in light and dark and in both
 * directions, and the table still scrolls (scrollLeft set to 200 and read back), the pinned
 * action column is still on screen, and all five table buttons are reachable.
 */
class PhonePageOverflowTest extends TestCase
{
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    /** Each rule, with the measurement that put it there. */
    private const RULES = [
        '.ss-toolbar .ss-field' => 'the report toolbar field can shrink below its content',
        '.ss-dt-card-body > .table-responsive { contain: paint; }' => 'scrollable overflow stops escaping the table scroller',
    ];

    public function test_every_overflow_rule_is_present_in_both_mirrored_sheets(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->declarations($sheet);

            foreach (self::RULES as $needle => $why) {
                $this->assertStringContainsString($needle, $css,
                    $sheet . ' no longer carries `' . $needle . '` — ' . $why . '. See this '
                    . 'file\'s docblock for the measurement it came from.');
            }
        }
    }

    /**
     * The containment rule is the one that could regress something, so it is pinned to the
     * narrowest selector that was measured, not to `.table-responsive` everywhere.
     */
    public function test_the_containment_is_scoped_to_the_design_system_table_card(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->declarations($sheet);

            /* The SELECTOR is what matters, not the substring -- the first version of this test
               used `assertStringNotContainsString('.table-responsive { contain:')` and failed on
               the scoped rule itself, because `.ss-dt-card-body > .table-responsive { contain:`
               ends with exactly that text. */
            preg_match_all('/([^{}]+)\{[^}]*\bcontain\s*:/', $css, $m);

            foreach ($m[1] as $selector) {
                $selector = trim(preg_replace('/\s+/', ' ', $selector));

                $this->assertStringContainsString('ss-', $selector,
                    $sheet . ": `contain` is declared on `{$selector}`, which carries no design-"
                    . 'system scope. Containment makes the element a containing block for '
                    . 'absolutely positioned descendants, and only the `.ss-dt-card-body` '
                    . 'scroller was measured for that.');
            }
        }
    }

    /** Still mirrored, which is the property a single-sheet fix always breaks. */
    public function test_the_phone_block_is_byte_identical_in_both_sheets(): void
    {
        $blocks = [];

        foreach (self::SHEETS as $sheet) {
            $source = (string) file_get_contents(dirname(__DIR__, 3) . '/' . $sheet);
            $start = strpos($source, 'P2.5-WAVE-22');
            $end = strpos($source, '/* end P2.5-WAVE-22 */');
            $this->assertNotFalse($start, $sheet . ': the phone block is gone.');
            $this->assertNotFalse($end, $sheet . ': the phone block has no end marker.');
            $blocks[] = substr($source, $start, $end - $start);
        }

        $this->assertSame($blocks[0], $blocks[1],
            'The phone block differs between custom.css and rtl-custom.css. The Arabic layout '
            . 'loads rtl-custom.css INSTEAD of custom.css, so a rule in only one of them reaches '
            . 'only half the merchants.');
    }

    private function declarations(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        // Comments stripped: these rules are explained in prose that quotes them.
        return (string) preg_replace('#/\*.*?\*/#s', '', (string) file_get_contents($path));
    }
}
