<?php

namespace Tests\Unit\Ui;

use PHPUnit\Framework\TestCase;

/**
 * THE FINANCE TABLES WENT THROUGH THE DESIGN SYSTEM, NOT THE BOOTSTRAP DEFAULT.  P2.5-WAVE-10
 * =================================================================================================
 *
 * The three finance screens render the merchant's own money -- a wallet-transaction ledger and an
 * order-financials table on /finance, and the withdrawal-requests table on /finance/withdrawals.
 * All three were hand-written `<table class="table table-hover">` with `<thead class="table-light">`:
 * the stock Bootstrap treatment, which gives a flat grey header band, a hover tint the design
 * system does not use anywhere else, and -- measured on the live page -- a Status column clipped to
 * "Co..." / "STA..." because the default header carries no width discipline.
 *
 * `.ss-table` already existed and already answered every one of those questions (Wave 6 settled its
 * header case so English and Arabic read alike; `.ss-table__num` aligns money to the end edge in
 * both directions). The defect was reach: the finance tables, the ones that most need a money-grade
 * table, were the ones still on the default. This asserts they are not.
 *
 * NOT A DUPLICATE OF FinanceMoneyFiguresTest, which owns the CELL question -- that every money
 * figure in these tables uses `.ss-money`'s tabular figures. This owns the TABLE question -- that
 * the surrounding `<table>` is the design system's, not Bootstrap's. A table can carry tabular
 * money cells inside a stock Bootstrap frame; both halves have to hold.
 *
 * THE BITE. Reverting any of the three tables to `class="table table-hover"` /
 * `<thead class="table-light">` fails test_every_finance_table_is_a_design_system_table (the table
 * loses `ss-table`) AND test_no_finance_table_falls_back_to_the_bootstrap_default (the ad-hoc
 * markers return). A naive `str_contains($file, 'ss-table')` would survive the revert of two of the
 * three tables, so the count is asserted per table, not per file.
 *
 * A UNIT TEST reading the Blade source: no database, no container, no module loader.
 */
class FinanceTablesUseDesignSystemTest extends TestCase
{
    private const SHEET = 'public/css/store-syria-ds.css';

    /**
     * Each finance view and the number of `<table>` elements measured in it. The count is a
     * premise: a view that stops rendering a table it used to (a magic-window slip, a refactor
     * that drops one) makes the "every table" assertion pass over fewer tables than it claims to
     * cover, which is the commonest way a census in this project has quietly narrowed.
     *
     * @var array<string,int>
     */
    private const FINANCE_TABLES = [
        'resources/views/finance/index.blade.php' => 2,
        'resources/views/finance/withdrawals.blade.php' => 1,
        'resources/views/finance/unreconciled-captures.blade.php' => 1,
    ];

    private function read(string $rel): string
    {
        $path = dirname(__DIR__, 3) . '/' . $rel;
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }

    /** @return list<string> every `<table ...>` opening tag in the source */
    private function tableTags(string $source): array
    {
        preg_match_all('/<table\b[^>]*>/s', $source, $m);

        return $m[0];
    }

    /**
     * PREMISE. `.ss-table` must be a real rule in the stylesheet, or asserting the markup uses it
     * proves nothing -- a class name no CSS defines styles nothing.
     */
    public function test_the_design_system_table_rule_exists(): void
    {
        $this->assertStringContainsString(
            '.ss-table thead th',
            $this->read(self::SHEET),
            'the .ss-table system is gone from the stylesheet, so the class the finance views carry '
                . 'resolves to nothing'
        );
    }

    /** Every table on every finance screen is a design-system table. */
    public function test_every_finance_table_is_a_design_system_table(): void
    {
        foreach (self::FINANCE_TABLES as $view => $expected) {
            $tags = $this->tableTags($this->read($view));

            $this->assertGreaterThanOrEqual(
                $expected,
                count($tags),
                "premise: {$view} renders " . count($tags) . " <table> element(s), fewer than the "
                    . "{$expected} measured -- a table was dropped and is no longer covered here"
            );

            foreach ($tags as $i => $tag) {
                $this->assertMatchesRegularExpression(
                    '/\bss-table\b/',
                    $tag,
                    "table #{$i} in {$view} does not carry .ss-table, so it renders in the stock "
                        . "Bootstrap frame while the design-system tables beside it do not: {$tag}"
                );
            }
        }
    }

    /**
     * And no finance table keeps the Bootstrap default it was moved off. `table-hover` is the
     * host hover tint the design system uses nowhere else; `thead.table-light` is the flat grey
     * header band `.ss-table thead th` replaces. Either returning is the regression this wave fixed.
     */
    public function test_no_finance_table_falls_back_to_the_bootstrap_default(): void
    {
        foreach (array_keys(self::FINANCE_TABLES) as $view) {
            $source = $this->read($view);

            $this->assertStringNotContainsString(
                'table-hover',
                $source,
                "{$view} still carries the Bootstrap `table-hover` tint, which the design-system "
                    . 'tables do not use'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<thead\b[^>]*\bclass\s*=\s*"[^"]*\btable-light\b/s',
                $source,
                "{$view} still carries a `thead.table-light` header band instead of the design "
                    . "system's `.ss-table thead th`"
            );
        }
    }
}
