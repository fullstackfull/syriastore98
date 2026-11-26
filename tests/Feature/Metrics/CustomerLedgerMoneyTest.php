<?php

namespace Tests\Feature\Metrics;

use App\Models\User;
use App\Support\Metrics\CustomerLedgerMoney;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * GAP-156 / P05-08 -- the two customer money figures, and the ways each could be wrong.
 *
 * The fixture is the metric layer's own two-store shape, because a customer figure that is right
 * for store A and wrong across the tenant boundary is the defect this whole layer exists to
 * prevent: `Order` is in no `scoped_models` list, so nothing adds `store_id` for any principal.
 */
final class CustomerLedgerMoneyTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 1;

    private const STORE_B = 2;

    /** Deliberately NOT 1 and 2: a bug that reads a store id as a customer id must not pass. */
    private const ALICE = 41;

    private const BOB = 42;

    private array $wallets = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::STORE_A => 'cust-a', self::STORE_B => 'cust-b'] as $id => $slug) {
            $owner = User::factory()->create([
                'email' => $slug . '-owner@example.test',
                'type' => 'admin',
            ]);

            DB::table('stores')->insert([
                'id' => $id, 'name' => 'Store ' . $id, 'slug' => $slug,
                'email' => $slug . '@example.test', 'theme_id' => 'stylique',
                'created_by' => $owner->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            $this->wallets[$id] = DB::table('store_wallets')->insertGetId([
                'store_id' => $id, 'currency_code' => 'USD',
                'available_balance' => 0, 'pending_balance' => 0,
                'withdrawal_pending_balance' => 0, 'total_income' => 0, 'total_withdrawn' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    // ------------------------------------------------------------------ fixture

    private function order(int $customerId, array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'ORD-' . uniqid('', true),
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 100.0,
            'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'customer_id' => $customerId,
            'store_id' => self::STORE_A,
            'order_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function ledger(int $orderId, array $attributes = []): int
    {
        $storeId = (int) ($attributes['store_id'] ?? self::STORE_A);

        return DB::table('store_order_financials')->insertGetId(array_merge([
            'store_id' => $storeId,
            'wallet_id' => $this->wallets[$storeId],
            'order_id' => $orderId,
            'currency_code' => 'USD',
            'product_amount' => 100.0, 'discount_amount' => 0.0,
            'tax_amount' => 0.0, 'shipping_amount' => 0.0,
            'customer_total' => 100.0,
            'product_cost' => 40.0, 'gross_profit' => 60.0,
            'payment_status' => 'unpaid',
            'paid_amount' => 0.0, 'remaining_amount' => 100.0,
            'paid_at' => null,
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function movement(int $orderId, array $attributes = []): int
    {
        $storeId = (int) ($attributes['store_id'] ?? self::STORE_A);

        return DB::table('store_wallet_transactions')->insertGetId(array_merge([
            'store_id' => $storeId,
            'wallet_id' => $this->wallets[$storeId],
            'currency_code' => 'USD',
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 100.0,
            'balance_before' => 0.0, 'balance_after' => 100.0,
            'reference_type' => 'order', 'reference_id' => $orderId,
            'description' => 'test', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    /**
     * Named `figures()`, not `metric()`: `MetricResult::metric()` is the RESULT's accessor for its
     * own machine key, and a helper of the same name here made the key assertion below read as if
     * it were asking the object under test rather than the value it returned. It also sent me to
     * a method that does not exist -- `MetricResult` has no `key()` at all.
     */
    private function figures(): CustomerLedgerMoney
    {
        return new CustomerLedgerMoney();
    }

    private function scopeA(): MetricScope
    {
        return MetricScope::store(self::STORE_A);
    }

    private function allTime(): MetricWindow
    {
        return MetricWindow::allTime();
    }

    // ------------------------------------------------------------------ positive

    public function test_a_paid_order_counts_in_both_figures(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);
        $this->movement($order);

        $this->assertSame(
            ['USD' => 100.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency()
        );

        $this->assertSame(
            ['USD' => 100.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency()
        );
    }

    // ------------------------------------------------------------------ the whole point: they differ

    /**
     * THE CASE THIS CLASS EXISTS FOR. A shopper pays, then the order is cancelled. The customer
     * DID pay -- so historical collected payments still counts it -- and the merchant did NOT keep
     * it, so net retained does not. Labelling either as the other is the thing the owner's
     * instruction forbids, and this case is what makes the two numbers observably different.
     */
    public function test_a_paid_then_cancelled_order_is_collected_but_not_retained(): void
    {
        $order = $this->order(self::ALICE);

        // P4-F124 preserves `paid_amount` through the reversal; the status carries the reversal.
        $this->ledger($order, [
            'payment_status' => 'cancelled', 'paid_amount' => 100.0, 'paid_at' => now(),
        ]);

        // The movement record carries both legs, which is why the signed sum nets to zero.
        $this->movement($order);
        $this->movement($order, [
            'type' => 'order_cancelled', 'direction' => 'debit', 'amount' => 100.0,
        ]);

        $this->assertSame(
            ['USD' => 100.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'Historical collected payments dropped a payment the shopper really made. Cancellation '
            . 'does not un-make it; section 2 exists to say so.'
        );

        $this->assertSame(
            ['USD' => 0.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'Net retained receipts counted money the merchant gave back. The reversal writes its '
            . 'own debit and the signed sum must subtract it.'
        );
    }

    public function test_a_refunded_order_is_collected_but_not_retained(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, [
            'payment_status' => 'refunded', 'paid_amount' => 80.0, 'paid_at' => now(),
        ]);
        $this->movement($order, ['amount' => 80.0]);
        $this->movement($order, [
            'type' => 'order_refunded', 'direction' => 'debit', 'amount' => 80.0,
        ]);

        $this->assertSame(['USD' => 80.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());

        $this->assertSame(['USD' => 0.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());
    }

    public function test_the_two_figures_never_share_a_key(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 50.0, 'paid_at' => now()]);
        $this->movement($order, ['amount' => 50.0]);

        $collected = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);
        $retained = $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame(CustomerLedgerMoney::COLLECTED, $collected->metric());
        $this->assertSame(CustomerLedgerMoney::NET_RETAINED, $retained->metric());
        $this->assertNotSame($collected->metric(), $retained->metric(),
            'The two figures share a machine key, so a surface could print one under the other\'s '
            . 'heading and nothing would notice.');
    }

    // ------------------------------------------------------------------ negative

    public function test_an_unpaid_order_counts_in_neither(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order);  // unpaid, paid_amount 0, no movement at all

        $this->assertSame([],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'An order nobody paid for was counted as money the shopper handed over.');

        $this->assertSame([],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());
    }

    /**
     * A cancelled order that was NEVER paid contributes nothing to either figure. Without the
     * `paid_amount > 0` limb, section 2's reversed-but-captured arm would sweep it in at zero and
     * inflate the row count, making "no rows" and "rows that summed to nothing" indistinguishable.
     */
    public function test_a_cancelled_order_that_was_never_paid_counts_in_neither(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'cancelled', 'paid_amount' => 0.0]);

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame([], $result->byCurrency());
        $this->assertSame(0, $result->rowCount(),
            'A never-paid cancellation was counted as a row, so a zero figure can no longer be '
            . 'told apart from no records at all.');
    }

    // ------------------------------------------------------------------ cross-tenant

    public function test_another_tenants_orders_for_the_same_customer_are_not_counted(): void
    {
        // The same shopper buys from BOTH stores. A customer row is per-store in this product,
        // but `orders.customer_id` is just an integer and nothing stops the ids coinciding.
        $mine = $this->order(self::ALICE);
        $this->ledger($mine, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);
        $this->movement($mine, ['amount' => 10.0]);

        $theirs = $this->order(self::ALICE, ['store_id' => self::STORE_B]);
        $this->ledger($theirs, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 999.0, 'paid_at' => now(),
        ]);
        $this->movement($theirs, ['store_id' => self::STORE_B, 'amount' => 999.0]);

        $this->assertSame(['USD' => 10.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'Store A was shown money this shopper paid to store B.');

        $this->assertSame(['USD' => 10.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'Store A was shown wallet movement belonging to store B.');
    }

    /**
     * THE SECOND PREDICATE EARNS ITS KEEP HERE. The ledger row says store A and the order says
     * store B. `Metric::financials()` scopes the ledger side structurally; without the explicit
     * `orders.store_id` predicate on the join, this row would be read under store A's heading.
     */
    public function test_a_ledger_row_whose_order_belongs_to_another_store_is_not_counted(): void
    {
        $foreignOrder = $this->order(self::ALICE, ['store_id' => self::STORE_B]);

        $this->ledger($foreignOrder, [
            'store_id' => self::STORE_A,  // disagrees with its own order, on purpose
            'payment_status' => 'paid', 'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $this->assertSame([],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'A ledger row was trusted for its own store_id while the order it belongs to is '
            . 'another tenant\'s. Both sides of the join need the predicate.');
    }

    /**
     * F3's FIX, AND THE ONLY SCOPE THAT CAN SEE IT. With a single-store scope, pinning both sides
     * INTO the scope and pinning them TO EACH OTHER are equivalent, so the case above passes
     * either way. With a MULTI-store scope they are not: a row whose order says B and whose ledger
     * says A satisfies `whereIn(...[A,B])` on both sides and is readmitted -- with no store
     * dimension in the grouping to attribute it by. Only the column-to-column condition excludes
     * it, and only this case can tell the two apart.
     *
     * Multi-store scopes are not hypothetical in this layer: `HomeController:288` and
     * `PlatformAnalyticsFigures` build them for the operator.
     */
    public function test_a_mismatched_row_is_excluded_even_when_both_stores_are_in_scope(): void
    {
        $foreignOrder = $this->order(self::ALICE, ['store_id' => self::STORE_B]);

        $this->ledger($foreignOrder, [
            'store_id' => self::STORE_A,   // disagrees with its own order
            'payment_status' => 'paid', 'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $honest = $this->order(self::ALICE);
        $this->ledger($honest, ['payment_status' => 'paid', 'paid_amount' => 7.0, 'paid_at' => now()]);

        $bothStores = MetricScope::stores([self::STORE_A, self::STORE_B]);

        $this->assertSame(['USD' => 7.0],
            $this->figures()->collected($bothStores, $this->allTime(), self::ALICE)->byCurrency(),
            'A ledger row whose order belongs to a different store was readmitted because BOTH '
            . 'stores are in scope. The tenant condition has to tie the two tables to each other, '
            . 'not merely place each inside the scope.');
    }

    public function test_another_customers_money_is_not_counted(): void
    {
        $hers = $this->order(self::ALICE);
        $this->ledger($hers, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $his = $this->order(self::BOB);
        $this->ledger($his, ['payment_status' => 'paid', 'paid_amount' => 77.0, 'paid_at' => now()]);

        $this->assertSame(['USD' => 10.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());

        $this->assertSame(['USD' => 77.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::BOB)->byCurrency());
    }

    // ------------------------------------------------------------------ mixed currency

    public function test_two_currencies_stay_two_buckets_and_are_never_summed(): void
    {
        $usd = $this->order(self::ALICE);
        $this->ledger($usd, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $syp = $this->order(self::ALICE, ['currency_code' => 'SYP']);
        $this->ledger($syp, [
            'currency_code' => 'SYP', 'payment_status' => 'paid',
            'paid_amount' => 250.0, 'paid_at' => now(),
        ]);

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        /*
         * `ksort` BOTH SIDES, because PHP's `===` on arrays compares key ORDER and the order here
         * is whatever the engine returns from a GROUP BY with no ORDER BY. SQLite emits group keys
         * sorted; MySQL has guaranteed nothing since 5.7. Without this the case is green on the
         * suite's SQLite and a coin-flip on the production engine -- the exact shape of
         * false-reading this project keeps finding.
         */
        $actual = $result->byCurrency();
        ksort($actual);

        $this->assertSame(['SYP' => 250.0, 'USD' => 100.0], $actual);

        // `total()` refuses more than one bucket, which is what stops 100 USD and 250 SYP from
        // being added into "350" of nothing.
        $this->expectException(\App\Support\Metrics\MixedCurrencyException::class);
        $result->total();
    }

    // ------------------------------------------------------------------ missing ledger row

    /**
     * AN ORDER WITH NO LEDGER ROW IS INVISIBLE TO BOTH FIGURES, AND THAT IS THE COVERAGE BOUNDARY,
     * not a bug in this class. `store_order_financials` is written only by `OrderObserver::created`
     * and has no backfill, so every order placed before the table existed has no row.
     * `LedgerCoverage` is what tells a surface from when the ledger can be trusted; this test pins
     * that the metric does not invent a figure for a row that is not there.
     */
    public function test_an_order_with_no_ledger_row_contributes_nothing_rather_than_a_guess(): void
    {
        $this->order(self::ALICE, ['final_price' => 900.0, 'payment_status' => 'Paid']);

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame([], $result->byCurrency(),
            'The metric produced a figure for an order with no ledger row -- which could only come '
            . 'from `orders.final_price`, the very column GAP-156 is about.');

        $this->assertSame(0, $result->rowCount());
    }

    // ------------------------------------------------------------------ no fan-out

    /**
     * TWO MOVEMENTS ON ONE ORDER MUST NOT MULTIPLY THE ORDER, and two orders must not multiply
     * their movements. The movement join is many-to-one, and this drives it with real duplicate
     * rows. The LEDGER side is one-to-one, which this case cites rather than drives -- the case
     * below drives it, because review pointed out that the docblock claimed both halves were
     * proven here when only one was.
     */
    public function test_several_movements_on_one_order_are_each_counted_once(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $this->movement($order, ['amount' => 60.0]);
        $this->movement($order, ['amount' => 40.0]);

        $this->assertSame(['USD' => 100.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'The movement sum fanned out: two movements on one order were multiplied by the join.');

        $this->assertSame(['USD' => 100.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'The LEDGER figure changed when movements were added, which it cannot do unless the '
            . 'two reads are joined to each other.');
    }

    /**
     * A MOVEMENT OF ANOTHER REFERENCE TYPE MUST NOT JOIN BY NUMERIC COINCIDENCE. A withdrawal with
     * `reference_id = 7` is not order 7, and the `reference_type` condition lives in the JOIN so
     * it cannot be lost to a later `orWhere`.
     */
    public function test_a_movement_of_another_reference_type_is_not_attributed_to_the_order(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);
        $this->movement($order, ['amount' => 10.0]);

        $this->movement($order, [
            'reference_type' => 'withdrawal', 'type' => 'withdrawal',
            'direction' => 'debit', 'amount' => 5000.0,
        ]);

        $this->assertSame(['USD' => 10.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'A withdrawal was attributed to a customer because its reference_id matched an order id.');
    }

    public function test_a_cancelled_movement_row_is_not_counted(): void
    {
        $order = $this->order(self::ALICE);
        $this->movement($order, ['amount' => 100.0]);
        $this->movement($order, ['amount' => 70.0, 'status' => 'cancelled']);

        $this->assertSame(['USD' => 100.0],
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'A movement that was written and then voided was counted as money.');
    }

    /**
     * THE OTHER HALF OF THE NO-FAN-OUT CLAIM, DRIVEN. `uq_store_order_financial_order` is on
     * `order_id` ALONE -- not `(store_id, order_id)` -- so a second ledger row for one order is
     * not merely unexpected, it is impossible, and the SUM cannot double-count. Asserting the
     * constraint bites is what turns "cites the schema" into "proves it".
     */
    public function test_a_second_ledger_row_for_one_order_is_impossible(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]);
    }

    // ------------------------------------------------------------------ partially paid

    /**
     * `partially_paid` IS IN THE PREDICATE AND WAS IN NO FIXTURE. Review's test: deleting it from
     * the `whereIn` reddened nothing. It is a reachable enum state holding real money, and §15 now
     * declares the inclusion rather than making it silently.
     */
    public function test_a_partially_paid_order_counts_as_collected(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, [
            'payment_status' => 'partially_paid', 'paid_amount' => 40.0, 'paid_at' => now(),
        ]);

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame(['USD' => 40.0], $result->byCurrency(),
            'A partially paid order contributed nothing. The shopper handed over 40; excluding it '
            . 'commits the under-count section 2 exists to prevent.');

        $this->assertSame('included', $result->notes()['partially_paid'] ?? null,
            'The inclusion is not declared in the notes, so a reader cannot tell whether it was '
            . 'chosen or overlooked -- which is what §11 forbids.');
    }

    // ------------------------------------------------------------------ bounded windows

    /**
     * EVERY CASE ABOVE USES ALL-TIME, AND `MetricWindow::applyTo` IS A NO-OP FOR ALL-TIME. So
     * until this block existed, both `applyTo` calls could have named the WRONG COLUMN -- or been
     * deleted -- and nothing would have reddened. Review proved that by mutation.
     */
    public function test_collected_is_bounded_by_the_window(): void
    {
        $old = $this->order(self::ALICE);
        $this->ledger($old, [
            'payment_status' => 'paid', 'paid_amount' => 70.0,
            'paid_at' => now()->subDays(30),
        ]);

        $recent = $this->order(self::ALICE);
        $this->ledger($recent, [
            'payment_status' => 'paid', 'paid_amount' => 5.0,
            'paid_at' => now()->subDay(),
        ]);

        $this->assertSame(['USD' => 5.0], $this->figures()->collected(
            $this->scopeA(),
            MetricWindow::between(now()->subDays(7), now()),
            self::ALICE
        )->byCurrency());
    }

    /**
     * A CAPTURE WITH NO DATE CANNOT BE PLACED IN A PERIOD. The class documents this; nothing drove
     * it. An unknown capture date is not evidence of a capture inside the window.
     */
    public function test_a_capture_with_no_paid_at_is_excluded_from_a_bounded_window(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, [
            'payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => null,
        ]);

        $this->assertSame([], $this->figures()->collected(
            $this->scopeA(),
            MetricWindow::between(now()->subDays(7), now()),
            self::ALICE
        )->byCurrency());

        // ... and all-time still sees it, so the exclusion is the WINDOW's doing and not the
        // predicate quietly dropping an undated capture everywhere.
        $this->assertSame(['USD' => 100.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());
    }

    /**
     * THE DISCRIMINATING CASE, and the one a future edit is most likely to break: the order is
     * INSIDE the window and its movement is OUTSIDE it. Only a figure dated by the MOVEMENT
     * answers empty here; a figure dated by the order answers 100. Nothing else in this file can
     * tell those two apart.
     */
    public function test_net_retained_is_dated_by_the_movement_not_by_the_order(): void
    {
        $order = $this->order(self::ALICE, ['order_date' => now()->subDay()]);
        $this->movement($order, ['amount' => 100.0, 'created_at' => now()->subDays(30)]);

        $this->assertSame([], $this->figures()->netRetained(
            $this->scopeA(),
            MetricWindow::between(now()->subDays(7), now()),
            self::ALICE
        )->byCurrency(),
            'The retained figure is dated by the order rather than by the movement, so money that '
            . 'moved last month is being reported as this week\'s.');
    }

    // ------------------------------------------------------------------ the coverage boundary

    /**
     * THE FAILURE MODE OF THIS WHOLE CHANGE, PINNED. GAP-156 replaces a figure that OVER-reported;
     * the replacement under-reports to 0.00 for any order the ledger cannot see. If that is not
     * disclosed, the new figure is wrong in a quieter way than the old one.
     */
    public function test_an_order_with_no_ledger_row_raises_the_coverage_caveat_and_is_counted(): void
    {
        $this->order(self::ALICE, ['final_price' => 900.0]);   // no ledger row at all

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame([], $result->byCurrency());

        $this->assertSame(1, $result->notes()['orders_without_ledger_row'] ?? null,
            'The order the ledger cannot see is not counted, so nothing on the screen can say why '
            . 'the figure is lower than the merchant expects.');

        $this->assertTrue($result->hasCaveat(\App\Support\Metrics\MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE),
            'A figure blind to one of this customer\'s orders reported itself as authoritative.');
    }

    public function test_a_fully_covered_customer_raises_no_coverage_caveat(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $result = $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame(0, $result->notes()['orders_without_ledger_row'] ?? null);
        $this->assertFalse($result->hasCaveat(\App\Support\Metrics\MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE),
            'A fully covered customer was given a coverage caveat, which would make the caveat '
            . 'noise and train a merchant to ignore it.');
    }

    /** The caveat is per customer, not per store: one uncovered shopper must not flag the others. */
    public function test_the_coverage_caveat_names_the_customer_it_is_about(): void
    {
        $this->order(self::ALICE);   // uncovered

        $covered = $this->order(self::BOB);
        $this->ledger($covered, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $results = $this->figures()->collectedForCustomers(
            $this->scopeA(), $this->allTime(), [self::ALICE, self::BOB]
        );

        $this->assertTrue($results[self::ALICE]
            ->hasCaveat(\App\Support\Metrics\MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE));

        $this->assertFalse($results[self::BOB]
            ->hasCaveat(\App\Support\Metrics\MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE),
            'A covered customer inherited another customer\'s coverage caveat.');
    }

    /**
     * A MOVEMENT WHOSE ORDER DOES NOT RESOLVE IS COUNTED AND DECLARED. `reference_id` has no
     * foreign key, so this state is reachable, and it is why the sum of every customer's retained
     * figure need not equal section 6 for the same store.
     */
    public function test_a_movement_whose_order_does_not_resolve_is_reported_not_hidden(): void
    {
        $order = $this->order(self::ALICE);
        $this->movement($order, ['amount' => 10.0]);
        $this->movement($order, ['reference_id' => 987654, 'amount' => 50.0]);

        $result = $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE);

        $this->assertSame(['USD' => 10.0], $result->byCurrency());

        $this->assertSame(1, $result->notes()['movements_without_resolvable_order'] ?? null,
            'An order-referenced movement with no resolvable order vanished silently, so the gap '
            . 'between this figure and NetFinancialReceipts has no stated cause.');
    }

    // ------------------------------------------------------------------ the bulk read

    public function test_the_bulk_read_answers_for_every_requested_customer(): void
    {
        $hers = $this->order(self::ALICE);
        $this->ledger($hers, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $his = $this->order(self::BOB);
        $this->ledger($his, ['payment_status' => 'paid', 'paid_amount' => 20.0, 'paid_at' => now()]);

        $never = 99;  // a customer with no orders at all

        $results = $this->figures()->collectedForCustomers(
            $this->scopeA(), $this->allTime(), [self::ALICE, self::BOB, $never]
        );

        $this->assertSame(['USD' => 10.0], $results[self::ALICE]->byCurrency());
        $this->assertSame(['USD' => 20.0], $results[self::BOB]->byCurrency());

        $this->assertArrayHasKey($never, $results,
            'A customer with no orders is missing from the bulk result, so every call site would '
            . 'need `?? 0` and would turn "no record" into "paid nothing".');

        $this->assertSame([], $results[$never]->byCurrency());
        $this->assertSame(0, $results[$never]->rowCount());
    }

    public function test_the_bulk_read_agrees_with_the_single_read(): void
    {
        foreach ([self::ALICE => 33.0, self::BOB => 44.0] as $customerId => $amount) {
            $order = $this->order($customerId);
            $this->ledger($order, [
                'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => now(),
            ]);
        }

        $bulk = $this->figures()->collectedForCustomers(
            $this->scopeA(), $this->allTime(), [self::ALICE, self::BOB]
        );

        foreach ([self::ALICE, self::BOB] as $customerId) {
            $this->assertSame(
                $this->figures()->collected($this->scopeA(), $this->allTime(), $customerId)->byCurrency(),
                $bulk[$customerId]->byCurrency(),
                "The page read and the single read disagree for customer {$customerId}."
            );
        }
    }

    public function test_the_bulk_read_does_not_leak_between_customers(): void
    {
        $hers = $this->order(self::ALICE);
        $this->ledger($hers, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $results = $this->figures()->collectedForCustomers(
            $this->scopeA(), $this->allTime(), [self::ALICE, self::BOB]
        );

        $this->assertSame([], $results[self::BOB]->byCurrency(),
            'One customer\'s money appeared under another\'s id in the grouped read.');
    }

    /**
     * F5: every bulk case drove `collectedForCustomers`, so the grouped MOVEMENT query -- built on
     * a different join closure -- was only ever reached one id at a time. A leak between customers
     * there had no coverage at all.
     */
    public function test_the_bulk_movement_read_does_not_leak_between_customers(): void
    {
        $hers = $this->order(self::ALICE);
        $this->movement($hers, ['amount' => 25.0]);

        $results = $this->figures()->netRetainedForCustomers(
            $this->scopeA(), $this->allTime(), [self::ALICE, self::BOB]
        );

        $this->assertSame(['USD' => 25.0], $results[self::ALICE]->byCurrency());

        $this->assertSame([], $results[self::BOB]->byCurrency(),
            'One customer\'s wallet movement appeared under another\'s id in the grouped read.');
    }

    public function test_the_bulk_movement_read_agrees_with_the_single_read(): void
    {
        $order = $this->order(self::ALICE);
        $this->movement($order, ['amount' => 12.0]);
        $this->movement($order, ['direction' => 'debit', 'amount' => 2.0]);

        $this->assertSame(
            $this->figures()->netRetained($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            $this->figures()->netRetainedForCustomers(
                $this->scopeA(), $this->allTime(), [self::ALICE]
            )[self::ALICE]->byCurrency()
        );
    }

    // ------------------------------------------------------------------ the order-set path

    /**
     * THE ORDER-SET PATH HAD NO TEST OF ITS OWN. It was exercised only through the resolver, and
     * only with id sets the resolver itself produced -- all single-store, all `customer_id = 0`.
     * So its tenant conditions, its reference-type guard, its voided-movement rule and its whole
     * coverage half were unasserted. Review enumerated the mutations that survived; these are
     * those cases.
     */
    public function test_the_order_set_path_cannot_reach_another_tenants_money(): void
    {
        $foreign = $this->order(self::ALICE, ['store_id' => self::STORE_B]);
        $this->ledger($foreign, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid',
            'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $result = $this->figures()->collectedForOrders($this->scopeA(), $this->allTime(), [$foreign]);

        $this->assertSame([], $result->byCurrency(),
            'A caller handed another store\'s order id and got its money. The ids must only ever '
            . 'NARROW the scope the builder already carries.');

        $this->assertSame(0, $result->rowCount());

        /*
         * WHICH PREDICATE DID THE WORK, stated because a mutation run will otherwise look like it
         * found a hole here. Three conditions hold this row out, and the first two ENTAIL the
         * third: the ledger side is scoped structurally by `financials($scope)`, and the join
         * ties `orders.store_id` to `store_order_financials.store_id`. So removing the explicit
         * `whereIn('orders.store_id', ...)` leaves this case green -- correctly, and no fixture
         * can catch it. That predicate is kept as defence against a future weakening of the join
         * rather than as a live guard, and `orderScopedFinancials`' docblock records the same.
         */
    }

    /**
     * AND THE COUNT IS THE PART THE SCOPE DOES NOT PROTECT, which is why it is asserted
     * separately. `orders_attributed` is `count($ids)` -- computed before any scope touches them --
     * so a foreign id yields "1 order, 0.00". The money is safe; the claim about it is not.
     */
    public function test_a_foreign_order_id_is_still_counted_as_attributed(): void
    {
        $foreign = $this->order(self::ALICE, ['store_id' => self::STORE_B]);

        $result = $this->figures()->collectedForOrders($this->scopeA(), $this->allTime(), [$foreign]);

        $this->assertSame(1, $result->notes()['orders_attributed'] ?? null,
            'Recorded deliberately: the metric reports what it was ASKED about, and the caller is '
            . 'responsible for handing it its own orders. `CustomerMoneyFigures` is that caller '
            . 'and its own test pins the store predicate that keeps this from arising.');
    }

    public function test_the_order_set_path_applies_the_same_inclusion_rule(): void
    {
        $paid = $this->order(self::ALICE);
        $this->ledger($paid, ['payment_status' => 'paid', 'paid_amount' => 10.0, 'paid_at' => now()]);

        $partially = $this->order(self::ALICE);
        $this->ledger($partially, [
            'payment_status' => 'partially_paid', 'paid_amount' => 4.0, 'paid_at' => now(),
        ]);

        $neverPaid = $this->order(self::ALICE);
        $this->ledger($neverPaid, ['payment_status' => 'cancelled', 'paid_amount' => 0.0]);

        $this->assertSame(['USD' => 14.0], $this->figures()->collectedForOrders(
            $this->scopeA(), $this->allTime(), [$paid, $partially, $neverPaid]
        )->byCurrency());
    }

    /** The two paths must agree on the same order, or the shared predicate has drifted. */
    public function test_both_paths_agree_on_the_same_order(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, [
            'payment_status' => 'refunded', 'paid_amount' => 33.0, 'paid_at' => now(),
        ]);

        $this->assertSame(
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            $this->figures()->collectedForOrders($this->scopeA(), $this->allTime(), [$order])->byCurrency(),
            'The customer-id path and the order-set path disagree about the same order, so the '
            . 'inclusion rule is no longer shared between them.'
        );
    }

    public function test_the_order_set_path_discloses_an_order_the_ledger_cannot_see(): void
    {
        $uncovered = $this->order(self::ALICE, ['final_price' => 900.0]);

        $result = $this->figures()->collectedForOrders($this->scopeA(), $this->allTime(), [$uncovered]);

        $this->assertSame(1, $result->notes()['orders_without_ledger_row'] ?? null);
        $this->assertTrue($result->hasCaveat(\App\Support\Metrics\MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE),
            'The order-set path reported itself authoritative while blind to the only order it '
            . 'was asked about.');
    }

    public function test_the_order_set_retained_figure_ignores_voided_and_foreign_movements(): void
    {
        $order = $this->order(self::ALICE);
        $this->movement($order, ['amount' => 100.0]);
        $this->movement($order, ['amount' => 70.0, 'status' => 'cancelled']);
        $this->movement($order, [
            'reference_type' => 'withdrawal', 'direction' => 'debit', 'amount' => 5000.0,
        ]);

        $this->assertSame(['USD' => 100.0], $this->figures()->netRetainedForOrders(
            $this->scopeA(), $this->allTime(), [$order]
        )->byCurrency());
    }

    /** An empty order set is a real answer here, unlike an empty customer list. */
    public function test_an_empty_order_set_answers_nothing_rather_than_throwing(): void
    {
        $result = $this->figures()->collectedForOrders($this->scopeA(), $this->allTime(), []);

        $this->assertSame([], $result->byCurrency());
        $this->assertSame(0, $result->notes()['orders_attributed'] ?? null);
        $this->assertArrayHasKey('orders_without_ledger_row', $result->notes(),
            'The empty result carries a different note shape from the populated one, so a surface '
            . 'reading that key gets null for some customers and a number for others.');
    }

    public function test_unusable_order_ids_are_dropped_rather_than_queried(): void
    {
        $order = $this->order(self::ALICE);
        $this->ledger($order, ['payment_status' => 'paid', 'paid_amount' => 7.0, 'paid_at' => now()]);

        $this->assertSame(['USD' => 7.0], $this->figures()->collectedForOrders(
            $this->scopeA(), $this->allTime(), [$order, 0, -5, 'not-an-id']
        )->byCurrency());
    }

    // ------------------------------------------------------------------ unidentifiable customer

    /** @dataProvider unidentifiableIds */
    public function test_an_unidentifiable_customer_id_is_refused(int $customerId): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->figures()->collected($this->scopeA(), $this->allTime(), $customerId);
    }

    public static function unidentifiableIds(): array
    {
        return [
            'customer zero, which is every unattributed order' => [0],
            'a negative id' => [-1],
        ];
    }

    public function test_an_empty_customer_list_is_refused_rather_than_reported_as_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->figures()->collectedForCustomers($this->scopeA(), $this->allTime(), []);
    }

    /**
     * THE TRAP THE REFUSAL EXISTS FOR, driven rather than described: unattributed orders really do
     * pile up under id 0, and without the guard a caller asking for "customer 0" would be handed
     * their combined money as one person's history.
     */
    public function test_unattributed_orders_really_do_collect_under_id_zero(): void
    {
        foreach ([10.0, 20.0, 30.0] as $amount) {
            $order = $this->order(0);
            $this->ledger($order, [
                'payment_status' => 'paid', 'paid_amount' => $amount, 'paid_at' => now(),
            ]);
        }

        $this->assertSame(
            60.0,
            (float) DB::table('store_order_financials')
                ->join('orders', 'orders.id', '=', 'store_order_financials.order_id')
                ->where('orders.customer_id', 0)
                ->sum('store_order_financials.paid_amount'),
            'PREMISE: id 0 does not actually accumulate unattributed orders here, so the refusal '
            . 'above is guarding nothing and this file proves less than it claims.'
        );
    }

    // ------------------------------------------------------------------ currency identity

    /**
     * THE RECORDED CURRENCY IS THE FIGURE'S CURRENCY. Nothing here reads a store setting, a
     * display preference or an operator's selected tenant: the bucket key is the column.
     */
    public function test_the_figure_carries_the_recorded_currency_not_a_display_currency(): void
    {
        $order = $this->order(self::ALICE, ['currency_code' => 'SYP']);
        $this->ledger($order, [
            'currency_code' => 'SYP', 'payment_status' => 'paid',
            'paid_amount' => 500.0, 'paid_at' => now(),
        ]);

        $this->assertSame(['SYP' => 500.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency());
    }

    public function test_a_row_with_no_recorded_currency_is_bucketed_as_unknown_not_guessed(): void
    {
        $order = $this->order(self::ALICE, ['currency_code' => null]);
        $this->ledger($order, [
            'currency_code' => null, 'payment_status' => 'paid',
            'paid_amount' => 15.0, 'paid_at' => now(),
        ]);

        $this->assertSame([MetricResult::UNKNOWN_CURRENCY => 15.0],
            $this->figures()->collected($this->scopeA(), $this->allTime(), self::ALICE)->byCurrency(),
            'A row with no recorded currency was assigned one, which is a guess printed as money.');
    }
}
