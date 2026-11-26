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
     * authoritative data." P4-F134: `ordersave` recorded NO redemption at all, so the figure is
     * UNDER-counted. P4-F135: a refused coupon still wrote `order_coupon_details` and
     * `user_coupons`, so it is OVER-counted. Errors in both directions, neither quantified, so the
     * true value cannot even be bounded.
     *
     * BOTH ARE NOW REVIEWED AND FIXED (2026-11-20), AND THIS CAVEAT IS STILL CORRECT AND STILL
     * BLOCKING -- for the period before the fix. The fix is forward-only and historical redemption
     * rows are deliberately not backfilled, so a window that reaches back into that period really
     * does carry both errors. What changed is that it is no longer the ONLY thing section 9 can
     * say: a window lying entirely inside a store's complete period gets
     * {@see self::COUPON_REDEMPTION_COVERAGE} instead, which qualifies rather than disqualifies.
     * `CouponRedemption::for()` chooses between them from `CouponRedemptionCoverage`, per store.
     */
    public const COUPON_REDEMPTION_UNRELIABLE = 'coupon_redemption_unreliable';

    /**
     * Section 9, the POST-FIX form: the records are sound, and they start where they start.
     *
     * P4-F134's fix began recording redemptions on a route that recorded none, and P4-F135's
     * stopped recording refused ones -- but neither rewrote history, because backfilling financial
     * records is forbidden by the rule that governs the ledger (owner decision D1). So a figure
     * computed over a window AFTER a store's last unrecorded redemption is complete and may be
     * presented as fact; it simply does not extend back to the beginning of the store.
     *
     * QUALIFYING, NOT BLOCKING, and the distinction is the whole point: "this number is right, and
     * here is where it starts" is a different statement from "this number cannot be trusted", and a
     * merchant who is given the second when the first is true learns to ignore caveats.
     */
    public const COUPON_REDEMPTION_COVERAGE = 'coupon_redemption_coverage';

    /**
     * Section 11 rule 3. The rows summed are denominated in more than one currency and nothing
     * converted them. `MetricResult::total()` refuses to add them up; only the per-currency
     * breakdown means anything.
     */
    public const MIXED_CURRENCY = 'mixed_currency';

    /**
     * Section 11 rule 4. The figure comes off `orders.final_price`; aggregate on
     * `store_order_financials` (DECIMAL(15,2)) wherever both are available. GMV is the one metric
     * where they are not.
     *
     * THIS DOCBLOCK SAID "which is FLOAT -- about seven significant digits on a currency that runs
     * to millions". THAT BASIS IS REFUTED BY MEASUREMENT, in both directions, and the caveat it
     * grounds is still attached because its WORDING is a merchant-facing sentence and not
     * engineering's to rewrite (`GAP-287`, owner decision `J24`).
     * =============================================================================================
     *
     * 1. THE COLUMN IS NOT A `FLOAT`. `$table->float()` emits a MySQL **`double`**. Measured on
     *    10.11.14-MariaDB with the exact declarations the two migrations use: `COLUMN_TYPE` is
     *    `double`, `information_schema.NUMERIC_PRECISION` is 22, and **0 of 5 money values lost
     *    precision** -- `2500000.49`, `1234567.89` and `999999.99` all round-trip exactly. The
     *    control settles that this is discriminating rather than lucky: the same five values
     *    through a column declared literally `FLOAT` lose **3 of 5** (`2500000.49` -> `2500000`,
     *    `1234567.89` -> `1234570`). Script:
     *    `tests/Support/Security/bin/measure-float-money-basis.php`.
     *
     * 2. THE MAGNITUDE IS OVERSTATED BY THREE ORDERS OF MAGNITUDE, which is the half that matters
     *    for the sentence a merchant reads. Accumulation, not a single value, is what a report
     *    does -- and over 10,000 rows the running total diverged from the exact decimal by
     *    **0.0000111163 on 253,136,425.00**, with `DECIMAL(15,2)` exact to the cent. That is about
     *    a thousandth of a cent. The message promises a difference "in the smallest units"; the
     *    measured difference is a thousandth of one.
     *
     * 3. SO WHY IS THE CAVEAT STILL HERE. Because **the migrations are not the schema** (CLAUDE.md
     *    section 1) and nothing in this environment can read production's `orders` table. A column
     *    altered by hand reads however it was altered, and `SHOW CREATE TABLE orders` on production
     *    is the only thing that closes it. Withdrawing a disclosure on the strength of a FRESH
     *    schema would be the same error in the opposite direction.
     *
     * WHAT IS WRONG IS THEREFORE THE WORDING, NOT THE PRESENCE. A more accurate sentence is a
     * merchant-facing string on eleven surfaces with an Arabic translation beside it, and that
     * Arabic is content -- so it is escalated rather than guessed at. The existing Arabic is a
     * faithful translation of a sentence that overstates, so it is wrong in the same way and by the
     * same amount; one content decision fixes both.
     */
    public const FLOAT_PRECISION = 'float_precision';

    /**
     * THE SAME MEASURED CLAIM AS `FLOAT_PRECISION`, PLURALISED. Not a new monetary definition.
     *
     * `FLOAT_PRECISION` says "This figure", which is exactly right for a row carrying one money
     * figure and wrong for a row carrying several. `order_report` and `bar_chart_order_report`
     * export NINE columns per period, SIX of them money -- Order value, Average order value, the
     * two including shipping and tax, Shipping charged and Coupon value applied -- so a cell
     * saying "This figure" leaves the reader to guess which of the six it means.
     *
     * The basis is identical: every one of those six is accumulated in `ReportController` as
     * `+= (float) $order->...` off `orders.final_price`. This constant states that about all of
     * them at once. **It also inherits `FLOAT_PRECISION`'s refuted premise verbatim** -- this
     * paragraph read "which is FLOAT (see `FLOAT_PRECISION` above)" and the column is a `double`;
     * read that docblock for the measurement and for why the caveat stays attached anyway. The
     * accumulation this constant is specifically about is the half that was measured: 10,000 rows
     * diverge by 0.0000111163 on 253,136,425.00, so the plural form overstates by the same three
     * orders of magnitude as the singular. It adds
     * no inclusion rule, no payment-status predicate and no new definition of any figure -- those
     * belong to the owner's D1/D2 labelling decisions, and the column HEADERS already carry the
     * labels those decisions settled.
     */
    public const MONEY_FLOAT_PRECISION = 'money_float_precision';

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

    /**
     * SOME ORDERS IN THIS WINDOW HAVE NO LEDGER ROW, so a figure that reads the entitlement
     * record cannot see them.
     *
     * `store_order_financials` has exactly one migration -- `2026_11_02_000400_create_...` -- with
     * no `INSERT … SELECT` and no backfill anywhere in `database/migrations/`. It is written only
     * by `OrderObserver::created()`. So every order placed before that table existed has no row,
     * and any metric reading `payment_status`, `paid_amount`, `gross_profit`, `shipping_amount` or
     * `tax_amount` is blind to it.
     *
     * The caveat carries no date on purpose. `notes()['orders_without_ledger_row']` counts the
     * orders in the window that the ledger cannot see, which is measurable, self-adjusting, and
     * does not go stale the way a hardcoded cut-over date would. Zero means the window is wholly
     * covered and the caveat does not fire.
     */
    public const ENTITLEMENT_RECORD_INCOMPLETE = 'entitlement_record_incomplete';

    /**
     * THE FIGURE COUNTS ONLY ORDERS ATTRIBUTABLE TO A RECOGNISABLE CUSTOMER.
     *
     * `orders.customer_id` is `INTEGER DEFAULT 0`, so an order with nobody attached and an order
     * belonging to "customer zero" are the same value and only a comparison separates them.
     * Counting id 0 as a customer would give one "customer" holding every unattributed order ever
     * placed -- a repeat-purchase rate near 100% for any store that takes them, and the most
     * flattering wrong number this schema can produce.
     *
     * **IT IS NOT "GUESTS EXCLUDED", AND THAT DISTINCTION COST A WRONG PREDICATE.** A guest order
     * CAN carry a real customer id: `PaymentService` sets
     * `$orderCustomerId = ! empty($request->customer_id) ? $request->customer_id : 0` on the guest
     * branch, and its own comment records that this is "the id of the Customer the handler found
     * or created from the billing info -- which is legitimate". Such a shopper is recognisable on
     * a later visit, so excluding them on `is_guest` alone would discard real repeat-purchase
     * signal -- most of it, for a store whose business is mostly guest checkout. Only
     * `customer_id <= 0` is unidentifiable, and only that is excluded.
     */
    public const UNIDENTIFIED_CUSTOMERS_EXCLUDED = 'unidentified_customers_excluded';

    /**
     * ANA-006, the FEE half. `gross_profit` is `(product_amount - discount_amount) - product_cost`
     * and subtracts no payment-processing fee, because THIS PRODUCT RECORDS NONE: measured
     * 2026-11-22, `store_order_financials` has no fee column, nothing anywhere stores what a
     * gateway charged, and no figure in the metric layer reads one. So for every store that takes
     * card payments the margin is overstated by the gateway's cut, silently.
     *
     * THIS CONSTANT STATES THE OMISSION AND DEFINES NOTHING. No rate is invented, no fee is
     * estimated and no figure changes value -- which is precisely why it could be added without an
     * owner decision while the fee DATA question (`D-N9`/`D-N13`: capture per transaction, or let
     * a merchant type a rate per method) remains reserved. Writing a fee into the arithmetic needs
     * a source and a rule; saying the arithmetic has neither needs no permission.
     *
     * UNCONDITIONAL, for the same structural reason as `EXCLUDES_SHIPPING_AND_TAX`. There is no
     * per-store or per-order signal to condition it on -- a caveat shown only "when fees apply"
     * would require knowing when they apply, which is the very thing that is missing.
     */
    public const EXCLUDES_PAYMENT_FEES = 'excludes_payment_fees';

    private const MESSAGES = [
        self::COST_UNKNOWN => 'Cost price was not recorded for some orders, so margin is overstated for those rows.',
        self::EXCLUDES_SHIPPING_AND_TAX => 'Margin excludes shipping and tax.',
        self::EXCLUDES_PAYMENT_FEES => 'Payment-processing fees are not subtracted: no fee is recorded for any order, so margin is overstated by whatever the gateway charged.',
        self::PARTIAL_REFUNDS_EXCLUDED => 'Partial refunds are not counted; only fully refunded orders are.',
        self::CANCEL_DATE_INCOMPLETE => 'Some cancellations carry no cancellation date and are dated by when the ledger row changed.',
        self::COUPON_REDEMPTION_UNRELIABLE => 'Coupon redemption records are known to be both incomplete and inflated. This figure is not authoritative.',
        self::COUPON_REDEMPTION_COVERAGE => 'Coupon redemptions are recorded in full only from the date shown; earlier orders are not included and are not reconstructed.',
        self::MIXED_CURRENCY => 'These amounts are in more than one currency and have not been converted.',
        self::FLOAT_PRECISION => 'This figure is stored at limited precision and may differ from the ledger in the smallest units.',
        self::MONEY_FLOAT_PRECISION => 'The money amounts in this row are stored at limited precision and may differ from the ledger in the smallest units.',
        self::RECONCILIATION_DIVERGENCE => 'The ledger and the order records disagree on this figure.',
        self::REFUND_AMOUNT_UNCONVERTED => 'Refund amounts carry no currency and have not been converted.',
        self::CURRENCY_UNRECORDED => 'Some of these orders predate currency recording and are counted in your store\'s currency.',
        self::ENTITLEMENT_RECORD_INCOMPLETE => 'Some orders in this period have no financial record, so they are not included in this figure.',
        self::UNIDENTIFIED_CUSTOMERS_EXCLUDED => 'Orders with no customer attached are not counted: they cannot be matched to a returning shopper.',
    ];

    /**
     * Caveats that mean the figure must not be presented as authoritative, however it is labelled.
     *
     * Only section 9 is in here, and it is in here by owner instruction rather than by engineering
     * judgement.
     *
     * `COUPON_REDEMPTION_COVERAGE` is deliberately NOT in here. It is the same subject matter, and
     * leaving it out is the substantive half of P5-5: a window that lies entirely inside a store's
     * complete period carries no unbounded error, so disqualifying it would be withholding a figure
     * the data supports. A window that reaches back before the recording fix still gets
     * `COUPON_REDEMPTION_UNRELIABLE` and is still disqualified.
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
