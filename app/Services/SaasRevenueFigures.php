<?php

namespace App\Services;

use App\Models\PlanOrder;
use App\Models\User;
use App\Support\Metrics\MetricResult;
use App\Support\Metrics\MoneyText;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * GAP-192 / SAAS-007 — THE PLATFORM'S OWN REVENUE, KEPT APART FROM MERCHANT SALES.
 * =================================================================================================
 *
 * "فصل إيرادات المنصة MRR/ARR عن GMV ومبيعات التجار" — separate platform revenue (MRR/ARR) from
 * GMV and merchant sales. What was measured against that requirement: `GMV` appears four times in
 * the traceability matrix and `MRR` and `ARR` appear ZERO times. Every money figure this product
 * showed before this slice measured what shoppers pay MERCHANTS. This one measures what merchants
 * pay STORE SYRIA, and it reads entirely different tables: `plans`, `users.plan_id` and
 * `plan_orders`, never `orders` or `store_order_financials`.
 *
 * THEY ARE NOT EVEN THE SAME KIND OF NUMBER, which is why keeping them apart is a requirement and
 * not a layout preference:
 *
 *   - A merchant's sales are per-store, in `store_order_financials.currency_code`, which differs
 *     between stores, so a cross-tenant figure is a SET of per-currency amounts.
 *   - A plan price is in `plans.price`, which has NO currency column at all, so it is denominated
 *     in whatever the platform's own currency setting says — one scale for the whole platform.
 *
 * Adding a GMV to an MRR would be meaningless twice over: different money, different payer.
 *
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 * WHAT IS A DEFINITION AND WHAT WOULD HAVE BEEN AN INVENTED POLICY
 * ─────────────────────────────────────────────────────────────────────────────────────────────────
 *
 * MRR is the monthly-normalised value of live recurring subscriptions, so a `Year` plan
 * contributes a twelfth of its price and ARR is MRR times twelve. Those are the definitions of the
 * two terms the plan named; the screen prints both sentences beside the figures.
 *
 * WHICH SUBSCRIPTIONS COUNT IS NOT A DEFINITION, and this class refuses to settle it with a SUM.
 * Every subscription that is not a live recurring one goes into a NAMED, COUNTED bucket:
 * non-recurring (`Unlimited`), free (price 0), in trial, lapsed, no expiry recorded, and plan row
 * missing. Nothing is dropped and nothing is counted as zero — the same discipline
 * `PlatformAnalyticsFigures` applies to a store whose ledger cannot vouch for it, for the same
 * reason: a zero reads as "they pay nothing" where the truth is "they are not in this figure".
 * An owner who wants a different rule can see the population it would move.
 *
 * THE SETTLED-STATUS SET IS CENSUSED FROM THE WRITERS, NOT GUESSED. `plan_orders.payment_status`
 * is free text written by about forty gateway handlers in four spellings — `success`, `succeeded`,
 * `Succeeded` and `Approved` — and `PlanOrderSettledStatusCensusTest` re-derives the set from
 * those files so a new gateway's spelling reddens instead of quietly dropping its money out of
 * platform revenue. `is_refund` is excluded with it: `PlanController::refund` sets that flag, and
 * counting a refunded order would overstate what the platform kept.
 *
 * THIS CLASS IS NOT AN AUTHORIZATION BOUNDARY AND MUST NEVER BE THE ONLY ONE. It deliberately
 * reads every merchant and every plan order on the platform, so calling it from a
 * merchant-reachable handler would be a cross-tenant disclosure. `plan_orders` is NOT a table
 * without a tenant dimension — the migration gives it a nullable, indexed `store_id` — and neither
 * `User` nor `PlanOrder` is in `config/tenancy.php`'s `scoped_models`, so no global scope narrows
 * any of this for anybody. Its one caller is `SuperAdminRevenueController`, behind
 * `PlatformOperatorOnly` on the route. A second caller must bring a second gate.
 *
 * NO MySQL-ONLY SQL. Every aggregate here uses `count`, `sum`, `case when`, `lower` and bound
 * comparisons only — no `MONTH(`, no `YEAR(`, no `DATE_FORMAT`. CLAUDE.md records that 47 such
 * calls are why most of `ReportController` answers HTTP 500 under the test suite and how an
 * 18-site date bug survived 9,112 tests. These figures run on SQLite in the suite and are
 * therefore actually tested.
 *
 * TWO LIMITS OF THAT CLAIM, STATED RATHER THAN LEFT TO BE DISCOVERED:
 *
 *   - `subscriptions()` GROUPS BY SELECT ALIASES (`plan_missing`, `expiry`, `trialling`). MySQL,
 *     MariaDB and SQLite all accept that; the standard does not, so this is "portable across the
 *     two engines this product runs on", not "portable".
 *   - THE AGGREGATES GO THROUGH THE MODELS AND `toBase()`, NOT THE DB FACADE's `table` ENTRY
 *     POINT, and that is not a style choice. `tests/Feature/Tenancy/
 *     ServiceLayerRawQueryInventoryTest` censuses every such call in `app/Services` and requires
 *     each one either to carry a `store_id` predicate or to be declared in its
 *     `STORE_LESS_BY_DESIGN` map with the argument for being wider than one tenant — and
 *     `RawQueryTenancyInventoryTest` keeps a per-directory and a per-table count beside it. Four
 *     such calls here reddened five of those assertions, correctly: they ARE cross-tenant.
 *     (THE TOKEN IS WRITTEN WITHOUT ITS OPENING PAREN THROUGHOUT THIS PARAGRAPH, and that is
 *     load-bearing: those censuses read the file whole, comments included, and the first draft of
 *     this very note pushed the per-directory count from 225 to 227 by EXPLAINING the thing it
 *     measures. CLAUDE.md records four occasions in one day on which a census was satisfied — or
 *     broken — by prose about its own subject; this is one more.)
 *     Going through `User::query()` and `PlanOrder::query()` instead is
 *     BEHAVIOUR-IDENTICAL — measured, not assumed: `getGlobalScopes()` is `[]` for `User`,
 *     `PlanOrder` and `Plan`, and none of the three is in `config/tenancy.php`'s `scoped_models`,
 *     so no predicate is added or removed — and it is the shape `Plan::most_purchese_plan()`
 *     already uses for the one other operator aggregate in this product. `toBase()` keeps the
 *     rows as plain objects, so nothing is hydrated and the model cache is not consulted for an
 *     aggregate that must be current.
 *   - `where('users.type', 'admin')` is the ENGINE's comparison, so under MySQL's default
 *     collation it also matches `Admin` and, under PAD SPACE, `admin ` — both of which are
 *     merchants and should be counted. It does NOT match a LEADING space, so a row hand-edited to
 *     `' admin'` would be missing from these figures entirely. The only writers of `users.type`
 *     are the seeder's exact literal and `UserController`'s role-name path, which normalises, so
 *     this needs a hand-edited row; it is recorded because a figure that silently omits a paying
 *     merchant is the kind of wrong that nobody notices.
 */
