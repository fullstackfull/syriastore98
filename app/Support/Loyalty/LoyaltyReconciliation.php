<?php

namespace App\Support\Loyalty;

use App\Models\LoyaltyPointTransaction;
use Illuminate\Support\Facades\DB;

/**
 * `MKT-005` — THE LOG AGAINST THE BALANCE. IT REPORTS; IT NEVER REPAIRS.
 * =================================================================================================
 *
 * `PHASE-05-OWNER-DECISION-PACKAGE.md` §2.4 D4b states the rule: *"The displayed balance must be
 * reconcilable against the log."* It then records why it is not today: *"`customers.total_club_point`
 * is a denormalised column that the listener REPLACES rather than debits, so it can already drift
 * from its own log."* This is the check that makes the drift visible.
 *
 * -------------------------------------------------------------------------------------------------
 * IT NEVER WRITES. THAT IS THE DESIGN.
 * -------------------------------------------------------------------------------------------------
 *
 * Repairing drift means writing `customers.total_club_point` — changing a customer's spendable
 * points. Points are money-adjacent, so an automatic correction is the owner's decision and is
 * reported as one rather than taken. It does not write a compensating ledger row either: a
 * reconciliation that filed its own findings into the log it audits would make the next run read
 * clean, which is the shape of a control that cannot fail.
 *
 * -------------------------------------------------------------------------------------------------
 * THE ANCHOR, AND WHY A SUM FROM ZERO WOULD BE USELESS
 * -------------------------------------------------------------------------------------------------
 *
 * A store that installed `RewardClubPoint` before this ledger existed has balances with no history.
 * Summing deltas from zero would report every such customer as having drifted by their whole
 * balance — thousands of false findings the day the ledger ships, which is how a control gets
 * switched off.
 *
 * So the chain is anchored on the **first row's `balance_before`**: the balance that existed the
 * moment this ledger first observed that customer. What happened before that is outside what the log
 * can vouch for, and {@see self::NO_HISTORY} says so rather than guessing.
 *
 * -------------------------------------------------------------------------------------------------
 * SIX STATUSES, BECAUSE THEY ARE DIFFERENT INVESTIGATIONS
 * -------------------------------------------------------------------------------------------------
 *
 * | status | what it means | a finding? |
 * |---|---|---|
 * | `UNOBSERVABLE`       | the vendored balance column is not installed, so there is nothing to compare against | no |
 * | `NO_HISTORY`         | the ledger holds no movement for this customer; it can vouch for nothing | no |
 * | `RECONCILED`         | the chain is internally consistent and ends at the live balance | no |
 * | `INCOMPLETE_HISTORY` | a movement could not see one of its own endpoints, so the chain cannot be walked | **yes** |
 * | `CHAIN_BROKEN`       | the rows disagree with EACH OTHER — a movement happened and was never logged | **yes** |
 * | `BALANCE_DRIFT`      | the rows agree, and the column does not — something wrote the balance unobserved | **yes** |
 *
 * `NO_HISTORY` and `UNOBSERVABLE` are not findings. Conflating either with drift is the
 * `(int) null === 0` hazard one level up: an absent reading reported as a wrong number.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY A TOLERANCE AND NOT EQUALITY
 * -------------------------------------------------------------------------------------------------
 *
 * The ledger's columns are `decimal(15,2)`; the authority it is compared against is a MySQL `double`
 * (`$table->float()` emits one — CLAUDE.md records a `FLOAT_PRECISION` caveat being attached and
 * WITHDRAWN on exactly that wording, so no precision defect is claimed from it). The award figure is
 * itself a division. Comparing two differently-represented numbers for exact equality would report
 * drift in the last bits, so the comparison is against {@see LoyaltyLedger::EPSILON} — half a cent,
 * which is below anything the ledger's own columns can represent.
 *
 * @see \App\Support\Loyalty\LoyaltyReconciliationVerdict
 */
final class LoyaltyReconciliation
{
    public const UNOBSERVABLE = 'unobservable';
    public const NO_HISTORY = 'no_history';
    public const RECONCILED = 'reconciled';
    public const INCOMPLETE_HISTORY = 'incomplete_history';
    public const CHAIN_BROKEN = 'chain_broken';
    public const BALANCE_DRIFT = 'balance_drift';

    /** The statuses a human should look at. See the class docblock's table. */
    public const FINDINGS = [self::INCOMPLETE_HISTORY, self::CHAIN_BROKEN, self::BALANCE_DRIFT];

