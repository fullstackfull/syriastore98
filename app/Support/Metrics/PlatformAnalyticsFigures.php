<?php

namespace App\Support\Metrics;

use App\Models\Store;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Shetabit\Visitor\Models\Visit;

/**
 * P5-7 — THE PLATFORM OPERATOR'S CROSS-TENANT FIGURES.
 * =================================================================================================
 *
 * The same metrics as `/analytics`, summed across every tenant, for the one principal entitled to
 * see them. It reuses `App\Support\Metrics` rather than writing its own SQL, so the platform view
 * and a merchant's own view cannot disagree about one number — which is the defect this reuse
 * exists to prevent, and the same reason `/analytics` and `/finance` share FinanceLedgerFigures.
 *
 * THIS CLASS IS NOT AN AUTHORIZATION BOUNDARY AND MUST NEVER BE THE ONLY ONE. It deliberately
 * reads EVERY store, so calling it from a merchant-reachable handler would be a cross-tenant
 * disclosure. `Store` is `Cachable` and is NOT in `config/tenancy.php`'s `scoped_models`, so no
 * global scope narrows the read for anybody: that is what makes the figures possible here and what
 * makes each caller's own gate load-bearing.
 *
 * IT HAS TWO CALLERS, AND THEY ARE GATED DIFFERENTLY. This sentence used to name one gate and was
 * left false when the second caller arrived:
 *
 *   - `forPlatform()` -- `PlatformAnalyticsController`, behind `PlatformOperatorOnly` ON THE ROUTE,
 *     which answers 403 (never 401 — the shipped Flutter app forces a logout on any 401).
 *   - `topStoresByOrderValue()` and `topStoresByPageViews()` -- `HomeController::
 *     handleSuperAdmin()`, which carries NO route middleware of its own. Its gate is the dispatch in `HomeController::index()`: an exact,
 *     case-sensitive `$user->type == 'super admin'`, stricter than `UserPolicy::isOperator()`.
 *     `OperatorDashboardTopStoresTest` holds a merchant out of it, and a mutation that dispatches
 *     every signed-in user there is caught.
 *
 * A third caller must bring a third gate. Nothing in this class will refuse it.
 *
 * THREE THINGS ARE HARDER ACROSS TENANTS THAN WITHIN ONE, and each is answered by refusing to
 * invent rather than by producing a number:
 *
 * 1. CURRENCY. `store_order_financials.currency_code` differs between stores, so a cross-tenant
 *    money figure is a SET of per-currency amounts, not a total. `MetricResult::total()` throws on
 *    more than one bucket, by design. Summing them would need historical FX rates at each order's
 *    own date, and no such record exists in this schema — `currency_rates` holds current rates and
 *    nothing stamps a rate onto an order. So payments are reported PER CURRENCY and never summed,
 *    and the screen says why. A single platform revenue number is the figure an operator most
 *    wants and the one this data cannot support honestly.
 *
 * 2. COVERAGE IS PER STORE, so a SUM over stores is only fully vouched for from the date the LAST
 *    contributing store became covered — the intersection, which is the latest `verifiedFrom()`
 *    among the included stores, not the earliest and not the platform's own oldest order. Using
 *    the earliest would claim coverage the ledger does not have for the other stores.
 *
 * 3. A STORE THE LEDGER CANNOT VOUCH FOR AT ALL is excluded from the money figures and COUNTED,
 *    never silently dropped and never counted as zero. A zero would read as "that tenant sold
 *    nothing"; the truth is "we cannot say".
 *
 * Counts of rows — stores, orders, buyers — need neither a currency nor the ledger, so they are
 * always available and are not subject to any of the above. **That sentence is load-bearing and was
 * briefly false:** the first version computed `buyers` on `CustomerRetention`'s DEFAULT basis, which
 * is settled-only and therefore reads the ledger. See `buyers()` for why it now passes `false`.
 */
