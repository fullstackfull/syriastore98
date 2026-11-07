<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-22 — the DataTables control bar is reachable at phone width, and it is one row.
 * ================================================================================================
 *
 * THE DEFECT THIS SLICE FOUND WAS NOT THE ONE IT WENT LOOKING FOR. The brief was height: the first
 * data row on /order sat at y=1293 on a 568px screen. Measuring the bands turned up something
 * worse. On /order at 320px, `.table-responsive` had clientWidth 250 and scrollWidth 1141,
 * `.dataTable-top` was 905px wide, and its button block started at x=376 -- 56px PAST the right
 * edge of the screen. The search box, the export button, the column-visibility collection and the
 * reset button were all in the DOM and all unreachable unless the merchant first discovered that
 * the table scrolls sideways. The length selector, at x=35, was the only control they could see.
 *
 * THE WIDTH CAME FROM THE WRAPPER, which DataTables sizes to the TABLE rather than to its
 * container. Pinning it back to the container put every control on screen -- and MEASURED the bill:
 * the bar went 77px -> 182px and became the tallest band on the page. So the second half of the fix
 * is a disclosure: the search field keeps the row beside a 44x44 toggle, and the length selector
 * and the button group sit one tap behind it. 182px -> 68px, with nothing unreachable.
 *
 * EVERY ASSERTION BELOW PINS A FAILURE THAT ACTUALLY HAPPENED DURING THIS SLICE.
 *
 *   mirrored sheets      Wave 16b shipped a fix to `custom.css` only. The Arabic layout loads
 *                        `rtl-custom.css` INSTEAD, so half the merchants got nothing. Byte
 *                        equality of the block is the only check that cannot be half-applied.
 *   cascade order        `.ss-dt-controls__toggle { display: none }` was written AFTER the phone
 *                        `display: inline-flex`. A media query adds no specificity, so the later
 *                        declaration won and the toggle never appeared. Caught by measuring.
 *   selector specificity Three attempts at the `.dataTable-top` padding had NO effect: the theme's
 *                        `.card:not(.table-card) .dataTable-top { padding: 25px 0 }` is (0,3,0)
 *                        because `:not(.table-card)` counts as a class. Read off the page with
 *                        CSS.getMatchedStylesForNode.
 *   the label pairing    The script cannot call `__()`. The layout publishes the string on <body>
 *                        and the script reads it. Break either side and the button loses its
 *                        accessible name -- which is the whole of its affordance, since it is
 *                        icon-only.
 */
