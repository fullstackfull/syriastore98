<?php

namespace App\Support\Metrics;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Shetabit\Visitor\Models\Visit;

/**
 * WHAT THE MERCHANT ANALYTICS DASHBOARD SHOWS. P5-4 / ANA.
 *
 * Every figure here is either delegated to the P5-2 metric layer or counted from a table that really
 * records the thing. §3 of the Phase 05 plan forbids inventing a metric, and the data basis for each of
 * the four the slice asks for was measured before this class was written (PHASE-05-EXECUTION-PLAN, P5-4):
 *
 *   MONEY IS NOT RE-IMPLEMENTED. `payments` and `profit` come from {@see FinanceLedgerFigures}, which
 *   already owns the hard parts: the ledger-coverage start (`store_order_financials` holds nothing before
 *   2026-11-02 and is not backfilled), `assumingUnknownCurrencyIs()` for the rows whose `currency_code` is
 *   NULL, and the refusal to add two real currencies -- a tile whose figure would be a number with no unit
 *   shows no number and says why instead. Duplicating that would be two places to get it wrong.
 *
 *   ORDERS AND VISITS ARE COUNTED OVER THE SAME WINDOW, which is the only way their ratio means anything.
 *   The window is the last {@see self::RECENT_DAYS} days and the period is stated on every tile.
 *
 *   CONVERSION IS ORDERS / VISITS, AND `visits` IS NOT A TABLE NOTHING WRITES: `ActiveTheme` logs one row
 *   per themed storefront request through `SanitisedVisitor`. But it is REQUESTS, not unique visitors --
 *   there is no cookie-level de-duplication and bots and link prefetchers are counted -- so the figure is
 *   labelled "visits" and carries that limit as a caveat on every render. No funnel, session or bounce
 *   rate appears here, because none of those is recorded.
 *
 *   NEW VERSUS RETURNING needs nothing invented: a customer who ordered before this window is returning.
 *
 * THE STORE PREDICATE IS EXPLICIT EVERYWHERE. `Order` is absent from `config/tenancy.php`'s
 * `scoped_models`, so no global scope filters it for any principal, and the visit rows are read by
 * `store_id` -- the column that was found empty and fixed in the same breath as this slice. Counting is
 * done through Eloquent rather than `DB::table()` on purpose: a raw query here would have to be declared
 * in the three raw-query tenancy inventories, and an Eloquent read with its own `where` needs no exception.
 */
final class MerchantAnalyticsFigures
{
    /** The activity window. Short enough to be about now, long enough that a quiet week is not a cliff. */
    private const RECENT_DAYS = 30;

    /** U+2066 / U+2069: isolate a run so a number lays out left-to-right inside an Arabic paragraph. */
    private const LRI = "\u{2066}";

    private const PDI = "\u{2069}";

    /**
     * @return array{
     *     coverage: LedgerCoverage,
     *     payments: array<string, mixed>,
     *     profit: array<string, mixed>,
     *     orders: array{value: ?string, period: string, hint: string},
     *     visits: array{value: ?string, period: string, hint: string},
     *     conversion: array{value: ?string, period: string, hint: string},
     *     customers: array{value: ?string, period: string, hint: string},
     *     redemptions: array{value: ?string, period: string, hint: string}
     * }
     */
    public static function forStore(int $storeId, string $storeCurrency, ?CarbonInterface $now = null): array
    {
        // The money half, unchanged and not restated: one owner for currency folding and ledger coverage.
        $money = FinanceLedgerFigures::forStore($storeId, $storeCurrency);

        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);
        $from = $now->subDays(self::RECENT_DAYS - 1)->startOfDay();
        $to = $now->endOfDay();
        $period = __('last :count days', ['count' => number_format(self::RECENT_DAYS)]);

        $orders = self::countOrders($storeId, $from, $to);
        $visits = self::countVisits($storeId, $from, $to);

