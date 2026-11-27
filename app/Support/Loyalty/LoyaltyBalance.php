<?php

namespace App\Support\Loyalty;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `MKT-005` — THE CAPABILITY-GATED READER OF A BALANCE THAT MAY NOT EXIST.
 * =================================================================================================
 *
 * `customers.total_club_point` is declared by a **vendored** migration
 * (`packages/workdo/RewardClubPoint/src/database/migrations/2024_04_17_105551_…`) which only runs
 * when the module is installed. `GAP-191`, re-measured by this slice: host migrations declare it
 * **nowhere**, and `Schema::hasColumn('customers', 'total_club_point')` is **FALSE** in this suite.
 * Naming it unconditionally throws `no such column`.
 *
 * -------------------------------------------------------------------------------------------------
 * NULL IS NOT ZERO, AND THAT IS THE WHOLE CLASS
 * -------------------------------------------------------------------------------------------------
 *
 * The eleven existing first-party reads are all spelled
 * `(float) ($customerDetail->total_club_point ?? 0)`. That is correct for a DISCOUNT — no column, no
 * points, no discount — and it collapses two opposite facts:
 *
 *   `0.0`  this customer has no points
 *   `null` nobody can see whether they do, because the substrate is not installed
 *
 * A LEDGER cannot collapse them. A reconciliation reading an absent column as `0` would report every
 * customer in the store as having drifted to zero, and a movement row would claim a `balance_after`
 * of zero that nobody ever observed. CLAUDE.md records a test that passed because `(int) null` is
 * `0`; this is the same hazard with money-adjacent consequences.
 *
 * -------------------------------------------------------------------------------------------------
 * `Schema::hasColumn()` AND NOT `module_is_active()`
 * -------------------------------------------------------------------------------------------------
 *
 * CLAUDE.md: `module_is_active()` resolves its principal from `Auth::check()` first, so it answers
 * FALSE from a console command, a queue worker or a test body with nobody signed in while the
 * `add_on_managers` row reads TRUE. And the thing a read actually needs is the COLUMN, which
 * outlives the activation either way: a store that installs the module, earns points and then
 * deactivates it still has balances worth reading. This is the same gate, for the same reason, that
 * `SegmentDefinition::capabilityMet()` uses for the `min_loyalty_points` VIP rule.
 *
 * -------------------------------------------------------------------------------------------------
 * THERE IS NO WRITER HERE, DELIBERATELY
 * -------------------------------------------------------------------------------------------------
 *
 * Nothing in this slice writes a balance. The only current writer anywhere in this tree is the
 * vendored `AddRewardClubPointLis`, and adding a second authority over a money-adjacent column that
 * a first-party ledger could then disagree with is exactly the drift D4b warns about. See
 * {@see \App\Support\Loyalty\LoyaltyLedger}'s docblock.
 *
 * @see \App\Support\Loyalty\LoyaltyReconciliation
 */
final class LoyaltyBalance
{
    public const COLUMN = 'total_club_point';

    /**
     * Is the vendored balance column installed?
     *
     * `hasColumn()` HITS THE SCHEMA, so it is asked per call rather than memoised: a migration can
     * run between tests in one process, and a memoised answer would make the capability answer for
     * the previous test's schema. `SegmentDefinition::capabilityMet()` records the same reasoning.
     */
    public static function available(): bool
    {
        return Schema::hasColumn('customers', self::COLUMN);
    }

    /**
     * One customer's balance in one store, as the row actually holds it.
     *
     * Returns **null** when the balance is UNOBSERVABLE — the column is not installed, or no such
     * customer exists in that store. Returns `0.0` when the customer genuinely sits at zero. Those
     * are different answers and every caller here depends on the difference.
     *
     * THE STORE PREDICATE IS THE READER'S OWN. `Customer` IS one of the eleven models whose
     * `StoreScope` really filters, but `null_tenant => 'open'` means it adds NO predicate when no
     * tenant resolves — the state of a console command, a queue worker and a test body — so a reader
     * that leaned on it would answer with another store's customer of the same id. `customers.id`
     * values are reused across tenants in a shared database.
     *
     * RAW, AND NOT THROUGH THE MODEL, for three measured reasons. `Customer` is `Cachable`, and
     * CLAUDE.md records three false readings where an Eloquent read served a pre-write row — a
     * reconciliation must compare against what the table holds now, not against a cached instance.
     * `Customer` also soft-deletes, and a dispute about points is most likely to be raised about a
     * closed account, whose ledger rows do not disappear either. And the raw builder lets the column
     * be selected conditionally, which is what keeps the absent-column case from throwing.
     */
    public static function forCustomer(int $storeId, int $customerId): ?float
    {
        if (! self::available() || $storeId <= 0 || $customerId <= 0) {
            return null;
        }

        $value = DB::table('customers')
            ->where('store_id', $storeId)
            ->where('id', $customerId)
            ->value(self::COLUMN);

        /*
         * `value()` answers null both for "no such row" and for "the column is null". Both are
         * unobservable rather than zero: the vendored migration declares `->default(0)`, so a real
         * customer of this store reads a number.
         */
        return $value === null ? null : (float) $value;
    }

    /**
     * The balance carried by a `Customer` INSTANCE, for a caller that already holds one.
     *
     * {@see \App\Observers\CustomerLoyaltyPointObserver} is the caller: it is handed the model after
     * a write and must read the attribute off it, because the point of that observer is to see what
     * this very save did rather than to re-query.
     *
     * `getAttributes()` AND NOT `->total_club_point`. On a model whose row has no such column the
     * magic accessor answers null, which is indistinguishable from a null value — and this class
     * exists to keep those apart. `array_key_exists` on the loaded attributes asks the right
     * question: did the row this instance came from carry the column at all.
     */
    public static function observedOn(Customer $customer): ?float
    {
        if (! array_key_exists(self::COLUMN, $customer->getAttributes())) {
            return null;
        }

        $value = $customer->getAttributes()[self::COLUMN];

        return $value === null ? null : (float) $value;
    }

    /**
     * The balance this `Customer` instance held BEFORE the save being observed.
     *
     * `getOriginal()` on an attribute the row does not carry answers null, so the same
     * `array_key_exists` discipline applies — against `getRawOriginal()`, which is the pre-save
     * attribute array rather than a cast single value.
     */
    public static function observedOriginalOn(Customer $customer): ?float
    {
        $original = $customer->getRawOriginal();

        if (! is_array($original) || ! array_key_exists(self::COLUMN, $original)) {
            return null;
        }

        $value = $original[self::COLUMN];

        return $value === null ? null : (float) $value;
    }
}
