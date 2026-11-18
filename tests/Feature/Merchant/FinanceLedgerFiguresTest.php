<?php

namespace Tests\Feature\Merchant;

use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE THREE FIGURES ON /finance, AND WHAT EACH ONE IS NOT. Owner decisions D1 and D2, P5-16.
 * =================================================================================================
 *
 * D2 asked for four figures that disagree on purpose to carry four different labels, so that a store
 * that is half cash-on-delivery, or that has just paid itself out, is not read as a failing one:
 *
 *     Order value  .  Payments received  .  Available to withdraw  .  Estimated gross profit
 *
 * Order value lives on the sales report. The other three are here, and `Payments received` and
 * `Estimated gross profit` are the FIRST figures in the product to be read through the metric layer
 * (`App\Support\Metrics`, P5-2) rather than computed inline.
 *
 * D1 asked that a figure which reads the ledger be shown only for the period the ledger can vouch
 * for, with its start stated: `store_order_financials` has no backfill, so for every order placed
 * before it existed the true answer is "unknown" and the stored answer is zero. The rule is
 * `LedgerCoverage` and is tested in `LedgerCoverageTest`; this file tests what the merchant SEES.
 *
 * THE TILES ARE READ OFF THE PAGE, not off a view-model, because the failure this slice exists to
 * prevent is a wrong number on a screen. Every assertion that mentions an amount reads it out of the
 * tile that carries the label, never out of the whole document: P5-10 had a test that passed because
 * `1,200.00` in an adjacent tile contained the string `200.00`.
 *
 * THE TENANT UNDER TEST IS NEVER STORE 1, and store 1 is a DECOY with a distinctive ledger and a gap of its own.
 * Every earlier version of this file used the first tenant of a fresh database, which is store id 1 -- the literal
 * `getCurrentStore()` falls back to for a storeless operator and the literal a careless `forStore(1, ...)` would
 * hard-code -- so a regression that read the wrong tenant passed every page test (found by the review of the
 * fixes; mutation confirmed it). With a decoy in store 1, reading store 1 changes every figure and every period.
 *
 * MONEY CARRIES INVISIBLE ISOLATES (U+2066 .. U+2069) so an Arabic page keeps `amount CODE` together and a
 * negative sign in front. `tiles()` strips them so the figures can be compared as people read them; the tests that
 * are about the isolates read the raw markup.
 */
class FinanceLedgerFiguresTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Store 1's own figures, which no other store's page may ever show. */
    private const DECOY_PAID = '31337.31';

    private const DECOY_PAID_SHOWN = '31,337.31';

    private function merchantOnFinance(): array
    {
        // THE DECOY. It takes store id 1; the tenant under test is therefore store 2 or later.
        [, $decoy] = $this->makeTenant();
        $this->ledgerOrder($decoy, '2026-10-01 10:00:00', $this->paid(self::DECOY_PAID, ['gross_profit' => '27272.72']));
        $this->preLedgerOrder($decoy, '2026-10-02 08:00:00');                   // and a gap, so its period is not "all time"

        [$merchant, $store] = $this->makeTenant();

        $this->assertSame($decoy->id + 1, $store->id, 'premise: the tenant under test follows the decoy');
        $this->assertGreaterThan(1, $store->id, 'premise: the tenant under test is not store 1, whose id a wrong-tenant read could hard-code');

        Permission::firstOrCreate(['name' => 'View Finance Dashboard'], ['display_name' => 'View Finance Dashboard']);
        $this->grantPermission(User::find($merchant->id), 'View Finance Dashboard');

        /* Several handlers read the platform admin through Cache::remember('admin_details'). */
        Cache::forget('admin_details');

        return [User::find($merchant->id), $store];
    }

    /** The store's currency setting, written the way the app reads it (`defult_currancy`, sic). */
    private function storeCurrency(User $merchant, Store $store, string $value): void
    {
        DB::table('settings')->insert([
            'name' => 'defult_currancy', 'value' => $value, 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new \App\Models\Setting)->flushCache();
    }

    /**
     * An order on a day, whose ledger row is then set to what a scenario needs. The row is the one
     * `OrderObserver::created()` wrote -- adjusted, not fabricated -- so the fixture stays faithful to
     * how rows are really made (the same approach as FinanceInventoryValuationTest).
     */
    private function ledgerOrder(Store $store, string $orderDate, array $ledger = []): int
    {
        $order = $this->makeOrder($store, ['order_date' => $orderDate]);

        $updated = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->where('store_id', $store->id)
            ->update($ledger + ['updated_at' => now()]);

        $this->assertSame(1, $updated, 'OrderObserver::created() wrote no ledger row, so this fixture measures nothing');

        return $order->id;
    }

    /** An order the ledger cannot see: what every order placed before the table existed looks like. */
    private function preLedgerOrder(Store $store, ?string $orderDate): int
    {
        $order = $this->makeOrder($store, ['order_date' => $orderDate]);
        DB::table('store_order_financials')->where('order_id', $order->id)->delete();

        return $order->id;
    }

    private function paid(string $amount, array $more = []): array
    {
        // array_merge, NOT `+`: the union operator keeps the LEFT value for a repeated key, so a scenario's
        // override (a different status, a null currency) would be silently discarded.
        return array_merge([
            'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => '2026-11-10 12:00:00',
            'currency_code' => 'USD', 'product_cost' => '40.00', 'gross_profit' => '60.00',
        ], $more);
    }

    private const ISOLATES = ["\u{2066}", "\u{2069}"];

    /**
     * @param  bool  $raw  keep the invisible isolates (U+2066 .. U+2069) the presenter wraps money in; by default they are
     *                     stripped, so a figure compares as the person reads it
     * @return array<string, array{value: string, period: string, hint: string}> every `<x-ss.stat>` on the page, by label
     */
    private function tiles(string $html, bool $raw = false): array
    {
        preg_match_all(
            '/<span class="ss-stat__label">\s*(.*?)\s*<\/span>\s*<span class="ss-stat__value"[^>]*>\s*(.*?)\s*<\/span>\s*<span class="ss-stat__period">\s*(.*?)\s*<\/span>(?:\s*<p class="ss-stat__hint"[^>]*>\s*(.*?)\s*<\/p>)?/s',
            $html,
            $m,
            PREG_SET_ORDER
        );

        $clean = static fn (string $text): string => trim(preg_replace('/\s+/', ' ', $raw ? strip_tags($text) : str_replace(self::ISOLATES, '', strip_tags($text))));

        $tiles = [];
        foreach ($m as $row) {
            $tiles[trim(strip_tags($row[1]))] = [
                'value' => $clean($row[2]),
                'period' => $clean($row[3]),
                'hint' => $clean($row[4] ?? ''),
            ];
        }

        return $tiles;
    }

    private function page(User $merchant): string
    {
        return html_entity_decode(
            $this->actingAs($merchant->fresh())->get('/finance')->assertOk()->getContent(),
            ENT_QUOTES | ENT_HTML5
        );
    }

    // ---------------------------------------------------------------------------------------------
    // Available to withdraw
    // ---------------------------------------------------------------------------------------------

    public function test_the_wallet_tile_is_labelled_available_to_withdraw_and_says_it_is_not_revenue(): void
    {
        [$merchant] = $this->merchantOnFinance();

        $html = $this->page($merchant);

        $this->assertMatchesRegularExpression('/<h6 class="text-muted mb-2">\s*Available to withdraw\s*<\/h6>/', $html,
            'the wallet tile is no longer labelled for what it is');
        $this->assertDoesNotMatchRegularExpression('/<h6 class="text-muted mb-2">\s*Available Balance\s*<\/h6>/', $html,
            'the old label is still on the tile');
        $this->assertStringContainsString('A running balance, not revenue.', $html);
    }

    // ---------------------------------------------------------------------------------------------
    // Payments received
    // ---------------------------------------------------------------------------------------------

    public function test_payments_received_counts_what_customers_paid_including_a_later_cancellation(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.25'));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('50.00', ['payment_status' => 'cancelled']));   // paid, then cancelled: still money received
        $this->ledgerOrder($store, '2026-11-07 10:00:00', ['payment_status' => 'unpaid', 'paid_amount' => '0.00', 'currency_code' => 'USD']);
        $this->ledgerOrder($store, '2026-11-08 10:00:00', ['payment_status' => 'pending', 'paid_amount' => '0.00', 'currency_code' => 'USD']);

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        // EXACT, not "contains": P5-10 had a test that passed because `1,200.00` contains `200.00`.
        $this->assertSame('150.25 USD', $tile['value'], 'paid 100.25 + cancelled-after-paying 50.00; the two unpaid orders must not count');
        $this->assertSame('all time', $tile['period'], 'every order has a row, so all-time is verified and says so');
    }

    public function test_when_earlier_orders_have_no_financial_record_the_figure_starts_where_the_record_does_and_says_so(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, '2026-09-10 09:00:00');
        $this->preLedgerOrder($store, '2026-09-11 09:00:00');
        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('80.00 USD', $tile['value']);
        $this->assertSame('from 31 October 2026', $tile['period'],
            'D1 Option A: the start of the verified period is stated, and it is the day after the latest order the ledger cannot see');
        // The WHOLE sentence, anchored to its own number: three orders, distinct from every other figure on the tile.
        $this->assertStringContainsString(
            'Orders placed before 31 October 2026 with no financial record: 3. If any of them was paid inside this period, that payment is not included.',
            $tile['hint'],
            'the number of orders left out is stated, with the date they precede, and not just implied'
        );
        $this->assertStringNotContainsString('no date', $tile['hint'], 'no order is undated here, so that sentence must not appear');
    }

    public function test_a_store_whose_every_order_has_a_record_says_nothing_about_missing_ones(): void
    {
        // The notes only earn their place if they go away. `> 0` -> `>= 0` printed "...: 0." under both tiles of every
        // store, complete ones included.
        [$merchant, $store] = $this->merchantOnFinance();
        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('10.00'));

        foreach ($this->tiles($this->page($merchant)) as $label => $tile) {
            if (! in_array($label, ['Payments received', 'Estimated gross profit'], true)) {
                continue;
            }
            $this->assertStringNotContainsString('financial record', $tile['hint'], $label.' still talks about orders without a record');
            $this->assertStringNotContainsString('cannot be placed in any period', $tile['hint'], $label);
        }
    }

    public function test_payments_received_says_what_it_is_and_what_it_is_not(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();
        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('10.00'));

        $hint = $this->tiles($this->page($merchant))['Payments received']['hint'];

        $this->assertStringContainsString('Money customers actually paid, counted on the day it was paid', $hint);
        $this->assertStringContainsString('It is not order value and not profit.', $hint, 'D2: the label exists to stop this being read as sales');
    }

    public function test_money_that_arrived_before_the_verified_start_is_not_counted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        // A pre-ledger order has no row, so it cannot contribute; but a ledger row that is DATED before the
        // start (paid on 28 Oct for an order placed earlier and given a row) must not leak into a figure
        // whose stated period starts on 31 Oct.
        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-10-20 08:00:00', $this->paid('999.00', ['paid_at' => '2026-10-28 09:00:00']));
        $this->ledgerOrder($store, '2026-11-03 08:00:00', $this->paid('11.00', ['paid_at' => '2026-11-04 09:00:00']));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('11.00 USD', $tile['value'], 'a payment dated before the stated start was counted');
    }

    public function test_when_no_order_has_a_financial_record_the_figures_are_unavailable_and_say_why(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, '2026-09-10 09:00:00');
        $this->preLedgerOrder($store, '2026-10-01 09:00:00');

        $tiles = $this->tiles($this->page($merchant));

        foreach (['Payments received', 'Estimated gross profit'] as $label) {
            $this->assertSame('Not available', $tiles[$label]['value'], $label.' showed a number with nothing behind it');
            $this->assertStringContainsString('No order has a financial record yet', $tiles[$label]['hint']);
        }
    }

    public function test_a_store_with_no_orders_shows_zero_not_unavailable(): void
    {
        [$merchant] = $this->merchantOnFinance();

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('0.00 USD', $tile['value'], 'no orders is a real zero, not a missing figure');
        $this->assertSame('all time', $tile['period']);
    }

    public function test_another_stores_ledger_never_reaches_this_stores_figures(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();
        [, $other] = $this->makeTenant();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('10.00', ['gross_profit' => '6.00']));
        $this->ledgerOrder($other, '2026-11-05 10:00:00', $this->paid('77777.00', ['gross_profit' => '55555.50']));

        $html = $this->page($merchant);
        $tiles = $this->tiles($html);

        // BOTH tiles, value and period. The amounts print through number_format, so a leak shows as `77,777.00`, which
        // does not contain `77777` -- the earlier absence check could only fire on a number this page never prints.
        $this->assertSame('10.00 USD', $tiles['Payments received']['value']);
        $this->assertSame('6.00 USD', $tiles['Estimated gross profit']['value']);
        $this->assertSame('all time', $tiles['Payments received']['period']);
        $this->assertStringNotContainsString('77,777', $html);
        $this->assertStringNotContainsString('55,555', $html);

        // And the positive control: the other store's own page DOES show those amounts, so the absence above means something.
        $otherMerchant = \App\Models\User::find($other->created_by);
        Permission::firstOrCreate(['name' => 'View Finance Dashboard'], ['display_name' => 'View Finance Dashboard']);
        $this->grantPermission($otherMerchant, 'View Finance Dashboard');
        $this->assertSame('77,777.00 USD', $this->tiles($this->page($otherMerchant->fresh()))['Payments received']['value'],
            'positive control: the other store\'s own page shows its own money, so the absences above are not vacuous');
    }

    public function test_the_stores_own_figures_are_shown_and_store_1s_decoy_is_not(): void
    {
        // The wrong-tenant mutants (`forStore(1, ...)`, `?? 1`) all read store 1, and store 1 is the decoy.
        [$merchant, $store] = $this->merchantOnFinance();
        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('10.00'));

        $html = $this->page($merchant);

        $this->assertGreaterThan(1, $store->id);
        $this->assertStringNotContainsString(self::DECOY_PAID_SHOWN, $html, 'store 1\'s ledger reached another store\'s page');
        $this->assertStringNotContainsString('27,272.72', $html);
        $this->assertSame('10.00 USD', $this->tiles($html)['Payments received']['value']);
        $this->assertSame('all time', $this->tiles($html)['Payments received']['period'], 'store 1 has a gap of its own, so its period is not all time');
    }

    public function test_a_merchant_with_no_current_store_is_served_their_own_store_and_not_store_1(): void
    {
        // `getCurrentStore()` falls back for a principal with no `current_store`; a regression that fell back to the
        // literal 1 instead would hand store 1's totals to everyone (CustomerController recorded that exact shape as a
        // past cross-tenant defect).
        [$merchant, $store] = $this->merchantOnFinance();
        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('42.00'));

        $merchant->current_store = null;
        $merchant->save();

        $html = $this->page(\App\Models\User::find($merchant->id));

        $this->assertSame('42.00 USD', $this->tiles($html)['Payments received']['value']);
        $this->assertStringNotContainsString(self::DECOY_PAID_SHOWN, $html);
    }

    // ---------------------------------------------------------------------------------------------
    // Estimated gross profit
    // ---------------------------------------------------------------------------------------------

    public function test_estimated_gross_profit_counts_paid_orders_only_and_says_what_it_leaves_out_truthfully(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['gross_profit' => '60.00']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('100.00', ['gross_profit' => '35.50']));
        $this->ledgerOrder($store, '2026-11-07 10:00:00', $this->paid('100.00', ['payment_status' => 'cancelled', 'gross_profit' => '500.00']));   // reversed: the merchant did not keep it
        $this->ledgerOrder($store, '2026-11-08 10:00:00', ['payment_status' => 'unpaid', 'paid_amount' => '0.00', 'gross_profit' => '700.00', 'currency_code' => 'USD']);

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertSame('95.50 USD', $tile['value'], '60.00 + 35.50 from the two settled orders only; the cancelled 500.00 and the unpaid 700.00 must not count');
        // THE CLAIM HAS TO BE TRUE FOR EVERY STORE. "Excludes shipping and tax" is false for a store that enters prices
        // inclusive of tax: tax is then inside `product_price`, and so inside this figure. Shipping is excluded for all.
        $this->assertStringContainsString('Shipping is not included.', $tile['hint']);
        $this->assertStringContainsString('If your prices include tax, the tax is part of the sales value.', $tile['hint']);
        $this->assertStringNotContainsString('Excludes shipping and tax', $tile['hint'], 'a sentence that is false for inclusive-tax stores');
        $this->assertStringContainsString('counted on the day they were paid', $tile['hint']);
    }

    public function test_estimated_gross_profit_counts_the_settled_orders_whose_cost_was_never_recorded(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['product_cost' => '0.00', 'gross_profit' => '100.00']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('100.00', ['product_cost' => '0.00', 'gross_profit' => '100.00']));
        $this->ledgerOrder($store, '2026-11-07 10:00:00', $this->paid('100.00', ['product_cost' => '40.00', 'gross_profit' => '60.00']));

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertStringContainsString('At least 2 paid orders have no product cost on record', $tile['hint'],
            'a LOWER BOUND: an order that mixes costed and uncosted lines has a non-zero cost and is never counted');
        $this->assertStringContainsString('may be overstated', $tile['hint'], 'a product that truly costs nothing is counted too, so "is overstated" would be false');
        $this->assertStringContainsString('mix products with and without a cost price are not counted here', $tile['hint']);
    }

    public function test_when_every_cost_is_recorded_the_missing_cost_warning_stays_silent(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00'));

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertStringNotContainsString('no product cost on record', $tile['hint'], 'a caveat that never goes away stops being read');
    }

    // ---------------------------------------------------------------------------------------------
    // Currency
    // ---------------------------------------------------------------------------------------------

    public function test_amounts_in_more_than_one_currency_are_not_combined_and_the_page_still_renders(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('900.00', ['currency_code' => 'SYP']));

        $tiles = $this->tiles($this->page($merchant));      // a MixedCurrencyException would be an HTTP 500 here

        $this->assertSame('Not available', $tiles['Payments received']['value']);
        $this->assertStringContainsString('more than one currency', $tiles['Payments received']['hint']);
        $this->assertSame('Not available', $tiles['Estimated gross profit']['value']);
    }

    public function test_orders_from_before_currency_was_recorded_are_counted_in_the_store_currency_and_the_page_says_so(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('25.00', ['currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('125.00 USD', $tile['value']);
        $this->assertStringContainsString('records that carry no currency', $tile['hint']);
        $this->assertStringContainsString('Includes 25.00 USD', $tile['hint'], 'the assumed amount is stated so a merchant can judge it');
    }

    // ---------------------------------------------------------------------------------------------
    // Found by independent review of P5-16
    // ---------------------------------------------------------------------------------------------

    public function test_a_start_in_the_future_reads_not_available_never_zero(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));
        $this->preLedgerOrder($store, now()->format('Y-m-d H:i:s'));          // the gap is TODAY

        $tiles = $this->tiles($this->page($merchant));
        $today = now()->locale('en')->translatedFormat('j F Y');
        $tomorrow = now()->addDay()->locale('en')->translatedFormat('j F Y');

        foreach (['Payments received', 'Estimated gross profit'] as $label) {
            $this->assertSame('Not available', $tiles[$label]['value'],
                $label.' printed a number for a period that has not started: 0.00 reads as "no sales"');
            $this->assertSame('from '.$tomorrow, $tiles[$label]['period']);
            // WHAT IS KNOWN, NOT WHAT IS PROMISED. The first wording said the record "is complete from tomorrow", which
            // predicts that tomorrow's orders will all be recorded; nothing in the code knows that.
            $this->assertStringContainsString(
                'The latest order without a financial record is dated '.$today.'. A verified period can begin on '.$tomorrow.' at the earliest, so there is nothing to show yet.',
                $tiles[$label]['hint']
            );
            $this->assertStringNotContainsString('is complete from', $tiles[$label]['hint']);
        }
    }

    public function test_with_no_ledger_row_at_all_the_hint_says_so_even_when_the_latest_order_is_today(): void
    {
        // "Nothing verified" and "starts in the future" can both be true; there is no record that could be
        // "complete from tomorrow" when there is no record, so the first reason wins.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, '2026-10-01 09:00:00');
        $this->preLedgerOrder($store, now()->format('Y-m-d H:i:s'));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('Not available', $tile['value']);
        $this->assertStringContainsString('No order has a financial record yet', $tile['hint']);
        $this->assertStringNotContainsString('A verified period can begin', $tile['hint']);
        $this->assertSame('no financial records yet', $tile['period']);
    }

    public function test_an_unrowed_order_paid_later_never_reaches_the_figure_and_the_tile_says_orders_are_excluded_even_when_paid_in_the_period(): void
    {
        // The review's first finding. The period is set by ORDER date and the figure is dated by PAYMENT
        // date, so a cash-on-delivery order placed before the start and collected after it is invisible: it
        // has no ledger row, `OrderFinancialService::syncFromOrder` returns at once when there is none, and no
        // path ever creates one. The tile must not imply the period is complete.
        [$merchant, $store] = $this->merchantOnFinance();

        $unrowed = $this->preLedgerOrder($store, '2026-10-30 09:00:00');
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00', ['paid_at' => '2026-11-04 09:00:00']));

        $walletBefore = DB::table('store_wallets')->where('store_id', $store->id)->get()->toArray();
        $movementsBefore = DB::table('store_wallet_transactions')->where('store_id', $store->id)->count();

        // Collect the unrowed order's cash INSIDE the verified period, through Eloquent so the observer fires.
        \App\Models\Order::query()->findOrFail($unrowed)->update(['payment_status' => 'Paid']);

        // THE STEP THE TEST EXISTS FOR, asserted rather than assumed: deleting the update above used to leave every
        // assertion below green, so the test could not tell "collected later" from "never collected".
        $this->assertSame('Paid', (string) DB::table('orders')->where('id', $unrowed)->value('payment_status'),
            'premise: the unrowed order really was marked paid');
        $this->assertSame(0, DB::table('store_order_financials')->where('order_id', $unrowed)->count(),
            'premise: marking an unrowed order paid still creates no ledger row, which is the whole point');
        // ...and what that means for the merchant's wallet, which is the consequence GAP-213(c) asks the owner to decide.
        $this->assertEquals($walletBefore, DB::table('store_wallets')->where('store_id', $store->id)->get()->toArray(),
            'the collected cash reached the wallet, so the late-collection decision has been taken without the owner');
        $this->assertSame($movementsBefore, DB::table('store_wallet_transactions')->where('store_id', $store->id)->count());

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('80.00 USD', $tile['value'], 'the unrowed order\'s cash cannot be in the figure');
        $this->assertStringContainsString('If any of them was paid inside this period, that payment is not included.', $tile['hint'],
            'the tile said orders were not included but not that money received in the period can be among them');
    }

    public function test_a_recorded_order_placed_before_the_start_and_paid_after_it_is_counted_because_the_figure_is_by_date_paid(): void
    {
        // The period is set by ORDER date, the figures by PAYMENT date. A rowed cash-on-delivery order placed on 20 Oct
        // and collected on 4 Nov is cash received inside a period that starts on 31 Oct, and it is counted. "Add an
        // order_date predicate to the window" is the obvious wrong repair; it would drop this recorded cash and leave
        // every other test green.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-10-20 08:00:00', $this->paid('25.00', ['paid_at' => '2026-11-04 09:00:00', 'gross_profit' => '15.00']));

        $tiles = $this->tiles($this->page($merchant));

        $this->assertSame('from 31 October 2026', $tiles['Payments received']['period']);
        $this->assertSame('25.00 USD', $tiles['Payments received']['value'], 'recorded cash was dropped because the order was placed before the start');
        $this->assertSame('15.00 USD', $tiles['Estimated gross profit']['value']);
    }

    public function test_a_blank_store_currency_setting_falls_back_like_a_missing_one_and_never_prints_unknown(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();
        $this->storeCurrency($merchant, $store, '');

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('25.00', ['currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('125.00 USD', $tile['value'], '`?? "USD"` does not catch an empty string, so the fold went into a blank bucket');
        $this->assertStringNotContainsString('unknown', $tile['value']);
        $this->assertMatchesRegularExpression('/Includes 25\.00 USD/', $tile['hint'], 'the assumed amount must name a real currency');
    }

    public function test_the_stores_own_currency_setting_is_the_one_unrecorded_amounts_are_counted_in(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();
        $this->storeCurrency($merchant, $store, 'SYP');

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'SYP']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('25.00', ['currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('125.00 SYP', $tile['value']);
        $this->assertStringContainsString('Includes 25.00 SYP', $tile['hint']);
    }

    public function test_two_real_currencies_are_listed_with_their_amounts_so_the_tile_is_not_a_dead_end(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('900.00', ['currency_code' => 'SYP']));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('Not available', $tile['value'], 'nothing is converted and no single number is invented');
        $this->assertStringContainsString('100.00 USD', $tile['hint']);
        $this->assertStringContainsString('900.00 SYP', $tile['hint']);
        $this->assertStringContainsString('not combined', $tile['hint']);

        // EACH `amount CODE` IS ITS OWN LTR UNIT. Without that, an Arabic page lays the first amount out as an Arabic
        // number and merges the rest into one run, so `1,000.00 USD, 15,000,000.00 SYP` can read with the currencies
        // swapped (a UAX #9 simulation; no browser was available, so this pins the markup that prevents it).
        $raw = $this->tiles($this->page($merchant), true)['Payments received']['hint'];
        // Alphabetical by code (SYP before USD), whichever order the engine returns the groups in.
        $this->assertStringContainsString("\u{2066}900.00 SYP\u{2069}, \u{2066}100.00 USD\u{2069}", $raw);
    }

    public function test_a_negative_amount_with_no_currency_still_discloses_that_it_was_folded_in(): void
    {
        // `if ($assumed > 0.0)` suppressed the note for a zero or NEGATIVE folded amount, and profit can be
        // negative (a sale below cost). The disclosure follows the metric's own caveat now, not the sign.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['gross_profit' => '60.00', 'currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('100.00', ['gross_profit' => '-20.00', 'currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertSame('40.00 USD', $tile['value']);
        $this->assertStringContainsString('carry no currency', $tile['hint'], 'the folded amount was negative, and the note vanished with its sign');

        // The folded amount can be NEGATIVE, so it is isolated too: "Includes -20.00 USD" in a right-to-left page would
        // otherwise read as "20.00-" (the sign trailing), the same defect as the figure itself.
        $raw = $this->tiles($this->page($merchant), true)['Estimated gross profit']['hint'];
        $this->assertStringContainsString("Includes \u{2066}-20.00 USD\u{2069} from records that carry no currency", $raw);
    }

    public function test_a_negative_profit_keeps_its_sign_in_front_and_isolated_from_the_page_direction(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['gross_profit' => '-20.00']));

        $html = $this->page($merchant);
        $tile = $this->tiles($html)['Estimated gross profit'];
        $raw = $this->tiles($html, true)['Estimated gross profit'];

        $this->assertSame('-20.00 USD', $tile['value']);
        // In an RTL page the sign of "-20.00 USD" lands after the number ("USD 20.00-") unless the run is isolated; the
        // design system documents the same failure for identifiers (.ss-ident). LRI .. PDI keep it as one LTR unit.
        $this->assertSame("\u{2066}-20.00 USD\u{2069}", $raw['value'], 'the amount is not isolated, so an Arabic page can print the loss as a profit with a trailing dash');
    }

    public function test_a_lower_case_store_currency_still_matches_upper_case_rows(): void
    {
        // MySQL's GROUP BY collapses `usd` and `USD` into one bucket; a PHP array key does not. A setting saved as `syp`
        // beside rows stamped `SYP` plus one row with no currency used to read as two currencies and hid a correct total.
        [$merchant, $store] = $this->merchantOnFinance();
        $this->storeCurrency($merchant, $store, ' syp ');

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'SYP']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('25.00', ['currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('125.00 SYP', $tile['value'], 'a lower-case setting split one currency into two and the tile gave up');
        $this->assertStringContainsString('Includes 25.00 SYP', $tile['hint']);
    }

    public function test_ledger_rows_stamped_in_lower_case_are_the_same_currency_as_the_store_setting(): void
    {
        // The other half of the case above: here the ROWS are lower-case (an old setting stamped `syp`) and the store
        // setting is upper-case. On MySQL the GROUP BY would already have merged them; in PHP they are two array keys.
        [$merchant, $store] = $this->merchantOnFinance();
        $this->storeCurrency($merchant, $store, 'SYP');

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'syp']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('25.00', ['currency_code' => null]));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('125.00 SYP', $tile['value'], 'a lower-case row and the upper-case fold were read as two currencies');
    }

    public function test_a_currency_with_nothing_in_it_does_not_make_the_figure_mixed(): void
    {
        // One free order (paid, 0.00) after a change of currency adds a 0.00 bucket. The merchant was not paid in that
        // currency, so the figure is not "more than one currency" and a correct total must not be withheld for it.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('0.00', ['currency_code' => 'SYP', 'gross_profit' => '0.00']));

        $tiles = $this->tiles($this->page($merchant));

        $this->assertSame('100.00 USD', $tiles['Payments received']['value']);
        $this->assertStringNotContainsString('more than one currency', $tiles['Payments received']['hint']);
    }

    public function test_when_every_order_without_a_record_is_undated_the_period_is_all_time_and_they_are_counted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, null);
        $this->preLedgerOrder($store, null);
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('all time', $tile['period']);
        $this->assertSame('80.00 USD', $tile['value']);
        $this->assertStringContainsString('Orders with no date and no financial record cannot be placed in any period: 2.', $tile['hint']);
        $this->assertStringNotContainsString('Orders placed before', $tile['hint'], 'no order is dated, so the dated sentence must not appear');
    }

    public function test_dated_and_undated_orders_are_counted_apart_and_never_added_to_each_other(): void
    {
        // Three unrowed orders in all, two of them undated. The first version printed "3" and then "2" and it read as
        // five. Each sentence now carries its own, disjoint, number -- distinct values, so a swapped or merged count
        // cannot be satisfied by its neighbour.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, '2026-10-30 09:00:00');
        $this->preLedgerOrder($store, null);
        $this->preLedgerOrder($store, null);
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));

        $hint = $this->tiles($this->page($merchant))['Payments received']['hint'];

        $this->assertStringContainsString('Orders placed before 31 October 2026 with no financial record: 1.', $hint);
        $this->assertStringContainsString('Orders with no date and no financial record cannot be placed in any period: 2.', $hint);
        $this->assertStringNotContainsString(': 3', $hint, 'the all-time total is not a number this tile prints');
    }

    public function test_an_operator_who_chose_this_store_is_served_its_figures_with_a_200(): void
    {
        [, $store] = $this->merchantOnFinance();
        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('42.00'));

        $operator = $this->makePlatformAdmin();
        $operator->current_store = $store->id;
        $operator->save();

        $tile = $this->tiles($this->page(\App\Models\User::find($operator->id)))['Payments received'];

        $this->assertSame('42.00 USD', $tile['value'], 'an operator who chose a store reads that store, like the wallet tile');
    }

    public function test_a_staff_member_with_the_permission_reads_their_owners_store_and_no_other(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();
        [, $otherStore] = $this->makeTenant();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('42.00'));
        $this->ledgerOrder($otherStore, '2026-11-05 10:00:00', $this->paid('9999.00'));

        $staff = $this->makeStaff($merchant->fresh(), ['current_store' => $store->id]);
        $this->grantPermission(\App\Models\User::find($staff->id), 'View Finance Dashboard');

        $tile = $this->tiles($this->page(\App\Models\User::find($staff->id)))['Payments received'];

        $this->assertSame('42.00 USD', $tile['value']);
    }

    // ---------------------------------------------------------------------------------------------
    // Arabic, and what the figures leave alone
    // ---------------------------------------------------------------------------------------------

    /** Arabic, with the month name Carbon itself produces for the date, so the test fails if the locale is not honoured. */
    private function arabicDate(string $date): string
    {
        return \Carbon\Carbon::parse($date)->locale('ar')->translatedFormat('j F Y');
    }

    public function test_the_figures_render_in_arabic_with_their_numbers_substituted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        // THREE dated gaps and ONE order with no cost: the two counts differ, and neither is a digit of `15.00`, so a
        // dropped or swapped count cannot be satisfied by a neighbouring number on the same tile.
        $this->preLedgerOrder($store, '2026-10-28 23:00:00');
        $this->preLedgerOrder($store, '2026-10-29 23:00:00');
        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00', ['product_cost' => '0.00', 'gross_profit' => '80.00']));
        // A row with no currency, so the `:amount` sentence is rendered and its placeholder can be checked.
        $this->ledgerOrder($store, '2026-11-03 08:00:00', $this->paid('15.00', ['currency_code' => null]));

        $html = $this->page($merchant);
        $tiles = $this->tiles($html);

        $this->assertArrayHasKey('المبالغ المحصّلة', $tiles, 'Payments received is untranslated');
        $this->assertArrayHasKey('إجمالي الربح التقديري', $tiles, 'Estimated gross profit is untranslated');
        $this->assertStringContainsString('المتاح للسحب', $html);

        $from = $this->arabicDate('2026-10-31');
        $this->assertSame('من '.$from, $tiles['المبالغ المحصّلة']['period'], 'the period must carry the ARABIC month, not carbon\'s English fallback');
        $this->assertStringNotContainsString('October', $tiles['المبالغ المحصّلة']['period']);

        $payments = $tiles['المبالغ المحصّلة']['hint'];
        $this->assertStringContainsString('الطلبات المقدَّمة قبل '.$from.' وليس لها سجل مالي: 3.', $payments, 'the count of left-out orders did not survive translation');
        $this->assertStringContainsString('يشمل 15.00 USD من سجلات بلا عملة مسجَّلة', $payments, 'the assumed-currency sentence did not render in Arabic');
        $this->assertStringContainsString('محسوبة في يوم دفعها', $payments);

        $profit = $tiles['إجمالي الربح التقديري']['hint'];
        $this->assertStringContainsString('هناك 1 طلب مقبوض على الأقل بلا تكلفة منتجات مسجَّلة', $profit);
        $this->assertStringContainsString('إذا كانت أسعارك شاملة للضريبة فالضريبة جزء من قيمة المبيعات', $profit);

        foreach ([':count', ':date', ':amount', ':amounts', ':next', ':currency'] as $placeholder) {
            $this->assertStringNotContainsString($placeholder, $html, 'a placeholder reached the page unsubstituted');
        }
    }

    public function test_the_mixed_currency_list_renders_in_arabic_with_each_amount_isolated(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('1000.00', ['currency_code' => 'USD']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('15000000.00', ['currency_code' => 'SYP']));

        $raw = $this->tiles($this->page($merchant), true)['المبالغ المحصّلة'];

        $this->assertSame('غير متوفّر', $raw['value']);
        $this->assertStringContainsString(
            'هذه المبالغ بأكثر من عملة واحدة ولم تُجمع: '."\u{2066}15,000,000.00 SYP\u{2069}, \u{2066}1,000.00 USD\u{2069}.",
            $raw['hint'],
            'the amounts are not each isolated, so a right-to-left page can pair them with the wrong currency'
        );
    }

    public function test_a_negative_profit_is_isolated_in_arabic_too(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['gross_profit' => '-20.00']));

        $this->assertSame("\u{2066}-20.00 USD\u{2069}", $this->tiles($this->page($merchant), true)['إجمالي الربح التقديري']['value']);
    }

    public function test_the_future_start_sentence_renders_in_arabic_with_both_dates(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));
        $this->preLedgerOrder($store, now()->format('Y-m-d H:i:s'));

        $hint = $this->tiles($this->page($merchant))['المبالغ المحصّلة']['hint'];

        $today = \Carbon\Carbon::now()->locale('ar')->translatedFormat('j F Y');
        $tomorrow = \Carbon\Carbon::now()->addDay()->locale('ar')->translatedFormat('j F Y');

        $this->assertSame(
            'تاريخ آخر طلب بلا سجل مالي هو '.$today.'. ولا يمكن أن تبدأ الفترة الموثَّقة قبل '.$tomorrow.'، لذلك لا يوجد ما يُعرض بعد.',
            $hint
        );
    }

    public function test_reading_the_ledger_changes_nothing_that_is_stored(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00'));

        // EVERY table a finance page could plausibly write, compared whole -- not three of them, and not the wallet
        // against a constant. The wallet exists already (the observer provisions it with the first order), so the
        // lazy-create case is driven separately below.
        $tables = ['store_order_financials', 'store_wallets', 'store_wallet_transactions', 'store_withdrawals', 'orders', 'settings', 'users', 'stores'];
        $snapshot = static fn (): array => array_map(
            static fn (string $table): array => DB::table($table)->orderBy('id')->get()->toArray(),
            array_combine($tables, $tables)
        );

        $before = $snapshot();

        $this->page($merchant);

        $after = $snapshot();
        foreach ($tables as $table) {
            $this->assertEquals($before[$table], $after[$table], 'a read of /finance changed `'.$table.'`');
        }
    }

    public function test_the_only_write_a_first_visit_makes_is_the_one_zeroed_wallet_it_always_made(): void
    {
        // A store with no orders has no wallet yet; the page creates it (existing behaviour, refused for an operator who
        // has chosen no store). The new block must add nothing to that.
        [$merchant, $store] = $this->merchantOnFinance();

        $this->assertSame(0, DB::table('store_wallets')->where('store_id', $store->id)->count(), 'premise: no wallet before the visit');

        $this->page($merchant);

        $wallets = DB::table('store_wallets')->where('store_id', $store->id)->get();
        $this->assertCount(1, $wallets);
        $this->assertEquals(0, (float) $wallets[0]->available_balance);
        $this->assertEquals(0, (float) $wallets[0]->total_income);
        $this->assertSame(0, DB::table('store_wallet_transactions')->where('store_id', $store->id)->count());
        $this->assertSame(0, DB::table('store_withdrawals')->where('store_id', $store->id)->count());
    }
}
