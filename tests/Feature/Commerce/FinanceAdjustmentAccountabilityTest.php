<?php

namespace Tests\Feature\Commerce;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P4-F128 -- "finance-screen adjustment needs authz + reason + auditable event."
 *
 * The authz half already held: every finance handler goes through the `finance` gate, which tests
 * the platform operator FIRST and only then calls isAbleTo() -- the ordering this codebase requires
 * because Laratrust's isAbleTo() alone REFUSES the operator. The other two halves did not exist.
 *
 * This file is about the two that did not. The reason is required rather than optional, and the
 * audit row is written only after the money actually moved -- both of those are choices that a test
 * has to pin, because the failure mode of each is silence.
 */
class FinanceAdjustmentAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId = 1;

    /**
     * An operator who has EXPLICITLY CHOSEN this store.
     *
     * Without the choice the handler refuses at operatorHasChosenNoStore() and never reaches the
     * adjustment -- which would make every case below pass for the wrong reason, by never
     * exercising the code they are about. Eloquent rather than DB::table(): `User` is Cachable and
     * getCurrentStore() calls User::find(), so a raw write would serve the pre-write row.
     */
    private function operator(): User
    {
        $operator = User::factory()->create([
            'email' => 'operator@example.test',
            'type' => 'super admin',
        ]);

        $operator->current_store = $this->storeId;
        $operator->save();

        return User::find($operator->id);
    }

    private function seedFixture(): array
    {
        $owner = User::factory()->create(['email' => 'owner@example.test', 'type' => 'admin']);

        DB::table('stores')->insert([
            'id' => $this->storeId,
            'name' => 'F128 store',
            'slug' => 'f128-store',
            'email' => 'f128@example.test',
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
            'product_order_id' => 'ORD-F128', 'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 250.0,
            'payment_type' => 'cod', 'payment_status' => 'Unpaid',
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

        return compact('walletId', 'orderId', 'financialId');
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        $f = $this->seedFixture();

        $response = $this->actingAs($this->operator())
            ->from(route('finance.index'))
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
            ]);

        $response->assertSessionHasErrors('reason');

        $this->assertSame(
            'unpaid',
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status'),
            'The adjustment went through without a reason.'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) DB::table('store_wallets')->where('id', $f['walletId'])->value('available_balance'),
            0.001,
            'Money moved on a request that was supposed to be refused.'
        );
    }

    public function test_an_adjustment_with_a_reason_moves_the_money_and_writes_one_audit_row(): void
    {
        $f = $this->seedFixture();

        $this->actingAs($this->operator())
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
                'reason' => 'Cash collected at the counter on 2026-11-16',
            ]);

        $this->assertSame(
            'paid',
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status')
        );

        $rows = AuditLog::where('action', 'finance.financial_payment_status.updated')->get();
        $this->assertCount(1, $rows, 'Expected exactly one audit row for one adjustment.');

        $row = $rows->first();
        $this->assertSame('unpaid', $row->old_values['entitlement_state']);
        $this->assertSame('paid', $row->new_values['entitlement_state']);
        $this->assertSame('Cash collected at the counter on 2026-11-16', $row->new_values['reason']);
        $this->assertNotNull($row->user_id, 'The audit row does not name who did it.');
    }

    public function test_the_audit_row_records_the_settlement_this_adjustment_did_not_change(): void
    {
        // The point of carrying the order's own status into the audit row: the divergence this
        // adjustment CREATES is visible from the audit trail alone, at the moment it is created,
        // without waiting for the reconciliation report to find it.
        $f = $this->seedFixture();

        $this->actingAs($this->operator())
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
                'reason' => 'Bank transfer received out of band',
            ]);

        $row = AuditLog::where('action', 'finance.financial_payment_status.updated')->first();

        $this->assertSame(
            'Unpaid',
            $row->new_values['settlement_unchanged'],
            'The audit row does not record the customer settlement, so a reader cannot see that '
            . 'this adjustment created a divergence.'
        );
        $this->assertSame(
            'Unpaid',
            DB::table('orders')->where('id', $f['orderId'])->value('payment_status'),
            'The handler moved the customer settlement. P4-F128 keeps the two distinct.'
        );
    }

    public function test_a_failed_adjustment_writes_no_audit_row(): void
    {
        // An audit trail that records attempts as though they were movements lies in the direction
        // that matters most. The row is written after the try/catch for this reason.
        $f = $this->seedFixture();

        // The service is made to throw directly. Removing its wallet was the first attempt and the
        // schema refused it -- store_order_financials.wallet_id is NOT NULL and FK-constrained,
        // which is the constraint doing its job. Driving the exception at the service boundary
        // tests exactly the property this case is about (the audit row sits AFTER the catch) and
        // nothing else.
        $this->mock(\App\Services\OrderFinancialService::class, function ($mock) {
            $mock->shouldReceive('markFinancialAsPaid')
                ->andThrow(new \RuntimeException('ledger unavailable'));
        });

        $this->actingAs($this->operator())
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
                'reason' => 'This one is going to fail',
            ]);

        $this->assertSame(
            'unpaid',
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status'),
            'premise: the adjustment was supposed to fail, and it did not -- this case proves nothing'
        );
        $this->assertCount(
            0,
            AuditLog::where('action', 'finance.financial_payment_status.updated')->get(),
            'A failed adjustment was recorded as though money had moved.'
        );

        // Without this, deleting the handler's catch would let the exception propagate, the audit
        // line would never run, and 0 rows would still satisfy the assertion above -- the case
        // would pass while testing nothing. Found by independent review.
        $this->assertNotNull(
            session('error'),
            'The failure was not reported to the caller, so this case would also pass if the '
            . "handler's catch were deleted entirely."
        );
    }

    public function test_a_reason_that_is_not_valid_utf8_cannot_move_money_without_an_audit_row(): void
    {
        /*
         * AUDIT EVASION, found by independent review. The `reason` goes straight into a json-cast
         * column OUTSIDE the try/catch, and the money commits inside the service's OWN transaction
         * before that line runs. So a reason carrying a malformed UTF-8 byte sequence makes
         * json_encode() return false, Eloquent throws JsonEncodingException, and the result is:
         * money moved, NO audit row, HTTP 500.
         *
         * The principal who benefits is the one the accountability slice exists to hold to account,
         * which is what makes it worth a test rather than a note. `string` is is_string() and `max`
         * is mb_strlen -- neither rejects malformed bytes -- and this route carries no `xss`
         * middleware, so nothing normalises the input on the way in.
         */
        $f = $this->seedFixture();

        $response = $this->actingAs($this->operator())
            ->from(route('finance.index'))
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
                'reason' => "bad \xC3\x28 bytes",
            ]);

        $response->assertSessionHasErrors('reason');

        $this->assertSame(
            'unpaid',
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status'),
            'Money moved on a reason that cannot be stored, so the audit row could never be written.'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) DB::table('store_wallets')->where('id', $f['walletId'])->value('available_balance'),
            0.001
        );
        $this->assertCount(
            0,
            AuditLog::where('action', 'finance.financial_payment_status.updated')->get()
        );
    }

    public function test_the_audit_read_cannot_reach_another_tenants_order(): void
    {
        /*
         * THE ASSERTION THAT PINS THE ONE TENANCY-RELEVANT LINE IN THIS CHANGE.
         *
         * Found by independent review: the sibling case asserting `settlement_unchanged` passes
         * whether or not `->where('store_id', $storeId)` is present, because its fixture has ONE
         * store, so the scoped and unscoped reads return the same row. The predicate it was
         * written to protect was unpinned -- a test passing for the wrong reason, which is the
         * failure class this project keeps finding.
         *
         * This builds the D4 shape instead: a financial row belonging to store A whose `order_id`
         * names an order belonging to store B. That is exactly what leg 1 of the reconciliation
         * exists to report, and it is the only state in which the two reads differ. Unscoped, the
         * handler would copy STORE B's settlement into STORE A's audit log.
         */
        $f = $this->seedFixture();

        $otherOwner = User::factory()->create(['email' => 'other@example.test', 'type' => 'admin']);
        DB::table('stores')->insert([
            'id' => 2, 'name' => 'Other store', 'slug' => 'other-store',
            'email' => 'other-store@example.test', 'theme_id' => 'stylique',
            'created_by' => $otherOwner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $foreignOrderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-OTHER', 'product_json' => json_encode([]),
            'product_price' => 0.0, 'delivery_price' => 0.0, 'tax_price' => 0.0,
            'coupon_price' => 0.0, 'final_price' => 999.0,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',          // DIFFERENT from store 1's order, which is Unpaid
            'store_id' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // store 1's financial row now names store 2's order -- the control failure leg 1 reports
        DB::table('store_order_financials')
            ->where('id', $f['financialId'])
            ->update(['order_id' => $foreignOrderId]);

        $this->actingAs($this->operator())
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'paid',
                'reason' => 'Adjusting a row that names another tenants order',
            ]);

        $row = AuditLog::where('action', 'finance.financial_payment_status.updated')->first();
        $this->assertNotNull($row, 'premise: the adjustment did not happen, so this proves nothing');

        $this->assertNull(
            $row->new_values['settlement_unchanged'],
            "The audit read reached ANOTHER TENANT'S order. Store 2's settlement is 'Paid' and "
            . "store 1's is 'Unpaid', so an unscoped read returns 'Paid' here. Null is the honest "
            . 'answer: the order this row names is not ours.'
        );
    }

    public function test_a_no_op_adjustment_records_the_state_that_resulted_not_the_one_requested(): void
    {
        /*
         * markFinancialAsUnpaid writes NOTHING unless the row is exactly 'paid'. So asking for
         * 'unpaid' on a `cancelled` row is a complete no-op -- and recording the REQUESTED state
         * would sign a reasoned audit entry describing a movement that did not happen.
         *
         * This is ordinary use, not a crafted case. P4-F124 writes `cancelled` on every
         * cancellation, and the screen's select offers only unpaid/paid, so on a cancelled row
         * NEITHER option carries `selected` and a browser submits the first one -- 'unpaid'. A
         * merchant clicking Update on a cancelled row lands here.
         */
        $f = $this->seedFixture();
        DB::table('store_order_financials')
            ->where('id', $f['financialId'])
            ->update(['payment_status' => 'cancelled']);

        $this->actingAs($this->operator())
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => 'unpaid',
                'reason' => 'Clicked Update on a cancelled row',
            ]);

        $this->assertSame(
            'cancelled',
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status'),
            'premise: the service was supposed to no-op on a terminal row and it did not'
        );

        $row = AuditLog::where('action', 'finance.financial_payment_status.updated')->first();
        $this->assertNotNull($row);

        $this->assertSame(
            'cancelled',
            $row->new_values['entitlement_state'],
            'The audit row claims the entitlement moved to the requested state. It did not move at '
            . 'all -- the row is still cancelled, and the audit trail must say so.'
        );
        $this->assertSame(
            'unpaid',
            $row->new_values['requested_state'],
            'What was asked for is still recorded, beside what resulted.'
        );
    }
}
