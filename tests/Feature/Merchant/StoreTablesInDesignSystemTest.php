<?php

namespace Tests\Feature\Merchant;

use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-WAVE-6 — the three store tables that P2.5-WAVE-1's 51 rules never reached.
 * =================================================================================================
 *
 * Wave 1's DataTable rules are all scoped `.ss-dt-card-body ...`, and that class is emitted by
 * `resources/views/components/datatable.blade.php:37` — the shared `<x-datatable>` component that
 * serves 66 tables. **A table rendered by hand gets NONE of it**: no 13px semibold header, no row
 * rule, no hover, no pinned action column, no `--ss-dt-card-bg` dark-mode hook, no tabular money.
 *
 * `PHASE-2.5-REMAINING-AREAS-WORKLIST.md` §2 lists twenty such tables across six areas. Three of
 * them are the store area's, and they are what this slice brings in:
 *
 *   store/view-storelinks.blade.php:1   the Store-links modal, reached from TWO screens
 *                                       (store/index:93-99 and store/action:31-37). It has no
 *                                       `.card-body` at all, only `.table-responsive`, so the class
 *                                       goes on the only wrapper it has — which is the ancestor the
 *                                       selector needs, and nothing in the sheet keys on
 *                                       `.card-body` itself.
 *   store/custom_domain.blade.php:36    store.customdomain
 *   store/subdomain.blade.php:36        store.subdomain, reached from custom_domain by a button,
 *                                       so a merchant sees both in one click
 *
 * **THE WORK-LIST SAID FOUR AND IT IS THREE.** Its row #7, `customer/view.blade.php:47`, claims
 * that table is outside the scope. Measured before editing anything: line 45 already reads
 * `<div class="card-body table-border-style ss-dt-card-body">`. The audit snapshot predates the
 * commit that wrapped it. See `PHASE-2.5-WAVE-6-REANCHOR.md` for that and for three more citations
 * in the same section that no longer point where they say.
 *
 * AND THE ARABIC CASE ASYMMETRY CLOSES AS A SIDE EFFECT, which is the reason this is worth more
 * than three class attributes. `public/assets/css/style.css:27493-27498` sets
 * `.table thead th { text-transform: uppercase }` and `style-rtl.css` carries it **identically**,
 * so an Arabic merchant gets a transform that does nothing for a script with no case. The design
 * system's undo is `.ss-dt-card-body table.dataTable > thead > tr > th { text-transform: none
 * !important }` at `public/css/store-syria-ds.css:5251-5252` — scoped, so it only ever reached
 * wrapped tables. **The vendored sheet is not edited**, which CLAUDE.md forbids.
 */
class StoreTablesInDesignSystemTest extends SecurityTestCase
{
    private const VIEWS = __DIR__ . '/../../../resources/views/';
    private const DS = __DIR__ . '/../../../public/css/store-syria-ds.css';

    /** The three this slice wraps, with the line the work-list cites for each table. */
    private const WRAPPED = [
        'store/view-storelinks.blade.php',
        'store/custom_domain.blade.php',
        'store/subdomain.blade.php',
    ];

    // =============================================================================================
    //  PREMISES — the scope mechanism must be what this test believes it is
    // =============================================================================================

    public function test_premise_the_design_systems_table_rules_are_scoped_to_that_class(): void
    {
        $css = file_get_contents(self::DS);
        $this->assertNotFalse($css, 'premise: the design system stylesheet is readable');

        $this->assertStringContainsString('.ss-dt-card-body table.dataTable', $css,
            'the design system no longer scopes its DataTable rules to `.ss-dt-card-body`, so '
            . 'adding that class to a wrapper no longer brings a table into the system and this '
            . 'whole slice needs re-reading');
    }

    public function test_premise_the_scoped_uppercase_undo_is_LIVE_CSS_and_not_commentary(): void
    {
        /*
         * READ THE LIVE CSS, NOT THE FILE. A text search for `text-transform: none` in this
         * stylesheet returns lines 1428, 1437 and 1449 — **all three inside a comment block**
         * documenting a rule P2.5-WAVE-6 deleted. The work-list cited `:4747-4748` for the undo on
         * that basis, and 4747 is a dropzone dark-mode rule. The real one is at :5251-5252.
         * Comments are blanked while line structure is preserved, the way
         * `tests/Support/source_tree`-style guards do it elsewhere in this suite.
         */
        $live = self::withoutCssComments(file_get_contents(self::DS));

        $this->assertMatchesRegularExpression(
            '/\.ss-dt-card-body\s+table\.dataTable\s*>\s*thead\s*>\s*tr\s*>\s*th\s*\{[^}]*text-transform:\s*none\s*!important/s',
            $live,
            'the scoped `text-transform: none` undo is gone from the LIVE css (comments excluded), '
            . 'so wrapping a table no longer stops the vendored uppercase reaching an Arabic page'
        );
    }

    public function test_premise_the_vendored_uppercase_is_still_there_in_both_directions(): void
    {
        // If this ever stops being true the undo is unnecessary, not broken — but the reason this
        // slice exists would have changed, and that should be noticed rather than inherited.
        foreach (['style.css', 'style-rtl.css'] as $sheet) {
            $path = __DIR__ . '/../../../public/assets/css/' . $sheet;
            $this->assertFileExists($path);
            $this->assertMatchesRegularExpression(
                '/\.table\s+thead\s+th\s*\{[^}]*text-transform:\s*uppercase/s',
                (string) file_get_contents($path),
                "{$sheet} no longer uppercases table headers, so the asymmetry this slice closes "
                . 'as a side effect no longer exists'
            );
        }
    }

    // =============================================================================================
    //  THE SLICE
    // =============================================================================================

    /** @dataProvider wrappedViews */
    public function test_the_table_is_inside_the_design_systems_scope(string $view): void
    {
        $source = file_get_contents(self::VIEWS . $view);
        $this->assertNotFalse($source, "premise: {$view} is readable");

        $this->assertStringContainsString('<table', $source,
            "{$view} no longer renders a table, so this row of the work-list is stale");

        $this->assertStringContainsString('ss-dt-card-body', $source,
            "{$view} renders a hand-rolled table that is NOT inside `.ss-dt-card-body`, so it "
            . 'receives none of P2.5-WAVE-1: no 13px semibold header, no row rule, no hover, no '
            . 'pinned action column, no dark-mode surface hook, no tabular money — and the '
            . "vendored uppercase still reaches it in Arabic");
    }

    public static function wrappedViews(): array
    {
        return array_map(fn ($v) => [$v], self::WRAPPED);
    }

    /**
     * THE CLASS MUST BE AN ANCESTOR OF THE TABLE, not merely present in the file. A wrapper added
     * to the wrong element reads identically to a grep and does nothing to the page — which is the
     * failure mode a previous slice in this project shipped, where a submit button sat textually
     * inside a form and was not its descendant in the parsed tree.
     */
    public function test_the_class_is_on_an_ANCESTOR_of_the_table_in_the_parsed_tree(): void
    {
        foreach (self::WRAPPED as $view) {
            $source = (string) file_get_contents(self::VIEWS . $view);

            // Blade directives are not HTML; strip them so DOMDocument sees markup it can parse.
            $html = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}|@[a-zA-Z]+(\(.*?\))?/s', '', $source);

            $dom = new \DOMDocument();
            libxml_use_internal_errors(true);
            $dom->loadHTML('<!DOCTYPE html><html><body>' . $html . '</body></html>');
            libxml_clear_errors();

            $tables = $dom->getElementsByTagName('table');
            $this->assertGreaterThan(0, $tables->length, "premise: {$view} parses to a <table>");

            $found = false;
            for ($node = $tables->item(0)->parentNode; $node !== null; $node = $node->parentNode) {
                if ($node instanceof \DOMElement
                    && str_contains((string) $node->getAttribute('class'), 'ss-dt-card-body')) {
                    $found = true;
                    break;
                }
            }

            $this->assertTrue($found,
                "{$view}: `ss-dt-card-body` appears in the file but is NOT on an ancestor of the "
                . 'table in the parsed tree, so the design system selector never matches and the '
                . 'table looks wrapped to a grep while rendering exactly as before');
        }
    }

    /** Blanks /* ... *​/ comments, preserving line structure. */
    private static function withoutCssComments(string $css): string
    {
        $out = '';
        $i = 0;
        $n = strlen($css);

        while ($i < $n) {
            if ($css[$i] === '/' && $i + 1 < $n && $css[$i + 1] === '*') {
                $end = strpos($css, '*/', $i + 2);
                $end = $end === false ? $n : $end + 2;
                for ($k = $i; $k < $end; $k++) {
                    $out .= $css[$k] === "\n" ? "\n" : ' ';
                }
                $i = $end;
                continue;
            }
            $out .= $css[$i];
            $i++;
        }

        return $out;
    }
}
