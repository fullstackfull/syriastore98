<?php

namespace App\Support\Metrics;

use InvalidArgumentException;

/**
 * THE KNOWN LIMITS OF A FIGURE, carried with the figure.
 *
 * `PHASE-05-METRIC-DEFINITIONS.md` section 11 rule 5: "A metric with a known gap displays the gap.
 * A number that hides its own limits is worse than no number." This is the vocabulary of those
 * gaps, and every one of them is a measured property of this schema rather than a disclaimer
 * written to be safe.
 *
 * A caveat is a machine key, never a sentence -- the same split F154 drew for order statuses. The
 * key is what a report branches on and what a test asserts; `message()` resolves the Arabic or
 * English a merchant reads. Translating the key would break both.
 *
 * Adding a constant here is a claim that a figure is limited in a way a merchant could act on
 * wrongly. It takes a citation, not an opinion.
 */
final class MetricCaveat
{
    /**
     * Section 8. `store_order_financials.product_cost` is a snapshot of `products.cost_price`
     * taken at order time. For any financial row written before the P1-SEC-44 fix it may be 0 --
     * or, historically, ANOTHER STORE'S cost. A margin computed over such a row is not wrong by a
     * little; where the cost is 0 it reports the whole sale as profit.
     */
    public const COST_UNKNOWN = 'cost_unknown';

    /**
     * Section 8. Margin is `(product_amount - discount_amount) - product_cost` and excludes
     * shipping and tax by construction. A merchant comparing it against a bank deposit will find
     * it smaller, and that is correct rather than missing money.
     */
    public const EXCLUDES_SHIPPING_AND_TAX = 'excludes_shipping_and_tax';

    /**
     * Section 5. Partial refunds are excluded entirely: per
     * `docs/finance/P4-F127-PARTIAL-REFUND-CAPABILITY.md` the ledger cannot represent them safely,
     * so no metric may imply it has counted them.
     */
    public const PARTIAL_REFUNDS_EXCLUDED = 'partial_refunds_excluded';

    /**
     * Section 4. `DashboardController::orderCancel` writes NO `cancel_date` -- measured; every
     * other cancellation door stamps it. A `cancel_date`-dated report therefore under-counts
     * cancellations from that one door, and this metric falls back to the financial row's
     * `updated_at` for them. Stated rather than silently worked around.
     */
    public const CANCEL_DATE_INCOMPLETE = 'cancel_date_incomplete';

    /**
     * Section 9, and the only caveat in this list that is BLOCKING rather than qualifying.
     *
     * Owner instruction: "Review P4-F134 and P4-F135 before using coupon-redemption records as
     * authoritative data." Both are open. P4-F134: `ordersave` may record no redemption at all, so
     * the figure is UNDER-counted. P4-F135: a refused coupon still writes `order_coupon_details`
     * and `user_coupons`, so the figure is OVER-counted. Errors in both directions, neither
     * quantified, so the true value cannot even be bounded.
     */
    public const COUPON_REDEMPTION_UNRELIABLE = 'coupon_redemption_unreliable';

    /**
     * Section 11 rule 3. The rows summed are denominated in more than one currency and nothing
     * converted them. `MetricResult::total()` refuses to add them up; only the per-currency
     * breakdown means anything.
     */
    public const MIXED_CURRENCY = 'mixed_currency';

    /**
     * Section 11 rule 4. The figure comes off `orders.final_price`, which is FLOAT -- about seven
     * significant digits on a currency that runs to millions. Aggregate on
     * `store_order_financials` (DECIMAL(15,2)) wherever both are available; GMV is the one metric
     * where they are not.
     */
    public const FLOAT_PRECISION = 'float_precision';

    /**
     * Section 6. The settlement-based and movement-based computations of net receipts disagreed.
     * The document is explicit that this "is a reconciliation defect and must fail the build, not
     * be averaged" -- so the test fails, and a screen that renders anyway must say so.
     */
    public const RECONCILIATION_DIVERGENCE = 'reconciliation_divergence';