final class PlatformAnalyticsFigures
{
    /** The merchant screen's own recent window, so the two surfaces mean the same thing by "recent". */
    private const RECENT_DAYS = 30;

    /**
     * @return array<string, mixed> tiles for resources/views/platform/analytics.blade.php
     */
    public static function forPlatform(?CarbonInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();

        $storeIds = Store::query()->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $coverage = self::coverageAcrossStores($storeIds, $now);

        return [
            'stores' => self::stores($storeIds, $coverage),
            'orders' => self::orders($storeIds, $now),
            'buyers' => self::buyers($storeIds, $now),
            'payments' => self::payments($coverage),
            'coverage' => $coverage,
        ];
    }

    /**
     * THE INTERSECTION, which is the whole of decision 2 above.
     *
     * `included` are the stores whose ledger can vouch for some period. `verifiedFrom` is the
     * LATEST start among them, because a sum is only as covered as its least-covered contributor;
     * a store covered for all time contributes no constraint and so contributes no date.
     *
     * @param  list<int>  $storeIds
     * @return array{included: list<int>, excluded: list<int>, verifiedFrom: ?CarbonImmutable, allComplete: bool, undatedWithoutRow: int}
     */
    private static function coverageAcrossStores(array $storeIds, CarbonImmutable $now): array
    {
        $included = [];
        $excluded = [];
        $verifiedFrom = null;
        $allComplete = true;
        $undatedWithoutRow = 0;

        foreach ($storeIds as $storeId) {
            $store = LedgerCoverage::forStore($storeId, $now);

            if ($store->window() === null) {
                $excluded[] = $storeId;

                continue;
            }

            $included[] = $storeId;
            $undatedWithoutRow += $store->undatedOrdersWithoutRow();

            if (! $store->isComplete()) {
                $allComplete = false;
            }

            $from = $store->verifiedFrom();

            if ($from !== null && ($verifiedFrom === null || $from->greaterThan($verifiedFrom))) {
                $verifiedFrom = $from;
            }
        }

        return [
            'included' => $included,
            'excluded' => $excluded,
            'verifiedFrom' => $verifiedFrom,
            'allComplete' => $allComplete,
            'undatedWithoutRow' => $undatedWithoutRow,
        ];
    }

    /** @param array{included: list<int>, excluded: list<int>, verifiedFrom: ?CarbonImmutable, allComplete: bool, undatedWithoutRow: int} $coverage */
    private static function stores(array $storeIds, array $coverage): array
    {
        $excluded = count($coverage['excluded']);

        return [
            'value' => self::ltr(number_format(count($storeIds))),
            'period' => __('now'),
            'hint' => $excluded === 0
                ? __('Every store on the platform. All of them have a financial record that can be read.')
                : __('Every store on the platform. :count of them have no financial record this view can read, so they are left out of the money figures below rather than counted as zero.', [
                    'count' => self::ltr(number_format($excluded)),
                ]),
        ];
    }

    /** How many stores each currency's list shows -- the panel has always been a top five. */
    private const TOP_STORES = 5;

    /** The fallback `ReportController::financeReport()` applies to a store with no currency setting. */
    private const FALLBACK_CURRENCY = 'USD';

