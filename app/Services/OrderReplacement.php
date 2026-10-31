<?php

namespace App\Services;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * E13 REQUIREMENT D — CANCEL AN ORDER AND ISSUE A LINKED REPLACEMENT.
 *
 * Implemented exactly as `E13-CANCEL-AND-REPLACEMENT-DESIGN.md` §5 specifies, under D-15.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * IT IS DELIBERATELY UNREACHABLE, AND SAYING SO IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * **No route, no controller action and no UI entry point call this.** That is not an oversight and
 * it is not "half done" — it is the honest state of a slice whose commercial rule is unanswered.
 *
 * The design's §6 **Q1** asks whether a replacement may differ from the original at all. If the
 * answer is "identical lines only", §5 is complete and Q2 (a partially-paid original) and Q3
 * (original prices or today's) *fall away* — which is why this implements the identical-lines-only
 * variant and nothing wider. It invents no pricing rule, no settlement rule and no stock delta,
 * so it needs no commercial decision to be CORRECT. What it needs a decision for is to be
 * REACHABLE: wiring a money path to a design whose business rule the owner has not settled is
 * exactly what the standing limits forbid.
 *
 * **And F237 is why that is written here in capitals rather than left implicit.** F237 was a
 * mechanism recorded as DONE whose production callers never existed, so a guard that read as
 * working was inert for every real order. The difference between that and this is not the code —
 * it is that this says so. A reader who wants the replacement reachable needs Q1 answered and one
 * route; a reader who assumes it already works has been told otherwise.
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
 *    stamped `LINE_STOCK_CARRIED`. *"Do not duplicate stock movements"* is satisfied by the stamp:
 *    the goods never came back, so nothing goes out again.
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
    public const REPLACED = 'replaced';

    /** Is the additive column present? Without it there is no link, so there is no operation. */
    public static function available(): bool
    {
        return \Illuminate\Support\Facades\Schema::hasColumn('orders', 'replaced_order_id');
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

        $replacement = null;

        try {
            DB::transaction(function () use ($original, &$replacement, $lines) {
                /* Re-read under the transaction and re-test, so two concurrent calls cannot both
                   pass the checks above and both write. */
                if (Order::where('replaced_order_id', $original->id)->exists()) {
                    throw new \RuntimeException(self::REFUSED_ALREADY_REPLACED);
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
        } catch (\RuntimeException $theReplacementWasRefused) {
            $reason = $theReplacementWasRefused->getMessage();
            $state = str_starts_with($reason, self::REFUSED_CANCEL_FAILED)
                ? self::REFUSED_CANCEL_FAILED
                : self::REFUSED_ALREADY_REPLACED;

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
