<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * E13 REQUIREMENT D — CANCEL AN ORDER AND ISSUE A LINKED REPLACEMENT.
 *
 * Implemented exactly as `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` §5 specifies, under D-15.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * REACHABLE SINCE D-32, AND FAIL-CLOSED ON D-15's PROOF OBLIGATIONS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * D-32 (owner, 2026-10-31) answered the design's §6 **Q1** with the narrowest reading — identical
 * products, quantities and prices — so `POST order-replace/{id}` exists and the merchant reaches it
 * from `order/view.blade.php`.
 *
 * **D-32 DOES NOT WAIVE D-15's FINANCIAL OR STOCK PROOF REQUIREMENTS, and a first version of this
 * class read as though it did.** It checked authorization, dispatch status, readable lines and
 * one-replacement-per-original — and nothing at all about money or stock. Measured against D-15's
 * nine conditions, three were unenforced:
 *
 *   - **Condition 6** — *refuse the automatic path for partial payments, and for collected payments
 *     whose refund or transfer is not provably correct.* There was no payment test of any kind, so
 *     a `Paid` order was replaced and the customer's money was neither refunded nor accounted for.
 *   - **Conditions 4 and 5** — *do not treat the original as having consumed stock without evidence
 *     from the real processing path; do not use a "stock already decremented" marker on an order
 *     whose decrement is not proven.* Every line was stamped `LINE_STOCK_CARRIED` unconditionally.
 *     On an order whose decrement never happened that marker is a false claim, and it suppresses
 *     the restore the cancellation would otherwise perform — so stock went missing on paper.
 *   - **Condition 8** — *safe under concurrency.* The in-transaction `exists()` re-check is not a
 *     concurrency guard: two transactions can both pass it before either inserts.
 *
 * All three are now enforced, and the enforcement is **fail-closed**: anything this class cannot
 * prove, it refuses. `eligibility()` is public and the controller calls the same method, so the
 * merchant's button is absent for exactly the orders the service would refuse.
 *
 * **CONDITION 3 IS A RULE ABOUT WORDS AS MUCH AS CODE.** Reversing the MERCHANT's wallet is not a
 * refund to the CUSTOMER. `Order::order_status_change('cancel')` and its Observer reverse the
 * merchant's balance; that is the correct bookkeeping for a cancellation and it is **not** evidence
 * that the shopper got their money back. Nothing here reads it as such — which is why a collected
 * payment is refused outright rather than "handled" by the reversal that already happens.
 *
 * **WHY THE ALLOWED SET IS CURRENTLY ONLY UNCOLLECTED ORDERS.**
 * `RefundIntegrityService::hasGatewayRefundDriver()` is a hardcoded `return false` — no gateway
 * refund driver exists for any provider, and `RefundIntegrityTest` ratchets that. So condition 6's
 * "provably correct refund" is satisfiable by nothing today, and every collected payment falls into
 * its refusal. That is not this class being conservative; it is the measured state of the platform,
 * and the moment a real driver lands this class widens on its own with no edit here.
 *
 * **F237 remains the cautionary case**: a mechanism recorded as DONE whose production callers never
 * existed, so a guard that read as working was inert for every real order. The stock test below is
 * written against `StockMovement::movedOutFor()`, which F237 wired, and it refuses `UNKNOWN` — the
 * verdict an uninstrumented order produces — rather than treating silence as consent.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE FIVE STEPS, AND WHICH CONDITION EACH SATISFIES
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * 1. **Refuse unless the original can be cancelled** — the same `delivered_status != 0` test the
 *    existing arm uses, read from it rather than re-expressed, so dispatch-time semantics cannot
 *    drift between the two.
 * 2. **Cancel the original through the existing engine** — `Order::order_status_change()`, not a
 *    copy of its body. The Observer then reverses the money exactly as it does today.
 * 3. **Create the replacement from the original's own `product_json`**, lines identical, each
 *    stamped `LINE_STOCK_CARRIED` — **but only once every line's out-movement is PROVEN**.
 *    *"Do not duplicate stock movements"* is satisfied by the stamp: the goods never came back, so
 *    nothing goes out again. D-15 conditions 4 and 5 are satisfied by refusing to apply the stamp
 *    at all unless `StockMovement::movedOutFor()` says `moved` for that exact product or variant.
 *    `unknown` and `not_moved` both refuse the whole operation: a partly-stamped replacement would
 *    be a stock rule nobody approved.
 * 4. **One transaction**, so a failure leaves neither a cancelled original without a replacement
 *    nor the reverse.
 * 5. **Authorised exactly as the existing status change is** — `OrderPolicy::updateStatus`, which
 *    tests `isPlatformAdmin` FIRST (CLAUDE.md: `isAbleTo()` alone refuses the platform operator)
 *    and then store ownership. **No new permission is invented.**
 *
 * WHAT IT MUST NOT DO, each for a stated reason:
 *
 * - **Not touch the original's lines, prices or totals.** Owner condition, and an audit-trail
 *   requirement: the cancelled order is the record of what was cancelled.
 * - **Not create a second financial row for the original.** The reversal is the correct financial
 *   event and `UNIQUE (order_id)` would reject a second one anyway.
 * - **Not restore stock.** That is S2 and it is not approved.
 * - **Not change the `cancel` arm.** Every existing caller keeps today's behaviour.
 * - **Not be reachable from the storefront or the customer API.** Merchant-initiated only; a
 *   customer-initiated replacement is a different authorisation question.
 */
