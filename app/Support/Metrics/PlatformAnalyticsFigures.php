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
            'visits' => self::visits($storeIds, $now),
            'buyers' => self::buyers($storeIds, $now),
            'retention' => self::retention($coverage),
            'payments' => self::payments($coverage),
            'profit' => self::profit($coverage),
            'devices' => self::devices($storeIds, $now),
            'sources' => self::sources($storeIds, $now),
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
        $metric = new GrossMerchandiseValue();
        $window = MetricWindow::allTime();

        /*
         * STEP 1 -- THE DISTINCT RECORDED SPELLINGS. One row per spelling, not per store.
         *
         * THIS METHOD USED TO READ EVERY (store, currency) PAIR ON THE PLATFORM INTO PHP and rank
         * there, because the bucket key is not the column -- see the fallback below. Its caller's
         * scope is every store there is, so that read, the `Store` name read and the `Setting`
         * read each grew with the number of tenants, on the operator's landing page. The panel
         * this replaced ranked and limited in SQL and returned five rows; the bound was lost when
         * the ranking moved into PHP, and this is it restored. The answer is unchanged: every one
         * of `OperatorDashboardTopStoresTest`'s cases, the fallback and duplicate-setting ones
         * included, passes against both shapes.
         */
        $recorded = $metric->distinctCurrencies($scope, $window);

        if ($recorded === []) {
            return [];
        }

        /*
         * STEP 2 -- WHICH SPELLINGS SHARE A CURRENCY, AND WHICH NAME NO CURRENCY AT ALL.
         *
         * THROUGH `CurrencySymbol::code()`, exactly as before and for the same reasons. A currency
         * code is merchant-written -- a store setting, snapshotted onto each order -- so it is
         * neither trusted to be safe to print nor trusted to be a currency. `code()` answers both:
         * three ASCII letters, case folded, or ''.
         *
         * `clean()` ALONE WAS NOT ENOUGH, and that was the correction. It removes markup and
         * control characters, which a SYMBOL passes intact, so `$` and `ل.س` were taken for
         * currencies -- and P5-R14 measured that the two storefront-API order writers really did
         * stamp the symbol into `orders.currency_code`, with owner decision D-N34 leaving those
         * rows in place. A store whose orders came through both writers was therefore listed once
         * per spelling, each with a partial subtotal, under a heading reading `$`.
         *
         * NORMALISATION STAYS IN PHP. Pushing it into SQL -- so one grouped query could do the
         * whole job -- would put this rule in a second language, in two dialects, with SQLite's
         * string functions standing in for MySQL's in every test. That is the defect class this
         * codebase keeps paying for, so the SQL groups by the raw column and PHP decides what the
         * raw values mean.
         */
        /** @var array<string, list<?string>> $spellingsByCurrency */
        $spellingsByCurrency = [];
        /** @var list<?string> $uncodedSpellings */
        $uncodedSpellings = [];

        foreach ($recorded as $spelling) {
            $code = \App\Support\Money\CurrencySymbol::code($spelling ?? '');

            if ($code === '') {
                $uncodedSpellings[] = $spelling;

                continue;
            }

            $spellingsByCurrency[$code][] = $spelling;
        }

        /*
         * STEP 3 -- THE STORES WHOSE BUCKET THE DATABASE CANNOT KNOW, computed exactly.
         *
         * An order with no recorded currency, or one carrying a symbol, takes its STORE's own
         * currency setting, and only then the product-wide default. That is the same assumption
         * `MetricResult::assumingUnknownCurrencyIs()` makes for a merchant's tiles, applied per
         * store because here every row is a different store -- and it is why a ranked SQL query
         * per currency cannot do the whole job: the bucket for these rows is in another table.
         *
         * So they are read separately and in full, and they are the only rows that still cost one
         * read per store. That set is the legacy one D-N34 left in place: it does not grow with
         * the platform, and on a platform without it steps 3 and 6 do not run at all.
         */
        $exact = $uncodedSpellings === []
            ? []
            : self::figuresForStoresNeedingTheirOwnSetting($metric, $scope, $window, $uncodedSpellings);

        /*
         * STEP 4 -- ONE RANKED, LIMITED QUERY PER CURRENCY.
         *
         * Each returns at most `TOP_STORES` rows, summed over every spelling of that currency, so
         * a store appears once and the engine does the ranking it has the index for.
         *
         * WHY FIVE IS ENOUGH TO BE EXACT: a store with no uncoded rows has its whole total in this
         * query, so if five stores outrank it here, each of those five has a total at least as
         * large and it is genuinely not in the top five. A store WITH uncoded rows is in `$exact`
         * by construction, whether or not this query kept it.
         */
        /** @var array<string, array<int, array{amount: float, orders: int}>> $candidates */
        $candidates = [];

        foreach ($spellingsByCurrency as $code => $spellings) {
            foreach ($metric->topStoresForCurrencies($scope, $window, $spellings, self::TOP_STORES) as $row) {
                $candidates[$code][$row['store_id']] = [
                    'amount' => $row['amount'],
                    'orders' => $row['orders'],
                ];
            }
        }

        /*
         * STEP 5 -- the exact figures REPLACE whatever step 4 credited those stores with, because
         * step 4 counted only their coded rows. A store's figure can only rise this way, so it
         * cannot promote anyone else into the top five, and a store demoted out of it is still a
         * candidate and is simply ranked below the five that remain.
         */
        foreach ($exact as $code => $stores) {
            foreach ($stores as $storeId => $figure) {
                $candidates[$code][$storeId] = $figure;
            }
        }

        if ($candidates === []) {
            return [];
        }

        /*
         * STEP 6 -- NAMES, FOR THE CANDIDATES ALONE. This was one query over every store with an
         * order; it is now one query over at most five stores per currency plus the legacy set.
         */
        $wanted = [];

        foreach ($candidates as $stores) {
            foreach (array_keys($stores) as $storeId) {
                $wanted[$storeId] = (int) $storeId;
            }
        }

        $names = Store::query()->whereIn('id', array_values($wanted))->pluck('name', 'id');

        // Alphabetical, so the panel reads the same whichever order the engine grouped in.
        ksort($candidates);

        $ranked = [];

        foreach ($candidates as $currency => $stores) {
            // Highest order value first; the store id breaks a tie so two stores on the same
            // amount do not swap places between requests. The same rule the SQL ordered by.
            uksort($stores, static fn (int $a, int $b): int => [$stores[$b]['amount'], $a] <=> [$stores[$a]['amount'], $b]);

            foreach ($stores as $storeId => $figure) {
                // A store deleted between the ranking and this read has nothing to be listed
                // under, and loses its place rather than shifting the list -- which is what
                // `topStoresByPageViews()` has always done with a view logged against a store that
                // no longer exists.
                if (! isset($names[$storeId])) {
                    continue;
                }

                if (count($ranked[$currency] ?? []) >= self::TOP_STORES) {
                    break;
                }

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
     * THE LEGACY ROWS' STORES, AND THEIR WHOLE FIGURE -- the one part of this panel that still
     * costs a read per store, bounded by a set that does not grow.
     *
     * TWO READS OF THE ORDERS, AND THE SECOND IS THE WIDER ONE ON PURPOSE. The first finds WHO has
     * an uncoded row. The second reads ALL of those stores' rows, coded ones included, because a
     * store's total in one currency can come from both: its setting may normalise to the same code
     * its coded rows carry, and adding only half of it would understate the store on the very
     * screen that ranks it.
     *
     * @param  list<?string>  $uncodedSpellings  recorded spellings that are not currency codes
     * @return array<string, array<int, array{amount: float, orders: int}>> currency => store => figure
     */
    private static function figuresForStoresNeedingTheirOwnSetting(
        GrossMerchandiseValue $metric,
        MetricScope $scope,
        MetricWindow $window,
        array $uncodedSpellings,
    ): array {
        $uncoded = $metric->perStore($scope, $window, null, $uncodedSpellings);

        $storeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['store_id'],
            $uncoded
        )));

        if ($storeIds === []) {
            return [];
        }

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

        /** @var array<string, array<int, array{amount: float, orders: int}>> $exact */
        $exact = [];

        foreach ($metric->perStore($scope, $window, $storeIds) as $row) {
            $storeId = (int) $row['store_id'];

            $code = \App\Support\Money\CurrencySymbol::code($row['currency'] ?? '');

            if ($code === '') {
                $own = \App\Support\Money\CurrencySymbol::code($configured[$storeId] ?? '');
                $code = $own !== '' ? $own : self::FALLBACK_CURRENCY;
            }

            // Summed, because 'usd' and 'USD' -- or a recorded code and the store's own -- are one
            // currency once normalised, and a store must appear once in its currency's list.
            $exact[$code][$storeId]['amount'] = round(($exact[$code][$storeId]['amount'] ?? 0.0) + $row['amount'], 2);
            $exact[$code][$storeId]['orders'] = ($exact[$code][$storeId]['orders'] ?? 0) + $row['orders'];
        }

        return $exact;
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
    /**
     * P05-14 / P5-R13 -- REPEAT PURCHASE, ACROSS EVERY STORE THE LEDGER CAN VOUCH FOR.
     *
     * P05-14 says the operator sees "the same metrics" as a merchant. Counted, the merchant screen
     * carries THIRTEEN figures and this one carried FOUR, so nine were missing. This is the first
     * of them, and it is first because it needed no new machinery: the intersection `payments()`
     * already computes is exactly the window this needs.
     *
     * IT IS LEDGER-BOUND, WHICH IS WHY IT TAKES `$coverage` AND NOT A STORE LIST. The merchant's
     * own version reads `LedgerCoverage::forStore()` because `CustomerRetention`'s default basis is
     * SETTLED-ONLY -- paid orders -- and its docblock gives the reason: "a repeat rate computed
     * over orders nobody has confirmed were paid is not a retention figure". So the same three
     * cross-tenant rules this class states for money apply here too, and are applied the same way:
     * the window is the INTERSECTION (the latest verified start among the included stores), a store
     * the ledger cannot vouch for is EXCLUDED and COUNTED rather than silently dropped, and the
     * sentence about the intersection is printed only when a start date is actually shown.
     *
     * ONE EXTRA CAVEAT THAT THE MERCHANT VERSION DOES NOT NEED, and without it the figure would be
     * read as something it is not: nothing links one person's accounts in two stores, so this is a
     * rate over store-customer pairs, not over people. `buyers()` already says that of its count;
     * a RATE invites the misreading more strongly, because "35% of buyers came back" sounds like a
     * statement about human beings.
     *
     * @param  array{included: list<int>, excluded: list<int>, verifiedFrom: ?CarbonImmutable, allComplete: bool, undatedWithoutRow: int}  $coverage
     */
    private static function retention(array $coverage): array
    {
        if ($coverage['included'] === []) {
            return [
                'value' => null,
                'period' => __('not yet available'),
                'hint' => __('Repeat purchase is measured over the period the financial record can vouch for, and no store has such a period yet. Nothing is reconstructed.'),
            ];
        }

        $window = $coverage['verifiedFrom'] === null
            ? MetricWindow::allTime()
            : MetricWindow::since($coverage['verifiedFrom']);

        $figures = (new CustomerRetention())->for(MetricScope::stores($coverage['included']), $window);
        $rate = $figures->repeatRate();

        $period = $coverage['verifiedFrom'] === null
            ? __('all time')
            : __('from :date', ['date' => $coverage['verifiedFrom']->isoFormat('D MMM YYYY')]);

        if ($rate === null) {
            return [
                'value' => null,
                'period' => $period,
                'hint' => __('No order in this period belongs to a customer who could be recognised again, so there is nothing to divide by. Orders placed without a customer account cannot be attributed to a person.'),
            ];
        }

        $notes = [
            __('Of :identified buyers who can be recognised again, :repeat ordered more than once. Counted over paid orders only: a repeat rate computed over orders nobody has confirmed were paid is not a retention figure.', [
                'identified' => self::ltr(number_format($figures->identifiedCustomers())),
                'repeat' => self::ltr(number_format($figures->repeatCustomers())),
            ]),
            __('One person with accounts in two stores is counted in each, because nothing links the two accounts: this is a rate over customers of a store, not over people.'),
        ];

        if ($coverage['excluded'] !== []) {
            $notes[] = __('Stores left out because their financial record cannot vouch for any period: :count.', [
                'count' => self::ltr(number_format(count($coverage['excluded']))),
            ]);
        }

        // Only when a start date is actually shown -- the same correction review made to payments().
        if (! $coverage['allComplete'] && $coverage['verifiedFrom'] !== null) {
            $notes[] = __('The period shown is the latest start among the stores included, because a rate across stores is only as complete as its least complete contributor.');
        }

        if (($unattributed = (int) ($figures->notes()['orders_without_identifiable_customer'] ?? 0)) > 0) {
            $notes[] = __('Orders with no customer account, excluded: :count.', [
                'count' => self::ltr(number_format($unattributed)),
            ]);
        }

        if (($unrowed = (int) ($figures->notes()['orders_without_ledger_row'] ?? 0)) > 0) {
            $notes[] = __('Orders with no financial record, which this basis cannot see: :count.', [
                'count' => self::ltr(number_format($unrowed)),
            ]);
        }

        return [
            'value' => self::ltr(number_format($rate * 100, 2).'%'),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }

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

        $byCurrency = $result->byCurrency();

        // Deterministic order, for the reason `profit()` records: `GROUP BY` guarantees none.
        ksort($byCurrency);

        $rows = [];

        foreach ($byCurrency as $currency => $amount) {
            $rows[] = [
                // Through MoneyText, which now holds the one definition of this label (FIN-SYM).
                'currency' => MoneyText::currencyLabel((string) $currency),
                'amount' => self::ltr(number_format($amount, 2)),
            ];
        }

        $notes = [__('Money actually collected, read from the financial record. Each currency is shown on its own line and the lines are NOT added together: converting them would need the exchange rate on each order\'s own date, and no such record exists.')];

        // The same row, the same silence, fixed in both tables at once -- see the helper.
        if (array_key_exists(MetricResult::UNKNOWN_CURRENCY, $byCurrency)) {
            $notes[] = self::unknownCurrencyRowNote();
        }

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
     * ORDERS BY CHANNEL, ACROSS TENANTS -- `GAP-162` / `P05-14`, the fourth and last counterpart a
     * mapping pass found buildable without an owner decision.
     *
     * NOT A VISIT BREAKDOWN, although it renders beside two of them. `orders.order_source` is
     * written by the WRITER that created the order, from a closed three-value vocabulary
     * (`Order::SOURCES`), and is never inferred afterwards -- which is exactly why this one needs
     * no row cap while `channels` and `campaigns` do: their buckets are VISITOR-AUTHORED, and what
     * a cap MEANS across tenants (one global top-N, or a bounded contribution per store) is the
     * product question `D-N39` and `D-N40` hold. There is nothing to decide here.
     *
     * THE STORE PREDICATE IS EXPLICIT AND IT DOES WORK. `Order` registers NO `StoreScope` and is
     * absent from `config/tenancy.php`'s `scoped_models` -- this file's own table of that records
     * it -- so nothing adds a `store_id` predicate for any principal. As with `devices` and
     * `visits` the `whereIn` earns its place for a row pointing at a store that no longer exists,
     * which `StoreController::destroy()` leaves behind; pinned by its own orphan-row test.
     *
     * ONE ORDERER, IN PHP, AND DELIBERATELY NO `orderByDesc` IN THE SQL. The fold below merges NULL
     * and the empty string AFTER any ordering the engine applied, and two absences can merge into a
     * bucket larger than the named one -- so SQL cannot decide the ranking. The merchant twin
     * records why there must be only one orderer rather than two: with both, reverting EITHER
     * leaves the other producing the right answer, so neither mutation can be caught and the
     * ordering reads as covered when only the pair is.
     *
     * AN UNRECOGNISED VALUE IS SHOWN, NOT DROPPED. `order_source` is a string column and
     * `Order::orderSourceLabel()` returns null outside its closed match, so a writer added later --
     * or a hand-edited row -- would otherwise vanish from a count whose label says "every order".
     * It is rendered with its stored value and the hint says the version has no name for it.
     *
     * THESE ARE ORDERS AND NOT VISITS, and that sentence is more load-bearing here than on the
     * merchant screen: this table sits directly beneath a cross-tenant page-view COUNT and a device
     * breakdown, and there is no link in this product between a page view and an order. Dividing
     * one by the other would invent a per-channel conversion rate -- which is also the figure
     * `D-N32` is open about.
     *
     * @param  list<int>  $storeIds
     * @return array{rows: list<array{label: string, count: string}>, period: string, hint: string}
     */
    private static function sources(array $storeIds, CarbonImmutable $now): array
    {
        if ($storeIds === []) {
            return [
                'rows' => [],
                'period' => __('not yet available'),
                'hint' => __('No store exists yet, so there is no channel breakdown. Nothing is estimated.'),
            ];
        }

        [$from, $to] = self::recentBounds($now);

        $rows = \App\Models\Order::query()
            ->selectRaw('order_source as bucket, COUNT(*) as total')
            ->whereIn('store_id', $storeIds)
            ->whereBetween('order_date', [$from, $to])
            ->groupBy('order_source')
            ->toBase()
            ->get();

        $buckets = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row->bucket ?? ''));

            $buckets[$value] = ($buckets[$value] ?? 0) + (int) $row->total;
        }

        arsort($buckets);

        $out = [];
        $unrecognised = false;

        foreach ($buckets as $value => $count) {
            if ((string) $value === '') {
                $label = __('Channel not recorded (placed before this was tracked)');
            } else {
                $named = \App\Models\Order::orderSourceLabel((string) $value);

                if ($named === null) {
                    $unrecognised = true;
                    $label = __(':value (not a source this version knows)', ['value' => $value]);
                } else {
                    $label = $named;
                }
            }

            $out[] = ['label' => $label, 'count' => self::ltr(number_format($count))];
        }

        $notes = [
            __('Which path created each order across every store: the point of sale, a merchant entering it by hand, or the storefront. It is recorded by the writer when the order is created and is never inferred afterwards.'),
            __('These are ORDERS, not visits. There is no link in this product between a page view and an order, so these counts cannot be divided by the page-view figures above them to produce a rate per channel.'),
            __('It counts orders placed in this period whatever their payment status, and does not exclude orders later cancelled or refunded.'),
        ];

        if ($unrecognised) {
            $notes[] = __('One or more orders carry a source this version does not have a name for. They are shown with the stored value rather than left out of the count.');
        }

        return [
            'rows' => $out,
            'period' => self::recentPeriod(),
            'hint' => $out === []
                ? __('No orders were placed in this period, so there is no channel breakdown. Nothing is estimated.')
                : implode(' ', $notes),
        ];
    }

    /**
     * PAGE VIEWS, ACROSS TENANTS -- `GAP-162` / `P05-14`, the third counterpart.
     *
     * A COUNT IS NOT THE CONTESTED RATIO, which is why this can ship while `conversion` cannot.
     * `D-N32` / `GAP-155` is open on conversion's BASIS -- `PHASE-05-METRIC-DEFINITIONS.md` §13
     * says verbatim that the owner has not ruled, the requirement expects "visitors or sessions
     * against orders" while the tile divides by page views, and WITHDRAWING the tile is one of the
     * registered options. None of that is in question for the page-view count itself, which the
     * merchant screen has shown since P5-4b. So the count is built and the rate is left alone.
     *
     * TWO QUERIES, BOTH AGGREGATES, NO ROW EVER LOADED. That is a standing constraint on this
     * screen rather than a preference: `Most Popular Store` used to read the store id of EVERY row
     * of the visit log into PHP and was rewritten for exactly this reason (`P5-R3b`). The second
     * query returns at most one row per store, which is the bound this whole screen already has.
     * `PlatformAnalyticsScreenTest::test_no_query_reads_visit_rows_into_php` asserts it on the SQL,
     * because a `get()` that looks bounded is only bounded by today's data.
     *
     * THE STORE PREDICATE IS EXPLICIT AND IT DOES WORK. `shetabit_visits` is not in
     * `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no predicate for anybody --
     * operator included. On the `devices` slice the equivalent `whereIn` SURVIVED deletion against
     * the whole suite, because `$storeIds` IS every store for any ordinary fixture; it earns its
     * place only for a row pointing at a store that no longer exists, which
     * `StoreController::destroy()` leaves behind. Pinned here by its own orphan-row test.
     *
     * COVERAGE IS TWO DIFFERENT QUESTIONS ON THIS SIDE, AND NEITHER IS THE LEDGER'S.
     *
     *   1. WHEN DID ATTRIBUTION BEGIN? Visit rows carried no `store_id` at all until the
     *      attribution fix of 2026-11-20 (`SanitisedVisitor`'s docblock) and nothing backfills
     *      them, so each store has its own first attributed view. Across tenants the honest start
     *      is the LATEST of them, for the same reason `verifiedFrom` is the latest among the
     *      included stores: a total across stores is only as covered as its least-covered
     *      contributor. Taking the earliest would claim coverage the newest store does not have.
     *      It is computed over ALL TIME and not over the window, because the question is when
     *      recording started, not what happened lately.
     *   2. WHICH STORES HAVE NEVER LOGGED ONE? Those have no start date at all, and their zero
     *      cannot be told apart from "their views were never recorded". So they are COUNTED AND
     *      DISCLOSED rather than folded into the figure -- the same choice `payments()` makes for
     *      a store whose ledger can vouch for nothing.
     *
     * THE SENTENCES ARE CONDITIONAL, and that is deliberate. `visitCoverageNote()` on the merchant
     * side records the reasoning: a permanent "data may be incomplete" note on every render is not
     * a disclosure, it is wallpaper -- merchants stop reading it and the real warning loses its
     * meaning. So the attribution sentence appears only when the window actually reaches back past
     * the start, and the never-logged sentence only when such a store exists.
     *
     * IT DOES NOT RE-SCOPE THE FIGURE. The window stays the screen's own 30 days and the sentence
     * says which part of it has data. Silently narrowing the period to the covered one would make
     * this tile disagree with the Orders tile beside it, and which of the two is wanted is a
     * product question; saying nothing is wrong under either answer, which is why this is the part
     * that ships without one.
     *
     * @param  list<int>  $storeIds
     * @return array{value: ?string, period: string, hint: string}
     */
    private static function visits(array $storeIds, CarbonImmutable $now): array
    {
        if ($storeIds === []) {
            return self::nothingYet(__('No store exists yet, so there is nothing to count.'));
        }

        [$from, $to] = self::recentBounds($now);

        $visits = (int) Visit::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from, $to])
            ->toBase()
            ->count();

        $starts = Visit::query()
            ->whereIn('store_id', $storeIds)
            ->selectRaw('store_id, MIN(created_at) as first_seen')
            ->groupBy('store_id')
            ->toBase()
            ->get();

        $attributedFrom = null;

        foreach ($starts as $row) {
            if (($raw = $row->first_seen ?? null) === null) {
                continue;
            }

            $seen = CarbonImmutable::parse($raw);

            if ($attributedFrom === null || $seen->greaterThan($attributedFrom)) {
                $attributedFrom = $seen;
            }
        }

        $withoutAnyView = count($storeIds) - $starts->count();

        $notes = [__('Page views of every store\'s storefront, counted once per request. It is not a count of people: the same visitor browsing five pages is five, and automated traffic such as search-engine crawlers and link previews is included.')];

        if ($visits > 0 && $attributedFrom !== null && $attributedFrom->startOfDay()->greaterThan($from->startOfDay())) {
            $notes[] = __('Page views are attributed to individual stores only from :date, the latest start among the stores that have logged one, so this period is only partly covered. Earlier visits were not attributed to any store and cannot be counted here.', [
                // `isoFormat` so the month name is translated, with the locale passed explicitly
                // rather than relying on Carbon's global one; `ltr()` because an unwrapped
                // Western-digit date reorders itself inside an Arabic sentence.
                'date' => self::ltr($attributedFrom->locale(app()->getLocale())->isoFormat('D MMM YYYY')),
            ]);
        }

        if ($withoutAnyView > 0) {
            $notes[] = __('Stores that have never logged a page view: :count. A zero from them cannot be told apart from a store whose views were never recorded, so they are counted here rather than folded into the figure.', [
                'count' => self::ltr(number_format($withoutAnyView)),
            ]);
        }

        return [
            'value' => self::ltr(number_format($visits)),
            'period' => self::recentPeriod(),
            'hint' => implode(' ', $notes),
        ];
    }

    /**
     * ESTIMATED GROSS PROFIT, ACROSS TENANTS -- `GAP-162` / `P05-14`, the second counterpart.
     *
     * THE DEFINITION IS NOT RE-DECIDED HERE. It is `StoreRevenueMargin(true)`, the same metric and
     * the same `settledOnly` flag `/finance` and `/analytics` pass through
     * `FinanceLedgerFigures::forStore()`. Sales value minus product cost for orders that have been
     * PAID, dated on the day they were paid. Nothing about what profit MEANS changes by being read
     * across stores -- only the scope does -- which is the whole reason this screen goes through
     * the metric layer instead of writing its own query.
     *
     * AND THE METRIC ALREADY GROUPS BY CURRENCY, so this needed no change to it: `Metric::
     * sumByCurrency()` emits one row per `store_order_financials.currency_code` for the whole
     * scope in ONE statement, and `Metric::financials()` takes the whole scope. A mapping pass
     * measured that and it held.
     *
     * NO TWO *NAMED* CURRENCIES ARE EVER ADDED, and the qualifier is a CORRECTION to what this
     * paragraph said when it was written ("CURRENCIES ARE NEVER ADDED", full stop). An independent
     * financial review refuted it by execution: the `UNKNOWN_CURRENCY` row really does add amounts
     * from stores whose currency settings differ, because `MetricResult` collapses every
     * unparseable value into one key. The row is now disclosed rather than silently trusted -- see
     * `unknownCurrencyRowNote()`, which carries the measurement.
     *
     * `total()` is not called. The stated reason was also imprecise: it throws only on MORE THAN
     * ONE bucket, so on a single-currency platform it would return happily. It is not called
     * because the answer it would give on a multi-currency platform does not exist, and a method
     * whose correctness depends on how many tenants happen to share a currency is not one a screen
     * should call. Each currency is its own row, exactly as `payments()` does. No historical FX
     * record exists in this schema (`currency_rates` holds CURRENT rates and nothing stamps a rate
     * onto an order), so a converted cross-currency profit would be a number with no defensible
     * basis.
     *
     * A PROFIT CAN BE NEGATIVE, and that is why the amount keeps its bidi isolate. Measured and
     * recorded in `FinanceLedgerFigures`: in an Arabic page an unisolated negative renders as
     * "USD 20.00-", with the sign after the number.
     *
     * NO `realBuckets()` / `MoneyText::buckets(..., true)` HERE, deliberately, and this is the one
     * place this figure differs from its merchant twin. That helper drops any bucket whose amount
     * rounds to zero, which is right for a LEDGER tile -- a currency nothing was ever paid in
     * should not make a figure look mixed. Across many stores a currency whose NET profit is
     * approximately zero is a MEANINGFUL statement rather than a rounding artefact: it says that
     * tenant group broke even. `payments()` also iterates `byCurrency()` raw -- CORRECTED: this
     * paragraph used to say it does so "for the same reason", and `payments()` records no reason at
     * all, so a motive was being attributed to another function that the other function does not
     * state. What is true is that both skip the helper, and the half of it that IS needed -- the
     * `ksort()` -- is now done explicitly in both.
     *
     * COVERAGE IS THE SHARED INTERSECTION, unchanged: included stores are those whose ledger can
     * vouch for some period, the window is dated from the LATEST `verifiedFrom` among them because
     * a total across stores is only as covered as its least-covered contributor, and a store that
     * can vouch for nothing is EXCLUDED AND COUNTED rather than contributing a zero that would
     * read as "that tenant made no profit".
     *
     * THE COST CAVEAT IS A SECOND, DIFFERENT COVERAGE QUESTION and is carried separately.
     * `LedgerCoverage` answers "does the ledger have a row"; it does not answer "does that row
     * have a cost". `COST_UNKNOWN` / `notes()['rows_without_cost']` is the only signal for the
     * second, it is scope-wide, and it is a COUNT OF ROWS and not an amount -- so the sentence
     * says how many orders, names no money, and does not say which stores.
     *
     * @param  array{included: list<int>, excluded: list<int>, verifiedFrom: ?CarbonImmutable, allComplete: bool, undatedWithoutRow: int}  $coverage
     * @return array{rows: list<array{currency: string, amount: string}>, period: string, hint: string}
     */
    private static function profit(array $coverage): array
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

        $result = (new StoreRevenueMargin(true))->for(MetricScope::stores($coverage['included']), $window);

        $byCurrency = $result->byCurrency();

        /*
         * SORTED BY KEY, which is the one thing skipping `MoneyText::buckets()` cost that had to be
         * put back. `byCurrency()`'s order is the order `Metric::sumByCurrency()` iterated the raw
         * `GROUP BY` result set in, and MySQL guarantees no `GROUP BY` ordering -- SQLite happens to
         * return it sorted, which is exactly why a test could not see this. An operator's currency
         * rows would reorder between page loads and between engines. `buckets()`'s own `ksort()`
         * carries the same note; this reproduces that half of it without the zero-dropping half.
         */
        ksort($byCurrency);

        $rows = [];

        foreach ($byCurrency as $currency => $amount) {
            $rows[] = [
                'currency' => MoneyText::currencyLabel((string) $currency),
                'amount' => self::ltr(number_format($amount, 2)),
            ];
        }

        /*
         * "ACROSS THE STORES INCLUDED", not "across every store". Found by review: the scope is
         * `$coverage['included']` and the first version of this sentence said "every store", which
         * the excluded-store sentence further down then contradicted -- a reader who stops at the
         * figure has been told something the code does not do.
         *
         * THE TAX CLAUSE IS THE MERCHANT TILE'S, and dropping it was a disclosure regression the
         * review caught against a check that already exists:
         * `tests/Support/Security/bin/verify-metric-layer-on-mysql.php` asserts the merchant hint
         * carries it, under the name "and says what it leaves out TRUTHFULLY".
         * `StoreRevenueMargin`'s docblock records why (`GAP-213(d)`): `EXCLUDES_SHIPPING_AND_TAX` is
         * only half true, because a store whose `TaxOption price_type` is `inclusive` has the tax
         * inside `product_price` and therefore inside `gross_profit`. `TaxOption` is PER STORE, so
         * the cross-tenant figure can mix tax-bearing and tax-free margins INSIDE ONE CURRENCY ROW
         * -- a blend the merchant figure never had to make, and the sentence that warns of it is
         * the one that had been left out.
         */
        $notes = [
            __('Sales value minus product cost for orders that have been paid, counted on the day they were paid, across the stores included below. Shipping is not included. Each currency is shown on its own line and the lines are NOT added together: converting them would need the exchange rate on each order\'s own date, and no such record exists.'),
            __('If a store enters its prices with tax included, that tax is part of its sales value, and stores on this screen may be set either way -- so one currency row can combine margins measured with tax and without it.'),
        ];

        if (array_key_exists(MetricResult::UNKNOWN_CURRENCY, $byCurrency)) {
            $notes[] = self::unknownCurrencyRowNote();
            $notes[] = __('For this figure that matters more than for money collected: a margin can be negative, so adding two currencies in that row can show a loss where one currency gained and another lost.');
        }

        $withoutCost = (int) ($result->notes()['rows_without_cost'] ?? 0);

        if ($withoutCost > 0) {
            $notes[] = __('At least :count paid orders across all stores have no product cost on record, so their profit may be overstated. Orders that mix products with and without a cost price are not counted here.', [
                'count' => self::ltr(number_format($withoutCost)),
            ]);
        }

        if ($coverage['excluded'] !== []) {
            $notes[] = __('Stores left out because their financial record cannot vouch for any period: :count.', [
                'count' => self::ltr(number_format(count($coverage['excluded']))),
            ]);
        }

        if (! $coverage['allComplete'] && $coverage['verifiedFrom'] !== null) {
            $notes[] = __('The period shown is the latest start among the stores included, because a total across stores is only as complete as its least complete contributor.');
        }

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
    /**
     * DEVICES, ACROSS TENANTS -- `GAP-162` / `P05-14`, the first of the four counterparts a
     * mapping pass found genuinely buildable without an owner decision.
     *
     * IT IS A BREAKDOWN, NOT A TILE, so its shape is `{rows, period, hint}` and NOT the
     * `{value, period, hint}` every other figure on this screen returns. That difference is the
     * whole reason `nothingYet()` is not used for the empty case below: it has no `rows` key, and
     * a consumer looping `$figure['rows']` would fatal on it.
     *
     * THE EMPTY-SCOPE GUARD IS FIRST AND IS LOAD-BEARING. `MetricScope`'s constructor THROWS on an
     * empty store list ("an empty scope would compile to 0 = 1"), and every figure on this screen
     * opens with this branch. This one does not even reach a scope -- it reads `Visit` directly,
     * as `topStoresByPageViews()` does -- but the branch stays, because a platform with no stores
     * must render a breakdown-shaped empty rather than an exception or a missing key. A mapping
     * pass omitted this guard from all four of its proposals, which is the one omission that
     * would have shipped red.
     *
     * THE SAME FOLD AS THE MERCHANT TILE, FOR THE SAME MEASURED REASON. SQL `GROUP BY device`
     * treats NULL and `''` as TWO groups, so a naive loop emits two rows both labelled "Device not
     * recorded" with a count each -- a reader takes one absence for two devices and neither count
     * for the real one. `MerchantAnalyticsFigures::devices()` records that measurement; this
     * repeats the fold rather than the defect.
     *
     * NO ROW CAP, DELIBERATELY, and this is the one presentation choice worth stating. The two
     * attribution breakdowns (`channels`, `campaigns`) are blocked on owner decisions `D-N39` and
     * `D-N40` precisely because their buckets are VISITOR-AUTHORED and a cross-tenant cap is a
     * product question. `device` is written by the visitor library from a parsed user agent, not
     * chosen by a visitor, so its vocabulary is closed in practice and capping it would drop real
     * buckets to solve a problem this column does not have. The column IS declared `text`, so an
     * unbounded tail is theoretically reachable -- a property this side SHARES with the merchant
     * tile rather than one it introduces, recorded so that if it is ever capped, both sides are
     * capped together and keep agreeing.
     *
     * TENANCY: `whereIn('store_id', ...)` over the ids `forPlatform()` resolved from `Store`, the
     * same predicate `topStoresByPageViews()` uses. `Visit` carries no global scope, which is
     * exactly why the predicate is explicit.
     *
     * @param  list<int>  $storeIds
     * @return array{rows: list<array{label: string, count: string}>, period: string, hint: string}
     */
    private static function devices(array $storeIds, CarbonImmutable $now): array
    {
        [$from, $to] = self::recentBounds($now);

        if ($storeIds === []) {
            return [
                'rows' => [],
                'period' => __('not yet available'),
                'hint' => __('No store exists yet, so there is nothing to count.'),
            ];
        }

        $grouped = Visit::query()
            ->whereIn('store_id', $storeIds)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('device as bucket, COUNT(*) as total')
            ->groupBy('device')
            ->orderByDesc('total')
            ->toBase()
            ->get();

        $buckets = [];

        foreach ($grouped as $row) {
            $label = trim((string) ($row->bucket ?? ''));

            $buckets[$label] = ($buckets[$label] ?? 0) + (int) $row->total;
        }

        arsort($buckets);

        $rows = [];

        foreach ($buckets as $label => $count) {
            $rows[] = [
                'label' => $label === '' ? __('Device not recorded') : (string) $label,
                'count' => self::ltr(number_format($count)),
            ];
        }

        return [
            'rows' => $rows,
            'period' => self::recentPeriod(),
            'hint' => __('Page views across every store, grouped by the device the visitor used. It counts requests rather than people, and a device is recorded only when the visit log could determine one.'),
        ];
    }

    private static function recentWindow(CarbonImmutable $now): MetricWindow
    {
        [$from, $to] = self::recentBounds($now);

        return MetricWindow::between($from, $to);
    }

    /**
     * THE TWO BOUNDS, extracted so this screen has ONE definition of "the last 30 days".
     *
     * `recentWindow()` wraps them for the metric layer; the device breakdown needs them raw,
     * because it reads `Visit` directly as `topStoresByPageViews()` does rather than through a
     * metric. Going back through `MetricWindow::from()`/`to()` for that would mean handling two
     * nullable returns that this construction can never produce -- `MetricWindow` is nullable
     * because `allTime()` and `since()` exist, not because `between()` can yield one -- and a
     * `whereBetween` handed a null silently matches nothing. One source, no nullable, and the
     * breakdown cannot drift from the tiles beside it.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private static function recentBounds(CarbonImmutable $now): array
    {
        return [
            $now->subDays(self::RECENT_DAYS - 1)->startOfDay(),
            $now->endOfDay(),
        ];
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
    /**
     * THE ONE SENTENCE THE "CURRENCY NOT RECORDED" ROW NEEDS, shared by both money tables because
     * both have the row and both were silent about it.
     *
     * MEASURED BY AN INDEPENDENT FINANCIAL REVIEW, and it refuted this class's own claim that
     * currencies are never added. `MetricResult::make()` re-keys every bucket through
     * `CurrencySymbol::code()`, which yields three uppercase letters or an empty string -- so EVERY
     * unparseable value collapses into the single `UNKNOWN_CURRENCY` key and is summed there. That
     * is not a rare state: `store_order_financials.currency_code` is written from the per-store
     * `defult_currancy` setting, which `FIN-SYM` measured to hold SYMBOLS on real rows (owner
     * decision `D-N34` covers the rows already written). Two stores whose settings hold `$` and
     * `ل.س` therefore land in one row and are added.
     *
     * The review drove it: `+1000.00` from a dollar store and `-2000.00` from a Syrian-pound store
     * rendered as ONE row reading `-1,000.00`, under a caption saying the rows are not added
     * together. For payments the row is merely inflated, because money collected is never negative;
     * for a margin the sum can REVERSE THE SIGN and show a platform-wide loss where the truth is a
     * gain in one currency and a loss in another.
     *
     * WHAT IS NOT DONE HERE, deliberately: the fold itself is not changed. It lives in
     * `MetricResult`, every money surface in the product shares it, and splitting the bucket by the
     * raw stored value would change what `/finance` and `/analytics` report as well -- a change to
     * a shipped financial definition, which is the owner's. So the row stays and says what it is.
     */
    private static function unknownCurrencyRowNote(): string
    {
        return __('The row labelled "Currency not recorded" holds records whose currency this product never stored. Those amounts are added together even where the stores that produced them use different currencies, so that row is not a figure to rely on; every other row is in the single currency it names.');
    }

    private static function ltr(string $number): string
    {
        return "\u{2066}" . $number . "\u{2069}";
    }
}
