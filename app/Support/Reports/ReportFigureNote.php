<?php

namespace App\Support\Reports;

use App\Support\Metrics\CaveatedFigure;
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
     * GAP-152 / P05-04 — THE CAVEAT LIST THESE FIGURES CARRY, DECLARED ONCE.
     * =============================================================================================
     *
     * Before this, every one of the five composites below ended with its own
     * `MetricCaveat::message(MetricCaveat::FLOAT_PRECISION)`. That is five literals naming one
     * claim, which is the shape GAP-217's own next action warned about in terms -- "never a fresh
     * literal, since one literal duplicated across four blades is what let two of them diverge in
     * the first place". The sentences moved here and the CAVEAT did not: it was still composed per
     * method. Now the list is declared once and the words are DERIVED from it by
     * `CaveatedFigure`, whose `hint()` is computed from `caveats()`, so a caveat added here reaches
     * every screen and every export without a composite changing.
     *
     * WHY `FLOAT_PRECISION` AND NOTHING ELSE, unchanged and still measured: every money figure on
     * these report screens is accumulated in PHP as `+= (float) $order->...` off
     * `orders.final_price`, `delivery_price` and `coupon_price`. `payment_status` appears ZERO
     * times in the controller's report handlers, so a settlement caveat would be an invented
     * inclusion rule -- the owner's D1/D2 territory.
     *
     * @return list<string>
     */
    private static function moneyCaveats(): array
    {
        return [MetricCaveat::FLOAT_PRECISION];
    }

    /**
     * ONE MONEY FIGURE ON A REPORT SCREEN, as a carrier: its definition, and the caveats derived
     * from the one list above.
     *
     * THE PERIOD IS THE CALLER'S, AND AN EMPTY ONE IS NOT AN OVERSIGHT. The five `*Hint()`
     * composites are read as TEXT by surfaces that state the period themselves -- `<x-ss.stat>`
     * makes `period` a required prop, and the exports write a Period column of their own -- so a
     * carrier built only to be asked for `hint()` makes no period claim, and nothing renders one.
     * A caller with a real period (`_sales_stats` has `$periodLabel`) passes it and gets a carrier
     * `<x-ss.figure>` can render whole.
     */
    public static function figure(?string $value, string $period, string ...$meaning): CaveatedFigure
    {
        return CaveatedFigure::of($value, $period, self::moneyCaveats(), $meaning);
    }

    /** The "Order value" figure, whole: value, period and the words derived from the caveat list. */
    public static function orderValueFigure(?string $value, string $period): CaveatedFigure
    {
        return self::figure($value, $period, self::orderValue());
    }

    public static function grossOrderValueFigure(?string $value, string $period): CaveatedFigure
    {
        return self::figure($value, $period, self::grossOrderValue());
    }

    public static function shippingChargedFigure(?string $value, string $period): CaveatedFigure
    {
        return self::figure($value, $period, self::shippingCharged());
    }

    public static function couponValueAppliedFigure(?string $value, string $period): CaveatedFigure
    {
        return self::figure($value, $period, self::couponValueApplied());
    }

    /**
     * THE NINE-COLUMN ROW'S DISCLOSURE: a set, not a figure, and `CaveatedFigure::forFigures()` is
     * the carrier shape for exactly that -- caveats qualifying SEVERAL figures, with no value of
     * its own, so `isSingleFigure()` is false and a tile component refuses it rather than printing
     * "Not available" where a number belongs.
     *
     * `MONEY_FLOAT_PRECISION` rather than `FLOAT_PRECISION`: the same measured claim, pluralised,
     * because "This figure" leaves the reader of a six-money-column row guessing which.
     *
     * @param  list<string>  $meaning
     * @param  array<string, string>  $statedAs
     */
    public static function moneyRowFigures(string $period = '', array $meaning = [], array $statedAs = [], array $extraCaveats = []): CaveatedFigure
    {
        return CaveatedFigure::forFigures(
            $period,
            array_merge([MetricCaveat::MONEY_FLOAT_PRECISION], $extraCaveats),
            $meaning,
            $statedAs,
        );
    }

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
        return self::orderValueFigure(null, '')->hint();
    }

    /** The same composition for the gross figure. */
    public static function grossOrderValueHint(): string
    {
        return self::grossOrderValueFigure(null, '')->hint();
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
        return self::shippingChargedFigure(null, '')->hint();
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
        return self::couponValueAppliedFigure(null, '')->hint();
    }

    /** The coupon tile's existing definition, kept verbatim so both language files keep resolving it. */
    public static function couponValueApplied(): string
    {
        return __('The amount discounted by coupons, already deducted from order value.');
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
        return self::moneyRowFigures('')->hint();
    }

    /**
     * The export cell for the two sales reports: the precision caveat PLUS the screen's own
     * ledger-sourced collected-payments figure.
     *
     * P5-R7-B. `_sales_stats.blade.php` has shown a "Payments received" tile since ANA-005, and
     * the file those screens download carried nothing about it -- the chart handlers passed the
     * tile to the view and never into the session the export replays. So the page said what
     * customers paid and the spreadsheet said only what they were asked to pay, which is the
     * largest caveat an export of these reports can drop.
     *
     * IT GOES IN THE DISCLOSURE COLUMN, NOT A NEW ONE, and that is a correctness decision rather
     * than a convenience. Every other column in these files is PER PERIOD; the collected figure
     * is ONE total for the whole window. An eleventh column would repeat that window total on
     * every period row, and a reader sorting or filtering the file would take it for that row's
     * collected amount -- inviting exactly the misreading this work exists to prevent. A sentence
     * can say "over this report's period" and a column header cannot.
     *
     * COMPOSED SERVER-SIDE from the tile the screen rendered, so the file is in the request's
     * locale and cannot drift from the page. `$tile['value']` is already a formatted money string
     * carrying its own currency; it is not re-formatted here and no arithmetic is done on it.
     *
     * GAP-152 / P05-04 — AND THE LEDGER FIGURE'S OWN CAVEATS, WHICH THIS CELL USED TO DROP.
     *
     * MEASURED FROM SOURCE before this change: the cell was `moneyRowHint() . ' ' . <the value>`.
     * It read `$tile['value']` and nothing else -- not `$tile['hint']`, not `$tile['caveats']` --
     * so the file carried the ledger NUMBER with none of the disclosures the screen prints beside
     * the same number: the orders with no financial record, the amount assumed into the store
     * currency, the two-currency refusal. Phase 05's rule is that an export which drops a caveat is
     * a defect, and `ReportController::salesCollectionTile()` had already flattened a
     * `CaveatedFigure` with `->toArray()`, whose wire form carries `caveats` for precisely this
     * reason ("so a gate can assert the mechanism GENERICALLY" -- `CaveatedFigure::toArray()`).
     * The list was in this method's hand and it composed a sentence instead of deriving one.
     *
     * THE TILE'S CAVEATS ARE ANSWERED BY THE TILE'S OWN HINT, which is `FinanceLedgerFigures`'
     * pattern rather than a shortcut: that hint is itself DERIVED from the same caveat list and
     * already holds one sentence per caveat, several of them quantified in ways the vocabulary's
     * generic sentence cannot be ("At least 3 paid orders have no product cost on record"). So
     * declaring it as the answer to each of the tile's caveats records where they are discharged,
     * `hint()` collapses the duplicate, and the reader sees the screen's own words once. The
     * generic sentence would be a second, weaker wording of the same facts.
     *
     * A TILE WITH NO `caveats` KEY IS NOT AN ERROR. A session saved before GAP-152, and every
     * fixture written against the old shape, holds `value`/`period`/`hint` only; the cell then says
     * what it said before plus whatever hint it was given, and nothing throws.
     *
     * @param array{value: ?string, period?: string, hint?: string, caveats?: list<string>}|\App\Support\Metrics\CaveatedFigure|null $tile
     */
    public static function salesRowHint($tile): string
    {
        return self::salesRowFigures($tile)->hint();
    }

    /**
     * The same disclosure as a carrier, so a caller or a gate can read the caveat KEYS the cell's
     * words came from rather than matching sentences.
     *
     * @param array{value: ?string, period?: string, hint?: string, caveats?: list<string>}|\App\Support\Metrics\CaveatedFigure|null $tile
     */
    public static function salesRowFigures($tile = null): CaveatedFigure
    {
        /* NULL IS "the question cannot be asked" -- no store chosen, or a window the report
           cannot express -- and a null `value` is the same thing one level in. Neither is zero,
           so neither gets a figure: the file simply says what it said before. */
        if ($tile === null || ($tile['value'] ?? null) === null) {
            return self::moneyRowFigures();
        }

        $meaning = [__('Payments received over this report\'s period, from the payment ledger: :value. This is one total for the whole period, not a figure for each row.', [
            'value' => (string) $tile['value'],
        ])];

        $tileHint = trim((string) ($tile['hint'] ?? ''));
        $tileCaveats = [];
        $statedAs = [];

        foreach ((array) ($tile['caveats'] ?? []) as $caveat) {
            $caveat = (string) $caveat;

            /* AN UNKNOWN KEY IS SKIPPED RATHER THAN PASSED ON. `CaveatedFigure` throws on one, and
               this list can come out of a SESSION written by an earlier revision of the product --
               a stale key would turn a merchant's download into an HTTP 500. */
            if (! MetricCaveat::exists($caveat)) {
                continue;
            }

            $tileCaveats[] = $caveat;

            /*
             * THE ROW'S OWN CAVEAT KEEPS ITS OWN SENTENCE. If a future ledger metric ever attaches
             * `MONEY_FLOAT_PRECISION` too, `array_unique` in the carrier would collapse the two
             * keys into one and this declaration would REPLACE the plural precision statement with
             * the tile's hint -- silently deleting from the file the one sentence GAP-217 added to
             * it. Not live today (`CollectedCustomerPayments` attaches no caveat of its own and the
             * tile's come from `MetricResult::make()` and `assumingUnknownCurrencyIs()`), which is
             * exactly why it is guarded now rather than after.
             */
            if ($tileHint !== '' && $caveat !== MetricCaveat::MONEY_FLOAT_PRECISION) {
                $statedAs[$caveat] = $tileHint;
            }
        }

        if ($tileHint !== '') {
            $meaning[] = $tileHint;
        }

        return self::moneyRowFigures('', $meaning, $statedAs, $tileCaveats);
    }
}
