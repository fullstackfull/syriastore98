<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use App\Models\Role;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\User;
use App\Services\OrderEntitlementReversal;
use App\Services\OrderFinancialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * THE TERMINAL GUARD IS ONE CALLER DEEP, AND THE OTHER CALLER IS THE ONE THE GUARD RECOMMENDS.
 * GAP-257.
 * =================================================================================================
 *
 * WHAT GAP-257 SAYS, AND WHAT THIS FILE MEASURED INSTEAD.
 *
 * The row describes three residual cases, all on ONE path: the order's own `payment_status` moves,
 * `OrderObserver::updated()` fires, `OrderFinancialService::syncFromOrder()` runs, and a late
 * `Paid` credits the merchant again. Its P4-F124 guard returns early when the financial row is
 * already `cancelled` or `refunded`, and the row's proposed dispositions are (a) widen that guard
 * to treat `delivered_status` 2 and 3 as terminal, or (b) *"route a settled-order payment
 * correction through the finance surface on both doors"* -- both called a money-rule change
 * belonging with an owner decision.
 *
 * DRIVEN RATHER THAN READ, AND DISPOSITION (b) RESTS ON A FALSE PREMISE. The finance surface is
 * the SECOND caller of the credit, and it does not go through the guard:
 *
 *     app/Services/OrderFinancialService.php:344   syncFromOrder()   -- HAS the terminal guard
 *     app/Http/Controllers/StoreFinanceController.php:527            -- calls markFinancialAsPaid()
 *                                                                      DIRECTLY
 *
 * and `markFinancialAsPaid()` itself (`:439-456`) carries exactly ONE guard --
 * `if (! $financial || $financial->payment_status === 'paid') return;` -- which refuses an
 * already-paid row and says nothing about `cancelled` or `refunded`. So the control P4-F124 added
 * lives in one of the credit's two callers, and the caller that lacks it is the very door the
 * guard's own docblock nominates as the safe route: *"A merchant who genuinely needs to move a
 * reversed order's entitlement does it through the finance surface, which acts on the financial
 * record deliberately and with its own authorization."*
 *
 * -------------------------------------------------------------------------------------------------
 * LIVE, NOT LATENT, AND THE CONTROL IS RENDERED FOR EXACTLY THESE ROWS
 * -------------------------------------------------------------------------------------------------
 *
 * `POST finance/order-financials/{id}/payment-status` (`routes/web.php:290-293`) is gated on
 * `finance.permission:Edit Finance Payment Status` and nothing else. Its validator admits
 * `EntitlementStateLabel::selectable()`, which is `['paid', 'unpaid']` -- so `paid` passes while
 * `cancelled` and `refunded` are refused as REQUESTED values (`FinanceAdjustmentAccountabilityTest
 * ::test_a_state_the_form_cannot_offer_is_refused_by_the_door`). That existing case pins "a
 * merchant may not DECLARE a terminal state". Nothing pinned "a merchant may not LEAVE one", which
 * is this file.
 *
 * And the screen offers the control on a terminal row. The handler's own audit comment
 * (`StoreFinanceController:572-577`) says so in as many words: *"the screen's select offers only
 * unpaid/paid, so on a cancelled row NEITHER option carries `selected` and the browser submits the
 * first one -- 'unpaid'."* That paragraph reasons correctly about the `unpaid` direction --
 * `markFinancialAsUnpaid()` writes nothing unless the row is exactly `paid`, so on a cancelled row
 * it IS the no-op the comment describes. The `paid` direction is the one nobody checked.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THIS IS A SECOND CREDIT AND NOT A RESTORED ONE
 * -------------------------------------------------------------------------------------------------
 *
 * The arithmetic matters, because "the wallet nets to one credit" is true of several sequences in
 * this family and is NOT true of this one. Every path out of `paid` debits as it goes:
 *
 *     markFinancialAsUnpaid()   requires the row be `paid`, debits, writes `unpaid`
 *     reverse()                 refuses a row already terminal; debits iff it was `paid`
 *
 * so a credit always has a matching debit -- EXCEPT here. The reversal has already debited and
 * moved the row to `cancelled`; this door then credits the FULL `customer_total` again and
 * overwrites `cancelled` with `paid`. The order ends with TWO `order_paid` credit transactions and
 * ONE `order_cancelled` debit, a wallet holding money for goods that were never sold, and no row
 * left saying the entitlement was ever reversed -- which is the exact loss P4-F124's guard exists
 * to prevent, reached by not going through it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT CLAIM
 * -------------------------------------------------------------------------------------------------
 *
 * The three cases GAP-257 itself names are on the `syncFromOrder` path and are NOT this. Two
 * control cases below drive that path and assert the guard still holds there, so this finding
 * cannot be mistaken for a re-report of the row: `test_control_*`. And the RETURNED-order case the
 * row calls most likely is measured on its own terms rather than assumed --
 * `test_measured_a_returned_order_*` -- because its arithmetic is a restored single credit, not a
 * double one, and the distinction is the whole reason this file exists.
 *
 * NO CAPABILITY IS REMOVED BY THE FIX, which is what makes it a defect fix rather than a money-rule
 * change. The deliberate re-credit route already exists and is a different door:
 * `StoreFinanceController::addBalance()` (`:1169`), gated on its own permission `Add Finance
 * Balance`, validating an explicit `amount` and REQUIRING a `description`. The two cases below
 * close a silent, full-`customer_total`, unamounted duplicate of that -- not the route itself.
 *
 * ALL MONEY ASSERTIONS ARE ON THE WALLET AND THE TRANSACTION LEDGER, never an HTTP code. The door
 * answers `back()` with a flash message for every outcome it has, so a 302 proves nothing.
 * `StoreWallet` and `StoreWalletTransaction` are among the sixteen models that are NOT `Cachable`,
 * so a direct read is safe here; `Order` IS cached and is read through `DB::table()`.
 *
 * @see \App\Services\OrderFinancialService::markFinancialAsPaid()
 * @see \App\Services\OrderFinancialService::syncFromOrder()
 * @see \App\Http\Controllers\StoreFinanceController::updateFinancialPaymentStatus()
 */
class TerminalEntitlementSecondCreditTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId = 9257;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    // =============================================================================================
    //  FIXTURE
    // =============================================================================================

    /**
     * A store with a wallet, one 250.00 order, and a financial row at `unpaid`.
     *
     * Shaped after `FinanceAdjustmentAccountabilityTest::seedFixture()` so the two files cannot
     * disagree about what this surface's rows look like.
     *
     * @return array{walletId: int, orderId: int, financialId: int, owner: User}
     */
    private function seedFixture(): array
    {
        $owner = User::factory()->create(['email' => 'gap257-owner@example.test', 'type' => 'admin']);

        DB::table('stores')->insert([
            'id' => $this->storeId,
            'name' => 'GAP-257 store',
            'slug' => 'gap257-store',
            'email' => 'gap257@example.test',
            'theme_id' => 'stylique',
            'created_by' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $walletId = DB::table('store_wallets')->insertGetId([
            'store_id' => $this->storeId, 'currency_code' => 'USD',
            'available_balance' => 0, 'pending_balance' => 0, 'withdrawal_pending_balance' => 0,
            'total_income' => 0, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-GAP257', 'product_json' => json_encode([]),
            'product_price' => 250.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 250.0,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'delivered_status' => 0,
            'store_id' => $this->storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $financialId = DB::table('store_order_financials')->insertGetId([
            'store_id' => $this->storeId, 'wallet_id' => $walletId, 'order_id' => $orderId,
            'currency_code' => 'USD', 'currency_symbol' => '$',
            'product_amount' => 250, 'discount_amount' => 0, 'tax_amount' => 0, 'shipping_amount' => 0,
            'customer_total' => 250, 'product_cost' => 0, 'gross_profit' => 0,
            'payment_status' => 'unpaid', 'paid_amount' => 0, 'remaining_amount' => 250,
            'paid_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('walletId', 'orderId', 'financialId', 'owner');
    }

    /**
     * The store's own owner holding both finance permissions through a real Laratrust role.
     *
     * BOTH are needed and the second one is not obvious: `finance.index` carries
     * `finance.permission:View Finance Dashboard`, and this door's `from(route('finance.index'))`
     * redirect target would otherwise 403. Taken from the sibling file, which records that its
     * first run got exactly that error page.
     */
    private function financeEditor(User $owner): User
    {
        $role = Role::create([
            'name' => 'gap257-finance-editor', 'display_name' => 'Finance editor',
            'created_by' => $owner->id, 'store_id' => $this->storeId,
        ]);

        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $role->givePermission(\App\Models\Permission::firstOrCreate(
                ['name' => $name], ['display_name' => $name],
            ));
        }

        $owner->addRole($role);
        $owner->current_store = $this->storeId;
        $owner->save();
        $owner->flushCache();

        $editor = User::find($owner->id);

        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $this->assertTrue($editor->isAbleTo($name),
                "PREMISE: this principal does not hold '{$name}', so the door below refuses them "
                . 'and every money assertion in this file would pass while measuring the refusal.');
        }

        return $editor;
    }

    // =============================================================================================
    //  READS -- the wallet and the ledger are the evidence, never the HTTP code
    // =============================================================================================

    private function walletBalance(int $walletId): float
    {
        return (float) StoreWallet::findOrFail($walletId)->available_balance;
    }

    private function financialState(int $financialId): string
    {
        return (string) DB::table('store_order_financials')
            ->where('id', $financialId)->value('payment_status');
    }

    /** @return array<int, string> the ledger as `direction:type` pairs, oldest first */
    private function ledger(int $walletId): array
    {
        return StoreWalletTransaction::where('wallet_id', $walletId)
            ->orderBy('id')
            ->get()
            ->map(fn ($t) => $t->direction . ':' . $t->type)
            ->all();
    }

    private function creditCount(int $walletId): int
    {
        return count(array_filter(
            $this->ledger($walletId),
            fn (string $row) => $row === 'credit:order_paid'
        ));
    }

    // =============================================================================================
    //  STEPS
    // =============================================================================================

    /** Credit the order legitimately, the way the product does: the ORDER's own status moves. */
    private function creditOnceThroughTheOrder(int $orderId): void
    {
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $order->payment_status = 'Paid';
        $order->save();
    }

    private function reverse(int $orderId, string $reason): bool
    {
        return app(OrderEntitlementReversal::class)->reverse(
            Order::withoutGlobalScopes()->findOrFail($orderId),
            $reason,
            'gap257 fixture'
        );
    }

    /** The finance surface's own door, as a merchant's browser reaches it. */
    private function financeSurfaceMarks(User $editor, int $financialId, string $state)
    {
        return $this->actingAs($editor)
            ->from(route('finance.index'))
            ->post(route('finance.order-financial.status', $financialId), [
                'payment_status' => $state,
                'reason' => 'Correcting this by hand.',
            ]);
    }

    // =============================================================================================
    //  PREMISES
    // =============================================================================================

    /**
     * THE LEGITIMATE CREDIT LANDS, AND THE REVERSAL TAKES IT BACK. Without this, every "the wallet
     * did not move" assertion below could be the fixture never having moved any money at all.
     */
    public function test_premise_one_credit_then_a_reversal_nets_to_nothing(): void
    {
        $f = $this->seedFixture();

        $this->creditOnceThroughTheOrder($f['orderId']);

        $this->assertSame(250.0, $this->walletBalance($f['walletId']),
            'the legitimate credit did not land, so this fixture measures nothing. Ledger: '
            . json_encode($this->ledger($f['walletId'])));
        $this->assertSame('paid', $this->financialState($f['financialId']));

        $this->assertTrue($this->reverse($f['orderId'], OrderEntitlementReversal::REASON_CANCELLED),
            'the reversal reported no movement');

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            'the reversal did not debit, so a later credit would not be a SECOND one.');
        $this->assertSame('cancelled', $this->financialState($f['financialId']));
        $this->assertSame(1, $this->creditCount($f['walletId']));
    }

    /**
     * THE CREDIT FUNCTION'S ONLY GUARD, ASSERTED DIRECTLY. This is the disagreement itself: the
     * terminal check lives in `syncFromOrder()`, and the function that moves the money has none.
     */
    public function test_premise_the_credit_function_is_the_place_the_money_moves(): void
    {
        $f = $this->seedFixture();
        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->reverse($f['orderId'], OrderEntitlementReversal::REASON_CANCELLED);

        /* Called with no HTTP door and no observer in the way: just the service. */
        app(OrderFinancialService::class)->markFinancialAsPaid((int) $f['financialId']);

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            'OrderFinancialService::markFinancialAsPaid() credited a `cancelled` financial row. '
            . 'Its only guard refuses a row that is already `paid`; the terminal states are '
            . 'checked by syncFromOrder() alone, so every OTHER caller of this function -- and '
            . 'there is one, StoreFinanceController:527 -- bypasses the control. Wallet: '
            . $this->walletBalance($f['walletId']) . ', row: '
            . $this->financialState($f['financialId']) . ', ledger: '
            . json_encode($this->ledger($f['walletId'])));
    }

    // =============================================================================================
    //  CONTROLS -- the guard DOES hold where GAP-257 put it
    // =============================================================================================

    /**
     * THE PATH GAP-257 DESCRIBES IS GUARDED, so this file is a different finding and not a
     * re-report. A late `Paid` on the ORDER after a cancellation moves no money.
     */
    public function test_control_a_late_paid_on_the_order_itself_is_still_refused(): void
    {
        $f = $this->seedFixture();
        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->reverse($f['orderId'], OrderEntitlementReversal::REASON_CANCELLED);

        /* The order's own status moves again -- the observer path, which HAS the guard. */
        $order = Order::withoutGlobalScopes()->findOrFail($f['orderId']);
        $order->payment_status = 'Unpaid';
        $order->save();
        $order->payment_status = 'Paid';
        $order->save();

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            'the P4-F124 guard in syncFromOrder() has stopped working. That guard is NOT what this '
            . 'file is about, and if it is broken the finding here is the smaller half.');
        $this->assertSame('cancelled', $this->financialState($f['financialId']),
            'syncFromOrder() overwrote the terminal state');
    }

    /** The same control for `refunded`, the other terminal state. */
    public function test_control_a_late_paid_after_a_refund_is_still_refused(): void
    {
        $f = $this->seedFixture();
        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->reverse($f['orderId'], OrderEntitlementReversal::REASON_REFUNDED);

        $order = Order::withoutGlobalScopes()->findOrFail($f['orderId']);
        $order->payment_status = 'Unpaid';
        $order->save();
        $order->payment_status = 'Paid';
        $order->save();

        $this->assertSame(0.0, $this->walletBalance($f['walletId']));
        $this->assertSame('refunded', $this->financialState($f['financialId']));
    }

    // =============================================================================================
    //  THE DEFECT, THROUGH THE REAL DOOR
    // =============================================================================================

    /**
     * @dataProvider terminalStates
     */
    public function test_the_finance_surface_cannot_credit_a_terminal_row_a_second_time(
        string $reason
    ): void {
        $f = $this->seedFixture();
        $editor = $this->financeEditor($f['owner']);

        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->reverse($f['orderId'], $reason);

        $this->assertSame(0.0, $this->walletBalance($f['walletId']), 'premise: net zero before the door');

        $this->financeSurfaceMarks($editor, (int) $f['financialId'], 'paid');

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            "POST finance/order-financials/{id}/payment-status with payment_status=paid on a "
            . "`{$reason}` row credited the merchant 250.00 for the SECOND time. The entitlement "
            . 'was already reversed and a debit records it, so this is not a restored credit: the '
            . 'wallet now holds money for goods that were never sold. Ledger: '
            . json_encode($this->ledger($f['walletId'])));

        $this->assertSame(1, $this->creditCount($f['walletId']),
            'the order carries more than one `credit:order_paid` transaction. Ledger: '
            . json_encode($this->ledger($f['walletId'])));

        $this->assertSame($reason, $this->financialState($f['financialId']),
            "the terminal state `{$reason}` was overwritten with `"
            . $this->financialState($f['financialId']) . '`, so nothing on the row says the '
            . 'entitlement was ever reversed. Preserving that record is the whole purpose of the '
            . 'guard in syncFromOrder(), whose docblock calls it the owner\'s principle 2 one '
            . 'layer down: "Cancellation does not erase evidence of a captured payment."');
    }

    /** @return array<string, array{0: string}> */
    public static function terminalStates(): array
    {
        return [
            'cancelled' => [OrderEntitlementReversal::REASON_CANCELLED],
            'refunded' => [OrderEntitlementReversal::REASON_REFUNDED],
        ];
    }

    // =============================================================================================
    //  BOTH DIRECTIONS -- the legitimate work of this door must survive
    // =============================================================================================

    /**
     * THE DOOR'S ORDINARY JOB STILL WORKS. A guard that refused every `paid` would pass both cases
     * above and break the finance surface, which exists to settle unpaid orders by hand.
     */
    public function test_the_finance_surface_can_still_settle_an_unpaid_order(): void
    {
        $f = $this->seedFixture();
        $editor = $this->financeEditor($f['owner']);

        $this->financeSurfaceMarks($editor, (int) $f['financialId'], 'paid');

        $this->assertSame(250.0, $this->walletBalance($f['walletId']),
            'the finance surface can no longer settle an ordinary unpaid order. That is this '
            . 'door\'s entire purpose and a guard that breaks it is a defect, not a fix. Ledger: '
            . json_encode($this->ledger($f['walletId'])));
        $this->assertSame('paid', $this->financialState($f['financialId']));
    }

    /** And the `unpaid` direction on a genuinely paid row is untouched. */
    public function test_the_finance_surface_can_still_reverse_an_ordinary_settlement(): void
    {
        $f = $this->seedFixture();
        $editor = $this->financeEditor($f['owner']);

        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->assertSame(250.0, $this->walletBalance($f['walletId']), 'premise');

        $this->financeSurfaceMarks($editor, (int) $f['financialId'], 'unpaid');

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            'the finance surface can no longer reverse an ordinary settlement.');
        $this->assertSame('unpaid', $this->financialState($f['financialId']));
    }

    // =============================================================================================
    //  THE CASE GAP-257 CALLS MOST LIKELY, MEASURED RATHER THAN ASSUMED
    // =============================================================================================

    /**
     * GAP-257's CASE (1) IS NOT A DOUBLE CREDIT, AND SAYING SO IS PART OF THE ANSWER.
     *
     * The row reads: *"a RETURNED (3) order generally -- the financial row is moved to `refunded`
     * only by `OrderRefundController`, so a returned-but-not-yet-refunded order's `Paid` credits
     * the full `customer_total`"*. Driven: that is true and it is a RESTORED single credit, because
     * the move away from `paid` debited on the way out. The wallet nets to one credit, the ledger
     * holds one credit and one debit, and the financial row is `paid` -- which is a defensible
     * state for a return nobody has refunded yet.
     *
     * The harm the row names is real but it is ENTITLEMENT rather than arithmetic: the merchant is
     * credited while the goods are back. That is the `delivered_status`-aware rule its disposition
     * (a) proposes, and it is a money-rule change belonging with an owner decision -- so it is
     * measured here and left alone, with the figures recorded so the owner is deciding against
     * numbers rather than a description.
     *
     * This case is NOT inverted by the fix in this change and must not be: it asserts the present
     * behaviour of a path nobody has authorised changing.
     */
    public function test_measured_a_returned_order_restores_one_credit_rather_than_doubling_it(): void
    {
        $f = $this->seedFixture();

        $this->creditOnceThroughTheOrder($f['orderId']);
        $this->assertSame(250.0, $this->walletBalance($f['walletId']), 'premise');

        /* The order is returned, and its settlement is moved off Paid as part of that workflow. */
        DB::table('orders')->where('id', $f['orderId'])->update(['delivered_status' => 3]);
        $order = Order::withoutGlobalScopes()->findOrFail($f['orderId']);
        $order->payment_status = 'Unpaid';
        $order->save();

        $this->assertSame(0.0, $this->walletBalance($f['walletId']),
            'premise: the move off Paid debited, which is why the next credit is a RESTORED one');

        /* A later `Paid` -- the COD bag handed back, a correction, a late callback. */
        $order = Order::withoutGlobalScopes()->findOrFail($f['orderId']);
        $order->payment_status = 'Paid';
        $order->save();

        $this->assertSame(250.0, $this->walletBalance($f['walletId']),
            'MEASURED, NOT ASSERTED AS CORRECT: a returned order can be credited again, because '
            . 'nothing on the `syncFromOrder` path reads `delivered_status`. The arithmetic is a '
            . 'single net credit -- this is the figure, not an endorsement.');
        $this->assertSame(1, $this->creditCount($f['walletId']) - 1,
            'the ledger should hold exactly two credits and one debit for this sequence; it holds '
            . json_encode($this->ledger($f['walletId'])));
        $this->assertSame(3, (int) DB::table('orders')->where('id', $f['orderId'])->value('delivered_status'),
            'premise: the order really is RETURNED');
    }
}
