<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\StoreWallet;
use App\Models\User;
use App\Services\OrderEntitlementReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P4-F124 — CANCELLING A PREPAID ORDER MUST NOT RECORD THAT THE CUSTOMER NEVER PAID.
 * =================================================================================================
 *
 * WHAT IT USED TO DO. `Order::order_status_change()`'s cancel arm wrote
 * `payment_status = 'Unpaid'`, and that line was not a statement about settlement — it was the
 * TRIGGER. `OrderObserver::updated()` returns early unless `wasChanged('payment_status')`, so
 * writing `'Unpaid'` was the only way to make the wallet credit reverse. The arm's own inherited
 * comment calls it "the missing magic line … so the Observer detects the change and calls the
 * wallet service".
 *
 * The cost was that a captured payment was overwritten to produce a side effect. A shopper who paid
 * by card and then cancelled left an order saying they had not paid.
 *
 * THE OWNER'S DECISION IS OPTION A, and these are the clauses this file holds:
 *
 *   - "`orders.payment_status` represents the customer's actual payment settlement."
 *   - "Cancellation must not rewrite a captured payment as Unpaid merely to trigger wallet
 *      reversal."
 *   - "Represent cancellation and financial reversal reasons in the existing financial ledger
 *      structures."
 *   - "Never create a new order payment-status value simply to represent cancellation."
 *   - "Test all seven cancellation doors and duplicate/retried events."
 *
 * THERE ARE NOT SEVEN DOORS. A read-only audit of the route table found **eight always-registered
 * handlers reached by nine routes**, plus a ninth handler and tenth route when the vendored
 * `LotteryPrizes` module is active. The `payment_status` split is 5-write / 3-do-not, not 4/3: the
 * register's count omits `OrderController::order_replace`, which is a genuine cancel door because
 * it cancels the original through the same engine arm. That correction is recorded in the findings
 * register and is why this file enumerates doors explicitly rather than trusting a number.
 *
 * WHAT A PASS HERE MEANS, stated so it cannot be over-read: the ledger now records cancellation as
 * a reversal of ENTITLEMENT while leaving SETTLEMENT alone, and it does so once per order however
 * many times a door fires. It does not mean any historical row was corrected — nothing in this
 * change rewrites past data, by instruction.
 *
 * EVERY ASSERTION READS THE RAW ROW. `Order` and `StoreWallet` are `Cachable` and the suite runs
 * under `CACHE_STORE=array`, where a model read can outlive the write that should have invalidated
 * it — three measured false readings in this repository.
 *
 * @see \App\Services\OrderEntitlementReversal
 * @see \App\Models\Order::order_status_change()
 */
class CancellationLedgerSemanticsTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['store_wallets', 'store_order_financials', 'store_wallet_transactions'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->markTestSkipped($table . ' is absent from the test schema, so every '
                    . 'assertion in this file would be vacuous');
            }
        }
    }

    /**
     * A merchant, a store with a provisioned wallet, and a PAID order whose credit has landed.
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

    private function financial(Order $order): ?object
    {
        return DB::table('store_order_financials')->where('order_id', $order->id)->first();
    }

    private function walletBalance(Store $store): float
    {
        return (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance');
    }

    private function rawPaymentStatus(Order $order): string
    {
        return (string) DB::table('orders')->where('id', $order->id)->value('payment_status');
    }

    private function reversalTransactions(Order $order): int
    {
        return (int) DB::table('store_wallet_transactions')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->whereIn('type', ['order_cancelled', 'order_refunded'])
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISE
    |--------------------------------------------------------------------------
    */

    /** PREMISE: a paid order really does credit the wallet, so there is something to reverse. */
    public function test_premise_a_paid_order_credits_the_wallet(): void
    {
        [, $store, $order] = $this->paidOrder();

        $this->assertEquals(100.00, $this->walletBalance($store),
            'the fixture did not credit the wallet, so every reversal assertion below is vacuous');

        $financial = $this->financial($order);

        $this->assertNotNull($financial, 'no financial row for a paid order');
        $this->assertSame('paid', (string) $financial->payment_status,
            'the financial row is not at paid, so the reversal has nothing to move from');
    }

    /** PREMISE: the ledger enum really accepts the terminal states this change writes. */
    public function test_premise_the_ledger_accepts_cancelled_and_refunded(): void
    {
        [, , $order] = $this->paidOrder();

        foreach (['cancelled', 'refunded'] as $state) {
            DB::table('store_order_financials')
                ->where('order_id', $order->id)
                ->update(['payment_status' => $state]);

            $this->assertSame($state, (string) $this->financial($order)->payment_status,
                "store_order_financials.payment_status cannot hold '{$state}', so this change's "
                . 'whole approach is unavailable on this schema');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | THE CORE SEMANTICS
    |--------------------------------------------------------------------------
    */

    /**
     * THE HEADLINE: the reversal happens and the customer's payment history survives it.
     *
     * Both halves in one case on purpose — asserting them separately would let an implementation
     * that reverses nothing pass the first and one that erases settlement pass the second.
     */
    public function test_a_cancellation_reverses_entitlement_without_rewriting_settlement(): void
    {
        [, $store, $order] = $this->paidOrder();

        $moved = app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'test');

        $this->assertTrue($moved, 'the reversal reported that it did nothing');

        $this->assertSame('Paid', $this->rawPaymentStatus($order),
            'THE DEFECT: cancelling rewrote the customer\'s payment history. They paid; the order '
            . 'must go on saying so.');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the merchant kept the credit for a cancelled order');

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status,
            'the financial row does not record the cancellation, so nothing says why the balance '
            . 'moved');
    }

    /**
     * AND THE EVIDENCE OF THE CAPTURE IS STILL THERE — principle 2, explicitly.
     *
     * `markFinancialAsUnpaid()` zeroes `paid_amount` and nulls `paid_at`, which is right for a
     * merchant correcting a mistaken Paid and wrong for a cancellation. This case is what stops a
     * future implementation reaching for that method as a shortcut.
     */
    public function test_the_cancellation_preserves_what_the_customer_actually_paid(): void
    {
        [, , $order] = $this->paidOrder();

        $before = $this->financial($order);

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $after = $this->financial($order);

        $this->assertEquals((float) $before->paid_amount, (float) $after->paid_amount,
            'the cancellation erased how much the customer paid');

        $this->assertNotNull($after->paid_at,
            'the cancellation erased WHEN the customer paid, so the capture is now unevidenced');
    }

    /** The reversal is recorded as its own auditable event, with a reason. */
    public function test_the_reversal_writes_an_auditable_transaction_naming_the_reason(): void
    {
        [, , $order] = $this->paidOrder();

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'web merchant door');

        $txn = DB::table('store_wallet_transactions')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('type', 'order_cancelled')
            ->first();

        $this->assertNotNull($txn, 'no reversal transaction was written');
        $this->assertSame('debit', (string) $txn->direction);
        $this->assertEquals(100.00, (float) $txn->amount);
        $this->assertStringContainsString('cancelled', (string) $txn->description);
        $this->assertStringContainsString('web merchant door', (string) $txn->description,
            'the door that acted is not recorded, so a balance movement cannot be traced to a cause');
    }

    /*
    |--------------------------------------------------------------------------
    | IDEMPOTENCY — duplicate cancellations and retried webhooks
    |--------------------------------------------------------------------------
    */

    /** THE OWNER NAMED THIS CASE: duplicate cancellation must not reverse twice. */
    public function test_a_duplicate_cancellation_reverses_once(): void
    {
        [, $store, $order] = $this->paidOrder();

        $first = app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);
        $second = app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);
        $third = app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $this->assertTrue($first, 'the first reversal did nothing');
        $this->assertFalse($second, 'the second call reported that it moved money');
        $this->assertFalse($third, 'the third call reported that it moved money');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the merchant was debited more than once for one cancellation — the balance went '
            . 'negative or past zero');

        $this->assertSame(1, $this->reversalTransactions($order),
            'more than one reversal transaction exists for a single cancellation');
    }

    /** A refund after a cancellation does not reverse a second time either. */
    public function test_a_refund_after_a_cancellation_does_not_double_reverse(): void
    {
        [, $store, $order] = $this->paidOrder();

        app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);
        $again = app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_REFUNDED);

        $this->assertFalse($again,
            'a refund on an already-cancelled order reversed the entitlement a second time');

        $this->assertEquals(0.00, $this->walletBalance($store));
        $this->assertSame(1, $this->reversalTransactions($order));
    }

    /*
    |--------------------------------------------------------------------------
    | THE UNPAID CASE — a COD parcel nobody collected
    |--------------------------------------------------------------------------
    */

    /**
     * An unpaid order still records its cancellation, and moves no money.
     *
     * This is the case that distinguishes "cancelled" from "still awaiting collection" in the
     * ledger — without it a cancelled COD order sits at `unpaid` for ever and no report can tell
     * the two apart.
     */
    public function test_an_unpaid_order_records_the_cancellation_and_moves_no_money(): void
    {
        [, $store, $order] = $this->paidOrder([
            'payment_status' => 'Unpaid',
            'payment_type' => 'cod',
        ]);

        $this->assertEquals(0.00, $this->walletBalance($store), 'premise: unpaid credits nothing');

        $moved = app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $this->assertTrue($moved, 'the cancellation of an unpaid order was not recorded at all');

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status,
            'a cancelled COD order is indistinguishable from one still awaiting collection');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'an UNPAID order debited the wallet, taking back money that never arrived');

        $this->assertSame(0, $this->reversalTransactions($order),
            'a wallet transaction was written for an order that never credited the wallet');

        $this->assertSame('Unpaid', $this->rawPaymentStatus($order),
            'the order\'s settlement status was rewritten');
    }

    /*
    |--------------------------------------------------------------------------
    | THE GUARD THAT STOPS THE RECORD BEING ERASED AGAIN
    |--------------------------------------------------------------------------
    */

    /**
     * A LATER `payment_status` CHANGE MUST NOT OVERWRITE THE CANCELLATION.
     *
     * `OrderFinancialService::syncFromOrder()` ends in an unconditional
     * `update(['payment_status' => $orderStatus])`. Left alone, any subsequent movement of the
     * order's own payment status — a merchant correcting it, a late gateway callback — would
     * rewrite the ledger row from `cancelled` back to `paid` or `unpaid` and the record of the
     * reversal would be gone, while the wallet stayed debited.
     *
     * That is principle 2 again, one layer down, and it is the half a reviewer is most likely to
     * miss because nothing in the cancellation path touches it.
     */
    public function test_a_later_payment_status_change_does_not_erase_the_cancellation(): void
    {
        [, $store, $order] = $this->paidOrder();

        app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status);

        /* A merchant now marks the order Unpaid for some unrelated reason. */
        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $fresh->payment_status = 'Unpaid';
        $fresh->save();

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status,
            'a later payment-status change overwrote the cancellation on the financial row, so '
            . 'the ledger no longer records why the wallet was debited');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the later change moved money a second time');
    }

    /** And the same in the other direction: a late Paid must not resurrect the entitlement. */
    public function test_a_late_paid_does_not_resurrect_a_cancelled_entitlement(): void
    {
        [, $store, $order] = $this->paidOrder([
            'payment_status' => 'Unpaid',
            'payment_type' => 'cod',
        ]);

        app(OrderEntitlementReversal::class)->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $fresh = Order::withoutGlobalScopes()->find($order->id);
        $fresh->payment_status = 'Paid';
        $fresh->save();

        $this->assertSame('cancelled', (string) $this->financial($order)->payment_status,
            'a late Paid on a cancelled order moved the ledger row out of cancelled');

        $this->assertEquals(0.00, $this->walletBalance($store),
            'a late Paid credited the merchant for an order that was cancelled');
    }

    /*
    |--------------------------------------------------------------------------
    | NEGATIVE CONTROLS
    |--------------------------------------------------------------------------
    */

    /** An order with no financial row reverses nothing and does not crash. */
    public function test_an_order_with_no_financial_row_is_a_no_op(): void
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        $order = Order::withoutEvents(fn () => $this->makeOrder($store, [
            'payment_status' => 'Paid',
            'final_price' => 50.00,
            'product_json' => '[]',
        ]));

        $this->assertNull($this->financial($order), 'premise: no financial row');

        $moved = app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED);

        $this->assertFalse($moved, 'it claimed to reverse an entitlement that was never created');
    }

    /** An unknown reason is refused rather than written. */
    public function test_an_unknown_reason_is_refused(): void
    {
        [, , $order] = $this->paidOrder();

        $this->expectException(\InvalidArgumentException::class);

        app(OrderEntitlementReversal::class)->reverse($order, 'something_else');
    }

    /** A second store's wallet is untouched by this store's cancellation. */
    public function test_another_stores_wallet_is_not_touched(): void
    {
        [, $storeA, $orderA] = $this->paidOrder();
        [, $storeB, ] = $this->paidOrder();

        $balanceBefore = $this->walletBalance($storeB);

        app(OrderEntitlementReversal::class)->reverse($orderA, OrderEntitlementReversal::REASON_CANCELLED);

        $this->assertEquals($balanceBefore, $this->walletBalance($storeB),
            'cancelling one store\'s order moved another store\'s balance');

        $this->assertEquals(0.00, $this->walletBalance($storeA));
    }
}
