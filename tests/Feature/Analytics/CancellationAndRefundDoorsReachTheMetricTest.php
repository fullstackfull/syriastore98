<?php

namespace Tests\Feature\Analytics;

use App\Http\Controllers\HomeController;
use App\Http\Controllers\OrderRefundController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Metrics\CancelledOrders;
use App\Support\Metrics\CompletedRefunds;
use App\Support\Metrics\MetricCaveat;
use App\Support\Metrics\MetricScope;
use App\Support\Metrics\MetricWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-2 / GAP-150 — THE TWO VERIFICATION LIMBS THE METRIC DEFINITIONS NAME AND NOTHING EXECUTED.
 * =================================================================================================
 *
 * `PHASE-05-METRIC-DEFINITIONS.md` names a verification test under every section. Two of those
 * sentences had no committed test, and `GAP-150` carries them as the part of P5-2 that is
 * "buildable now":
 *
 *   §4 Cancelled orders — "A cancellation **through each door** appears, and the one that writes
 *                          no `cancel_date` is still counted via the fallback."
 *   §5 Completed refunds — "An order at `Accept` contributes nothing; one at `Refunded` contributes
 *                          once; **a replayed refund contributes once**."
 *
 * WHAT WAS ALREADY THERE AND WHY IT IS NOT THESE LIMBS. `MetricLayerTest` holds both sections, and
 * it is the stronger test of the ARITHMETIC — but every one of its cancellation rows is INSERTED,
 * `payment_status => 'cancelled'` written straight into `store_order_financials`. So it proves the
 * metric reads a cancelled row correctly and proves nothing about whether any real door produces
 * one. In particular the "no `cancel_date`" case fabricates the unstamped row, which means the
 * fallback is tested against an assumption about `orderCancel` rather than against `orderCancel`.
 * `CancellationLedgerSemanticsTest` and `RefundEntitlementReversalTest` drive the real doors, and
 * they assert the LEDGER — the financial row, the wallet balance, the reversal transaction. Nobody
 * joined the two halves: no test drove a door and then read the FIGURE a merchant is shown.
 *
 * THAT GAP IS NOT THEORETICAL. The metric dates a cancellation by
 * `COALESCE(orders.cancel_date, store_order_financials.updated_at)`, so a door that stamps neither
 * -- or one whose ledger write happens to land outside the window -- disappears from a bounded
 * report while the ledger looks perfectly correct. Only a test that spans both can see that.
 *
 * SO EACH CASE HERE DRIVES A REAL DOOR AND THEN ASKS THE METRIC. The drive helpers are deliberately
 * the same shapes those two files proved -- the Flutter endpoint with a bearer token, the
 * `order-cancel` endpoint, the web engine, and `OrderRefundController::RefundAmonut()` with a
 * refund request sitting at `Accept` on an OFFLINE payment type -- because a door driven in a way
 * production cannot reach proves nothing either.
 *
 * THE WINDOW IS BOUNDED IN THE FALLBACK CASES, never `allTime()`. `MetricWindow::allTime()` applies
 * no predicate at all, so a date expression could be completely wrong and every assertion would
 * still pass. The fallback is only observable inside bounds.
 */
