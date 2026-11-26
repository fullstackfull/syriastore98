<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Services\OrderEntitlementReversal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-257 — A `Paid` ON A SETTLED (CANCELLED OR RETURNED) ORDER CREDITS THE STORE WALLET.
 * =================================================================================================
 *
 * WHAT THE REGISTER ROW CLAIMED, AND HOW IT WAS OBTAINED. `GAP-257` was raised by the independent
 * review of the settled-order payment fix and "traced through the code rather than inferred" — so
 * its three residual cases had never been EXECUTED. This file executes them and reads the wallet.
 *
 * THE CHAIN. `payment_status` -> `$order->save()` -> `OrderObserver::updated()` (which returns
 * early unless `wasChanged('payment_status')`) -> `OrderFinancialService::syncFromOrder()`, whose
 * P4-F124 guard returns early ONLY when the financial row is already `cancelled` or `refunded`.
 *
 * THE CASES, AND WHICH THE GUARD ACTUALLY COVERS — every row below is asserted in this file, in
 * BOTH directions, because "the guard misses it" and "the guard catches it" are different tests:
 *
 *   A  cancelled (2), reversal ran, financial row `cancelled`   -> CAUGHT, no credit
 *   B  refunded,      reversal ran, financial row `refunded`    -> CAUGHT, no credit
 *   C  RETURNED (3),  no reversal exists for a return at all    -> MISSED, credits customer_total
 *   D  cancelled (2), financial row left non-terminal           -> MISSED, credits customer_total
 *                     (a reversal that threw; or a cancellation predating P4-F124, whose
 *                      unconditional update wrote the row back to `unpaid` AFTER the debit — the
 *                      ledger then holds TWO `order_paid` credits and one debit for one order)
 *
 * NOTHING IN THIS FILE CHANGES A FIGURE, AND THAT IS THE POINT. Both dispositions the register
 * names for the money half — treat `delivered_status` 2/3 as terminal for the `paid` direction, or
 * route a settled-order correction through the finance surface — change WHEN A MERCHANT IS
 * CREDITED, which CLAUDE.md §2 reserves to the owner. So the money behaviour is PINNED here as it
 * is, with figures, so the decision shows up as a failing test rather than as silence. Invert the
 * `test_*_is_not_covered_*` cases when the owner takes it; do not relax them.
 *
 * WHAT THIS FILE DOES CLOSE is the register's own stated mitigation. The row says "An audit line
 * already ships (`Log::warning('Payment status changed on a settled order')` ...), so a double
 * credit is reconstructible in the meantime." MEASURED: that line exists at exactly one site,
 * `Api/DashboardController.php:1044`, and the web twin named in its own comment —
 * `OrderController::order_payment_status`, behind the same `Manage Order` ability — had no audit
 * line of any kind. So on the panel door the mitigation did not exist, and a credit on a settled
 * order left no trace naming the actor. Adding the trace moves no money and refuses nothing.
 *
 * EVERY ASSERTION READS THE RAW ROW. `Order` and `StoreWallet` are `Cachable`, the suite runs under
 * `CACHE_STORE=array`, and a model read can outlive the write that should have invalidated it.
 *
 * @see \App\Services\OrderFinancialService::syncFromOrder()
 * @see \App\Services\OrderEntitlementReversal::reverse()
 * @see \App\Http\Controllers\OrderController::order_payment_status()
 */
class SettledOrderPaymentCreditTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ASSERTED, NOT BRANCHED ON, and deliberately NOT the `markTestSkipped` shape the older
         * ledger tests in this directory use. GATE F closed on 2026-11-02, so an absent money
         * table is no longer a condition that can be true -- `GateFCoverageLedgerTest` rules that
         * a branch on it is dead code reading as coverage skipped, and a conditional skip would
         * additionally break the suite's declared outcome set.
         */
        foreach ([
            'store_wallets' => '2026_11_02_000100_create_store_wallets_table.php',
            'store_order_financials' => '2026_11_02_000400_create_store_order_financials_table.php',
            'store_wallet_transactions' => '2026_11_02_000200_create_store_wallet_transactions_table.php',
        ] as $table => $migration) {
            $this->assertTrue(Schema::hasTable($table),
                $table . ' is absent, so every assertion in this file would be vacuous. It is '
                . 'created by database/migrations/' . $migration . '.');
        }
    }

    /**
     * A merchant holding `Manage Order`, their store with a provisioned wallet, and one order.
     *
     * The order is `cod`/`Unpaid` by default, which is the state every case here starts from: the
     * financial row is written at `unpaid` and the wallet is untouched, so any later movement is
     * attributable to the request under test and nothing else.
     *
     * @return array{0: User, 1: Store, 2: Order}
     */
    private function merchantWithOrder(array $orderOverrides = []): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->grantPermission(User::find($merchant->id), 'Manage Order');

        $order = $this->makeOrder($store, array_merge([
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Unpaid',
            'payment_type' => 'cod',
            'final_price' => 100.00,
            'product_price' => 100.00,
            'product_json' => '[]',
            'return_status' => 0,
        ], $orderOverrides));

        return [User::find($merchant->id), $store, $order];
    }

    private function walletBalance(Store $store): float
    {
        return (float) DB::table('store_wallets')->where('store_id', $store->id)->value('available_balance');
    }

    private function financialStatus(Order $order): ?string
    {
        $value = DB::table('store_order_financials')->where('order_id', $order->id)->value('payment_status');

        return $value === null ? null : (string) $value;
    }

    private function creditCount(Order $order): int
    {
        return (int) DB::table('store_wallet_transactions')
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->where('type', 'order_paid')
            ->where('direction', 'credit')
            ->count();
    }

    /** Move `delivered_status` without touching `payment_status`, so the observer stays asleep. */
    private function setDeliveredStatus(Order $order, int $status): void
    {
        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $fresh->delivered_status = $status;
        $fresh->save();
    }

    /** The panel door, driven as the owning merchant. */
    private function postPaidThroughThePanel(User $merchant, Order $order)
    {
        return $this->actingAs($merchant)->post(route('order.payment.status'), [
            'order_id' => $order->id,
            'payment_status' => 'Paid',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | PREMISES — without these every assertion below is vacuous
    |--------------------------------------------------------------------------
    */

    /** PREMISE: an unpaid cod order credits nothing, so a later credit is attributable. */
    public function test_premise_an_unpaid_order_credits_nothing(): void
    {
        [, $store, $order] = $this->merchantWithOrder();

        $this->assertSame('unpaid', $this->financialStatus($order),
            'the fixture did not write a financial row at unpaid, so nothing below is measuring '
            . 'the paid direction of syncFromOrder()');
        $this->assertEquals(0.00, $this->walletBalance($store),
            'the fixture already credited the wallet, so a later credit cannot be attributed');
    }

    /** PREMISE: the panel door really does move the wallet, so a refusal is distinguishable. */
    public function test_premise_the_panel_door_credits_a_live_order(): void
    {
        [$merchant, $store, $order] = $this->merchantWithOrder();

        $this->postPaidThroughThePanel($merchant, $order);

        $this->assertEquals(100.00, $this->walletBalance($store),
            'THE CONTROL: a Paid on a PENDING order must credit the merchant. Without this, every '
            . '"nothing moved" assertion below would also pass for a door that is simply broken.');
        $this->assertSame('paid', $this->financialStatus($order));
        $this->assertSame(1, $this->creditCount($order));
    }

    /*
    |--------------------------------------------------------------------------
    | THE TWO CASES THE P4-F124 GUARD DOES COVER
    |--------------------------------------------------------------------------
    */

    /** CASE A: a cancellation whose reversal ran leaves the row terminal, and the guard holds. */
    public function test_case_a_a_paid_on_a_reversed_cancellation_credits_nothing(): void
    {
        [$merchant, $store, $order] = $this->merchantWithOrder();

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'test');
        $this->setDeliveredStatus($order, HomeController::ORDER_STATUS_CANCEL);

        $this->assertSame('cancelled', $this->financialStatus($order),
            'the premise of this case is a TERMINAL financial row');

        $this->postPaidThroughThePanel($merchant, $order);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'P4-F124 guard breached: a late Paid credited a cancelled order');
        $this->assertSame(0, $this->creditCount($order));
        $this->assertSame('cancelled', $this->financialStatus($order),
            'the cancellation record was erased by a settlement edit');
    }

    /** CASE B: the same, for a refund. */
    public function test_case_b_a_paid_on_a_reversed_refund_credits_nothing(): void
    {
        [$merchant, $store, $order] = $this->merchantWithOrder();

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_REFUNDED, 'test');
        $this->setDeliveredStatus($order, HomeController::ORDER_STATUS_RETURN);

        $this->assertSame('refunded', $this->financialStatus($order));

        $this->postPaidThroughThePanel($merchant, $order);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'P4-F124 guard breached: a late Paid credited a refunded order');
        $this->assertSame(0, $this->creditCount($order));
    }

    /*
    |--------------------------------------------------------------------------
    | THE TWO CASES IT DOES NOT — PINNED AS MEASURED, PENDING THE OWNER'S CALL
    |--------------------------------------------------------------------------
    */

    /**
     * CASE C — A RETURNED ORDER. The register calls this the most likely of the three, and it is
     * the one that needs no hand-made state at all: NOTHING calls `OrderEntitlementReversal` for a
     * return. The financial row is moved to `refunded` only by `OrderRefundController`, so a
     * returned-but-not-yet-refunded order's row sits at `unpaid` and the guard has nothing to see.
     *
     * MEASURED: wallet 0.00 -> 100.00 on an order whose goods came back.
     */
    public function test_case_c_a_paid_on_a_returned_order_is_not_covered_by_the_guard(): void
    {
        [$merchant, $store, $order] = $this->merchantWithOrder();

        $this->setDeliveredStatus($order, HomeController::ORDER_STATUS_RETURN);

        $this->assertSame('unpaid', $this->financialStatus($order),
            'a return left the financial row terminal, which would make this case moot');

        $this->postPaidThroughThePanel($merchant, $order);

        $this->assertEquals(100.00, $this->walletBalance($store),
            'GAP-257 case C changed behaviour. If the owner has taken the money half of I-257, '
            . 'this expectation must be INVERTED to 0.00, not relaxed.');
        $this->assertSame(1, $this->creditCount($order));
    }

    /**
     * CASE D — A CANCELLATION WHOSE FINANCIAL ROW IS NOT TERMINAL, which is the genuine DOUBLE
     * credit and the one P4-F124's own docblock names.
     *
     * The state is reached two ways in production and the measurement is the same for both: a
     * `reverseOrLog()` whose reversal threw (logged `critical`, status move rolled back), and a
     * cancellation predating P4-F124, whose unconditional `update(['payment_status' => ...])` wrote
     * the row back to `unpaid` AFTER the wallet had already been debited. It is built here the
     * second way, through the ledger's own writers, so the sequence is real rather than asserted:
     * credit, reverse, then the pre-P4-F124 overwrite.
     *
     * THE FIRST DRAFT OF THIS CASE READ 0.00 AND THAT WAS THE TEST'S FAULT, not a refutation.
     * `OrderObserver::updated()` returns early unless `wasChanged('payment_status')`, so posting
     * `Paid` to an order whose column ALREADY said `Paid` never reached `syncFromOrder()` at all —
     * a "nothing moved" reading from a request that did nothing. The sequence below moves the
     * column for real: Paid (credit 1) -> cancel + reverse (debit) -> row forced non-terminal ->
     * Unpaid (a no-op on an already-unpaid row) -> Paid (credit 2). This is exactly the trap
     * CLAUDE.md names as a test passing for the wrong reason, met in the wrong direction.
     *
     * MEASURED: the ledger ends holding TWO `order_paid` credits and ONE `order_cancelled` debit
     * for ONE order, and the wallet keeps 100.00 of a cancelled order's value.
     */
    public function test_case_d_a_paid_on_a_cancellation_with_a_non_terminal_row_credits_twice(): void
    {
        [$merchant, $store, $order] = $this->merchantWithOrder(['payment_status' => 'Paid']);

        $this->assertEquals(100.00, $this->walletBalance($store),
            'the fixture did not take the first credit, so there is no second one to measure');
        $this->assertSame(1, $this->creditCount($order));

        app(OrderEntitlementReversal::class)
            ->reverse($order, OrderEntitlementReversal::REASON_CANCELLED, 'test');
        $this->setDeliveredStatus($order, HomeController::ORDER_STATUS_CANCEL);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the reversal did not debit, so the double credit below would not be a double credit');

        /*
         * THE PRE-P4-F124 STATE, written exactly as the removed code wrote it: the financial row
         * back to a non-terminal value while the debit stands. This is the state P4-F124 stopped
         * CREATING and did not repair, so it is what a live install can still hold.
         */
        DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->update(['payment_status' => 'unpaid']);

        /*
         * AND THE ORDER'S OWN COLUMN HAS TO MOVE, or the observer never wakes. A merchant
         * correcting a mistaken Paid is the ordinary way this happens.
         */
        $this->actingAs($merchant)->post(route('order.payment.status'), [
            'order_id' => $order->id,
            'payment_status' => 'Unpaid',
        ]);

        $this->assertEquals(0.00, $this->walletBalance($store),
            'the Unpaid leg moved money, so the credit measured below would not be attributable');
        $this->assertSame(1, $this->creditCount($order));

        $this->postPaidThroughThePanel($merchant, $order);

        $this->assertEquals(100.00, $this->walletBalance($store),
            'GAP-257 case D changed behaviour. If the owner has taken the money half of I-257, '
            . 'this expectation must be INVERTED to 0.00, not relaxed.');
        $this->assertSame(2, $this->creditCount($order),
            'the ledger should hold two order_paid credits for one order here — that is the '
            . '"credit the merchant a second time" P4-F124\'s docblock names');
    }

    /*
    |--------------------------------------------------------------------------
    | THE MITIGATION — the register relies on it, and on the panel door it did not exist
    |--------------------------------------------------------------------------
    */

    /**
     * THE FIX THIS FILE CARRIES. A credit on a settled order must leave a trace naming the actor,
     * on EVERY door that can take it. The admin API has had one since the echo fix; the panel door
     * that the API's own comment names as the older and wider one had none.
     */
    public function test_the_panel_door_records_an_audit_line_for_a_settled_order(): void
    {
        /*
         * ONE LISTENER, REGISTERED ONCE, AND THAT IS NOT TIDYING. Registering it inside the loop
         * attached a SECOND listener on the second pass while the first still held a reference to
         * the same `$lines` slot, so one warning was counted twice and the case failed against a
         * correct fix. Measured on this file.
         */
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            if ($message->level === 'warning'
                && $message->message === 'Payment status changed on a settled order') {
                $lines[] = $message->context;
            }
        });

        foreach ([HomeController::ORDER_STATUS_CANCEL, HomeController::ORDER_STATUS_RETURN] as $status) {
            [$merchant, , $order] = $this->merchantWithOrder();
            $this->setDeliveredStatus($order, $status);

            $lines = [];

            $this->postPaidThroughThePanel($merchant, $order);

            $this->assertCount(1, $lines,
                'the panel door credited a settled order (delivered_status ' . $status . ') and '
                . 'left no trace of it. GAP-257\'s stated mitigation is that this line exists.');
            $this->assertSame($order->id, $lines[0]['order_id'] ?? null);
            $this->assertSame((int) $merchant->id, $lines[0]['actor_id'] ?? null,
                'an audit line that does not name the actor cannot reconstruct a double credit');
            $this->assertSame($status, $lines[0]['delivered_status'] ?? null);
            $this->assertSame('Unpaid', $lines[0]['old_payment_status'] ?? null);
            $this->assertSame('Paid', $lines[0]['new_payment_status'] ?? null);
        }
    }

    /**
     * AND IT IS NOT A LOG ON EVERY EDIT. A live order's payment status is recorded routinely; a
     * warning on each one would bury the settled-order case it exists to surface. Same predicate
     * as the API twin: a settled `delivered_status` AND an actual change of value.
     */
    public function test_the_panel_door_does_not_log_a_live_or_unchanged_edit(): void
    {
        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            if ($message->message === 'Payment status changed on a settled order') {
                $lines[] = $message->context;
            }
        });

        // A live order.
        [$merchant, , $order] = $this->merchantWithOrder();
        $this->postPaidThroughThePanel($merchant, $order);
        $this->assertCount(0, $lines, 'a routine Paid on a pending order raised a warning');

        // A settled order whose payment status does not actually move.
        [$merchant2, , $settled] = $this->merchantWithOrder(['payment_status' => 'Paid']);
        $this->setDeliveredStatus($settled, HomeController::ORDER_STATUS_CANCEL);
        $this->postPaidThroughThePanel($merchant2, $settled);
        $this->assertCount(0, $lines, 'Paid -> Paid is not a change and must not raise a warning');
    }
}