        return [
            'coverage' => $money['coverage'],
            'payments' => $money['payments'],
            'profit' => $money['profit'],
            'orders' => [
                'value' => self::ltr(number_format($orders)),
                'period' => $period,
                'hint' => __('Orders placed in this period, whatever their payment status. It is not a money figure and does not exclude orders later cancelled or refunded.'),
            ],
            'visits' => [
                'value' => self::ltr(number_format($visits)),
                'period' => $period,
                'hint' => self::visitsMeaning(),
            ],
            'conversion' => self::conversion($orders, $visits, $period),
            'customers' => self::customers($storeId, $from, $to, $period),
            'redemptions' => self::redemptions($storeId, $storeCurrency, $from, $to, $period),
        ];
    }

    /**
     * WHAT COUPONS COST THIS STORE IN THIS PERIOD. P5-5, section 9.
     *
     * The figure is only shown when `CouponRedemption` says it may be: that metric decides per
     * window, from `CouponRedemptionCoverage`, whether the window lies inside the period whose
     * redemption records are complete. For a window reaching back before the P4-F134/F135 recording
     * fix it is not authoritative -- those records are both under- and over-counted and history is
     * deliberately not backfilled -- and this tile then shows NOTHING rather than a number with a
     * warning under it.
     *
     * THAT CHOICE IS THE POINT, not caution. A merchant who is shown a figure and a sentence saying
     * it cannot be trusted will read the figure; the component's own `value => null` state exists so
     * that "we cannot tell you this yet, and here is why" is sayable without printing a number.
     * The /finance tiles make the same choice for the same reason.
     */
    private static function redemptions(
        int $storeId,
        string $storeCurrency,
        CarbonInterface $from,
        CarbonInterface $to,
        string $period,
    ): array {
        $result = (new CouponRedemption())->for(
            MetricScope::store($storeId),
            MetricWindow::between(CarbonImmutable::instance($from), CarbonImmutable::instance($to)),
        );

        if (! $result->isAuthoritative()) {
            $coverage = CouponRedemptionCoverage::forStore($storeId);

            return [
                'value' => null,
                'period' => $period,
                'hint' => $coverage->hasNothingComplete()
                    ? __('No coupon redemption in this store has a complete record, so this figure cannot be shown. Redemptions are recorded in full for orders placed from now on; earlier ones are not reconstructed.')
                    : __('Coupon redemptions are recorded in full only from :date, which is after this period began, so this figure cannot be shown. Earlier redemptions are not reconstructed. Orders in this store with a discount but no redemption record: :count.', [
                        'date' => self::day($coverage->completeFrom()),
                        'count' => number_format($coverage->ordersWithoutRow()),
                    ]),
            ];
        }

        /*
         * `assumingUnknownCurrencyIs` for the reason the metric layer's own guidance gives: reading
         * `->total()` on a result with more than one currency bucket THROWS, and a store whose older
         * orders carry no currency_code has exactly that shape. Folding the unknown bucket into the
         * store's own currency is what FinanceLedgerFigures does, and it is stated, not silent.
         */
        $result = $result->assumingUnknownCurrencyIs($storeCurrency);
        $buckets = array_filter(
            $result->byCurrency(),
            static fn ($amount, $currency) => $currency !== '' && (float) $amount !== 0.0,
            ARRAY_FILTER_USE_BOTH
        );

        if (count($buckets) > 1) {
            // Two real currencies and nothing converted them. No number is invented.
            return [
                'value' => null,
                'period' => $period,
                'hint' => __('Coupon discounts in this period are recorded in more than one currency and have not been converted, so no single total can be shown.'),
            ];
        }

        return [
            'value' => self::ltr(number_format((float) $result->total(), 2) . ' ' . $storeCurrency),
            'period' => $period,
            'hint' => __('The total discount given by coupons on orders in this period. Recorded in full from :date; earlier redemptions are not included and are not reconstructed.', [
                'date' => self::day(CouponRedemptionCoverage::forStore($storeId)->completeFrom()),
            ]),
        ];
    }

    /** A date a merchant reads, or the word for "the beginning" when the record covers everything. */
    private static function day(?CarbonInterface $date): string
    {
        return $date === null
            ? __('the beginning')
            : self::ltr($date->toDateString());
    }

    /** The one sentence every visit-derived figure has to carry. */
    private static function visitsMeaning(): string
    {
        return __('Page views of your storefront, counted once per request. It is not a count of people: the same visitor browsing five pages is five, and automated traffic such as search-engine crawlers and link previews is included.');
    }

    /**
     * Orders per visit, as a percentage.
     *
     * NO VISITS MEANS NO RATIO, not zero per cent: dividing by nothing is undefined, and a store whose
     * visit log is empty (a brand-new store, or one whose theme is unset so `ActiveTheme` never logs)
     * would otherwise be told its conversion is 0%, which reads as a failure rather than as no data.
     *
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function conversion(int $orders, int $visits, string $period): array
    {
        $meaning = __('Orders divided by storefront page views in this period.').' '.self::visitsMeaning();

        if ($visits <= 0) {
            return [
                'value' => null,
                'period' => $period,
                'hint' => $meaning.' '.__('No page views were recorded in this period, so there is nothing to divide by.'),
            ];
        }

        $rate = ($orders / $visits) * 100;

        return [
            // Two decimals, because a real storefront rate is commonly under 5% and whole numbers hide it.
            'value' => self::ltr(number_format($rate, 2).'%'),
            'period' => $period,
            'hint' => $orders > $visits
                ? $meaning.' '.__('There are more orders than recorded page views, so some orders arrived by a route that logs no page view.')
                : $meaning,
        ];
    }

    /**
     * New versus returning buyers in the window.
     *
     * "Returning" is decided by whether the customer had ANY earlier order at this store, not by anything
     * inside the window, so a buyer whose first-ever order is in this period counts as new exactly once.
     * A guest order with no `customer_id` cannot be attributed to a person and is excluded from both, with
     * the count stated rather than folded into "new".
     *
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function customers(int $storeId, CarbonInterface $from, CarbonInterface $to, string $period): array
    {
        $buyers = Order::query()
            ->where('store_id', $storeId)
            ->whereBetween('order_date', [$from, $to])
            ->whereNotNull('customer_id')
            ->where('customer_id', '>', 0)
            ->distinct()
            ->pluck('customer_id')
            ->all();

        $guestOrders = Order::query()
            ->where('store_id', $storeId)
            ->whereBetween('order_date', [$from, $to])
            ->where(fn ($q) => $q->whereNull('customer_id')->orWhere('customer_id', '<=', 0))
            ->count();

        $returning = $buyers === [] ? 0 : Order::query()
            ->where('store_id', $storeId)
            ->whereIn('customer_id', $buyers)
            ->where('order_date', '<', $from)
            ->distinct()
            ->pluck('customer_id')
            ->count();

        $new = max(0, count($buyers) - $returning);

        $hint = __('Buyers in this period, split by whether they had ordered from this store before it. A buyer is counted once however many orders they placed.');

        if ($guestOrders > 0) {
            $hint .= ' '.__('Orders placed without a customer account cannot be attributed to a person and are counted in neither: :count.', [
                'count' => number_format($guestOrders),
            ]);
        }

        return [
            'value' => self::ltr(__(':new new · :returning returning', [
                'new' => number_format($new),
                'returning' => number_format($returning),
            ])),
            'period' => $period,
            'hint' => $hint,
        ];
    }

    private static function countOrders(int $storeId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Order::query()
            ->where('store_id', $storeId)
            ->whereBetween('order_date', [$from, $to])
            ->count();
    }

    /**
     * Visits are read by `store_id`, the column every analytics surface in this product filters on. It was
     * NULL on every row until the attribution fix that shipped with this slice, so a figure built on it
     * before then would have been a confident zero.
     */
    private static function countVisits(int $storeId, CarbonInterface $from, CarbonInterface $to): int
    {
        return Visit::query()
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from, $to])
            ->count();
    }

    private static function ltr(string $value): string
    {
        return self::LRI.$value.self::PDI;
    }
}
