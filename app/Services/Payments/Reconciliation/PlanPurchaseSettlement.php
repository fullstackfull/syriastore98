<?php

namespace App\Services\Payments\Reconciliation;

use App\Http\Controllers\Concerns\GuardsPlanActivation;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\PlanCoupon;
use App\Models\PlanOrder;
use App\Models\PlanUserCoupon;
use App\Models\User;
use App\Services\PlanPurchaseIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TURN ONE VERIFIED, MATCHING PLAN PURCHASE INTO ITS RECORD AND ITS ENTITLEMENT -- EXACTLY ONCE,
 * OR NOT AT ALL.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * NO RULE IN THIS CLASS IS NEW. It is the paid Paytm return's sequence, run where no browser is.
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `PaytmPaymentController::getPaymentStatus()` already defines what a verified plan purchase
 * becomes: the intent is consumed by one conditional UPDATE, a `plan_orders` row is written from
 * the INTENT's figures, `User::assignPlan()` grants the entitlement, the order is attached to the
 * intent only once the entitlement landed, and the coupon the purchase used is counted. Every one
 * of those steps is here and every one calls the same engine: `PlanPurchaseIntent` for the
 * consumption and the attachment, `assignPlan()` for the entitlement, and `GuardsPlanActivation`
 * -- the trait the controllers use -- for the coupon lookup and the one-transaction-one-order
 * lookup. Nothing is re-expressed. What differs is the three things a session-less, unattended
 * door needs and a browser return never had.
 *
 * 1. IT IS ONE DATABASE TRANSACTION. The browser return consumes the intent, then writes the
 *    order, then assigns the plan, as three separate commits -- which is where requirement 5's
 *    "captured payment, intent spent, nothing to show" state comes from (`unreconciled()`'s third
 *    clause exists for it). A reconciliation that could leave that state behind would be
 *    manufacturing the thing it was built to repair. Here a failure at ANY step rolls the whole
 *    settlement back, the consumption included, so the intent is `pending` again and the next run
 *    can try again. Rolling back the consumption does not reopen a spent payment: nothing was
 *    granted, because the grant rolled back with it.
 *
 * 2. THE ORDER'S ID IS THE INTENT'S REFERENCE. The return uses `time()` -- F228 measured two
 *    purchases a second apart colliding on it. `plan_orders.order_id` is UNIQUE, so keying the
 *    order on the reference makes "one intent, one order" a database fact as well as a consequence
 *    of the conditional UPDATE. Two independent refusals, either of which is sufficient.
 *
 * 3. IT CHECKS WHAT IT IS ABOUT TO NEED BEFORE IT SPENDS ANYTHING. The purchaser and the plan are
 *    read BEFORE the intent is consumed. The F241 callback consumed first and then discovered the
 *    purchaser was gone, which left a consumed intent nobody could retry; inside a transaction that
 *    would roll back anyway, but a refusal that never wrote is simpler to reason about than one
 *    that wrote and undid it.
 *
 * WHAT IT DOES NOT DO. No refund, no reversal, no wallet movement, no stock movement, no
 * `store_order_financials` row: a plan purchase is paid to the platform and touches none of them
 * (`PaytmPlanCallbackTest::test_the_plan_callback_writes_no_wallet_or_order_financial_entry` pins
 * that for the callback, and the reconciliation's own test pins it here). It does not decide
 * whether a suspended purchaser should hold a plan -- suspension is enforced where access is
 * granted (`AccountSuspension`), not where money is recorded, and withholding the record of a
 * captured payment would be a settlement rule nobody approved.
 */
final class PlanPurchaseSettlement
{
    use GuardsPlanActivation;

    /** The audit action for a settlement. A constant because a reader will query on it. */
    public const AUDIT_SETTLED = 'payment.reconciliation.settled';

    /** The audit action for a confirmed capture this application could not settle. */
    public const AUDIT_DISCREPANCY = 'payment.reconciliation.discrepancy';

    /** `audit_logs.subject_type` for an intent. A plain string: the intent is a row, not a model. */
    public const AUDIT_SUBJECT = 'plan_purchase_intent';

    /**
     * CAN THIS INTENT BE SETTLED AT ALL? Null when it can; otherwise the outcome that says why not.
     *
     * A pure read, shared by the dry run and the settlement so that "would settle" and "settled"
     * cannot disagree about a missing purchaser, a deleted plan or a transaction that is already
     * somebody's order.
     */
    public function preflight(object $intent, CaptureAnswer $answer): ?ReconciliationOutcome
    {
        $acknowledgement = $this->acknowledgementOn($intent);

        if ($acknowledgement !== null) {
            /*
             * A PERSON HAS DEALT WITH THIS PAYMENT. Refused here so that it is never reported as a
             * fresh discrepancy and never audited as one again -- first, before the discrepancy
             * checks below.
             *
             * THIS IS NOT THE CONTROL, AND AN EARLIER VERSION OF THIS COMMENT CLAIMED IT WAS. It
             * said this guard was what the browser return comes through. That was false, and
             * independent review measured it: no plan browser return reaches this class at all.
             * `PaytmPaymentController:489`, `CashfreeController:291` and `MolliePaymentController:269`
             * each call `PlanPurchaseIntent::verifyAndConsume()` or `consumeForPlanBinding()`
             * directly, so a purchaser re-presenting their own signed return body would have settled
             * an acknowledged payment -- money that may already have been refunded -- and this guard
             * would never have run. Only `PaymentCallbackController` settles through here, and that
             * route ships closed.
             *
             * THE CONTROL IS NOW THE CONSUMPTION ITSELF: both conditional UPDATEs in
             * `PlanPurchaseIntent` carry `acknowledged_at IS NULL` beside `status = pending`, which
             * is the one chokepoint every door funnels through and is atomic rather than
             * advisory. This stays because a refusal that names the reason beats a refusal that
             * reads "already consumed".
             */
            return new ReconciliationOutcome(
                ReconciliationOutcome::ACKNOWLEDGED,
                'a person recorded what was done about this payment: ' . $acknowledgement,
                ['provider_txn_id' => trim((string) $answer->transactionId)],
            );
        }

        $transactionId = trim((string) $answer->transactionId);

        if ($transactionId === '') {
            /*
             * NO PROVIDER TRANSACTION ID, NO SETTLEMENT. `plan_orders.txn_id` is how every other
             * door recognises a payment it has already recorded (`refusePlanActivationReplay()`),
             * and how an operator matches a row to a provider statement. An order written without
             * one could be neither refused as a replay nor reconciled afterwards.
             */
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                'the provider confirmed the payment but returned no transaction identifier',
                [],
                ReconciliationOutcome::NO_TRANSACTION_ID,
            );
        }

        if (! is_numeric($intent->user_id ?? null) || User::disableCache()->find((int) $intent->user_id) === null) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                'the purchaser this intent names no longer exists',
                ['provider_txn_id' => $transactionId],
                ReconciliationOutcome::PURCHASER_MISSING,
            );
        }

        if (! is_numeric($intent->plan_id ?? null) || Plan::disableCache()->find((int) $intent->plan_id) === null) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                'the plan this intent names no longer exists',
                ['provider_txn_id' => $transactionId],
                ReconciliationOutcome::PLAN_MISSING,
            );
        }

        /*
         * ONE PROVIDER TRANSACTION, ONE PLAN ORDER -- whoever wrote the first one.
         *
         * A `pending` intent beside an order that already carries its transaction id is not a state
         * the intent-bound doors can produce (they consume before they write), so something else
         * recorded this capture. Whatever it was, a second order for the same money is the one
         * outcome that must not happen; a person decides what the first one means.
         */
        $existing = $this->existingPlanOrderForProviderReference(
            (string) $intent->provider,
            self::storableTransactionId($transactionId)
        );

        if ($existing !== null && (string) $existing->order_id !== (string) $intent->reference) {
            /*
             * `order_id !== reference` IS WHAT MAKES THIS A FOREIGN ORDER. The financial review found
             * that without it this refused the order THIS INTENT had itself produced — a second run
             * after a successful settlement reported a discrepancy and audited one, instead of
             * reporting that there was nothing left to do.
             */
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                'this provider transaction is already recorded as a plan order',
                [
                    'provider_txn_id' => $transactionId,
                    'existing_plan_order_id' => $existing->id,
                    'existing_order_same_purchaser' => (int) $existing->user_id === (int) $intent->user_id,
                    'existing_order_same_plan' => (int) $existing->plan_id === (int) $intent->plan_id,
                ],
                ReconciliationOutcome::TRANSACTION_ALREADY_RECORDED,
            );
        }

        return $this->entitlementHasMovedOn($intent);
    }

    /**
     * HAS ANYTHING HAPPENED TO THIS PURCHASER'S ENTITLEMENT SINCE THE PAYMENT WAS INITIATED?
     *
     * THE SHARPEST FINDING OF THE INDEPENDENT FINANCIAL REVIEW, confirmed by execution three ways.
     * `assignPlan()` SETS the plan and recomputes `plan_expire_date` from `Carbon::now()`; it does
     * not accumulate. It also switches off stores and staff beyond the new plan's limits. So
     * applying a days-old payment is not "recording a purchase that happened" — it is changing what
     * the purchaser holds TODAY:
     *
     *   - a three-day-old intent for the 100/Month plan DOWNGRADED a buyer who had since bought the
     *     999/Year one, moved their expiry a year closer, and switched off two of their three
     *     active stores;
     *   - a purchase an operator had already granted by hand was granted AGAIN, pushing the expiry
     *     out by a month on one payment;
     *   - two captured intents of one purchaser both settled: two sales recorded, one month of
     *     service granted, and the duplicate payment invisible afterwards.
     *
     * None of that is a defect in the engine — it is what applying a late payment MEANS — which is
     * exactly why it is not engineering's to decide. **The honest action is to refuse and record.**
     * The intent stays open, the money stays visible, and a person decides whether this purchase
     * should be applied, refunded, or left.
     *
     * THE TEST IS ABOUT WHAT CHANGED, NOT ABOUT HOW OLD THE INTENT IS. A purchaser who has done
     * nothing since initiation settles normally however long ago it was, because nothing would be
     * overwritten. `disableCache()` on both reads: 79 of 95 models here are `Cachable` and an
     * entitlement decision must not be made against a cached row.
     */
    private function entitlementHasMovedOn(object $intent): ?ReconciliationOutcome
    {
        $purchaser = User::disableCache()->find((int) $intent->user_id);

        if ($purchaser === null) {
            return null;
        }

        $holdsAnotherPlan = is_numeric($purchaser->plan_id)
            && (int) $purchaser->plan_id > 0
            && (int) $purchaser->plan_id !== (int) $intent->plan_id;

        /*
         * A plan sale recorded for this purchaser AFTER the payment was initiated. Whoever wrote it
         * -- another provider, another reconciliation, the operator by hand -- it is newer than this
         * purchase and settling now would overwrite its effect.
         *
         * EXCEPT THE ORDER THIS INTENT ITSELF WROTE, and leaving that out was a defect of mine that
         * `verify-plan-payment-reconciliation-on-mysql.php` caught on its first run. After a
         * successful settlement the purchaser holds this plan and this intent's own order is the
         * newest one, so a provider retry or a second run reported
         * `DISCREPANCY/ENTITLEMENT_MOVED_ON` -- and would have written an audit row saying a
         * person must look at a purchase that had completed perfectly. `order_id` is the intent's
         * reference precisely so its own order is identifiable.
         */
        $newerOrder = PlanOrder::disableCache()
            ->where('user_id', (int) $intent->user_id)
            ->where('payment_status', 'success')
            ->where('created_at', '>', $intent->created_at)
            ->where('order_id', '!=', (string) $intent->reference)
            ->orderByDesc('id')
            ->first();

        if (! $holdsAnotherPlan && $newerOrder === null) {
            return null;
        }

        return new ReconciliationOutcome(
            ReconciliationOutcome::DISCREPANCY,
            'the purchaser\'s entitlement has changed since this payment was initiated, so applying '
            . 'it now would overwrite what they hold rather than record what they bought',
            [
                'provider_txn_id' => null,
                'intent_plan_id' => $intent->plan_id,
                'purchaser_holds_plan_id' => $purchaser->plan_id,
                'purchaser_plan_expires' => $purchaser->plan_expire_date,
                'newer_plan_order_id' => $newerOrder->id ?? null,
                'newer_plan_order_at' => $newerOrder->created_at ?? null,
            ],
            ReconciliationOutcome::ENTITLEMENT_MOVED_ON,
        );
    }

    /**
     * Settle `$intent` on the strength of `$answer`. All of it, or none of it.
     *
     * @param  ?User  $actor  the operator who asked, when there is one; a console run has none
     */
    public function settle(object $intent, CaptureAnswer $answer, ?User $actor = null, string $channel = 'console'): ReconciliationOutcome
    {
        if (! $answer->isVerified()) {
            /* A caller's bug, not a provider's answer -- and the direction for a bug on a money
               path is "nothing happens". */
            return new ReconciliationOutcome(
                ReconciliationOutcome::UNVERIFIABLE,
                'settlement was requested without a verified capture',
            );
        }

        try {
            return DB::transaction(function () use ($intent, $answer, $actor, $channel) {
                return $this->settleInsideTransaction($intent, $answer, $actor, $channel);
            });
        } catch (\Throwable $theSettlementRaised) {
            /*
             * EVERYTHING ABOVE ROLLED BACK, the consumption included. The intent is exactly as it
             * was, so this is retryable -- and it is logged at `error` because a verified, matching
             * payment that would not settle is a fault somebody has to find, not a refusal.
             */
            Log::error('Payment reconciliation: a verified plan purchase could not be settled and was rolled back.', [
                'provider' => $intent->provider ?? null,
                'reference' => $intent->reference ?? null,
                'intent_id' => $intent->id ?? null,
                'reason' => $theSettlementRaised->getMessage(),
            ]);

            return new ReconciliationOutcome(
                ReconciliationOutcome::SETTLEMENT_FAILED,
                'the settlement raised and was rolled back: ' . $theSettlementRaised->getMessage(),
                ['provider_txn_id' => $answer->transactionId],
            );
        }
    }

    private function settleInsideTransaction(object $intent, CaptureAnswer $answer, ?User $actor, string $channel): ReconciliationOutcome
    {
        $blocked = $this->preflight($intent, $answer);

        if ($blocked !== null) {
            return $blocked;
        }

        $provider = (string) $intent->provider;
        $reference = (string) $intent->reference;
        $transactionId = trim((string) $answer->transactionId);

        /*
         * THE CONSUMPTION -- the existing method, with every control it already has: the amount and
         * currency compared against the intent by `PlanPurchaseIntent::compare()`, and ONE
         * conditional `UPDATE ... WHERE status = 'pending'`. Two runs, or a run and a buyer's own
         * return, cannot both win it. Inside this transaction the row lock it takes is held until
         * commit, so a concurrent door waits and then finds the intent spent.
         */
        /*
         * `$consumed` IS THE ROW, AND IT IS WHAT EVERYTHING BELOW IS BUILT FROM.
         *
         * The financial review found that this used `$intent` -- the object the CALLER handed in --
         * for the purchaser and the plan, while the amount had been verified against the row. An
         * object naming a different purchaser and a dearer plan therefore settled: the bystander
         * got the plan and the order, the consumed row named somebody else, and the real purchaser
         * got nothing. Latent, because both callers pass a freshly-read row -- and a money path
         * must not depend on its callers being careful. The row the conditional UPDATE matched is
         * the only authority there is.
         */
        [$state, $consumed, $reason] = PlanPurchaseIntent::consumeForProviderCallback(
            $provider,
            $reference,
            (string) $answer->amount,
            (string) ($answer->currency ?? ''),
            $transactionId
        );

        if ($state !== PlanPurchaseIntent::VERIFIED || $consumed === null) {
            return $this->outcomeForUnconsumedIntent($provider, $reference, $state, $reason, $transactionId);
        }

        $intent = $consumed;

        /* Read after the consumption, so these are the rows the entitlement is granted on. */
        $purchaser = User::disableCache()->find((int) $intent->user_id);
        $plan = Plan::disableCache()->find((int) $intent->plan_id);

        if ($purchaser === null || $plan === null) {
            throw new \RuntimeException('the purchaser or the plan disappeared between the check and the settlement');
        }

        $order = new PlanOrder();
        /* THE INTENT'S OWN REFERENCE, not the clock. `order_id` is UNIQUE, so this intent can never
           own a second order whatever else goes wrong. See point 2 of the class header. */
        $order->order_id = $reference;
        $order->name = (string) ($purchaser->name ?? '');
        $order->card_number = '';
        $order->card_exp_month = '';
        $order->card_exp_year = '';
        $order->plan_name = $plan->name;
        $order->plan_id = $plan->id;
        /* THE FIGURES COME FROM THE INTENT -- the server's own record of what it advertised and
           charged, which the provider's answer has just been matched against. The same source the
           browser return records from, and for the reason it gives. */
        $order->price = $intent->expected_amount;
        $order->price_currency = (string) $intent->currency;
        /* THE STORED VALUE IS THE CUT ONE, and `existingPlanOrderForProviderReference()` looks up
           the FULL id -- so a provider id longer than the column would make the one-transaction-one-
           order check blind to its own row. Cut once, here, and the preflight above is given the
           same value. Theoretical for Paytm's 35 characters; found by the financial review. */
        $order->txn_id = self::storableTransactionId($transactionId);
        /* The CANONICAL provider name, which is the first spelling `refusePlanActivationReplay()`
           searches. A console run has no buyer locale to translate it into. */
        $order->payment_type = $provider;
        $order->payment_status = 'success';
        $order->receipt = '';
        $order->user_id = $purchaser->id;
        $order->save();

        $assigned = $purchaser->assignPlan($plan->id);

        if (empty($assigned['is_success'])) {
            /* Thrown, not returned: the order above and the consumption must not survive an
               entitlement that did not land, and only an exception rolls this transaction back. */
            throw new \RuntimeException('assignPlan did not succeed: ' . (string) ($assigned['error'] ?? 'no reason given'));
        }

        /* Attached only now, so `plan_order_id` keeps meaning "this purchase completed". */
        PlanPurchaseIntent::attachOrder($provider, $reference, $order->id);

        $this->countTheCouponThePurchaseUsed($intent, $purchaser, $order);

        $outcome = new ReconciliationOutcome(ReconciliationOutcome::SETTLED, '', [
            'provider_txn_id' => $transactionId,
            'plan_order_id' => $order->id,
            'purchaser_id' => $purchaser->id,
            'plan_id' => $plan->id,
            'amount' => (string) $intent->expected_amount,
            'currency' => (string) $intent->currency,
        ]);

        self::audit(self::AUDIT_SETTLED, $intent, $outcome, $actor, $channel);

        $this->flushTheEntitlementCacheAfterCommit();

        Log::info('Payment reconciliation settled a verified plan purchase.', [
            'provider' => $provider,
            'reference' => $reference,
            'plan_order_id' => $order->id,
            'user_id' => $purchaser->id,
            'plan_id' => $plan->id,
        ]);

        return $outcome;
    }

    /**
     * The consumption did not win. Say which of the things that can mean it was.
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════
     * THE RE-READ MUST ESCAPE THIS TRANSACTION'S SNAPSHOT, AND THE FIRST VERSION DID NOT.
     * ══════════════════════════════════════════════════════════════════════════════════════════
     *
     * Measured by the independent financial review on MariaDB with two connections: the loser of a
     * real race was reported and AUDITED as an `AMOUNT` discrepancy -- a false record, and a run
     * that exits 1 -- because under REPEATABLE READ this plain `find()` read the snapshot taken
     * before the winner committed, so the row still looked `pending` and the code fell through to
     * the mismatch arm. `ALREADY_SETTLED` was unreachable on the engine production runs.
     *
     * `lockForUpdate()` is what makes the read current: it waits for the winner's transaction and
     * then reads the committed row. On SQLite it compiles to nothing and the plain read is already
     * current, so the suite's sequential cases are unaffected either way -- which is exactly why
     * this needed a real engine to find.
     *
     * AND THE DEFAULT ARM IS NO LONGER `AMOUNT`. A state this method cannot account for is reported
     * with no discrepancy code rather than as a mismatching amount, because an amount discrepancy
     * is the one that CLOSES the intent.
     */
    /**
     * WHAT A PERSON RECORDED ABOUT THIS INTENT, or null when nobody has recorded anything.
     *
     * READS THE ROW IT WAS HANDED, AND AN EARLIER VERSION RE-READ IT UNDER A LOCK. That re-read was
     * written to close a race between an operator acknowledging and a settlement in flight, and
     * independent review was right that it did not: `findForUpdate()` swallows every throwable and
     * returns null, so a lock-wait timeout degraded the guard to the possibly-stale object it
     * existed to distrust -- fail-open, on the money path, untested because every caller passes a
     * freshly-read row.
     *
     * It is gone rather than fixed because that race is now closed where it belongs. Both
     * conditional UPDATEs in `PlanPurchaseIntent` carry `acknowledged_at IS NULL`, so an
     * acknowledgement committed at any moment before the consumption makes the consumption lose its
     * claim -- atomically, in the one statement, for every door. A second lock here would be a
     * weaker copy of a control that already holds.
     *
     * `?? null` on both reads, so a database migrated before `2026_11_25_000200` -- table present,
     * columns absent -- answers "not acknowledged" and settles exactly as it did before.
     */
    private function acknowledgementOn(object $intent): ?string
    {
        $at = $intent->acknowledged_at ?? null;

        if ($at === null) {
            return null;
        }

        return (string) $at . ': ' . (string) ($intent->acknowledged_reason ?? 'no reason recorded');
    }

    private function outcomeForUnconsumedIntent(string $provider, string $reference, string $state, string $reason, string $transactionId): ReconciliationOutcome
    {
        $now = PlanPurchaseIntent::findForUpdate($provider, $reference);

        if ($now !== null && $now->status === PlanPurchaseIntent::CONSUMED) {
            /* Another door settled it between this run reading the intent and writing it. That is
               the conditional UPDATE doing its job, and there is nothing left to do. */
            return new ReconciliationOutcome(
                ReconciliationOutcome::ALREADY_SETTLED,
                'the intent was consumed by another door before this run could settle it',
                ['provider_txn_id' => $transactionId, 'plan_order_id' => $now->plan_order_id ?? null],
            );
        }

        if ($now !== null && $now->status === PlanPurchaseIntent::REFUSED) {
            /* Closed by another door, with its own reason already on the row. Not this run's to
               relabel -- and the first version overwrote that reason with its own. */
            return new ReconciliationOutcome(
                ReconciliationOutcome::NOT_ELIGIBLE,
                'the intent was refused by another door before this run could settle it',
                ['provider_txn_id' => $transactionId],
            );
        }

        if ($state === PlanPurchaseIntent::UNVERIFIABLE) {
            return new ReconciliationOutcome(ReconciliationOutcome::UNVERIFIABLE, $reason, ['provider_txn_id' => $transactionId]);
        }

        if ($state === PlanPurchaseIntent::REFUSED_RECOVERABLE) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                $reason,
                ['provider_txn_id' => $transactionId],
                ReconciliationOutcome::CURRENCY,
            );
        }

        if ($state === PlanPurchaseIntent::REFUSED_MISMATCH && str_contains($reason, 'amount')) {
            return new ReconciliationOutcome(
                ReconciliationOutcome::DISCREPANCY,
                $reason,
                ['provider_txn_id' => $transactionId],
                ReconciliationOutcome::AMOUNT,
            );
        }

        /* Anything else: reported, recorded, and NOT closed. */
        return new ReconciliationOutcome(
            ReconciliationOutcome::DISCREPANCY,
            $reason,
            ['provider_txn_id' => $transactionId],
        );
    }

    /** The provider transaction id as `plan_orders.txn_id` can hold it. */
    private static function storableTransactionId(string $transactionId): string
    {
        return substr($transactionId, 0, 100);
    }

    /**
     * FLUSH THE MODEL CACHE AFTER THE COMMIT, NOT DURING IT.
     *
     * MEASURED BY THE INDEPENDENT FINANCIAL REVIEW, on MariaDB with production's
     * `CACHE_STORE=database` and three processes: the settlement's own saves flush `Cachable` while
     * the transaction is still open, so a concurrent reader blocked on the in-transaction
     * `DELETE FROM cache`, then read and CACHED the pre-settlement row — and a fresh process
     * afterwards read `User::find()` as `plan_id` NULL while `users.plan_id` was 1 and the expiry a
     * month out. The buyer keeps the old plan and the old module set until something else happens
     * to flush the store.
     *
     * The repository already holds this rule, its reasoning and a real-engine proof for the theme
     * projection (`ThemeConfigurationService::flushProjectionCacheAfterCommit()`, and
     * `verify-theme-cache-flush-after-commit-on-mysql.php`). This is the same shape on the
     * entitlement path, so it gets the same treatment rather than a second argument.
     *
     * EVERY MODEL THE SETTLEMENT WRITES, not just `User`: `assignPlan()` also saves the purchaser's
     * stores and staff and writes `user_active_modules`, and the order and the coupon rows are read
     * back by other surfaces. A flush that covered one of them would leave the rest stale and would
     * read as if the question had been handled.
     *
     * `flushCache()` on a non-taggable store is `Cache::flush()`, so this erases the whole store —
     * including, as the review also measured, an unrelated in-flight `gateway-return-claim:*` key.
     * That is the pre-existing F194 behaviour of this project's model cache and not something this
     * method can fix; what it can do is not make it worse by flushing at a moment when the new rows
     * are not yet visible. Recorded rather than silently inherited.
     */
    private function flushTheEntitlementCacheAfterCommit(): void
    {
        DB::afterCommit(static function (): void {
            (new User())->flushCache();
            (new PlanOrder())->flushCache();
            (new PlanUserCoupon())->flushCache();
            (new PlanCoupon())->flushCache();
        });
    }

    /**
     * THE USAGE ROW FOR THE COUPON THIS PURCHASE ACTUALLY USED.
     *
     * The same write the browser return makes after a successful activation -- a `PlanUserCoupon`
     * row against the order, and the coupon switched off once its platform-wide limit is reached.
     *
     * ONE DELIBERATE DIFFERENCE FROM THAT DOOR, STATED RATHER THAN HIDDEN. The return keys the
     * write on `coupon_code` alone, and the initiator records `coupon_code` unconditionally -- even
     * when its own lookup found nothing because the code was inactive, in which case the buyer was
     * charged the FULL price. The return then finds that coupon (its lookup does not require
     * `is_active`, by design) and charges a usage to a coupon that discounted nothing. The
     * initiator sets `coupon_id` ONLY when it applied the discount, so `coupon_id` is the server's
     * own record of whether a coupon was used. This keys on it. For every purchase that really
     * used a coupon the two doors write the identical row.
     *
     * The row is still RESOLVED by code through the trait's own lookup, and must be the row the
     * intent names: a code that has since been re-used for a different coupon charges nothing.
     */
    private function countTheCouponThePurchaseUsed(object $intent, User $purchaser, PlanOrder $order): void
    {
        if (! is_numeric($intent->coupon_id ?? null) || empty($intent->coupon_code)) {
            return;
        }

        /*
         * RESOLVED BY THE ID THE INTENT RECORDED, UNCACHED.
         *
         * This looked the coupon up by CODE and then compared ids, which the financial review showed
         * drops the usage silently: rename the code after initiation and the discounted purchase
         * settles with no usage row and a limit-1 coupon left active. The id is what the intent
         * records precisely so it survives a code being edited (the migration says so), and
         * `PlanCoupon` is `Cachable`, so an entitlement decision must not read it through a cache.
         */
        $coupon = PlanCoupon::disableCache()->find((int) $intent->coupon_id);

        if ($coupon === null) {
            return;
        }

        $usage = new PlanUserCoupon();
        $usage->user_id = $purchaser->id;
        $usage->coupon_id = $coupon->id;
        $usage->order = $order->order_id;
        $usage->save();

        if ($coupon->limit <= $coupon->used_coupon()) {
            $coupon->is_active = 0;
            $coupon->save();
        }
    }

    /**
     * ONE AUDIT ROW, WRITTEN DIRECTLY.
     *
     * Not through `AuditLog::record()`: that reads the `web` guard for the actor and derives the
     * store from a subject model, and this has neither -- a console run has no session, and an
     * intent is a row rather than a model. `ThemeVersionController` writes its refusal audit the
     * same way for the same kind of reason. `store_id` is null because a plan purchase is paid to
     * the platform and belongs to no store; the purchaser is in `new_values`.
     */
    public static function audit(string $action, object $intent, ReconciliationOutcome $outcome, ?User $actor, string $channel): void
    {
        AuditLog::create([
            'user_id' => $actor?->id,
            'store_id' => null,
            'action' => $action,
            'subject_type' => self::AUDIT_SUBJECT,
            'subject_id' => is_numeric($intent->id ?? null) ? (int) $intent->id : null,
            'old_values' => [
                'status' => $intent->status ?? null,
                'failure_reason' => $intent->failure_reason ?? null,
            ],
            'new_values' => array_merge([
                'outcome' => $outcome->kind,
                /* KEYED ON BY `PlanPaymentReconciler::recordDiscrepancy()`, which is why it is a code
                   and not only part of the sentence. */
                'discrepancy' => $outcome->discrepancy,
                'reason' => $outcome->reason,
                'provider' => $intent->provider ?? null,
                'reference' => $intent->reference ?? null,
                'intent_purchaser_id' => $intent->user_id ?? null,
                'intent_plan_id' => $intent->plan_id ?? null,
                'intent_expected_amount' => isset($intent->expected_amount) ? (string) $intent->expected_amount : null,
                'intent_currency' => $intent->currency ?? null,
            ], $outcome->evidence),
            'ip_address' => null,
            'user_agent' => 'payments:reconcile (' . $channel . ')',
        ]);
    }
}
