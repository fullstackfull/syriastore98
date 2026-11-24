<?php

namespace Tests\Feature\Reports;

use App\Http\Controllers\ReportController;
use App\Models\Store;
use App\Support\Metrics\FinanceLedgerFigures;
use App\Support\Metrics\MetricWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `Payments received` OVER A WINDOW THE MERCHANT CHOSE. Owner decisions D1 (Option A) and D2.
 * =================================================================================================
 *
 * D2 approved four labels that disagree on purpose. Three of them are on `/finance`, where there is no
 * date filter, so `FinanceLedgerFigures` was built to CHOOSE the period itself -- "never chosen by the
 * caller" -- and read exactly the span `LedgerCoverage` can vouch for.
 *
 * The sales report is the opposite: six demand tiles already answer a window the merchant picked from a
 * date picker, and `Payments received` has to join them. So the assembler needs a second mode, and the
 * mode is the whole risk of this slice: a figure that silently answers a DIFFERENT period from the tiles
 * beside it is the wrong-number-that-looks-right, and a figure that answers the requested period by
 * reading a ledger that has no rows for it reports a real business as a dead one.
 *
 * D1 OPTION A IS THE RULE, and it has two halves that are easy to confuse:
 *
 *   * "Show them, with the start date stated" -- so a window that OVERLAPS the verified period is
 *     answered, narrowed to the overlap, and the tile's own period names the narrowed span. It must
 *     never print the requested span over a figure that covers less of it.
 *   * "A merchant asking for last year gets a refusal with a reason instead of a figure" -- so a window
 *     with NO overlap is refused. Not zero. Zero is a statement about the business; the ledger is making
 *     a statement about itself.
 *
 * THE WINDOW IS NEVER WIDENED. `MetricWindow`'s constructor throws when `from` is after `to`, so the
 * no-overlap case has to be caught before the window is built -- the same ordering defect that reached
 * a live HTTP 500 in `AnalyticsPeriod` when a clamp was applied without re-asserting the ordering.
 *
 * DATED BY `paid_at`, NOT BY `order_date`, and this test pins it because "add an `order_date` predicate"
 * is the obvious wrong repair: it would silently drop cash collected this month on an order placed last
 * month, which is precisely the money a collection figure exists to show.
 *
 * THE TENANT UNDER TEST IS NEVER STORE 1. Store 1 is the id `getCurrentStore()` falls back to for a
 * storeless operator and the one a careless `forStore(1, ...)` would hard-code, so it is a decoy here
 * with its own distinctive ledger.
 */
class SalesReportCollectionFigureTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const ISOLATES = ["\u{2066}", "\u{2069}"];

    /** Store 1's own collection, which no other store's figure may ever show. */
    private const DECOY_PAID = '31337.31';

    private function plain(?string $value): ?string
    {
        return $value === null ? null : str_replace(self::ISOLATES, '', $value);
    }

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

    private function preLedgerOrder(Store $store, ?string $orderDate): int
    {
        $order = $this->makeOrder($store, ['order_date' => $orderDate]);
        DB::table('store_order_financials')->where('order_id', $order->id)->delete();

        return $order->id;
    }

    private function paid(string $amount, string $paidAt): array
    {
        return [
            'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => $paidAt,
            'currency_code' => 'USD', 'product_cost' => '40.00', 'gross_profit' => '60.00',
        ];
    }

    /** A tenant that is not store 1, behind a decoy whose figures differ from every assertion here. */
    private function tenantBehindADecoy(): Store
    {
        [, $decoy] = $this->makeTenant();
        $this->ledgerOrder($decoy, '2026-10-01 10:00:00', $this->paid(self::DECOY_PAID, '2026-10-01 10:00:00'));

        [, $store] = $this->makeTenant();
        $this->assertGreaterThan(1, $store->id, 'premise: the tenant under test is not store 1');

        return $store;
    }

    private function figure(Store $store, ?MetricWindow $window, ?string $label = null): array
    {
        return FinanceLedgerFigures::forStore($store->id, 'USD', $window, $label)['payments'];
    }

    /** `ReportController::salesCollectionTile()`, which is private and whose handlers the suite cannot reach. */
    private function controllerTile(): ?array
    {
        $method = new \ReflectionMethod(ReportController::class, 'salesCollectionTile');
        $method->setAccessible(true);

        return $method->invoke(
            new ReportController(),
            Request::create('/', 'GET', ['chart_data' => 'year']),
            'This year',
        );
    }

    /**
     * THE PLATFORM OPERATOR WITH NO STORE MUST NOT BE SHOWN STORE 1's COLLECTED CASH.
     *
     * `getCurrentStore()` does not fail closed for them: for a `super admin` it returns the
     * hard-coded literal 1 (`helper.php:873`), and store 1 is an ordinary merchant's tenant --
     * owner decision 7's words at `StoreFinanceController:833` are that "store 1 belongs to a
     * merchant who did not ask for it." `/finance` refuses this exact principal at eight call
     * sites before reaching the SAME assembler, so without the limb in `salesCollectionTile()`
     * one assembler would be refused on one surface and render store 1's money on another.
     *
     * STORE 1 IS GIVEN A DISTINCTIVE LEDGER HERE, so a regression is a visible wrong number
     * rather than an empty tile that could be mistaken for the correct refusal.
     *
     * Found by the authorization review of this slice. No test encoded this boundary, because the
     * six demand tiles beside this one have disclosed store 1's ORDER VALUE to the same principal
     * for as long as they have existed -- recorded as `GAP-223`, not changed here.
     */
    public function test_a_platform_operator_who_has_chosen_no_store_is_shown_no_collection_tile(): void
    {
        [, $storeOne] = $this->makeTenant();
        $this->assertSame(1, $storeOne->id, 'premise: the decoy really is store 1, the id getCurrentStore() falls back to');
        $this->ledgerOrder($storeOne, '2026-11-05 09:00:00', $this->paid(self::DECOY_PAID, '2026-11-05 09:00:00'));

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $this->actingAs($operator);

        $this->assertNull(
            $this->controllerTile(),
            'an operator who has chosen no store must get NO tile, never store 1\'s collected cash'
        );
    }

    public function test_an_operator_who_has_chosen_a_store_still_reads_that_stores_figure(): void
    {
        // The limb must decline to GUESS a tenant, not refuse an operator who named one.
        [, $storeOne] = $this->makeTenant();
        $this->ledgerOrder($storeOne, '2026-11-05 09:00:00', $this->paid(self::DECOY_PAID, '2026-11-05 09:00:00'));

        [, $chosen] = $this->makeTenant();
        $this->ledgerOrder($chosen, '2026-11-05 09:00:00', $this->paid('222.00', '2026-11-05 09:00:00'));
        DB::table('settings')->insert([
            'store_id' => $chosen->id, 'name' => 'defult_currancy', 'value' => 'USD',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $operator = $this->makePlatformAdmin(['current_store' => $chosen->id]);
        $this->actingAs($operator);

        $tile = $this->controllerTile();

        $this->assertNotNull($tile, 'an operator who HAS chosen a store is not what the limb refuses');
        $this->assertSame('222.00 USD', $this->plain($tile['value']));
        $this->assertStringNotContainsString('31,337.31', (string) $tile['value'], 'store 1 is the decoy');
    }

    public function test_a_merchant_with_a_store_is_unaffected_by_the_operator_limb(): void
    {
        [, $storeOne] = $this->makeTenant();
        $this->ledgerOrder($storeOne, '2026-11-05 09:00:00', $this->paid(self::DECOY_PAID, '2026-11-05 09:00:00'));

        [$merchant, $store] = $this->makeTenant();
        $this->ledgerOrder($store, '2026-11-05 09:00:00', $this->paid('333.00', '2026-11-05 09:00:00'));
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => 'USD',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant);

        $tile = $this->controllerTile();

        $this->assertNotNull($tile, 'the limb must not refuse an ordinary merchant');
        $this->assertSame('333.00 USD', $this->plain($tile['value']));
    }

    /**
     * A MERCHANT WHO OWNS A STORE BUT NEVER SWITCHED TO ONE STILL GETS THE FIGURE.
     *
     * `users.current_store` is nullable and a merchant who has only ever had one store may never
     * have written it. `getCurrentStore()` resolves them anyway, through an OWNERSHIP predicate
     * (`Store::where('created_by', $user->id)->first()`, `helper.php:876-877`), and the six demand
     * tiles beside this one already rely on that. So the operator limb must test `isOperator`, not
     * just `empty($user->current_store)` -- otherwise this merchant loses the seventh tile while
     * their other six keep working.
     *
     * ADDED BECAUSE A MUTATION SURVIVED. Dropping `isOperator` from the limb left every test green:
     * `makeTenant()` always writes `current_store`, so no test exercised this state. That is the
     * same "a fixture made the mutation unreachable" shape as the default-argument mutation in
     * `SalesStatsPassesItsCollectionFigureTest`, and it is a real coverage gap rather than a
     * mutation not worth catching.
     */
    public function test_a_merchant_with_no_current_store_but_an_owned_store_still_gets_the_figure(): void
    {
        [, $storeOne] = $this->makeTenant();
        $this->ledgerOrder($storeOne, '2026-11-05 09:00:00', $this->paid(self::DECOY_PAID, '2026-11-05 09:00:00'));

        // NOT makeTenant(): that writes current_store, which is the very thing under test.
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $this->assertEmpty($merchant->current_store, 'premise: this merchant has never chosen a store');
        $this->assertSame(
            $store->id,
            (int) \Illuminate\Support\Facades\DB::table('stores')->where('created_by', $merchant->id)->value('id'),
            'premise: but they do own one, which is what getCurrentStore() falls back to'
        );

        $this->ledgerOrder($store, '2026-11-05 09:00:00', $this->paid('444.00', '2026-11-05 09:00:00'));
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => 'USD',
            'created_by' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant);

        $tile = $this->controllerTile();

        $this->assertNotNull($tile, 'the operator limb must not catch a merchant resolved by ownership');
        $this->assertSame('444.00 USD', $this->plain($tile['value']));
        $this->assertStringNotContainsString('31,337.31', (string) $tile['value'], 'store 1 is the decoy');
    }

    public function test_the_figure_answers_the_window_the_caller_asked_for(): void
    {
        $store = $this->tenantBehindADecoy();

        // Both orders are inside the verified period; only one is inside the requested window.
        $this->ledgerOrder($store, '2026-11-05 09:00:00', $this->paid('120.00', '2026-11-05 09:00:00'));
        $this->ledgerOrder($store, '2026-11-25 09:00:00', $this->paid('900.00', '2026-11-25 09:00:00'));

        $tile = $this->figure($store, MetricWindow::between(
            Carbon::parse('2026-11-01'),
            Carbon::parse('2026-11-10'),
        ));

        $this->assertSame('120.00 USD', $this->plain($tile['value']), 'the figure must cover the requested window, not everything the ledger holds');
        $this->assertStringNotContainsString('1,020.00', (string) $tile['value']);
        $this->assertStringNotContainsString('31,337.31', (string) $tile['value'], 'store 1 is a decoy; its collection must not appear');
    }

    public function test_the_figure_is_dated_by_payment_and_not_by_the_order_date(): void
    {
        $store = $this->tenantBehindADecoy();

        // Placed BEFORE the window, paid INSIDE it. A collection figure counts the money, not the order.
        $this->ledgerOrder($store, '2026-11-02 09:00:00', $this->paid('77.00', '2026-11-20 09:00:00'));

        /* AND ITS MIRROR, so this cannot pass while the window is ignored: placed INSIDE the window and
           paid outside it. An `order_date` predicate would keep exactly the wrong one of the two, and an
           assembler that ignores the window altogether would answer 500.00 for both. */
        $this->ledgerOrder($store, '2026-11-20 09:00:00', $this->paid('423.00', '2026-12-05 09:00:00'));

        $tile = $this->figure($store, MetricWindow::between(
            Carbon::parse('2026-11-15'),
            Carbon::parse('2026-11-30'),
        ));

        $this->assertSame('77.00 USD', $this->plain($tile['value']), 'money that arrived inside the window belongs to the window, whatever date the order carries');
    }

    public function test_a_window_that_reaches_back_past_the_ledger_states_where_it_really_starts(): void
    {
        $store = $this->tenantBehindADecoy();

        // The ledger's first vouched day: a pre-ledger order on 1 Nov forces coverage to begin after it.
        $this->preLedgerOrder($store, '2026-11-01 08:00:00');
        $this->ledgerOrder($store, '2026-11-10 09:00:00', $this->paid('250.00', '2026-11-10 09:00:00'));

        /* Paid AFTER the requested end, so the figure discriminates too: an assembler that ignores the
           window reads 1,250.00 and the clamped one reads 250.00. Without this row the period assertion
           below would pass against the unwindowed answer, whose period already names the same day. */
        $this->ledgerOrder($store, '2026-12-20 09:00:00', $this->paid('1000.00', '2026-12-20 09:00:00'));

        $tile = $this->figure($store, MetricWindow::between(
            Carbon::parse('2026-01-01'),
            Carbon::parse('2026-11-30'),
        ));

        $this->assertSame('250.00 USD', $this->plain($tile['value']), 'the clamp moves the start; it must not move the end');
        $this->assertNotSame('', (string) $tile['period']);
        $this->assertStringNotContainsString('1 January', (string) $tile['period'], 'the tile must not name a start the ledger cannot answer for');
        $this->assertStringContainsString('2 November', (string) $tile['period'], 'the period must name the clamped start, which is the day after the last unrowed order');
    }

    public function test_the_surfaces_own_period_label_is_kept_when_nothing_was_clamped(): void
    {
        $store = $this->tenantBehindADecoy();

        $this->ledgerOrder($store, '2026-11-05 09:00:00', $this->paid('120.00', '2026-11-05 09:00:00'));

        $tile = $this->figure(
            $store,
            MetricWindow::between(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-10')),
            'This month (November 2026)',
        );

        $this->assertSame('This month (November 2026)', $tile['period'], 'an unclamped tile must read like the six demand tiles beside it');
    }

    public function test_the_surfaces_own_period_label_is_discarded_when_the_window_was_clamped(): void
    {
        $store = $this->tenantBehindADecoy();

        /* The label the sales report would hand over is WRONG for this figure: the ledger cannot
           answer 1 November. Keeping it would print the requested span over a narrower figure --
           the wrong-number-that-looks-right, and the one mutation the first version of this file
           could not catch, because it never supplied a label at all. */
        $this->preLedgerOrder($store, '2026-11-01 08:00:00');
        $this->ledgerOrder($store, '2026-11-10 09:00:00', $this->paid('250.00', '2026-11-10 09:00:00'));

        $tile = $this->figure(
            $store,
            MetricWindow::between(Carbon::parse('2026-11-01'), Carbon::parse('2026-11-30')),
            'This month (November 2026)',
        );

        $this->assertSame('250.00 USD', $this->plain($tile['value']));
        $this->assertNotSame('This month (November 2026)', $tile['period'], 'a clamped figure must not be labelled with the period it does not cover');
        $this->assertStringContainsString('2 November', (string) $tile['period'], 'the tile must name the span it really read');
        $this->assertStringContainsString('30 November', (string) $tile['period']);
    }

    public function test_a_window_entirely_before_the_ledger_is_refused_with_a_reason_rather_than_a_zero(): void
    {
        $store = $this->tenantBehindADecoy();

        $this->preLedgerOrder($store, '2026-11-01 08:00:00');
        $this->ledgerOrder($store, '2026-11-10 09:00:00', $this->paid('250.00', '2026-11-10 09:00:00'));

        $tile = $this->figure($store, MetricWindow::between(
            Carbon::parse('2026-09-01'),
            Carbon::parse('2026-09-30'),
        ));

        $this->assertNull($tile['value'], 'a window the ledger cannot answer must be refused, never answered with zero');
        $this->assertStringContainsString('2 November', (string) $tile['hint'], 'the refusal must name the earliest date that can be answered');
    }

    public function test_passing_no_window_keeps_the_coverage_period_the_finance_screen_relies_on(): void
    {
        $store = $this->tenantBehindADecoy();

        $this->ledgerOrder($store, '2026-11-05 09:00:00', $this->paid('120.00', '2026-11-05 09:00:00'));
        $this->ledgerOrder($store, '2026-11-25 09:00:00', $this->paid('900.00', '2026-11-25 09:00:00'));

        $tile = $this->figure($store, null);

        $this->assertSame('1,020.00 USD', $this->plain($tile['value']), 'with no window the figure is the whole verified period, as /finance has always shown it');
        $this->assertSame('all time', $tile['period']);
    }

    public function test_a_window_wider_than_the_ledger_on_both_ends_still_reads_only_the_ledger(): void
    {
        $store = $this->tenantBehindADecoy();

        $this->preLedgerOrder($store, '2026-11-01 08:00:00');
        $this->ledgerOrder($store, '2026-11-10 09:00:00', $this->paid('250.00', '2026-11-10 09:00:00'));

        $tile = $this->figure($store, MetricWindow::between(
            Carbon::parse('2020-01-01'),
            Carbon::parse('2030-12-31'),
        ));

        /* THIS TEST CANNOT TELL A HONOURED WINDOW FROM AN IGNORED ONE, by construction -- nothing lies
           outside 2020..2030 -- and that is stated rather than hidden. What it does prove is that the
           clamp does not throw: `MetricWindow` rejects a window that ends before it starts, so an
           intersection written without re-asserting the ordering is an exception, not a wrong number. */
        $this->assertSame('250.00 USD', $this->plain($tile['value']));
        $this->assertStringContainsString('2 November', (string) $tile['period'], 'the widest possible request still answers from where the record begins');
    }
}
