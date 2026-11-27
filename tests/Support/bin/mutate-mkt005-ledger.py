#!/usr/bin/env python3
"""
`MKT-005` / `P05-11` — MUTATIONS FOR «سجل حركات», THE LOYALTY POINT TRANSACTION LOG.

Twenty-four one-change mutations, each applied alone, run, and reverted. They attack the claims the
slice makes rather than its syntax, grouped by the claim they attack:

  THE CHOKEPOINT EXISTS AT ALL
   1. the observer is never registered
   2. the capability gate is deleted, so an absent vendored column is no longer a reason to stand
      down -- this is the "a store without the module behaves exactly as today" constraint
   3. the `wasChanged` gate is deleted, so a merchant renaming a customer writes an audit row

  WHAT THE ROW SAYS
   4. the sign is dropped, so a redemption records as an award
   5. `kind` is taken from the sign even with nothing to say why -- an unattributed movement then
      asserts a cause the log cannot establish
   6. the balance endpoints are not recorded, so the row cannot be read without a replay
   7. `'off'` is read with `!empty()` -- the D7 trap, which stamps a false redemption claim on the
      ordinary earning order of every shopper who already qualifies

  ATTRIBUTION SAFETY
   8. the note is peeked instead of claimed, so one order names every later movement
   9. the note is not keyed on the customer, so it names ANOTHER customer's movement

  TENANCY
  10. the movement is filed under `getCurrentStore()` instead of the customer's own store
  11. the history reader drops its store predicate
  12. the balance reader drops its store predicate
  13. the screen reads the history for the resolved store instead of the customer's

  NULL IS NOT ZERO
  14. an unobservable balance reads as `0.0`

  APPEND-ONLY
  15. the `updating` refusal is deleted
  16. the `deleting` refusal is deleted

  THE RECONCILIATION
  17. the chain is anchored at zero rather than at the first row's `balance_before` -- the day-one
      false-finding flood
  18. `NO_HISTORY` becomes a finding -- the same flood by another route
  19. the per-link gap check is dropped, so an unlogged movement reads as clean
  20. the row's own arithmetic check is dropped
  21. the reconciliation repairs the drift it finds

  THE SCREEN
  22. the "programme is not installed" branch is replaced by an empty table, which reads as "this
      customer never earned a point"
  23. `<bdi>` is dropped from the signed figure, so a minus can render at the wrong end in Arabic
  24. an Arabic entry is replaced by its English key -- present, non-blank, and still English

FOUR EXPECTED SURVIVORS, EVERY ONE MEASURED AND NAMED -- 2, 3, 25 and 29. They are not weak
tests; they are redundancy, and the group says so rather than hiding it. "A store without the
vendored module behaves exactly as today" is defended by FOUR independent refusals in series
(`LoyaltyBalance::available()`, `wasChanged()`, a null endpoint, and the epsilon/`record()` zero
refusal), so no single-line mutation can break it -- removing any one, or the first two together,
leaves the next one answering. 29 is the same shape one layer out: the observer's storeless check is
backed by `LoyaltyLedger::record()`'s own, which 26 and 27 prove is itself tested. The redundancy is
recorded in `CustomerLoyaltyPointObserver`'s docblock, which is where a reader meets it.

THREE SURVIVORS WERE REAL AND WERE FIXED RATHER THAN EXPLAINED -- 7, 10 and 13, each because the
test could not tell the two readings apart, and 28, which found an untested `catch` on a payment
path. What each needed is recorded beside it.

A `!!` line means the run proved nothing (anchor missed, or the filter matched no test) and is
counted as a survivor, per `mutate.py`'s own three-ways-a-run-lies guard.

Run: `python3 tests/Support/bin/mutate-mkt005-ledger.py` from the repository root.
"""

from __future__ import annotations

import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

from mutate import Mutation, run  # noqa: E402

PROVIDER = 'app/Providers/AppServiceProvider.php'
OBSERVER = 'app/Observers/CustomerLoyaltyPointObserver.php'
LEDGER = 'app/Support/Loyalty/LoyaltyLedger.php'
BALANCE = 'app/Support/Loyalty/LoyaltyBalance.php'
ATTRIBUTION = 'app/Support/Loyalty/LoyaltyAttribution.php'
LISTENER = 'app/Listeners/RecordLoyaltyPointAttribution.php'
MODEL = 'app/Models/LoyaltyPointTransaction.php'
RECON = 'app/Support/Loyalty/LoyaltyReconciliation.php'
CONTROLLER = 'app/Http/Controllers/CustomerController.php'
VIEW = 'resources/views/customer/loyalty-history.blade.php'
ARABIC = 'resources/lang/ar.json'