class OrderReplacement
{
    public const REFUSED_NOT_AUTHORISED = 'not_authorised';
    public const REFUSED_ALREADY_DISPATCHED = 'already_dispatched';
    public const REFUSED_ALREADY_REPLACED = 'already_replaced';
    public const REFUSED_UNREADABLE_LINES = 'unreadable_lines';
    public const REFUSED_COLUMN_ABSENT = 'column_absent';
    public const REFUSED_CANCEL_FAILED = 'cancel_failed';

    /** D-15 condition 6 — a partial payment has no approved safe scenario. */
    public const REFUSED_PAYMENT_PARTIAL = 'payment_partially_paid';

    /** D-15 conditions 3 and 6 — money was collected and no provable refund path exists. */
    public const REFUSED_PAYMENT_COLLECTED = 'payment_collected_without_refund_path';

    /** D-15 conditions 4 and 5 — this line's out-movement cannot be proven either way. */
    public const REFUSED_STOCK_EVIDENCE_UNKNOWN = 'stock_evidence_unknown';

    /** D-15 condition 5 — this line provably took no stock out, so the carried marker would lie. */
    public const REFUSED_STOCK_NOT_MOVED = 'stock_not_moved';

    public const REPLACED = 'replaced';

    /**
     * PAYMENT STATES THAT MEAN NOTHING WAS COLLECTED — AN ALLOWLIST, AND THE DIRECTION MATTERS.
     *
     * A denylist of "collected" states would let an unrecognised value through to the replacement;
     * an allowlist sends it to the refusal instead. `orders.payment_status` is free text written by
     * 70 vendored modules in mixed case (`Paid`, `paid`, `succeeded`, `success`, `Approved`,
     * `Pending payment`…), so an unrecognised value is not hypothetical, and the safe reading of
     * one is "money may have moved".
     */
    private const UNCOLLECTED_PAYMENT_STATES = [
        '', 'unpaid', 'pending', 'pending payment', 'awaiting payment',
        'failed', 'rejected', 'declined', 'cancelled', 'canceled', 'void', 'voided',
    ];