class TableControlDisclosureTest extends TestCase
{
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];
    private const OPEN_MARK = 'P2.5-WAVE-22';
    private const END_MARK = '/* end P2.5-WAVE-22 */';

    public function test_premise_both_mirrored_sheets_carry_a_wave_22_block(): void
    {
        foreach (self::SHEETS as $sheet) {
            $source = $this->read($sheet);
            $this->assertStringContainsString(self::OPEN_MARK, $source, $sheet . ' has no WAVE-22 block.');
            $this->assertStringContainsString(self::END_MARK, $source, $sheet . ' has no WAVE-22 end marker.');
        }
    }

    /**
     * The Wave 16b lesson, as a byte comparison. `rtl-custom.css` is loaded INSTEAD of
     * `custom.css` on the Arabic layout, never alongside it.
     */
    public function test_the_wave_22_block_is_byte_identical_in_both_sheets(): void
    {
        $this->assertSame(
            $this->block(self::SHEETS[0]), $this->block(self::SHEETS[1]),
            'The WAVE-22 block differs between custom.css and rtl-custom.css. The Arabic admin '
            . 'layout loads rtl-custom.css INSTEAD of custom.css, so anything present in only one '
            . 'of them reaches only half the merchants. This is exactly how Wave 16b shipped a '
            . 'half-fix that measured 1.63:1 on the Arabic page while the English page passed.');
    }

    /**
     * The cascade bug, pinned. Both selectors are (0,1,0) and a media query adds no specificity,
     * so source order decides and the desktop rule has to come first.
     */
    public function test_the_desktop_hide_precedes_the_phone_show_for_the_toggle(): void
    {
        foreach (self::SHEETS as $sheet) {
            $block = $this->block($sheet);

            $hide = strpos($block, '.ss-dt-controls__toggle { display: none; }');
            $show = strpos($block, 'display: inline-flex;');

            $this->assertNotFalse($hide, $sheet . ': the desktop `display: none` for the toggle is '
                . 'gone, so an icon-only button that does nothing renders on every desktop list '
                . 'screen -- the show/hide rules it drives are phone-only.');
            $this->assertNotFalse($show, $sheet . ': the phone `display: inline-flex` is gone.');

            $this->assertLessThan($show, $hide,
                $sheet . ': `.ss-dt-controls__toggle { display: none }` now comes AFTER the phone '
                . 'rule. Both selectors are (0,1,0) and @media adds no specificity, so the later '
                . 'declaration wins at EVERY width and the toggle disappears entirely. MEASURED '
                . 'that way once already.');
        }
    }

    /** The fix that put the controls back on screen. Without it the disclosure hides nothing. */
    public function test_the_wrapper_is_pinned_to_its_container_not_to_the_table(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertMatchesRegularExpression(
                '/\.table-responsive\s*>\s*\.dataTables_wrapper\s*\{[^}]*inline-size:\s*100%/s',
                $this->block($sheet),
                $sheet . ': the rule pinning `.dataTables_wrapper` to its container is gone. '
                . 'DataTables sizes the wrapper to the TABLE, so without it the control bar is '
                . '905px wide inside a 250px scrollport and the search box, export button, '
                . 'column-visibility collection and reset button all render off-screen -- '
                . 'MEASURED starting at x=376 on a 320px viewport.');
        }
    }

    /** The specificity the closed state needs in order to beat this file's own base rule. */
    public function test_the_closed_state_outranks_the_base_width_rule(): void
    {
        foreach (self::SHEETS as $sheet) {
            $this->assertStringContainsString(
                '.card.ss-dt-card .ss-dt-card-body .dataTable-top[data-ss-controls] .dataTable-botton',
                $this->block($sheet),
                $sheet . ': the closed-state rule has lost its prefix. The base rule a few lines '
                . 'above is (0,4,0); the short form is (0,3,0) and MEASURED as losing -- the '
                . 'search field dropped to its own line (y=619 instead of y=567), costing the 60px '
                . 'the disclosure exists to save.');
        }
    }

    /** Both halves of the label pairing, since the script cannot translate. */
    public function test_the_layout_publishes_the_label_and_the_script_reads_it(): void
    {
        $this->assertStringContainsString(
            "data-ss-label-table-options=\"{{ __('Table options') }}\"",
            $this->read('resources/views/layouts/app.blade.php'),
            'The layout no longer publishes the control-bar label on <body>. The script builds the '
            . 'toggle and cannot call `__()`, so this attribute IS the translation path.');

        $this->assertStringContainsString(
            "getAttribute('data-ss-label-table-options')",
            $this->read('public/js/store-syria-ds.js'),
            'The script no longer reads the published label, so the icon-only toggle falls back to '
            . 'English on the Arabic page.');
    }

    /** The disclosure contract: a real button, with state, pointing at what it controls. */
    public function test_the_toggle_is_built_as_a_real_disclosure_button(): void
    {
        $script = $this->read('public/js/store-syria-ds.js');

        foreach ([
            "createElement('button')" => 'a real <button>, so Enter and Space work without a key handler',
            "'aria-expanded', 'false'" => 'the collapsed state is announced',
            "'aria-controls'" => 'the button names what it controls',
            "'aria-label'" => 'the icon-only button has an accessible name',
        ] as $needle => $why) {
            $this->assertStringContainsString($needle, $script,
                'store-syria-ds.js no longer sets ' . $needle . ' — ' . $why . '. MEASURED through '
                . 'the accessibility tree: `button / name="Table options" expanded=false`, and '
                . '`expanded=true` after Enter.');
        }
    }

    public function test_the_control_bar_label_is_translated(): void
    {
        $ar = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/resources/lang/ar.json'), true);

        $this->assertIsArray($ar);
        $this->assertArrayHasKey('Table options', $ar,
            'ar.json has no "Table options", so the toggle announces English on the Arabic page.');
        $this->assertSame('خيارات الجدول', $ar['Table options']);
    }

    /**
     * The block, with comments removed so prose cannot satisfy an assertion. The capture starts
     * INSIDE the opening marker comment, so there is no `/*` to pair with the first `*' . '/` --
     * two earlier tests in this project failed on their own docblocks for exactly that reason.
     */
    private function block(string $sheet): string
    {
        $source = $this->read($sheet);

        $start = strpos($source, self::OPEN_MARK);
        $end = strpos($source, self::END_MARK);
        $this->assertNotFalse($start, $sheet . ': no WAVE-22 block.');
        $this->assertNotFalse($end, $sheet . ': no WAVE-22 end marker.');

        $block = substr($source, $start, $end - $start);

        // Drop the remainder of the comment this capture began inside, THEN strip whole comments.
        $firstClose = strpos($block, '*/');
        if ($firstClose !== false) {
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
