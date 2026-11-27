<?php

namespace Tests\Unit\Loyalty;

use App\Models\Customer;
use App\Support\Loyalty\LoyaltyLedger;
use App\Support\Loyalty\LoyaltyReconciliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `MKT-005` — RECONCILING THE LOG AGAINST THE BALANCE, AND REPORTING RATHER THAN REPAIRING.
 * =================================================================================================
 *
 * `PHASE-05-OWNER-DECISION-PACKAGE.md` §2.4 D4b states the rule this exists to check: *"The
 * displayed balance must be reconcilable against the log."* It then records why it is not today:
 * *"`customers.total_club_point` is a denormalised column that the listener REPLACES rather than
 * debits, so it can already drift from its own log."*
 *
 * -------------------------------------------------------------------------------------------------
 * IT REPORTS. IT NEVER CORRECTS. THAT IS THE DESIGN AND NOT A LIMITATION.
 * -------------------------------------------------------------------------------------------------
 *
 * Correcting drift means writing `customers.total_club_point` — changing a customer's spendable
 * points. Points are money-adjacent, so that is the owner's decision and it is reported as one.
 * {@see self::test_reconciling_never_changes_the_balance_it_is_checking} drives that.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ANCHOR, WHICH IS WHY THIS WORKS AT ALL FOR AN EXISTING STORE
 * -------------------------------------------------------------------------------------------------
 *
 * A store that installed `RewardClubPoint` years ago has balances with no history: this ledger did
 * not exist when they were earned. A reconciliation that summed deltas from zero would report every
 * such customer as having drifted by their entire balance — thousands of false findings on day one,
 * which is how a control gets switched off.
 *
 * So the chain is anchored on the **first row's `balance_before`** — the balance that existed at the
 * moment this ledger first observed that customer. Everything before that is explicitly outside what
 * the log can vouch for, and the verdict says so rather than guessing.
 *
 * -------------------------------------------------------------------------------------------------
 * SIX VERDICTS, BECAUSE "DRIFT" AND "NO HISTORY" ARE OPPOSITE FACTS
 * -------------------------------------------------------------------------------------------------
 *
 * `NO_HISTORY` is not a finding — it is the ordinary state of a customer who has never moved a
 * point, and of every customer in a store that installed the ledger yesterday. Reporting it as drift
 * would be the `(int) null === 0` hazard again, one level up. `CHAIN_BROKEN` (the rows disagree with
 * each other, so a movement was missed) and `BALANCE_DRIFT` (the rows agree but the column does not)
 * are different investigations and are reported as different things.
 *
 * @see \App\Support\Loyalty\LoyaltyReconciliation
 */
