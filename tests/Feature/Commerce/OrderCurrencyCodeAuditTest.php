<?php

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Security\OrderCurrencyCodeAudit;
use Tests\Support\StripsSourceComments;

/**
 * D-N34 — THE READ-ONLY COUNT THE OWNER NEEDS, PROVEN IN BOTH DIRECTIONS.
 *
 * `tests/Support/Security/bin/report-order-currency-codes.php` is what gets run against a restored
 * copy of production; `OrderCurrencyCodeAudit` is the logic it wraps. A report that has only ever
 * been run against the data it was written from cannot be told from one that hard-codes its
 * answer, so both the clean case and the dirty one are driven here.
 *
 * Fixtures are written with `DB::table()` on purpose: `Order`'s own `creating` hook would stamp a
 * currency onto a row that is meant to have none.
 */
class OrderCurrencyCodeAuditTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private function order(int $storeId, ?string $currency): void
    {
        DB::table('orders')->insert([
            'product_order_id' => 'CA-' . $this->uniqueFixtureRef(),
            'store_id' => $storeId, 'customer_id' => 0, 'is_guest' => 1,
            'final_price' => 10.00, 'currency_code' => $currency,
            'order_date' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function report(): array
    {
        return (new OrderCurrencyCodeAudit(DB::connection()))->report();
    }

    public function test_a_book_of_coded_orders_reports_nothing_to_decide(): void
    {
        [, $store] = $this->makeTenant();

        $this->order($store->id, 'USD');
        $this->order($store->id, 'USD');
        $this->order($store->id, null);

        $report = $this->report();

        $this->assertSame(3, $report['orders']);
        $this->assertSame(2, $report['recorded_as_a_code']);
        $this->assertSame(1, $report['not_recorded'],
            'An order with no recorded currency is its own case and must not be reported as a symbol.');
        $this->assertSame(0, $report['recorded_as_something_else']);
        $this->assertSame([], $report['values_that_are_not_codes']);
        $this->assertSame(0, $report['stores_holding_such_orders']);
        $this->assertSame(0, $report['stores_whose_orders_carry_more_than_one_value']);
    }

    public function test_orders_recorded_with_a_symbol_are_counted_by_value_and_by_store(): void
    {
        [, $pounds] = $this->makeTenant();
        [, $dollars] = $this->makeTenant();
        [, $clean] = $this->makeTenant();

        // The measured shape: one store's web orders carry the code, its API orders the symbol.
        $this->order($pounds->id, 'SYP');
        $this->order($pounds->id, '$');
        $this->order($pounds->id, '$');
        $this->order($dollars->id, 'ل.س');
        $this->order($clean->id, 'EUR');
        $this->order($clean->id, '');

        $report = $this->report();

        $this->assertSame(6, $report['orders']);
        $this->assertSame(2, $report['recorded_as_a_code']);
        $this->assertSame(1, $report['not_recorded']);
        $this->assertSame(3, $report['recorded_as_something_else']);
        $this->assertSame(['$' => 2, 'ل.س' => 1], $report['values_that_are_not_codes'],
            'The values are not reported most frequent first, or one of them was miscounted.');
        $this->assertSame(2, $report['stores_holding_such_orders']);
        $this->assertSame(1, $report['stores_whose_orders_carry_more_than_one_value'],
            'Only the pounds store disagrees with itself: one code and one symbol. A store whose '
            . 'every order carries the same symbol is wrong, but consistently.');
    }

    /**
     * A LONGER CODE IS STILL A CODE. The admin API's validator allows up to ten characters for the
     * store's currency, so a rule of "exactly three letters" would report a legitimate code as a
     * defect and inflate the figure the owner is asked to decide on.
     */
    public function test_a_code_longer_than_three_letters_is_not_reported_as_a_symbol(): void
    {
        [, $store] = $this->makeTenant();

        $this->order($store->id, 'USDT');
        $this->order($store->id, 'usd');

        $report = $this->report();

        $this->assertSame(2, $report['recorded_as_a_code']);
        $this->assertSame(0, $report['recorded_as_something_else']);
    }

    /**
     * THE FIGURE IS A FLOOR, AND THAT IS ASSERTED RATHER THAN ONLY WRITTEN DOWN. A symbol made of
     * Latin letters passes the code rule, so it is counted as a code. An independent review raised
     * this against my own fixtures, which use `SP` as a symbol. Pinning it means the limit cannot
     * quietly stop being true, in either direction.
     */
    public function test_a_symbol_made_of_letters_is_counted_as_a_code_and_the_report_says_so(): void
    {
        [, $store] = $this->makeTenant();

        $this->order($store->id, 'SP');     // a symbol, in Latin letters

        $report = $this->report();

        $this->assertSame(1, $report['recorded_as_a_code'],
            'The audit no longer counts a letters-only symbol as a code, so the floor stated in its '
            . 'docblock and printed by its script is wrong in the other direction.');
        $this->assertSame(0, $report['recorded_as_something_else']);

        $script = (string) file_get_contents(base_path('tests/Support/Security/bin/report-order-currency-codes.php'));

        $this->assertStringContainsString('THE COUNT IS A FLOOR', $script,
            'The script no longer tells its reader that the count is a floor.');
    }

    /**
     * IT CANNOT WRITE. The safety model is the absence of a write statement, in the class and in
     * the script that wraps it — so that is what is asserted, with comments stripped because both
     * files explain at length what they do not do.
     */
    public function test_neither_the_audit_nor_its_script_contains_a_write(): void
    {
        foreach ([
            'tests/Support/Security/OrderCurrencyCodeAudit.php',
            'tests/Support/Security/bin/report-order-currency-codes.php',
        ] as $path) {
            $source = $this->sourceWithoutComments($path);

            $this->assertNotSame('', trim($source), "PREMISE: {$path} could not be read.");

            $this->assertDoesNotMatchRegularExpression(
                '/->\s*(insert|insertGetId|insertOrIgnore|update|updateOrInsert|upsert|delete|truncate|increment|decrement|statement|unprepared)\s*\(|\b(INSERT|UPDATE|DELETE|TRUNCATE|ALTER|DROP)\b\s/i',
                $source,
                "{$path} contains a write. This report must be safe to point at a copy of production."
            );
        }
    }
}
