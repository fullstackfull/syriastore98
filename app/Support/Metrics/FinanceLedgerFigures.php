<?php

namespace App\Support\Metrics;

/**
 * THE TWO LEDGER FIGURES ON /finance, ASSEMBLED FOR THE PAGE. Owner decisions D1 and D2, P5-16.
 * =================================================================================================
 *
 * `Payments received` and `Estimated gross profit` are the first figures in the product read through
 * the metric layer (P5-2) instead of computed inline. This class is the seam between that layer and
 * a Blade tile: it decides WHICH PERIOD to read (D1), reads it, and turns the answer into the things a
 * tile needs -- a value, a period, a hint, and an honest "not available".
 *
 * THE PERIOD IS NEVER WIDENED BY THE CALLER. It comes from `LedgerCoverage`, which finds the day from
 * which the ledger can vouch for every order. A merchant cannot ask for a window earlier than that
 * and be handed zero; the figure starts where the record does and says so (D1 Option A).
 *
 * A CALLER MAY NARROW IT, AND ONLY THE SALES REPORT DOES. `/finance` and `/analytics` pass no window, so
 * they read the whole verified period exactly as they always have. The sales report is different: six
 * demand tiles there already answer a window the merchant picked, and `Payments received` has to stand
 * beside them without answering a different question under the same heading. So `forStore()` takes an
 * optional window, intersects it with coverage, and the intersection has three outcomes -- the two halves
 * of D1 Option A plus the one that is only a crash:
 *
 *   * the request starts inside the verified period  -> answered as asked, and the caller's own label is
 *                                                       kept so the tile reads like its neighbours
 *   * the request reaches back past it               -> answered from where the record begins, and the
 *                                                       tile's period is REPLACED with the real span.
 *                                                       Printing the requested span over a narrower
 *                                                       figure is the wrong-number-that-looks-right
 *   * the request ends before the record begins      -> REFUSED, naming the earliest answerable date.
 *                                                       Never zero: zero is a claim about the business,
 *                                                       and the ledger is making a claim about itself
 *
 * THE ORDERING IS RE-ASSERTED AFTER THE CLAMP. `MetricWindow`'s constructor throws when `from` is after
 * `to`, so the no-overlap case must be caught BEFORE the window is built. Applying a clamp without
 * re-checking the ordering is the defect that reached a live HTTP 500 in `AnalyticsPeriod`.
 *
 * WHAT A TILE MAY SAY, in the order the rules apply:
 *
 *   * nothing verified (orders exist, none has a ledger row)  -> no figure, and the reason
 *   * the verified period has not begun (the gap is today)    -> no figure, and what is actually known
 *   * more than one real currency                              -> no figure; the amounts are LISTED, never
 *                                                                 added and never converted
 *   * otherwise                                                -> the figure, in the currency of its rows
 *
 * BOTH FIGURES ARE DATED BY PAYMENT, and the tile says so. The period is set by ORDER date and the figures are
 * dated by the day the money arrived (`store_order_financials.paid_at`), so two things follow and the tile names both:
 *
 *   * a rowed order placed before the start and paid after it IS counted. That is right for cash and is pinned by
 *     test; "add an `order_date` predicate" is the obvious wrong repair and would silently drop recorded cash.
 *   * a cash-on-delivery order placed before the start and collected after it has NO ledger row, never gets one
 *     (`OrderFinancialService::syncFromOrder` returns at once when there is none) and is in neither figure. The tile
 *     says a payment on an unrowed order that arrived inside the period is not included.
 *
 * THE COUNTS NEVER OVERLAP. Orders with no ledger row are reported as two disjoint numbers -- those placed before
 * the start, and those with no date at all -- because the first version printed the all-time total and then its own
 * undated subset ("3" then "2") and it read as five.
 *
 * UNRECORDED CURRENCY is folded into the store currency (`MetricResult::assumingUnknownCurrencyIs`) and
 * SAID: the metric layer's own guidance is that wiring `->total()` into a tile without it is an HTTP 500
 * for every store with history. What is assumed is that rows carrying no currency were in the currency the
 * store uses now, true for every store that has not changed currency. The note follows the metric's own
 * `CURRENCY_UNRECORDED` caveat rather than the SIGN of the folded amount. A BLANK store-currency setting is treated
 * as a missing one (`?? 'USD'` does not catch an empty string), and every currency code is compared UPPER-CASED:
 * MySQL's GROUP BY collapses `usd` and `USD` into one bucket while a PHP array key does not, so a lower-case setting
 * beside upper-case rows used to read as two currencies. A bucket that sums to nothing is not a currency the merchant
 * was paid in, so it does not make a figure "mixed" (one free order in a new currency hid a correct total).
 *
 * MONEY IS ISOLATED LEFT-TO-RIGHT. In an Arabic (right-to-left) page the sign of a negative profit landed after the
 * number ("USD 20.00-"), and in a list of amounts the numbers detached from their currency codes, which can swap the
 * pairing. Every amount that carries a code or a sign is therefore wrapped in U+2066 .. U+2069 (LRI .. PDI). They are
 * invisible in a left-to-right page and keep each `amount CODE` together in a right-to-left one. The design system
 * documents the same failure and its cure for identifiers (`.ss-ident`).
 *
 * NO TRY/CATCH, BY CHOICE. The known failure (two currencies) is handled before `total()` is reached; any
 * other fault should be loud. Swallowing it would turn a latent bug into a merchant page that quietly shows
 * "not available" and hides why -- the pattern this codebase's own observer comment calls "the silence".
 *
 * NOTHING HERE WRITES. The metrics are read-only, this class only reads them, and
 * `FinanceLedgerFiguresTest::test_reading_the_ledger_changes_nothing_that_is_stored` pins it.
 */
