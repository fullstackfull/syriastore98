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

    /**
     * P5-R4 -- A PRINCIPAL THE `<select>` ACTUALLY RENDERS FOR.
     *
     * Every other case in this file drives `operator()`, and the view gates the control on a BARE
     * `isAbleTo('Edit Finance Payment Status')`, which in this codebase REFUSES the operator -- so
     * those cases all measure the read-only BADGE. The `<option>` half is the half that decided
     * what a click SUBMITTED, so it needs a principal who sees it: the store's own owner, holding
     * the permission through a Laratrust role, which is how a merchant really holds it.
     */
    private function merchantWhoMayEditTheStatus(): User
    {
        $merchant = User::find(DB::table('stores')->where('id', $this->storeId)->value('created_by'));

        $role = \App\Models\Role::create([
            'name' => 'finance-editor', 'display_name' => 'Finance editor',
            'created_by' => $merchant->id, 'store_id' => $this->storeId,
        ]);

        /*
         * BOTH, and the second one is the lesson. `finance.index` carries
         * `finance.permission:View Finance Dashboard`, so a principal holding only the edit
         * permission is refused the PAGE and served a 403 -- a 6.6KB error document in which every
         * assertion about options passes or fails for the wrong reason. Measured, not assumed: the
         * first run of this case got exactly that page.
         */
        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $role->givePermission(\App\Models\Permission::firstOrCreate(
                ['name' => $name], ['display_name' => $name],
            ));
        }

        $merchant->addRole($role);
        $merchant->current_store = $this->storeId;
        $merchant->save();
        $merchant->flushCache();

        $merchant = User::find($merchant->id);

        foreach (['View Finance Dashboard', 'Edit Finance Payment Status'] as $name) {
            $this->assertTrue($merchant->isAbleTo($name),
                "PREMISE: this principal does not hold '{$name}'. Without the first the page is a "
                . '403 error document; without the second the <select> is never rendered and the '
                . 'assertions below would measure the read-only badge.');
        }

        return $merchant;
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

    // ---------------------------------------------------------------- P5-1: the screen must SHOW a reversed row

    /** @dataProvider engineWrittenEntitlementStates */
    public function test_an_engine_written_row_shows_its_real_state_on_the_finance_screen(string $state, string $label): void
    {
        /*
         * P5-1. F124 and F127 write `cancelled` and `refunded` into
         * store_order_financials.payment_status. The screen offered only unpaid/paid, so on such a
         * row NEITHER option carried `selected`: the browser rendered the first one and the
         * merchant was told a REFUNDED order was "Not Received" -- the opposite of what happened.
         * The read-only badge did the same for anyone without the edit permission.
         *
         * This asserts the state is now rendered. It does NOT assert the control is removed: the
         * paid/unpaid options stay selectable, because syncFromOrder's docblock records the
         * finance surface as the authorised escape hatch out of a reversal.
         */
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])->update(['payment_status' => $state]);

        $response = $this->actingAs($this->operator())->get(route('finance.index'));

        $response->assertOk();
        $response->assertSee($label);

        /*
         * AND THE ROW MUST NOT STILL READ "Not Received" -- which is the assertion that actually
         * bites. `assertSee($label)` alone is NOT sufficient and this was caught by falsifying it:
         * the word "Cancelled" also appears in the transaction-status FILTER on this page, so the
         * cancelled case stayed green with the badge removed. The fixture seeds exactly one
         * financial row, so the misleading label must be absent from the page entirely.
         */
        $response->assertDontSee(__('Not Received'));
    }

    /**
     * P5-R4 / GAP-149 -- FOUR, AND THE NAME CHANGED WITH THEM.
     *
     * This provider was `terminalEntitlementStates` and held the two REVERSALS. The screen could
     * represent only those two plus `paid`, while the column's production enum holds SIX and
     * `OrderFinancialService::normalizePaymentStatus()` writes every one: a gateway's 'pending' or
     * 'processing' becomes `pending`, its 'partial' becomes `partially_paid`.
     *
     * `pending` and `partially_paid` are NOT terminal, which is why the name had to change rather
     * than the list quietly growing under it. What the four share is that the ENGINE writes them
     * and a merchant may not type them -- and that is the property both this test and
     * `test_an_engine_written_state_cannot_be_chosen_from_the_dropdown` actually turn on.
     */
    public static function engineWrittenEntitlementStates(): array
    {
        return [
            'cancelled' => ['cancelled', 'Cancelled'],
            'refunded' => ['refunded', 'Refunded'],
            'pending' => ['pending', 'Pending'],
            'partially_paid' => ['partially_paid', 'Partially Paid'],
        ];
    }

    /** @dataProvider engineWrittenEntitlementStates */
    public function test_an_engine_written_state_cannot_be_chosen_from_the_dropdown(string $state): void
    {
        /*
         * Showing the state must not make it settable. `cancelled` and `refunded` are written by
         * OrderEntitlementReversal when a reversal really happens; typing one here would fabricate
         * one. The option is rendered `disabled`, and the handler's validator refuses the value
         * independently -- two bounds, because the markup one is only as good as the browser.
         *
         * P5-R4 -- AND THE SAME HOLDS FOR THE OTHER TWO, FOR A DIFFERENT REASON WORTH STATING.
         * `pending` and `partially_paid` come from the gateway normaliser, and typing
         * `partially_paid` would assert a partial capture whose AMOUNT the application was never
         * told: `OrderFinancialService` leaves `paid_amount` at zero for such a row precisely
         * because no amount is available, and `CollectedCustomerPayments` only counts the row once
         * that amount is above zero. A merchant choosing the word would move the badge without
         * moving a figure. `in:paid,unpaid` was already narrow enough to refuse all four, so this
         * case widens the EVIDENCE rather than the validator.
         */
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])->update(['payment_status' => $state]);

        /*
         * THE MARKUP HALF IS NOT ASSERTED HERE, and the reason is a pre-existing defect worth
         * naming rather than working around. The view gates the <select> on a BARE
         * `auth()->user()->isAbleTo('Edit Finance Payment Status')`, which in this codebase
         * REFUSES the platform operator -- so this principal is served the read-only badge and
         * never sees the control at all, while the backend admits them to the endpoint. It fails
         * closed, so it is not a hole; but it means a markup assertion driven by an operator would
         * measure the badge and pass for the wrong reason.
         *
         * Asserting the SERVER-SIDE refusal instead is the stronger half in any case: the
         * `disabled` attribute is only as good as the browser honouring it.
         */
        $this->actingAs($this->operator())
            ->from(route('finance.index'))
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'payment_status' => $state,
                'reason' => 'trying to set an engine-written state by hand',
            ])
            ->assertSessionHasErrors('payment_status');

        $this->assertSame(
            $state,
            DB::table('store_order_financials')->where('id', $f['financialId'])->value('payment_status'),
            'premise: the row was not left in the engine-written state this case is about'
        );
    }

    /**
     * P5-R4 / GAP-149 -- THE EDITABLE CELL, WHICH IS WHERE THE DEFECT CHANGED WHAT WAS SUBMITTED.
     *
     * The badge cases above prove the merchant READS the right state. This proves the CONTROL
     * shows it: on a `pending` or `partially_paid` row neither `<option>` carried `selected`, so
     * the browser rendered the first one -- "Not Received" -- as the row's current state, and any
     * click posted `unpaid`.
     *
     * EVERY ASSERTION IS SCOPED TO THE ROW'S OWN `<select>`, because this page carries a
     * transaction-status FILTER whose options include `cancelled`, `completed` and the rest.
     * Measured: a whole-page `assertStringContainsString('<option value="cancelled" >')` is
     * satisfied by that filter, which is CLAUDE.md's "scope the search to the block too" in its
     * exact form -- the same mistake that let a 44px target survive because another component
     * carried the same rule.
     *
     * @dataProvider engineWrittenEntitlementStates
     */
    public function test_the_editable_cell_preselects_the_engine_written_state(string $state, string $label): void
    {
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])->update(['payment_status' => $state]);

        $select = $this->theRowsPaymentStatusSelect(
            (string) $this->actingAs($this->merchantWhoMayEditTheStatus())
                ->get(route('finance.index'))
                ->getContent()
        );

        $this->assertStringContainsString(
            '<option value="' . $state . '" selected disabled>',
            $select,
            "a `{$state}` row renders no selected+disabled option, so the browser falls back to "
            . 'the first option and shows the merchant a state the row is not in'
        );

        $this->assertStringContainsString($label, $select,
            "the option for `{$state}` carries no readable label");

        /*
         * AND THE TWO SELECTABLE OPTIONS MUST STILL BE THERE, NEITHER SELECTED. Removing them
         * would turn a correct label into a dead end: `syncFromOrder`'s docblock records this
         * surface as the authorised way out of a reversal, so paid/unpaid stay choosable.
         */
        foreach (['unpaid', 'paid'] as $selectable) {
            $this->assertStringContainsString('<option value="' . $selectable . '" >', $select,
                "the {$selectable} option is gone from the row's select, or carries `selected` on "
                . 'a row that is not in that state');
        }
    }

    /**
     * The one `<select name="payment_status">` the seeded row renders, with its options.
     *
     * The fixture seeds exactly one financial row, so exactly one of these exists; the premise
     * asserts that rather than trusting it, because a second row would make every assertion above
     * a statement about whichever one came first.
     */
    private function theRowsPaymentStatusSelect(string $html): string
    {
        $this->assertSame(1, preg_match_all('/<select name="payment_status".*?<\/select>/s', $html, $m),
            'PREMISE: the page does not carry exactly one per-row payment_status select. Either the '
            . 'control is gone (this principal was refused and is reading the badge, or the page is '
            . 'a 403) or the fixture seeded more than one row.');

        return $m[0][0];
    }

    /**
     * P5-R4 ROUND 2 -- A REFUSED STATE MUST BE VISIBLE, found by the independent review.
     *
     * A `<option ... selected disabled>` submits NO value. So on any engine-written row a merchant
     * who presses Update without first choosing paid or unpaid sends no `payment_status`,
     * `required|in:paid,unpaid` refuses it, and the handler redirects back. The cell had an error
     * surface for `reason` ONLY, so that refusal rendered NOTHING: the page reloaded, the row was
     * unchanged, and the merchant was given no reason.
     *
     * The shape predates this slice -- P5-1 introduced the selected+disabled option for the two
     * reversals -- but widening the control to `pending` made it reachable on an ordinary
     * uncollected-COD row, which turns a rare dead end into a routine one.
     *
     * TWO ASSERTIONS, BECAUSE THE FIRST ONE ALONE WAS ALREADY TRUE BEFORE THE FIX. The session
     * error bag was always populated; what was missing is anything that PRINTS it.
     */
    public function test_a_refused_payment_status_is_visible_to_the_merchant(): void
    {
        $f = $this->seedFixture();
        DB::table('store_order_financials')->where('id', $f['financialId'])->update(['payment_status' => 'pending']);

        $merchant = $this->merchantWhoMayEditTheStatus();

        $this->actingAs($merchant)
            ->from(route('finance.index'))
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'reason' => 'pressed Update without choosing a state',
            ])
            ->assertSessionHasErrors('payment_status');

        /*
         * FOLLOWING THE REDIRECT, not issuing a second request. A flashed error bag does not
         * survive a fresh `get()` in a Feature test -- measured: `session('errors')` came back
         * null and the first version of this case failed on its own premise. `followingRedirects()`
         * renders the page WITH the bag, which is what the merchant actually sees.
         *
         * The expected text is COMPUTED the way the framework computes it, not pasted, so this
         * case does not break when the suite locale changes.
         */
        $expected = __('validation.required', ['attribute' => 'payment status']);

        $this->assertNotSame('validation.required', $expected,
            'PREMISE: the validation language file did not resolve, so the string below would be a '
            . 'translation key and the assertion would pass or fail for the wrong reason.');

        $html = (string) $this->actingAs($merchant)
            ->from(route('finance.index'))
            ->followingRedirects()
            ->post(route('finance.order-financial.status', $f['financialId']), [
                'reason' => 'pressed Update without choosing a state',
            ])
            ->getContent();

        $this->assertStringContainsString($expected, $html,
            'The refusal is invisible: the merchant pressed Update, the page came back unchanged, '
            . 'and nothing on it says why. The cell needs an @error(\'payment_status\') surface '
            . 'beside the one it already had for `reason`.');
    }

    /**
     * WHY THERE IS NO CASE HERE FOR AN UNRECOGNISED STATE, and this is a finding rather than a gap.
     *
     * `EntitlementStateLabel` has an unknown-value branch and the view guards the disabled option
     * with `isKnown()`, so the obvious next case is a row holding something like `chargeback`. IT
     * CANNOT BE WRITTEN: `2026_..._create_store_order_financials_table` declares the column as
     * `$table->enum('payment_status', [...six...])`, which SQLite enforces as a CHECK constraint
     * and MySQL as the enum itself. The attempt fails with
     *
     *     SQLSTATE[23000]: CHECK constraint failed: payment_status
     *
     * at the fixture, not at the assertion -- measured, which is how this comment exists. So the
     * unknown branch is a second bound behind one the DATABASE already holds, and the only way to
     * reach it is a schema change. It is covered where it can be: directly, in
     * `tests/Unit/Finance/EntitlementStateLabelTest`, which needs no row.
     */
    public function test_the_column_itself_refuses_a_state_outside_the_six(): void
    {
        $f = $this->seedFixture();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('store_order_financials')
            ->where('id', $f['financialId'])
            ->update(['payment_status' => 'chargeback']);
    }
}