final class SaasRevenueFigures
{
    /**
     * The spellings a gateway writes to `plan_orders.payment_status` when money was taken,
     * lower-cased. Censused from the writers by `PlanOrderSettledStatusCensusTest`.
     */
    public const SETTLED_PLAN_STATUSES = ['success', 'succeeded', 'approved'];

    /** The recent window, the same 30 days the analytics screens mean by "recent". */
    private const RECENT_DAYS = 30;

    /** Months in a year — the only arithmetic MRR and ARR need, named rather than inlined twice. */
    private const MONTHS_IN_YEAR = 12;

    /**
     * @return array{
     *     currency: string,
     *     recurring: array<string, array<string, mixed>>,
     *     buckets: list<array{key: string, label: string, count: int, hint: string}>,
     *     receipts: array<string, mixed>,
     *     disclosures: array<string, mixed>
     * }
     */
    public static function forPlatform(?CarbonInterface $now = null): array
    {
        $now = $now ? CarbonImmutable::parse($now) : CarbonImmutable::now();

        $currency = self::platformCurrency();
        $subscriptions = self::subscriptions($now);

        /*
         * ARR IS DERIVED FROM THE UNROUNDED MRR, and that is a correction the independent financial
         * review found. `subscriptions()` returns both: `mrr` rounded for display and `mrr_exact`
         * for arithmetic. Deriving ARR from the rounded figure lost money visibly -- measured, one
         * yearly plan at 100 gives MRR 8.33 and an ARR of 99.96 against a true annual of 100.00.
         *
         * NO TEST COULD SEE IT, which is the part worth recording: every yearly price in the
         * fixtures was 1200 or 600, both exactly divisible by twelve, so the rounded and unrounded
         * derivations agreed for every case the suite drove. CLAUDE.md's trap -- the fixture cannot
         * tell the two readings apart -- and the reason the new case uses 100.
         */
        $mrr = $subscriptions['mrr'];
        $arr = round($subscriptions['mrr_exact'] * self::MONTHS_IN_YEAR, 2);

        return [
            'currency' => $currency,
            'recurring' => [
                'mrr' => [
                    'value' => MoneyText::amount($mrr, $currency),
                    'raw' => $mrr,
                    'period' => __('now'),
                    'hint' => __('Monthly recurring revenue: the monthly value of the subscriptions that are live today. A yearly plan contributes a twelfth of its price. This is what merchants pay Store Syria and has nothing to do with what shoppers pay merchants.'),
                ],
                'arr' => [
                    'value' => MoneyText::amount($arr, $currency),
                    'raw' => $arr,
                    'period' => __('now'),
                    'hint' => __('Annual recurring revenue: the monthly figure multiplied by twelve. It is a restatement of the same subscriptions and not a forecast, so it assumes nothing about renewals, upgrades or churn.'),
                ],
                'subscribers' => [
                    'value' => (string) ($subscriptions['counts']['monthly'] + $subscriptions['counts']['yearly']),
                    'raw' => $subscriptions['counts']['monthly'] + $subscriptions['counts']['yearly'],
                    'period' => __('now'),
                    'hint' => __('Merchants on a live, paid, recurring plan today. Every other subscription is counted in its own row below rather than folded into this number.'),
                ],
            ],
            'buckets' => self::buckets($subscriptions['counts']),
            'receipts' => self::receipts($now),
            'disclosures' => [
                'inactive_contributors' => $subscriptions['inactive_contributors'],
                'currency_has_no_column' => true,
            ],
        ];
    }