    /**
     * P05-14 / P05-19 (GAP-162, GAP-167) -- THE OPERATOR DASHBOARD'S TOP STORES, BY ORDER VALUE.
     *
     * ONE RANKED LIST PER CURRENCY, NEVER ONE LIST. Point 1 of this class's docblock is the rule:
     * a cross-tenant money figure is a SET of per-currency amounts, because nothing stamps an
     * exchange rate onto an order. A ranking is a comparison, and comparing two currencies by
     * their digits is the same error as adding them. The panel this replaces did exactly that --
     * `SUM(final_price) ... ORDER BY total_amount DESC` across every store -- so a store trading in
     * a large-nominal currency outranked every dollar store by arithmetic alone.
     *
     * WITHIN one currency nothing changes: order value, highest first, top five. On a platform
     * whose stores all trade in one currency this is the list the dashboard always showed, now
     * printed in that currency instead of the signed-in operator's own store's.
     *
     * THE SCOPE IS A REQUIRED ARGUMENT, so the breadth of the read is written at the call site
     * (P05-19) rather than implied by which method was called. This class is still not an
     * authorization boundary -- see the docblock above -- and the caller is still responsible for
     * being reachable by the operator alone.
     *
     * THE BASIS IS `GrossMerchandiseValue`'s DEFAULT -- cancelled orders INCLUDED -- which is what
     * this panel has always summed and what `orders()` below already uses for the platform count.
     * The merchant dashboard's order-value figure excludes cancellations by owner decision F257;
     * whether the operator's ranking should follow is a decision F257 does not make, so it is
     * left as shipped and STATED on the panel rather than quietly changed here.
     *
     * AN ORDER WITH NO RECORDED CURRENCY takes its STORE's own currency setting. That is the same
     * assumption `MetricResult::assumingUnknownCurrencyIs()` makes for a merchant's tiles, applied
     * per store because here every row is a different store.
     *
     * @return array<string, list<array{store_id: int, name: string, orders: int, amount: float, value: string}>>
     *         currency code => that currency's stores, highest order value first
     */
    public static function topStoresByOrderValue(MetricScope $scope): array
    {
        $buckets = (new GrossMerchandiseValue())->perStore($scope, MetricWindow::allTime());

        if ($buckets === []) {
            return [];
        }

        $storeIds = array_values(array_unique(array_column($buckets, 'store_id')));

        // One query each, rather than the `Store::find()` per row the view used to run.
        $names = Store::query()->whereIn('id', $storeIds)->pluck('name', 'id');

        /*
         * NEWEST ROW FIRST, so that `pluck()` -- which keeps the LAST value it sees for a key --
         * ends on each store's OLDEST row. That is the row `ReportController::financeReport()`'s
         * `->value('value')` reads for the same setting, so a store with duplicate rows is given
         * the same currency on both screens instead of whichever the engine returned last.
         */
        $configured = \App\Models\Setting::query()
            ->whereIn('store_id', $storeIds)
            ->where('name', 'defult_currancy')
            ->orderByDesc('id')
            ->pluck('value', 'store_id');

        /** @var array<string, array<int, array{amount: float, orders: int}>> $byCurrency */
        $byCurrency = [];

        foreach ($buckets as $bucket) {
            $storeId = $bucket['store_id'];

            // A store deleted between the two queries has nothing to be listed under.
            if (! isset($names[$storeId])) {
                continue;
            }

            /*
             * THROUGH `CurrencySymbol::code()`, BOTH SOURCES. A currency code is merchant-written
             * -- it is a store setting, snapshotted onto each order -- so it is neither trusted to
             * be safe to print nor trusted to be a currency. `code()` answers both: three ASCII
             * letters, case folded, or '' .
             *
             * `clean()` ALONE WAS NOT ENOUGH, and this is the correction. It removes markup and
             * control characters, which a SYMBOL passes intact, so `$` and `ل.س` were taken for
             * currencies -- and P5-R14 measured that the two storefront-API order writers really
             * did stamp the symbol into `orders.currency_code`, with owner decision D-N34 leaving
             * those rows in place. A store whose orders came through both writers was therefore
             * listed once per spelling, each with a partial subtotal, under a heading reading `$`.
             *
             * A recorded value that is not a code falls back exactly as a missing one does: the
             * store's own setting, and only then the product-wide default. Two values that ARE
             * codes and normalise alike are summed below, so a store appears once per currency.
             */
            $recorded = \App\Support\Money\CurrencySymbol::code($bucket['currency'] ?? '');
            $ownSetting = \App\Support\Money\CurrencySymbol::code($configured[$storeId] ?? '');

            $currency = $recorded !== '' ? $recorded : ($ownSetting !== '' ? $ownSetting : self::FALLBACK_CURRENCY);

            // Summed, because 'usd' and 'USD' -- or a recorded code and the store's own -- are one
            // currency once normalised, and a store must appear once in its currency's list.
            $byCurrency[$currency][$storeId]['amount'] = round(($byCurrency[$currency][$storeId]['amount'] ?? 0.0) + $bucket['amount'], 2);
            $byCurrency[$currency][$storeId]['orders'] = ($byCurrency[$currency][$storeId]['orders'] ?? 0) + $bucket['orders'];
        }

        // Alphabetical, so the panel reads the same whichever order the engine grouped in.
        ksort($byCurrency);

        $ranked = [];

        foreach ($byCurrency as $currency => $stores) {
            // Highest order value first; the store id breaks a tie so two stores on the same
            // amount do not swap places between requests.
            uksort($stores, static fn (int $a, int $b): int => [$stores[$b]['amount'], $a] <=> [$stores[$a]['amount'], $b]);

            foreach (array_slice($stores, 0, self::TOP_STORES, true) as $storeId => $figure) {
                $ranked[$currency][] = [
                    'store_id' => (int) $storeId,
                    'name' => (string) $names[$storeId],
                    'orders' => (int) $figure['orders'],
                    'amount' => (float) $figure['amount'],
                    'value' => MoneyText::amount((float) $figure['amount'], (string) $currency),
                ];
            }
        }

        return $ranked;
    }