    /** Is the additive column present? Without it there is no link, so there is no operation. */
    public static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('orders', 'replaced_order_id');
    }

    /**
     * D-15 CONDITIONS 3 AND 6 — MAY THIS ORDER'S PAYMENT STATE BE REPLACED AUTOMATICALLY?
     *
     * Returns `REPLACED` when the payment state permits it, or the refusal that applies.
     *
     * **A PARTIAL PAYMENT IS REFUSED OUTRIGHT.** Condition 6 names it, and there is no approved
     * safe scenario for one: the shopper has paid part of a sum that is about to be cancelled, and
     * deciding what happens to that part is a settlement rule the owner has not made.
     *
     * **A COLLECTED PAYMENT IS REFUSED UNLESS A REAL REFUND PATH EXISTS.** Condition 3 separates
     * reversing the merchant's balance from refunding the customer, and condition 6 refuses the
     * automatic path where the refund is "not provably correct". `hasGatewayRefundDriver()` is the
     * platform's own answer to that question, and it is currently `false` for every provider — so
     * the refusal covers every collected payment rather than a subset. When a driver lands, this
     * method widens with no edit.
     *
     * **COD IS NOT EXEMPT, deliberately.** A `Paid` cash-on-delivery order means the merchant holds
     * the shopper's cash. Returning it is a real-world act this system cannot observe, so claiming
     * it as "provably correct" would be the same inference condition 3 forbids one layer up.
     */
    public static function paymentEligibility(Order $original): string
    {
        return self::paymentVerdict($original->payment_status, $original->payment_type);
    }

    /**
     * The payment rule itself, over two RAW VALUES rather than a model.
     *
     * **IT TAKES VALUES SO THAT THE LOCKED RE-CHECK CAN ASK THE SAME QUESTION.** The transaction in
     * `cancelAndReplace()` re-reads the order through `DB::table('orders')` — it has to, because
     * `Order` is `Cachable` and a lock must take the DATABASE row rather than a cached copy — and
     * that gives it a `stdClass`, not an `Order`. Routing both callers through one method is what
     * stops the gate and the re-check from being two expressions that have to be kept in step by
     * hand; this codebase has already paid for that twice (the stock badge and its filter, the
     * dashboard count and its list).
     *
     * @param  mixed  $paymentStatus  `orders.payment_status`, free text written by 70 modules.
     * @param  mixed  $paymentType    `orders.payment_type`, for the refund-driver question.
     */
    private static function paymentVerdict($paymentStatus, $paymentType): string
    {
        $status = strtolower(trim((string) $paymentStatus));

        if (str_contains($status, 'partial')) {
            return self::REFUSED_PAYMENT_PARTIAL;
        }

        if (in_array($status, self::UNCOLLECTED_PAYMENT_STATES, true)) {
            return self::REPLACED;
        }

        /* Resolved through the container so a future real driver is picked up here automatically. */
        if (! app(RefundIntegrityService::class)->hasGatewayRefundDriver($paymentType)) {
            return self::REFUSED_PAYMENT_COLLECTED;
        }

        return self::REPLACED;
    }

    /**
     * D-15 CONDITIONS 4 AND 5 — IS EVERY LINE'S OUT-MOVEMENT PROVEN?
     *
     * Returns `REPLACED` when every line reads `moved`, otherwise the refusal for the first line
     * that does not. **`unknown` and `not_moved` are both refusals, for different reasons:**
     *
     *   - `unknown` means the platform never observed this order's placement — an order written
     *     before `stock_movements` existed, or by a writer not yet enrolled. Condition 4 forbids
     *     treating it as having consumed stock, and silence is not evidence.
     *   - `not_moved` means the platform observed the order and it provably took nothing out (a
     *     refused decrement records `direction = out, applied = false`). Stamping
     *     `LINE_STOCK_CARRIED` would then be a false claim — condition 5 by name — and it would
     *     also suppress a restore that should happen, so stock would go missing on paper.
     *
     * **ALL-OR-NOTHING, not per line.** A replacement carrying the marker on some lines and not
     * others would need a rule for what the unmarked lines do about stock, and no such rule is
     * approved. Refusing is the only option that invents nothing.
     */
    public static function stockEvidence(Order $original, array $lines): string
    {
        if (! StockMovement::available()) {
            return self::REFUSED_STOCK_EVIDENCE_UNKNOWN;
        }

        foreach ($lines as $line) {
            if (! is_array($line)) {
                return self::REFUSED_STOCK_EVIDENCE_UNKNOWN;
            }

            $verdict = StockMovement::movedOutFor(
                $original->store_id,
                $original->id,
                $line['product_id'] ?? null,
                $line['variant_id'] ?? null
            );

            if ($verdict === StockMovement::NOT_MOVED) {
                return self::REFUSED_STOCK_NOT_MOVED;
            }

            if ($verdict !== StockMovement::MOVED) {
                return self::REFUSED_STOCK_EVIDENCE_UNKNOWN;
            }
        }

        return self::REPLACED;
    }

    /**
     * THE WHOLE ELIGIBILITY QUESTION IN ONE PLACE, so the merchant's button and the service cannot
     * disagree about who is eligible.
     *
     * `OrderController::order_view()` calls this to decide whether to render the control, and
     * `cancelAndReplace()` calls it again before doing anything. The directive's requirement —
     * *"keep the merchant action hidden or disabled for ineligible orders"* — is met by there being
     * exactly one predicate rather than two that drift.
     *
     * It does NOT test authorization: the caller's permission is the caller's question, and the
     * controller already asks `Gate::allows('updateStatus')` for the button while the service asks
     * it again for the action.
     */
    public static function eligibility(Order $original): string
    {
        if (! self::available()) {
            return self::REFUSED_COLUMN_ABSENT;
        }

        if (Order::where('replaced_order_id', $original->id)->exists()) {
            return self::REFUSED_ALREADY_REPLACED;
        }

        if ((int) $original->delivered_status !== 0) {
            return self::REFUSED_ALREADY_DISPATCHED;
        }

        $lines = json_decode((string) $original->product_json, true);

        if (! is_array($lines) || $lines === []) {
            return self::REFUSED_UNREADABLE_LINES;
        }

        $payment = self::paymentEligibility($original);

        if ($payment !== self::REPLACED) {
            return $payment;
        }

        return self::stockEvidence($original, $lines);
    }

    /**
     * Cancel `$original` and issue one linked replacement with identical lines.
     *
     * @return array{0:string,1:?Order,2:string} [state, replacement, reason]
     */
    public static function cancelAndReplace(Order $original, $actor): array
    {
        if (! self::available()) {
            /* The column is additive and dev/test only, so an installation without it simply has
               no replacement operation. It must not half-perform one: cancelling the original and
               failing to link a replacement is the worst outcome available here. */
            return [self::REFUSED_COLUMN_ABSENT, null, 'the replaced_order_id column is not installed'];
        }

        /* STEP 5, FIRST. Authorisation before anything is read, so a caller who may not act learns
           nothing about the order either. `updateStatus` is the existing status-change gate --
           cancelling IS a status change, and this operation is a cancel plus a reissue, so
           inventing a second permission would let the two drift apart. */
        if (! $actor || ! Gate::forUser($actor)->allows('updateStatus', $original)) {
            return [self::REFUSED_NOT_AUTHORISED, null, 'the actor may not change this order\'s status'];
        }

        /*
         * ONE REPLACEMENT PER ORIGINAL, AND THIS IS TESTED BEFORE THE DISPATCH CHECK ON PURPOSE.
         *
         * The order matters only for the REASON given, and the reason is the whole value of a
         * named state. A replayed call arrives at an original this operation has already
         * cancelled, so `delivered_status` is 2 -- and with the dispatch test first, the replay
         * was answered `already_dispatched`, which is both misleading (it was cancelled, not
         * shipped) and useless to a caller trying to tell a double-submit from a genuine refusal.
         * Measured: the replay test asserted `already_replaced` and got `already_dispatched`.
         *
         * Not a UNIQUE constraint, because the column is indexed rather than constrained -- see
         * the migration for why a real FK was declined. The authoritative check is the one inside
         * the transaction below, which a concurrent second call cannot pass; this one is the cheap
         * early answer.
         */
        if (Order::where('replaced_order_id', $original->id)->exists()) {
            return [self::REFUSED_ALREADY_REPLACED, null, 'this order has already been replaced'];
        }

        /* STEP 1. The same test the cancel arm applies, so the two cannot disagree about what
           "dispatched" means. Read from `Order::order_status_change()`'s own condition. */
        if ((int) $original->delivered_status !== 0) {
            return [self::REFUSED_ALREADY_DISPATCHED, null, 'the order has been dispatched, so it cannot be cancelled'];
        }

        $lines = json_decode((string) $original->product_json, true);

        if (! is_array($lines) || $lines === []) {
            /* A POS order's lines carry neither `product_id` nor a quantity, and the same refusal
               the return workflow makes applies: refuse with a reason rather than raise an
               undefined-key 500 half way through building a replacement. */
            return [self::REFUSED_UNREADABLE_LINES, null, 'the order\'s lines cannot be read'];
        }

        /*
         * D-15 CONDITIONS 3 AND 6 — THE PAYMENT GATE, BEFORE ANYTHING IS WRITTEN.
         *
         * This is the condition the first version of this class did not have at all. Placed before
         * the transaction because a refusal must leave the original exactly as it was, and because
         * the merchant should learn the reason without the order being touched.
         */
        $payment = self::paymentEligibility($original);

        if ($payment !== self::REPLACED) {
            return [$payment, null, $payment === self::REFUSED_PAYMENT_PARTIAL
                ? 'the order is partially paid, and no approved settlement rule covers that'
                : 'money was collected for this order and no provable customer-refund path exists'];
        }

        /*
         * D-15 CONDITIONS 4 AND 5 — THE STOCK-EVIDENCE GATE.
         *
         * `LINE_STOCK_CARRIED` is applied below on every line. It is a CLAIM that the original took
         * that stock out, and condition 5 forbids making it without proof. So the proof is demanded
         * here, for every line, before a single stamp is written.
         */
        $stock = self::stockEvidence($original, $lines);

        if ($stock !== self::REPLACED) {
            return [$stock, null, $stock === self::REFUSED_STOCK_NOT_MOVED
                ? 'a line of this order provably took no stock out, so it cannot be carried'
                : 'this order\'s stock movement cannot be proven, so it cannot be carried'];
        }

        $replacement = null;

        try {
            DB::transaction(function () use ($original, &$replacement, $lines) {
                /*
                 * D-15 CONDITION 8 — THE CRITICAL CONDITIONS ARE RE-TESTED UNDER A ROW LOCK.
                 *
                 * `lockForUpdate()` on the ORIGINAL serialises two concurrent replacements of the
                 * same order: the second blocks here until the first commits, and then reads the
                 * row the first left behind. Read through the query builder rather than Eloquent on
                 * purpose — `Order` is `Cachable`, and a lock must take the DATABASE row, not a
                 * cached copy of it.
                 *
                 * **SQLite IGNORES `FOR UPDATE`**, so in the PHPUnit suite this is a no-op and the
                 * suite proves the LOGIC only. The serialisation itself is proven on MariaDB by
                 * `tests/Support/Security/bin/verify-order-replacement-concurrency-on-mysql.php`.
                 * Saying so here because a green suite would otherwise read as proof of something
                 * it cannot test.
                 */
                /*
                 * THE LOCK CARRIES THE ROW'S OWN `store_id`, and that is not decoration.
                 *
                 * `Order` is absent from `config/tenancy.php`'s `scoped_models` AND a raw
                 * query-builder statement is invisible to every Eloquent global scope anyway, so
                 * this predicate is the only tenant statement this line can make. The caller does
                 * authorise the order — `OrderController` resolves it for the acting merchant and
                 * `cancelAndReplace()` checks again — but `ServiceLayerRawQueryInventoryTest`'s
                 * rule is the right one: a raw statement is scoped AT THE CALL SITE or declared
                 * with its reason, and "the caller checks" is the weakest of the two.
                 *
                 * A row whose `store_id` is NULL therefore fails to lock and the replacement is
                 * refused. That is fail-closed and deliberate: such an order is already broken,
                 * and `where('store_id', null)` would compile to `IS NULL` and match any other
                 * orphan — the exact trap this codebase keeps paying for.
                 */
                $locked = DB::table('orders')
                    ->where('id', $original->id)
                    ->where('store_id', $original->store_id)
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    throw new \RuntimeException(self::REFUSED_CANCEL_FAILED . ': the order disappeared');
                }

                /* Dispatch state is re-tested under the lock: a concurrent status change could have
                   shipped or cancelled this order between the check above and this transaction. */
                if ((int) $locked->delivered_status !== 0) {
                    throw new \RuntimeException(self::REFUSED_ALREADY_DISPATCHED);
                }

                if (Order::where('replaced_order_id', $original->id)->exists()) {
                    throw new \RuntimeException(self::REFUSED_ALREADY_REPLACED);
                }

                /*
                 * THE PAYMENT STATE IS RE-TESTED UNDER THE LOCK TOO, AND IT IS THE ONE THAT CAN
                 * REALLY MOVE WHILE A MERCHANT IS DECIDING.
                 *
                 * `payment_status` is written by gateway returns and provider callbacks, which
                 * arrive whenever the provider sends them — not by the merchant on this screen. So
                 * between `eligibility()` answering `REPLACED` for an UNPAID order and this
                 * transaction opening, a callback can legitimately mark that same order `Paid`. The
                 * pre-transaction gate would then have been answered about a state that no longer
                 * exists, and the replacement would proceed for an order whose money HAS been
                 * collected — exactly the case D-15 condition 3 refuses, reached through a window
                 * rather than through a missing check.
                 *
                 * Asked of `$locked`, which is the row as the database holds it a moment ago under
                 * `FOR UPDATE`, through the same `paymentVerdict()` the gate used.
                 *
                 * THE STOCK EVIDENCE IS DELIBERATELY NOT RE-TESTED HERE, and the asymmetry is the
                 * point rather than an omission: `stock_movements` rows for THIS order are written
                 * when the order is placed, which has already happened and cannot happen again, so
                 * there is no concurrent writer to race. `payment_status` has one.
                 */
                $lockedPayment = self::paymentVerdict($locked->payment_status ?? null, $locked->payment_type ?? null);

                if ($lockedPayment !== self::REPLACED) {
                    throw new \RuntimeException($lockedPayment);
                }

                /* STEP 2. The existing engine, not a copy of it. The Observer reverses the money. */
                $cancelled = Order::order_status_change([
                    'order_id' => $original->id,
                    'order_status' => 'cancel',
                ]);

                if (($cancelled['status'] ?? '') !== 'success') {
                    throw new \RuntimeException(self::REFUSED_CANCEL_FAILED . ': ' . ($cancelled['message'] ?? ''));
                }

                /* STEP 3. Identical lines, each stamped as accounted for on the original. */
                $carried = array_map(function ($line) {
                    if (is_array($line)) {
                        $line[Order::LINE_STOCK_CARRIED] = 1;
                    }

                    return $line;
                }, $lines);

                /* Every economic value is copied from the original, not recomputed. Q3 (original
                   prices or today's) cannot arise, because nothing here consults the catalogue. */
                $replacement = Order::create([
                    /*
                     * THE SOURCE IS THE ORIGINAL'S OWN, and `OrderSourceWriterCensusTest` is why
                     * this is here rather than defaulted: it counts every `Order::create` in the
                     * repository and requires each to stamp a source or declare itself not a
                     * writer. Mine was the twentieth and it caught it immediately.
                     *
                     * A reissue is not a new sales channel. Stamping `SOURCE_MANUAL` because a
                     * merchant triggered it would misreport where the demand came from: a
                     * storefront order reissued by a merchant was still a storefront order, and
                     * the reports that group by source would move revenue between channels every
                     * time an address was corrected. Copying the original's value keeps the
                     * channel attribution of the sale that actually happened.
                     *
                     * Falls back to `SOURCE_MANUAL` only when the original carries no source --
                     * true of rows written before that column existed -- because the census
                     * requires a stamp and a NULL would fail it for a reason that is about the
                     * original's age rather than this operation.
                     */
                    'order_source' => $original->order_source ?: Order::SOURCE_MANUAL,
                    'replaced_order_id' => $original->id,
                    'product_order_id' => 'R-' . $original->product_order_id,
                    'order_date' => now(),
                    'customer_id' => $original->customer_id,
                    'is_guest' => $original->is_guest,
                    'product_json' => json_encode($carried),
                    'product_id' => $original->product_id,
                    'product_price' => $original->product_price,
                    'coupon_price' => $original->coupon_price,
                    'delivery_price' => $original->delivery_price,
                    'tax_price' => $original->tax_price,
                    'final_price' => $original->final_price,
                    'currency_code' => $original->currency_code,
                    'currency_symbol' => $original->currency_symbol,
                    'payment_type' => $original->payment_type,
                    /* UNPAID, always. The original's payment was reversed by step 2, so the
                       replacement starts owing. Carrying a `paid` status across would assert a
                       settlement nobody made -- Q2's question, and it cannot arise because this
                       does not try to answer it. */
                    'payment_status' => 'Unpaid',
                    'delivered_status' => 0,
                    'store_id' => $original->store_id,
                    'created_by' => $original->created_by,
                ]);
            });
        } catch (QueryException $theDatabaseRefusedTheSecondWriter) {
            /*
             * D-15 CONDITION 8 — THE DATABASE-ENFORCED HALF OF "ONE REPLACEMENT PER ORIGINAL".
             *
             * `orders_replaced_order_id_unique` (migration 2026_11_01_000100) makes the second
             * concurrent INSERT fail rather than succeed. Two transactions can both pass a SELECT
             * before either inserts — on MySQL's default REPEATABLE READ a read of a NON-unique
             * index takes no gap lock — so the `exists()` test above is an early answer, not a
             * guard. This arm is where the guarantee actually lives.
             *
             * Only an integrity violation is translated into the named refusal. Any other database
             * error falls through to the general arm, because reporting a connection failure as
             * "already replaced" would hide a fault behind a business refusal.
             */
            if ($theDatabaseRefusedTheSecondWriter->getCode() === '23000'
                || str_contains(strtolower($theDatabaseRefusedTheSecondWriter->getMessage()), 'unique')) {
                return [self::REFUSED_ALREADY_REPLACED, null, 'this order has already been replaced'];
            }

            Log::error('Order replacement failed on a database error and was rolled back; the original is unchanged.', [
                'original_order_id' => $original->id,
                'reason' => $theDatabaseRefusedTheSecondWriter->getMessage(),
            ]);

            return [self::REFUSED_CANCEL_FAILED, null, $theDatabaseRefusedTheSecondWriter->getMessage()];
        } catch (\RuntimeException $theReplacementWasRefused) {
            /*
             * EVERY REFUSAL THROWN INSIDE THE TRANSACTION IS NAMED HERE, and the `default` used to
             * swallow the ones that were not.
             *
             * It read `default => self::REFUSED_ALREADY_REPLACED`, which was true of the only
             * unnamed thrower at the time — and then the under-lock PAYMENT re-check was added, and
             * a `payment_partially_paid` or `payment_collected_without_refund_path` refusal would
             * have been reported to the merchant, and to the ledger, as *"this order has already
             * been replaced"*. The operation was correctly refused either way; what would have been
             * wrong is the REASON, on the one gate that exists to protect money.
             *
             * So each state is matched by name, and the default is now an honest general refusal
             * plus a log, because an unrecognised throw from inside this transaction means somebody
             * added a refusal and did not add its arm. The transaction has rolled back in every
             * case, so the original is unchanged whichever arm is taken.
             */
            $reason = $theReplacementWasRefused->getMessage();
            $state = match (true) {
                str_starts_with($reason, self::REFUSED_CANCEL_FAILED) => self::REFUSED_CANCEL_FAILED,
                $reason === self::REFUSED_ALREADY_DISPATCHED => self::REFUSED_ALREADY_DISPATCHED,
                $reason === self::REFUSED_ALREADY_REPLACED => self::REFUSED_ALREADY_REPLACED,
                $reason === self::REFUSED_PAYMENT_PARTIAL => self::REFUSED_PAYMENT_PARTIAL,
                $reason === self::REFUSED_PAYMENT_COLLECTED => self::REFUSED_PAYMENT_COLLECTED,
                default => self::REFUSED_CANCEL_FAILED,
            };

            if ($state === self::REFUSED_CANCEL_FAILED
                && ! str_starts_with($reason, self::REFUSED_CANCEL_FAILED)) {
                Log::error('Order replacement was refused inside the transaction for a reason this method does not name; reported as a general refusal. The original is unchanged. Add an arm for it.', [
                    'original_order_id' => $original->id,
                    'reason' => $reason,
                ]);
            }

            return [$state, null, $reason];
        } catch (\Throwable $theReplacementFailed) {
            Log::error('Order replacement failed and was rolled back; the original is unchanged.', [
                'original_order_id' => $original->id,
                'reason' => $theReplacementFailed->getMessage(),
            ]);

            return [self::REFUSED_CANCEL_FAILED, null, $theReplacementFailed->getMessage()];
        }

        return [self::REPLACED, $replacement, ''];
    }
}
