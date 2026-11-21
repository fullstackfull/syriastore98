<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\OrderRefundController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderEntitlementReversal;
use App\Services\RefundIntegrityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F127 — A COMPLETED REFUND MUST MOVE THE MERCHANT'S ENTITLEMENT. A REQUEST MUST NOT.
 * =================================================================================================
 *
 * MEASURED BEFORE THIS CHANGE: `OrderRefundController` is 1218 lines and contains **zero**
 * functional references to `payment_status`, `StoreWallet` or `OrderFinancialService`. A fully
 * refunded order kept `orders.payment_status = 'Paid'`, kept its `store_order_financials` row at
 * `paid`, and kept the merchant's wallet credit. `order_refunds.refund_status` was the only record
 * that money had come back, and nothing financial read it.
 *
 * `store_order_financials.payment_status` has carried `'refunded'` in its production enum the whole
 * time and **nothing in the codebase could reach it**.
 *
 * THE OWNER'S RULE, and the distinction this file exists to hold:
 *
 *   - "A refund request is not a completed refund."
 *   - "Only a verified, completed refund may cause a financial reversal."
 *   - "Do not call a gateway refund complete without provider confirmation or independently
 *      evidenced manual settlement."
 *   - "Customer payment capture history remains truthful."
 *   - "Reversal is idempotent. Duplicate refund notifications cannot debit twice."
 *
 * WHAT MAKES THE DISTINCTION REAL HERE, rather than a naming convention: `resolveRefundStatus()` is
 * the ONLY producer of the target state, and it returns `'Refunded'` (settled) **only** for an
 * OFFLINE payment type. For every online gateway it returns `'Accept'` — approved, not settled —
 * and the `Accept → Refunded` edge is unreachable by construction, because
 * `hasGatewayRefundDriver()` is a hardcoded `return false` for every type and the two gateway
 * refund HTTP clients in the tree have zero callers. **No refund request has ever left this
 * server.** So "do not call a gateway refund complete without provider confirmation" holds today
 * for a blunt reason, and this change does not pretend otherwise: it hangs the reversal on the one
 * transition that already asserts completion.
 *
 * PARTIAL REFUNDS ARE NOT TOUCHED. `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md` determines
 * that the existing ledger cannot represent them safely — `refund_amount` is assigned rather than
 * accumulated, one row per order, per-line detail is display text in a `VARCHAR(255)` blob, no
 * currency, `FLOAT` precision — so that mutation stays BLOCKED by instruction rather than
 * approximated.
 *
 * @see \App\Services\OrderEntitlementReversal
 * @see \App\Services\RefundIntegrityService::resolveRefundStatus()
 */
class RefundEntitlementReversalTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['store_wallets', 'store_order_financials', 'store_wallet_transactions', 'order_refunds'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped($table . ' is absent from the test schema');
            }
        }
    }

    /**
     * A merchant who may manage refunds, their store, a PAID order whose credit has landed, and a
     * refund request sitting at the state a merchant settles FROM.
     *
     * 'Accept', not 'Processing', and the difference is load-bearing: `LEGAL_TRANSITIONS` only
     * permits `Processing -> Accept|Cancel` and `Accept -> Refunded|Cancel`, so a request still at
     * 'Processing' CANNOT reach the settled state. The first draft of this file started there,
     * every settle attempt was refused as an illegal transition, and two cases passed because
     * nothing moved -- for a reason that had nothing to do with the reversal. The premise assertion
     * in the headline case is what caught it.
     *
     * @return array{0: User, 1: Store, 2: Order, 3: int}
     */
    private function paidOrderWithRefundRequest(array $orderOverrides = [], string $refundStatus = 'Accept'): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order Refund Request');

        $order = $this->makeOrder($store, array_merge([
            'payment_status' => 'Paid',
            // OFFLINE: the only family for which `resolveRefundStatus()` returns 'Refunded'.
            'payment_type' => 'bank_transfer',
            'final_price' => 100.00,
            'product_price' => 100.00,
            'product_json' => '[]',
            'delivered_status' => 1,
        ], $orderOverrides));

        $refundId = DB::table('order_refunds')->insertGetId([
            'order_id' => $order->id,
            'refund_status' => $refundStatus,
            'refund_reason' => 'Other',
            'product_refund_id' => json_encode([]),
            'refund_amount' => 0.0,
            'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [User::find($merchant->id), $store, $order, $refundId];
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

    private function financial(Order $order): ?object
    {
        return DB::table('store_order_financials')->where('order_id', $order->id)->first();
    }

    private function walletBalance(Store $store): float
    {
        return (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance');
    }

    private function reversals(Order $order): int
    {
        return (int) DB::table('store_wallet_transactions')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->whereIn('type', ['order_cancelled', 'order_refunded'])
            ->count();
    }

    private function refundStatus(int $refundId): string
    {
        return (string) DB::table('order_refunds')->where('id', $refundId)->value('refund_status');
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISE — without these the cases below could all pass vacuously
    |--------------------------------------------------------------------------
    */

    /** PREMISE: an offline type really does resolve to SETTLED, and an online one does not. */
    public function test_premise_only_an_offline_type_resolves_to_settled(): void
    {
        $service = app(RefundIntegrityService::class);

        $this->assertSame(RefundIntegrityService::STATUS_SETTLED,
            $service->resolveRefundStatus('bank_transfer', 100.00, 'test'),
            'an offline refund no longer resolves to Refunded, so no case here can reach a '
            . 'completed refund at all');

        $this->assertSame(RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
            $service->resolveRefundStatus('stripe', 100.00, 'test'),
            'an ONLINE refund now resolves to settled — which would mean the platform is calling a '
            . 'gateway refund complete with no provider confirmation. That is the owner\'s '
            . 'explicit prohibition.');
    }

    /** PREMISE: no gateway refund driver exists, so no online refund can ever be settled. */
    public function test_premise_no_gateway_refund_driver_exists(): void
    {
        $service = app(RefundIntegrityService::class);

        foreach (['stripe', 'paypal', 'tap', 'benefit', 'paytabs'] as $gateway) {
            $this->assertFalse($service->hasGatewayRefundDriver($gateway),
                "a refund driver appeared for {$gateway}. The reversal in this file hangs on the "
                . "'Refunded' transition, which must stay unreachable for a gateway until a real "
                . 'provider confirmation exists.');
        }
    }

    /** PREMISE: the order's credit landed, so there is an entitlement to reverse. */
    public function test_premise_the_order_credited_the_wallet(): void
    {
        [, $store, $order, ] = $this->paidOrderWithRefundRequest();

        $this->assertEquals(100.00, $this->walletBalance($store),
            'the fixture did not credit the wallet');

        $this->assertSame('paid', (string) $this->financial($order)->payment_status);
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT
    |--------------------------------------------------------------------------
    */

    /** A COMPLETED refund reverses the merchant's entitlement. */
    public function test_a_completed_refund_reverses_the_entitlement(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithRefundRequest();

        $this->settleRefund($merchant, $order, $refundId);

        $this->assertSame(RefundIntegrityService::STATUS_SETTLED, $this->refundStatus($refundId),
            'premise: the refund did not reach the settled state, so nothing was measured');

        $this->assertSame('refunded', (string) $this->financial($order)->payment_status,
            'a fully refunded order still shows the merchant as entitled to the money');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the merchant kept the credit for an order whose money went back to the customer');
    }

    /** AND THE CUSTOMER'S CAPTURE HISTORY STAYS TRUTHFUL. */
    public function test_the_refund_does_not_rewrite_the_customers_payment_history(): void
    {
        [$merchant, , $order, $refundId] = $this->paidOrderWithRefundRequest();

        $before = $this->financial($order);

        $this->settleRefund($merchant, $order, $refundId);

        $this->assertSame('Paid',
            (string) DB::table('orders')->where('id', $order->id)->value('payment_status'),
            'the refund rewrote the order\'s settlement. The customer DID pay; the refund is a '
            . 'separate later event and must not erase the capture.');

        $after = $this->financial($order);

        $this->assertEquals((float) $before->paid_amount, (float) $after->paid_amount,
            'the refund erased how much the customer originally paid');

        $this->assertNotNull($after->paid_at,
            'the refund erased when the customer paid');
    }

    /** The reversal is auditable and names the reason. */
    public function test_the_reversal_is_auditable(): void
    {
        [$merchant, , $order, $refundId] = $this->paidOrderWithRefundRequest();

        $this->settleRefund($merchant, $order, $refundId);

        $txn = DB::table('store_wallet_transactions')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('type', 'order_refunded')
            ->first();

        $this->assertNotNull($txn, 'no refund reversal transaction was written');
        $this->assertSame('debit', (string) $txn->direction);
        $this->assertEquals(100.00, (float) $txn->amount);
        $this->assertStringContainsString('refunded', (string) $txn->description);
    }

    /*
    |--------------------------------------------------------------------------
    | A REQUEST IS NOT A REFUND
    |--------------------------------------------------------------------------
    */

    /**
     * AN APPROVED-BUT-NOT-SETTLED REFUND MOVES NOTHING. This is the heart of the owner's rule.
     *
     * An online gateway order resolves to `Accept`, which means "the merchant agreed" and not "the
     * money went back". No provider was asked, so no entitlement may move.
     */
    public function test_an_approved_but_unsettled_refund_reverses_nothing(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithRefundRequest([
            'payment_type' => 'stripe',
        ]);

        $this->settleRefund($merchant, $order, $refundId);

        $this->assertSame(RefundIntegrityService::STATUS_APPROVED_NOT_SETTLED,
            $this->refundStatus($refundId),
            'premise: an online refund reached the settled state, which it must not');

        $this->assertEquals(100.00, $this->walletBalance($store),
            'an APPROVED refund that settled no money debited the merchant. A request is not a '
            . 'refund.');

        $this->assertSame('paid', (string) $this->financial($order)->payment_status,
            'an unsettled refund moved the financial record out of paid');

        $this->assertSame(0, $this->reversals($order));
    }

    /*
    |--------------------------------------------------------------------------
    | IDEMPOTENCY AND ORDERING — the owner named both sequences
    |--------------------------------------------------------------------------
    */

    /** A duplicate refund notification cannot debit twice. */
    public function test_a_duplicate_refund_does_not_debit_twice(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithRefundRequest();

        $this->settleRefund($merchant, $order, $refundId);
        $this->settleRefund($merchant, $order, $refundId);
        $this->settleRefund($merchant, $order, $refundId);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'a repeated refund debited the merchant more than once; the balance has gone past zero');

        $this->assertSame(1, $this->reversals($order),
            'more than one reversal transaction exists for a single refund');
    }

    /** CANCELLATION THEN REFUND: one reversal, not two. */
    public function test_cancellation_then_refund_reverses_once(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithRefundRequest();

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'test');

        $this->settleRefund($merchant, $order, $refundId);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'cancelling and then refunding debited the merchant twice for one order');

        $this->assertSame(1, $this->reversals($order));
    }

    /** REFUND THEN CANCELLATION: one reversal, not two. */
    public function test_refund_then_cancellation_reverses_once(): void
    {
        [$merchant, $store, $order, $refundId] = $this->paidOrderWithRefundRequest();

        $this->settleRefund($merchant, $order, $refundId);

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'test');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'refunding and then cancelling debited the merchant twice for one order');

        $this->assertSame(1, $this->reversals($order));

        $this->assertSame('refunded', (string) $this->financial($order)->payment_status,
            'a later cancellation overwrote the refund on the ledger, losing the reason the '
            . 'balance moved');
    }

    /*
    |--------------------------------------------------------------------------
    | TENANT BOUNDARY
    |--------------------------------------------------------------------------
    */

    /** A merchant cannot settle another tenant's refund, and no balance moves. */
    public function test_another_tenants_refund_is_refused(): void
    {
        [, $victimStore, $victimOrder, $victimRefund] = $this->paidOrderWithRefundRequest();

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider, ['default_language' => 'en']);
        $outsider->current_store = $outsiderStore->id;
        $outsider->save();
        $this->grantPermission(User::find($outsider->id), 'Manage Order Refund Request');

        $balanceBefore = $this->walletBalance($victimStore);

        $this->settleRefund(User::find($outsider->id), $victimOrder, $victimRefund);

        $this->assertEquals($balanceBefore, $this->walletBalance($victimStore),
            'another tenant moved this store\'s wallet balance through the refund door');

        $this->assertSame(0, $this->reversals($victimOrder),
            'another tenant wrote a reversal against this order');
    }
}
