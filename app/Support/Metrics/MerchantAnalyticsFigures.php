<?php

namespace App\Support\Metrics;

use App\Models\Order;
use App\Support\Analytics\AnalyticsPeriod;
use App\Support\Money\CurrencySymbol;
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
{    /**
     * The currency a BLANK `defult_currancy` setting means, and it is deliberately the same
     * constant name and the same value as `FinanceLedgerFigures::FALLBACK_CURRENCY`. The two
     * classes render money tiles side by side on `/analytics`; a review measured them disagreeing
     * about whether a figure has a unit at all when that row is empty.
     */
    private const FALLBACK_CURRENCY = 'USD';


    /** The activity window. Short enough to be about now, long enough that a quiet week is not a cliff. */
    /*
     * PUBLIC SINCE ANA-002, and for one specific reason: it is now the number
     * `AnalyticsPeriod::DEFAULT` has to agree with. `AnalyticsPeriodFilterTest` compares the
     * default period's bounds against `subDays(RECENT_DAYS - 1)->startOfDay()` so that adding a
     * filter cannot quietly move the window every pre-existing test was written against. A private
     * constant would force that check through reflection, which asserts the same thing less
     * readably and breaks on a rename without saying why.
     */
    public const RECENT_DAYS = 30;

    /**
     * How many distinct referring URLs are read before folding them into hosts. A bound is needed
     * because the host fold happens in PHP (SQLite has no URL functions), and when it is REACHED
     * the hint says so -- this project's rule is that a cap which is not disclosed reads as
     * completeness.
     */
    private const MAX_REFERER_ROWS = 500;

    /** The only columns `groupedVisits()` will group by; see its docblock for why it is a closed list. */
    private const GROUPABLE_VISIT_COLUMNS = ['device', 'platform', 'browser', 'referer', 'url'];

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
     *     redemptions: array{value: ?string, period: string, hint: string},
     *     retention: array{value: ?string, period: string, hint: string},
     *     devices: array{rows: list<array{label: string, count: string}>, period: string, hint: string},
     *     channels: array{rows: list<array{label: string, count: string}>, period: string, hint: string},
     *     sources: array{rows: list<array{label: string, count: string}>, period: string, hint: string}
     * }
     *
     * THE TWO BREAKDOWNS ARE PART OF THIS CONTRACT, not an extra. They are NOT the figure shape:
     * a figure is one `value`, a breakdown is a `rows` list, and `FigureCsv::rows()` skips any entry
     * with no `value` key -- so an export that forgot `breakdownRows()` would drop them in SILENCE
     * rather than fail. `PlatformAnalyticsFigures` already carries the same three-key shape for its
     * currency block, and that symmetry is what the Blade and the CSV both rely on.
     */
    public static function forStore(
        int $storeId,
        string $storeCurrency,
        ?CarbonInterface $now = null,
        ?AnalyticsPeriod $chosen = null,
        bool $moneyFollowsThePeriod = false,
    ): array {
        $now = $now === null ? CarbonImmutable::now() : CarbonImmutable::instance($now);

        /*
         * ANA-002 — THE CHOSEN PERIOD, AND WHY IT IS OPTIONAL.
         *
         * `$chosen === null` resolves to `AnalyticsPeriod::default()`, which is the 30-day rolling
         * window this screen has always shown, with the same label built from the same words. So a
         * caller that passes nothing gets BYTE-IDENTICAL figures to before the filter existed --
         * which is what lets the pre-existing tests stand as the regression proof that adding a
         * filter changed no unfiltered figure.
         *
         * `RECENT_DAYS` is still the constant behind that default: `AnalyticsPeriod::DEFAULT` is
         * `last_30_days`, and `PeriodDefaultMatchesFiguresTest` pins the two together so the
         * default cannot drift away from the constant this class documents.
         *
         * THE PERIOD IS CALLER-SUPPLIED AND THE STORE IS NOT. That distinction is the whole safety
         * argument: `$storeId` reaches this method from `getCurrentStore()` only, and every query
         * below still carries it. What a merchant chooses here is which of their OWN days to read.
         */
        $chosen = $chosen ?? AnalyticsPeriod::default($now);

        $from = $chosen->from();
        $to = $chosen->to();
        $period = $chosen->label();

        /*
         * P5-R15 -- THE MONEY HALF FOLLOWS THE PERIOD, BUT ONLY WHEN SOMEBODY ASKED FOR ONE.
         *
         * This was the first statement of the method and took NO window, so the money tiles
         * ignored the period control while the ten figures below it obeyed it. A merchant who
         * chose "Last 7 days" saw seven days of orders beside the ledger's whole coverage of
         * money. Nothing on screen was false -- `<x-ss.stat>` makes `period` a required prop and
         * each money tile prints its own, so the tile said "from 2 November 2026" while the
         * selector said seven days -- but the control did not govern half its own screen.
         *
         * `$moneyFollowsThePeriod` IS A SEPARATE SIGNAL FROM `$chosen`, and it has to be. The
         * shape this copies is already shipped on the sibling screen: `ReportController`'s finance
         * report resolves `null` unless the REQUEST named a period, because
         * `AnalyticsPeriod::fromRequest([])` falls through to `last_30_days` and "resolving one
         * unconditionally would narrow every existing reader's figure without being asked".
         * `FinanceReportPeriodTest::test_with_no_period_requested_the_figures_are_unchanged` pins
         * that, and `::test_choosing_all_time_in_the_control_is_not_a_period` pins the other
         * direction. This screen was the one that was missed.
         *
         * WHY NOT JUST `$chosen === null`: the scheduled-report runner has a stored period and
         * needs it for the counts, yet must NOT narrow the money -- so one nullable period cannot
         * carry both answers. `analytics_report_schedules.period_key` is `string(32)` NOT NULL, so
         * every saved schedule row holds a concrete preset whether or not the merchant ever chose
         * one; a stored row is therefore not evidence of a choice, and narrowing the money in a
         * recurring e-mail somebody set up months ago would change a figure they never asked to
         * change. The runner passes `false` and says so at its call site.
         *
         * NOTHING ABOUT THE CLAMP IS RE-IMPLEMENTED HERE and it must not be: `narrow()` already
         * clamps a request to the ledger's verified coverage, re-spells the label as the span it
         * really read, and refuses in words a period that ends before the records begin. A second
         * copy of that rule is how two screens start disagreeing about the same money.
         */
        $money = FinanceLedgerFigures::forStore(
            $storeId,
            $storeCurrency,
            $moneyFollowsThePeriod ? $chosen->window() : null,
            $moneyFollowsThePeriod ? $chosen->label() : null,
        );

        $orders = self::countOrders($storeId, $from, $to);
        $visits = self::countVisits($storeId, $from, $to);

        /*
         * ANA-004 / GAP-227(a) -- WHEN PAGE-VIEW LOGGING ACTUALLY BEGAN FOR THIS STORE.
         *
         * Orders reach back as far as the store does. Page views reach back only as far as
         * `shetabit_visits` holds rows for it, and that table starts whenever `ActiveTheme` first
         * logged one -- when the store first set a theme, or when the feature shipped, whichever
         * is later. Nothing backfills it.
         *
         * So for any window reaching back past that first row, `$visits` covers less of the window
         * than `$orders` does, and the ratio between them is a larger number that looks like a
         * conversion rate. Measured in VisitCoverageStartTest: 20.00% reported where the covered
         * period's own rate is 10.00%, with no warning of any kind before this.
         *
         * READ ONCE AND SHARED, for the same reason the baseline counts are: the visits tile and
         * the rate derived from it must not disagree about when coverage starts.
         */
        $visitCoverageStart = self::visitCoverageStart($storeId);
        $visitCoverageNote = self::visitCoverageNote($visitCoverageStart, $from, $visits);

        /*
         * ANA-002 — THE BASELINE, AND WHY ONLY THE TWO COUNTS GET ONE.
         *
         * `MetricWindow::previous()` gives the equally long period ending the day before this one
         * begins. It cannot be null here because both bounds are set a few lines above; the null
         * case exists for unbounded windows and is asserted in its own test.
         *
         * THE COUNTS ARE COMPARABLE AND THE MONEY IS NOT, and that asymmetry is measured rather
         * than cautious. `orders` comes from `orders`, which holds the whole history.
         *
         *   CORRECTED 2026-11-22, by the measurement two methods below. This paragraph used to
         *   say `shetabit_visits` holds the whole history too, "so a 30-day baseline is as
         *   complete as the 30 days after it". THAT IS FALSE. Visit rows carried no `store_id`
         *   at all until the attribution fix of 2026-11-20 (`SanitisedVisitor`'s docblock), and
         *   nothing is backfilled -- so an earlier window can be partly or wholly outside this
         *   store's attributed history exactly as the money tiles are. `visitCoverageStart()`
         *   is what measures it, and the two visit-derived comparisons are now suppressed when
         *   the BASELINE window reaches back past coverage, for the same reason the money tiles
         *   were left alone. An independent review caught that the first version of this change
         *   disclosed only the CHOSEN window and left the baseline free to report a false trend.
         *
         * The money tiles come from `FinanceLedgerFigures`, which inherits `LedgerCoverage`'s
         * verified start: `store_order_financials` holds nothing before 2026-11-02 and is NOT
         * backfilled, by owner decision. A baseline reaching back 31-60 days therefore lands
         * partly or wholly before coverage, so the prior figure would be understated by an unknown
         * amount and the delta would be a FALSE CLAIM dressed as a trend -- which is worse than no
         * comparison, because a merchant reads a delta as a fact about their business. Those tiles
         * are left alone until the coverage question is answered for them.
         */
        $baseline = \App\Support\Metrics\MetricWindow::between($from, $to)->previous();

        /*
         * THE BASELINE'S LABEL COMES FROM THE CHOSEN PERIOD'S OWN LENGTH, not from `RECENT_DAYS`.
         * Hardcoding 30 here was correct only while the window was fixed: on a 7-day filter it
         * would have printed "the 30 days before that" beside a delta computed over 7, which is a
         * figure whose label describes a different window. `previous()` returns the equally long
         * window ending the day before, so the length is the chosen period's own.
         */
        $baselinePeriod = $chosen->baselineLabel();

        /*
         * READ ONCE, USED THREE TIMES. These were two inline calls inside the orders and visits
         * tiles; the conversion tile's own baseline (GAP-227(a)) needs BOTH of them, and reading
         * them a second time would let the rate's baseline be computed from a different pair of
         * counts than the two tiles above it display.
         */
        $baselineOrders = self::countOrders($storeId, $baseline->from(), $baseline->to());
        $baselineVisits = self::countVisits($storeId, $baseline->from(), $baseline->to());

        /*
         * IS THE EARLIER WINDOW ITSELF COVERED? A chosen window can start AFTER coverage -- so it
         * gets no note -- while the equally long window before it starts BEFORE coverage and is
         * therefore understated by an unknown amount. The delta between them would then be a
         * false trend presented as a fact, which is the precise failure this slice exists to
         * prevent, so the two visit-derived comparisons are withheld rather than shown wrong.
         *
         * ONLY THE PARTLY-COVERED CASE, and the boundary matters. A baseline window lying
         * WHOLLY before coverage produces `$baselineVisits === 0`, and `comparison()` already
         * has a deliberate answer for that -- "There is no figure for the earlier period to
         * compare against" -- which four tests in `MerchantAnalyticsFiguresTest` and
         * `ConversionBaselineTest` encode as the shipped contract. A first version of this guard
         * fired on that case too and reddened seven tests; suppressing it would have replaced a
         * stated answer with silence, which is worse.
         *
         * The case `comparison()` CANNOT see is the window that STRADDLES the coverage start:
         * its figure is a real non-zero number counted over only part of the window, so the
         * delta looks sound and is not.
         */
        $baselineCovered = ! self::straddlesCoverage($visitCoverageStart, $baseline->from(), $baseline->to());

        return [
            'coverage' => $money['coverage'],
            'payments' => $money['payments'],
            'profit' => $money['profit'],
            'orders' => [
                'value' => self::ltr(number_format($orders)),
                'period' => $period,
                'hint' => __('Orders placed in this period, whatever their payment status. It is not a money figure and does not exclude orders later cancelled or refunded.'),
                'comparison' => self::comparison($orders, $baselineOrders, $baselinePeriod),
            ],
            'visits' => [
                'value' => self::ltr(number_format($visits)),
                'period' => $period,
                // The count itself is short by whatever happened before logging began, so the note
                // belongs here too and not only on the rate derived from it.
                'hint' => self::visitsMeaning().$visitCoverageNote
                    .($baselineCovered ? '' : ' '.self::baselineUncoveredNote()),
                'comparison' => $baselineCovered
                    ? self::comparison($visits, $baselineVisits, $baselinePeriod)
                    : null,
            ],
            'conversion' => self::conversion($orders, $visits, $period, $baselineOrders, $baselineVisits, $baselinePeriod, $visitCoverageNote, $baselineCovered),
            'customers' => self::customers($storeId, $from, $to, $period),
            'redemptions' => self::redemptions($storeId, $storeCurrency, $from, $to, $period),
            'retention' => self::retention($storeId),
            'devices' => self::devices($storeId, $from, $to, $period),
            'channels' => self::channels($storeId, $from, $to, $period, $visits),
            'sources' => self::sources($storeId, $from, $to, $period),
            'campaigns' => self::campaigns($storeId, $from, $to, $period),

            /*
             * ANA-002 — THE PERIOD ITSELF, so the screen and the CSV can both state what was
             * actually read without re-deriving it. `clamped` is the sentence saying a requested
             * period was shortened or moved; it is null when nothing was adjusted. A clamp a
             * merchant cannot see is a figure whose label does not describe it.
             */
            'period' => [
                'key' => $chosen->key(),
                'label' => $period,
                'from' => $chosen->from()->toDateString(),
                'to' => $chosen->to()->toDateString(),
                'days' => $chosen->days(),
                'clamped' => $chosen->clampNote(),
            ],
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

        /*
         * ANA-RED. THE GUARD USED TO ASK A DIFFERENT QUESTION FROM THE ONE THAT THROWS, and
         * `total()` is gone from this method because of it.
         *
         * What stood here filtered a COPY of `byCurrency()` -- dropping `''` keys and zero amounts
         * -- guarded on `count($buckets) > 1`, and then called `$result->total()`.
         * `MetricResult::total()` throws when `count($this->byCurrency) > 1` over the UNFILTERED
         * array, so the guard whose whole purpose was to prevent that exception could not see the
         * shape that causes it. MEASURED on this revision before the fix, with a probe:
         *
         *   a zero SYP bucket beside a real USD bucket   THREW MixedCurrencyException
         *   two zero buckets                             THREW MixedCurrencyException
         *   one SYP bucket, store setting USD            printed "250.00 USD"
         *
         * The first two are an UNCAUGHT exception on the two HTTP surfaces -- `GET /analytics` and
         * `GET /analytics/export`, neither of which has a try/catch. THE THIRD CALLER IS DIFFERENT
         * AND AN EARLIER DRAFT OF THIS COMMENT GOT IT WRONG: `ScheduledReportRunner` wraps the
         * call in `catch (\Throwable)`, logs a warning and marks the schedule FAILED. So there the
         * symptom was not a 500 but a recurring report that silently never arrived -- arguably
         * worse, because nobody is watching a schedule, but not what the sentence claimed.
         * And a zero-amount redemption row is ORDINARY DATA, not a corner case:
         * `order_coupon_details.coupon_discount_amount` is declared `float default 0` BY ITS
         * MIGRATION (per CLAUDE.md the migrations are not the schema, and no dump was available
         * here to cross-check it).
         *
         * THE THIRD IS THE QUIETER ONE AND IT DID NOT THROW. The amount came from the surviving
         * bucket and the label came from the store's `defult_currancy` setting, so a real figure
         * was shown under the wrong currency with no caveat and no refusal -- 250 Syrian pounds
         * reported as 250 US dollars. A wrong number a merchant cannot tell is wrong is worse
         * than a missing one, which is the principle the rest of this class is built on.
         *
         * SO THE DECISION AND THE NUMBER NOW COME FROM THE SAME ARRAY. `MoneyText::buckets()` is
         * the shared form of the filter this method rolled by hand, and it is better at it: it
         * normalises each key (`strtoupper(trim())`, except `UNKNOWN_CURRENCY`, which keeps its
         * spelling for FIN-SYM's reason), merges collisions, sorts so the answer does not depend
         * on the engine's GROUP BY order, and with `$dropEmpty` drops |amount| < 0.005. Nothing
         * real is lost by giving up the `$currency !== ''` limb: `Metric::sumByCurrency()` maps
         * both null and `''` to `UNKNOWN_CURRENCY`, so an empty key never reaches `byCurrency()`.
         *
         * The refusal below is unchanged and is still the right answer for the case it names: two
         * currencies that BOTH hold money, with nothing to convert them.
         */
        $buckets = MoneyText::buckets($result->byCurrency(), true);

        if (count($buckets) > 1) {
            /*
             * Two real currencies and nothing converted them. No number is invented -- and the
             * AMOUNTS ARE LISTED, so the tile is not a dead end for a store that changed
             * currency. `FinanceLedgerFigures::tile()` already did this and this one did not,
             * which a review called out: the data is already in `$buckets`, and a refusal that
             * shows nothing reads as missing data rather than as two honest numbers.
             */
            $listed = [];

            foreach ($buckets as $bucketCurrency => $bucketAmount) {
                $listed[] = MoneyText::amount((float) $bucketAmount, (string) $bucketCurrency);
            }

            return [
                'value' => null,
                'period' => $period,
                'hint' => __('Coupon discounts in this period are recorded in more than one currency and have not been converted, so no single total can be shown.')
                    . ' ' . __('These amounts are in more than one currency and are not combined: :amounts.', [
                        'amounts' => implode(', ', $listed),
                    ]),
            ];
        }

        /*
         * NO BUCKET MEANS NO MONEY, so the figure is zero and the label cannot misstate any
         * amount. The store's own setting names it, normalised through `CurrencySymbol::code()`
         * with a BLANK setting falling back to `USD`.
         *
         * THAT BLANK FALLBACK IS HERE BECAUSE A REVIEWER REFUTED MY OWN CLAIM ABOUT IT. A first
         * draft of this comment said `FinanceLedgerFigures` "has the same fallback". It does not:
         * that class normalises once at entry with `strtoupper(trim())` and substitutes its
         * `FALLBACK_CURRENCY` for an empty string, and it never calls `CurrencySymbol::code()`.
         * The two mechanisms differ, and with a blank `defult_currancy` row -- reachable, because
         * both this screen's controller and `ScheduledReportRunner` read it with
         * `->value('value') ?? 'USD'` and `??` does not catch `''` -- the earlier draft printed
         * `0.00 ` with no unit at all, beside a Payments tile on the SAME CARD reading `0.00 USD`.
         * Two money figures on one card disagreeing about whether a figure has a unit is not a
         * detail. So the blank case now lands on the same `USD` the sibling uses.
         *
         * A NON-BLANK setting that is not a code (a symbol, or a name like "US Dollar") still
         * prints verbatim, which is owner decision D-N34's territory and matches the sibling's
         * behaviour rather than inventing a policy here.
         */
        $currency = (string) array_key_first($buckets);
        $amount = $buckets === [] ? 0.0 : (float) $buckets[$currency];

        if ($buckets === []) {
            $currency = CurrencySymbol::code($storeCurrency) ?: trim($storeCurrency);
            $currency = $currency === '' ? self::FALLBACK_CURRENCY : $currency;
        }

        /*
         * THE DISCLOSURES, AND THE FIRST IS THE REVIEW'S HEADLINE FINDING.
         *
         * (1) `assumingUnknownCurrencyIs()` above folds rows that recorded NO currency into the
         *     store's, and `MetricResult` attaches `CURRENCY_UNRECORDED` plus
         *     `notes()['currency_assumed_amount']` precisely so a surface can say so. This method
         *     never read either -- it was the ONLY caller of that fold in the application that
         *     disclosed nothing, while `FinanceLedgerFigures`, `CustomerMoneyTiles` and
         *     `WalletEntitlementFigure` all state it. `orders.currency_code` was deliberately not
         *     backfilled, so an unrecorded currency is the ORDINARY state for older rows: the
         *     tile could print a real amount under a code the data does not record, with no
         *     caveat. That is the same failure mode as the defect this change fixes, arriving
         *     through the sanctioned door. The sentence is the sibling's, verbatim, so there is
         *     one wording and one translation key rather than two.
         *
         * (2) The bucket's currency can now legitimately DIFFER from the store's -- that is the
         *     point of taking the label from the bucket -- so the tile says when it does, in
         *     `WalletEntitlementFigure`'s words. Without it a merchant reading `250.00 SYP` on a
         *     USD store has no way to know the two were not converted.
         *
         * (3) THE SETTLEMENT BASIS, which no sentence on this tile stated. `CouponRedemption`
         *     joins `orders` with no `payment_status` and no cancellation predicate, so a coupon
         *     on an unpaid or later-cancelled order counts in full. The Orders and Payments tiles
         *     beside it both spell their basis out; this one did not, so "what coupons cost me"
         *     overstated by the cancelled and unpaid share with nothing on screen to say so. The
         *     clause describes what the query already does and decides no policy.
         */
        $notes = [
            __('The total discount given by coupons on orders in this period. Recorded in full from :date; earlier redemptions are not included and are not reconstructed.', [
                'date' => self::day(CouponRedemptionCoverage::forStore($storeId)->completeFrom()),
            ]),
            __('It counts coupons on orders placed in this period whatever their payment status, and does not exclude orders later cancelled or refunded.'),
        ];

        if ($result->hasCaveat(MetricCaveat::CURRENCY_UNRECORDED)) {
            $notes[] = __('Includes :amount from records that carry no currency, counted in your store currency.', [
                'amount' => MoneyText::amount((float) ($result->notes()['currency_assumed_amount'] ?? 0.0), $currency),
            ]);
        }

        $settingCode = CurrencySymbol::code($storeCurrency);

        if ($buckets !== [] && $settingCode !== '' && $currency !== $settingCode) {
            $notes[] = __('This figure is in :wallet, and your store currency is :store. The two are not converted.', [
                'wallet' => MoneyText::currencyLabel($currency),
                'store' => $settingCode,
            ]);
        }

        return [
            'value' => MoneyText::amount($amount, $currency),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }


    /**
     * REPEAT PURCHASE. P5-6's surface, and its coverage question is settled rather than reopened.
     *
     * THE RECONCILIATION, because the plan recorded this tile as owner-blocked. The stated blocker
     * was PHASE-05-FOUR-FIGURE-LABELLING-PROPOSAL.md §3: the entitlement record begins 2026-11-02
     * with no backfill, so a settled-basis figure reads zero for everything before it. §3 put three
     * options to the owner and recommended A -- "show it with the start date stated, and a window
     * that cannot be set earlier". **OWNER DECISION D1 IS THAT OPTION**, already approved and
     * already implemented in `LedgerCoverage`, which `/finance` has used since P5-16. So this tile
     * applies D1 rather than asking again; nothing new is decided here.
     *
     * THE WINDOW IS THE LEDGER'S, NOT THIS SCREEN'S 30 DAYS, and that is deliberate. A repeat
     * purchase needs two orders, so measuring it over thirty days systematically reports a store as
     * less loyal than it is -- the figure would be wrong in a direction a merchant cannot see.
     * `LedgerCoverage::window()` is the longest span the ledger can vouch for, which is the honest
     * basis, and the tile STATES that period rather than borrowing its neighbours' label.
     *
     * THE SETTLED BASIS is `CustomerRetention`'s default: a repeat-purchase rate computed over
     * orders whose payment nothing confirmed is not a retention figure. The metric discloses what
     * that costs -- `orders_without_ledger_row` and the unidentified-customer count -- and those
     * disclosures are carried onto the tile rather than summarised away.
     */
    private static function retention(int $storeId): array
    {
        $coverage = LedgerCoverage::forStore($storeId);
        $window = $coverage->window();

        if ($window === null) {
            return [
                'value' => null,
                'period' => __('not yet available'),
                'hint' => __('Repeat purchase is measured over the period the financial record can vouch for, and this store has no such period yet. No order has a financial record, so there is nothing to measure and nothing is reconstructed.'),
            ];
        }

        $period = $coverage->isComplete()
            ? __('all time')
            : __('from :date', ['date' => self::day($coverage->verifiedFrom())]);

        $figures = (new CustomerRetention())->for(MetricScope::store($storeId), $window);
        $rate = $figures->repeatRate();

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
        ];

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
            'value' => self::ltr(number_format($rate * 100, 2) . '%'),
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }

    /** A date a merchant reads, or the word for "the beginning" when the record covers everything. */
    private static function day(?CarbonInterface $date): string
    {
        return $date === null
            ? __('the beginning')
            : self::ltr($date->toDateString());
    }

    /**
     * The first page view this store ever logged, or null if it has never logged one.
     *
     * STORE-SCOPED BY HAND, because nothing else will do it. `shetabit_visits` carries `store_id`
     * but the model is NOT in `config/tenancy.php`'s `scoped_models`, so `StoreScope` adds no
     * predicate for any principal -- merchant, operator or guest. Without the `where` below, one
     * tenant's long log would make every other tenant's short one look complete, and a
     * single-tenant fixture cannot see the difference. Pinned by
     * `VisitCoverageStartTest::test_the_coverage_start_is_this_stores_own`.
     */
    private static function visitCoverageStart(int $storeId): ?CarbonImmutable
    {
        $first = Visit::query()
            ->where('store_id', $storeId)
            ->min('created_at');

        return $first === null ? null : CarbonImmutable::parse($first);
    }

    /**
     * The sentence a figure carries when the window reaches back past the first page view --
     * EMPTY STRING when it does not, which is most of the time and is the point.
     *
     * THREE WAYS THIS RETURNS NOTHING, each for its own reason:
     *
     *   - NO ROWS AT ALL (`$start === null`). That is the no-page-views case, which the figures
     *     already answer by refusing the division outright. A coverage sentence on top of it
     *     would answer the same question a second time and differently.
     *   - THE WINDOW STARTS AT OR AFTER COVERAGE. Nothing is missing, so there is nothing to say.
     *     A permanent "data may be incomplete" note on every render is not a disclosure, it is
     *     wallpaper: merchants stop reading it, and the real warning loses its meaning.
     *   - NO VISITS IN THIS WINDOW (`$visits <= 0`), even though the store has logged some. Same
     *     reason as the first: the refusal already covers it.
     *
     * IT DOES NOT RE-SCOPE THE FIGURES. The window stays exactly as the merchant asked for it,
     * and the sentence says which part of it has data. Silently narrowing the period to the
     * covered one would answer a question they did not ask and would make this tile disagree with
     * the Orders tile beside it. Which of the two is wanted is a product question; saying nothing
     * is wrong under either answer, which is why this is the part that can ship without one.
     */
    private static function visitCoverageNote(?CarbonImmutable $start, CarbonInterface $from, int $visits): string
    {
        if ($start === null || $visits <= 0 || ! $start->startOfDay()->greaterThan(CarbonImmutable::parse($from)->startOfDay())) {
            return '';
        }

        return ' '.__('Page views are recorded against this store from :date, so this period is only partly covered. Earlier visits were not attributed to any store and cannot be counted here.', [
            // `isoFormat` over `format` so the month name is translated; the locale is passed
            // explicitly rather than relying on Carbon's global one being in step with the app's.
            // `ltr()` because every other date this class prints is isolated, and an unwrapped
            // Western-digit date reorders itself inside an Arabic sentence.
            'date' => self::ltr($start->locale(app()->getLocale())->isoFormat('D MMM YYYY')),
        ]);
    }

    /**
     * Does this window BEGIN before page views were attributed to the store and END at or after
     * that point? That is the case whose count is non-zero and still short.
     *
     * Whole days, for the same reason `visitCoverageNote()` uses them: coverage begins at a clock
     * time while a window begins at midnight.
     */
    private static function straddlesCoverage(?CarbonImmutable $start, CarbonInterface $from, CarbonInterface $to): bool
    {
        if ($start === null) {
            return false;
        }

        $day = $start->startOfDay();

        return $day->greaterThan(CarbonImmutable::parse($from)->startOfDay())
            && $day->lessThanOrEqualTo(CarbonImmutable::parse($to)->startOfDay());
    }

    /**
     * Said when the EARLIER window is the one outside coverage, so its figure is understated and
     * the delta is withheld. Separate from the note above because the two describe different
     * windows and a merchant who sees one must not be told the other.
     */
    private static function baselineUncoveredNote(): string
    {
        return __('No change against the earlier period is shown: that period reaches back before page views were recorded against this store, so its figure would be understated and the comparison would report a trend that did not happen.');
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
    private static function conversion(
        int $orders,
        int $visits,
        string $period,
        int $baselineOrders,
        int $baselineVisits,
        string $baselinePeriod,
        string $coverageNote = '',
        bool $baselineCovered = true
    ): array {
        $meaning = __('Orders divided by storefront page views in this period.').' '.self::visitsMeaning().$coverageNote
            .($baselineCovered ? '' : ' '.self::baselineUncoveredNote());

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
            'comparison' => $baselineCovered
                ? self::rateComparison($rate, $baselineOrders, $baselineVisits, $baselinePeriod)
                : null,
        ];
    }

    /**
     * ANA-004 / GAP-227(a) — THE CONVERSION TILE'S BASELINE, IN PERCENTAGE POINTS.
     *
     * BOTH OF ITS INPUTS CARRIED A BASELINE AND THE RATIO CARRIED NONE, measured 2026-11-22. A
     * merchant could see that orders were up 12% and page views up 40% and could not see that the
     * rate had therefore FALLEN -- which is the one thing the tile exists to say.
     *
     * IT DOES NOT GO THROUGH `comparison()`, AND THAT IS THE WHOLE POINT. That helper reports a
     * change as a PERCENTAGE OF THE BASELINE, which is right for a count and a lie for a rate:
     * 2.10% rising to 2.80% is +0.70 PERCENTAGE POINTS, and calling it "+33%" is the classic way an
     * analytics screen overstates a small movement. So the change is stated in points, and
     * `percent` is deliberately left null -- the component already renders a comparison without
     * one, and a percentage of a percentage is the figure this method exists to avoid printing.
     *
     * A BASELINE WITH NO PAGE VIEWS IS NOT A ZERO RATE. It is no rate at all, for exactly the
     * reason the tile itself returns null in that case, so the comparison says so in words and
     * reports no movement rather than inventing a rise from nothing.
     *
     * @return array{direction: string, change: string, baseline: string, period: string, percent: null, note: ?string}
     */
    private static function rateComparison(float $rate, int $baselineOrders, int $baselineVisits, string $baselinePeriod): array
    {
        if ($baselineVisits <= 0) {
            return [
                'direction' => 'same',
                'change' => self::ltr(number_format(0, 2)).' '.__('percentage points'),
                'baseline' => __('no rate'),
                'period' => $baselinePeriod,
                'percent' => null,
                'note' => __('No page views were recorded in the earlier period, so there is no rate to compare against.'),
            ];
        }

        $baselineRate = ($baselineOrders / $baselineVisits) * 100;
        $difference = $rate - $baselineRate;

        return [
            'direction' => match (true) {
                $difference > 0 => 'up',
                $difference < 0 => 'down',
                default => 'same',
            },
            'change' => self::ltr(number_format(abs($difference), 2)).' '.__('percentage points'),
            'baseline' => self::ltr(number_format($baselineRate, 2).'%'),
            'period' => $baselinePeriod,
            'percent' => null,
            'note' => null,
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

    /**
     * ONE PERIOD AGAINST THE ONE BEFORE IT. ANA-002.
     *
     * The shape is deliberately NOT a pre-formatted sentence: `direction` is a machine value so a
     * view can word and style it, and the numbers are formatted separately. A single string would
     * force the view to parse it, and would make the Arabic wording unchangeable without touching
     * arithmetic.
     *
     * NO SIGN CHARACTER IS EMITTED, and that is a bidi decision rather than a style one. A leading
     * `+` or `-` beside a Western-digit number inside an Arabic sentence reorders unpredictably
     * -- the sign can end up on the far side of the digits -- so the magnitude is formatted
     * unsigned and wrapped in the isolates the rest of this class uses, and the DIRECTION travels
     * as its own value. The view says "up"/"down" in words, in either language.
     *
     * THE PERCENTAGE IS WITHHELD WHEN THERE IS NOTHING TO DIVIDE BY, which is the one place a
     * comparison can state a falsehood with a straight face. A baseline of zero and a current
     * figure of forty is not "+100%" and is not "+4000%" and is certainly not infinity: it is a
     * start from nothing, and the only true statement is the absolute change plus the fact that
     * there was no prior figure. `percent` is null in that case and `note` says why, exactly as
     * the figure tiles use `value => null` to mean "we cannot tell you this".
     *
     * MEASURED while mutation-testing this method: removing that guard does not merely print a
     * misleading percentage, it throws `DivisionByZeroError` on PHP 8 and the whole screen 500s for
     * any store whose previous period was empty -- which is every new store. So the guard is the
     * difference between an honest tile and an outage, not a stylistic nicety.
     *
     * Zero against zero is `same` with a 0% change, which IS computable and is not a refusal.
     *
     * @return array{direction:string, change:string, baseline:string, period:string, percent:?string, note:?string}
     */
    private static function comparison(int $current, int $baseline, string $baselinePeriod): array
    {
        $difference = $current - $baseline;

        $direction = match (true) {
            $difference > 0 => 'up',
            $difference < 0 => 'down',
            default => 'same',
        };

        $percent = null;
        $note = null;

        if ($baseline === 0 && $current === 0) {
            $percent = self::ltr(number_format(0, 1) . '%');
        } elseif ($baseline === 0) {
            $note = __('There is no figure for the earlier period to compare against, so no percentage is shown.');
        } else {
            $percent = self::ltr(number_format(abs($difference) / $baseline * 100, 1) . '%');
        }

        return [
            'direction' => $direction,
            'change' => self::ltr(number_format(abs($difference))),
            'baseline' => self::ltr(number_format($baseline)),
            'period' => $baselinePeriod,
            'percent' => $percent,
            'note' => $note,
        ];
    }

    /**
     * ANA-004 — WHAT THE VISITS CAME FROM. Device first.
     *
     * `visits.device` is written by `App\Services\SanitisedVisitor`, whose own docblock lists
     * `device` among the columns "the analytics screens read". So this is AGGREGATION, not
     * user-agent parsing: the classification is the library's and is not re-done or second-guessed
     * here.
     *
     * AN UNRECORDED DEVICE IS ITS OWN ROW, NAMED, and translated. The pre-existing
     * `ThemeAnalyticController` labels that case `'Other'` -- a hardcoded English word on a
     * bilingual product, and worse, a word that reads like a KIND of device rather than like an
     * absence. A visit whose agent the library could not classify is not an "other device"; it is a
     * visit we cannot tell you the device for.
     *
     * ORDERED BY COUNT, DESCENDING. The pre-existing screen orders by the LABEL
     * (`orderBy('device', 'DESC')`), which puts the alphabet before the magnitude and makes the
     * biggest share arbitrary to find.
     *
     * WINDOWED. The pre-existing screen has no date bound on these three breakdowns at all, so it
     * reports all time beside figures labelled thirty days. This one takes the screen's own window,
     * and `period` says which.
     */
    private static function devices(int $storeId, CarbonInterface $from, CarbonInterface $to, string $period): array
    {
        /*
         * THE EMPTY BUCKETS ARE FOLDED IN PHP, and a test is the reason this is not a plain loop
         * over the query's rows. SQL `GROUP BY device` treats NULL and the empty string as TWO
         * groups, so the first version of this method emitted two rows BOTH labelled "Device not
         * recorded", with a count of 1 each -- a merchant would have read one absence as two
         * devices and neither count as the real one. `channels()` below already folded in PHP for
         * the host; this does it for the same reason.
         */
        $buckets = [];
        foreach (self::groupedVisits($storeId, $from, $to, 'device') as $row) {
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
            'period' => $period,
            'hint' => $rows === []
                ? __('No visits were recorded in this period, so there is no device breakdown. Nothing is estimated.')
                : self::visitsMeaning() . ' ' . __('The device is whatever the visitor\'s browser reported; a visit whose device could not be determined is shown as not recorded rather than guessed.')
                    . ' ' . __('The detector names handsets and Macs. It does not identify a Windows, Linux or ChromeOS computer at all, so ordinary desktop visits appear under not recorded — that bucket is usually large for this reason and not because anything failed.'),
        ];
    }

    /**
     * ANA-004 — AND WHERE THEY CAME FROM, which is the half that needed a decision about what NOT
     * to claim.
     *
     * THE REFERRING HOST IS REPORTED VERBATIM. No host is classified into "search", "social" or
     * "paid", and that is deliberate rather than unfinished: such a classification needs a curated
     * list of hosts, a list is a product decision that goes stale, and a visit filed under the
     * wrong channel is worse than one filed under its own domain name. What IS measured -- which
     * host the browser named -- is reported.
     *
     * THE REFERER SURVIVES SANITISATION, which had to be checked before any of this was possible.
     * `SanitisedVisitor::redact()` strips sensitive query-parameter VALUES and replaces an
     * encrypted path segment, keeping scheme, host and path. So the host is intact; what is gone
     * is the capability token a referring URL might have carried.
     *
     * NO REFERER IS "NOT RECORDED", NOT "DIRECT". A missing referer is commonly read as a direct
     * visit and it is not evidence of one: a browser's privacy setting, an app's in-app webview, an
     * HTTPS-to-HTTP hop and a bookmarked link all strip it. Calling that bucket "Direct" would
     * invent a traffic source; it is named for what is true of it.
     *
     * THE HOST IS FOLDED IN PHP BECAUSE SQLITE HAS NO URL FUNCTIONS, so the grouping is done on
     * distinct referring URLs in SQL and folded to hosts here. That is bounded by
     * `MAX_REFERER_ROWS` and, when the bound is reached, the hint SAYS SO -- a silently truncated
     * breakdown reads as a complete one, and it also states how many visits are then missing from
     * the rows, so this column and the page-views tile cannot disagree by an unstated amount.
     *
     * THE BOUND LIMITS PHP, NOT THE DATABASE, and that is worth saying plainly rather than leaving
     * for someone to discover. `LIMIT` is applied AFTER grouping and ordering, so the engine still
     * scans every visit this store has in the window and builds a temporary table keyed on a
     * MEDIUMTEXT column. `visits` is indexed on `store_id` alone -- nothing on `created_at` -- and
     * one row is written per themed storefront request by `ActiveTheme`, anonymously and with no
     * throttle, so an unthrottled writer can make this store's own `/analytics` slow over time.
     * It is NOT an unauthenticated amplifier: the read requires `Manage Reports`. The effective
     * remedy is a composite `(store_id, created_at)` index, which is a PERSISTENT MIGRATION and
     * therefore the owner's decision, so it is recorded here rather than taken. The pre-existing
     * `ThemeAnalyticController` and `Api\DashboardController` run the same shape with no window
     * and no limit at all, so this is inherited, not introduced.
     */
    /** The tags read off a landing URL. `utm_term` and `utm_content` are ad-level and not shown. */
    private const CAMPAIGN_TAGS = ['utm_source', 'utm_campaign'];

    /** Distinct tagged LANDING URLS read before the list is capped; see the note the cap emits. */
    private const MAX_CAMPAIGN_URL_ROWS = 500;

    /**
     * ANA-004 / GAP-227(a) -- WHICH CAMPAIGNS SENT TRAFFIC.
     *
     * MEASURED 2026-11-22: `utm_source` / `utm_medium` / `utm_campaign` occur 20+ times in this
     * repository and EVERY occurrence is an outbound link in a vendored WorkDo view pointing at
     * workdo.io. No first-party code read an INBOUND tag. Meanwhile `SanitisedVisitor::url()`
     * keeps the query string and redacts only credential-shaped parameter NAMES -- `utm_*` match
     * none of them -- so the tags have been recorded all along and nothing displayed them.
     *
     * THREE CLAIMS THIS REFUSES TO MAKE, each because the data cannot support it:
     *
     *   NO ATTRIBUTION AND NO CAMPAIGN REVENUE. Nothing links a visit row to an order row --
     *   established for the conversion tile and unchanged. "This campaign earned you X" is not
     *   derivable, so it is not shown and the hint says why.
     *
     *   IT COUNTS LANDINGS, NOT PEOPLE AND NOT SESSIONS. A shopper arriving on a tagged link and
     *   then browsing five untagged pages is ONE row here and six page views on the tile beside
     *   it. The two columns are therefore not comparable and the hint says so, because the layout
     *   invites exactly that comparison.
     *
     *   LAST-TOUCH IS NOT ASSUMED EITHER. Only the request that actually carried the tag is
     *   counted; no tag is carried forward across a visit.
     *
     * THE PRE-FILTER IS A SUPERSET, DELIBERATELY. `LIKE '%utm%'` narrows the read cheaply but
     * decides nothing: what counts as a tag is settled by parsing the query string, so
     * `/products/autumn-outmoded-hat` and `?sutm_source=` are read and then dropped. A tighter
     * SQL pattern would have to reason about LIKE's own `_` wildcard and could silently MISS
     * campaign traffic, which is the worse failure of the two.
     */
    private static function campaigns(int $storeId, CarbonInterface $from, CarbonInterface $to, string $period): array
    {
        // MAX + 1, so "exactly full" is distinguishable from "more than fits" -- the same reason
        // `channels()` reads one extra row rather than testing `>= MAX`.
        $raw = self::groupedVisits($storeId, $from, $to, 'url', self::MAX_CAMPAIGN_URL_ROWS + 1, '%utm%');
        $capped = count($raw) > self::MAX_CAMPAIGN_URL_ROWS;

        if ($capped) {
            $raw = array_slice($raw, 0, self::MAX_CAMPAIGN_URL_ROWS);
        }

        $buckets = [];

        foreach ($raw as $row) {
            $tags = self::campaignTags((string) ($row->bucket ?? ''));

            if ($tags === null) {
                continue;
            }

            [$source, $campaign] = $tags;

            /*
             * A CAMPAIGN NAME AND A SOURCE ARE DIFFERENT QUESTIONS AND BOTH ARE SHOWN. Folding
             * `spring` from the newsletter together with `spring` from Instagram would hide the
             * one thing the merchant is asking -- which channel delivered it. A half-tagged link
             * (source only) keeps its own row rather than being dropped, because half-tagged
             * links are what people actually paste.
             */
            $label = $campaign === '' ? $source : $campaign.' — '.$source;

            $buckets[$label] = ($buckets[$label] ?? 0) + (int) $row->total;
        }

        // One orderer, in PHP, because the buckets are formed after the SQL read -- a second
        // `orderBy` in the query would make either orderer individually revertible and so
        // untestable, which a mutation caught on `sources()`.
        arsort($buckets);

        $rows = [];

        foreach ($buckets as $label => $count) {
            /*
             * THE LABEL IS ISOLATED BECAUSE A VISITOR WROTE IT.
             *
             * Every other breakdown on this screen labels its rows from something the platform
             * derived -- a device name from a detector, a HOST parsed out of a referer. A campaign
             * tag is different in kind: it is whatever text a visitor put in the query string of a
             * link they followed, and it reaches a merchant's admin screen verbatim.
             *
             * Blade's `{{ }}` escapes the HTML, so this is not an injection. What `e()` does NOT
             * neutralise is a Unicode BIDI OVERRIDE (U+202E and its family), which is not markup
             * and survives escaping intact: a tag containing one reorders the glyphs of whatever
             * follows it, so a crafted campaign name can make the rest of the row -- or the rows
             * after it -- read as something other than what the data says.
             *
             * `ltr()` wraps the value in U+2066 ... U+2069, an ISOLATE. That is the construct for
             * exactly this: it confines any direction change to the wrapped text, so the worst a
             * crafted tag can do is scramble its own cell.
             *
             * It is also the ordinary bilingual fix, and would be worth doing with no attacker in
             * the picture: a Latin campaign name sitting in an Arabic RTL table reorders around
             * the neighbouring digits without it.
             */
            $rows[] = ['label' => self::ltr((string) $label), 'count' => self::ltr(number_format($count))];
        }

        $notes = [];

        if ($rows === []) {
            // An empty panel that says nothing leaves a merchant unable to tell "no tagged
            // traffic" from "this is broken".
            $notes[] = __('No visit in this period arrived on a link carrying campaign tags, so there is nothing to break down. Nothing is estimated.');
            $notes[] = __('Add utm_source and utm_campaign to the links you share to see them counted here.');
        } else {
            $notes[] = __('Each line counts landings: requests that arrived carrying campaign tags. It is not a count of people or of sessions, and a visitor who arrives on a tagged link and then browses further adds one line here and several page views to the figure above, so the two are not comparable.');
            $notes[] = __('No order is linked to a campaign anywhere in the data, so this cannot say what a campaign earned and no revenue figure is offered. It reports arrivals only.');
            $notes[] = __('A link tagged with a source but no campaign name is shown under its source alone.');
        }

        if ($capped) {
            $notes[] = __('Only the :count most common tagged landing addresses were read, so a rarely used tagged link may not be represented in any row.', [
                'count' => self::ltr(number_format(self::MAX_CAMPAIGN_URL_ROWS)),
            ]);
        }

        return ['rows' => $rows, 'period' => $period, 'hint' => implode(' ', $notes)];
    }

    /**
     * The `[source, campaign]` a URL carries, or NULL when it carries no usable tag.
     *
     * A campaign with no SOURCE returns null: `utm_campaign=spring` alone names a campaign but
     * not where it ran, and a row labelled only `spring` would sit beside rows labelled
     * `spring — newsletter` and read as a third channel. Recorded rather than silently dropped
     * in a comment, because it is a judgement and not a fact.
     *
     * @return array{0: string, 1: string}|null
     */
    private static function campaignTags(string $url): ?array
    {
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return null;
        }

        parse_str($query, $parameters);

        $values = [];

        foreach (self::CAMPAIGN_TAGS as $tag) {
            $raw = $parameters[$tag] ?? '';
            // An array-shaped parameter (`utm_source[]=x`) is not a tag; `trim` would fatal on it.
            $values[$tag] = is_string($raw) ? trim($raw) : '';
        }

        if ($values['utm_source'] === '') {
            return null;
        }

        return [$values['utm_source'], $values['utm_campaign']];
    }

    private static function channels(int $storeId, CarbonInterface $from, CarbonInterface $to, string $period, int $visits): array
    {
        /*
         * MAX + 1 READ, SO THE CAP IS DETECTED EXACTLY. Reading MAX and testing `>= MAX` told a
         * store with PRECISELY 500 distinct referring pages that its list was truncated when it
         * was not -- an over-disclosure, which trains a reader to ignore the sentence. One extra
         * row distinguishes "exactly full" from "more than fits"; the extra row is then dropped.
         */
        $raw = self::groupedVisits($storeId, $from, $to, 'referer', self::MAX_REFERER_ROWS + 1);
        $capped = count($raw) > self::MAX_REFERER_ROWS;

        if ($capped) {
            $raw = array_slice($raw, 0, self::MAX_REFERER_ROWS);
        }

        $hosts = [];

        foreach ($raw as $row) {
            $referer = trim((string) ($row->bucket ?? ''));
            $count = (int) $row->total;

            if ($referer === '') {
                $key = '';
            } else {
                $host = parse_url($referer, PHP_URL_HOST);
                // An unparseable referer is not a host and must not be printed as one.
                $key = is_string($host) && $host !== '' ? strtolower($host) : '?';
            }

            $hosts[$key] = ($hosts[$key] ?? 0) + $count;
        }

        arsort($hosts);

        $rows = [];

        foreach ($hosts as $host => $count) {
            $rows[] = [
                'label' => match ($host) {
                    '' => __('No referring page recorded'),
                    '?' => __('Referring page could not be read'),
                    default => $host,
                },
                'count' => self::ltr(number_format($count)),
            ];
        }

        $notes = [];

        if ($rows === []) {
            $notes[] = __('No visits were recorded in this period, so there is no referrer breakdown. Nothing is estimated.');
        } else {
            $notes[] = self::visitsMeaning();
            $notes[] = __('Each line is the host the visitor\'s browser named. No host is filed under a marketing channel: that would need a maintained list of search and social domains, and a visit filed under the wrong channel is worse than one shown under its own domain. Your own domain appears here when a shopper moved between your pages.');
            $notes[] = __('A visit with no referring page is shown as not recorded, NOT as direct: a privacy setting, an in-app browser, a bookmark or an HTTPS-to-HTTP hop all remove it.');
        }

        /*
         * WHEN THE READ IS CAPPED, THE COLUMN NO LONGER SUMS TO THE PAGE-VIEWS TILE, and saying
         * only "less frequent ones are not represented" does not cover that -- raised by an
         * independent review and it was right.
         *
         * The cap bounds distinct referring PAGES; the rows a merchant reads are HOSTS. A host's
         * printed count is the sum of only the page-groups that survived, so a store whose
         * shoppers navigate internally (one group per product URL) can show its own domain at 499
         * when the true figure is 600 -- and the reader has no way to know by how much. Two
         * figures on one screen disagreeing by an UNSTATED amount is the defect this whole
         * workstream exists to prevent, so the amount is stated.
         */
        if ($capped) {
            $represented = array_sum($hosts);
            $missing = max(0, $visits - $represented);

            $notes[] = __('Only the :count most common referring pages were read, so the counts above do not add up to the page views for this period: :missing visit(s) are not represented in any row.', [
                'count' => self::ltr(number_format(self::MAX_REFERER_ROWS)),
                'missing' => self::ltr(number_format($missing)),
            ]);
        }

        return [
            'rows' => $rows,
            'period' => $period,
            'hint' => implode(' ', $notes),
        ];
    }

    /**
     * ANA-004 / GAP-227(a) — ORDERS BY SALES CHANNEL, the one real channel dimension in the schema.
     *
     * THE COLUMN ALREADY EXISTED AND NOTHING EVER AGGREGATED IT. `orders.order_source` is
     * `string(20)`, nullable, with a composite index `(store_id, order_source)` and a vocabulary of
     * exactly three values. It has been a per-row label on the order list and the kanban card, and
     * a filter (`?ss_source=`), since P2-04-11 — and the 2026-11-22 measurement of `ANA-004` found
     * that **no figure, chart or breakdown anywhere in the product ever grouped by it**. This is
     * that grouping, and nothing more: no new column, no migration, no inference.
     *
     * IT IS AN ORDER BREAKDOWN AND NOT A CONVERSION ONE, and the hint says so rather than leaving a
     * merchant to assume otherwise. The same measurement established that there is NO LINK OF ANY
     * KIND between a visit and an order in this schema, so these counts cannot be divided by the
     * referring-page or device breakdowns beside them. Placing three tables on one screen invites
     * exactly that division; saying it is impossible is cheaper than letting somebody derive a
     * number that means nothing.
     *
     * NULL IS AN ABSENCE AND KEEPS ITS OWN ROW. `Order`'s own docblock is explicit: "THERE IS
     * DELIBERATELY NO `SOURCE_UNKNOWN`. An order with no source has NULL, and NULL is the ABSENCE
     * of a record, not a fourth category: it means the order predates the column." So the empty
     * bucket is labelled as an absence WITH ITS REASON, and `Order::orderSourceLabel()` — which
     * returns null for anything it does not know, deliberately — is the only namer used.
     *
     * A VALUE THE VOCABULARY DOES NOT KNOW IS SHOWN, NOT DROPPED, and not folded into the absence
     * either. A row written by a future version, by hand or by a restored dump would otherwise
     * vanish from a breakdown that still showed a plausible total, and the one thing worse than an
     * unexplained row is a missing one. It is shown verbatim and marked as unrecognised.
     *
     * THE STORE PREDICATE IS EXPLICIT. `Order` carries no `StoreScope` and is not in
     * `config/tenancy.php`'s `scoped_models`, so no global scope adds `store_id` for anybody —
     * operator included. Windowed on `order_date`, the same column and bounds `countOrders()` uses,
     * so this breakdown and the Orders tile above it cannot disagree about which orders are in the
     * period.
     *
     * @return array{rows: list<array{label: string, count: string}>, period: string, hint: string}
     */
    private static function sources(int $storeId, CarbonInterface $from, CarbonInterface $to, string $period): array
    {
        $rows = Order::query()
            ->selectRaw('order_source as bucket, COUNT(*) as total')
            ->where('store_id', $storeId)
            ->whereBetween('order_date', [$from, $to])
            ->groupBy('order_source')
            ->get();

        /*
         * FOLDED IN PHP for the reason `devices()` records: SQL `GROUP BY` treats NULL and the
         * empty string as two groups, so an unrecorded source would otherwise emit two rows both
         * labelled as an absence, and a merchant would read one absence as two.
         *
         * AND THAT IS WHY THERE IS NO `orderByDesc('total')` ABOVE, although `devices()`'s shared
         * helper has one. Folding CHANGES the order -- two absence buckets merge into a bucket
         * larger than either -- so SQL cannot decide it and `arsort()` below must. A draft had
         * both, and a mutation run showed exactly why that is worse than it looks: with two
         * orderers, reverting EITHER one leaves the other producing the right answer, so neither
         * mutation can be caught and the ordering reads as covered when only the pair is. One
         * orderer, one mutation, one test.
         */
        $buckets = [];

        foreach ($rows as $row) {
            $value = trim((string) ($row->bucket ?? ''));

            $buckets[$value] = ($buckets[$value] ?? 0) + (int) $row->total;
        }

        arsort($buckets);

        $out = [];
        $unrecognised = false;

        foreach ($buckets as $value => $count) {
            if ($value === '') {
                $label = __('Source not recorded (placed before this was tracked)');
            } else {
                $named = Order::orderSourceLabel($value);

                if ($named === null) {
                    $unrecognised = true;
                    $label = __(':value (not a source this version knows)', ['value' => $value]);
                } else {
                    $label = $named;
                }
            }

            $out[] = ['label' => $label, 'count' => self::ltr(number_format($count))];
        }

        $hint = __('Which path created each order: the point of sale, a merchant entering it by hand, or the storefront. It is recorded by the writer when the order is created and is never inferred afterwards.')
            .' '.__('These are ORDERS, not visits. There is no link in this product between a page view and an order, so these counts cannot be divided by the page-view breakdowns beside them to produce a rate per channel.');

        if ($unrecognised) {
            $hint .= ' '.__('One or more orders carry a source this version does not have a name for. They are shown with the stored value rather than left out of the count.');
        }

        return [
            'rows' => $out,
            'period' => $period,
            'hint' => $out === []
                ? __('No orders were placed in this period, so there is no channel breakdown. Nothing is estimated.')
                : $hint,
        ];
    }

    /**
     * One grouped count over `visits`, store-scoped and windowed, for whichever column the caller
     * names.
     *
     * THE COLUMN IS NOT CALLER INPUT. It is chosen from a closed list here, because the value is
     * interpolated into `selectRaw`/`groupBy` and a name from a request would be an injection
     * point. Both call sites pass a literal; the guard is for the third.
     *
     * The store predicate is EXPLICIT. `shetabit_visits` is not in `config/tenancy.php`'s
     * `scoped_models`, so no global scope adds `store_id` for anybody -- operator included.
     *
     * @return list<object>
     */
    private static function groupedVisits(int $storeId, CarbonInterface $from, CarbonInterface $to, string $column, ?int $limit = null, ?string $contains = null): array
    {
        if (! in_array($column, self::GROUPABLE_VISIT_COLUMNS, true)) {
            throw new \InvalidArgumentException('visits cannot be grouped by ' . $column);
        }

        $query = Visit::query()
            ->selectRaw($column . ' as bucket, COUNT(*) as total')
            ->where('store_id', $storeId)
            ->whereBetween('created_at', [$from, $to])
            ->groupBy($column)
            ->orderByDesc('total');

        /*
         * An OPTIONAL coarse pre-filter, bound as a parameter. It exists so the campaign read
         * does not pull every distinct storefront URL through the row cap before discovering that
         * almost none carry tags -- without it the cap would drop campaign traffic systematically
         * on any busy store. It narrows the READ and decides nothing: the caller parses what
         * comes back.
         */
        if ($contains !== null) {
            $query->where($column, 'like', $contains);
        }

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get()->all();
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