    /**
     * P05-19 (GAP-167) -- THE OPERATOR DASHBOARD'S MOST-VIEWED STORES.
     *
     * COUNTED BY THE DATABASE, FIVE ROWS BACK. What this replaces pulled the `store_id` of EVERY
     * row in the visit log into a PHP array and counted it there, on every load of the operator's
     * landing page. That table gains a row for every themed storefront request of every tenant,
     * crawlers included, and nothing prunes it -- so the page's memory use grew with the whole
     * platform's traffic. `store_id` is indexed (`AnalyticsPeriod` records that as verified
     * against the schema), and a grouped count over it is the read that index serves.
     *
     * THE SCOPE IS A REQUIRED ARGUMENT, as it is for the money ranking above. The visit log is
     * not in `config/tenancy.php`'s `scoped_models`, so no global scope narrows it for anybody;
     * the old read was cross-tenant by the absence of a predicate. Naming the stores also settles
     * what the old one got wrong at the edges: a page view logged against a store that no longer
     * exists is not a store, and took one of the five places before the view skipped it.
     *
     * WHAT THE NUMBER IS: page views, every one recorded, with no window. Requests rather than
     * people -- one visitor on five pages is five -- and automated traffic is in it. That is the
     * merchant screen's own definition of a visit (`MerchantAnalyticsFigures::visitsMeaning()`),
     * and the panel now says so. No window is introduced here: choosing one would change what the
     * operator is shown, not how it is computed.
     *
     * Like every method of this class it is NOT an authorization boundary. Its one caller is
     * `HomeController::handleSuperAdmin()`, gated as described on `topStoresByOrderValue()`.
     *
     * @return list<array{store_id: int, name: string, views: int}> most page views first
     */
    public static function topStoresByPageViews(MetricScope $scope): array
    {
        $counts = Visit::query()
            ->whereIn('store_id', $scope->storeIds())
            ->selectRaw('store_id, COUNT(*) as page_views')
            ->groupBy('store_id')
            ->orderByDesc('page_views')
            // The store id breaks a tie, so two stores level on views do not swap between requests.
            ->orderBy('store_id')
            ->limit(self::TOP_STORES)
            ->toBase()
            ->get();

        if ($counts->isEmpty()) {
            return [];
        }

        // One query, rather than the `Store::find()` per row the view used to run.
        $names = Store::query()->whereIn('id', $counts->pluck('store_id')->all())->pluck('name', 'id');

        $ranked = [];

        foreach ($counts as $row) {
            $storeId = (int) $row->store_id;

            // A store deleted between the two queries has nothing to be listed under.
            if (! isset($names[$storeId])) {
                continue;
            }

            $ranked[] = [
                'store_id' => $storeId,
                'name' => (string) $names[$storeId],
                'views' => (int) $row->page_views,
            ];
        }

        return $ranked;
    }

