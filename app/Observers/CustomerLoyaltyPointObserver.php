<?php

namespace App\Observers;

use App\Models\Customer;
use App\Support\Loyalty\LoyaltyAttribution;
use App\Support\Loyalty\LoyaltyBalance;
use App\Support\Loyalty\LoyaltyLedger;

/**
 * `MKT-005` — «سجل حركات». THE CHOKEPOINT: EVERY MOVEMENT OF A LOYALTY BALANCE BECOMES A LEDGER ROW.
 * =================================================================================================
 *
 * -------------------------------------------------------------------------------------------------
 * WHY HERE, MEASURED BY READING THE VENDORED WRITER
 * -------------------------------------------------------------------------------------------------
 *
 * `packages/workdo/RewardClubPoint/src/app/Listeners/AddRewardClubPointLis::handle()` moves the
 * balance with two statements — an assignment on a `Customer` instance and `$customer->save()`. It
 * is the only writer of that column anywhere in this tree (measured: all eleven first-party
 * references are reads that spend it). An ordinary Eloquent save fires `updated`, so this observer
 * sees it.
 *
 * Three reasons this is the right seam, each of which rules out an alternative:
 *
 * 1. **IT SEES THE REAL BEFORE AND AFTER**, so no vendored arithmetic is re-derived. The award is
 *    `product_price * order_points / order_point_price` inside the module; computing it again here
 *    would be the *"second copy of a decision the host already makes elsewhere"* the project skill
 *    forbids, and it would drift the first time upstream changed a rounding.
 * 2. **A LISTENER ON `AddRewardClubPoint` COULD NOT SEE THE RESULT.** Module providers are brought
 *    in from `app->booted()`, so every first-party listener runs strictly earlier — that ordering is
 *    measured in `NormaliseRewardRedemptionFlag`'s docblock and is exactly why that listener can
 *    rewrite the flag the vendored one then reads. Earlier is the wrong end for an observer.
 * 3. **IT CATCHES WRITERS THAT DO NOT EXIST YET.** The Khalti redemption already takes a discount
 *    and fires no event at all (`PHASE-05-OWNER-DECISION-PACKAGE.md` §2.5), so an event-shaped log
 *    would have a known blind spot on day one.
 *
 * NOT IN `app/Listeners`, on purpose. Event discovery is ON and registers everything there with a
 * typed `handle()` under a `Class@handle` key, which CLAUDE.md records as a source of double
 * registration and of tests that cannot see what is registered. This is registered with
 * `Customer::observe()` in `AppServiceProvider::boot()`, beside `Order::observe(OrderObserver::class)`.
 *
 * -------------------------------------------------------------------------------------------------
 * IT WRITES ONE TABLE AND ONE TABLE ONLY
 * -------------------------------------------------------------------------------------------------
 *
 * The only write is an INSERT into `loyalty_point_transactions`. It never writes
 * `customers.total_club_point`, never corrects it, never rounds it. Points are money-adjacent and
 * this slice has no authority over them — making the ledger authoritative would change the input of
 * all eleven spend paths and therefore change customer-visible balances, which is the owner's
 * decision and is reported as one. So:
 *
 *   * a store WITHOUT the vendored module behaves exactly as it does today (the column is absent,
 *     {@see LoyaltyBalance::available()} is false, and this returns on its first line);
 *   * a store WITH it has no balance changed by any of this.
 *
 * -------------------------------------------------------------------------------------------------
 * IT CANNOT FAIL THE REQUEST IT OBSERVES
 * -------------------------------------------------------------------------------------------------
 *
 * It runs inside payment confirmation, after the balance is already written. CLAUDE.md records a
 * post-commit insert throwing on that path and leaving a half-written state at HTTP 500 — discount
 * granted, balance unchanged, no ledger row, repeatable. So the write goes through
 * {@see LoyaltyLedger::observe()}, which reports and swallows. A log that can take a payment down is
 * a worse defect than a missing log line.
 *
 * -------------------------------------------------------------------------------------------------
 * FOUR LAYERS GUARD "NOTHING MOVED", AND SINGLE-GATE MUTATIONS SURVIVE BECAUSE OF IT
 * -------------------------------------------------------------------------------------------------
 *
 * Saying so here because a mutation group that does not explain its survivors is worse than none.
 * Measured: deleting {@see LoyaltyBalance::available()} below, and separately deleting the
 * `wasChanged()` gate, BOTH survive their tests. That is not a weak test — it is four independent
 * refusals in series, each of which alone is sufficient:
 *
 *   1. `LoyaltyBalance::available()`  — no vendored column, so nothing to observe.
 *   2. `wasChanged(COLUMN)`           — this save did not touch it.
 *   3. `$before === null || $after === null` — an endpoint nobody could see.
 *   4. `abs($delta) < EPSILON` here, and {@see LoyaltyLedger::record()}'s own refusal behind it.
 *
 * With the column ABSENT, (1) and (2) both answer; with it present and another column edited, (2),
 * (3) and (4) all answer. Layer 1 is kept although nothing depends on it alone, for the reason
 * `NormaliseRewardRedemptionFlag` keeps its redundant registration: it is the one line that STATES
 * the bounding constraint ("a store without the module behaves exactly as today") where a reader
 * will look for it. `mutate-mkt005-ledger.py` carries the measurement and a combined mutation that
 * removes the pair, which IS caught.
 *
 * @see \Tests\Feature\Loyalty\LoyaltyPointLedgerObserverTest
 */
