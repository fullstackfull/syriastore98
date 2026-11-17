<?php

namespace Tests\Feature\Commerce;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P4-F128 -- the three withdrawal surfaces now record WHO moved the money and WHEN.
 *
 * The owner's decision is authz + reason + auditable event. All three handlers already had the
 * authz, operator-first through the `finance` gate. None had an audit row, and `rejectWithdrawal`
 * -- which RETURNS MONEY to the merchant's wallet -- accepted a null note, so a payout could be
 * refused and the balance moved back with nothing recorded about why.
 *
 * WHAT IS DELIBERATELY NOT TESTED HERE, because it is deliberately not built: a required reason on
 * approve and pay. Those forms are a single button whose `onclick="return confirm(...)"` is pinned
 * by two ratchets, frozen because that guard is SYNCHRONOUS where the shared dialog handler is
 * not; and requiring one changes merchant-visible behaviour on the payout path, which CLAUDE.md
 * reserves for the owner. The gap is named in the F128 document rather than closed quietly.
 *
 * THE AUDIT ROWS SIT AFTER THE CATCH in all three, so only a transition that COMMITTED is
 * recorded. Each handler returns the facts OUT of its DB::transaction rather than re-reading the
 * row afterwards -- `use (...)` captures by value, and a post-commit re-read would report whatever
 * another request had since done to that row.
 */
class WithdrawalAccountabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $storeId = 1;

    private function operator(): User
    {
        $operator = User::factory()->create([
            'email' => 'w-operator@example.test',
            'type' => 'super admin',
        ]);

        // Must have CHOSEN this store, or every handler refuses at operatorHasChosenNoStore()
        // and the cases below would pass without reaching the code they are about.
        $operator->current_store = $this->storeId;
        $operator->save();

        return User::find($operator->id);
    }

    private function seedFixture(string $status = 'pending', float $amount = 50.0): array
    {
        $owner = User::factory()->create(['email' => 'w-owner@example.test', 'type' => 'admin']);

        DB::table('stores')->insert([
            'id' => $this->storeId, 'name' => 'W store', 'slug' => 'w-store',
            'email' => 'w@example.test', 'theme_id' => 'stylique',
            'created_by' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $walletId = DB::table('store_wallets')->insertGetId([
            'store_id' => $this->storeId, 'currency_code' => 'USD',
            'available_balance' => 100.0, 'pending_balance' => 0,
            'withdrawal_pending_balance' => $amount,
            'total_income' => 0, 'total_withdrawn' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $withdrawalId = DB::table('store_withdrawals')->insertGetId([
            'store_id' => $this->storeId, 'wallet_id' => $walletId,
            'amount' => $amount, 'status' => $status,
            'requested_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The request-time ledger row the handlers expect to find and move.
        DB::table('store_wallet_transactions')->insert([
            'store_id' => $this->storeId, 'wallet_id' => $walletId, 'currency_code' => 'USD',
            'type' => 'withdrawal_request', 'direction' => 'debit', 'amount' => $amount,
            'balance_before' => 100.0 + $amount, 'balance_after' => 100.0,
            'reference_type' => 'withdrawal', 'reference_id' => $withdrawalId,
            'description' => 'requested', 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('walletId', 'withdrawalId', 'amount');
    }

    private function rowsFor(string $action)
    {
        return AuditLog::where('action', $action)->get();
    }

    // ------------------------------------------------------------------ approve

    public function test_approving_a_withdrawal_writes_one_audit_row_naming_who_and_what(): void
    {
        $f = $this->seedFixture('pending');

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.approve', $f['withdrawalId']));

        $this->assertSame(
            'approved',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status'),
            'premise: the approval did not happen, so this case proves nothing'
        );

        $rows = $this->rowsFor('finance.withdrawal.approved');
        $this->assertCount(1, $rows);

        $row = $rows->first();
        $this->assertSame('pending', $row->old_values['status']);
        $this->assertSame('approved', $row->new_values['status']);
        $this->assertEqualsWithDelta($f['amount'], (float) $row->new_values['amount'], 0.001);
        $this->assertNotNull($row->user_id, 'the audit row does not name who approved it');
    }

    public function test_the_approval_audit_row_says_that_no_money_moved(): void
    {
        /*
         * Approval makes the amount PAYABLE; it writes no store_wallets column and inserts no
         * ledger row. Recording that explicitly matters because the opposite reading -- "approval
         * is missing its ledger row" -- is the natural one, and acting on it would fabricate a
         * movement that never happened.
         */
        $f = $this->seedFixture('pending');
        $before = DB::table('store_wallets')->where('id', $f['walletId'])->first();

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.approve', $f['withdrawalId']));

        $after = DB::table('store_wallets')->where('id', $f['walletId'])->first();

        $this->assertEqualsWithDelta((float) $before->available_balance, (float) $after->available_balance, 0.001);
        $this->assertEqualsWithDelta((float) $before->withdrawal_pending_balance, (float) $after->withdrawal_pending_balance, 0.001);
        $this->assertEqualsWithDelta((float) $before->total_withdrawn, (float) $after->total_withdrawn, 0.001);

        $this->assertFalse(
            $this->rowsFor('finance.withdrawal.approved')->first()->new_values['moved_money'],
            'the audit row claims approval moved money. It does not -- it makes the amount payable.'
        );
    }

    // ------------------------------------------------------------------ reject

    public function test_rejecting_without_a_reason_is_refused_and_returns_no_money(): void
    {
        $f = $this->seedFixture('pending');

        $response = $this->actingAs($this->operator())
            ->from(route('finance.withdrawals'))
            ->post(route('finance.withdrawals.reject', $f['withdrawalId']), []);

        $response->assertSessionHasErrors('admin_note');

        $this->assertSame(
            'pending',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status'),
            'the rejection went through with no reason recorded'
        );
        $this->assertEqualsWithDelta(
            100.0,
            (float) DB::table('store_wallets')->where('id', $f['walletId'])->value('available_balance'),
            0.001,
            'money moved back on a request that was supposed to be refused'
        );
    }

    public function test_rejecting_with_a_reason_returns_the_money_and_records_both_balances(): void
    {
        $f = $this->seedFixture('pending');

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.reject', $f['withdrawalId']), [
                'admin_note' => 'Bank details did not match the registered account',
            ]);

        $this->assertSame(
            'rejected',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status')
        );

        $row = $this->rowsFor('finance.withdrawal.rejected')->first();
        $this->assertNotNull($row, 'no audit row for a rejection that moved money back');

        $this->assertSame(
            'Bank details did not match the registered account',
            $row->new_values['reason'],
            'the stated reason is not in the audit row'
        );
        $this->assertEqualsWithDelta(100.0, (float) $row->old_values['available_balance'], 0.001);
        $this->assertEqualsWithDelta(150.0, (float) $row->new_values['available_balance'], 0.001);
        $this->assertEqualsWithDelta($f['amount'], (float) $row->new_values['amount_returned'], 0.001);
    }

    public function test_a_reason_that_is_not_valid_utf8_is_refused_before_the_money_moves(): void
    {
        // The same audit-evasion shape the F128 adjustment was shown to have by execution: the
        // note lands in a json-cast column, so malformed bytes would throw AFTER the money moved.
        $f = $this->seedFixture('pending');

        $response = $this->actingAs($this->operator())
            ->from(route('finance.withdrawals'))
            ->post(route('finance.withdrawals.reject', $f['withdrawalId']), [
                'admin_note' => "bad \xC3\x28 bytes",
            ]);

        $response->assertSessionHasErrors('admin_note');

        $this->assertSame(
            'pending',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status')
        );
        $this->assertCount(0, $this->rowsFor('finance.withdrawal.rejected'));
    }

    // ------------------------------------------------------------------ pay

    public function test_paying_a_withdrawal_records_the_money_that_actually_left(): void
    {
        $f = $this->seedFixture('approved');

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.pay', $f['withdrawalId']));

        $this->assertSame(
            'paid',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status'),
            'premise: the payout did not happen'
        );

        $row = $this->rowsFor('finance.withdrawal.paid')->first();
        $this->assertNotNull($row, 'the handler that pays money out wrote no audit row');

        $this->assertSame('approved', $row->old_values['status']);
        $this->assertSame('paid', $row->new_values['status']);
        $this->assertEqualsWithDelta($f['amount'], (float) $row->new_values['amount_paid_out'], 0.001);
        $this->assertEqualsWithDelta($f['amount'], (float) $row->new_values['total_withdrawn'], 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $row->new_values['withdrawal_pending_balance'], 0.001);
    }

    public function test_the_payout_audit_row_omits_available_balance_because_the_handler_never_touches_it(): void
    {
        // The amount was reserved out of available_balance when the withdrawal was REQUESTED.
        // Recording it here would imply a second deduction -- the same double-count that makes a
        // naive ledger reconstruction report phantom drift on every paid withdrawal.
        $f = $this->seedFixture('approved');
        $before = (float) DB::table('store_wallets')->where('id', $f['walletId'])->value('available_balance');

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.pay', $f['withdrawalId']));

        $this->assertEqualsWithDelta(
            $before,
            (float) DB::table('store_wallets')->where('id', $f['walletId'])->value('available_balance'),
            0.001,
            'payWithdrawal moved available_balance, which would double-count the reservation'
        );

        $this->assertArrayNotHasKey(
            'available_balance',
            $this->rowsFor('finance.withdrawal.paid')->first()->new_values
        );
    }

    // ------------------------------------------------------------------ the negative control

    public function test_a_failed_transition_writes_no_audit_row(): void
    {
        // Approving an already-approved withdrawal throws inside the transaction. An audit trail
        // that records attempts as movements lies in the direction that matters most.
        $f = $this->seedFixture('approved');

        $this->actingAs($this->operator())
            ->post(route('finance.withdrawals.approve', $f['withdrawalId']));

        $this->assertSame(
            'approved',
            DB::table('store_withdrawals')->where('id', $f['withdrawalId'])->value('status'),
            'premise: the status changed, so this was not the failure case'
        );
        $this->assertCount(0, $this->rowsFor('finance.withdrawal.approved'));
        $this->assertNotNull(session('error'), 'the failure was not reported to the caller');
    }
}