final class FinanceLedgerFigures
{
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * @param  ?MetricWindow  $requested  the window the merchant chose, or null to read the whole verified period
     * @param  ?string  $requestedLabel  how that window is named on the surface, kept when nothing was clamped
     * @return array{coverage: LedgerCoverage, payments: array<string, mixed>, profit: array<string, mixed>}
     */
    public static function forStore(
        int $storeId,
        string $storeCurrency,
        ?MetricWindow $requested = null,
        ?string $requestedLabel = null,
    ): array {
        $storeCurrency = strtoupper(trim($storeCurrency));
        $storeCurrency = $storeCurrency === '' ? self::FALLBACK_CURRENCY : $storeCurrency;

        $coverage = LedgerCoverage::forStore($storeId);
        $window = $coverage->window();
        $scope = MetricScope::store($storeId);
        $period = self::periodLabel($coverage);
        $refusal = null;

        if ($requested !== null && $window !== null) {
            [$window, $period, $refusal] = self::narrow($window, $requested, $coverage, $requestedLabel);
        }

        return [
            'coverage' => $coverage,
            'payments' => self::tile(
                $window === null ? null : (new CollectedCustomerPayments())->for($scope, $window),
                $storeCurrency,
                $period,
                $coverage,
                __('Money customers actually paid, counted on the day it was paid, including orders cancelled or refunded after payment. It is not order value and not profit.'),
                null,
                $refusal,
            ),
            'profit' => self::tile(
                $window === null ? null : (new StoreRevenueMargin(true))->for($scope, $window),
                $storeCurrency,
                $period,
                $coverage,
                __('Sales value minus product cost for orders that have been paid, counted on the day they were paid. Shipping is not included. If your prices include tax, the tax is part of the sales value.'),
                static fn (MetricResult $result): ?string => ($n = (int) ($result->notes()['rows_without_cost'] ?? 0)) > 0
                    ? __('At least :count paid orders have no product cost on record, so their profit may be overstated. Orders that mix products with and without a cost price are not counted here.', ['count' => number_format($n)])
                    : null,
                $refusal,
            ),
        ];
    }

    /**
     * The verified period AND the requested one, or the reason there is no overlap.
     *
     * Returns [window, period, refusal]. A null window with a non-null refusal is the "asking for last
     * year" case from D1 Option A: the record cannot answer, and it says the date from which it could.
     *
     * `$coverage->window()` is `allTime()` for a complete ledger, whose `from()` is null -- unbounded, so
     * nothing is clamped and the requested window is taken whole. `to()` is always null on a coverage
     * window, so the requested end is never moved: only the start can be.
     *
     * @return array{0: ?MetricWindow, 1: string, 2: ?string}
     */
    private static function narrow(
        MetricWindow $coverageWindow,
        MetricWindow $requested,
        LedgerCoverage $coverage,
        ?string $requestedLabel,
    ): array {
        $recordBegins = $coverageWindow->from();
        $from = $requested->from();
        $to = $requested->to();

        $clamped = $recordBegins !== null
            && ($from === null || $from->lessThan($recordBegins));

        if ($clamped) {
            $from = $recordBegins;
        }

        /* THE ORDERING, RE-ASSERTED. Only reachable when the clamp moved `from` past a bounded `to`,
           which is exactly the no-overlap case -- and `MetricWindow` would throw on it. */
        if ($from !== null && $to !== null && $from->greaterThan($to)) {
            return [
                null,
                $requestedLabel ?? self::periodLabel($coverage),
                __('Financial records begin on :date, and the period you chose ends before that, so there is nothing to show for it.', [
                    'date' => self::date($recordBegins),
                ]),
            ];
        }

        $window = MetricWindow::between($from, $to);

        /* `MetricWindow::describe()` is a DEBUG format ("2026-11-01 .. 2026-11-10") and must never
           reach a merchant tile, so an unlabelled window is spelled the same way a clamped one is. */
        return [$window, (! $clamped && $requestedLabel !== null) ? $requestedLabel : self::span($from, $to), null];
    }