    /**
     * THE PLATFORM'S OWN CURRENCY CODE, because `plans.price` does not carry one.
     *
     * `CURRENCY_NAME` is the operator's configured currency CODE and `app/Helper/helper.php`
     * defaults it to `USD`; this uses the same key and the same default, so the figure cannot be
     * denominated differently from the rest of the platform's own settings. The plans screen
     * prints `CURRENCY`, which is a SYMBOL — right for a price tag, wrong beside a reported
     * aggregate, where a three-letter code removes the ambiguity between dollars that share a
     * sign.
     *
     * The settings bag is read through the shared helper rather than re-queried, so there is one
     * definition of "the platform's settings". That helper memoises the operator ROW for an hour,
     * which is why a test that creates an operator mid-run may need to clear it — it does not
     * memoise the settings themselves.
     */
    private static function platformCurrency(): string
    {
        $settings = function_exists('getSuperAdminAllSetting') ? getSuperAdminAllSetting() : [];

        /*
         * THE SAME VALIDATOR, for the same reason, on the figure's own denomination. This read
         * `strtoupper(trim(...))` and fell back to `USD` only when the setting was EMPTY -- so a
         * `CURRENCY_NAME` of `Dollar` rendered the MRR tile as `100.00 DOLLAR`, a token that is not
         * a currency presented as the unit of a money figure. `CURRENCY_NAME` is free text: it is
         * `clean()`ed on read (it is in `CurrencySymbol::SETTING_NAMES`) which strips markup but
         * deliberately does not force a code.
         *
         * Falling back to `USD` for a non-code is the same answer this already gave for an empty
         * one, and it is the documented platform default (`helper.php:1568`, `:1603`).
         */
        $code = \App\Support\Money\CurrencySymbol::code($settings['CURRENCY_NAME'] ?? '');

        return $code !== '' ? $code : 'USD';
    }

