<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * P2.5-WAVE-28 — at phone width a table row is a readable card, not one visible column.
 * ================================================================================================
 *
 * THE DEFECT. Wave 22 brought the first data row on `/order` from y=1293 to y=600, and that made
 * the real problem visible rather than solving it: the row the merchant finally reached carried an
 * order id and nothing else. MEASURED at 320x568 across eleven list screens -- 4 to 12 columns per
 * table, tables 417 to 1200px wide inside a 250px scrollport, so 2 to 4 columns on screen, of
 * which one is the empty select-all column and one the pinned Action column. No date, no customer,
 * no total, no status, unless the merchant discovers the table scrolls sideways.
 *
 * THE FIX IS A LAYOUT CHANGE, NOT A HIDING. Every cell is still rendered. The table, its tbody and
 * its rows blockify; each cell becomes a two-column CSS table whose first cell is the column's own
 * heading, copied onto the cell as `data-ss-label` by the design-system script. MEASURED after:
 * 12/12 cells readable on /product, 10/10 on /order and /customer, 8/8 on /category, 5/5 on
 * /stock-reports, zero page overflow, every value starting at the same x, in all four
 * theme/direction combinations -- and the desktop table unchanged.
 *
 * EVERY ASSERTION BELOW PINS A FAILURE THAT ACTUALLY HAPPENED DURING THIS SLICE.
 *
 *   grid vs table        The cell was a two-column GRID first. That holds only while the value is
 *                        one box. MEASURED on /product: the Review cell is `<i class="ti"></i> 0`
 *                        -- an element and a text run -- so auto-placement put the icon in column
 *                        2 and wrapped the number to column 1 of the NEXT row, under the label.
 *                        CSS table fixup wraps all contiguous content in exactly ONE anonymous
 *                        cell, however many nodes, so the value is a single box by construction.
 *   thead clipped        `display: none` on the heading row would take the column names out of the
 *                        accessibility tree at the same moment the visible table stops conveying
 *                        them. It is clipped instead, so a screen reader still reads them.
 *   the pin's withdrawal `box-shadow: none` written in the mirrored sheets was MATCHED AND THEN
 *                        OVERRIDDEN: `store-syria-ds.css` loads AFTER them and pins the Action
 *                        column globally at equal specificity. Read off the page with
 *                        CSS.getMatchedStylesForNode after a screenshot showed a grey bar beside
 *                        the Action row. The withdrawal lives beside the rules it withdraws.
 *   the edge gutters     Same cascade, different property: the theme insets the first and last
 *                        cell of every row, and at (0,4,2) and (0,5,2) it outweighed a
 *                        `padding-inline: 0` written at (0,2,4). MEASURED as a 25px indent on the
 *                        checkbox cell and a 10px one on Action, which put that row's label 4px
 *                        out of line with the other nine.
 *   two !important       Two declarations here answer `!important` declarations whose premise --
 *                        that there is a column to align within -- is false at this width. A third
 *                        is a decision, not a tweak, so the count is pinned.
 *   comments are not     Four tests in this phase have failed on their own prose. Every source
 *   declarations         assertion below strips comments first.
 */
class StackedTableRowTest extends TestCase
{
    private const SHEETS = ['public/css/custom.css', 'public/css/rtl-custom.css'];
    private const OPEN_MARK = 'P2.5-WAVE-28 —';
    private const END_MARK = '/* end P2.5-WAVE-28 */';
    private const DS_SHEET = 'public/css/store-syria-ds.css';
    private const SCRIPT = 'public/js/store-syria-ds.js';

    private const IMPORTANT_CEILING = 2;

    public function test_premise_both_mirrored_sheets_carry_a_wave_28_block(): void
    {
        foreach (self::SHEETS as $sheet) {
            $source = $this->read($sheet);
            $this->assertStringContainsString(self::OPEN_MARK, $source, $sheet . ' has no WAVE-28 block.');
            $this->assertStringContainsString(self::END_MARK, $source, $sheet . ' has no WAVE-28 end marker.');
        }
    }

    /**
     * The Wave 16b lesson, applied to this block. The Arabic layout loads `rtl-custom.css`
     * INSTEAD of `custom.css`, so a fix written to one sheet reaches half the merchants.
     */
    public function test_the_block_is_byte_identical_in_both_mirrored_sheets(): void
    {
        [$ltr, $rtl] = array_map(fn ($s) => $this->block($s), self::SHEETS);

        $this->assertNotSame('', $ltr, 'The WAVE-28 block came back empty.');
        $this->assertSame(
            $ltr,
            $rtl,
            'The WAVE-28 block differs between custom.css and rtl-custom.css, so Arabic merchants '
            . 'get a different layout from English ones.'
        );
    }