T_OBS = 'tests/Feature/Loyalty/LoyaltyPointLedgerObserverTest.php'
T_LEDGER = 'tests/Unit/Loyalty/LoyaltyLedgerTest.php'
T_BALANCE = 'tests/Unit/Loyalty/LoyaltyBalanceTest.php'
T_RECON = 'tests/Unit/Loyalty/LoyaltyReconciliationTest.php'
T_SCREEN = 'tests/Feature/Loyalty/CustomerLoyaltyHistoryScreenTest.php'
T_STRINGS = 'tests/Unit/Loyalty/LoyaltyStringsAreTranslatedTest.php'

MUTATIONS = [
    # ---------------------------------------------------------------- the chokepoint
    Mutation(
        name='1. the observer is never registered',
        path=PROVIDER,
        old='        Customer::observe(CustomerLoyaltyPointObserver::class);',
        new='        // Customer::observe(CustomerLoyaltyPointObserver::class);',
        test_file=T_OBS,
        filter='test_an_increase_is_logged_as_an_earn_with_the_balance_it_observed',
    ),
    Mutation(
        name='2. the capability gate ALONE is deleted [EXPECTED SURVIVOR: layer 2 answers]',
        path=OBSERVER,
        old="""        if (! LoyaltyBalance::available()) {
            return;
        }

        /*
         * `wasChanged()` AND NOT A BARE COMPARISON.""",
        new="""        if (false) {
            return;
        }

        /*
         * `wasChanged()` AND NOT A BARE COMPARISON.""",
        test_file=T_OBS,
        filter='test_a_store_without_the_vendored_module_behaves_exactly_as_before',
    ),
    Mutation(
        name='3. the wasChanged gate ALONE is deleted [EXPECTED SURVIVOR: layers 3-4 answer]',
        path=OBSERVER,
        old='        if (! $customer->wasChanged(LoyaltyBalance::COLUMN)) {',
        new='        if (false) {',
        test_file=T_OBS,
        filter='test_a_save_that_does_not_touch_the_balance_logs_nothing',
    ),

    # ---------------------------------------------------------------- what the row says
    Mutation(
        name='4. the sign is dropped (a redemption records as an award)',
        path=OBSERVER,
        old='            delta: $delta,\n            balanceBefore: $before,',
        new='            delta: abs($delta),\n            balanceBefore: $before,',
        test_file=T_OBS,
        filter='test_a_decrease_is_logged_as_a_redemption_with_a_negative_delta',
    ),
    Mutation(
        name='5. kind is taken from the sign even when nothing says why',
        path=OBSERVER,
        old="""            kind: $note === null
                ? LoyaltyLedger::ADJUST
                : ($delta > 0 ? LoyaltyLedger::EARN : LoyaltyLedger::REDEEM),""",
        new='            kind: $delta > 0 ? LoyaltyLedger::EARN : LoyaltyLedger::REDEEM,',
        test_file=T_OBS,
        filter='test_an_unattributed_movement_is_logged_as_an_adjustment',
    ),
    Mutation(
        name='6. the balance endpoints are not recorded',
        path=OBSERVER,
        old='            balanceBefore: $before,\n            balanceAfter: $after,',
        new='            balanceBefore: null,\n            balanceAfter: null,',
        test_file=T_OBS,
        filter='test_an_increase_is_logged_as_an_earn_with_the_balance_it_observed',
    ),
    Mutation(
        name="7. the D7 trap: 'off' read with !empty() becomes a redemption claim",
        path=LISTENER,
        old="            redemptionClaimed: $flag === 'on',",
        new='            redemptionClaimed: ! empty($flag),',
        test_file=T_OBS,
        # The EVENT path cannot see this: `NormaliseRewardRedemptionFlag` is discovered out of
        # the same directory, `N` sorts before `R`, so it strips the 'off' before this listener
        # reads the bag and `!empty(None)` is false. MEASURED -- the first version of this
        # mutation SURVIVED `test_the_shipped_checkouts_off_flag_...` for exactly that reason.
        # The direct case drives the listener itself, the only way to reach its own comparison.
        filter='test_the_attribution_listener_itself_refuses_the_off_flag_without_the_normaliser',
    ),

    # ---------------------------------------------------------------- attribution safety
    Mutation(
        name='8. the note is peeked instead of claimed (one order names every movement)',
        path=OBSERVER,
        old='        $note = app(LoyaltyAttribution::class)->claimFor($customerId);',
        new='        $note = app(LoyaltyAttribution::class)->peekFor($customerId);',
        test_file=T_OBS,
        filter='test_an_attribution_is_used_once_and_not_reapplied',
    ),
    Mutation(
        name="9. the note is not keyed on the customer (it names another customer's movement)",
        path=ATTRIBUTION,
        old="""        $note = $this->notes[$customerId] ?? null;

        unset($this->notes[$customerId]);

        return $note;""",
        new="""        $note = $this->notes[$customerId] ?? (reset($this->notes) ?: null);

        unset($this->notes[$customerId]);

        return $note;""",
        test_file=T_OBS,
        filter='test_an_attribution_for_one_customer_does_not_name_anothers_movement',
    ),

    # ---------------------------------------------------------------- tenancy
    Mutation(
        name='10. the movement is filed under getCurrentStore() instead of the customer',
        path=OBSERVER,
        old="        $storeId = (int) ($customer->store_id ?? 0);",
        new="        $storeId = (int) (getCurrentStore() ?? $customer->store_id ?? 0);",
        test_file=T_OBS,
        filter='test_the_movement_is_filed_under_the_customers_own_store',
    ),
    Mutation(
        name='11. the history reader drops its store predicate',
        path=LEDGER,
        old="""        return LoyaltyPointTransaction::query()
            ->where('store_id', $storeId)
            ->where('customer_id', $customerId)""",
        new="""        return LoyaltyPointTransaction::query()
            ->where('customer_id', $customerId)""",
        test_file=T_LEDGER,
        filter='test_the_history_reader_does_not_reach_another_stores_movements',
    ),
    Mutation(
        name='12. the balance reader drops its store predicate',
        path=BALANCE,
        old="""        $value = DB::table('customers')
            ->where('store_id', $storeId)
            ->where('id', $customerId)""",
        new="""        $value = DB::table('customers')
            ->where('id', $customerId)""",
        test_file=T_BALANCE,
        filter='test_it_does_not_read_another_stores_customer',
    ),
    Mutation(
        name="13. the screen reads the resolved store's history, not the customer's",
        path=CONTROLLER,
        old="""                'history' => \\App\\Support\\Loyalty\\LoyaltyLedger::historyFor(
                    $customerStoreId,""",
        new="""                'history' => \\App\\Support\\Loyalty\\LoyaltyLedger::historyFor(
                    (int) (getCurrentStore() ?? $customerStoreId),""",
        test_file=T_SCREEN,
        # NOT the merchant case: there the signed-in merchant's resolved store IS the
        # customer's, so this mutant is inert and SURVIVED it. The operator separates the two
        # numbers -- `getCurrentStore()` answers the hardcoded literal 1 for a `super admin`
        # with an empty `current_store`, and the customer under test lives in a store that is
        # not 1.
        filter='test_the_operator_sees_the_customers_own_store_and_not_store_one',
    ),

    # ---------------------------------------------------------------- null is not zero
    Mutation(
        name='14. an unobservable balance reads as 0.0',
        path=BALANCE,
        old="""        if (! self::available() || $storeId <= 0 || $customerId <= 0) {
            return null;
        }""",
        new="""        if (! self::available() || $storeId <= 0 || $customerId <= 0) {
            return 0.0;
        }""",
        test_file=T_BALANCE,
        filter='test_with_the_column_absent_the_balance_is_null_and_not_zero',
    ),

    # ---------------------------------------------------------------- append-only
    Mutation(
        name='15. the updating refusal is deleted',
        path=MODEL,
        old="""        static::updating(static function (): void {
            throw new RuntimeException(""",
        new="""        static::updating(static function (): void {
            return;
            throw new RuntimeException(""",
        test_file=T_LEDGER,
        filter='test_a_recorded_movement_cannot_be_edited',
    ),
    Mutation(
        name='16. the deleting refusal is deleted',
        path=MODEL,
        old="""        static::deleting(static function (): void {
            throw new RuntimeException(""",
        new="""        static::deleting(static function (): void {
            return;
            throw new RuntimeException(""",
        test_file=T_LEDGER,
        filter='test_a_recorded_movement_cannot_be_deleted',
    ),

    # ---------------------------------------------------------------- the reconciliation
    Mutation(
        name='17. the chain is anchored at zero rather than at the first balance_before',
        path=RECON,
        old='        $anchor = (float) $rows->first()->balance_before;',
        new='        $anchor = 0.0;',
        test_file=T_RECON,
        filter='test_a_consistent_chain_that_ends_at_the_live_balance_reconciles',
    ),
    Mutation(
        name='18. NO_HISTORY becomes a finding (the day-one false-finding flood)',
        path=RECON,
        old='    public const FINDINGS = [self::INCOMPLETE_HISTORY, self::CHAIN_BROKEN, self::BALANCE_DRIFT];',
        new='    public const FINDINGS = [self::NO_HISTORY, self::INCOMPLETE_HISTORY, self::CHAIN_BROKEN, self::BALANCE_DRIFT];',
        test_file=T_RECON,
        filter='test_a_customer_with_no_movements_is_no_history_and_not_drift',
    ),
    Mutation(
        name='19. the per-link gap check is dropped (an unlogged movement reads as clean)',
        path=RECON,
        old='            if (abs($before - $cursor) >= LoyaltyLedger::EPSILON\n                || abs(',
        new='            if (false\n                || abs(',
        test_file=T_RECON,
        filter='test_a_gap_between_two_movements_is_a_broken_chain',
    ),
    Mutation(
        name="20. the row's own arithmetic check is dropped",
        path=RECON,
        old='                || abs(($before + (float) $row->points_delta) - $after) >= LoyaltyLedger::EPSILON) {',
        new='                || false) {',
        test_file=T_RECON,
        filter='test_a_row_whose_own_arithmetic_does_not_hold_is_a_broken_chain',
    ),
    Mutation(
        name='21. the reconciliation repairs the drift it finds',
        path=RECON,
        old='        $difference = round($actual - $expected, 2);',
        new="""        $difference = round($actual - $expected, 2);

        if (abs($difference) >= LoyaltyLedger::EPSILON) {
            DB::table('customers')->where('store_id', $storeId)->where('id', $customerId)
                ->update([LoyaltyBalance::COLUMN => $expected]);
        }""",
        test_file=T_RECON,
        filter='test_reconciling_never_changes_the_balance_it_is_checking',
    ),

    # ---------------------------------------------------------------- the screen
    Mutation(
        name='22. the "programme is not installed" sentence becomes an empty table',
        path=VIEW,
        old='{{ __(\'The loyalty points programme is not installed on this store, so there are no points and no history to show.\') }}',
        new='{{ __(\'No points movements recorded yet\') }}',
        test_file=T_SCREEN,
        filter='test_without_the_module_the_screen_says_the_programme_is_not_installed',
    ),
    Mutation(
        name='23. <bdi> is dropped from the signed figure',
        path=VIEW,
        old='                                                <bdi>{{ $ssSignedPoints($ssMovement->points_delta) }}</bdi>',
        new='                                                {{ $ssSignedPoints($ssMovement->points_delta) }}',
        test_file=T_SCREEN,
        filter='test_a_negative_movement_is_bidi_isolated_in_arabic',
    ),
    # ------------------------------------------------- the pair, and the inner refusals
    Mutation(
        name='25. BOTH observer gates go together [EXPECTED SURVIVOR: layer 3 answers]',
        path=OBSERVER,
        old="""        if (! LoyaltyBalance::available()) {
            return;
        }

        /*
         * `wasChanged()` AND NOT A BARE COMPARISON.""",
        new="""        /*
         * `wasChanged()` AND NOT A BARE COMPARISON.""",
        test_file=T_OBS,
        filter='test_a_store_without_the_vendored_module_behaves_exactly_as_before',
    ),
    Mutation(
        name="26. the ledger's zero-movement refusal is deleted",
        path=LEDGER,
        old='        if (abs($delta) < self::EPSILON) {',
        new='        if (false) {',
        test_file=T_LEDGER,
        filter='test_it_refuses_a_zero_movement',
    ),
    Mutation(
        name='27. the kind vocabulary is no longer enforced',
        path=LEDGER,
        old='        if (! in_array($kind, self::KINDS, true)) {',
        new='        if (false) {',
        test_file=T_LEDGER,
        filter='test_it_refuses_a_kind_outside_its_own_vocabulary',
    ),
    Mutation(
        name='28. a failed log rethrows into the payment path',
        path=LEDGER,
        old='            report($e);\n\n            return null;',
        new='            throw $e;',
        test_file=T_LEDGER,
        # NOT the dropped-table case: there `record()` returns null from its `available()`
        # check and never throws, so the catch block is never entered and this mutation
        # SURVIVED. That was a real gap -- an untested `catch` on a payment path -- and the
        # case below was written for it, driving the swallow with an invalid kind.
        filter='test_observe_swallows_a_programmer_error_rather_than_failing_its_caller',
    ),
    Mutation(
        name="29. the observer's storeless refusal is deleted [EXPECTED SURVIVOR: the ledger's own answers]",
        path=OBSERVER,
        old='        if ($storeId <= 0 || $customerId <= 0) {',
        new='        if (false) {',
        test_file=T_OBS,
        filter='test_a_customer_with_no_store_is_not_logged',
    ),
    Mutation(
        name='24. an Arabic entry is replaced by its English key',
        path=ARABIC,
        old='"Loyalty points history": "سجل حركات نقاط الولاء"',
        new='"Loyalty points history": "Loyalty points history"',
        test_file=T_STRINGS,
        filter='test_the_arabic_entries_are_not_copies_of_the_english',
    ),
]

if __name__ == '__main__':
    print(__doc__)
    sys.exit(run(MUTATIONS))