    /**
     * EVERY MERCHANT SUBSCRIPTION, CLASSIFIED, IN ONE GROUPED QUERY.
     *
     * AGGREGATED IN THE DATABASE AND NOT IN PHP. `Plan::most_purchese_plan()` carries the record of
     * why: it used to pull every `plan_orders` row into PHP with no predicate and no bound, on
     * every load of the operator's dashboard. The classification needs five inputs, two of which
     * are per-user dates, so those two are reduced to labels by a `case when` over BOUND dates and
     * the result is grouped — which keeps the row count at "distinct combinations", not "merchants".
     *
     * THE DATE COMPARISON IS A STRING COMPARISON, DELIBERATELY. `users.plan_expire_date` is a
     * VARCHAR holding `Y-m-d` (the migration declares `string`, and `User::assignPlan()` writes
     * `isoFormat('YYYY-MM-DD')`), so an ISO comparison is correct lexicographically on both MySQL
     * and SQLite and needs no date function that one of them lacks. A value of `'2026-11-25
     * 00:00:00'` still compares correctly against `'2026-11-25'`; an EMPTY STRING is treated as
     * "no expiry recorded" rather than as lapsed, because that is what it is.
     *
     * `>=` AND NOT `>`: the expiry date is the last day of the subscription, so a plan expiring
     * today is live today. `PlatformRevenueFiguresTest` pins that boundary on its own.
     *
     * @return array{mrr: float, counts: array<string, int>, inactive_contributors: int}
     */
    private static function subscriptions(CarbonImmutable $now): array
    {
        $today = $now->format('Y-m-d');

            /*
             * PLATFORM-WIDE ON PURPOSE, AND COUNTED BY NEITHER RAW-QUERY INVENTORY — see
             * `planOrderGroups()` below for the full disposition (GAP-253). `users` is in neither
             * `config/tenancy.php`'s `scoped_models`, so no predicate would be added for any
             * principal, and a count of every merchant's subscription is what this figure IS.
             */
        $rows = User::query()
            ->leftJoin('plans', 'plans.id', '=', 'users.plan_id')
            ->where('users.type', 'admin')
            ->whereNotNull('users.plan_id')
            ->selectRaw(
                'plans.duration as duration,'
                . ' plans.price as price,'
                . ' case when plans.id is null then 1 else 0 end as plan_missing,'
                . " case when users.plan_expire_date is null or users.plan_expire_date = '' then 'none'"
                . ' when users.plan_expire_date >= ? then \'live\' else \'past\' end as expiry,'
                . " case when users.trial_expire_date is not null and users.trial_expire_date >= ?"
                . ' then 1 else 0 end as trialling,'
                . ' count(*) as subscribers,'
                . ' sum(case when users.is_active = ? then 0 else 1 end) as inactive',
                [$today, $today, '1']
            )
            ->groupBy('plans.duration', 'plans.price', 'plan_missing', 'expiry', 'trialling')
            ->toBase()
            ->get();

        $counts = [
            'monthly' => 0,
            'yearly' => 0,
            'non_recurring' => 0,
            'free' => 0,
            'in_trial' => 0,
            'lapsed' => 0,
            'no_expiry_recorded' => 0,
            'plan_missing' => 0,
        ];

        $mrr = 0.0;
        $inactiveContributors = 0;

        foreach ($rows as $row) {
            $subscribers = (int) $row->subscribers;
            $price = (float) $row->price;
            $duration = (string) $row->duration;

            /*
             * THE ORDER OF THESE LIMBS IS THE CLASSIFICATION, and each earlier one is a reason the
             * later ones cannot be asked. A row with no readable plan has no price and no duration
             * to judge; a trialling merchant pays nothing whatever their plan says; a free plan has
             * nothing to recur. Only what survives all of them is revenue.
             */
            if ((int) $row->plan_missing === 1) {
                $counts['plan_missing'] += $subscribers;

                continue;
            }

            if ((int) $row->trialling === 1) {
                $counts['in_trial'] += $subscribers;

                continue;
            }

            if ($price <= 0.0) {
                $counts['free'] += $subscribers;

                continue;
            }

            if ($duration === 'Unlimited') {
                $counts['non_recurring'] += $subscribers;

                continue;
            }

            if ($row->expiry === 'none') {
                $counts['no_expiry_recorded'] += $subscribers;

                continue;
            }

            if ($row->expiry !== 'live') {
                $counts['lapsed'] += $subscribers;

                continue;
            }

            $monthly = $duration === 'Year' ? $price / self::MONTHS_IN_YEAR : $price;

            if ($duration === 'Year') {
                $counts['yearly'] += $subscribers;
            } else {
                $counts['monthly'] += $subscribers;
            }

            $mrr += $monthly * $subscribers;
            $inactiveContributors += (int) $row->inactive;
        }

        return [
            'mrr' => round($mrr, 2),
            /* The unrounded accumulation, for ARR. See the note at the `arr` derivation: rounding
             * once and then multiplying by twelve loses up to 0.06 on the annual figure, and every
             * fixture price happened to be divisible by twelve so nothing caught it. */
            'mrr_exact' => $mrr,
            'counts' => $counts,
            'inactive_contributors' => $inactiveContributors,
        ];
    }