    public function test_the_row_blockifies_and_the_cell_is_a_css_table_not_a_grid(): void
    {
        $css = $this->declarations($this->block(self::SHEETS[0]));

        foreach (['table.dataTable', 'tbody', 'tr', 'td'] as $part) {
            $this->assertStringContainsString($part, $css, 'The blockifying selector lost ' . $part . '.');
        }

        $this->assertMatchesRegularExpression(
            '/>\s*td\s*\{[^}]*display:\s*table\s*;/s',
            $css,
            'The stacked cell must be `display: table`. As a grid, any cell holding more than one '
            . 'node -- an icon and a number, say -- wraps its second item under the label.'
        );
        $this->assertStringContainsString(
            'table-layout: fixed',
            $css,
            'Without `table-layout: fixed` the label column is renegotiated per row and the labels '
            . 'stop lining up down the card.'
        );
        $this->assertStringNotContainsString(
            'grid-template-columns',
            $css,
            'A grid-era declaration survived the rewrite. The two layouts do not compose.'
        );
    }

    public function test_the_label_is_a_real_cell_that_wraps_at_one_weight(): void
    {
        $css = $this->declarations($this->block(self::SHEETS[0]));

        $this->assertStringContainsString(
            'content: attr(data-ss-label)',
            $css,
            'The label must come from the attribute the script writes, not from a hard-coded string.'
        );
        $this->assertStringContainsString('display: table-cell', $css, 'The label is not a table cell.');
        $this->assertStringContainsString(
            'white-space: normal',
            $css,
            'Labels inherit `white-space: nowrap` from the table. MEASURED: "Shipping Method" is '
            . '108px of text in a 100px column and ran straight over its own value.'
        );
        $this->assertStringContainsString(
            'font-weight: 400',
            $css,
            'Labels inherit the cell\'s weight. MEASURED: the Order Id cell carries `.ss-dt-ident` '
            . '(600), so one label down the card was bold and the other eight were not.'
        );
    }

    /**
     * The column names have to survive for a screen reader at the moment the visible table stops
     * conveying them. `display: none` would remove them from the accessibility tree.
     */
    public function test_the_heading_row_is_clipped_rather_than_removed(): void
    {
        $css = $this->declarations($this->block(self::SHEETS[0]));

        $this->assertMatchesRegularExpression(
            '/>\s*thead\s*\{[^}]*clip-path:\s*inset\(50%\)/s',
            $css,
            'The heading row must be clipped, not hidden.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/>\s*thead\s*\{[^}]*display:\s*none/s',
            $css,
            '`display: none` on thead takes the column names out of the accessibility tree.'
        );
    }

    /**
     * Where a withdrawal has to live. `store-syria-ds.css` loads AFTER the mirrored sheets and
     * pins the Action column globally, so an equal-specificity override written there loses.
     */
    public function test_the_pin_is_withdrawn_in_the_sheet_that_sets_it(): void
    {
        $ds = $this->declarations($this->read(self::DS_SHEET));

        $withdrawn = false;

        foreach ($this->mediaBlocks($ds, '(max-width: 575.98px)') as $body) {
            if (str_contains($body, 'ss-col-pinned') && str_contains($body, 'box-shadow: none')) {
                $withdrawn = true;
                break;
            }
        }

        $this->assertTrue(
            $withdrawn,
            'store-syria-ds.css must withdraw the pinned column and its edge at phone width, in '
            . 'the sheet that sets them. A withdrawal written in the mirrored sheets is matched '
            . 'and then overridden -- MEASURED with CSS.getMatchedStylesForNode.'
        );

        foreach (self::SHEETS as $sheet) {
            $this->assertStringNotContainsString(
                'box-shadow: none',
                $this->declarations($this->block($sheet)),
                $sheet . ' tries to neutralise the pinned edge, which cannot work: '
                . 'store-syria-ds.css loads afterwards at equal specificity.'
            );
        }
    }

    /**
     * Both theme gutter rules get a named counterpart. A card outside `.dash-content` matches only
     * the first, so dropping either one leaves one of the two indents in place.
     */
    public function test_both_edge_gutter_rules_have_a_counterpart(): void
    {
        $ds = $this->declarations($this->read(self::DS_SHEET));

        $this->assertStringContainsString(
            '.card:not(.table-card) .ss-dt-card-body table.dataTable > tbody > tr > td',
            $ds,
            'The 25px first-child gutter has no counterpart.'
        );
        $this->assertStringContainsString(
            '.dash-content .card:not(.table-card) .ss-dt-card-body table.dataTable > tbody > tr > td',
            $ds,
            'The 10px last-child gutter has no counterpart; it is scoped to .dash-content and '
            . 'outweighs the shorter selector.'
        );
    }

    public function test_the_block_answers_exactly_two_important_declarations(): void
    {
        $css = $this->declarations($this->block(self::SHEETS[0]));

        $this->assertSame(
            self::IMPORTANT_CEILING,
            substr_count($css, '!important'),
            'The WAVE-28 block is allowed ' . self::IMPORTANT_CEILING . ' `!important` declarations: '
            . 'one answering `.text-center`, one answering `.card .form-switch`. Both reply to an '
            . '`!important` that cannot be beaten on specificity, and both are withdrawn above '
            . '575.98px. A third is a decision, not a tweak -- raise this number deliberately.'
        );
    }