    /** @param list<int> $storeIds */
    private static function orders(array $storeIds, CarbonImmutable $now): array
    {
        if ($storeIds === []) {
            return self::nothingYet(__('No store exists yet, so there is nothing to count.'));
        }

        $result = (new GrossMerchandiseValue())->for(
            MetricScope::stores($storeIds),
            self::recentWindow($now)
        );

        return [
            'value' => self::ltr(number_format($result->rowCount())),
            'period' => self::recentPeriod(),
            'hint' => __('Orders placed in every store, whatever their payment status. A count of orders is not a count of sales: an unpaid or cancelled order is counted here.'),
        ];
    }

    /**
     * A HEADCOUNT OVER ALL ORDERS, which is what the label says and what the merchant screen's
     * tile of the same name means.
     *
     * `CustomerRetention(false)` and NOT its default. The default is `settledOnly = true`, an INNER
     * JOIN to `store_order_financials` on `payment_status = 'paid'` -- correct for a RETENTION rate
     * and wrong here, for two reasons found by review of the first version of this file:
     *
     *  1. It would make this a paid-only figure wearing a headcount's label, and it would silently
     *     contribute ZERO buyers for every store the ledger cannot vouch for -- the exact
     *     "that tenant sold nothing" misreading this class refuses elsewhere by excluding and
     *     counting such stores instead.
     *  2. `/analytics`'s own `Buyers` tile counts distinct `customer_id` on `orders` with no
     *     payment predicate at all. Two tiles with one label must mean one thing.
     *
     * The guest-order exclusion is disclosed by count, as the merchant tile does, because an order
     * with no customer account cannot be attributed to a person and so is in neither the numerator
     * nor the denominator of anything.
     *
     * @param  list<int>  $storeIds
     */
    private static function buyers(array $storeIds, CarbonImmutable $now): array
    {
        if ($storeIds === []) {
            return self::nothingYet(__('No store exists yet, so there is nothing to count.'));
        }

        $figures = (new CustomerRetention(false))->for(
            MetricScope::stores($storeIds),
            self::recentWindow($now)
        );

        $hint = __('People who can be recognised again, counted once each across the whole platform. Every order is counted whatever its payment status. One person with accounts in two stores is counted in each, because nothing links the two accounts.');

        if (($unattributed = (int) ($figures->notes()['orders_without_identifiable_customer'] ?? 0)) > 0) {
            $hint .= ' '.__('Orders with no customer account, which cannot be attributed to a person and are counted here at all: :count.', [
                'count' => self::ltr(number_format($unattributed)),
            ]);
        }

        return [
            'value' => self::ltr(number_format($figures->identifiedCustomers())),
            'period' => self::recentPeriod(),
            'hint' => $hint,
        ];
    }