class CustomerLoyaltyPointObserver
{
    public const SOURCE = 'customer-observer';

    /**
     * A customer created already holding points.
     *
     * Rare — the vendored column declares `->default(0)` and no first-party path sets it at
     * creation — but if it happens it is the opening entry of that customer's history, and a history
     * whose first movement is missing cannot be reconciled.
     *
     * `balance_before` IS `0.0` HERE AND NOT NULL, which is the one place in this slice where zero is
     * the right answer for an absent thing: the customer did not exist, so they held no points. That
     * is a fact about the world, not an unobservable. It also gives the reconciliation its anchor.
     */
    public function created(Customer $customer): void
    {
        if (! LoyaltyBalance::available()) {
            return;
        }

        $opening = LoyaltyBalance::observedOn($customer);

        if ($opening === null) {
            /*
             * The insert did not name the column, so the row carries the database default and this
             * instance has no attribute for it. Nothing moved that anyone can see.
             */
            return;
        }

        $this->log($customer, before: 0.0, after: $opening);
    }

    /** A balance that moved. */
    public function updated(Customer $customer): void
    {
        if (! LoyaltyBalance::available()) {
            return;
        }

        /*
         * `wasChanged()` AND NOT A BARE COMPARISON. It is false for a save that touched some other
         * column — a merchant renaming a customer must not put a row in an audit log — and it is
         * false for a rewrite to the same figure, which the vendored listener performs on every
         * qualifying order. Laravel compares numerics by value here, so `100.0` written over `100`
         * is correctly not a change.
         */
        if (! $customer->wasChanged(LoyaltyBalance::COLUMN)) {
            return;
        }

        $before = LoyaltyBalance::observedOriginalOn($customer);
        $after = LoyaltyBalance::observedOn($customer);

        if ($before === null || $after === null) {
            /*
             * Unobservable at one end, so no delta can be stated. Recording a movement with an
             * invented endpoint would put a figure in the log that nobody ever saw, which is the
             * `(int) null === 0` hazard LoyaltyBalance exists to keep out.
             */
            return;
        }

        $this->log($customer, before: $before, after: $after);
    }

    /**
     * Turn an observed before/after into exactly one ledger row.
     *
     * THE KIND COMES FROM THE OBSERVED DELTA, and the limit of that is stated rather than hidden.
     * The vendored redemption branch REPLACES the balance with just that order's award, so one save
     * can carry both a full-balance redemption and a fresh award and what is observable is their
     * NET. A customer at 500 who redeems and earns 10 is logged as `redeem −490`; one at 5 who
     * redeems and earns 10 is logged as `earn +5`, with `redemption_claimed` true beside it. Both
     * rows carry the real `balance_before` and `balance_after`, so an auditor reads what happened
     * from the row whatever the label says. Splitting one save into two synthetic rows would need
     * the module's own arithmetic, which is the drift this design refuses.
     */
    private function log(Customer $customer, float $before, float $after): void
    {
        $delta = $after - $before;

        if (abs($delta) < LoyaltyLedger::EPSILON) {
            return;
        }

        $storeId = (int) ($customer->store_id ?? 0);
        $customerId = (int) $customer->getKey();

        /*
         * THE CUSTOMER'S OWN STORE, never `getCurrentStore()`. That helper reads the default (`web`)
         * guard and returns the hardcoded literal `1` for a `super admin` with an empty
         * `current_store`, and `null_tenant => 'open'` means the global scope adds no predicate at
         * all when nobody resolves — so either would file a store-7 customer's movement under store
         * 1, where the owning merchant could never see it and another merchant could. A storeless
         * customer is skipped rather than filed under nothing: `where('store_id', null)` becomes
         * `whereNull`, so such a row is matched by no reader and present in the table.
         */
        if ($storeId <= 0 || $customerId <= 0) {
            return;
        }

        $note = app(LoyaltyAttribution::class)->claimFor($customerId);

        LoyaltyLedger::observe(
            storeId: $storeId,
            customerId: $customerId,
            /*
             * An unattributed movement is an `adjust`, NOT a dropped row. This is what makes a
             * balance change from outside the known paths visible; a ledger that only recorded
             * movements it could explain would be silent in exactly the case a merchant most needs
             * it.
             */
            kind: $note === null
                ? LoyaltyLedger::ADJUST
                : ($delta > 0 ? LoyaltyLedger::EARN : LoyaltyLedger::REDEEM),
            delta: $delta,
            balanceBefore: $before,
            balanceAfter: $after,
            source: self::SOURCE,
            orderId: $note['order_id'] ?? null,
            redemptionClaimed: (bool) ($note['redemption_claimed'] ?? false),
        );
    }
}