    /**
     * SOME OF THIS FIGURE PREDATES CURRENCY RECORDING AND WAS ASSUMED INTO THE NAMED CURRENCY.
     *
     * `orders.currency_code` was added by `2026_10_12_000102` and that migration says, verbatim,
     * "historical rows keep NULL -- this migration does NOT backfill, because the correct
     * per-order historical rate is not recoverable from code, and inventing one would be exactly
     * the silent divergence GATE F exists to prevent. Reading code must tolerate NULL on
     * pre-migration orders."
     *
     * Tolerating NULL is what `Metric::sumByCurrency()` does: it buckets those rows under
     * `unknown`. But a merchant tile renders ONE number beside ONE currency symbol, and
     * `MetricResult::total()` refuses to add two buckets -- so on any store with history the
     * obvious wiring is an uncaught exception on a merchant admin route.
     *
     * `MetricResult::assumingUnknownCurrencyIs()` resolves that, and this caveat is the price of
     * it. Inventing a RATE is forbidden; stating an ASSUMPTION is not, and the assumption is
     * narrow: that orders placed before the column existed were in the currency the store uses
     * now. `notes()['currency_assumed_amount']` carries how much money that covers, so the
     * merchant can see whether the assumption matters at all.
     */
    public const CURRENCY_UNRECORDED = 'currency_unrecorded';

    /**
     * Section 11 rule 3, the second limb. `order_refunds.refund_amount` carries NO currency column
     * at all, so a refund figure derived from it cannot be converted and cannot be safely added to
     * a figure that was.
     */
    public const REFUND_AMOUNT_UNCONVERTED = 'refund_amount_unconverted';

    private const MESSAGES = [
        self::COST_UNKNOWN => 'Cost price was not recorded for some orders, so margin is overstated for those rows.',
        self::EXCLUDES_SHIPPING_AND_TAX => 'Margin excludes shipping and tax.',
        self::PARTIAL_REFUNDS_EXCLUDED => 'Partial refunds are not counted; only fully refunded orders are.',
        self::CANCEL_DATE_INCOMPLETE => 'Some cancellations carry no cancellation date and are dated by when the ledger row changed.',
        self::COUPON_REDEMPTION_UNRELIABLE => 'Coupon redemption records are known to be both incomplete and inflated. This figure is not authoritative.',
        self::MIXED_CURRENCY => 'These amounts are in more than one currency and have not been converted.',
        self::FLOAT_PRECISION => 'This figure is stored at limited precision and may differ from the ledger in the smallest units.',
        self::RECONCILIATION_DIVERGENCE => 'The ledger and the order records disagree on this figure.',
        self::REFUND_AMOUNT_UNCONVERTED => 'Refund amounts carry no currency and have not been converted.',
        self::CURRENCY_UNRECORDED => 'Some of these orders predate currency recording and are counted in your store\'s currency.',
    ];

    /**
     * Caveats that mean the figure must not be presented as authoritative, however it is labelled.
     *
     * Only section 9 is in here, and it is in here by owner instruction rather than by engineering
     * judgement.
     */
    private const BLOCKING = [
        self::COUPON_REDEMPTION_UNRELIABLE,
    ];

    public static function all(): array
    {
        return array_keys(self::MESSAGES);
    }

    public static function exists(string $caveat): bool
    {
        return array_key_exists($caveat, self::MESSAGES);
    }

    /** The sentence a merchant reads, in the merchant's own language. */
    public static function message(string $caveat): string
    {
        if (! self::exists($caveat)) {
            throw new InvalidArgumentException('Unknown metric caveat: ' . $caveat);
        }

        return __(self::MESSAGES[$caveat]);
    }

    public static function isBlocking(string $caveat): bool
    {
        return in_array($caveat, self::BLOCKING, true);
    }
}