    /**
     * PER CURRENCY, NEVER SUMMED — decision 1 above.
     *
     * @param array{included: list<int>, excluded: list<int>, verifiedFrom: ?CarbonImmutable, allComplete: bool, undatedWithoutRow: int} $coverage
     */
    private static function payments(array $coverage): array
    {
        if ($coverage['included'] === []) {
            return [
                'rows' => [],
                'period' => __('not yet available'),
                'hint' => __('No store has a financial record this view can read, so there is nothing to total. Nothing is reconstructed.'),
            ];
        }

        $window = $coverage['verifiedFrom'] === null
            ? MetricWindow::allTime()
            : MetricWindow::since($coverage['verifiedFrom']);

        $result = (new CollectedCustomerPayments())->for(MetricScope::stores($coverage['included']), $window);

        $rows = [];

        foreach ($result->byCurrency() as $currency => $amount) {
            $rows[] = [
                'currency' => $currency === MetricResult::UNKNOWN_CURRENCY ? __('Currency not recorded') : $currency,
                'amount' => self::ltr(number_format($amount, 2)),
            ];
        }

        $notes = [__('Money actually collected, read from the financial record. Each currency is shown on its own line and the lines are NOT added together: converting them would need the exchange rate on each order\'s own date, and no such record exists.')];

        if ($coverage['excluded'] !== []) {
            $notes[] = __('Stores left out because their financial record cannot vouch for any period: :count.', [
                'count' => self::ltr(number_format(count($coverage['excluded']))),
            ]);
        }

        /*
         * ONLY WHEN A START DATE IS ACTUALLY SHOWN. Found by review: a store can be included with
         * `isComplete()` false and `verifiedFrom()` null -- the state where every order the ledger
         * cannot see is UNDATED, for which `LedgerCoverage::window()` returns all-time. If that is
         * the only incomplete store, `$verifiedFrom` stays null, the period reads "all time", and
         * printing a sentence about "the latest start among the stores included" would describe a
         * date the reader cannot see, attached to a figure claiming coverage it does not have.
         */
        if (! $coverage['allComplete'] && $coverage['verifiedFrom'] !== null) {
            $notes[] = __('The period shown is the latest start among the stores included, because a total across stores is only as complete as its least complete contributor.');
        }

        /*
         * AND THE ORDERS THAT ARE INVISIBLE TO BOTH. An order with no financial row AND no date
         * cannot be placed inside or outside any window, so it narrows no period and is disclosed
         * nowhere else on this screen. The merchant screen reports its own; this reports the sum.
         */
        if ($coverage['undatedWithoutRow'] > 0) {
            $notes[] = __('Orders with no financial record and no date, which no period can include or exclude: :count.', [
                'count' => self::ltr(number_format($coverage['undatedWithoutRow'])),
            ]);
        }

        return [
            'rows' => $rows,
            'period' => $coverage['verifiedFrom'] === null
                ? __('all time')
                : __('from :date', ['date' => $coverage['verifiedFrom']->isoFormat('D MMM YYYY')]),
            'hint' => implode(' ', $notes),
        ];
    }

    /**
     * EXACTLY THE MERCHANT SCREEN'S WINDOW, which is the point of the constant.
     *
     * The first version of this file used `MetricWindow::since($now->subDays(30))`. Found by
     * review: `applyTo()` widens `from` with `startOfDay()` and applies NO upper bound when `to`
     * is null, so that window spanned THIRTY-ONE calendar days under a "last 30 days" label and
     * admitted future-dated orders -- which `LedgerCoverage` records can legitimately exist, since
     * `order_date` can be stamped up to 5.5 hours ahead. The same store's order count therefore
     * differed between `/analytics` and `/platform/analytics`, contradicting this class's own
     * reason for reusing the metric layer.
     */
    private static function recentWindow(CarbonImmutable $now): MetricWindow
    {
        return MetricWindow::between(
            $now->subDays(self::RECENT_DAYS - 1)->startOfDay(),
            $now->endOfDay()
        );
    }

    /** The merchant screen's EXISTING key, not a second one saying the same thing in other words. */
    private static function recentPeriod(): string
    {
        return __('last :count days', ['count' => number_format(self::RECENT_DAYS)]);
    }

    private static function nothingYet(string $why): array
    {
        return ['value' => null, 'period' => __('not yet available'), 'hint' => $why];
    }

    /**
     * Bidi isolation, as MerchantAnalyticsFigures does: a Western-digit number inside an Arabic
     * sentence otherwise reorders around neighbouring punctuation.
     */
    private static function ltr(string $number): string
    {
        return "\u{2066}" . $number . "\u{2069}";
    }
}
