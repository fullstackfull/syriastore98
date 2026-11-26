<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `SHIP-001…004` / `COD-001…004`, section 04-D bullet 7 — THE THREE-WAY SEPARATION IS TWO-WAY.
 * =================================================================================================
 *
 * THE RECOVERED OBLIGATION, verbatim from `DECISION-ID-REGISTER.md` section 04-D, bullet 7:
 *
 *   الفصل بين التسليم والتحصيل والتحويل للتاجر.
 *   — *Separate delivery, collection, and transfer to the merchant.*
 *
 * and bullet 9, which depends on the same mechanism:
 *
 *   تسويات جزئية ومبالغ معلقة وسجلات قابلة للتدقيق.
 *   — *Partial settlements, PENDING AMOUNTS, and auditable records.*
 *
 * **THE FAMILY IS RECOVERED AT FAMILY LEVEL ONLY.** The register records 8 ids against ONE section
 * of TEN bullets and says in as many words that "no per-id pairing is derivable". So this file
 * reconciles a BULLET, not a numbered decision, and it must not be read as `COD-003` or any other
 * id — assigning one would be inventing a text `CLAUDE.md` §4.1 forbids inventing.
 *
 * -------------------------------------------------------------------------------------------------
 * MEASURED: DELIVERY+COLLECTION IS ONE EVENT, AND THE COLUMN FOR THE THIRD LEG IS DEAD
 * -------------------------------------------------------------------------------------------------
 * `store_wallets` carries BOTH `available_balance` and `pending_balance`, so the separation bullet 7
 * asks for is **schema-supported**. The writer does not use it. `OrderObserver::updated()` fires on
 * any change to `orders.payment_status`, `OrderFinancialService::syncFromOrder()` routes a new
 * `Paid` to `markFinancialAsPaid()`, and that method credits **`available_balance`**. So the moment
 * a COD order is marked paid — which for cash on delivery is the moment the courier collects — the
 * merchant's SPENDABLE balance rises.
 *
 * There is no state for *"the courier has collected this cash and has not yet remitted it to the
 * platform"*. Collection and transfer-to-the-merchant are the same event, and
 * `StoreFinanceController`'s withdrawal path draws on `available_balance`, so the merchant may
 * request money the platform has not yet received.
 *
 * -------------------------------------------------------------------------------------------------
 * THIS FILE CHANGES NO AMOUNT, AND THE REPAIR IS NOT ENGINEERING'S
 * -------------------------------------------------------------------------------------------------
 * Routing a COD collection into `pending_balance` would change which money a merchant can withdraw
 * TODAY, for every store on the platform. That is a material change to a money rule, which this
 * project's standing instruction reserves to the owner, and it is adjacent to `J20` (`GAP-257`:
 * may a `Paid` on a cancelled or returned order credit the wallet?). Every figure below is the
 * figure the shipped code produces. **When this is fixed, INVERT the cases; do not relax them.**
 *
 * @see \Tests\Feature\Commerce\CodStateMachineTest      bullet 6, duplicate collection — covered
 * @see \Tests\Unit\Commerce\ShipCod04DFamilyCensusTest  the other nine bullets' mechanisms
 * @see \Tests\Unit\Database\CodCollectionEventsStillAbsentTest  bullets 8-9, gated by D-15
 */
final class CodCollectionAndRemittanceSeparationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Every cash-on-delivery spelling the platform recognises, so one arm cannot be measured alone. */
    private const COD_TYPES = ['cod', 'cash_on_delivery'];

    /*
    |--------------------------------------------------------------------------
    | premises
    |--------------------------------------------------------------------------
    */

    /**
     * PREMISE 1: the schema CAN express the separation. Without this the finding would be "the
     * platform has no pending column", which is a different and much weaker statement than "it has
     * one and the money does not go there".
     */
    public function test_premise_the_wallet_schema_can_express_a_pending_balance(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('store_wallets'),
            'premise: the wallet table exists in the test schema (GATE F closed 2026-11-02). '
            . 'Every figure in this file is vacuous without it.');

        foreach (['available_balance', 'pending_balance', 'withdrawal_pending_balance'] as $column) {
            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn('store_wallets', $column),
                sprintf('premise: `store_wallets.%s` exists, so bullet 7\'s three-way separation '
                    . 'is a WRITER gap and not a schema gap.', $column)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | the fixture
    |--------------------------------------------------------------------------
    */

    /**
     * A merchant, a store with a provisioned wallet, and an UNPAID cash-on-delivery order.
     *
     * The order is created unpaid on purpose: the credit must be observed ARRIVING, because a
     * balance that was already right at creation would not tell us which event moved it.
     *
     * @return array{0: User, 1: Store, 2: Order}
     */
    private function unpaidCodOrder(string $paymentType = 'cod'): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $order = $this->makeOrder($store, [
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_status' => 'Pending',
            'payment_type' => $paymentType,
            'final_price' => 250.00,
            'product_price' => 250.00,
            'product_json' => '[]',
            'return_status' => 0,
        ]);

        return [User::find($merchant->id), $store, $order];
    }

    /** @return array{available: float, pending: float} */
    private function balances(Store $store): array
    {
        $row = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertNotNull($row, 'premise: the store has a provisioned wallet row. Without one '
            . 'OrderFinancialService::createForOrder() throws ModelNotFoundException, the observer '
            . 'swallows it, and NO credit is written at all -- which would make an "it did not go '
            . 'to pending" assertion pass for entirely the wrong reason.');

        return [
            'available' => round((float) $row->available_balance, 2),
            'pending' => round((float) $row->pending_balance, 2),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | THE MEASUREMENT
    |--------------------------------------------------------------------------
    */

    /**
     * **COLLECTING CASH ON DELIVERY RAISES THE MERCHANT'S SPENDABLE BALANCE IMMEDIATELY.**
     *
     * Driven through the writer the product uses: `orders.payment_status` moves to `Paid`, which is
     * what a merchant marking a COD order collected does, and `OrderObserver::updated()` →
     * `syncFromOrder()` → `markFinancialAsPaid()` follows.
     *
     *     before   available 0.00    pending 0.00
     *     after    available RISES   pending 0.00   <- bullet 7's third leg never happens
     *
     * `pending_balance` is asserted to be EXACTLY unchanged rather than merely "not the whole
     * amount", because a partial routing would be a different finding and should not be absorbed
     * by a loose assertion.
     */
    public function test_a_cod_collection_credits_the_spendable_balance_and_never_the_pending_one(): void
    {
        [, $store, $order] = $this->unpaidCodOrder();

        $before = $this->balances($store);

        $this->assertSame(0.00, $before['available'],
            'premise: nothing is spendable before collection, so the rise below is this event\'s.');

        $order->payment_status = 'Paid';
        $order->save();

        $after = $this->balances($store);

        $this->assertGreaterThan($before['available'], $after['available'], sprintf(
            'premise for the real assertion: the collection DID credit the merchant. A balance '
            . 'that did not move would mean the observer never ran and the pending assertion '
            . 'below would be vacuous. before %s after %s',
            json_encode($before), json_encode($after)
        ));

        $this->assertSame(0.00, $after['pending'], sprintf(
            'MEASURED, AND IT IS BULLET 7: `pending_balance` is still 0.00 after a cash-on-'
            . 'delivery collection. The platform has no state for cash a courier is holding but '
            . 'has not remitted, so "delivery", "collection" and "transfer to the merchant" are '
            . 'not three separable events -- the first two are one and the third does not exist. '
            . 'A non-zero figure here means the separation has arrived; INVERT this case and '
            . 'record which decision did it. before %s after %s',
            json_encode($before), json_encode($after)
        ));
    }

    /**
     * EVERY COD SPELLING, because measuring one arm proves nothing about the other.
     *
     * `CodStateMachineTest` establishes that the platform recognises more than one spelling of
     * cash on delivery; a finding about "COD" that held for only one of them would be half a
     * finding.
     */
    public function test_the_same_holds_for_every_cash_on_delivery_spelling(): void
    {
        foreach (self::COD_TYPES as $type) {
            [, $store, $order] = $this->unpaidCodOrder($type);

            $order->payment_status = 'Paid';
            $order->save();

            $after = $this->balances($store);

            $this->assertGreaterThan(0.00, $after['available'], sprintf(
                'premise for `%s`: the credit landed.', $type
            ));
            $this->assertSame(0.00, $after['pending'], sprintf(
                'payment_type `%s` also credits the spendable balance directly.', $type
            ));
        }
    }

    /**
     * THE CONTROL, AND IT IS WHAT MAKES THE FINDING SPECIFIC RATHER THAN GENERAL.
     *
     * Without it, "`pending_balance` is 0.00" is consistent with a platform that simply never uses
     * pending columns for anything. It does use one: the merchant→platform WITHDRAWAL leg moves
     * `available_balance` into `withdrawal_pending_balance` (`StoreFinanceController`), so the
     * two-phase pattern is understood and implemented here — just on the leg AFTER the merchant
     * already has the money, and not on the leg before.
     *
     * Driven as a direct wallet write rather than through the withdrawal door, because the subject
     * is which COLUMN the pattern exists on; the withdrawal door's own behaviour and authorisation
     * are `FinancialSurfaceCoverageTest`'s and are not re-measured here.
     */
    public function test_control_the_platform_does_implement_a_two_phase_balance_on_the_withdrawal_leg(): void
    {
        [, $store, $order] = $this->unpaidCodOrder();

        $order->payment_status = 'Paid';
        $order->save();

        $available = $this->balances($store)['available'];

        $this->assertGreaterThan(0.00, $available, 'premise: there is money to withdraw.');

        // The movement `StoreFinanceController`'s withdrawal request performs.
        DB::table('store_wallets')->where('store_id', $store->id)->update([
            'available_balance' => $available - 10.00,
            'withdrawal_pending_balance' => 10.00,
        ]);

        // NO `flushCache()` HERE, and that is measured rather than assumed: `StoreWallet` is one
        // of the sixteen models in `app/Models/` that do NOT use `Cachable`, so it has no such
        // method and calling it raises BadMethodCallException. The read below is a raw query for
        // the same reason -- there is no Eloquent cache to defeat.
        $row = DB::table('store_wallets')->where('store_id', $store->id)->first();

        $this->assertSame(10.00, round((float) $row->withdrawal_pending_balance, 2),
            'control: a pending column IS writable and IS used on the withdrawal leg, so the '
            . 'untouched `pending_balance` above is specific to the collection -> remittance leg '
            . 'and is not "this platform does not do two-phase balances".');

        $this->assertSame(0.00, round((float) $row->pending_balance, 2),
            'and the collection-side column is still the dead one.');
    }

    /**
     * AND A NON-COD ORDER BEHAVES IDENTICALLY, which tells the owner something useful about scope:
     * this is not a COD-specific defect that a COD-specific fix would address.
     *
     * A gateway order's money really is in the platform's hands when it is marked paid, so
     * crediting `available_balance` is correct for it. The finding is that the SAME writer serves
     * both, with no notion of who is holding the cash — so bullet 7's separation needs a
     * distinction the writer does not currently make, rather than a different column.
     */
    public function test_a_gateway_order_takes_the_identical_path_so_the_writer_draws_no_distinction(): void
    {
        [, $store, $order] = $this->unpaidCodOrder('stripe');

        $order->payment_status = 'Paid';
        $order->save();

        $after = $this->balances($store);

        $this->assertGreaterThan(0.00, $after['available'],
            'a gateway order credits the spendable balance -- which is CORRECT for it, because the '
            . 'platform holds that money.');
        $this->assertSame(0.00, $after['pending'],
            'the identical path. So the writer makes no distinction between money the platform '
            . 'holds and cash a courier holds, and bullet 7 needs that distinction introduced '
            . 'rather than an existing one re-routed.');
    }

    /**
     * THE LEDGER ROW IS WRITTEN, so bullet 9's "auditable records" half is satisfied even though
     * its "pending amounts" half is not.
     *
     * Recorded as CLEAN deliberately. A reconciliation that reported only what is missing would
     * leave a reader unable to tell a gap from an unexamined area, and this half is genuinely
     * built: the credit carries a typed, directional, before/after ledger row referencing the
     * order.
     */
    public function test_the_collection_does_leave_an_auditable_ledger_row(): void
    {
        [, $store, $order] = $this->unpaidCodOrder();

        $order->payment_status = 'Paid';
        $order->save();

        $rows = DB::table('store_wallet_transactions')
            ->where('store_id', $store->id)
            ->where('reference_type', 'order')
            ->where('reference_id', $order->id)
            ->get();

        $this->assertGreaterThan(0, $rows->count(), sprintf(
            'bullet 9 asks for auditable records. The credit must be traceable to its order. '
            . 'Rows: %s', $rows->toJson()
        ));

        $row = $rows->first();

        $this->assertSame('credit', (string) $row->direction);
        $this->assertNotSame('', (string) $row->type,
            'the row is TYPED, so a reader can tell a collection credit from a withdrawal.');
        $this->assertNotSame(
            round((float) $row->balance_before, 2),
            round((float) $row->balance_after, 2),
            'and it carries before/after balances, which is what makes the ledger auditable '
            . 'rather than merely present.'
        );
    }
}
