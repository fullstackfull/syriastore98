<?php

namespace Tests\Unit\Loyalty;

use App\Models\LoyaltyPointTransaction;
use App\Support\Loyalty\LoyaltyLedger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * `MKT-005` — «سجل حركات», THE TRANSACTION LOG. THE LEDGER'S OWN CONTRACT.
 * =================================================================================================
 *
 * `MKT-005` is «نقاط ولاء وVIP وإحالات مع سجل حركات» — *loyalty points, VIP and referrals, with a
 * transaction log*. The VIP half shipped as a segment rule (`VipTierSegmentRuleTest`); referrals are
 * an owner decision (`J37`); **the log is the clause with nothing behind it** and this is it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS LEDGER IS, AND — THE LOAD-BEARING PART — WHAT IT IS NOT
 * -------------------------------------------------------------------------------------------------
 *
 * It is an **observer of record**. It is NOT the authority on any customer's balance.
 *
 * The balance lives in `customers.total_club_point`, declared by a **vendored** migration
 * (`packages/workdo/RewardClubPoint/.../2024_04_17_105551_…`) that only runs when the module is
 * installed. Measured in this suite: `Schema::hasColumn('customers', 'total_club_point')` is
 * **FALSE**. Making this ledger authoritative would mean something reads it to decide what a shopper
 * may spend — which changes the input of all eleven first-party spend paths and therefore changes
 * customer-visible balances and the money discount derived from them. Points are money-adjacent, so
 * that is the owner's call and not engineering's; it is reported as one rather than taken here.
 *
 * So: **the only write this slice performs anywhere is an INSERT into this new table.** Nothing
 * reads it to price anything, nothing writes `customers.total_club_point`, and a store without the
 * vendored module behaves exactly as it does today — which {@see self::test_the_ledger_refuses_cleanly_when_its_own_table_is_absent}
 * and the observer suite assert in both directions.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY EACH ROW CARRIES THE BALANCE IT SAW
 * -------------------------------------------------------------------------------------------------
 *
 * A log of deltas alone cannot be reconciled without replaying every row in order, and it cannot
 * answer a dispute at all for a customer whose balance predates the log. Each row therefore records
 * the **observed** `balance_before` and `balance_after` as well as the signed `points_delta`, so one
 * row answers "what happened" and the first row's `balance_before` anchors the whole chain against a
 * balance that existed before this table did. That anchor is what makes
 * {@see \App\Support\Loyalty\LoyaltyReconciliation} able to tell *drift* from *no history*.
 *
 * -------------------------------------------------------------------------------------------------
 * APPEND-ONLY IS ENFORCED, NOT DOCUMENTED
 * -------------------------------------------------------------------------------------------------
 *
 * `PHASE-05-OWNER-DECISION-PACKAGE.md` §2.4 D4b states the rule this table exists to keep: *"A
 * reversal writes a compensating row; it never edits history."* A comment saying so is worth
 * nothing — `updating` and `deleting` therefore **throw**, and the two cases below drive them.
 *
 * @see \App\Support\Loyalty\LoyaltyLedger
 * @see \Tests\Feature\Loyalty\LoyaltyPointLedgerObserverTest  the movements, end to end
 */