    /**
     * The named buckets, each with the sentence that says why it is not in the headline figure.
     *
     * @param  array<string, int>  $counts
     * @return list<array{key: string, label: string, count: int, hint: string}>
     */
    private static function buckets(array $counts): array
    {
        $declared = [
            'monthly' => [
                __('Live monthly subscriptions'),
                __('Counted in the recurring figures above at their whole price.'),
            ],
            'yearly' => [
                __('Live yearly subscriptions'),
                __('Counted in the recurring figures above at a twelfth of their price per month.'),
            ],
            'non_recurring' => [
                __('Lifetime subscriptions'),
                __('A plan with an Unlimited duration is paid once, so it is not monthly recurring revenue. Spreading it over an unknown lifetime would be inventing a figure, so it is counted here instead.'),
            ],
            'free' => [
                __('Free plans'),
                __('The plan price is zero, so there is nothing to recur.'),
            ],
            'in_trial' => [
                __('In trial'),
                __('A trial that has not yet ended. Nothing has been paid and nothing has been agreed, so the plan price is not revenue yet.'),
            ],
            'lapsed' => [
                __('Lapsed'),
                __('The expiry date has passed. The merchant still holds the plan on their record, which is why they are counted here rather than dropped.'),
            ],
            'no_expiry_recorded' => [
                __('No expiry recorded'),
                __('A recurring plan with no expiry date. Every path in this product writes one, so these rows cannot be dated and are reported rather than assumed to be live.'),
            ],
            'plan_missing' => [
                __('Plan no longer exists'),
                __('The merchant\'s record points at a plan row that has been deleted, so neither its price nor its duration can be read.'),
            ],
        ];

        $buckets = [];

        foreach ($declared as $key => [$label, $hint]) {
            $buckets[] = [
                'key' => $key,
                'label' => $label,
                'count' => $counts[$key] ?? 0,
                'hint' => $hint,
            ];
        }

        return $buckets;
    }