    public function test_the_script_labels_every_cell_and_states_the_aria_roles(): void
    {
        $js = $this->code($this->read(self::SCRIPT));

        $this->assertStringContainsString('function labelTableCells', $js, 'The labelling function is gone.');
        $this->assertStringContainsString(
            "setAttribute('data-ss-label'",
            $js,
            'Nothing writes the attribute the CSS reads, so every label would render empty.'
        );

        foreach (["'role', 'table'", "'role', 'row'", "'role', 'cell'", "'role', 'columnheader'"] as $role) {
            $this->assertStringContainsString(
                $role,
                $js,
                'Missing ' . $role . '. `display: block` on a table destroys its implicit ARIA '
                . 'semantics, so each role has to be stated explicitly or the table stops being a '
                . 'table for assistive technology.'
            );
        }

        $this->assertStringContainsString(
            'function observeDataTables',
            $js,
            'DataTables rewrites every cell on sort, search and paging. Without an observer the '
            . 'labels survive the first render only.'
        );
        $this->assertMatchesRegularExpression(
            '/function init\s*\([^)]*\)\s*\{.*?observeDataTables\s*\(/s',
            $js,
            'observeDataTables() is defined but never called from init().'
        );
    }

    /**
     * A wave that ships in a cached file ships for new browsers only. Both sheets this slice
     * touches are served with `?v=time()`, but `store-syria-ds.css` and the design-system script
     * carry a hand-bumped key -- and they are loaded from TWO places: the merchant shell, and an
     * include that some screens pull in as well. MEASURED before this assertion existed: the
     * script was on `p02-9` in the shell and `p02-7` in the include, two URLs for one file, and
     * the sheet was on `p02-7` in both after its contents had changed. A returning merchant would
     * have got this wave's labels with the previous sheet -- the pinned edge still drawn, the
     * gutters still indenting -- which is the half-applied state the mirrored-sheet rule exists to
     * prevent, arriving by a different door.
     */
    public function test_every_design_system_asset_reference_carries_the_same_cache_key(): void
    {
        $keys = [];

        foreach (['resources/views/layouts/app.blade.php',
                  'resources/views/layouts/includes/store-syria-ds.blade.php'] as $view) {
            preg_match_all(
                '#(store-syria-ds\\.(?:css|js))\'\) \}\}\?v=([A-Za-z0-9._-]+)#',
                $this->read($view),
                $found,
                PREG_SET_ORDER
            );

            $this->assertNotEmpty($found, $view . ' references no design-system asset.');

            foreach ($found as $one) {
                $keys[$view . ' -> ' . $one[1]] = $one[2];
            }
        }

        $this->assertCount(
            1,
            array_unique($keys),
            'The design-system assets are served under more than one cache key: '
            . json_encode($keys, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            . ' Bump every reference together, or a merchant gets one file from this wave and '
            . 'another from the last one.'
        );
    }

    /**
     * Executable JavaScript only. The first draft of this test searched the raw file, and a
     * mutation that commented the `columnheader` line out did NOT redden it: the string was still
     * there, inside a `//`. Block comments go, and so does any line whose first non-space
     * characters are `//` -- which is what a disabled statement looks like. A `//` inside a string
     * such as a URL is left alone, because only whole comment lines are dropped.
     */
    private function code(string $js): string
    {
        $js = (string) preg_replace('#/\\*.*?\\*/#s', '', $js);

        return (string) preg_replace('/^[ \t]*\/\/.*$/m', '', $js);
    }

    /**
     * The bodies of every `@media <condition>` block, by counting braces. A regular expression
     * cannot do this: `store-syria-ds.css` carries several blocks on the same condition, and a
     * lazy quantifier walks straight out of one and into the next -- which is how the first draft
     * of this assertion failed against CSS that was correct.
     *
     * @return list<string>
     */
    private function mediaBlocks(string $css, string $condition): array
    {
        $needle = '@media ' . $condition;
        $bodies = [];
        $from = 0;

        while (($at = strpos($css, $needle, $from)) !== false) {
            $open = strpos($css, '{', $at + strlen($needle));
            $from = $at + strlen($needle);

            if ($open === false) {
                continue;
            }

            $depth = 0;
            $length = strlen($css);

            for ($i = $open; $i < $length; $i++) {
                if ($css[$i] === '{') {
                    $depth++;
                } elseif ($css[$i] === '}') {
                    $depth--;

                    if ($depth === 0) {
                        $bodies[] = substr($css, $open + 1, $i - $open - 1);
                        $from = $i;
                        break;
                    }
                }
            }
        }

        return $bodies;
    }

    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        $this->assertFileExists($path, $relative . ' is missing.');

        return (string) file_get_contents($path);
    }

    /** The WAVE-28 region of a mirrored sheet, markers included. */
    private function block(string $relative): string
    {
        $source = $this->read($relative);
        $open = strpos($source, self::OPEN_MARK);
        $end = strpos($source, self::END_MARK);

        if ($open === false || $end === false || $end < $open) {
            return '';
        }

        return substr($source, $open, $end - $open + strlen(self::END_MARK));
    }

    /**
     * Declarations only. Four tests in this phase have passed or failed on the prose in their own
     * comments; a comment that says `!important` is not an `!important`.
     */
    private function declarations(string $css): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $css);
    }
}
