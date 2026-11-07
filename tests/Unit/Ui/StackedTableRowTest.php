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

        /* THE ONE DECLARATION THE WHOLE WAVE RESTS ON, pinned to its own rule. This used to be four
           bare substring searches for 'table.dataTable', 'tbody', 'tr' and 'td' over the whole
           block -- and every one of those strings occurs many times in the block's OTHER rules
           ('tr' even occurs inside `attr(data-ss-label)`), so the method asserted nothing about
           the blockifying rule at all. PROVED by adversarial review: the entire four-selector
           `display: block` rule was deleted from both mirrored sheets and the generated region,
           and `php artisan test tests/Unit/Ui` returned 801 passed, 7140 assertions, nothing red
           -- including the test whose name says the row blockifies. A merchant would have been
           back to one visible column on /order with CI fully green. */
        $this->assertMatchesRegularExpression(
            '/\.ss-dt-card-body table\.dataTable,\s*'
            . '\.ss-dt-card-body table\.dataTable > tbody,\s*'
            . '\.ss-dt-card-body table\.dataTable > tbody > tr,\s*'
            . '\.ss-dt-card-body table\.dataTable > tbody > tr > td\s*\{[^}]*display:\s*block/s',
            $css,
            'The four-selector `display: block` rule is gone. Without it the table never '
            . 'blockifies, `display: table` on the cell is just a table cell inside a still-'
            . 'tabular row, and at 320px the merchant is back to one visible column.'
        );

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

        /* ANCHORED, because the two needles are nested. The shorter selector is a proper
           substring of the longer one, so asserting both with `assertStringContainsString` meant
           the first assertion was satisfied by the SECOND rule alone. PROVED by adversarial
           review: the two non-`.dash-content` selectors were deleted and this test passed with
           three assertions, as did all 801 tests in tests/Unit/Ui -- leaving the theme's 25px
           first-child indent with no counterpart for any table card outside `.dash-content`. */
        $this->assertMatchesRegularExpression(
            '/(?<!\.dash-content )\.card:not\(\.table-card\) \.ss-dt-card-body table\.dataTable/',
            $ds,
            'The 25px first-child gutter has no counterpart that is NOT scoped to .dash-content. '
            . 'A table card rendered outside .dash-content matches only the shorter selector, so '
            . 'deleting it returns the 25px indent on the select-all cell.'
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
            'function observeStackedTables',
            $js,
            'DataTables rewrites every cell on sort, search and paging. Without an observer the '
            . 'labels survive the first render only.'
        );
        $this->assertMatchesRegularExpression(
            '/function init\s*\([^)]*\)\s*\{.*?observeStackedTables\s*\(/s',
            $js,
            'observeStackedTables() is defined but never called from init().'
        );
    }

    /**
     * A wave that ships in a cached file ships for new browsers only. `store-syria-ds.css` and the
     * design-system script are referenced from TWO places -- the merchant shell and an include some
     * screens also pull in -- and both used to carry a hand-typed `?v=p02-N`. MEASURED during
     * Wave 29: the script was on `p02-9` in one and `p02-7` in the other, two URLs for one file;
     * and the stylesheet was on `p02-7` in both AFTER its contents had changed in Wave 28. A
     * returning merchant would have got this wave's labels against the previous wave's sheet.
     *
     * The literal is gone. Both sites derive the key from the file's own bytes, so it changes when
     * and only when they do, and the two cannot disagree because neither carries a number.
     */
    /**
     * P2.5-WAVE-29. The design system's own `.ss-table--stack` was converged onto this mechanism,
     * and its declarations are GENERATED from the Wave 28 block by sync-stack-block.py rather than
     * retyped -- CSS has no mixins, and the two live in different stylesheets on different
     * breakpoints, so they cannot share a rule. This asserts the generation is current.
     *
     * It is the mirrored-sheet rule applied to a second pair: before Wave 29, `.ss-table--stack`
     * was a SEPARATE implementation that nothing used and that was broken three ways -- `thead`
     * removed rather than clipped, `content: attr(data-label)` naming an attribute nothing writes,
     * and no ARIA roles at all. A second implementation is a second thing to get wrong.
     */
    public function test_the_stack_modifier_is_generated_from_the_wave_28_block(): void
    {
        $root = dirname(__DIR__, 3);
        $wave28 = $this->ruleSet($this->declarations($this->block(self::SHEETS[0])), '.ss-dt-card-body table.dataTable');
        $modifier = $this->ruleSet($this->generatedRegion(), '.ss-table.ss-table--stack');

        $this->assertNotEmpty($wave28, 'The Wave 28 block yielded no rules.');
        $this->assertSame(
            $wave28,
            $modifier,
            'public/css/store-syria-ds.css is out of date with the Wave 28 block. '
            . 'Run: python3 docs/development/phase-2.5/tools/sync-stack-block.py'
        );
        $this->assertFileExists(
            $root . '/docs/development/phase-2.5/tools/sync-stack-block.py',
            'The generator is gone, so the next author would retype the block by hand.'
        );
    }

    /** The generated region of store-syria-ds.css, between its markers. */
    private function generatedRegion(): string
    {
        $css = $this->read(self::DS_SHEET);
        $open = strpos($css, 'sync-stack-block.py -- do not edit by hand */');
        $end = strpos($css, '/* end generated */');

        if ($open === false || $end === false || $end < $open) {
            return '';
        }

        return $this->declarations(substr($css, $open, $end - $open));
    }

    /**
     * Declarations keyed by selector, with the family prefix stripped so the two sets are
     * comparable. Order within a rule is preserved: a different order is a different cascade.
     *
     * @return list<array{0: string, 1: list<string>}>
     */
    private function ruleSet(string $css, string $prefix): array
    {
        $rules = [];

        /* Both regions start INSIDE a comment -- `block()` anchors on the wave marker and
           `generatedRegion()` on the generator's name -- so each slice begins with a dangling
           comment tail that has no `/*` for `declarations()` to match. Left in, the whole opening
           paragraph became part of the first selector and the two sets could never be equal. */
        if (($close = strpos($css, '*/')) !== false) {
            $open = strpos($css, '/*');

            if ($open === false || $close < $open) {
                $css = substr($css, $close + 2);
            }
        }

        preg_match_all('/([^{}]+)\{([^{}]*)\}/s', $css, $found, PREG_SET_ORDER);

        foreach ($found as $one) {
            $selector = trim((string) preg_replace('/\s+/', ' ', $one[1]));

            if (! str_contains($selector, $prefix)) {
                continue;
            }

            $key = str_replace($prefix, '@', $selector);
            $decls = array_values(array_filter(array_map('trim', explode(';', $one[2]))));

            /* DOCUMENT ORDER, NOT A MAP, and the difference is not cosmetic. Keyed by selector,
               the WAVE-28 block's DELIBERATE second `> tbody > tr > td` rule -- the one carrying
               only `text-align: start !important`, split out so its `!important` is visible --
               overwrote the first, so the eight declarations that make the cell a CSS table were
               silently excluded from the identity check on BOTH sides. PROVED by adversarial
               review: the generated region's cell rule was rewritten to `display: grid` with a
               two-column template and this test still passed, as did all 801 tests in
               tests/Unit/Ui. The docblock already said it wanted order -- 'a different order is a
               different cascade' -- and now the structure says it too. */
            $rules[] = [$key, $decls];
        }

        return $rules;
    }

    public function test_the_design_system_assets_version_from_their_own_bytes(): void
    {
        $views = dirname(__DIR__, 3) . '/resources/views';
        $literals = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($views));

        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (preg_match_all('#store-syria-[a-z-]+\\.(?:css|js)\'\) \}\}\?v=(p02-[0-9]+)#', $source, $m)) {
                foreach ($m[1] as $key) {
                    $literals[] = str_replace($views . '/', '', $file->getPathname()) . ' -> ' . $key;
                }
            }
        }

        $this->assertSame(
            [],
            $literals,
            "A design-system asset is pinned to a hand-typed cache key:\n  "
            . implode("\n  ", $literals)
            . "\nThere were FOUR such sites, not the two this test first looked at, and the sign-in "
            . 'page was still on p02-7 while the merchant shell had reached p02-10 -- so a merchant '
            . 'who signed in got a stylesheet seven waves old. Derive the key from the file.'
        );

        $this->assertFileExists(
            dirname(__DIR__, 3) . '/app/Support/DesignSystemAssets.php',
            'The helper every layout calls is missing, so every page would fatal.'
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