    /** A window as a person reads it, in the page's locale. */
    private static function span(?\Carbon\CarbonInterface $from, ?\Carbon\CarbonInterface $to): string
    {
        if ($from === null) {
            return $to === null ? __('all time') : __('until :date', ['date' => self::date($to)]);
        }

        return $to === null
            ? __('from :date', ['date' => self::date($from)])
            : __(':from to :to', ['from' => self::date($from), 'to' => self::date($to)]);
    }

    private static function periodLabel(LedgerCoverage $coverage): string
    {
        if ($coverage->hasNothingVerified()) {
            return __('no financial records yet');
        }

        $from = $coverage->verifiedFrom();

        if ($from === null) {
            return __('all time');
        }

        return __('from :date', ['date' => self::date($from)]);
    }

    private static function date(\Carbon\CarbonInterface $date): string
    {
        return $date->locale(app()->getLocale())->translatedFormat('j F Y');
    }

    /** An amount (with its sign and code) as one left-to-right unit. */
    private static function ltr(string $text): string
    {
        return MoneyText::isolate($text);
    }

    /**
     * The amount per currency, upper-cased and with empty buckets dropped.
     *
     * @param  array<string, float>  $byCurrency
     * @return array<string, float>
     */
    private static function realBuckets(array $byCurrency): array
    {
        /* `true`: a ledger drops a currency nothing was really paid in, so a rounding artefact does
           not send the tile to the mixed-currency refusal. The WALLET figure passes `false` for the
           opposite and equally deliberate reason -- MoneyText::buckets() writes both out. */
        return MoneyText::buckets($byCurrency, true);
    }

    /**
     * @param  null|callable(MetricResult): ?string  $extra  a figure-specific limitation, or null for none
     * @param  ?string  $refusal  why the requested period cannot be answered at all, when that is the reason
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function tile(
        ?MetricResult $result,
        string $storeCurrency,
        string $period,
        LedgerCoverage $coverage,
        string $meaning,
        ?callable $extra,
        ?string $refusal = null,
    ): array {
        if ($result === null) {
            return [
                'value' => null,
                'period' => $period,
                // A refusal about the REQUESTED period outranks one about the record: the record may be
                // perfectly healthy and simply not cover the window the merchant picked.
                'hint' => $refusal ?? (
                // "Nothing verified" outranks "starts in the future": with no row at all there is no record
                // that could ever be verified from any date.
                    (! $coverage->hasNothingVerified() && $coverage->startsInTheFuture())
                        ? __('The latest order without a financial record is dated :date. A verified period can begin on :next at the earliest, so there is nothing to show yet.', [
                            'date' => self::date($coverage->latestOrderWithoutRowOn()),
                            'next' => self::date($coverage->verifiedFrom()),
                        ])
                        : __('No order has a financial record yet, so this figure cannot be shown.')
                ),
            ];
        }

        $result = $result->assumingUnknownCurrencyIs($storeCurrency);
        $notes = [$meaning];

        if ($coverage->datedOrdersWithoutRow() > 0) {
            $notes[] = __('Orders placed before :date with no financial record: :count. If any of them was paid inside this period, that payment is not included.', [
                'date' => self::date($coverage->verifiedFrom()),
                'count' => number_format($coverage->datedOrdersWithoutRow()),
            ]);
        }

        if ($coverage->undatedOrdersWithoutRow() > 0) {
            $notes[] = __('Orders with no date and no financial record cannot be placed in any period: :count.', [
                'count' => number_format($coverage->undatedOrdersWithoutRow()),
            ]);
        }

        if ($extra !== null && ($line = $extra($result)) !== null) {
            $notes[] = $line;
        }

        if ($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED)) {
            $notes[] = __('Includes :amount from records that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount((float) ($result->notes()['currency_assumed_amount'] ?? 0.0), $storeCurrency),
            ]);
        }

        $buckets = self::realBuckets($result->byCurrency());

        if (count($buckets) > 1) {
            // Two real currencies. Nothing is converted and no number is invented; the amounts are LISTED so
            // the tile is not a dead end for a store that changed currency.
            $listed = [];
            foreach ($buckets as $currency => $amount) {
                $listed[] = MoneyText::amount($amount, $currency);
            }

            $notes[] = __('These amounts are in more than one currency and are not combined: :amounts.', [
                'amounts' => implode(', ', $listed),
            ]);

            return ['value' => null, 'period' => $period, 'hint' => implode(' ', $notes)];
        }

        $currency = array_key_first($buckets) ?? $storeCurrency;
        $amount = $buckets[$currency] ?? 0.0;

        return [
            'value' => MoneyText::amount($amount, $currency),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }
}