final class LoyaltyReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private function giveThisSchemaTheVendoredColumn(): void
    {
        if (! Schema::hasColumn('customers', 'total_club_point')) {
            Schema::table('customers', function ($table) {
                $table->float('total_club_point', 15, 2)->default(0);
            });
        }
    }

    private function customerWithBalance(int $storeId, string $email, float $balance): int
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Recon', 'last_name' => 'Fixture',
            'email' => $email, 'store_id' => $storeId, 'status' => 1,
        ]);

        /*
         * ONLY WHERE THE COLUMN EXISTS. `test_with_no_balance_column_…` deliberately runs without
         * it, and an unconditional write here threw `no such column` — the exact defect this whole
         * slice is gated against, reproduced by the test fixture rather than by the code. Keeping
         * the guard means the unobservable case is driven on a schema that really lacks the column.
         *
         * RAW, so the observer does not log a movement this test did not ask for — and the Eloquent
         * cache is flushed afterwards, because CLAUDE.md records a raw write leaving `Cachable`
         * reads serving the pre-write row for the rest of the process.
         */
        if (Schema::hasColumn('customers', 'total_club_point')) {
            DB::table('customers')->where('id', $customer->id)
                ->update(['total_club_point' => $balance]);
            (new Customer)->flushCache();
        }

        return (int) $customer->id;
    }

    /** Write a chain of movements directly, so the chain's shape is the fixture rather than a side effect. */
    private function movement(
        int $storeId,
        int $customerId,
        float $before,
        float $after,
        string $kind = LoyaltyLedger::ADJUST,
    ): void {
        LoyaltyLedger::record(
            storeId: $storeId,
            customerId: $customerId,
            kind: $kind,
            delta: $after - $before,
            balanceBefore: $before,
            balanceAfter: $after,
            source: 'test',
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 1 — nothing to reconcile
    |--------------------------------------------------------------------------
    */

    /**
     * With the vendored column absent there is no balance, so there is nothing to reconcile against
     * — and that is NOT a finding. This is the state of every store that has not installed the
     * module, which is the state of this whole suite.
     */
    public function test_with_no_balance_column_the_verdict_is_unobservable_and_not_drift(): void
    {
        $this->assertFalse(Schema::hasColumn('customers', 'total_club_point'),
            'premise: the vendored column is absent here (GAP-191).');

        $customerId = $this->customerWithBalance(7, 'unobservable@example.com', 0.0);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::UNOBSERVABLE, $verdict->status);
        $this->assertFalse($verdict->isFinding(),
            'a store without the module is not a store with a problem.');
        $this->assertNull($verdict->actual);
    }

    /**
     * A customer the ledger has never seen. The log can vouch for NOTHING about them, which is a
     * different statement from "their balance is wrong" and from "their balance is zero".
     */
    public function test_a_customer_with_no_movements_is_no_history_and_not_drift(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'nohistory@example.com', 250.0);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::NO_HISTORY, $verdict->status);
        $this->assertFalse(
            $verdict->isFinding(),
            'THE DAY-ONE CASE. Every customer of a store that installs this ledger today has a '
            . 'balance and no history. Reporting 250 points as drift would be thousands of false '
            . 'findings at once, which is how a control gets switched off.'
        );
        $this->assertSame(250.0, $verdict->actual,
            'and the live balance is still reported, because a merchant looking at the screen wants '
            . 'to see it even when the log cannot explain it.');
        $this->assertNull($verdict->expected);
        $this->assertSame(0, $verdict->movements);
    }

    /*
    |--------------------------------------------------------------------------
    | 2 — a clean chain
    |--------------------------------------------------------------------------
    */

    public function test_a_consistent_chain_that_ends_at_the_live_balance_reconciles(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'clean@example.com', 60.0);

        $this->movement(7, $customerId, 100.0, 110.0, LoyaltyLedger::EARN);
        $this->movement(7, $customerId, 110.0, 50.0, LoyaltyLedger::REDEEM);
        $this->movement(7, $customerId, 50.0, 60.0, LoyaltyLedger::EARN);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::RECONCILED, $verdict->status);
        $this->assertFalse($verdict->isFinding());
        $this->assertSame(60.0, $verdict->expected);
        $this->assertSame(60.0, $verdict->actual);
        $this->assertSame(0.0, $verdict->difference);
        $this->assertSame(3, $verdict->movements);
        $this->assertSame(
            100.0,
            $verdict->anchor,
            'THE ANCHOR: the chain starts from the balance that existed when the ledger first saw '
            . 'this customer, not from zero. Summing the three deltas from zero gives 10 - 60 + 10 '
            . '= -40, so a reconciliation without the anchor would report this clean customer as '
            . 'having drifted by 100.'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | 3 — the two findings, which are different investigations
    |--------------------------------------------------------------------------
    */

    /**
     * The rows agree with each other and the column does not. Something wrote the balance without
     * the observer seeing it — a raw `UPDATE`, another process, or a write that predates the ledger
     * being registered.
     */
    public function test_a_consistent_chain_that_disagrees_with_the_column_is_balance_drift(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'drifted@example.com', 999.0);

        $this->movement(7, $customerId, 100.0, 110.0, LoyaltyLedger::EARN);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::BALANCE_DRIFT, $verdict->status);
        $this->assertTrue($verdict->isFinding());
        $this->assertSame(110.0, $verdict->expected);
        $this->assertSame(999.0, $verdict->actual);
        $this->assertSame(889.0, $verdict->difference);
    }

    /**
     * The rows disagree with EACH OTHER: one movement's starting balance is not the previous one's
     * ending balance, so a movement in between was never logged. A different investigation from
     * drift — here the log is incomplete rather than the column wrong.
     */
    public function test_a_gap_between_two_movements_is_a_broken_chain(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'gap@example.com', 300.0);

        $this->movement(7, $customerId, 100.0, 110.0, LoyaltyLedger::EARN);
        /* 110 -> 200 happened and was never logged. */
        $this->movement(7, $customerId, 200.0, 300.0, LoyaltyLedger::EARN);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(
            LoyaltyReconciliation::CHAIN_BROKEN,
            $verdict->status,
            'and NOT `RECONCILED`, even though the last row happens to end exactly at the live '
            . 'balance. A chain whose links do not meet cannot vouch for its own endpoint, and a '
            . 'reconciliation that only compared the last row with the column would call this '
            . 'clean — which is precisely the unlogged movement a merchant needs to know about.'
        );
        $this->assertTrue($verdict->isFinding());
        $this->assertNotNull($verdict->brokenAt, 'the verdict must say WHICH link failed.');
    }

    /**
     * A row whose own arithmetic does not hold — `balance_before + points_delta != balance_after`.
     * Only reachable by a writer that is not the observer, which is exactly why it is checked.
     */
    public function test_a_row_whose_own_arithmetic_does_not_hold_is_a_broken_chain(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'bent@example.com', 110.0);

        LoyaltyLedger::record(
            storeId: 7,
            customerId: $customerId,
            kind: LoyaltyLedger::EARN,
            delta: 5.0,                 /* claims +5 */
            balanceBefore: 100.0,
            balanceAfter: 110.0,        /* but moved +10 */
            source: 'test',
        );

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::CHAIN_BROKEN, $verdict->status);
        $this->assertTrue($verdict->isFinding());
    }

    /**
     * A row that could not see one of its endpoints makes the chain unvouchable — reported as
     * INCOMPLETE, which is neither "clean" nor "drifted".
     *
     * The observer never writes such a row (it returns when either endpoint is null), so this is
     * about a future writer and about {@see \App\Support\Loyalty\LoyaltyLedger::record()} being
     * callable directly. Calling it `CHAIN_BROKEN` would accuse the data of being wrong when it is
     * only silent.
     */
    public function test_a_movement_with_an_unobservable_endpoint_makes_the_history_incomplete(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'incomplete@example.com', 10.0);

        LoyaltyLedger::record(
            storeId: 7, customerId: $customerId, kind: LoyaltyLedger::ADJUST, delta: 10.0,
            balanceBefore: null, balanceAfter: null, source: 'test',
        );

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertSame(LoyaltyReconciliation::INCOMPLETE_HISTORY, $verdict->status);
        $this->assertSame(1, $verdict->movements, 'the row is counted even though it cannot chain.');
        $this->assertSame(10.0, $verdict->actual);
        $this->assertNull($verdict->expected);
    }

    /*
    |--------------------------------------------------------------------------
    | 4 — it never repairs, and it never leaves its tenant
    |--------------------------------------------------------------------------
    */

    /**
     * **THE BOUNDING CONSTRAINT.** Reconciling is a read.
     */
    public function test_reconciling_never_changes_the_balance_it_is_checking(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $customerId = $this->customerWithBalance(7, 'untouched@example.com', 999.0);
        $this->movement(7, $customerId, 100.0, 110.0, LoyaltyLedger::EARN);

        $verdict = LoyaltyReconciliation::forCustomer(7, $customerId);

        $this->assertTrue($verdict->isFinding(), 'premise: there really is drift to be tempted by.');

        $this->assertSame(
            999.0,
            (float) DB::table('customers')->where('id', $customerId)->value('total_club_point'),
            'the drifted balance must be exactly as it was. Correcting it would change a '
            . 'customer-visible, money-adjacent figure, which is the owner\'s decision.'
        );

        $this->assertSame(
            1,
            DB::table('loyalty_point_transactions')->count(),
            'and no compensating row may be invented either: a reconciliation that wrote its own '
            . 'findings into the log it is auditing would make the next run read clean.'
        );
    }

    public function test_it_does_not_reconcile_against_another_stores_movements(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $mine = $this->customerWithBalance(7, 'mine@example.com', 110.0);

        $this->movement(7, $mine, 100.0, 110.0, LoyaltyLedger::EARN);
        /* The SAME customer id in store 8 would, unfiltered, break the chain above. */
        $this->movement(8, $mine, 7.0, 9.0, LoyaltyLedger::EARN);

        $verdict = LoyaltyReconciliation::forCustomer(7, $mine);

        $this->assertSame(LoyaltyReconciliation::RECONCILED, $verdict->status);
        $this->assertSame(1, $verdict->movements);

        $this->assertSame(2, DB::table('loyalty_point_transactions')->count(),
            'premise: the foreign row really exists, so the verdict above is the predicate working '
            . 'and not a fixture that failed to write.');
    }

    /*
    |--------------------------------------------------------------------------
    | 5 — the store-wide summary
    |--------------------------------------------------------------------------
    */

    /**
     * The summary counts verdicts by status and never sums a balance.
     *
     * A single "total points outstanding" figure across customers would be the shape the metric
     * layer refuses for money: it reads as authoritative and is only as good as its least-covered
     * contributor. A count of findings is actionable and cannot be misread.
     */
    public function test_the_store_summary_counts_findings_without_summing_balances(): void
    {
        $this->giveThisSchemaTheVendoredColumn();

        $clean = $this->customerWithBalance(7, 'clean-sum@example.com', 110.0);
        $this->movement(7, $clean, 100.0, 110.0, LoyaltyLedger::EARN);

        $drifted = $this->customerWithBalance(7, 'drift-sum@example.com', 999.0);
        $this->movement(7, $drifted, 100.0, 110.0, LoyaltyLedger::EARN);

        /* A customer the ledger has never seen contributes NO verdict: it has no movements. */
        $this->customerWithBalance(7, 'untouched-sum@example.com', 500.0);

        /* Another store's drifted customer must not appear in this store's summary. */
        $theirs = $this->customerWithBalance(8, 'theirs-sum@example.com', 999.0);
        $this->movement(8, $theirs, 1.0, 2.0, LoyaltyLedger::EARN);

        $summary = LoyaltyReconciliation::forStore(7);

        $this->assertSame(2, $summary['customers'], 'only the two with movements in store 7.');
        $this->assertSame(1, $summary['findings']);
        $this->assertSame(1, $summary['by_status'][LoyaltyReconciliation::RECONCILED] ?? 0);
        $this->assertSame(1, $summary['by_status'][LoyaltyReconciliation::BALANCE_DRIFT] ?? 0);
        $this->assertArrayNotHasKey('total_points', $summary);
    }
}