    /**
     * Reconcile one customer's log against their balance.
     *
     * THE STORE PREDICATE IS EXPLICIT AT BOTH ENDS. Neither `loyalty_point_transactions` nor the
     * balance read is covered by a global scope that filters — the ledger model is absent from
     * `config/tenancy.php`'s `scoped_models`, and `null_tenant => 'open'` means `Customer`'s own
     * scope adds no predicate when no tenant resolves, which is the state of the console command
     * most likely to run this. `customers.id` values are reused across tenants in a shared
     * database, so an unfiltered read would splice two stores' chains together and report a break
     * in both.
     */
    public static function forCustomer(int $storeId, int $customerId): LoyaltyReconciliationVerdict
    {
        if (! LoyaltyBalance::available()) {
            return new LoyaltyReconciliationVerdict(status: self::UNOBSERVABLE, movements: 0);
        }

        $actual = LoyaltyBalance::forCustomer($storeId, $customerId);

        /* Oldest first: a chain is walked forwards. */
        $rows = LoyaltyLedger::available()
            ? LoyaltyPointTransaction::query()
                ->where('store_id', $storeId)
                ->where('customer_id', $customerId)
                ->orderBy('id')
                ->get()
            : collect();

        if ($rows->isEmpty()) {
            return new LoyaltyReconciliationVerdict(
                status: self::NO_HISTORY,
                movements: 0,
                /*
                 * The live balance is still reported. A merchant on the screen wants to see it even
                 * when the log cannot explain it, and `expected` stays null because nothing here
                 * vouches for a figure.
                 */
                actual: $actual,
            );
        }

        $movements = $rows->count();

        foreach ($rows as $row) {
            if ($row->balance_before === null || $row->balance_after === null) {
                return new LoyaltyReconciliationVerdict(
                    status: self::INCOMPLETE_HISTORY,
                    movements: $movements,
                    actual: $actual,
                    brokenAt: (int) $row->getKey(),
                );
            }
        }

        $anchor = (float) $rows->first()->balance_before;
        $cursor = $anchor;

        foreach ($rows as $row) {
            $before = (float) $row->balance_before;
            $after = (float) $row->balance_after;

            /*
             * TWO CHECKS PER LINK, and both are needed. The first asks whether this movement starts
             * where the last one finished — a gap there means a movement happened and was never
             * logged. The second asks whether the row's own arithmetic holds; a row claiming +5 while
             * moving +10 is only reachable from a writer that is not the observer, which is exactly
             * why it is asked.
             */
            if (abs($before - $cursor) >= LoyaltyLedger::EPSILON
                || abs(($before + (float) $row->points_delta) - $after) >= LoyaltyLedger::EPSILON) {
                return new LoyaltyReconciliationVerdict(
                    status: self::CHAIN_BROKEN,
                    movements: $movements,
                    actual: $actual,
                    anchor: $anchor,
                    brokenAt: (int) $row->getKey(),
                );
            }

            $cursor = $after;
        }

        $expected = round($cursor, 2);

        if ($actual === null) {
            /*
             * The chain is sound and the customer is gone from this store — a reconciliation cannot
             * be completed, and reporting it as drift would accuse a chain that is not at fault.
             */
            return new LoyaltyReconciliationVerdict(
                status: self::INCOMPLETE_HISTORY,
                movements: $movements,
                expected: $expected,
                anchor: $anchor,
            );
        }

        $difference = round($actual - $expected, 2);

        return new LoyaltyReconciliationVerdict(
            status: abs($difference) < LoyaltyLedger::EPSILON ? self::RECONCILED : self::BALANCE_DRIFT,
            movements: $movements,
            expected: $expected,
            actual: $actual,
            difference: $difference,
            anchor: $anchor,
        );
    }

    /**
     * A store-wide summary over the customers the ledger has actually seen.
     *
     * IT COUNTS AND DOES NOT SUM. A single "total points outstanding" figure would read as
     * authoritative while being only as good as its least-covered contributor — the same reason the
     * metric layer refuses a mixed-currency total — and it would be wrong by exactly the drift it is
     * meant to reveal. A count of findings is actionable and cannot be misread.
     *
     * CUSTOMERS WITH NO MOVEMENTS ARE NOT COUNTED. They are `NO_HISTORY` by definition, which is not
     * a finding, and including them would make this answer "how many customers does the store have"
     * on the day the ledger ships.
     *
     * @return array{customers: int, findings: int, by_status: array<string, int>}
     */
    public static function forStore(int $storeId, int $limit = 500): array
    {
        $summary = ['customers' => 0, 'findings' => 0, 'by_status' => []];

        if ($storeId <= 0 || ! LoyaltyLedger::available()) {
            return $summary;
        }

        $customerIds = DB::table('loyalty_point_transactions')
            ->where('store_id', $storeId)
            ->distinct()
            ->orderBy('customer_id')
            ->limit(max(1, $limit))
            ->pluck('customer_id');

        foreach ($customerIds as $customerId) {
            $verdict = self::forCustomer($storeId, (int) $customerId);

            $summary['customers']++;
            $summary['by_status'][$verdict->status] =
                ($summary['by_status'][$verdict->status] ?? 0) + 1;

            if ($verdict->isFinding()) {
                $summary['findings']++;
            }
        }

        return $summary;
    }
}