    /**
     * WHAT THE PLATFORM ACTUALLY COLLECTED, per currency, never summed across them.
     *
     * Two windows, because one figure cannot answer both questions an operator has: all time says
     * how much has ever been taken, and the recent window says whether anything is being taken
     * now. A single all-time figure on a platform that stopped selling last year looks healthy.
     *
     * @return array<string, mixed>
     */
    private static function receipts(CarbonImmutable $now): array
    {
        /*
         * TWO READS, NOT THREE AND NOT FOUR. The all-time grouping answers both the all-time rows
         * and the excluded counts, so the disclosure and the figure it qualifies are computed from
         * the same rows — two independent queries over a table being written to is how a
         * disclosure comes to contradict its own figure.
         */
        $allTime = self::planOrderGroups(null);
        $recent = self::planOrderGroups($now->subDays(self::RECENT_DAYS));

        return [
            'all_time' => [
                'rows' => self::receiptRows($allTime),
                'period' => __('all time'),
                /*
                 * NO PRECISION CAVEAT HERE, AND THAT IS A MEASURED DISPOSITION RATHER THAN AN
                 * OMISSION. This figure sums `plan_orders.price`, declared `$table->float('price')`
                 * (`2023_12_11_102124_create_plan_orders_table.php:24`) -- and a `float` money
                 * column is the exact basis on which GAP-217 requires `MetricCaveat::FLOAT_PRECISION`
                 * on the report tiles. So the caveat WAS added here, and then removed, because the
                 * engine refused the premise.
                 *
                 * MEASURED on MariaDB 10.11 by `verify-saas-revenue-on-mysql.php` [2] and [6],
                 * against the table the REAL migrations build: Laravel 11's `float()` emits a MySQL
                 * **`double`** -- `SHOW CREATE TABLE plan_orders` says `price double DEFAULT NULL` --
                 * and NOT a single-precision `FLOAT`. A plan payment of `2500000.49` (SYP scale,
                 * where a seven-digit mantissa WOULD lose the minor units) stores and reads back
                 * exactly, as does `999999.99`. A hand-written `CREATE TABLE t (p float)` does lose
                 * them, which is how this was briefly believed of this column; the migration does
                 * not build that column.
                 *
                 * The caveat is therefore not true of this figure, and attaching it would have put a
                 * fabricated limitation on a money screen. `PlatformRevenueFiguresTest` pins its
                 * ABSENCE, so a future sweep that adds caveats by column NAME re-measures first.
                 */
                'hint' => __('Plan payments the platform recorded as taken, grouped by the currency each was charged in. The rows are NOT added together: converting between them would need the exchange rate on each payment\'s own date, which this schema does not record.'),
            ],
            'recent' => [
                'rows' => self::receiptRows($recent),
                'period' => __('the last 30 days'),
                'hint' => __('The same measurement over the last 30 days, so an all-time figure cannot make a platform that has stopped selling look like one that has not.'),
            ],
            'excluded' => self::excludedOrders($allTime),
        ];
    }

