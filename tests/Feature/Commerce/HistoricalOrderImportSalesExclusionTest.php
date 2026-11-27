<?php

namespace Tests\Feature\Commerce;

use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\StripsSourceComments;

/**
 * DATA-005 / DATA-007 — HISTORICAL ORDERS MUST NOT COUNT AS NEW SALES.
 * =================================================================================================
 *
 * Two approved decisions meet here, and the pair is the whole point:
 *
 *   `DATA-005`  استيراد آمن للمنتجات والعملاء والمخزون والطلبات التاريخية
 *               Safe import of products, customers, stock and HISTORICAL ORDERS.
 *   `DATA-007`  عدم احتساب الطلبات التاريخية كمبيعات جديدة
 *               Historical orders must NOT count as new sales.
 *
 * If DATA-005's importer lands and DATA-007 is forgotten, every revenue figure in the product is
 * wrong on the day a merchant migrates in five years of history — the dashboard's gross sale, the
 * reports, the Flutter app's totals and the platform's own SaaS figures all at once, with no error
 * and no way for the merchant to tell. So DATA-007 is a PRECONDITION on DATA-005's design rather
 * than a filter that can be added afterwards, and this file measures the three facts that decide
 * how expensive it is.
 *
 * =================================================================================================
 *  WHAT IS TRUE TODAY, MEASURED — AND WHY THIS FILE BUILDS NOTHING
 * =================================================================================================
 *
 * FACT 1. THERE IS NO HISTORICAL-ORDER IMPORT. Measured: the eight `Imports/` classes across all 70
 * modules are products, brands, labels, main and sub categories, countries, states and cities.
 * There is no order importer anywhere in `app/`, `packages/workdo/<module>/src/app/Imports/` or `routes/`,
 * and no `is_historical`, `imported_at` or `import_source` column on `orders`. So DATA-007 is
 * VACUOUSLY SATISFIED: nothing can be double-counted because nothing is imported. That is a real
 * disposition and it is NOT the same as "done" — DATA-005's importer is missing functionality, and
 * the day it exists this test's first assertion fails and tells whoever wrote it to read the rest.
 *
 * FACT 2. A FUTURE IMPORTER CANNOT LAND SILENTLY, and this is existing protection rather than a
 * hope. `orders.order_source` (P2-04-11, owner-approved 2026-10-28) records WHICH PATH created an
 * order, with **no column default** precisely so an unstamped writer leaves NULL rather than
 * claiming a provenance nobody established. `OrderSourceWriterCensusTest` then holds the door: it
 * asserts an EXACT count of order writers and that each stamps a value from `Order::SOURCES`. A
 * historical-order importer would be writer number 21 and would have no vocabulary entry, so it
 * fails that census twice before it can ship. Asserted here so the dependency is recorded rather
 * than assumed.
 *
 * FACT 3. AND THIS IS THE FINDING: THERE IS NO SINGLE PLACE TO PUT THE EXCLUSION. `Metric`'s
 * docblock says its four accessors are the only raw queries a metric can reach, and they are
 * `final` so a subclass cannot obtain an unscoped builder. That is a genuine chokepoint — FOR THE
 * METRIC LAYER. It is not the only thing that sums an order. Measured below: order money is
 * aggregated at sites OUTSIDE `app/Support/Metrics/`, in `ReportController` ("Gross Sale"),
 * `HomeController` (the merchant dashboard's own gross sale), `Api/DashboardController` (what the
 * shipped Flutter app shows) and TWO models — `ActivityLog::totalspend()` and
 * `Customer::total_spend()`. Each one would count an imported historical order as a new sale.
 *
 * ("three models" until 2026-11-27, and it was never right: the declared list has held exactly two
 * `app/Models/` entries since it was written. Corrected while re-keying the census, because a
 * figure in the prose beside a measurement is read as part of it.)
 *
 * SO THE COST OF DATA-007 IS KNOWN RATHER THAN GUESSED, and that is what this file delivers:
 * the exclusion is not one line at a chokepoint, it is the metric accessors PLUS every site in the
 * census below. A future author who assumes `Metric` is the only door will leave the merchant
 * dashboard and the Flutter app wrong.
 *
 * =================================================================================================
 *  WHY THE VOCABULARY IS NOT WIDENED HERE
 * =================================================================================================
 *
 * The obvious move is a fourth `Order::SOURCE_HISTORICAL_IMPORT`, which the column was explicitly
 * designed to allow — its migration says the vocabulary "is expected to widen, and widening an
 * application-level allowlist is additive where widening a MySQL enum is a schema change".
 *
 * IT IS NOT DONE IN THIS CHANGE, for a reason that is about ownership rather than engineering.
 * `Order::SOURCES` is asserted as an EXACT SET by `OrderSourceWriterCensusTest`
 * (`assertSame(['pos', 'manual', 'storefront'], Order::SOURCES)`), iterated by
 * `OrderSourceAttributionTest`, `OrdersByChannelTest` and `OrderSourceBreakdownFoldsSpellingsTest`,
 * read by a MySQL verification script, and it drives the merchant order list's FILTER through
 * `OrderDataTable` and `TableFilterMemory` — so a fourth value is a user-visible filter option on a
 * Phase 02 surface and edits to four other workstreams' test files. Landing dead scaffolding plus
 * those edits, for an importer that does not exist, buys nothing and costs an integration conflict.
 * The precise change set is reported to the coordinator instead, to land WITH DATA-005's importer.
 *
 * WHAT THIS FILE THEREFORE IS: verification, not implementation. It records the three facts above
 * as executed assertions so that the next person to touch order import inherits a measurement
 * rather than a guess, and so that the day the importer arrives, this file fails.
 */
final class HistoricalOrderImportSalesExclusionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    /**
     * EVERY SITE THAT AGGREGATES ORDER MONEY OUTSIDE `app/Support/Metrics/`, measured 2026-11-26,
     * RE-KEYED 2026-11-27 ON THE METHOD RATHER THAN THE LINE.
     *
     * Each is a place a historical order would be counted as a new sale. The value is what the site
     * feeds and how many aggregations it holds, because "13 sites" is not actionable and "the
     * merchant dashboard's gross sale, twice" is.
     *
     * =============================================================================================
     *  WHY THE KEY IS A METHOD NOW. IT WAS `file:line`, AND TWO OF THE TEN WENT STALE IN ONE DAY.
     * =============================================================================================
     *
     * `HomeController.php:2123` and `:2138` were correct when this census was written and were
     * `:2185` and `:2200` the next day. Both lines are BYTE-IDENTICAL to the citing commit's own
     * tree (`git show 0628e8fdf:app/Http/Controllers/HomeController.php` at 2123 and 2138), so
     * nothing about the subject changed: 62 lines were inserted ABOVE them by three commits that
     * touched neither —
     *     a422c18f3  CUST-003, an unpublished product must not be offered     +13 −1
     *     3d72820e6  ADM-001/ADM-002, one operator definition and Store 360   +29 −1
     *     8cb288150  GAP-231, the two operator rankings count settled only    +22 −0
     * = +62 exactly. CLAUDE.md §3's convention exists for this: *cite the METHOD, not the line. It
     * cannot drift and a grep finds it.*
     *
     * AND THE DRIFT HAD TO BE MEASURED AGAINST THE CITING COMMIT'S OWN TREE, NOT `HEAD~1`, which
     * CLAUDE.md also records: 88 commits separate `0628e8fdf` from HEAD, exactly FIVE touch this
     * file, and `git diff HEAD~1 HEAD`, `HEAD~2 HEAD` and `HEAD~3 HEAD` all report it unchanged —
     * so any of those three parents would have shown a drift of zero while the real drift was 62.
     *
     * A METHOD KEY ALONE IS NOT ENOUGH, AND THAT IS MEASURED TOO. Three of the ten sites share a
     * method with another: `file::method()` alone is 7 distinct keys for 10 sites
     * (`best_selling_brand_chart`, `order_reports_chart` and `order_reports_chart_data` hold two
     * each — a per-date series and a running total). So the declared value carries the COUNT, and
     * `test_the_metric_layer_is_not_the_only_place_order_money_is_summed` asserts the method set
     * AND the count per method in both directions: a new aggregation inside an existing method
     * moves its count, and a new method appears as a new key. The failure message names the
     * matched lines, so a reader still gets the code without the key having to embed it.
     *
     * (`file::method()` plus the matched LINE TEXT would also be 10 distinct keys — measured. It is
     * not used because it would make each declared key a 130-character copy of the expression it
     * points at, which drifts on any reformat of that line, and a key nobody can read is not a
     * better citation than one that is merely coarse.)
     *
     * @var array<string, array{sites: int, feeds: string}>  "file::method()" => how many, and what it feeds
     */
    private const AGGREGATION_SITES = [
        /* IN THE CENSUS'S OWN `ksort` ORDER, declared rather than sorted in the assertion, so the
           diff a reader is shown on a failure is a one-line insertion rather than a reordering. */
        'app/Http/Controllers/Api/DashboardController.php::CustomerList()' => [
            'sites' => 1, 'feeds' => 'THE SHIPPED FLUTTER ADMIN APP',
        ],
        'app/Http/Controllers/Api/DashboardController.php::getCustomerOrdersApi()' => [
            'sites' => 1, 'feeds' => 'THE SHIPPED FLUTTER ADMIN APP',
        ],
        'app/Http/Controllers/HomeController.php::best_selling_brand_chart()' => [
            'sites' => 2, 'feeds' => 'THE MERCHANT DASHBOARD gross-sale series, and its total',
        ],
        'app/Http/Controllers/ReportController.php::order_reports_chart()' => [
            'sites' => 2, 'feeds' => 'report "Gross Sale" series per date, and its total',
        ],
        'app/Http/Controllers/ReportController.php::order_reports_chart_data()' => [
            'sites' => 2, 'feeds' => 'report "Gross Sale" series per date, and its total',
        ],
        'app/Models/ActivityLog.php::totalspend()' => [
            'sites' => 1, 'feeds' => 'SUM(final_price) and COUNT(*) per activity window',
        ],
        'app/Models/Customer.php::total_spend()' => [
            'sites' => 1, 'feeds' => "a customer's lifetime spend in this store",
        ],
    ];

    /**
     * The spellings that aggregate an order's money.
     *
     * ENUMERATED AS A PATTERN OVER `final_price`, which is the column every one of them sums.
     * CLAUDE.md §3's rule says a list of dangerous spellings is always short by one, and the safe
     * set here is not enumerable — "every expression that does not sum an order" is unbounded. So
     * the scope is narrowed instead: the census is over ONE COLUMN NAME inside an aggregate, which
     * makes the question decidable, and the test asserts the pattern is not vacuous in both
     * directions.
     */
    private const AGGREGATES_ORDER_MONEY = '/(?:SUM\(\s*`?(?:orders\.)?final_price`?\s*\)|sum\(\s*[\'"]final_price[\'"]\s*\))/i';

    /**
     * @return array<string, list<string>> "file::method()" => the matched lines, over app/ with
     *                                     comments stripped
     */
    private function aggregationSites(): array
    {
        $found = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = ltrim(str_replace(base_path(), '', $file->getPathname()), '/');

            /*
             * `app/Support/Metrics/` IS EXCLUDED, and that is the census's whole shape: the metric
             * layer's four accessors ARE a chokepoint, so one change there covers all of it. This
             * measures what that change would NOT cover.
             */
            if (str_starts_with($relative, 'app/Support/Metrics/')) {
                continue;
            }

            $lines = explode("\n", $this->sourceWithoutComments($relative));

            foreach ($lines as $index => $line) {
                if (! preg_match(self::AGGREGATES_ORDER_MONEY, $line)) {
                    continue;
                }

                $found[$relative . '::' . $this->enclosingMethod($lines, $index) . '()'][] = trim($line);
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * The name of the method a matched line sits in, by scanning BACK to the nearest named
     * `function`.
     *
     * NOT A PHP PARSER, and the two ways that matters are both deliberate. A CLOSURE cannot be
     * mistaken for the enclosing method: `function (` and `fn (` have no name, so the pattern
     * requires a word character after `function` and scans past them to the real declaration.
     * And a line that sits in no method at all answers `?`, which is a key no declaration below
     * carries, so it FAILS loudly rather than being silently attributed to the method above the
     * class.
     *
     * @param  list<string>  $lines
     */
    private function enclosingMethod(array $lines, int $index): string
    {
        for ($i = $index; $i >= 0; $i--) {
            if (preg_match('/function\s+(\w+)\s*\(/', $lines[$i], $m) === 1) {
                return $m[1];
            }
        }

        return '?';
    }

    /**
     * FACT 1 — there is no historical-order import, so DATA-007 is vacuously satisfied today.
     *
     * THE DAY THIS FAILS IS THE DAY IT MATTERS. If an order importer appears, this test fails and
     * the failure message is the handover: it names the other two facts and the exclusion cost.
     */
    public function test_no_historical_order_import_exists_so_nothing_can_be_counted_twice_yet(): void
    {
        $importers = [];

        foreach (glob(base_path('packages/workdo/*' . '/src/app/Imports/*.php')) ?: [] as $path) {
            $importers[] = basename($path);
        }

        sort($importers);

        $this->assertSame([
            'BrandImportData.php',
            'CityImportData.php',
            'CountryImportData.php',
            'LabelImportData.php',
            'MaincategoryImportData.php',
            'ProductsImportData.php',
            'StateImportData.php',
            'SubcategoryImportData.php',
        ], $importers,
            "AN IMPORTER HAS BEEN ADDED OR REMOVED. If it imports ORDERS, DATA-007 stops being "
            . "vacuous and becomes load-bearing: historical orders must not count as new sales, and "
            . "this file's other two tests say what that costs -- the Metric accessors are NOT the "
            . 'only door, and the merchant dashboard, the reports and the shipped Flutter app each '
            . 'sum order money themselves.');

        /*
         * AND NO COLUMN ON `orders` MARKS ONE EITHER, so there is no half-built mechanism somebody
         * might believe is already doing the job. Through `Schema::hasColumn()` against the live
         * migrated schema, because CLAUDE.md records that the migrations are not the schema.
         */
        foreach (['is_historical', 'is_historical_import', 'imported_at', 'import_source'] as $column) {
            $this->assertFalse(Schema::hasColumn('orders', $column),
                "`orders.{$column}` exists, so a historical-order marker has been added. Every site "
                . 'in AGGREGATION_SITES must now exclude it, not just the metric layer.');
        }
    }

    /**
     * FACT 2 — the gate that stops a future importer landing silently is real, and still shut.
     *
     * This is the one piece of DATA-007 that is already BUILT, by Phase 02's P2-04-11, and it was
     * never traced to the decision. It is asserted here rather than described, because a dependency
     * on another test's behaviour is worth failing on.
     */
    public function test_the_order_source_gate_would_catch_a_historical_importer_before_it_shipped(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'order_source'),
            'the column DATA-007 would hang off does not exist in this schema, so the gate below is '
            . 'vacuous');

        /*
         * NO DEFAULT is the load-bearing property: an unstamped writer must leave NULL rather than
         * silently claim a provenance. Measured off the migration, which is the only place the DDL
         * is stated, and paired with the live-schema check above.
         */
        $migration = $this->sourceWithoutComments(
            'database/migrations/2026_10_28_000001_add_order_source_to_orders_table.php'
        );

        $this->assertMatchesRegularExpression('/string\(\s*[\'"]order_source[\'"]\s*,\s*20\s*\)\s*->nullable\(\)/', $migration,
            'order_source is no longer a nullable string(20) with no default. A DEFAULT would make '
            . 'an unstamped historical importer claim a real provenance instead of reading as '
            . 'unrecorded.');
        $this->assertStringNotContainsString('->default(', $migration,
            'the order_source migration now sets a default, which is the fabrication its own '
            . "approval forbids and would let a historical import inherit a sales-counted source.");

        /*
         * THE VOCABULARY HAS NO ENTRY FOR A HISTORICAL IMPORT -- today. So an importer must either
         * add one (and be seen) or stamp nothing (and fail the writer census). Both doors are shut,
         * which is the property DATA-007 needs and gets for free from P2-04-11.
         */
        $this->assertSame(['pos', 'manual', 'storefront'], Order::SOURCES,
            'the order_source vocabulary has changed. If a historical-import value was added, '
            . 'DATA-007 is now live and every AGGREGATION_SITES entry must exclude it.');

        $this->assertNull(Order::orderSourceLabel('historical_import'),
            'an unrecognised source now has a merchant-facing label, so a historical import could '
            . 'be presented as an ordinary order');

        $census = $this->sourceWithoutComments('tests/Unit/Database/OrderSourceWriterCensusTest.php');

        $this->assertMatchesRegularExpression('/MEASURED_WRITERS\s*=\s*\d+/', $census,
            'OrderSourceWriterCensusTest no longer asserts an exact writer count, so a new order '
            . 'writer -- a historical importer among them -- could be added without being seen. '
            . 'That census is the gate this decision leans on.');
    }

    /**
     * FACT 3 — THE FINDING. The metric layer is a chokepoint; it is not the only door.
     *
     * Asserted as a set in both directions: a new place that sums order money fails here, and a
     * declared site that stops summing fails here too, so the cost estimate cannot rot.
     */
    public function test_the_metric_layer_is_not_the_only_place_order_money_is_summed(): void
    {
        $sites = $this->aggregationSites();

        /*
         * THE METHOD SET AND THE COUNT PER METHOD, IN ONE TWO-DIRECTIONAL COMPARISON. A new method
         * appears as a new key; a new aggregation inside an existing method moves its count. The
         * matched lines go in the MESSAGE rather than the key, so re-keying on the method (see
         * `AGGREGATION_SITES`) cost a reader nothing: they still get the code, and the key no
         * longer goes stale when 62 lines are inserted above it.
         */
        $measured = array_map('count', $sites);
        $declared = array_map(fn (array $entry) => $entry['sites'], self::AGGREGATION_SITES);

        $lines = [];

        foreach ($sites as $key => $matched) {
            foreach ($matched as $line) {
                $lines[] = $key . '  ' . $line;
            }
        }

        $this->assertSame($declared, $measured,
            "the set of order-money aggregations outside app/Support/Metrics/ has changed. These\n"
            . "are the sites a historical-order exclusion must reach IN ADDITION to the Metric\n"
            . "accessors; add the new one with what its figure feeds, or remove the stale entry.\n"
            . "What the sweep found:\n  " . implode("\n  ", $lines));

        $this->assertGreaterThan(1, array_sum($measured),
            'if this ever reaches zero the metric layer really IS the only door and DATA-007 '
            . 'becomes a one-place change -- which would be good news worth noticing rather than '
            . 'a test to delete.');

        /*
         * WHAT THIS STILL CANNOT SEE, stated rather than implied: REPLACING one aggregation with a
         * different one inside the same method keeps the count and passes. That is not a
         * regression from the `file:line` key this replaced -- that version's `assertSame` compared
         * KEYS only, so a changed expression on an unchanged line passed it too. Catching it would
         * mean declaring the expressions, which is the 130-character key `AGGREGATION_SITES`
         * explains and rejects. What matters for DATA-007 is that no site is ADDED unnoticed, and
         * both keys catch that.
         */
    }

    /**
     * THE PREMISE THAT THE PATTERN IS NOT VACUOUS, in both directions.
     *
     * A census over a regex needs this: a pattern that matched nothing would make Fact 3 read as
     * "the metric layer is the only door", which is the comfortable and wrong answer.
     */
    public function test_the_premise_that_the_aggregation_pattern_matches_what_it_claims(): void
    {
        foreach ([
            "->select(\\DB::raw('SUM(final_price) as total_price'))",
            "return \$this->ownStoreOrders()->sum('final_price');",
            '->selectRaw("SUM(orders.final_price) as revenue")',
            '->selectRaw("SUM(`final_price`)")',
        ] as $spelling) {
            $this->assertMatchesRegularExpression(self::AGGREGATES_ORDER_MONEY, $spelling, $spelling);
        }

        foreach ([
            "\$order->final_price = 10;",
            "->where('final_price', '>', 0)",
            "'final_price' => \$total,",
            "->sum('delivery_price')",
        ] as $spelling) {
            $this->assertDoesNotMatchRegularExpression(self::AGGREGATES_ORDER_MONEY, $spelling, $spelling);
        }
    }

    /**
     * AND THE EXCLUSION, WHEREVER IT LANDS, HAS A COLUMN TO KEY ON that really discriminates.
     *
     * Driven rather than asserted: two orders in one store, one stamped `storefront` and one left
     * NULL, and a `whereNotIn('order_source', [...])` predicate separates them. This is the shape a
     * future exclusion will take, proved to work against the real schema before anybody relies on
     * it — and it also proves the NULL case, which is the one that bites: in SQL,
     * `order_source NOT IN ('historical_import')` is UNKNOWN for a NULL row and EXCLUDES it, so a
     * naive exclusion predicate would silently drop every pre-2026-10-28 legacy order from sales.
     */
    public function test_an_exclusion_predicate_on_order_source_must_be_null_safe_or_it_drops_every_legacy_order(): void
    {
        [, $store] = $this->makeTenant();

        $stamped = $this->makeOrder($store, ['order_source' => Order::SOURCE_STOREFRONT]);
        $legacy = $this->makeOrder($store, ['order_source' => null]);

        $this->assertNotNull($stamped->id);
        $this->assertNull(Order::withoutGlobalScopes()->whereKey($legacy->id)->value('order_source'),
            'the fixture could not create an order with no source, so the NULL case below is not '
            . 'being measured');

        $base = fn () => \Illuminate\Support\Facades\DB::table('orders')->where('store_id', $store->id);

        /*
         * THE NAIVE PREDICATE. It keeps the stamped order and LOSES the legacy one -- not because
         * the legacy order is historical, but because NULL is not comparable. Measured, not
         * reasoned about, and this is the defect a future DATA-007 change is most likely to ship.
         */
        $naive = $base()->whereNotIn('order_source', ['historical_import'])->pluck('id')->all();

        $this->assertSame([$stamped->id], array_map('intval', $naive),
            'a plain whereNotIn no longer drops the NULL-source order. If the engine or the column '
            . 'changed, the null-safe form below may no longer be necessary -- check before '
            . 'simplifying any exclusion that relies on this.');

        /*
         * THE NULL-SAFE FORM, which is what DATA-007 actually needs: exclude the historical source
         * and KEEP everything whose source is unrecorded, because a legacy order is a real sale.
         */
        $safe = $base()
            ->where(function ($query) {
                $query->whereNull('order_source')
                    ->orWhereNotIn('order_source', ['historical_import']);
            })
            ->pluck('id')
            ->all();

        sort($safe);
        $expected = [$stamped->id, $legacy->id];
        sort($expected);

        $this->assertSame($expected, array_map('intval', $safe),
            'the null-safe exclusion does not keep both a stamped and a legacy order, so it is not '
            . 'the right shape for DATA-007 either');
    }
}
