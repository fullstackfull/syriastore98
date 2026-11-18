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
 */
class FinanceLedgerFiguresTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function merchantOnFinance(): array
    {
        [$merchant, $store] = $this->makeTenant();

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

    /** @return array<string, array{value: string, period: string, hint: string}> every `<x-ss.stat>` on the page, by label */
    private function tiles(string $html): array
    {
        preg_match_all(
            '/<span class="ss-stat__label">\s*(.*?)\s*<\/span>\s*<span class="ss-stat__value"[^>]*>\s*(.*?)\s*<\/span>\s*<span class="ss-stat__period">\s*(.*?)\s*<\/span>(?:\s*<p class="ss-stat__hint"[^>]*>\s*(.*?)\s*<\/p>)?/s',
            $html,
            $m,
            PREG_SET_ORDER
        );

        $tiles = [];
        foreach ($m as $row) {
            $tiles[trim(strip_tags($row[1]))] = [
                'value' => trim(preg_replace('/\s+/', ' ', strip_tags($row[2]))),
                'period' => trim(preg_replace('/\s+/', ' ', strip_tags($row[3]))),
                'hint' => trim(preg_replace('/\s+/', ' ', strip_tags($row[4] ?? ''))),
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
        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('80.00 USD', $tile['value']);
        $this->assertSame('from 31 October 2026', $tile['period'],
            'D1 Option A: the start of the verified period is stated, and it is the day after the latest order the ledger cannot see');
        $this->assertStringContainsString('How many: 2', $tile['hint'], 'the number of orders left out is stated, not just implied');
        $this->assertStringContainsString('without a financial record', $tile['hint']);
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

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('10.00'));
        $this->ledgerOrder($other, '2026-11-05 10:00:00', $this->paid('77777.00'));

        $html = $this->page($merchant);
        $tiles = $this->tiles($html);

        $this->assertSame('10.00 USD', $tiles['Payments received']['value']);
        $this->assertStringNotContainsString('77777', $html);
    }

    // ---------------------------------------------------------------------------------------------
    // Estimated gross profit
    // ---------------------------------------------------------------------------------------------

    public function test_estimated_gross_profit_counts_settled_orders_only_and_always_says_it_excludes_shipping_and_tax(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['gross_profit' => '60.00']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('100.00', ['gross_profit' => '35.50']));
        $this->ledgerOrder($store, '2026-11-07 10:00:00', $this->paid('100.00', ['payment_status' => 'cancelled', 'gross_profit' => '500.00']));   // reversed: the merchant did not keep it
        $this->ledgerOrder($store, '2026-11-08 10:00:00', ['payment_status' => 'unpaid', 'paid_amount' => '0.00', 'gross_profit' => '700.00', 'currency_code' => 'USD']);

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertSame('95.50 USD', $tile['value'], '60.00 + 35.50 from the two settled orders only; the cancelled 500.00 and the unpaid 700.00 must not count');
        $this->assertStringContainsString('Excludes shipping and tax', $tile['hint']);
    }

    public function test_estimated_gross_profit_counts_the_settled_orders_whose_cost_was_never_recorded(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00', ['product_cost' => '0.00', 'gross_profit' => '100.00']));
        $this->ledgerOrder($store, '2026-11-06 10:00:00', $this->paid('100.00', ['product_cost' => '0.00', 'gross_profit' => '100.00']));
        $this->ledgerOrder($store, '2026-11-07 10:00:00', $this->paid('100.00', ['product_cost' => '40.00', 'gross_profit' => '60.00']));

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertStringContainsString('Settled orders with no cost price recorded: 2', $tile['hint']);
        $this->assertStringContainsString('overstated', $tile['hint']);
    }

    public function test_when_every_cost_is_recorded_the_missing_cost_warning_stays_silent(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00'));

        $tile = $this->tiles($this->page($merchant))['Estimated gross profit'];

        $this->assertStringNotContainsString('no cost price recorded', $tile['hint'], 'a caveat that never goes away stops being read');
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
        $tomorrow = now()->addDay()->locale('en')->translatedFormat('j F Y');

        foreach (['Payments received', 'Estimated gross profit'] as $label) {
            $this->assertSame('Not available', $tiles[$label]['value'],
                $label.' printed a number for a period that has not started: 0.00 reads as "no sales"');
            $this->assertSame('from '.$tomorrow, $tiles[$label]['period']);
            $this->assertStringContainsString('complete from '.$tomorrow.', so there is nothing to show yet', $tiles[$label]['hint']);
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
        $this->assertStringNotContainsString('complete from', $tile['hint']);
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

        // Collect the unrowed order's cash INSIDE the verified period, through Eloquent so the observer fires.
        \App\Models\Order::query()->findOrFail($unrowed)->update(['payment_status' => 'Paid']);

        $this->assertSame(0, DB::table('store_order_financials')->where('order_id', $unrowed)->count(),
            'premise: marking an unrowed order paid still creates no ledger row, which is the whole point');

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('80.00 USD', $tile['value'], 'the unrowed order\'s cash cannot be in the figure');
        $this->assertStringContainsString('even if they were paid inside this period', $tile['hint'],
            'the tile said orders were not included but not that money received in the period can be among them');
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
    }

    public function test_when_every_order_without_a_record_is_undated_the_period_is_all_time_and_they_are_counted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->preLedgerOrder($store, null);
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00'));

        $tile = $this->tiles($this->page($merchant))['Payments received'];

        $this->assertSame('all time', $tile['period']);
        $this->assertSame('80.00 USD', $tile['value']);
        $this->assertStringContainsString('cannot be placed in any period', $tile['hint']);
        $this->assertStringContainsString('How many: 1', $tile['hint']);
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

    public function test_the_figures_render_in_arabic_with_their_numbers_substituted(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $merchant->language = 'ar';
        $merchant->save();

        $this->preLedgerOrder($store, '2026-10-30 23:00:00');
        $this->ledgerOrder($store, '2026-11-02 08:00:00', $this->paid('80.00', ['product_cost' => '0.00', 'gross_profit' => '80.00']));
        // A row with no currency, so the `:amount :currency` sentence is rendered and its placeholders can be checked.
        $this->ledgerOrder($store, '2026-11-03 08:00:00', $this->paid('15.00', ['currency_code' => null]));

        $html = $this->page($merchant);
        $tiles = $this->tiles($html);

        $this->assertArrayHasKey('المبالغ المحصّلة', $tiles, 'Payments received is untranslated');
        $this->assertArrayHasKey('إجمالي الربح التقديري', $tiles, 'Estimated gross profit is untranslated');
        $this->assertStringContainsString('المتاح للسحب', $html);

        $this->assertStringContainsString('من ', $tiles['المبالغ المحصّلة']['period']);
        $this->assertStringContainsString('1', $tiles['المبالغ المحصّلة']['hint'], 'the count of left-out orders did not survive translation');
        $this->assertStringNotContainsString(':count', $html, 'a placeholder reached the page unsubstituted');
        $this->assertStringNotContainsString(':date', $html);
        $this->assertStringNotContainsString(':amount', $html);
        $this->assertStringNotContainsString(':currency', $html);
        $this->assertStringContainsString('15.00 USD', $tiles['المبالغ المحصّلة']['hint'], 'the assumed-currency sentence did not render in Arabic');
        $this->assertStringContainsString('1', $tiles['إجمالي الربح التقديري']['hint']);
    }

    public function test_reading_the_ledger_changes_nothing_that_is_stored(): void
    {
        [$merchant, $store] = $this->merchantOnFinance();

        $this->ledgerOrder($store, '2026-11-05 10:00:00', $this->paid('100.00'));
        $before = [
            'ledger' => DB::table('store_order_financials')->orderBy('id')->get()->toArray(),
            'wallet' => DB::table('store_wallets')->orderBy('id')->get()->toArray(),
            'orders' => DB::table('orders')->orderBy('id')->get()->toArray(),
        ];

        $this->page($merchant);

        $this->assertEquals($before['ledger'], DB::table('store_order_financials')->orderBy('id')->get()->toArray());
        $this->assertEquals($before['orders'], DB::table('orders')->orderBy('id')->get()->toArray());
        // The wallet row is created lazily on first visit (existing behaviour); its VALUES are what must not move.
        foreach (DB::table('store_wallets')->where('store_id', $store->id)->get() as $wallet) {
            $this->assertEquals(0, (float) $wallet->available_balance);
            $this->assertEquals(0, (float) $wallet->total_income);
        }
    }
}
