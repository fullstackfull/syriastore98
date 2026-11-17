<?php

namespace Tests\Feature\Metrics;

use App\Models\User;
use App\Support\Metrics\CancelledOrders;
use App\Support\Metrics\CollectedCustomerPayments;
use App\Support\Metrics\CompletedRefunds;
use App\Support\Metrics\CouponRedemption;
use App\Support\Metrics\CustomerRetention;
use App\Support\Metrics\GrossMerchandiseValue;
use App\Support\Metrics\MerchantWalletEntitlement;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use App\Support\Metrics\MixedCurrencyException;
use App\Support\Metrics\MultiFigureMetric;
use App\Support\Metrics\NetFinancialReceipts;
use App\Support\Metrics\ShippingAndTax;
use App\Support\Metrics\SingleFigureMetric;
use App\Support\Metrics\StoreRevenueMargin;
use App\Support\Metrics\UnpaidOrders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P5-2 -- the verification test each metric definition names, plus the ones the LAYER needs.
 *
 * `PHASE-05-METRIC-DEFINITIONS.md` names a verification test under every section, and this file
 * implements them by their own words rather than by what the code happens to do. Where a
 * definition's test is a sentence ("a cancelled COD order appears in none of the three") the case
 * below is that sentence.
 *
 * THE GATE F TABLES ARE CREATED BY THE REAL MIGRATIONS, never by a Blueprint here.
 * `guard-gate-f-containment.sh` forbids a test hand-rolling them, and the reason is not
 * bureaucratic: a hand-written blueprint drifted from production in a previous package and the
 * tests passed against a schema that does not exist on the server.
 *
 * EVERY FIXTURE SEEDS TWO STORES. `Order` is not in `config/tenancy.php`'s `scoped_models` and
 * `store_order_financials` has no model at all, so nothing in the framework will add a `store_id`
 * predicate for any principal. A single-store fixture would let a metric that forgot its tenant
 * predicate pass every case in this file.
 */
class MetricLayerTest extends TestCase
{
    use RefreshDatabase;

    private const STORE_A = 1;

    private const STORE_B = 2;