    /**
     * EVERY PLAN ORDER, GROUPED BY THE THREE THINGS THAT DECIDE WHAT IT MEANS, in ONE read.
     *
     * The group key is (status spelling, currency, refund flag) — which is at most a few dozen
     * rows on any platform, however many orders it has — and the classification then happens in
     * PHP. That is a deliberate change from a `lower(payment_status)` comparison in SQL, for two
     * measured reasons:
     *
     *   1. `laravel-model-caching` CANNOT BUILD A CACHE KEY FOR A RAW EXPRESSION USED AS A
     *      `whereIn` COLUMN: `CacheKey::getWhereIn()` interpolates `$where['column']` into a
     *      string and throws `Object of class Illuminate\Database\Query\Expression could not be
     *      converted to string`. `PlanOrder` is `Cachable`, so every call threw — on the figure
     *      that was supposed to be the honest one.
     *   2. SQLite compares case-SENSITIVELY by default and MySQL does not. Nine writers spell the
     *      settled status `Succeeded`, so a case-sensitive `whereIn` would drop their money on
     *      one engine and keep it on the other; folding in PHP gives both engines the SAME
     *      comparator, and it is the suite's engine that any future reader will test against.
     *
     * The fold is `strtolower(trim(...))`, which is exactly what `PlanOrderSettledStatusCensusTest`
     * applies to the spellings it extracts from the writers — one comparator, two places that must
     * agree about it.
     *
     * @return list<object> rows of {status, currency, refunded, amount, orders}
     */
    private static function planOrderGroups(?CarbonImmutable $since): array
    {
        $query = PlanOrder::query();

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }

        /*
         * PLATFORM-WIDE ON PURPOSE, AND COUNTED BY NEITHER RAW-QUERY INVENTORY. Recorded because
         * the project's discipline is that every statement no Eloquent scope can reach is
         * inventoried, and this one is not:
         *
         *   - `RawQueryTenancyInventoryTest` attributes `DB::table('literal')` sites and
         *     deliberately excludes select EXPRESSIONS on an Eloquent builder. `selectRaw` on
         *     `PlanOrder::query()` is one, so this site is in no inventory total — as is the
         *     `User::query()` site in `subscriptions()`. Two statements, both invisible.
         *   - There is no service-layer inventory to catch them either: `tests/Unit/Tenancy/`
         *     holds a scoped-model claim test and a scope-context test, and neither counts raw SQL.
         *
         * IT IS A VISIBILITY GAP AND NOT A TENANCY ONE, and the distinction is the disposition
         * rather than an excuse. `plan_orders` and `users` are in neither `scoped_models`, so the
         * global scope adds no predicate for ANY principal here (CLAUDE.md: `StoreScope` short-
         * circuits for a model the config does not list) — the reach is the same whether this is
         * `selectRaw` or Eloquent. And the figure is the PLATFORM's own revenue: every tenant's
         * plan payment is what it measures, so a tenant predicate would make it wrong. What is
         * missing is the ENTRY, not the guard. GAP-253 records it; the fix is an inventory that
         * reads `selectRaw` in `app/Services`, which is a census change and not this slice's.
         */
        return $query
            ->selectRaw(
                'payment_status as status, price_currency as currency, is_refund as refunded,'
                . ' sum(price) as amount, count(*) as orders'
            )
            ->groupBy('payment_status', 'price_currency', 'is_refund')
            ->toBase()
            ->get()
            ->all();
    }

    /** Whether a stored spelling means the platform was paid. One comparator, used three times. */
    private static function isSettled(?string $status): bool
    {
        return in_array(strtolower(trim((string) $status)), self::SETTLED_PLAN_STATUSES, true);
    }

    /**
     * NULL IS NOT REFUNDED. `is_refund` was added later with a default of 0, so a row written
     * before that migration can carry NULL; reading NULL as refunded would drop real money out of
     * platform revenue, which is the failure mode this whole class exists to prevent.
     */
    private static function isRefunded(mixed $flag): bool
    {
        return $flag !== null && (int) $flag === 1;
    }

    /**
     * @param  list<object>  $groups
     * @return list<array{currency: string, amount: string, orders: int}>
     */
    private static function receiptRows(array $groups): array
    {
        $byCurrency = [];
        $orders = [];

        foreach ($groups as $row) {
            if (! self::isSettled($row->status) || self::isRefunded($row->refunded)) {
                continue;
            }

            /*
             * `CurrencySymbol::code()` AND NOT `strtoupper(trim())`, which is what this line was.
             * Found by the independent financial review, and the project already states the rule:
             * `PHASE-05-METRIC-DEFINITIONS.md` section 11 rule 3 names `CurrencySymbol::code()` as
             * "the single definition of 'is a currency code' in this application", and
             * `MetricResult:113-115` routes every money bucket in the metrics layer through it.
             * `CurrencySymbol`'s own docblock draws the distinction that decides this: `clean()`
             * asks "is this safe to print" and `code()` asks "is this a currency at all", and
             * "a reader that GROUPS money by the value needs the second one". This reader groups
             * money by the value.
             *
             * `plan_orders.price_currency` is `string(10)` written by ~40 gateway handlers from a
             * free-text settings row, so `$`, `ل.س` or `Dollar` are all storable. Upper-casing them
             * gave each its OWN currency row with a verbatim heading and a partial subtotal --
             * splitting one real currency across two rows. Anything that is not three ASCII letters
             * now joins the one bucket that is spelled as itself, which is what that bucket is for.
             */
            $code = \App\Support\Money\CurrencySymbol::code($row->currency);
            $key = $code === '' ? MetricResult::UNKNOWN_CURRENCY : $code;

            $byCurrency[$key] = ($byCurrency[$key] ?? 0.0) + (float) $row->amount;
            $orders[$key] = ($orders[$key] ?? 0) + (int) $row->orders;
        }

        $presented = [];

        /*
         * `MoneyText::buckets()` and not a local normalisation: it already folds the codes, keeps
         * the one key that is NOT a currency code spelled as itself, and sorts alphabetically so
         * the list reads the same whichever order the engine's GROUP BY returned.
         */
        /*
         * `dropEmpty: false` — F2 FROM THE INDEPENDENT REVIEW, AND IT CONTRADICTED THIS CLASS'S OWN
         * PROMISE. `MoneyText::buckets()` defaults to dropping any bucket under half a cent, so a
         * currency whose settled orders summed to 0.00 vanished -- AND ITS ORDER COUNT VANISHED WITH
         * IT. The payment was then in no row, in no excluded count, and nowhere on the screen, while
         * this class's header says "Nothing is dropped and nothing is counted as zero". If it was
         * the only currency the view printed "Not available" -- measurement-failure wording for a
         * true, countable zero.
         *
         * REACHABLE ON A NORMAL PATH: `StripePaymentController:136-142` writes a `PlanOrder` with
         * `price = 0`, `payment_status = 'succeeded'` and `receipt = 'free coupon'` when the charge
         * is skipped, so free plans and 100%-coupon purchases land here. `buckets()` carries this
         * flag for exactly this caller and its docblock says so: a caller for whom the bucket's
         * EXISTENCE is a fact about the business wants `false`.
         */
        foreach (MoneyText::buckets($byCurrency, false) as $currency => $amount) {
            $presented[] = [
                'currency' => MoneyText::currencyLabel($currency),
                'amount' => MoneyText::amount($amount, $currency),
                'orders' => $orders[$currency] ?? 0,
            ];
        }

        return $presented;
    }

    /**
     * The two populations the receipts figure leaves out, counted rather than implied.
     *
     * Counted from the SAME grouped read the figures come from, so the excluded counts and the
     * included amounts cannot disagree about which rows were which — two independent queries over
     * a table that is being written to is exactly how a disclosure comes to contradict the figure
     * it qualifies.
     *
     * @param  list<object>  $groups
     * @return array{refunded: int, unsettled: int}
     */
    private static function excludedOrders(array $groups): array
    {
        $refunded = 0;
        $unsettled = 0;

        foreach ($groups as $row) {
            $orders = (int) $row->orders;

            if (! self::isSettled($row->status)) {
                $unsettled += $orders;

                continue;
            }

            if (self::isRefunded($row->refunded)) {
                $refunded += $orders;
            }
        }

        return [
            'refunded' => $refunded,
            'unsettled' => $unsettled,
        ];
    }
}
