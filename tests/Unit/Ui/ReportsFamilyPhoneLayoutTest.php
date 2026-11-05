<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-24 — the reports and finance screens, which the list-screen work never reached.
 * ================================================================================================
 *
 * MEASURED at 320x568 across every destination the merchant's sidebar offers. Nine screens render
 * a table and have NO `.dataTable-top`, so every Wave 22 control-bar improvement missed them:
 *
 *     /finance                     2131 -> 1887      /stock-reports            684 (unchanged)
 *     /category-order-sale-reports 1543 -> 1457      /order-country-reports    598 (unchanged)
 *     /Brand-order-sale-reports    1496 -> 1410      /Status-reports           558 (unchanged)
 *     /product-order-sale-reports  1358 -> 1272      /dashboard               1817 (unchanged)
 *
 * SAID PLAINLY: THIS IS A SMALLER WIN THAN WAVE 22, and the reason is not a missing idea. These
 * are ANALYTICS screens. The table is a detail panel under a chart, so the chart and the filters
 * legitimately come first, and most of the height above the first row is content a merchant came
 * for. What is NOT content is a desktop multi-column grid stacking into a tall single column, and
 * that is what this slice corrects.
 *
 * TWO CHANGES, both shared rather than per-screen:
 *
 *   1. The report period picker (`components/ss/report-filter`, used by reports/index and the
 *      three sale-by screens). Four mutually exclusive periods rendered as four FULL-WIDTH pills,
 *      one per line. Four options that are one choice read as a group, so they go two-up: 164px
 *      becomes about 96px and "Last 7 days", the longest label, still fits at ~136px.
 *
 *      THE RULE HAD TO MOVE SHEETS TO WORK. Written in custom.css it was dead: store-syria-ds.css
 *      loads AFTER it and already carried `.ss-toolbar { flex-direction: column }` at this
 *      breakpoint, which makes the main axis VERTICAL -- so a `flex-basis` of 50% meant half the
 *      HEIGHT and the pills stayed 210px wide. Same trap as Wave 22's `.ss-filterbar`, hit twice.
 *
 *   2. /finance's action row and stat cards. Four permission-gated buttons at four different
 *      widths, one per line; five stat cards at 102px each with about 50px of empty space under
 *      each figure. The buttons go two-up with a shared width and wrapping labels; each card
 *      becomes a single line, label against figure, which keeps the FULL width for the number --
 *      a two-up card would be ~130px wide and a real balance would not fit, trading a height
 *      defect for a truncation one.
 *
 * AND A NAME COLLISION THAT COST FOUR REFUTED HYPOTHESES, recorded because the symptom is nasty.
 * The finance hook was first called `ss-actionbar`, which ALREADY EXISTS as this design system's
 * sticky action bar -- `components/ss/actionbar.blade.php`, `store-syria-ds.css:2442`, with
 * `__status` and `__actions` children and `flex-direction: column` at this very breakpoint. The
 * finance row silently inherited another component's layout, and the rules written to correct it
 * would have changed that component everywhere it is used.
 *
 * The symptom: EVERY declaration applied. `flex: 1 1 calc(50% - 8px)`, `white-space: normal` and
 * `min-width: 0` all read back correctly off the running element, and the layout ignored them,
 * because in a column `flex-basis: 50%` is half the height. The buttons came out 206px wide and
 * 96px tall in two columns overflowing a 320px window. Four hypotheses were measured and refuted
 * first -- a circular container width, a `nowrap` minimum, an indefinite ancestor, a missing
 * `flex-direction` -- before CSS.getMatchedStylesForNode showed TWO `.ss-actionbar` rules at this
 * breakpoint with the later one winning. A grep for the class name would have cost seconds.
 */
