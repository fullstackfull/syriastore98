<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE MERCHANT LIST SCREEN IS NOT A PREMIUM SURFACE.  P2.5-WAVE-1
 * =================================================================================================
 *
 * The owner's Phase 2.5 directive is explicit that this is NOT a request for "ordinary UI
 * polishing, minor CSS corrections, or accessibility-only fixes", and that a screen is not premium
 * merely because "its contrast passes WCAG". Every finding below passes contrast. They are design
 * defects, and they repeat on roughly a hundred of the 295 inventoried surfaces, because every
 * merchant list screen renders through the same DataTable.
 *
 * ALL FIVE WERE MEASURED IN THE RUNNING APPLICATION at 1440px on /product, both themes
 * (docs/development/phase-2.5/tools/measure-table-premium.cjs), not read off the markup.
 *
 * W1-1  THE TOOLBAR IS FIVE DIFFERENT COLOURS FOR FIVE THINGS THAT ARE NOT DIFFERENT.
 *       Measured fills: bulk-delete #FFD8E2 pink, colvis #E9D5E7, density #E9D5E7, reset #D8F4F7
 *       cyan, reload #FFECD2 amber. Four of the five are undoable view utilities with no semantic
 *       difference at all; the fifth deletes records. The colour says the opposite of the meaning.
 *
 *       AND #E9D5E7 IS NOT A BRAND COLOUR. In HSL it is hue 306deg -- magenta. The brand blue is
 *       hue 218deg. It is `btn-light-primary` from the vendored theme, never re-derived when the
 *       identity became blue, which is why its TEXT is the correct #335DA8 while its FILL is a
 *       leftover from the palette before it.
 *
 *       IT IS ALSO NOT THEME-AWARE. Measured identical in both themes: a #E9D5E7 chip sits at
 *       10.27:1 against the #292A33 dark page -- a pale pink tile glowing on a near-black surface.
 *
 * W1-2  THE TABLE HAS NO TYPOGRAPHIC HIERARCHY. Measured, every single body cell on /product is
 *       font-weight 400 at 14px in one colour -- the product NAME, which is the row's identifier
 *       and the thing the merchant is scanning for, renders exactly like the string "-" in the
 *       dead Brand column beside it. Nothing on the row says what the row IS.
 *
 *       THIS IS NOT A CONTRAST PROBLEM AND MUST NOT BE "FIXED" AS ONE. Measured 12.93:1 in light
 *       and 5.66:1 in dark; both pass AA comfortably. An earlier read of mine called the dark
 *       value a failure -- it was the dark theme's own background, and the reading was wrong.
 *
 * W1-3  MONEY IS NOT SET AS MONEY. The price column is `text-align: start` with
 *       `font-variant-numeric: normal`. Proportional digits in a vertical stack of prices means
 *       the decimal points do not line up, and start-alignment means the magnitude of a number is
 *       invisible until it is read. Tabular, end-aligned figures are the whole convention for a
 *       money column, and the directive names financial summaries specifically.
 *
 * W1-4  `text-transform: uppercase` ON COLUMN HEADERS IS A NO-OP IN ARABIC. Arabic is unicameral.
 *       So the identical element renders ALL-CAPS 700-weight in English and ordinary-case
 *       700-weight in Arabic: the two languages get a different visual weight for the same thing,
 *       on every table in the product. A bilingual product cannot carry emphasis in a mechanism
 *       that only one of its languages has.
 *
 * W1-5  THE ROW SEPARATOR ALL BUT VANISHES IN DARK. The rules are drawn for a white page.
 *
 * WHAT THIS PACKAGE DELIBERATELY DOES NOT DO
 * ------------------------------------------
 * It does not touch `--ss-brand`. Measured, that token resolves to #335DA8 in light and to the
 * CYAN #2AC3E5 in dark, so the primary action changes hue with the theme. That is a real identity
 * finding, and cyan is not arbitrary: brand blue on the dark page separates from it at only
 * 2.22:1. But a lifted blue -- #6E93D8, still hue 218deg -- measures 6.09:1 for its text and
 * 4.63:1 against the page, so cyan is a defensible choice and NOT a forced one. Changing it moves
 * the sidebar active state, links, focus rings and charts on every dark screen at once, so it is
 * raised for the owner rather than taken here.
 *
 * SCOPE IS `.ss-dt-card-body`, AND THAT IS NOT OPTIONAL. An unscoped table rule reaches every
 * table in all 70 vendored WorkDo modules. DesignSystemStylesheetScopeTest refused exactly that in
 * P2.5-DESIGN-K and was right to.
 */
class MerchantTablePremiumTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';
    private const OPENER = '/* ---- P2.5-WAVE-1: the merchant list screen ----';

    private function css(): string
    {
        $path = dirname(__DIR__, 3) . '/' . self::SHEET;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /**
     * The section's own text, bounded by the NEXT section heading rather than a magic length.
     *
     * A fixed window (`substr($css, $at, 4200)`) was used twice in this project and was wrong both
     * times: the comment above a block grew and the window silently stopped covering the end of
     * it, so assertions about "the block" were really assertions about its first 63%.
     */
    private function block(): string
    {
        $css = $this->css();
        $at = strpos($css, self::OPENER);
        $this->assertNotFalse($at, 'the P2.5-WAVE-1 section opener is missing from ' . self::SHEET);

        $next = strpos($css, '/* ---- ', $at + strlen(self::OPENER));

        return $next === false ? substr($css, $at) : substr($css, $at, $next - $at);
    }

    /** Declarations only. A comment that merely NAMES a selector must never satisfy an assertion. */
    private function blockWithoutComments(): string
    {
        return (string) preg_replace('#/\*.*?\*/#s', '', $this->block());
    }

    public function test_the_section_exists_and_carries_declarations_not_only_prose(): void
    {
        $decls = $this->blockWithoutComments();

        // PREMISE. Without this the three assertions below pass against an empty string.
        $this->assertGreaterThan(
            20,
            substr_count($decls, ';'),
            'the WAVE-1 section is all comment and no CSS, so every other assertion here is vacuous'
        );
        $this->assertStringContainsString('{', $decls);
    }

    /**
     * W1-1. The stale magenta is gone, and the four view utilities share ONE neutral treatment.
     */
    public function test_the_four_view_utilities_share_one_neutral_treatment(): void
    {
        $decls = $this->blockWithoutComments();

        foreach (['.ss-colvis', '.ss-density', '.buttons-reset', '.buttons-reload'] as $sel) {
            $this->assertStringContainsString(
                $sel,
                $decls,
                "the toolbar utility {$sel} is not restyled, so it keeps its vendored colour"
            );
        }

        $this->assertStringContainsString(
            '--ss-dt-tool-bg',
            $decls,
            'the utilities must share one component token, not four hand-written fills'
        );

        // The magenta leftover must not be reintroduced ANYWHERE in the sheet, in any casing.
        //
        // AGAINST THE DECLARATIONS, NOT THE FILE. The first version of this assertion ran against
        // `$this->css()` and failed instantly -- on the comment IMMEDIATELY ABOVE, which names
        // #E9D5E7 in order to explain why it is wrong. That is the third test in this project to
        // match its own prose (ReportExportControlContractTest matched the literal `a.btn` the
        // same way). A test that reads comments is testing the documentation.
        $this->assertDoesNotMatchRegularExpression(
            '/#e9d5e7/i',
            (string) preg_replace('#/\\*.*?\\*/#s', '', $this->css()),
            'the pre-rebrand magenta #E9D5E7 is back in the design system'
        );
    }

    /** W1-1, second half. Destroying records must not look like the other four. */
    public function test_bulk_delete_stays_visually_distinct_from_the_view_utilities(): void
    {
        $decls = $this->blockWithoutComments();

        $this->assertStringContainsString('.bulk-delete', $decls);

        // INSIDE THE RULE, NOT ANYWHERE IN THE SECTION. The first version asserted only that the
        // string `--ss-dt-tool-danger` appeared in the declarations -- and a bite that gave
        // bulk-delete the NEUTRAL tokens still passed, because the danger token's own definition
        // a few lines above kept the string alive. An assertion that a token is DEFINED says
        // nothing about whether anything USES it.
        $at = strpos($decls, '.bulk-delete');
        $open = strpos($decls, '{', $at);
        $close = strpos($decls, '}', $open);
        $rule = substr($decls, $open, $close - $open);

        $this->assertStringContainsString(
            '--ss-dt-tool-danger',
            $rule,
            'bulk-delete does not USE the danger token, so deleting records looks like reloading a view'
        );
        $this->assertStringNotContainsString(
            '--ss-dt-tool-fg',
            $rule,
            'bulk-delete is wearing the neutral utility foreground'
        );
    }

    /** Every toolbar fill follows the theme. A single hard-coded hex here is the old defect. */
    public function test_the_toolbar_tokens_are_defined_for_both_themes(): void
    {
        $block = $this->block();

        foreach (['--ss-dt-tool-bg', '--ss-dt-tool-fg', '--ss-dt-tool-border'] as $token) {
            $this->assertMatchesRegularExpression(
                '/\[data-ss-theme="dark"\][^{]*\{[^}]*' . preg_quote($token, '/') . '/s',
                $block,
                "{$token} has no dark-theme value, so the toolbar renders its light fill on a dark page"
            );
        }
    }

    /** W1-2. The identifier column is heavier than the cells around it. */
    public function test_the_row_identifier_is_typographically_distinct(): void
    {
        $decls = $this->blockWithoutComments();

        $this->assertStringContainsString(
            '--ss-dt-ident-weight',
            $decls,
            'nothing distinguishes the identifier cell, so the row has no subject'
        );
        $this->assertMatchesRegularExpression(
            '/--ss-dt-ident-weight:\s*[56]\d\d/',
            $decls,
            'the identifier weight must actually be heavier than the 400 the body cells carry'
        );
    }

    /** W1-3. Money is tabular and end-aligned. */
    public function test_numeric_columns_are_tabular_and_end_aligned(): void
    {
        $decls = $this->blockWithoutComments();

        $this->assertStringContainsString('tabular-nums', $decls, 'prices still use proportional figures');
        $this->assertStringContainsString(
            'text-align: end',
            $decls,
            'a money column must align to the end so magnitude is visible without reading'
        );
        // `end` is the logical keyword on purpose: `right` would be wrong in Arabic.
        $this->assertDoesNotMatchRegularExpression(
            '/\.ss-dt-num[^{]*\{[^}]*text-align:\s*right/s',
            $decls,
            'text-align: right hard-codes LTR and mirrors wrongly in Arabic'
        );
    }

    /** W1-4. The uppercase that only one of the two languages can render is gone. */
    public function test_column_headers_do_not_rely_on_a_case_transform(): void
    {
        $decls = $this->blockWithoutComments();

        $this->assertMatchesRegularExpression(
            '/text-transform:\s*none/',
            $decls,
            'headers still uppercase, which is emphasis English gets and Arabic does not'
        );
    }

    /** Scope. This is the rule that stopped P2.5-DESIGN-K reaching 70 vendored modules. */
    public function test_every_rule_is_scoped_to_the_merchant_table_card(): void
    {
        $decls = $this->blockWithoutComments();

        // Strip the :root/theme token blocks: those are declarations, not table rules.
        $rules = preg_split('/\}/', $decls) ?: [];
        $unscoped = [];

        foreach ($rules as $rule) {
            $sel = trim(explode('{', $rule)[0] ?? '');
            if ($sel === '' || !str_contains($rule, '{')) {
                continue;
            }
            if (str_contains($sel, ':root') || str_contains($sel, 'data-ss-theme')) {
                continue; // token definitions
            }
            // An at-rule is not a selector. Splitting on `}` hands back
            // `@media (prefers-color-scheme: dark)` as though it targeted elements; the rules
            // INSIDE it are separate chunks and are checked on their own.
            if (str_starts_with($sel, '@')) {
                continue;
            }
            if (!str_contains($sel, '.ss-dt-card-body') && !str_contains($sel, '.ss-dt-')) {
                $unscoped[] = $sel;
            }
        }

        $this->assertSame(
            [],
            $unscoped,
            "these rules escape the merchant table card and would reach all 70 WorkDo modules:\n  "
                . implode("\n  ", $unscoped)
        );
    }
}
