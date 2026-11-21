<?php

namespace App\Services;

use App\Models\Order;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * REVERSING A MERCHANT'S ENTITLEMENT WITHOUT REWRITING THE CUSTOMER'S PAYMENT HISTORY.  P4-F124.
 * =================================================================================================
 *
 * WHAT WAS WRONG. `orders.payment_status` carried two meanings at once. Writing `'Unpaid'` on a
 * cancellation was not a statement about the customer's settlement — it was *the mechanism* that
 * made the wallet credit reverse, because `OrderObserver::updated()` returns early unless
 * `wasChanged('payment_status')`. The cancel arm's own inherited comment calls that line "the
 * missing magic line … so the Observer detects the change and calls the wallet service".
 *
 * So cancelling a PREPAID order recorded that the customer had not paid. They had. The money was
 * captured, and the only durable record of it was overwritten to make an unrelated side effect
 * fire.
 *
 * THE OWNER'S DECISION, Option A, verbatim in the parts that constrain this class:
 *
 *   - "`orders.payment_status` represents the customer's actual payment settlement."
 *   - "Cancellation must not rewrite a captured payment as Unpaid merely to trigger wallet
 *      reversal."
 *   - "Represent cancellation and financial reversal reasons in the existing financial ledger
 *      structures."
 *   - "Never create a new order payment-status value simply to represent cancellation."
 *   - "Keep payment-status vocabulary backward-compatible with the shipped Flutter application."
 *
 * SO THIS CLASS NEVER TOUCHES `orders.payment_status`. Not to set it, not to clear it. The order
 * keeps saying what the customer actually did, and the reversal is recorded where entitlement
 * lives: `store_order_financials.payment_status` plus a `store_wallet_transactions` row naming the
 * reason.
 *
 * NO SCHEMA CHANGE IS NEEDED AND NONE IS MADE. Measured, not assumed:
 *   - `store_order_financials.payment_status` already carries `'cancelled'` and `'refunded'` in its
 *     production enum, and nothing in the codebase could previously reach either;
 *   - `store_wallet_transactions.type` is `varchar(50)` with no enum and no CHECK, and seven
 *     distinct values are already in use, so a new one cannot fail a write and no reader can reject
 *     it;
 *   - `store_order_financials` already carries `UNIQUE (order_id)`, so "one financial row per
 *     order" is an enforced guarantee rather than an observation.
 *
 * EVIDENCE OF THE CAPTURE IS PRESERVED, WHICH IS THE WHOLE POINT OF PRINCIPLE 2.
 * `OrderFinancialService::markFinancialAsUnpaid()` zeroes `paid_amount` and nulls `paid_at`,
 * because for it the correct reading really is "this was never paid" — a merchant correcting a
 * mistaken Paid. A cancellation is not that. This class leaves `paid_amount` and `paid_at` exactly
 * as they are and changes only the status, so the row still says how much the customer paid and
 * when, while saying that the merchant is no longer entitled to it.
 *
 * IDEMPOTENT, BECAUSE DUPLICATE CANCELLATIONS AND WEBHOOK RETRIES ARE NORMAL.
 * The guard is the financial row's own status, re-read under `lockForUpdate()` inside the
 * transaction: once it is `cancelled` or `refunded` the method returns without moving anything. A
 * courier that retries its webhook, a shopper who double-taps cancel, and a merchant cancelling an
 * order another door already cancelled all converge on one reversal.
 *
 * IT DOES NOT RESTORE STOCK, deliberately. The goods are the callers' business and must stay there:
 * `RestoresCancelledOrderStock` carries per-line markers, and `OrderReplacement::cancelAndReplace()`
 * cancels through the same engine arm while needing the stock to NOT come back — a cancel-plus-
 * reissue pair must net to zero movement, not to a restore. Moving the stock into a shared
 * cancellation choke point is a trap that this project has already documented.
 *
 * @see \App\Services\OrderFinancialService
 * @see \App\Observers\OrderObserver
 */
class OrderEntitlementReversal
{
    /** The order was cancelled. The customer may or may not have paid; the merchant is not entitled. */
    public const REASON_CANCELLED = 'cancelled';

    /** A completed, verified refund. Money went back to the customer. */
    public const REASON_REFUNDED = 'refunded';

    /**
     * The financial states this class may move FROM.
     *
     * Anything already in a terminal reversal state is left alone — that is the idempotency guard.
     */
    private const TERMINAL_STATES = [self::REASON_CANCELLED, self::REASON_REFUNDED];