final class LoyaltyLedgerTest extends TestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | 1 — the premise: this table is FIRST-PARTY and therefore always present
    |--------------------------------------------------------------------------
    */

    /**
     * **THE PREMISE FOR THE WHOLE SLICE**, and the asymmetry the design turns on.
     *
     * The ledger's own table is declared by `database/migrations/`, so it exists wherever this
     * application does. The *balance* it observes is declared by a vendored module's migration and
     * does not. If this ever inverts — the balance present, the ledger absent — the observer is
     * inert and the product is exactly what it is today, which is the safe direction.
     */
    public function test_premise_the_ledger_table_is_first_party_and_the_balance_column_is_not(): void
    {
        $this->assertTrue(
            Schema::hasTable('loyalty_point_transactions'),
            'premise: `loyalty_point_transactions` is a FIRST-PARTY migration, so it must exist in '
            . 'this suite. If it does not, every assertion below is vacuous.'
        );

        $this->assertFalse(
            Schema::hasColumn('customers', 'total_club_point'),
            'premise: the BALANCE column is vendored and absent here. This is the measured fact the '
            . 'whole design answers to (GAP-191). If a host migration ever declares it, the '
            . 'capability gates stop being exercised by the default suite and the observer suite '
            . 'must be re-read.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — recording a movement
    |--------------------------------------------------------------------------
    */

    public function test_it_records_a_movement_with_the_balance_it_observed(): void
    {
        $row = LoyaltyLedger::record(
            storeId: 7,
            customerId: 42,
            kind: LoyaltyLedger::EARN,
            delta: 10.5,
            balanceBefore: 100.0,
            balanceAfter: 110.5,
            orderId: 412,
            redemptionClaimed: false,
            source: 'test',
            reason: 'order 412',
        );

        $this->assertInstanceOf(LoyaltyPointTransaction::class, $row);

        /*
         * Read back through the RAW table, not the model. CLAUDE.md records three measured false
         * readings from an Eloquent read serving a pre-write row; this model is deliberately not
         * `Cachable` for that reason, and reading raw is how this test proves the row is in the
         * table rather than in memory.
         */
        $stored = DB::table('loyalty_point_transactions')->where('id', $row->id)->first();

        $this->assertNotNull($stored, 'the row must be in the table, not only in memory.');
        $this->assertSame(7, (int) $stored->store_id);
        $this->assertSame(42, (int) $stored->customer_id);
        $this->assertSame(LoyaltyLedger::EARN, $stored->kind);
        $this->assertSame(10.5, (float) $stored->points_delta);
        $this->assertSame(100.0, (float) $stored->balance_before);
        $this->assertSame(110.5, (float) $stored->balance_after);
        $this->assertSame(412, (int) $stored->order_id);
        $this->assertSame(0, (int) $stored->redemption_claimed);
        $this->assertNotNull($stored->created_at, 'a movement without a time is not a log entry.');
    }

    public function test_a_redemption_is_recorded_as_a_negative_movement(): void
    {
        $row = LoyaltyLedger::record(
            storeId: 7,
            customerId: 42,
            kind: LoyaltyLedger::REDEEM,
            delta: -490.0,
            balanceBefore: 500.0,
            balanceAfter: 10.0,
            orderId: 412,
            redemptionClaimed: true,
            source: 'test',
        );

        $stored = DB::table('loyalty_point_transactions')->where('id', $row->id)->first();

        $this->assertSame(-490.0, (float) $stored->points_delta,
            'the sign is the whole point: a redemption that recorded a positive number would read '
            . 'as an award in every sum over this table.');
        $this->assertSame(1, (int) $stored->redemption_claimed);
    }

    /**
     * An unobservable balance is recorded as NULL, never as `0`.
     *
     * CLAUDE.md records a case that passed because `(int) null` is `0`. Here the two readings are
     * opposite facts: `0` means *the customer had no points*, NULL means *this writer could not see
     * the balance*. Collapsing them would make a reconciliation report drift against a figure
     * nobody ever observed.
     */
    public function test_an_unobservable_balance_is_null_and_not_zero(): void
    {
        $row = LoyaltyLedger::record(
            storeId: 7,
            customerId: 42,
            kind: LoyaltyLedger::ADJUST,
            delta: 5.0,
            balanceBefore: null,
            balanceAfter: null,
            source: 'test',
        );

        $stored = DB::table('loyalty_point_transactions')->where('id', $row->id)->first();

        $this->assertNull($stored->balance_before);
        $this->assertNull($stored->balance_after);
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — what it refuses
    |--------------------------------------------------------------------------
    */

    public function test_it_refuses_a_kind_outside_its_own_vocabulary(): void
    {
        $this->expectException(RuntimeException::class);

        LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: 'confiscate', delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );
    }

    /**
     * A zero movement is not a movement. Recording one puts a row in the log that an auditor
     * cannot act on and that no reconciliation can use, and it is the shape a buggy observer
     * produces — a `save()` that touched some other column.
     */
    public function test_it_refuses_a_zero_movement(): void
    {
        $this->expectException(RuntimeException::class);

        LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 0.0,
            balanceBefore: 10.0, balanceAfter: 10.0, source: 'test',
        );
    }

    /**
     * A movement that names no store cannot be read back by any tenant-scoped reader, so it is a
     * row nobody will ever see. `loyalty_point_transactions` is NOT in `config/tenancy.php`'s
     * `scoped_models` and `null_tenant => 'open'`, so the store predicate is always explicit here
     * and a row with a zero store would be invisible to the screen and present in the table.
     */
    public function test_it_refuses_a_movement_that_names_no_store_or_no_customer(): void
    {
        $this->expectException(RuntimeException::class);

        LoyaltyLedger::record(
            storeId: 0, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );
    }

    public function test_it_refuses_a_movement_that_names_no_customer(): void
    {
        $this->expectException(RuntimeException::class);

        LoyaltyLedger::record(
            storeId: 7, customerId: 0, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — append-only, enforced
    |--------------------------------------------------------------------------
    */

    public function test_a_recorded_movement_cannot_be_edited(): void
    {
        $row = LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        try {
            $row->points_delta = 999;
            $row->save();
            $this->fail('history must not be editable: D4b — "a reversal writes a compensating row; '
                . 'it never edits history".');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1.0,
            (float) DB::table('loyalty_point_transactions')->where('id', $row->id)->value('points_delta'),
            'and the refusal must leave the stored row untouched, not merely throw after writing.'
        );
    }

    public function test_a_recorded_movement_cannot_be_deleted(): void
    {
        $row = LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        try {
            $row->delete();
            $this->fail('history must not be deletable.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('append-only', strtolower($e->getMessage()));
        }

        $this->assertSame(
            1,
            DB::table('loyalty_point_transactions')->where('id', $row->id)->count(),
            'and the row must still be there.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 5 — the capability gate on its OWN table
    |--------------------------------------------------------------------------
    */

    /**
     * `Schema::hasTable()` AND NOT `module_is_active()`.
     *
     * CLAUDE.md: `module_is_active()` resolves its principal from `Auth::check()` first, so it
     * answers FALSE from a console command or a test body with nobody signed in while the
     * `add_on_managers` row, `has`, `isEnabled` and `getByStatus` all read TRUE. A writer gated on
     * it would silently stop logging in exactly the contexts where a log matters most — a queue
     * worker and a scheduled job.
     *
     * The case is driven by dropping the ledger's own table, which is the only way to reach the
     * unavailable branch in a suite where the first-party migration always runs.
     */
    public function test_the_ledger_refuses_cleanly_when_its_own_table_is_absent(): void
    {
        $this->assertTrue(LoyaltyLedger::available(), 'premise: available with the table present.');

        Schema::drop('loyalty_point_transactions');

        $this->assertFalse(LoyaltyLedger::available());

        $this->assertNull(
            LoyaltyLedger::record(
                storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
                balanceBefore: null, balanceAfter: null, source: 'test',
            ),
            'with no table the writer must return null rather than throw: it is called from inside '
            . 'a payment path, and a log that cannot write must never be able to fail a checkout.'
        );
    }

    /**
     * `observe()` SWALLOWS WHAT `record()` THROWS, and this is the case that exercises the catch.
     *
     * WRITTEN BECAUSE A MUTATION FOUND THE GAP. Replacing `report($e); return null;` with
     * `throw $e;` SURVIVED `test_a_ledger_that_cannot_write_does_not_break_the_save`: that case
     * drops the TABLE, so `record()` returns null from its `available()` check and never throws, so
     * the catch block was never entered by any test. An untested `catch` in a payment path is
     * exactly the shape CLAUDE.md records as a post-commit throw leaving a half-written order at
     * HTTP 500.
     *
     * An invalid kind makes `record()` throw for a reason that has nothing to do with the schema, so
     * the swallow is driven directly.
     */
    public function test_observe_swallows_a_programmer_error_rather_than_failing_its_caller(): void
    {
        $this->assertTrue(LoyaltyLedger::available(), 'premise: the table is present, so the '
            . 'refusal below comes from the argument and not from a missing table.');

        $this->assertNull(
            LoyaltyLedger::observe(
                storeId: 7, customerId: 42, kind: 'confiscate', delta: 1.0,
                balanceBefore: null, balanceAfter: null, source: 'test',
            ),
            'the observer path must never throw: it runs after the balance is already committed, so '
            . 'a throw here turns a successful payment into an HTTP 500 with the points moved and '
            . 'no log row.'
        );

        $this->assertSame(
            0,
            DB::table('loyalty_point_transactions')->count(),
            'and swallowing must not mean writing something wrong instead — nothing is recorded.'
        );
    }

    /** And the same call through `record()` DOES throw, so the two paths are provably different. */
    public function test_record_still_throws_where_observe_swallows(): void
    {
        $this->expectException(RuntimeException::class);

        LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: 'confiscate', delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 6 — reading back, with the tenant predicate
    |--------------------------------------------------------------------------
    */

    public function test_the_history_reader_does_not_reach_another_stores_movements(): void
    {
        LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        /*
         * The SAME customer id in another store. `customers.id` values are reused across tenants in
         * a shared database, so this is the ordinary case rather than a contrived one.
         */
        LoyaltyLedger::record(
            storeId: 8, customerId: 42, kind: LoyaltyLedger::EARN, delta: 99.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        $history = LoyaltyLedger::historyFor(7, 42);

        $this->assertCount(1, $history);
        $this->assertSame(1.0, (float) $history[0]->points_delta);

        $this->assertSame(
            2,
            DB::table('loyalty_point_transactions')->count(),
            'premise: both rows are really in the table, so the reader filtered rather than the '
            . 'fixture failing to write one.'
        );
    }

    public function test_the_history_reader_returns_the_newest_movement_first(): void
    {
        $first = LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 1.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );
        $second = LoyaltyLedger::record(
            storeId: 7, customerId: 42, kind: LoyaltyLedger::EARN, delta: 2.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        $history = LoyaltyLedger::historyFor(7, 42);

        $this->assertSame(
            [$second->id, $first->id],
            $history->pluck('id')->map(static fn ($id) => (int) $id)->all(),
            'a merchant opens this screen to see what just happened. Ordering by `created_at` alone '
            . 'would tie for two movements in one request — both written in the same second — so '
            . 'the id breaks the tie and the order is total.'
        );
    }
}