class ReportsFamilyPhoneLayoutTest extends TestCase
{
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];

    /** The hook must not be a name the design system already owns. */
    public function test_the_finance_hook_does_not_collide_with_an_existing_component(): void
    {
        $view = $this->read('resources/views/finance/index.blade.php');

        $this->assertStringContainsString('ss-finance-actions', $view,
            'finance/index has lost its action-row hook.');

        $this->assertStringNotContainsString('ss-actionbar', $view,
            'finance/index is using `ss-actionbar` again. That class is the design system\'s '
            . 'STICKY ACTION BAR (components/ss/actionbar.blade.php, store-syria-ds.css:2442), '
            . 'and reusing the name makes this row inherit its layout -- including '
            . '`flex-direction: column` at phone width, which silently inverts every flex-basis '
            . 'written for it.');
    }

    /** And this slice must not have redefined that component either. */
    public function test_the_real_action_bar_component_is_not_restyled_by_this_slice(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->declarations($sheet);

            $this->assertDoesNotMatchRegularExpression('/\.ss-actionbar\s*[,{>]/', $css,
                $sheet . ' declares a rule for `.ss-actionbar`. That is a shared design-system '
                . 'component with its own sheet; a rule for it here would change it on every '
                . 'screen that uses it, which is exactly the accident this slice nearly shipped.');
        }
    }

    /** The period picker's rule lives where it can win. */
    public function test_the_period_picker_rule_lives_in_the_design_system_sheet(): void
    {
        $ds = $this->declarations('public/css/store-syria-ds.css');

        $this->assertMatchesRegularExpression(
            '/\.ss-toolbar\s+\.ss-chip\.chart-data\s*\{[^}]*flex:\s*1 1 calc\(50%/s', $ds,
            'The two-up rule for the report period chips is gone from store-syria-ds.css. It '
            . 'cannot live in custom.css: that sheet loads FIRST, and this one carries '
            . '`.ss-toolbar { flex-direction: column }` at the same breakpoint, which makes a '
            . '50% flex-basis mean half the HEIGHT. MEASURED dead once already.');

        $this->assertMatchesRegularExpression(
            '/\.ss-toolbar\s*\{[^}]*flex-direction:\s*row/s', $ds,
            'The toolbar is a column again at phone width, so the period chips cannot sit '
            . 'two-up. The per-child default `.ss-toolbar > * { flex: 1 1 100% }` is what '
            . 'preserves the old stacking for every other toolbar child.');

        $this->assertMatchesRegularExpression(
            '/\.ss-toolbar\s*>\s*\*\s*\{[^}]*flex:\s*1 1 100%/s', $ds,
            'The per-child full-width default is gone, so a search box or filter group in a '
            . 'toolbar would now share a row with whatever follows it. That default is what '
            . 'makes turning the toolbar back into a row safe.');
    }

    /** The finance rules, present and mirrored. */
    public function test_the_finance_rules_are_present_in_both_mirrored_sheets(): void
    {
        foreach (self::SHEETS as $sheet) {
            $css = $this->declarations($sheet);

            foreach ([
                '.ss-finance-actions' => 'the action row cannot go two-up',
                '.ss-finance-overview' => 'the stat cards keep their stacked card layout',
                'white-space: normal' => 'a long action label cannot wrap, so the button cannot shrink below it — MEASURED as a page overflow at 441px',
            ] as $needle => $why) {
                $this->assertStringContainsString($needle, $css,
                    $sheet . ' no longer carries `' . $needle . '` — ' . $why . '.');
            }
        }
    }

    /** Still mirrored. The property a single-sheet fix always breaks. */
    public function test_the_phone_block_is_byte_identical_in_both_sheets(): void
    {
        $blocks = [];

        foreach (self::SHEETS as $sheet) {
            $source = $this->read($sheet);
            $start = strpos($source, 'P2.5-WAVE-22');
            $end = strpos($source, '/* end P2.5-WAVE-22 */');
            $this->assertNotFalse($start, $sheet . ': the phone block is gone.');
            $this->assertNotFalse($end, $sheet . ': the phone block has no end marker.');
            $blocks[] = substr($source, $start, $end - $start);
        }

        $this->assertSame($blocks[0], $blocks[1],
            'The phone block differs between custom.css and rtl-custom.css. The Arabic layout '
            . 'loads rtl-custom.css INSTEAD of custom.css.');
    }

    private function declarations(string $relative): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->read($relative));
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