class CancellationAndRefundDoorsReachTheMetricTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'store_wallets', 'store_order_financials', 'store_wallet_transactions', 'order_refunds',
        ] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped($table . ' is absent from the test schema, so every '
                    . 'assertion in this file would be vacuous');
            }
        }
    }

    /**
     * A merchant, their store with a provisioned wallet, and a PAID order whose credit has landed.
     *
     * @return array{0: User, 1: Store, 2: Order}
     */
    private function paidOrder(array $overrides = []): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');
        $this->grantPermission(User::find($merchant->id), 'Manage Order Refund Request');

        $order = $this->makeOrder($store, array_merge([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Paid',
            'payment_type' => 'stripe',
            'final_price' => 100.00,
            'product_price' => 100.00,
            'product_json' => '[]',
            'return_status' => 0,
        ], $overrides));

        return [User::find($merchant->id), $store, $order];
    }

    private function asMerchant(User $user)
    {
        return $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        );
    }

    private function cancellations(Store $store, ?MetricWindow $window = null): \App\Support\Metrics\MetricResult
    {
        return (new CancelledOrders())->for(
            MetricScope::store($store->id),
            $window ?? MetricWindow::between(now()->subDay(), now()->addDay())
        );
    }

    private function refunds(Store $store, ?MetricWindow $window = null): \App\Support\Metrics\MetricResult
    {
        return (new CompletedRefunds())->for(
            MetricScope::store($store->id),
            $window ?? MetricWindow::between(now()->subDay(), now()->addDay())
        );
    }

    private function financial(Order $order): ?object
    {
        return DB::table('store_order_financials')->where('order_id', $order->id)->first();
    }

    // ------------------------------------------------------------------ premise

    /**
     * PREMISE: before any door fires the figure is zero, and the order really is in the ledger.
     *
     * Without this every case below could pass on a metric that answers 100 for everything, and
     * the "counted once" cases could pass on a metric that answers nothing at all.
     */
    public function test_premise_a_paid_uncancelled_order_is_not_a_cancellation_or_a_refund(): void
    {
        [, $store, $order] = $this->paidOrder();

        $this->assertNotNull($this->financial($order),
            'no financial row was written for the order, so nothing in this file is measuring the '
            . 'metric against a real order');

        $this->assertSame(0, $this->cancellations($store)->rowCount(),
            'a paid, uncancelled order is already counted as a cancellation');

        $this->assertSame(0, $this->refunds($store)->rowCount(),
            'a paid order with no refund is already counted as money returned');
    }

    // ------------------------------------------------------------------ §4, door by door

    /** DOOR 1: the web engine — `Order::order_status_change()`, which every web cancel funnels through. */
    public function test_a_cancellation_through_the_web_engine_door_appears_in_the_metric(): void
    {
        [, $store, $order] = $this->paidOrder();

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'cancel',
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            'the engine refused the cancel, so nothing was measured');

        $result = $this->cancellations($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'a cancellation through the web engine does not reach §4\'s figure, so a merchant '
            . 'reading "cancelled orders" is not shown it');
        $this->assertSame(1, $result->rowCount());
    }

    /** DOOR 2: the shipped Flutter admin app — `POST api/admin/orders/update-status`. */
    public function test_a_cancellation_through_the_flutter_door_appears_in_the_metric(): void
    {
        [$merchant, $store, $order] = $this->paidOrder();

        $this->asMerchant($merchant)->postJson('/api/admin/orders/update-status', [
            'product_order_id' => $order->product_order_id,
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
            'payment_status' => 'Paid',
            'note' => '',
        ]);

        $result = $this->cancellations($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'an order cancelled from the shipped app is missing from §4\'s figure');
        $this->assertSame(1, $result->rowCount());
    }

    /**
     * DOOR 3: `POST api/admin/order-cancel` — THE ONE §4's NOTE IS ABOUT.
     *
     * The definition says: *"`DashboardController::orderCancel` writes **no `cancel_date`** --
     * measured; every other door stamps it. Until that is fixed, a `cancel_date`-dated report
     * under-counts cancellations from that door, and the ledger row's `updated_at` is the
     * fallback."*
     *
     * `MetricLayerTest` tests the fallback against a row it writes itself with
     * `cancel_date => null`. This drives the door and asserts the premise -- the real door really
     * leaves the column null -- before asserting the figure. If that door is ever fixed to stamp
     * the date, the premise assertion here fails and sends the reader to the definition's note,
     * which is then the stale claim.
     */
    public function test_the_door_that_stamps_no_cancel_date_is_still_counted_through_the_fallback(): void
    {
        [$merchant, $store, $order] = $this->paidOrder();

        $this->asMerchant($merchant)->postJson('/api/admin/order-cancel', [
            'order_id' => $order->id,
        ]);

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status,
            'the order-cancel door did not record the cancellation, so the fallback below is '
            . 'measuring nothing');

        $this->assertNull(
            DB::table('orders')->where('id', $order->id)->value('cancel_date'),
            'this door now stamps `cancel_date`. That is an improvement, and it makes §4\'s note '
            . '-- "DashboardController::orderCancel writes no cancel_date" -- the stale claim: '
            . 'correct the definition rather than this test.'
        );

        /* A BOUNDED window, so the date expression is actually exercised: `allTime()` applies no
           predicate and the fallback would be invisible. */
        $result = $this->cancellations($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'a cancellation from the door that stamps no date fell out of a bounded window '
            . 'entirely -- the under-count §4 names, now reachable through a real door');

        $this->assertSame(1, $result->notes()['dated_by_fallback'],
            'the figure counts the row but does not report that it was dated by the fallback, so '
            . 'the merchant cannot tell which of their cancellations are dated by the ledger write '
            . 'rather than by the cancellation itself');

        $this->assertTrue($result->hasCaveat(MetricCaveat::CANCEL_DATE_INCOMPLETE),
            'the caveat §4 requires is absent on a figure that really did fall back');
    }

    /**
     * A RETRIED CANCELLATION THROUGH A DOOR IS ONE CANCELLATION IN THE FIGURE.
     *
     * The owner's P4-F124 clause is "duplicate/retried events", and the ledger half is already
     * held. This is the reporting half: three identical POSTs must not make a merchant's cancelled
     * total read 300.
     */
    public function test_a_retried_cancellation_through_a_door_is_counted_once(): void
    {
        [$merchant, $store, $order] = $this->paidOrder();

        $payload = [
            'product_order_id' => $order->product_order_id,
            'delivered_status' => HomeController::ORDER_STATUS_CANCEL,
            'payment_status' => 'Paid',
            'note' => '',
        ];

        $this->asMerchant($merchant)->postJson('/api/admin/orders/update-status', $payload);
        $this->asMerchant($merchant)->postJson('/api/admin/orders/update-status', $payload);
        $this->asMerchant($merchant)->postJson('/api/admin/orders/update-status', $payload);

        $result = $this->cancellations($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'a retried cancellation was counted once per attempt: the merchant\'s cancelled total '
            . 'is a multiple of the real one');
        $this->assertSame(1, $result->rowCount());
    }

    // ------------------------------------------------------------------ §5, the replay

    /**
     * A refund request sitting where a merchant settles FROM, on the only payment family that can
     * settle at all.
     *
     * `Accept`, not `Processing`: `LEGAL_TRANSITIONS` permits `Processing -> Accept|Cancel` and
     * `Accept -> Refunded|Cancel`, so a request at `Processing` cannot reach the settled state and
     * every assertion would pass for the wrong reason. OFFLINE (`bank_transfer`), because
     * `hasGatewayRefundDriver()` is a hardcoded false and an online type resolves to `Accept`
     * for ever.
     *
     * @return array{0: User, 1: Store, 2: Order, 3: int}
     */
    private function paidOrderWithAcceptedRefund(): array
    {
        [$merchant, $store, $order] = $this->paidOrder([
            'payment_type' => 'bank_transfer',
            'delivered_status' => 1,
        ]);

        $refundId = DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id,
            'refund_status' => 'Accept',
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode([]),
            'refund_amount' => 0.0,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant, $store, $order, $refundId];
    }

    private function settleRefund(User $merchant, Order $order, int $refundId, float $amount = 100.00)
    {
        $this->actingAs($merchant);

        $request = Request::create('/refund-amount/' . $order->id, 'POST', [
            'refundId' => $refundId,
            'refund_amount' => $amount,
        ]);
        $request->setLaravelSession(app('session.store'));

        return (new OrderRefundController())->RefundAmonut($request, $order->id);
    }

    /** §5's first clause, through the real door: an AGREED refund is not money returned. */
    public function test_an_accepted_but_unsettled_refund_is_not_money_returned(): void
    {
        [, $store] = $this->paidOrderWithAcceptedRefund();

        $result = $this->refunds($store);

        $this->assertEqualsWithDelta(0.0, $result->total(), 0.001,
            '§5 calls this the sharpest exclusion in the document: `Accept` means a merchant '
            . 'agreed and no money moved, and for every online gateway it is where a request stays '
            . 'for ever.');
        $this->assertSame(0, $result->rowCount());
    }

    /**
     * §5'S LAST CLAUSE, WHICH NOTHING EXECUTED: A REPLAYED REFUND CONTRIBUTES ONCE.
     *
     * `CompletedRefunds`'s own docblock says this is "true by construction rather than by a
     * DISTINCT in this query" -- the movement is "written exactly once by a locked idempotent
     * path". That is a claim about a path in another class, asserted in prose. This drives the real
     * settle door TWICE and reads the figure, so the claim is executed rather than described.
     *
     * WHAT WOULD MAKE THIS CASE FAIL, MEASURED RATHER THAN ASSUMED -- and the answer is "nothing
     * short of removing three guards at once", which is worth recording because it also says this
     * case cannot be the one that carries the mutation evidence.
     *
     *   1. `OrderRefundController::RefundAmonut()`'s own terminal-state check, `LEGAL_TRANSITIONS
     *      [$currentStatus] === []`, refuses a request already at `Refunded`.
     *   2. With that defeated, `RefundIntegrityService::cappedRefundAmount()` refuses: the replay
     *      came back "A refund amount must be a positive number no greater than the order total."
     *      -- the cumulative ceiling, measured by running the replay with (1) removed.
     *   3. With both defeated, `OrderEntitlementReversal` re-reads the financial row under
     *      `lockForUpdate()` and returns on a terminal status, and `$wasPaid` is false by then.
     *
     * Each layer alone suffices, so a one-line or even two-file mutation leaves this case green.
     * The sibling case below drives the ENGINE door, where layer 3 stands alone, and a mutation
     * removing it does fail that one -- so the metric's insensitivity to a replayed movement is
     * proved there and this case holds the real door end to end.
     */
    public function test_a_replayed_refund_contributes_once(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithAcceptedRefund();

        $this->settleRefund($merchant, $order, $refundId);

        /* PREMISE: the first settle really did settle. Without this a second call that refuses
           would make the "once" assertion pass on zero refunds. */
        $first = $this->refunds($store);
        $this->assertEqualsWithDelta(100.00, $first->total(), 0.001,
            'the first settle moved no money, so the replay below proves nothing');

        /* THE REPLAY: the same refund, settled again -- a double-submitted form, a retried
           request, a merchant pressing the button twice. */
        $this->settleRefund($merchant, $order, $refundId);

        $result = $this->refunds($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'a replayed refund doubled §5\'s figure: the merchant is shown twice as much money '
            . 'returned to customers as actually left their wallet, and net receipts (§6) '
            . 'subtracts it twice');

        $this->assertSame(1, $result->rowCount(),
            'two movement rows exist for one refund, so the figure counts the retry as a second '
            . 'refund');

        $this->assertTrue($result->hasCaveat(MetricCaveat::PARTIAL_REFUNDS_EXCLUDED),
            '§5 requires this caveat unconditionally: the ledger cannot represent a partial '
            . 'refund, so no figure may imply it counted them');
    }

    /**
     * AND THE REPLAY IS COUNTED ONCE THROUGH THE ENGINE TOO, which is the door every other refund
     * path funnels through.
     *
     * Driven separately from the controller above because the two have different guards: the
     * controller refuses on the refund request's own state machine, the engine on the financial
     * row's terminal status re-read under `lockForUpdate()`. A fix to either alone would leave the
     * other door double-counting, and §5's figure cannot tell which door produced a movement.
     */
    public function test_a_refund_replayed_through_the_engine_contributes_once(): void
    {
        [, $store, $order] = $this->paidOrder(['payment_type' => 'bank_transfer', 'delivered_status' => 1]);

        $engine = app(\App\Services\OrderEntitlementReversal::class);

        $this->assertTrue(
            $engine->reverse($order, \App\Services\OrderEntitlementReversal::REASON_REFUNDED),
            'the engine refused the first refund, so the replay below proves nothing'
        );

        $engine->reverse($order->fresh(), \App\Services\OrderEntitlementReversal::REASON_REFUNDED);
        $engine->reverse($order->fresh(), \App\Services\OrderEntitlementReversal::REASON_REFUNDED);

        $result = $this->refunds($store);

        $this->assertEqualsWithDelta(100.00, $result->total(), 0.001,
            'replaying the reversal engine multiplied the money-returned figure');
        $this->assertSame(1, $result->rowCount());
    }

    /**
     * A CANCELLATION IS NOT A REFUND, AND THE TWO FIGURES MUST NOT BOTH CLAIM THE SAME MONEY.
     *
     * §6 is §2 − §5 − cancellations-of-captured-payments, so a door that produced both an
     * `order_cancelled` and an `order_refunded` movement for one order would subtract the same
     * hundred twice. The engine's terminal-state guard is what prevents it; this reads the two
     * figures rather than the ledger.
     */
    public function test_a_cancelled_order_is_not_also_counted_as_a_refund(): void
    {
        [, $store, $order] = $this->paidOrder();

        Order::order_status_change(['order_id' => $order->id, 'order_status' => 'cancel']);

        app(\App\Services\OrderEntitlementReversal::class)
            ->reverse($order->fresh(), \App\Services\OrderEntitlementReversal::REASON_REFUNDED);

        $this->assertEqualsWithDelta(100.00, $this->cancellations($store)->total(), 0.001,
            'the cancellation left §4');

        $this->assertEqualsWithDelta(0.0, $this->refunds($store)->total(), 0.001,
            'the same hundred is counted as BOTH a cancellation and a refund, so net receipts '
            . 'subtracts it twice');
    }
}