    private array $wallets = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([self::STORE_A => 'metrics-a', self::STORE_B => 'metrics-b'] as $id => $slug) {
            // `stores.created_by` is NOT NULL, and the owner must be a type-`admin` row: a user
            // whose type is `admin` is the root of their own tenant (`User::creatorId()`), which
            // is what makes these two stores genuinely separate tenants rather than two rows.
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

    // ----------------------------------------------------------------- fixture helpers

    private function placeOrder(array $attributes = []): int
    {
        return DB::table('orders')->insertGetId(array_merge([
            'product_order_id' => 'ORD-' . uniqid(),
            'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 100.0,
            'currency_code' => 'USD',
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'store_id' => self::STORE_A,
            'order_date' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function recordFinancial(int $orderId, array $attributes = []): int
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

    private function recordMovement(array $attributes): int
    {
        $storeId = (int) ($attributes['store_id'] ?? self::STORE_A);

        return DB::table('store_wallet_transactions')->insertGetId(array_merge([
            'store_id' => $storeId,
            'wallet_id' => $this->wallets[$storeId],
            'currency_code' => 'USD',
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 100.0,
            'balance_before' => 0.0, 'balance_after' => 100.0,
            'reference_type' => 'order', 'reference_id' => null,
            'description' => 'test', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    private function scopeA(): MetricScope
    {
        return MetricScope::store(self::STORE_A);
    }

    private function allTime(): MetricWindow
    {
        return MetricWindow::allTime();
    }

    // ----------------------------------------------------------------- §1 GMV

    public function test_gmv_counts_a_paid_and_an_unpaid_order_alike(): void
    {
        $this->recordFinancial($this->placeOrder(['final_price' => 100.0]));
        $this->recordFinancial(
            $this->placeOrder(['final_price' => 250.0, 'payment_status' => 'Paid']),
            ['payment_status' => 'paid', 'paid_amount' => 250.0, 'paid_at' => now()]
        );

        $result = (new GrossMerchandiseValue())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(350.0, $result->total(), 0.001);
        $this->assertSame(2, $result->rowCount());
    }

    public function test_gmv_labels_whether_cancellations_were_counted_and_the_label_matches(): void
    {
        $this->placeOrder(['final_price' => 100.0]);
        $this->placeOrder(['final_price' => 60.0, 'delivered_status' => 2]);

        $included = (new GrossMerchandiseValue(true))->for($this->scopeA(), $this->allTime());
        $excluded = (new GrossMerchandiseValue(false))->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(160.0, $included->total(), 0.001);
        $this->assertSame('included', $included->notes()['cancellations']);

        $this->assertEqualsWithDelta(100.0, $excluded->total(), 0.001);
        $this->assertSame('excluded', $excluded->notes()['cancellations']);
    }

    public function test_excluding_cancellations_keeps_every_non_cancelled_fulfilment_state(): void
    {
        /*
         * THIS IS NOT THE TEST I FIRST WROTE, AND THE DIFFERENCE IS WORTH RECORDING.
         *
         * The intended case was an order with a NULL `delivered_status`, because in SQL
         * `NULL != 2` is NULL -- not true -- so a plain inequality would silently drop such rows
         * from GMV. It cannot be driven: `create_orders_table` declares the column
         * `integer()->default(0)`, NOT NULL, and the insert is rejected. `GrossMerchandiseValue`
         * keeps the null limb regardless, because the migrations are not the schema, but no test
         * here proves it fires and this one does not pretend to.
         *
         * What IS driven is the rest of the vocabulary: 0 pending, 1 delivered, 3 returned, 5 and
         * 6 in use elsewhere in `Order`. Only 2 may be removed.
         */
        foreach ([0, 1, 3, 5, 6] as $status) {
            $this->placeOrder(['final_price' => 10.0, 'delivered_status' => $status]);
        }

        $this->placeOrder(['final_price' => 99.0, 'delivered_status' => 2]);

        $excluded = (new GrossMerchandiseValue(false))->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(50.0, $excluded->total(), 0.001);
        $this->assertSame(5, $excluded->rowCount());
    }

    // ----------------------------------------------------------------- §2 collected payments

    public function test_an_order_paid_then_cancelled_still_counts_as_money_the_shopper_paid(): void
    {
        // The F124 shape: paid_amount and paid_at survive the reversal on purpose.
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'cancelled', 'paid_amount' => 100.0, 'paid_at' => now(),
            'remaining_amount' => 0.0,
        ]);

        $collected = (new CollectedCustomerPayments())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            100.0,
            $collected->total(),
            0.001,
            'filtering on payment_status = paid alone under-counts; that is the trap §2 names'
        );
    }

    public function test_uncollected_cod_is_not_money_the_shopper_paid(): void
    {
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'pending', 'paid_amount' => 0.0,
        ]);

        $collected = (new CollectedCustomerPayments())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $collected->total(), 0.001);
        $this->assertSame(0, $collected->rowCount());
    }

    public function test_an_order_cancelled_before_anyone_paid_is_not_money_the_shopper_paid(): void
    {
        /*
         * The other half of the F124 limb. That limb admits `cancelled` and `refunded` rows so a
         * paid-then-reversed order still counts -- which, without the `paid_amount > 0` guard,
         * would also admit every cancelled order that was NEVER paid and report its full value as
         * money a shopper handed over.
         */
        $this->recordFinancial($this->placeOrder(['final_price' => 300.0]), [
            'payment_status' => 'cancelled', 'paid_amount' => 0.0,
            'customer_total' => 300.0, 'remaining_amount' => 0.0,
        ]);

        $collected = (new CollectedCustomerPayments())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $collected->total(), 0.001);
        $this->assertSame(0, $collected->rowCount());
    }

    public function test_collected_payments_fall_in_the_month_the_money_arrived(): void
    {
        // Placed in January, captured in March. §2 answers "what did shoppers pay us", so the
        // capture date decides, not the order date -- the two routinely differ by a month.
        $this->recordFinancial(
            $this->placeOrder(['order_date' => now()->setDate(2026, 1, 15)]),
            [
                'payment_status' => 'paid', 'paid_amount' => 100.0,
                'paid_at' => now()->setDate(2026, 3, 10),
            ]
        );

        $january = (new CollectedCustomerPayments())->for(
            $this->scopeA(),
            MetricWindow::between(now()->setDate(2026, 1, 1), now()->setDate(2026, 1, 31))
        );
        $march = (new CollectedCustomerPayments())->for(
            $this->scopeA(),
            MetricWindow::between(now()->setDate(2026, 3, 1), now()->setDate(2026, 3, 31))
        );

        $this->assertEqualsWithDelta(0.0, $january->total(), 0.001,
            'the capture was dated by the order date, so January was credited with March\'s money');
        $this->assertEqualsWithDelta(100.0, $march->total(), 0.001);
    }

    // ----------------------------------------------------------------- §3 unpaid, three ways

    public function test_unpaid_orders_cannot_be_collapsed_into_one_figure(): void
    {
        $metric = new UnpaidOrders();

        $this->assertInstanceOf(MultiFigureMetric::class, $metric);
        $this->assertNotInstanceOf(
            SingleFigureMetric::class,
            $metric,
            '§3 is "three sub-metrics, never one"; a for() would make collapsing them the easy path'
        );
        $this->assertFalse(method_exists($metric, 'for'));
    }

    public function test_a_cancelled_cod_order_appears_in_none_of_the_three(): void
    {
        $cancelled = $this->placeOrder(['payment_type' => 'cod']);
        $this->recordFinancial($cancelled, [
            'payment_status' => 'cancelled', 'remaining_amount' => 100.0,
        ]);

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        foreach ($results as $subMetric => $result) {
            $this->assertEqualsWithDelta(
                0.0,
                $result->total(),
                0.001,
                'a cancelled order is not awaiting anything, yet it appears in ' . $subMetric
            );
        }
    }

    public function test_the_three_unpaid_sub_metrics_separate_cod_from_bank_transfer_from_unpaid(): void
    {
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => 'COD']),   // upper case on purpose
            ['payment_status' => 'pending', 'remaining_amount' => 30.0]
        );
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => 'bank_transfer']),
            ['payment_status' => 'pending', 'remaining_amount' => 50.0]
        );
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => 'stripe']),
            ['payment_status' => 'unpaid', 'remaining_amount' => 70.0]
        );

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            30.0,
            $results[UnpaidOrders::AWAITING_COD_COLLECTION]->total(),
            0.001,
            'the stored payment_type is "COD"; matching it lower-cased is what the application does'
        );
        $this->assertEqualsWithDelta(
            50.0,
            $results[UnpaidOrders::AWAITING_MANUAL_SETTLEMENT]->total(),
            0.001
        );
        $this->assertEqualsWithDelta(
            70.0,
            $results[UnpaidOrders::GENUINELY_UNPAID]->total(),
            0.001
        );
    }

    public function test_a_partially_paid_row_is_reported_as_outside_all_three_rather_than_hidden(): void
    {
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'partially_paid', 'paid_amount' => 40.0, 'remaining_amount' => 60.0,
        ]);

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        foreach ($results as $result) {
            $this->assertEqualsWithDelta(0.0, $result->total(), 0.001);
            $this->assertSame(1, $result->notes()['partially_paid_outside_all_three']);
        }
    }

    public function test_a_pending_gateway_order_is_reported_as_outside_all_three(): void
    {
        /*
         * FOUND BY REVIEW, and the larger of the two gaps in §3's partition. A gateway order whose
         * capture could not be verified sits at `pending` with the full total outstanding, and its
         * `payment_type` is whatever the client sent. For anything that is not a COD spelling or
         * exactly `bank_transfer` it matches no bucket. Silence there loses real money.
         */
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => 'stripe']),
            ['payment_status' => 'pending', 'remaining_amount' => 120.0]
        );

        // A NULL payment type is the same kind of row, and `NULL NOT IN (...)` is NULL rather than
        // true -- so without the explicit null limb this one would fall out of the count.
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => null]),
            ['payment_status' => 'pending', 'remaining_amount' => 45.0]
        );

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        foreach ($results as $subMetric => $result) {
            $this->assertEqualsWithDelta(
                0.0,
                $result->total(),
                0.001,
                'an unclassifiable pending row landed in ' . $subMetric
            );
            $this->assertSame(
                2,
                $result->notes()['pending_with_unclassified_payment_type'],
                'the rows in no bucket are not disclosed, so the money is simply lost'
            );
        }
    }

    public function test_a_classifiable_pending_order_is_not_counted_as_unclassified(): void
    {
        // BOTH classifiable spellings, because they are excluded by DIFFERENT predicates: the COD
        // set is a `whereNotIn` over three values and `bank_transfer` is a single `<>`. A fixture
        // with only one of them leaves the other's exclusion unpinned.
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => '  COD  ']),
            ['payment_status' => 'pending', 'remaining_amount' => 30.0]
        );
        $this->recordFinancial(
            $this->placeOrder(['payment_type' => 'bank_transfer']),
            ['payment_status' => 'pending', 'remaining_amount' => 50.0]
        );

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            30.0,
            $results[UnpaidOrders::AWAITING_COD_COLLECTION]->total(),
            0.001
        );
        $this->assertEqualsWithDelta(
            50.0,
            $results[UnpaidOrders::AWAITING_MANUAL_SETTLEMENT]->total(),
            0.001
        );
        $this->assertSame(
            0,
            $results[UnpaidOrders::AWAITING_COD_COLLECTION]->notes()['pending_with_unclassified_payment_type'],
            'a row that IS in a bucket was also reported as outside all three'
        );
    }

    public function test_a_financial_row_pointing_at_another_tenants_order_is_not_classified_by_it(): void
    {
        /*
         * NOTHING IN THE SCHEMA FORBIDS THIS ROW. `fk_order_financial_order` ties `order_id` to
         * `orders(id)` and `fk_order_financial_store` ties `store_id` to `stores(id)`, but no
         * constraint says the two must agree. A financial row carrying store A's `store_id` while
         * pointing at store B's order therefore passes the tenant predicate, and the join would
         * then read store B's `payment_type` to decide which of A's buckets it lands in.
         *
         * The join asserts the equality rather than assuming it, so such a row is excluded.
         */
        $foreignOrder = $this->placeOrder([
            'store_id' => self::STORE_B, 'payment_type' => 'bank_transfer', 'final_price' => 500.0,
        ]);
        $this->recordFinancial($foreignOrder, [
            'store_id' => self::STORE_A,
            'payment_status' => 'pending', 'remaining_amount' => 500.0,
        ]);

        $results = (new UnpaidOrders())->all($this->scopeA(), $this->allTime());

        foreach ($results as $subMetric => $result) {
            $this->assertEqualsWithDelta(
                0.0,
                $result->total(),
                0.001,
                'a financial row was classified by another tenant\'s order in ' . $subMetric
            );
        }
    }

    // ----------------------------------------------------------------- §4 cancellations

    public function test_a_cancellation_with_no_cancel_date_is_still_counted_and_the_gap_is_declared(): void
    {
        // The door that stamps it.
        $stamped = $this->placeOrder(['cancel_date' => now()->toDateString()]);
        $this->recordFinancial($stamped, [
            'payment_status' => 'cancelled', 'customer_total' => 100.0,
        ]);

        // DashboardController::orderCancel -- measured to write no cancel_date at all.
        $unstamped = $this->placeOrder(['cancel_date' => null]);
        $this->recordFinancial($unstamped, [
            'payment_status' => 'cancelled', 'customer_total' => 40.0,
        ]);

        $result = (new CancelledOrders())->for(
            $this->scopeA(),
            MetricWindow::between(now()->subDay(), now()->addDay())
        );

        $this->assertEqualsWithDelta(
            140.0,
            $result->total(),
            0.001,
            'the door that writes no cancel_date fell out of a bounded window'
        );
        $this->assertSame(1, $result->notes()['dated_by_fallback']);
        $this->assertTrue($result->hasCaveat(MetricCaveat::CANCEL_DATE_INCOMPLETE));
    }

    public function test_an_order_that_was_not_cancelled_is_not_counted_as_a_cancellation(): void
    {
        // A paid order carrying a cancel_date -- which happens, because the column is written by
        // the cancellation doors and never cleared if the row later moves on.
        $this->recordFinancial(
            $this->placeOrder(['cancel_date' => now()->toDateString(), 'final_price' => 400.0]),
            [
                'payment_status' => 'paid', 'paid_amount' => 400.0, 'paid_at' => now(),
                'customer_total' => 400.0,
            ]
        );

        $result = (new CancelledOrders())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $result->total(), 0.001);
        $this->assertSame(0, $result->rowCount());
    }

    public function test_the_cancel_date_caveat_is_absent_when_every_row_carries_one(): void
    {
        $this->recordFinancial(
            $this->placeOrder(['cancel_date' => now()->toDateString()]),
            ['payment_status' => 'cancelled']
        );

        $result = (new CancelledOrders())->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $result->notes()['dated_by_fallback']);
        $this->assertFalse(
            $result->hasCaveat(MetricCaveat::CANCEL_DATE_INCOMPLETE),
            'a caveat attached unconditionally is one a reader learns to ignore'
        );
    }

    // ----------------------------------------------------------------- §5 refunds

    public function test_an_agreed_but_unsettled_refund_contributes_nothing(): void
    {
        $orderId = $this->placeOrder();
        $this->recordFinancial($orderId, ['payment_status' => 'paid', 'paid_amount' => 100.0]);

        // `Accept` means a merchant agreed. No money has moved and -- because
        // hasGatewayRefundDriver() is a hardcoded false -- none ever will through this row.
        DB::table('order_refunds')->insert([
            'order_id' => $orderId, 'refund_status' => 'Accept',
            'refund_reason' => 'changed mind', 'refund_amount' => 100.0,
            'store_id' => self::STORE_A,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /*
         * THE DECOY, AND THIS CASE IS WORTHLESS WITHOUT IT.
         *
         * As first written this fixture held no wallet movements at all, so the metric returned
         * zero whatever it queried -- a mutation that deleted the `type` filter entirely left the
         * test GREEN. It proved only that an empty table sums to nothing.
         *
         * The capture credit is what makes the case adversarial: it is a real, completed, order-
         * referenced movement of exactly the refund's amount. A metric that reads
         * `order_refunds.refund_status` reports 100; a metric that forgets to filter on
         * `type = order_refunded` also reports 100 -- now from the credit. Only the correct one
         * reports nothing.
         */
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 100.0,
            'reference_id' => $orderId, 'balance_before' => 0.0, 'balance_after' => 100.0,
        ]);

        $refunds = (new CompletedRefunds())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            0.0,
            $refunds->total(),
            0.001,
            'an agreed refund was counted as money returned; §5 calls this the sharpest exclusion'
        );
        $this->assertSame(0, $refunds->rowCount());
    }

    public function test_a_settled_refund_contributes_once(): void
    {
        $orderId = $this->placeOrder();
        $this->recordFinancial($orderId, ['payment_status' => 'refunded', 'paid_amount' => 100.0]);

        // The capture that preceded it -- the same decoy as the case above, so that the EXACT
        // figure, not merely a non-zero one, is what this case pins. Without it a metric summing
        // every order movement would report 100 here too and look correct.
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 100.0,
            'reference_id' => $orderId, 'balance_before' => 0.0, 'balance_after' => 100.0,
        ]);
        $this->recordMovement([
            'type' => 'order_refunded', 'direction' => 'debit', 'amount' => 100.0,
            'reference_id' => $orderId, 'balance_before' => 100.0, 'balance_after' => 0.0,
        ]);

        $refunds = (new CompletedRefunds())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(100.0, $refunds->total(), 0.001);
        $this->assertSame(
            1,
            $refunds->rowCount(),
            'the capture credit was counted as a refund'
        );
        $this->assertTrue($refunds->hasCaveat(MetricCaveat::PARTIAL_REFUNDS_EXCLUDED));
    }

    public function test_a_voided_refund_movement_returns_nothing_and_is_not_counted(): void
    {
        // `store_wallet_transactions.status` is enum('pending','completed','cancelled'). A
        // cancelled row was written and then voided; no money left the merchant's entitlement.
        $orderId = $this->placeOrder();
        $this->recordFinancial($orderId, ['payment_status' => 'paid', 'paid_amount' => 100.0]);
        $this->recordMovement([
            'type' => 'order_refunded', 'direction' => 'debit', 'amount' => 100.0,
            'reference_id' => $orderId, 'balance_before' => 100.0, 'balance_after' => 100.0,
            'status' => 'cancelled',
        ]);

        $refunds = (new CompletedRefunds())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $refunds->total(), 0.001);
        $this->assertSame(0, $refunds->rowCount());
    }

    public function test_a_refund_movement_that_is_not_about_an_order_is_not_an_order_refund(): void
    {
        /*
         * `reference_type` is what says WHAT the movement was about. A row typed `order_refunded`
         * but referencing a withdrawal is not a customer refund, and counting it would subtract
         * the same money twice from any figure built on §5 -- once here and once wherever
         * withdrawals are accounted for.
         */
        $this->recordMovement([
            'type' => 'order_refunded', 'direction' => 'debit', 'amount' => 100.0,
            'reference_type' => 'withdrawal', 'reference_id' => 1,
            'balance_before' => 100.0, 'balance_after' => 0.0,
        ]);

        $refunds = (new CompletedRefunds())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $refunds->total(), 0.001);
        $this->assertSame(0, $refunds->rowCount());
    }

    // ----------------------------------------------------------------- §6 net receipts

    public function test_the_two_computations_of_net_receipts_agree_on_the_named_dataset(): void
    {
        $this->seedReconcilableDataset();

        $reconciliation = (new NetFinancialReceipts())->reconcile($this->scopeA(), $this->allTime());

        $this->assertTrue(
            $reconciliation['agrees'],
            'the settlement record and the movement record disagree: '
            . json_encode($reconciliation)
        );
        $this->assertSame([], $reconciliation['divergence']);
        $this->assertEqualsWithDelta(200.0, $reconciliation['movement']['USD'], 0.001);
        $this->assertEqualsWithDelta(200.0, $reconciliation['status']['USD'], 0.001);
    }

    public function test_a_withdrawal_is_not_a_customer_receipt(): void
    {
        /*
         * Net receipts is about money from SHOPPERS. A merchant moving their own money out is a
         * different event entirely, and without `reference_type = 'order'` it would subtract from
         * the figure -- so a merchant who withdrew their earnings would read that their customers
         * had paid them less.
         */
        $this->seedReconcilableDataset();

        $this->recordMovement([
            'type' => 'withdrawal_request', 'direction' => 'debit', 'amount' => 120.0,
            'reference_type' => 'withdrawal', 'reference_id' => 1,
            'balance_before' => 200.0, 'balance_after' => 80.0,
        ]);

        $reconciliation = (new NetFinancialReceipts())->reconcile($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            200.0,
            $reconciliation['movement']['USD'],
            0.001,
            'a withdrawal was counted against what shoppers paid'
        );
        $this->assertTrue($reconciliation['agrees']);
    }

    public function test_a_divergence_is_reported_rather_than_averaged(): void
    {
        $this->seedReconcilableDataset();

        // A credit the order records know nothing about -- the shape a double-written ledger row
        // or a hand-edited balance would produce.
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 75.0,
            'reference_id' => 999999, 'balance_before' => 0.0, 'balance_after' => 75.0,
        ]);

        $metric = new NetFinancialReceipts();
        $reconciliation = $metric->reconcile($this->scopeA(), $this->allTime());

        $this->assertFalse($reconciliation['agrees']);
        $this->assertEqualsWithDelta(75.0, $reconciliation['divergence']['USD'], 0.001);

        $result = $metric->for($this->scopeA(), $this->allTime());

        $this->assertTrue($result->hasCaveat(MetricCaveat::RECONCILIATION_DIVERGENCE));
        $this->assertEqualsWithDelta(
            275.0,
            $result->total(),
            0.001,
            'the reported figure must be the movement record, not an average of the two'
        );
    }

    /**
     * The dataset section 6 names: a paid order, a cancelled paid order, a refunded order and an
     * unpaid one. Every leg is written the way the application writes it, so the two computations
     * have a real chance to disagree.
     */
    private function seedReconcilableDataset(): void
    {
        // Paid: +200 stays.
        $paid = $this->placeOrder(['final_price' => 200.0]);
        $this->recordFinancial($paid, [
            'payment_status' => 'paid', 'paid_amount' => 200.0, 'paid_at' => now(),
            'remaining_amount' => 0.0, 'customer_total' => 200.0,
        ]);
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'reference_id' => $paid, 'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);

        // Paid then cancelled: +150 then -150.
        $cancelled = $this->placeOrder(['final_price' => 150.0]);
        $this->recordFinancial($cancelled, [
            'payment_status' => 'cancelled', 'paid_amount' => 150.0, 'paid_at' => now(),
            'remaining_amount' => 0.0, 'customer_total' => 150.0,
        ]);
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 150.0,
            'reference_id' => $cancelled, 'balance_before' => 200.0, 'balance_after' => 350.0,
        ]);
        $this->recordMovement([
            'type' => 'order_cancelled', 'direction' => 'debit', 'amount' => 150.0,
            'reference_id' => $cancelled, 'balance_before' => 350.0, 'balance_after' => 200.0,
        ]);

        // Paid then refunded: +90 then -90.
        $refunded = $this->placeOrder(['final_price' => 90.0]);
        $this->recordFinancial($refunded, [
            'payment_status' => 'refunded', 'paid_amount' => 90.0, 'paid_at' => now(),
            'remaining_amount' => 0.0, 'customer_total' => 90.0,
        ]);
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 90.0,
            'reference_id' => $refunded, 'balance_before' => 200.0, 'balance_after' => 290.0,
        ]);
        $this->recordMovement([
            'type' => 'order_refunded', 'direction' => 'debit', 'amount' => 90.0,
            'reference_id' => $refunded, 'balance_before' => 290.0, 'balance_after' => 200.0,
        ]);

        // Unpaid: no money, no movement.
        $this->recordFinancial($this->placeOrder(['final_price' => 60.0]), [
            'payment_status' => 'unpaid', 'customer_total' => 60.0, 'remaining_amount' => 60.0,
        ]);
    }

    // ----------------------------------------------------------------- §7 wallet entitlement

    public function test_available_balance_reconstructs_from_the_ledger_rows_that_moved_it(): void
    {
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 200.0]);

        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);

        $drift = (new MerchantWalletEntitlement())->drift($this->scopeA());

        $this->assertSame([], $drift, 'a consistent wallet was reported as drifted: ' . json_encode($drift));
    }

    public function test_a_paid_withdrawal_does_not_make_a_consistent_wallet_look_drifted(): void
    {
        /*
         * THE DOUBLE-COUNT P4-F128 MEASURED. The available balance is reserved when a withdrawal
         * is REQUESTED, so the later `withdrawal_paid` row carries balance_after == balance_before
         * by design. GateFReconciliation R1 and Q-F032-5 both sum it and both therefore report
         * phantom drift on every paid withdrawal.
         */
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 150.0, 'total_withdrawn' => 50.0]);

        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);
        $this->recordMovement([
            'type' => 'withdrawal_request', 'direction' => 'debit', 'amount' => 50.0,
            'reference_type' => 'withdrawal',
            'balance_before' => 200.0, 'balance_after' => 150.0,
        ]);
        $this->recordMovement([
            'type' => 'withdrawal_paid', 'direction' => 'debit', 'amount' => 50.0,
            'reference_type' => 'withdrawal',
            'balance_before' => 150.0, 'balance_after' => 150.0,   // moved nothing, by design
        ]);

        $drift = (new MerchantWalletEntitlement())->drift($this->scopeA());

        $this->assertSame(
            [],
            $drift,
            'the withdrawal_paid row was summed, debiting the wallet a second time: '
            . json_encode($drift)
        );
    }

    public function test_the_drift_check_does_not_reach_another_tenants_wallet(): void
    {
        /*
         * A CASE OF ITS OWN, because the cross-tenant sweep below cannot prove this. That test
         * asserts every metric's FIGURE first, and PHPUnit stops at the first failure -- so a leak
         * in `drift()` would be masked by the `wallet` figure failing one assertion earlier, and
         * the drift assertion would never run. Here nothing else can fail first.
         */
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 200.0]);
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);

        // Store B's wallet disagrees with its own ledger by 8,765. If the drift check read every
        // tenant, this is what it would report.
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_B])
            ->update(['available_balance' => 1234.0]);
        $this->recordMovement([
            'store_id' => self::STORE_B, 'type' => 'order_paid', 'direction' => 'credit',
            'amount' => 9999.0, 'balance_before' => 0.0, 'balance_after' => 9999.0,
        ]);

        $this->assertSame(
            [],
            (new MerchantWalletEntitlement())->drift($this->scopeA()),
            'the drift check reported another tenant\'s wallet'
        );

        // The premise: store B really is drifted, so the assertion above had something to catch.
        $foreign = (new MerchantWalletEntitlement())->drift(MetricScope::store(self::STORE_B));
        $this->assertCount(1, $foreign);
        $this->assertEqualsWithDelta(-8765.0, $foreign[0]['difference'], 0.001);
    }

    public function test_a_ledger_row_carrying_another_tenants_store_id_is_not_summed_into_this_wallet(): void
    {
        /*
         * FOUND BY REVIEW. `store_wallet_transactions` carries its OWN `store_id`, and
         * `fk_wallet_transactions_store` and `fk_wallet_transactions_wallet` are two unrelated
         * constraints -- nothing in the schema forces them to agree. Joining on `wallet_id` alone
         * would sum such a row into this wallet's ledger total and could flip the wallet into or
         * out of the drift report. No correct writer produces the row; the predicate is there so
         * the claim is "by construction" rather than "by data integrity".
         */
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 200.0]);
        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);

        // The corrupt row: store B's store_id, store A's wallet_id.
        $this->recordMovement([
            'store_id' => self::STORE_B, 'type' => 'order_paid', 'direction' => 'credit',
            'amount' => 500.0, 'balance_before' => 0.0, 'balance_after' => 500.0,
        ]);
        DB::table('store_wallet_transactions')
            ->where('amount', 500.0)
            ->update(['wallet_id' => $this->wallets[self::STORE_A]]);

        $this->assertSame(
            [],
            (new MerchantWalletEntitlement())->drift($this->scopeA()),
            'a foreign-tenant ledger row was summed into this wallet, reporting phantom drift'
        );
    }

    public function test_a_genuinely_drifted_wallet_is_reported(): void
    {
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 500.0]);

        $this->recordMovement([
            'type' => 'order_paid', 'direction' => 'credit', 'amount' => 200.0,
            'balance_before' => 0.0, 'balance_after' => 200.0,
        ]);

        $drift = (new MerchantWalletEntitlement())->drift($this->scopeA());

        $this->assertCount(1, $drift);
        $this->assertEqualsWithDelta(300.0, $drift[0]['difference'], 0.001);
    }

    public function test_the_wallet_balance_is_never_labelled_revenue_and_ignores_the_window(): void
    {
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_A])
            ->update(['available_balance' => 120.0]);

        $result = (new MerchantWalletEntitlement())->for(
            $this->scopeA(),
            MetricWindow::between(now()->addYear(), now()->addYears(2))
        );

        $this->assertEqualsWithDelta(120.0, $result->total(), 0.001);
        $this->assertNull($result->datedBy(), 'a running balance has no date column');
        $this->assertFalse($result->notes()['is_revenue']);
        $this->assertTrue($result->notes()['window_ignored']);
    }

    // ----------------------------------------------------------------- §8 margin

    public function test_an_order_with_no_cost_price_is_flagged_rather_than_read_as_pure_profit(): void
    {
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now(),
            'product_amount' => 100.0, 'discount_amount' => 10.0,
            'product_cost' => 0.0, 'gross_profit' => 90.0,
        ]);

        $margin = (new StoreRevenueMargin())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            90.0,
            $margin->total(),
            0.001,
            'margin should equal product_amount - discount_amount when cost is unknown'
        );
        $this->assertTrue($margin->hasCaveat(MetricCaveat::COST_UNKNOWN));
        $this->assertSame(1, $margin->notes()['rows_without_cost']);
        $this->assertTrue($margin->hasCaveat(MetricCaveat::EXCLUDES_SHIPPING_AND_TAX));
    }

    public function test_margin_with_a_known_cost_is_not_flagged_cost_unknown(): void
    {
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now(),
            'product_amount' => 100.0, 'product_cost' => 40.0, 'gross_profit' => 60.0,
        ]);

        $margin = (new StoreRevenueMargin())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(60.0, $margin->total(), 0.001);
        $this->assertFalse($margin->hasCaveat(MetricCaveat::COST_UNKNOWN));
        $this->assertSame(0, $margin->notes()['rows_without_cost']);
    }

    public function test_margin_states_which_orders_it_counted(): void
    {
        $this->recordFinancial($this->placeOrder(), [
            'payment_status' => 'unpaid', 'gross_profit' => 60.0,
        ]);

        $settled = (new StoreRevenueMargin(true))->for($this->scopeA(), $this->allTime());
        $everything = (new StoreRevenueMargin(false))->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(0.0, $settled->total(), 0.001);
        $this->assertSame('settled_only', $settled->notes()['basis']);

        $this->assertEqualsWithDelta(60.0, $everything->total(), 0.001);
        $this->assertSame('all_orders', $everything->notes()['basis']);
    }

    // ----------------------------------------------------------------- §9 coupons (blocked)

    public function test_coupon_redemption_can_never_be_presented_as_authoritative(): void
    {
        $orderId = $this->placeOrder();
        DB::table('order_coupon_details')->insert([
            'order_id' => $orderId, 'coupon_id' => 1, 'coupon_name' => 'TEN',
            'coupon_code' => 'TEN', 'coupon_discount_type' => 'flat',
            'coupon_discount_number' => 10.0, 'coupon_discount_amount' => 10.0,
            'coupon_final_amount' => 90.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = (new CouponRedemption())->for($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(10.0, $result->total(), 0.001);
        $this->assertFalse(
            $result->isAuthoritative(),
            'owner instruction: coupon-redemption records are not authoritative until '
            . 'P4-F134 and P4-F135 are resolved'
        );
        $this->assertTrue($result->hasCaveat(MetricCaveat::COUPON_REDEMPTION_UNRELIABLE));
        $this->assertSame(['P4-F134', 'P4-F135'], $result->notes()['blocked_by']);
    }

    // ----------------------------------------------------------------- §10 shipping and tax

    public function test_shipping_survives_a_refund_request_that_zeroed_the_order_column(): void
    {
        $orderId = $this->placeOrder(['delivery_price' => 25.0]);
        $this->recordFinancial($orderId, ['shipping_amount' => 25.0, 'tax_amount' => 8.0]);

        // `RefundAmonut` zeroes orders.delivery_price on a client-supplied boolean WITHOUT
        // recomputing final_price. The financial row is the snapshot and does not move.
        DB::table('orders')->where('id', $orderId)->update(['delivery_price' => 0.0]);

        $results = (new ShippingAndTax())->all($this->scopeA(), $this->allTime());

        $this->assertEqualsWithDelta(
            25.0,
            $results[ShippingAndTax::SHIPPING]->total(),
            0.001,
            'shipping was read from orders.delivery_price, which a refund request had zeroed'
        );
        $this->assertEqualsWithDelta(8.0, $results[ShippingAndTax::TAX]->total(), 0.001);
    }

    // ----------------------------------------------------------------- P5-6 retention

    public function test_a_customer_who_ordered_twice_is_a_repeat_customer_and_one_who_ordered_once_is_not(): void
    {
        foreach ([[7, 2], [8, 1], [9, 3]] as [$customerId, $orderCount]) {
            for ($i = 0; $i < $orderCount; $i++) {
                $this->recordFinancial(
                    $this->placeOrder(['customer_id' => $customerId]),
                    ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
                );
            }
        }

        $figures = (new CustomerRetention())->for($this->scopeA(), $this->allTime());

        $this->assertSame(3, $figures->identifiedCustomers());
        $this->assertSame(2, $figures->repeatCustomers(), 'customers 7 and 9 ordered more than once');
        $this->assertSame(6, $figures->ordersCounted());
        $this->assertEqualsWithDelta(0.6667, $figures->repeatRate(), 0.0001);
        $this->assertSame('settled_only', $figures->notes()['basis']);
    }

    public function test_orders_with_nobody_attached_do_not_collapse_into_one_enormously_loyal_customer(): void
    {
        /*
         * THE MOST FLATTERING WRONG NUMBER THIS SCHEMA CAN PRODUCE. `orders.customer_id` is
         * INTEGER DEFAULT 0, so every unattributed order shares id 0. Counting that as a customer
         * gives one "customer" holding all of them -- a repeat rate near 100%.
         */
        for ($i = 0; $i < 6; $i++) {
            $this->recordFinancial(
                $this->placeOrder(['customer_id' => 0, 'is_guest' => 1]),
                ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
            );
        }

        // One real customer, one order. The honest answer is 1 customer, 0 repeat, rate 0.
        $this->recordFinancial(
            $this->placeOrder(['customer_id' => 11]),
            ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
        );

        $figures = (new CustomerRetention())->for($this->scopeA(), $this->allTime());

        $this->assertSame(1, $figures->identifiedCustomers(), 'the six id-0 orders became a customer');
        $this->assertSame(0, $figures->repeatCustomers());
        $this->assertEqualsWithDelta(0.0, $figures->repeatRate(), 0.0001);
        $this->assertSame(6, $figures->notes()['orders_without_identifiable_customer']);
        $this->assertTrue($figures->hasCaveat(MetricCaveat::UNIDENTIFIED_CUSTOMERS_EXCLUDED));
    }

    public function test_a_guest_order_carrying_a_real_customer_id_IS_counted(): void
    {
        /*
         * THE CASE THAT CORRECTED THE DESIGN, and it was missing until a mutation that should
         * have reddened did not.
         *
         * This metric first excluded `is_guest <> 0` as well, which looked obviously right. It is
         * wrong: `PaymentService` sets `$orderCustomerId = ! empty($request->customer_id) ?
         * $request->customer_id : 0` on the GUEST branch, and its own comment records that the id
         * is "the Customer the handler found or created from the billing info -- which is
         * legitimate". A shopper matched to a Customer row by their billing details IS
         * recognisable on a later visit, so excluding them discards exactly the repeat-purchase
         * signal this metric exists to measure -- most of it, for a store whose business is mostly
         * guest checkout.
         *
         * The old predicate was invisible in the earlier fixture because its guests also carried
         * id 0, so both predicates excluded the same rows.
         */
        foreach ([1, 2] as $unused) {
            $this->recordFinancial(
                $this->placeOrder(['customer_id' => 77, 'is_guest' => 1]),
                ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
            );
        }

        $figures = (new CustomerRetention())->for($this->scopeA(), $this->allTime());

        $this->assertSame(
            1,
            $figures->identifiedCustomers(),
            'a guest order with a real customer id was discarded, losing real retention signal'
        );
        $this->assertSame(1, $figures->repeatCustomers(), 'they ordered twice and were recognisable both times');
        $this->assertEqualsWithDelta(1.0, $figures->repeatRate(), 0.0001);
        $this->assertSame(0, $figures->notes()['orders_without_identifiable_customer']);
        $this->assertFalse($figures->hasCaveat(MetricCaveat::UNIDENTIFIED_CUSTOMERS_EXCLUDED));
    }

    public function test_an_order_with_no_customer_is_excluded_whatever_the_guest_flag_says(): void
    {
        // `customer_id` defaults to 0, so "no customer" and "customer zero" are the same value
        // and only the comparison separates them -- the guest flag is not consulted at all.
        $this->recordFinancial(
            $this->placeOrder(['customer_id' => 0, 'is_guest' => 0]),
            ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
        );

        $figures = (new CustomerRetention())->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $figures->identifiedCustomers());
        $this->assertSame(1, $figures->notes()['orders_without_identifiable_customer']);
    }

    public function test_two_unpaid_orders_are_not_retention_but_are_counted_when_the_basis_says_so(): void
    {
        /*
         * The plan's gate note: "a repeat-purchase rate computed over unsettled orders is not a
         * retention figure". A customer who ordered twice and paid for neither tried twice; they
         * did not come back. Both bases are offered and each says which it is.
         */
        foreach ([1, 2] as $unused) {
            $this->recordFinancial(
                $this->placeOrder(['customer_id' => 21]),
                ['payment_status' => 'unpaid', 'remaining_amount' => 100.0]
            );
        }

        $settled = (new CustomerRetention(true))->for($this->scopeA(), $this->allTime());
        $everything = (new CustomerRetention(false))->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $settled->identifiedCustomers(), 'an unpaid order settled nothing');
        $this->assertNull($settled->repeatRate(), 'no customers means no rate, not a rate of zero');

        $this->assertSame(1, $everything->identifiedCustomers());
        $this->assertSame(1, $everything->repeatCustomers());
        $this->assertEqualsWithDelta(1.0, $everything->repeatRate(), 0.0001);
        $this->assertSame('all_orders', $everything->notes()['basis']);
    }

    public function test_orders_the_ledger_cannot_see_are_counted_and_declared(): void
    {
        // store_order_financials has no backfill, so a pre-ledger order has no row at all. The
        // settled basis cannot see it; saying how many is the disclosure, and it needs no
        // hardcoded cut-over date.
        $this->placeOrder(['customer_id' => 31]);   // no financial row
        $this->placeOrder(['customer_id' => 31]);   // no financial row

        $figures = (new CustomerRetention(true))->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $figures->identifiedCustomers());
        $this->assertSame(2, $figures->notes()['orders_without_ledger_row']);
        $this->assertTrue($figures->hasCaveat(MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE));
    }

    public function test_the_ledger_caveat_does_not_fire_when_every_order_has_a_row(): void
    {
        $this->recordFinancial(
            $this->placeOrder(['customer_id' => 41]),
            ['payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now()]
        );

        $figures = (new CustomerRetention(true))->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $figures->notes()['orders_without_ledger_row']);
        $this->assertFalse($figures->hasCaveat(MetricCaveat::ENTITLEMENT_RECORD_INCOMPLETE));
    }

    public function test_a_foreign_ledger_row_cannot_make_this_stores_order_look_settled(): void
    {
        /*
         * The retention query scopes ORDERS to this store and then joins the ledger. Nothing in
         * the schema forces a financial row's `store_id` to match its order's --
         * `fk_order_financial_order` and `fk_order_financial_store` are two unrelated constraints
         * -- and `uq_store_order_financial_order` only stops a SECOND row for the same order, not
         * a single row carrying the wrong store. So without the equality on the join, another
         * store's `paid` row would make this store's order count as settled.
         *
         * This is the mirror of the §3/§4 case above, where the financial row is ours and the
         * order is foreign. Both directions need the predicate and only one was covered.
         */
        $order = $this->placeOrder(['customer_id' => 61]);

        // The ONLY ledger row for this order, and it belongs to store B.
        $this->recordFinancial($order, [
            'store_id' => self::STORE_B,
            'payment_status' => 'paid', 'paid_amount' => 100.0, 'paid_at' => now(),
        ]);

        $figures = (new CustomerRetention(true))->for($this->scopeA(), $this->allTime());

        $this->assertSame(
            0,
            $figures->identifiedCustomers(),
            'another store\'s ledger row settled this store\'s order'
        );
        $this->assertSame(
            1,
            $figures->notes()['orders_without_ledger_row'],
            'the order has no ledger row OF ITS OWN, and the gap counter must say so'
        );
    }

    public function test_retention_refuses_another_tenants_customers(): void
    {
        // Store B's customer ordered five times. `Order` has no global scope, so only the
        // metric's own predicate keeps them out.
        for ($i = 0; $i < 5; $i++) {
            $this->recordFinancial(
                $this->placeOrder(['store_id' => self::STORE_B, 'customer_id' => 99]),
                [
                    'store_id' => self::STORE_B, 'payment_status' => 'paid',
                    'paid_amount' => 100.0, 'paid_at' => now(),
                ]
            );
        }

        $figures = (new CustomerRetention())->for($this->scopeA(), $this->allTime());

        $this->assertSame(0, $figures->identifiedCustomers(), 'store B\'s customer was counted');
        $this->assertSame(0, $figures->ordersCounted());
        $this->assertSame(0, $figures->notes()['orders_without_identifiable_customer'], 'the exclusion counter leaked too');
    }

    // ----------------------------------------------------------------- the layer's own rules

    public function test_every_metric_refuses_to_read_another_tenant(): void
    {
        /*
         * STORE B NEEDS A ROW EACH METRIC CAN ACTUALLY MATCH, or this case does not test what its
         * name says. Review measured the first version: five of its thirteen legs passed with the
         * tenant predicate DELETED, because store B held nothing those metrics would have selected
         * even unscoped. A `cancelled` metric cannot leak a `paid` row; a refund metric cannot
         * leak an `order_paid` movement; the three unpaid buckets cannot leak a settled order.
         * Each of the five now has a row of its own below.
         */

        // Matched by: GMV, collected payments, margin, shipping and tax, net receipts, coupons.
        $foreign = $this->placeOrder([
            'store_id' => self::STORE_B, 'final_price' => 9999.0, 'payment_type' => 'bank_transfer',
            'cancel_date' => now()->toDateString(),
        ]);
        $this->recordFinancial($foreign, [
            'store_id' => self::STORE_B, 'payment_status' => 'paid', 'paid_amount' => 9999.0,
            'paid_at' => now(), 'customer_total' => 9999.0, 'remaining_amount' => 9999.0,
            'product_amount' => 9999.0, 'product_cost' => 0.0, 'gross_profit' => 9999.0,
            'shipping_amount' => 99.0, 'tax_amount' => 99.0,
        ]);
        $this->recordMovement([
            'store_id' => self::STORE_B, 'amount' => 9999.0, 'reference_id' => $foreign,
            'balance_before' => 0.0, 'balance_after' => 9999.0,
        ]);

        // Matched by: §4 cancelled orders.
        $foreignCancelled = $this->placeOrder([
            'store_id' => self::STORE_B, 'final_price' => 8888.0, 'cancel_date' => null,
        ]);
        $this->recordFinancial($foreignCancelled, [
            'store_id' => self::STORE_B, 'payment_status' => 'cancelled',
            'customer_total' => 8888.0, 'paid_amount' => 0.0, 'remaining_amount' => 0.0,
        ]);

        // Matched by: §5 completed refunds.
        $this->recordMovement([
            'store_id' => self::STORE_B, 'type' => 'order_refunded', 'direction' => 'debit',
            'amount' => 7777.0, 'reference_id' => $foreign,
            'balance_before' => 9999.0, 'balance_after' => 2222.0,
        ]);

        // Matched by: all three §3 unpaid buckets -- one row each, so no bucket is left untested.
        foreach ([
            ['pending', 'cod', 6661.0],
            ['pending', 'bank_transfer', 6662.0],
            ['unpaid', 'stripe', 6663.0],
        ] as [$status, $type, $amount]) {
            $this->recordFinancial(
                $this->placeOrder(['store_id' => self::STORE_B, 'payment_type' => $type]),
                [
                    'store_id' => self::STORE_B, 'payment_status' => $status,
                    'remaining_amount' => $amount, 'customer_total' => $amount,
                ]
            );
        }
        DB::table('order_coupon_details')->insert([
            'order_id' => $foreign, 'coupon_id' => 2, 'coupon_code' => 'BIG',
            'coupon_discount_amount' => 500.0, 'coupon_final_amount' => 9499.0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        /*
         * STORE B'S WALLET IS DELIBERATELY DRIFTED -- 1234 held against a ledger that says 9999.
         *
         * As first written it was consistent, and that made the `drift()` assertion at the end of
         * this case vacuous: a drift check that read every tenant would still have returned an
         * empty array, because store B had nothing to report. The leak was caught only
         * transitively, through the `wallet` figure in the loop above. Now it is caught directly.
         */
        DB::table('store_wallets')->where('id', $this->wallets[self::STORE_B])
            ->update(['available_balance' => 1234.0]);

        $scope = $this->scopeA();
        $window = $this->allTime();

        $singles = [
            'gmv' => (new GrossMerchandiseValue())->for($scope, $window),
            'collected' => (new CollectedCustomerPayments())->for($scope, $window),
            'cancelled' => (new CancelledOrders())->for($scope, $window),
            'refunds' => (new CompletedRefunds())->for($scope, $window),
            'net_receipts' => (new NetFinancialReceipts())->for($scope, $window),
            'wallet' => (new MerchantWalletEntitlement())->for($scope, $window),
            'margin' => (new StoreRevenueMargin())->for($scope, $window),
            'coupons' => (new CouponRedemption())->for($scope, $window),
        ];

        foreach ((new UnpaidOrders())->all($scope, $window) as $name => $result) {
            $singles['unpaid.' . $name] = $result;
        }

        foreach ((new ShippingAndTax())->all($scope, $window) as $name => $result) {
            $singles['shipping_and_tax.' . $name] = $result;
        }

        /*
         * EVERY LEG IS EVALUATED BEFORE ANYTHING IS ASSERTED, and that is deliberate. A loop of
         * assertions stops at the first failure, so a tenancy regression would be reported as one
         * leaking metric when it is in fact thirteen -- and, worse, a leg with no teeth could hide
         * behind an earlier leg that has them. Collecting the figures and asserting the whole map
         * at once names every metric that leaked, in one run.
         */
        $expected = [];
        $actual = [];

        foreach ($singles as $name => $result) {
            $expected[$name] = 0.0;
            $actual[$name] = round($result->total(), 2);
        }

        $this->assertSame(
            $expected,
            $actual,
            'one or more metrics read store B\'s books while scoped to store A'
        );

        $this->assertSame(
            [],
            (new MerchantWalletEntitlement())->drift($scope),
            'the drift check reached store B\'s wallet'
        );
    }

    public function test_a_scope_naming_no_store_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        MetricScope::stores([]);
    }

    public function test_amounts_in_two_currencies_are_never_added_together(): void
    {
        $this->recordFinancial(
            $this->placeOrder(['currency_code' => 'USD', 'final_price' => 100.0])
        );
        $this->recordFinancial(
            $this->placeOrder(['currency_code' => 'SYP', 'final_price' => 500000.0]),
            ['currency_code' => 'SYP']
        );

        $result = (new GrossMerchandiseValue())->for($this->scopeA(), $this->allTime());

        $this->assertTrue($result->hasCaveat(MetricCaveat::MIXED_CURRENCY));
        $this->assertEqualsWithDelta(100.0, $result->totalIn('USD'), 0.001);
        $this->assertEqualsWithDelta(500000.0, $result->totalIn('SYP'), 0.001);

        $this->expectException(MixedCurrencyException::class);
        $result->total();
    }

    public function test_every_figure_names_the_record_it_came_from(): void
    {
        $scope = $this->scopeA();
        $window = $this->allTime();

        $expected = [
            'settlement' => [
                (new GrossMerchandiseValue())->for($scope, $window),
                (new CouponRedemption())->for($scope, $window),
            ],
            'entitlement' => [
                (new CollectedCustomerPayments())->for($scope, $window),
                (new CancelledOrders())->for($scope, $window),
                (new StoreRevenueMargin())->for($scope, $window),
            ],
            'movement' => [
                (new CompletedRefunds())->for($scope, $window),
                (new NetFinancialReceipts())->for($scope, $window),
                (new MerchantWalletEntitlement())->for($scope, $window),
            ],
        ];

        foreach ($expected as $record => $results) {
            foreach ($results as $result) {
                $this->assertSame(
                    $record,
                    $result->record(),
                    $result->metric() . ' does not say which record it measures'
                );
            }
        }
    }
}
