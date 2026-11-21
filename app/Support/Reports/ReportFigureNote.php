<?php

namespace App\Support\Reports;

use App\Support\Metrics\MetricCaveat;

/**
 * ONE SENTENCE PER REPORT FIGURE, read by the screen AND by the export that screen downloads.
 * =================================================================================================
 *
 * P05-15 / ANA-007. The Phase 05 rule is that an export carries the same caveats as the screen it
 * came from, and that an export which drops one is a defect. That rule is unenforceable while the
 * sentence lives as a string literal in the Blade, because the export is built somewhere else --
 * in `ReportController`, from a different file -- and nothing makes the two agree.
 *
 * WHAT WAS ACTUALLY MEASURED before this class existed (2026-11-21):
 *
 *  - The "Order value" definition was duplicated as an identical literal in FOUR blades:
 *    `_sales_stats`, `order_product_chart`, `order_category_chart`, `order_brand_chart`.
 *  - Only TWO of those four carried the precision caveat beside it, although all four figures are
 *    built the same way, so two screens disclosed a limitation the other two hid.
 *  - NONE of the five CSV exports carried either sentence. A merchant who downloaded the file got
 *    a bare money column with no statement that it is not payments received and not profit --
 *    the single most important thing the D1/D2 labelling work exists to say.
 *
 * So the sentences move here, every caller reads them from here, and the export cannot disagree
 * with the screen because there is only one string.
 *
 * WHY THE CAVEAT IS `FLOAT_PRECISION` AND WHY NOTHING ELSE WAS ADDED. Measured in
 * `ReportController`: every money figure on these report screens is accumulated in PHP as
 * `+= (float) $order->...` (or `array_sum()` / `->sum()`) off `orders.final_price`,
 * `delivery_price` and `coupon_price`, which are FLOAT columns. That is exactly the condition
 * `MetricCaveat::FLOAT_PRECISION` describes, and it holds for all of them with no exceptions.
 *
 * It is also the ONLY caveat these figures get. `payment_status` appears ZERO times in the
 * controller's report handlers, so a sentence about payments collected, settlement or refunds
 * would be an invented inclusion rule rather than a measured one -- and inventing monetary
 * definitions is precisely what the owner's D1/D2 decisions reserve to the owner. The definitions
 * these figures DO have are the labels those decisions settled, and the exports carry those labels
 * as their column headers already.
 */
final class ReportFigureNote
{
    /**
     * What "Order value" is, and -- the load-bearing half -- what it is not.
     *
     * The literal is kept verbatim from the four blades it replaces so that `en.json` and
     * `ar.json` keep resolving it; it is an existing translated key, not a new one.
     */
    public static function orderValue(): string
    {
        return __('The total value of items ordered. This is not payments received, and not profit.');
    }

    /** The same for the gross figure, whose settled label already says it includes shipping and tax. */
    public static function grossOrderValue(): string
    {
        return __('The full amount the orders came to: after coupons, and including shipping and tax. This is what customers were asked to pay, not what they paid, and not profit.');
    }

    /**
     * The screen hint, and the export cell, for a surface showing ONE money figure: the definition
     * and the one caveat that applies to it, in that order, separated by a single space.
     *
     * `order_category_chart` and `order_brand_chart` built exactly this string inline before this
     * class existed, so for those two the change is a refactor with no visible difference. For
     * `order_product_chart` and `_sales_stats` it adds the caveat they were missing.
     */
    public static function orderValueHint(): string
    {
        return self::orderValue() . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);
    }

    /** The same composition for the gross figure. */
    public static function grossOrderValueHint(): string
    {
        return self::grossOrderValue() . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);
    }

    /**
     * What "Shipping charged" is. GAP-217.
     *
     * Measured: `$TotalShippingCharge += (float) $order->delivery_price` over the orders in the
     * window (ReportController :585, :653, and via `->sum()` at :748). No payment-status
     * predicate, so it is what customers were ASKED for delivery, not what was collected.
     *
     * The second sentence is conditional because the arithmetic is: `SalesSummaryLabelTruthTest`
     * verified that `orders.final_price` includes shipping ONLY when the plan charges it
     * (`$delivery_price` is initialised to 0 and computed only when `shipping_method == 'on'`), so
     * claiming it is always part of the gross figure would be false for a plan that does not.
     */
    public static function shippingCharged(): string
    {
        return __('What customers were charged for delivery on these orders, whatever their payment status. Where the plan charges shipping, it is already part of order value including shipping and tax.');
    }

    /** The same composition for shipping: its definition and the one caveat that applies. */
    public static function shippingChargedHint(): string
    {
        return self::shippingCharged() . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);
    }

    /**
     * The coupon tile's EXISTING definition, kept verbatim so `en.json` and `ar.json` keep
     * resolving it, plus the caveat it was missing.
     *
     * This one already said what it is -- it is the only money tile on that card that did and
     * still had no precision statement -- which is why GAP-217 had to be corrected the same day it
     * was written: the row claimed both tiles carried nothing.
     */
    public static function couponValueAppliedHint(): string
    {
        return __('The amount discounted by coupons, already deducted from order value.')
            . ' ' . MetricCaveat::message(MetricCaveat::FLOAT_PRECISION);
    }

    /**
     * The export cell for a row carrying SEVERAL money columns, where "This figure" cannot say
     * which one it means.
     *
     * `order_report` and `bar_chart_order_report` write nine columns per period, SIX of them money.
     * Those files' column headers already carry the settled label of each figure, so what the
     * caveat column adds is the precision statement -- in the plural, which is the whole reason
     * `MONEY_FLOAT_PRECISION` exists beside `FLOAT_PRECISION`. No definition is repeated here,
     * because no single definition covers a nine-column row.
     */
    public static function moneyRowHint(): string
    {
        return MetricCaveat::message(MetricCaveat::MONEY_FLOAT_PRECISION);
    }
}