    /**
     * The `store_wallet_transactions.type` written for each reason.
     *
     * Distinct from `order_payment_reversed`, which `markFinancialAsUnpaid()` writes for a merchant
     * correcting a mistaken Paid. Three different events that all debit a wallet should not share
     * one name, because the only way anybody reconstructs why a balance moved is this column.
     */
    private const TRANSACTION_TYPES = [
        self::REASON_CANCELLED => 'order_cancelled',
        self::REASON_REFUNDED => 'order_refunded',
    ];

    /**
     * `reverse()`, but a ledger failure never blocks the operation that asked for it.
     *
     * WHICH DIRECTION TO FAIL IN IS THE WHOLE QUESTION, and this codebase has already answered it
     * for the sibling case. `OrderObserver::created()` swallows a failed financial write with the
     * reasoning written out at its catch: letting it throw "would now roll back the whole order
     * write … so a missing wallet row would stop the store selling. Losing a ledger entry is bad;
     * refusing every order is worse."
     *
     * The same holds here and is sharper: a merchant cancelling an order, a shopper cancelling
     * their own, and a courier reporting a failed delivery must all succeed even if the ledger
     * cannot be written. A cancellation that fails because of a finance-table problem leaves the
     * goods committed and the customer stuck.
     *
     * BUT THE OWNER'S RULE IS "Never commit an order as financially accounted for if its necessary
     * financial posting silently failed" -- and the operative word is SILENTLY. So the operation
     * proceeds and the failure is recorded at `critical`, naming the order, the store and the
     * reason, which is the level the observability census actually tracks. Nothing is marked as
     * accounted for: the financial row simply does not reach a terminal state, so a reconciliation
     * can still find it.
     *
     * MEASURED, not anticipated: the first version of the engine call had no guard, and a
     * `QueryException` from a thin test schema propagated out of `Order::order_status_change()` and
     * failed the cancellation itself across twenty-odd cases. In production the table exists, but a
     * cancellation whose success depends on the finance schema being present is a coupling nobody
     * chose.
     *
     * @return bool true when a reversal was performed; false when there was nothing to do OR the
     *              attempt failed. Callers must not treat false as "definitely nothing to do".
     */
    public function reverseOrLog(Order $order, string $reason, ?string $note = null): bool
    {
        try {
            return $this->reverse($order, $reason, $note);
        } catch (\Throwable $e) {
            Log::critical('Entitlement reversal failed; the order was still cancelled or refunded.', [
                'order_id' => $order->getKey(),
                'store_id' => $order->getAttributes()['store_id'] ?? null,
                'final_price' => $order->getAttributes()['final_price'] ?? null,
                'reason' => $reason,
                'note' => $note,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reverse the merchant's entitlement for this order, once.
     *
     * @param  Order   $order
     * @param  string  $reason  one of REASON_CANCELLED / REASON_REFUNDED
     * @param  string|null $note free text stored on the wallet transaction, e.g. which door acted
     * @return bool  true when THIS call performed the reversal; false when there was nothing to do
     *               (no financial row, or already reversed). A false is not an error.
     */
    public function reverse(Order $order, string $reason, ?string $note = null): bool
    {
        if (! in_array($reason, self::TERMINAL_STATES, true)) {
            throw new \InvalidArgumentException(
                'Unknown entitlement reversal reason: ' . $reason
            );
        }

        $orderId = (int) $order->getKey();
        $storeId = (int) ($order->getAttributes()['store_id'] ?? 0);

        if ($orderId <= 0 || $storeId <= 0) {
            return false;
        }

        return (bool) DB::transaction(function () use ($orderId, $storeId, $reason, $note) {

            /*
             * THE FINANCIAL ROW, LOCKED. Everything below decides from this row and not from the
             * order, because the order's `payment_status` is now deliberately NOT the record of
             * entitlement.
             */
            $financial = DB::table('store_order_financials')
                ->where('order_id', $orderId)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if (! $financial) {
                /*
                 * No financial row. Before P4-F032 this was the normal outcome for a store that
                 * had no wallet, and the credit was never made in the first place — so there is
                 * nothing to reverse and this is not an error. It is logged at `warning` because
                 * after that fix it should be rare, and a rising count means orders are still being
                 * written without a ledger entry.
                 */
                Log::warning('Entitlement reversal found no financial record for the order.', [
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'reason' => $reason,
                ]);

                return false;
            }

            /*
             * IDEMPOTENCY. A duplicate cancellation, a retried courier webhook, or two doors acting
             * on the same order all land here, and only the first one moves money.
             */
            if (in_array((string) $financial->payment_status, self::TERMINAL_STATES, true)) {
                return false;
            }

            $wasPaid = (string) $financial->payment_status === 'paid';

            /*
             * THE STATUS MOVES EVEN WHEN NO MONEY DOES.
             *
             * An unpaid order — a COD parcel never collected, say — still needs its ledger row to
             * say it was cancelled, or the row sits at `unpaid` for ever and is indistinguishable
             * from one still awaiting collection. That distinction is exactly what the reporting
             * phase needs.
             *
             * `paid_amount` and `paid_at` ARE NOT TOUCHED. Principle 2: cancellation does not erase
             * the evidence of a captured payment. For a prepaid order the row goes on saying how
             * much arrived and when, while the status says the merchant is no longer entitled to
             * it.
             */
            /*
             * THE `store_id` PREDICATE IS NOT REDUNDANT, even though the row was selected with it
             * three statements above.
             *
             * `ServiceLayerRawQueryInventoryTest` flagged this statement as store-less when it was
             * keyed on `id` alone, and the ratchet is right to: a reader of this line in isolation
             * cannot tell that the id came from a scoped lookup, and a later edit that moved the
             * lookup would silently leave an unscoped write behind. Naming the tenant at the write
             * costs one indexed column and makes the statement true on its own terms.
             */
            DB::table('store_order_financials')
                ->where('id', $financial->id)
                ->where('store_id', $storeId)
                ->update([
                    'payment_status' => $reason,
                    'updated_at' => now(),
                ]);

            if (! $wasPaid) {
                return true;
            }

            $wallet = StoreWallet::where('id', $financial->wallet_id)
                ->lockForUpdate()
                ->first();

            if (! $wallet) {
                /*
                 * The financial row names a wallet that is gone. Reversing nothing is the only safe
                 * answer: inventing a wallet here would credit a balance that no longer exists, and
                 * guessing which one was meant would be worse. The status move above still stands,
                 * so the cancellation is recorded.
                 */
                Log::critical('Entitlement reversal could not find the wallet named by the financial record.', [
                    'order_id' => $orderId,
                    'store_id' => $storeId,
                    'wallet_id' => $financial->wallet_id,
                    'reason' => $reason,
                ]);

                return true;
            }

            $amount = $this->amountToReverse($financial, $wallet);

            $before = (float) $wallet->available_balance;
            $after = $before - $amount;

            $wallet->available_balance = $after;
            $wallet->total_income = max(0, (float) $wallet->total_income - $amount);
            $wallet->save();

            StoreWalletTransaction::create([
                'store_id' => $financial->store_id,
                'wallet_id' => $wallet->id,
                'currency_code' => $wallet->currency_code ?? 'USD',

                'type' => self::TRANSACTION_TYPES[$reason],
                'direction' => 'debit',

                'amount' => $amount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'order',
                'reference_id' => $financial->order_id,

                /*
                 * THE REASON IS THE POINT OF THE WHOLE CHANGE. `type` says what kind of event this
                 * was; the description says which door asked for it. Together they are what anybody
                 * reconstructing a merchant's balance has to read, and before this they did not
                 * exist — the reversal was indistinguishable from a merchant correcting a mistaken
                 * Paid.
                 */
                'description' => 'Order #' . $financial->order_id . ' ' . $reason
                    . ($note !== null && $note !== '' ? ' (' . $note . ')' : ''),

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);

            return true;
        });
    }

    /**
     * How much to take back, in the wallet's CURRENT currency.
     *
     * The same shape `markFinancialAsUnpaid()` uses, and for the same reason: the authoritative
     * figure is what actually ENTERED the wallet, which is the `order_paid` transaction's amount,
     * not the order's total. They differ whenever the order and the wallet are denominated
     * differently, and reversing the order's total in that case would take back more or less than
     * was ever credited.
     *
     * The fallback covers rows written before wallet transactions existed.
     */
    private function amountToReverse(object $financial, StoreWallet $wallet): float
    {
        $walletCurrency = $wallet->currency_code ?? 'USD';

        $paidTransaction = StoreWalletTransaction::where('store_id', $financial->store_id)
            ->where('reference_type', 'order')
            ->where('reference_id', $financial->order_id)
            ->where('type', 'order_paid')
            ->where('status', 'completed')
            ->latest('id')
            ->first();

        if ($paidTransaction) {
            return (float) app(StoreCurrencyConversionService::class)->convertAmount(
                (float) $paidTransaction->amount,
                $paidTransaction->currency_code ?? $walletCurrency,
                $walletCurrency,
                (int) $financial->store_id
            );
        }

        return (float) app(StoreCurrencyConversionService::class)->convertAmount(
            (float) $financial->paid_amount,
            $financial->currency_code ?? 'USD',
            $walletCurrency,
            (int) $financial->store_id
        );
    }
}
