<?php

namespace Tests\Feature\Reports;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P5-R7-A — THE SALES-BY-PRODUCT REPORT ADDS FOREIGN CURRENCIES TOGETHER.
 * =================================================================================================
 *
 * ANA-TOP fixed this on the category and brand reports. `order_product_reports` is the third copy
 * of the same figure and was not in that slice's scope, so it still does it:
 *
 *     $totalNetSales += (float) ($product['final_price']);
 *
 * with no reference to `orders.currency_code` in any of its five period arms. A store that has
 * taken 25 EUR and 90 USD is shown `115.00` behind one symbol -- a number that is not a quantity
 * of either currency, presented as if it were. The owner's instruction is explicit: never sum
 * amounts in different currencies without an approved conversion basis; separate the buckets or
 * withhold the aggregate.
 *
 * THE SYMBOL IS A SECOND DEFECT, and it is a cross-tenant one. The handler opens
 * `Utility::GetValueByName('CURRENCY')` with NO store id. That helper falls through on a miss to
 * `Utility::Setting()`, which ignores its store-id argument whenever `Auth::check()` is true and
 * reads `getCurrentStore()` instead (`Utility.php:139-149`, reached from `:228-231`). For the
 * operator viewing a SELECTED TENANT that is the operator's own currency, printed against the
 * tenant's money -- which is exactly the "never silently convert an operator's selected tenant
 * values into the operator's display currency" case, in its mislabelling form.
 *
 * WHICH ARMS THIS SUITE CAN REACH, MEASURED NOT ASSUMED (2026-11-23). `last-month` and the
 * `else` date-range arm each hold `MONTH(order_date)` inside a `selectRaw`, which SQLite does not
 * implement, so under this suite they answer HTTP 500 before any of the arithmetic runs --
 * CLAUDE.md's standing warning about this controller. `this-month`, `seven-day` and `year` hold
 * ZERO MySQL-only constructs and ARE reachable. The test asserts that split as a premise, so if
 * a later change makes a reachable arm unreachable (or the reverse) this file says so rather than
 * silently testing less than it claims.
 */
class ProductReportCurrencyTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    /**
     * ALL FIVE PERIOD ARMS, because each is its own copy of the window and the bucket index.
     *
     * Two of them could not be driven before this slice: `last-month` and the date range each
     * held `MONTH(order_date)` in a `selectRaw`, which SQLite does not implement, so both
     * answered HTTP 500 under this suite before any arithmetic ran. Nothing read the column;
     * removing it is what made them reachable, and the premise test below fails if a MySQL-only
     * construct comes back and quietly takes an arm out of this provider's reach again.
     *
     * @return array<string, array{0: string}>
     */
    public static function reachablePeriods(): array
    {
        return [
            'last-month' => ['chart_data=last-month'],
            'this-month' => ['chart_data=this-month'],
            'seven-day' => ['chart_data=seven-day'],
            'year' => ['chart_data=year'],
            'date-range (else)' => [
                'chart_data=range&Date=' . urlencode(date('Y-m-01') . ' to ' . date('Y-m-d')),
            ],
        ];
    }

    /**
     * A date that genuinely falls inside the given arm's window.
     *
     * ONE FIXTURE DATE CANNOT SERVE ALL FIVE: a case whose order falls outside the window it is
     * testing passes for the wrong reason -- the figure is absent because there is no money, not
     * because the money was bucketed correctly. `seven-day` and `last-month` are the two that
     * catch this, and they want opposite dates.
     */
    private function dateInside(string $query): \Carbon\Carbon
    {
        if (str_contains($query, 'last-month')) {
            return now()->subMonthNoOverflow()->startOfMonth()->addHours(9);
        }

        if (str_contains($query, 'seven-day')) {
            return now()->startOfDay()->addHours(9);
        }

        return now()->startOfMonth()->addHours(9);
    }

    // ---------------------------------------------------------------------------------- the defect

    #[\PHPUnit\Framework\Attributes\DataProvider('reachablePeriods')]
    public function test_two_currencies_are_not_added_into_one_figure(string $query): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');

        $when = $this->dateInside($query);
        $this->orderFor($store, $product, 25.0, 'EUR', $when);
        $this->orderFor($store, $product, 90.0, 'USD', $when);

        $html = $this->chart($merchant, $query)['html'];

        $this->assertStringNotContainsString('115.00', $html,
            'the report added 25 EUR and 90 USD into one figure');

        $this->assertStringContainsString('25.00', $html,
            "the store's own currency total is missing, so the figure was withheld rather than "
            . 'bucketed');

        $this->assertStringContainsString('USD', $html,
            'the 90 USD is neither added nor disclosed, so it has silently vanished from the report');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reachablePeriods')]
    public function test_the_money_column_names_the_currency_it_is_counted_in(string $query): void
    {
        /* The chart's accessible table printed the money series through `number_format()` with
           no currency anywhere -- a column of bare numbers handed to a merchant whose store may
           trade in more than one currency, with the tile above it carrying a symbol for the same
           figure. The unit belongs in the column HEADER, which `<th scope="col">` makes a screen
           reader announce with every cell; see the note in `x-ss.bar-chart`. */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $product, 61.0, 'EUR', $this->dateInside($query));

        $html = $this->chart($merchant, $query)['html'];

        $this->assertMatchesRegularExpression(
            '/<th[^>]*scope="col"[^>]*>[^<]*\(EUR\)[^<]*<\/th>/u',
            $html,
            "the money column's header does not say which currency the column is counted in"
        );

        /* And the figure really is there, so the assertion above is about a column that has
           money in it rather than an empty table that trivially satisfies a header check. */
        $this->assertStringContainsString('61.00', $html, 'the money never reached the table');
    }

    public function test_the_export_replay_carries_the_currency_disclosure(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $product, 25.0, 'EUR');
        $this->orderFor($store, $product, 90.0, 'USD');

        /* The screen first: the export replays what the screen put in the session. */
        $this->chart($merchant);

        $response = $this->actingAs($merchant->fresh())->get('/product-order-export');

        $this->assertSame(200, $response->status(), 'the export handler did not run');
        $payload = $response->json();
        $this->assertIsArray($payload, 'the export did not return JSON');

        /* BY KEY, NOT BY SUBSTRING. The review flagged the first version of this assertion: it
           looked for `USD` anywhere in the encoded payload, which `figureCurrency` alone would
           satisfy if this fixture's store currency ever became USD -- and then
           `$return['figureCaveat']` could be deleted without the test failing. */
        $this->assertArrayHasKey('figureCaveat', $payload, 'the export carries no caveat key');
        $this->assertNotSame('', (string) $payload['figureCaveat'], 'the caveat key is empty');
        $this->assertStringContainsString('USD', (string) $payload['figureCaveat'],
            'Phase 05\'s rule is that an export carries the same caveats as the screen. This one '
            . 'hands the merchant a money column with no word about the second currency, so the '
            . 'spreadsheet is less honest than the page it came from.');
    }

    /**
     * A product id in THIS store's order that belongs to ANOTHER store is counted into our report.
     *
     * `Product` registers no `StoreScope` and is absent from `config/tenancy.php`'s
     * `scoped_models`, so nothing in this handler's chain stops a line naming a foreign product
     * from being matched: the arms compare `$product['product_id'] == $productId` where
     * `$productId` came from our own catalogue, which is safe, but the fallback that fills
     * `$selectedProducts` is the only thing doing the scoping. This pins that it keeps doing it.
     */
    public function test_a_foreign_product_in_our_order_is_not_counted(): void
    {
        [$merchant, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $theirs = $this->productIn($other, 'Lamps');

        /* One order, OURS, but naming the other tenant's product. */
        $this->orderFor($store, $theirs, 777.0, 'EUR');

        $html = $this->chart($merchant)['html'];

        $this->assertStringNotContainsString('777.00', $html,
            "a line naming another tenant's product was counted into this store's report");
    }

    public function test_a_selected_product_id_belonging_to_another_tenant_is_refused(): void
    {
        /*
         * `selectedProducts` is REQUEST INPUT and the handler loops over it. CLAUDE.md's standing
         * rule is that a predicate built from caller-supplied input is not a tenant check, so the
         * report must not count a product the caller merely named.
         *
         * THIS IS HELD BY TWO CONTROLS, AND THE PAIR IS WHAT THIS TEST PROVES -- measured, not
         * assumed. `reportSelectionWithinCurrentStore()` narrows the selection before the branch,
         * and `DimensionPeriodRevenue`'s product lookup carries its own `where('store_id', ...)`.
         * Mutating EITHER one alone leaves this test green, because the other still refuses the
         * id; mutating BOTH makes it fail and the foreign product's 555.00 appears in the report
         * (run 2026-11-23). So the two mutations are individually survivable and jointly fatal --
         * defence in depth rather than one control and one piece of dead code, which is the
         * reading a single surviving mutation would have suggested.
         */
        [$merchant, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $theirs = $this->productIn($other, 'Lamps');
        $this->orderFor($store, $theirs, 555.0, 'EUR');

        $html = $this->chart($merchant, 'chart_data=this-month&selectedProducts[]=' . $theirs->id)['html'];

        $this->assertStringNotContainsString('555.00', $html,
            'naming another tenant\'s product id in the query string got its money counted');
    }

    // ------------------------------------------------- what this file deliberately does NOT cover

    /*
     * MUTATION RESULTS, RECORDED SO THE GAPS ARE NOT REDISCOVERED AS NEWS.
     *
     * Fifteen mutations were run against this file (2026-11-23); twelve were caught. The three
     * survivors are each explained rather than papered over:
     *
     *  1. `reportSelectionWithinCurrentStore()` removed from the handler, and
     *  2. `where('store_id', ...)` removed from `DimensionPeriodRevenue`'s product lookup
     *     -- survive INDIVIDUALLY and are FATAL TOGETHER. Measured: with both gone, a foreign
     *     product's 555.00 appears in this store's report; with either present it does not.
     *     `test_a_selected_product_id_belonging_to_another_tenant_is_refused` proves the pair,
     *     which is the honest claim. A single surviving mutation here reads like dead code and
     *     this is not that.
     *
     *  3. `->flatten()` removed from the `$productIds` map survives, and no test in this file can
     *     catch it. The unflattened path is reachable only by the OPERATOR:
     *     `reportSelectionWithinCurrentStore()` already flattens for every other principal, and
     *     every case here drives a merchant. The outcome is also not a leak -- a nested
     *     `selectedProducts[][]=5` becomes product id 1, and `series(1)` reads a bucket populated
     *     only by the selected store's own orders, so the answer is wrong-but-empty rather than
     *     another tenant's. Building the operator-selection fixture for a no-leak correctness
     *     nicety was judged out of proportion; the guard is in place and this says why it is
     *     unpinned.
     */

    // ------------------------------------------------------------------------------- the premises

    public function test_every_period_arm_is_free_of_constructs_this_suite_cannot_run(): void
    {
        /*
         * THE REACHABILITY OF ALL FIVE ARMS IS THIS FILE'S LOAD-BEARING PREMISE.
         *
         * CLAUDE.md records that most of `ReportController` cannot run under this suite: 37
         * `MONTH(` and 10 `YEAR(` calls inside `selectRaw`, which SQLite does not implement, so
         * those handlers answer HTTP 500 in a Feature test. Two of this handler's five arms were
         * in that set, which is why the currency defect survived in them.
         *
         * Removing those two constructs is part of the fix, so this asserts they stay gone --
         * otherwise a later edit could reintroduce one, five data-provider cases would start
         * erroring instead of asserting, and the file would cover less than it claims.
         */
        $src = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        $start = strpos($src, 'public function order_product_reports');
        $end = strpos($src, 'public function product_order_export');

        $this->assertIsInt($start, 'order_product_reports is gone');
        $this->assertIsInt($end, 'product_order_export is gone');

        $body = substr($src, $start, $end - $start);

        $this->assertSame(0, preg_match_all('/\bMONTH\s*\(/', $body),
            'an arm uses MySQL\'s MONTH(), which SQLite has not got -- that arm now answers '
            . 'HTTP 500 under this suite and its cases in reachablePeriods() no longer test it');
        $this->assertSame(0, preg_match_all('/\bselectRaw\s*\(/', $body),
            'an arm reintroduced selectRaw; both of the ones this handler had were MySQL-only');

        /* The five arms are still five, so the provider covers the handler rather than part of
           it. Counted from the branch conditions, not from `} else {`, which the arms nest. */
        $this->assertSame(4, preg_match_all("/\\\$request->chart_data\\s*==\\s*'[a-z-]+'/", $body),
            'the handler no longer has four named period arms plus an else, so reachablePeriods() '
            . 'is stale');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reachablePeriods')]
    public function test_the_units_series_puts_each_day_s_units_in_that_day_s_bucket(string $query): void
    {
        /*
         * A TEST GAP FOUND BY MUTATION, not by reading. Emptying `quantitySeries()`'s loop so
         * every bucket stayed 0 SURVIVED the first version of this file: every assertion here was
         * about money, and the units series -- the chart's second series, and the "Items ordered"
         * tile -- was covered by nothing at all.
         *
         * Units are currency-free, so unlike the money series they are never withheld and never
         * bucketed by currency: both orders count, whatever they were priced in.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');
        $when = $this->dateInside($query);

        $this->orderFor($store, $product, 25.0, 'EUR', $when);
        $this->orderFor($store, $product, 90.0, 'USD', $when);

        $response = $this->actingAs($merchant->fresh())->get('/product-order-reports?' . $query);
        $this->assertSame(200, $response->status());

        $units = $response->json('PurchasedItemTotal');

        $this->assertIsArray($units, 'the units series is not an array');
        $this->assertSame(2, (int) array_sum($units),
            'the two orders are one unit each, so the units series must total 2 -- a foreign '
            . 'currency does not make a unit stop existing');

        /* And they are in ONE bucket, not spread or duplicated: both orders are on one day. */
        $nonZero = array_values(array_filter($units, static fn ($u) => (int) $u !== 0));
        $this->assertCount(1, $nonZero,
            'the units landed in more than one bucket, so the day mapping is wrong');
        $this->assertSame(2, (int) $nonZero[0]);
    }

    public function test_a_figure_that_cannot_be_stated_is_not_reported_as_zero(): void
    {
        /*
         * ALSO FOUND BY MUTATION. Deleting `aggregateTotal()`'s null branch so it returned
         * `0.00` SURVIVED, because no case in this file had a store whose every order is in a
         * currency other than its own -- the one situation where the two answers differ.
         *
         * They are different claims. `0.00` says nothing was ordered. The truth here is that
         * 90 USD was ordered and the figure cannot be stated in the store's currency without a
         * conversion basis this product has not got. The tile must say so rather than print a
         * zero a merchant would read as "no trade".
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $product, 90.0, 'USD');

        $html = $this->chart($merchant)['html'];

        /*
         * SCOPED TO THE `ss-stat__value` SPANS, and the first draft was not -- it asserted that
         * `>0.00<` appears nowhere in the page and FAILED against the correct fix. The chart's
         * accessible table legitimately prints `0.00` for every day with no own-currency money,
         * which is true of all of them here. The defect would be the TILE claiming zero, so the
         * tile is what the assertion may look at. CLAUDE.md's rule about scoping a search to its
         * block, met the hard way.
         */
        preg_match_all('/<span class="ss-stat__value"[^>]*>(.*?)<\/span>/s', $html, $tiles);

        $this->assertNotEmpty($tiles[1], 'no stat tiles rendered, so this proves nothing');

        $values = array_map('trim', $tiles[1]);

        $this->assertNotContains('0.00', $values,
            'a tile printed 0.00 for a store that HAS traded, just not in its own currency -- '
            . 'that is a false claim, not a missing figure');

        $this->assertContains(__('Not available'), $values,
            'the tile neither states the figure nor says it is unavailable');

        $this->assertStringContainsString('USD', $html,
            'the money that exists is not disclosed anywhere, so it has silently vanished');
    }

    // ------------------------------------------ what the independent authz/correctness review found

    public function test_money_dropped_because_the_store_no_longer_owns_the_product_is_disclosed(): void
    {
        /*
         * F3 FROM THE INDEPENDENT REVIEW, and it is the GAP-223(a) class: money inside the SQL
         * window that is silently left out of every figure.
         *
         * A line is dropped when its `product_id` is not in this store's `products` -- the
         * product was HARD-deleted (`Product` has no SoftDeletes), its `store_id` is NULL (the
         * column is nullable), or it belongs to another tenant. Excluding it is correct for a
         * per-product report. Saying nothing about it is not: the tile can be strictly less than
         * the window's order value with nothing accounting for the difference, and for the
         * seven-day and date-range arms the exclusion is NEW, because those two previously summed
         * every line of every order with no product filter at all.
         *
         * THE DISCLOSURE IS A COUNT, NOT A SUM, and that is deliberate -- dropped lines can be in
         * any mix of currencies, so a total of them is the very figure this class refuses to
         * state.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $kept = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $kept, 40.0, 'EUR');

        /* A second order naming a product that is then hard-deleted, which is what a merchant
           tidying their catalogue actually does. */
        $gone = $this->productIn($store, 'Shelves');
        $this->orderFor($store, $gone, 60.0, 'EUR');
        DB::table('products')->where('id', $gone->id)->delete();
        (new Product)->flushCache();

        $html = $this->chart($merchant)['html'];

        $this->assertStringContainsString('40.00', $html, 'the surviving product\'s money is gone too');
        $this->assertStringNotContainsString('100.00', $html,
            'premise: the deleted product\'s 60.00 must NOT be in the total');

        $this->assertMatchesRegularExpression('/1 order line\(s\)[^<]*do(es)? not own/u', $html,
            'the report left a line out of every figure and said nothing about it, so the tile is '
            . 'strictly less than the window it claims to describe with no explanation');
    }

    public function test_a_quiet_period_is_not_told_it_earned_in_another_currency(): void
    {
        /*
         * F4 FROM THE REVIEW, narrowed to the part that was actually false.
         *
         * The review observed that `aggregateTotal()` returns null both when a figure cannot be
         * stated AND when nothing was ordered at all, so a quiet week shows "Not available". I
         * tried returning 0.00 for the second case and TWO EXISTING ASSERTIONS went red --
         * `DimensionPeriodRevenueTest`'s "a dimension with no money still answers a zero series"
         * and "another tenant's product in our order is dropped, not attributed" both encode the
         * null on purpose, and the category and brand screens have shipped it. Changing the
         * number on one screen is the drift the review itself warned about, so it is unchanged.
         *
         * WHAT WAS FALSE IS THE SENTENCE. The caveat added "Nothing was earned in your store
         * currency here, so this row has no comparable total" whenever the total was unstateable
         * -- including for a store that had simply had no orders, which implies trade in some
         * other currency that never happened. It is now conditioned on money in another currency
         * actually existing.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');
        $this->productIn($store, 'Lamps');

        /* No orders at all. */
        $html = $this->chart($merchant)['html'];

        $this->assertStringNotContainsString('Nothing was earned in your store currency', $html,
            'a store with no orders was told nothing was earned in its own currency, which '
            . 'implies it earned in another one -- it did not earn at all');

        /* The shared order-value note is still there, so the tile is not left bare. Asserted on
           the RENDERED sentence, not on `MetricCaveat::FLOAT_PRECISION` -- that constant is the
           translation KEY (`'float_precision'`), which never appears in the html. My first draft
           asserted the key and failed against a correct page. */
        $this->assertStringContainsString(
            \App\Support\Reports\ReportFigureNote::orderValueHint(),
            $html,
            'the quiet-period tile lost the shared note along with the false sentence'
        );
    }

    public function test_the_export_names_the_currency_its_money_column_is_counted_in(): void
    {
        /*
         * Review area 6: the downloaded file's money column did not say what it was denominated
         * in, although the export already returned `figureCurrency` and the screen already
         * printed it in the chart's column header.
         *
         * THE REVIEW'S PROPOSED REMEDY DOES NOT WORK AND I MEASURED THAT. It suggested a term in
         * the Blade `var header = [...]` array; that array is rendered as Blade and asserted to
         * parse as JSON by `ReportCsvHeaderLocalisationTest`, which also pins its column count,
         * so a runtime value cannot live there -- putting one in took four of its cases red.
         *
         * The sentence goes in the CAVEAT instead, which both the screen's `:hint` and the
         * export's caveat column already carry and which `DimensionPeriodRevenue` builds in one
         * place -- so the product, category and brand screens and all three of their files gain
         * it together. Asserted on the export's own payload rather than on view source, because
         * what matters is that the merchant's file says it.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');

        $product = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $product, 25.0, 'EUR');

        $this->chart($merchant);

        $response = $this->actingAs($merchant->fresh())->get('/product-order-export');
        $this->assertSame(200, $response->status());

        $caveat = (string) $response->json('figureCaveat');

        $this->assertStringContainsString('EUR', $caveat,
            'the downloaded file never says which currency its money column is counted in');

        /* And the screen says the same thing, from the same source, so the two cannot drift. */
        $this->assertStringContainsString('EUR', $this->chart($merchant)['html'],
            'the screen and the file disagree about naming the currency');
    }

    public function test_an_unconfigured_store_is_not_told_a_default_code_is_its_currency(): void
    {
        /* The denomination sentence is emitted only when the store HAS configured a currency.
           An unconfigured store's code is `OrderCurrencyStamp::DEFAULT_CODE`, and naming a
           default as though the merchant had chosen it is worse than saying nothing. */
        [$merchant, $store] = $this->makeTenant();
        /* Deliberately NO storeCurrency() call. */

        $product = $this->productIn($store, 'Lamps');
        $this->orderFor($store, $product, 25.0, 'USD');

        $html = $this->chart($merchant)['html'];

        $this->assertStringNotContainsString('Amounts are counted in', $html,
            'a store that has configured no currency was told its amounts are counted in one');
    }

    public function test_an_array_valued_date_does_not_take_the_route_down(): void
    {
        /*
         * F2 FROM THE REVIEW, and a mutation caught the test gap before this existed: reverting
         * the guard to `(string) $request->Date` left every other case in this file green.
         *
         * `Date[]=x` makes `$request->Date` an ARRAY. `(string) $array` raises a PHP 8 warning,
         * which Laravel's `HandleExceptions` promotes to an `ErrorException`, so the route
         * answered 500 to any authenticated principal who could reach the report. Reading through
         * `input()` and accepting only a string is the guard the cast was mistaken for.
         */
        [$merchant, $store] = $this->makeTenant();
        $this->storeCurrency($store, 'EUR');
        $this->productIn($store, 'Lamps');

        $response = $this->actingAs($merchant->fresh())
            ->get('/product-order-reports?chart_data=range&Date[]=x&Date[]=y');

        $this->assertSame(200, $response->status(),
            'an array-valued Date took the report down (HTTP ' . $response->status() . ')');

        /* And it degrades honestly rather than inventing a window. */
        $this->assertIsArray($response->json(), 'the handler did not return the chart JSON');
    }

    public function test_all_three_csv_builders_prefer_the_currency_caveat_over_the_shared_note(): void
    {
        /*
         * THE CSV IS BUILT IN JAVASCRIPT, so no rendered-page assertion can reach it: the file is
         * assembled in the browser from the JSON the export handler returned. A source pin is the
         * only thing that can hold it, and this one covers all three builders because they
         * implement one rule.
         *
         * THE RULE: the file's note column resolves exactly as the screen's `:hint` does --
         * the per-figure caveat when there is one, else `ReportFigureNote::orderValueHint()`.
         * ANA-TOP wired that into the category and brand builders and wrote no test for it,
         * which is how this file comes to be closing a gap in its own predecessor.
         *
         * SCOPED TO THE BUILDER BLOCK, not the whole file. CLAUDE.md records a mutation that
         * survived because an assertion over a whole stylesheet answered "does this string exist
         * anywhere"; the same trap applies to a 300-line Blade file that mentions `figureNote` in
         * its prose. The slice runs from `csvContent` to the download link.
         */
        $builders = [
            'resources/views/reports/sale_by_product.blade.php' => 'figureCaveat',
            'resources/views/reports/sale_by_category.blade.php' => 'figureCaveats',
            'resources/views/reports/sale_by_brand.blade.php' => 'figureCaveats',
        ];

        foreach ($builders as $path => $key) {
            $src = $this->sourceWithoutComments($path);

            $from = strpos($src, 'var csvContent');
            $to = strpos($src, 'setAttribute("download"');

            $this->assertIsInt($from, $path . ': the CSV builder is gone');
            $this->assertIsInt($to, $path . ': the download link is gone');
            $this->assertGreaterThan($from, $to, $path . ': the builder block could not be sliced');

            $block = substr($src, $from, $to - $from);

            $this->assertStringContainsString('data.' . $key, $block,
                $path . ': the CSV builder never reads ' . $key . ', so the sentence about a '
                . 'second currency reaches the JSON and stops there -- the merchant\'s file is '
                . 'less honest than the page it came from');

            /* READING THE KEY IS NOT USING IT. The first version of this assertion stopped at the
               line above, and a mutation that kept `var caveat = data.figureCaveat` while writing
               `var rowNote = note` SURVIVED it -- the builder still mentioned the key and still
               ignored it. So the resolution itself is what must be pinned: the caveat has to be
               PREFERRED, which in both spellings means a non-empty test choosing it over the
               shared note. */
            $this->assertMatchesRegularExpression(
                '/(caveat|perRow)[^;]*!==\s*\x27\x27\s*\?[^;]*(caveat|perRow)[^;]*:[^;]*note/',
                $block,
                $path . ': the builder reads the caveat and then does not prefer it over the '
                . 'shared note, so the column it writes is the one it would have written anyway'
            );

            $this->assertStringContainsString('figureNote', $block,
                $path . ': the shared fallback note is gone, so a session stored before the '
                . 'caveat existed downloads a file with an empty note column');
        }
    }

    // ------------------------------------------------------------------------------- the fixtures

    private function storeCurrency(Store $store, string $code): void
    {
        DB::table('settings')->insert([
            'store_id' => $store->id, 'name' => 'defult_currancy', 'value' => $code,
            'created_by' => $store->created_by,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn(Store $store, string $name): Product
    {
        $categoryId = Category::create([
            'name' => $name . '-' . $store->id,
            'slug' => Str::slug($name) . '-' . $store->id,
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $store->id,
        ])->id;

        $brandId = ProductBrand::create([
            'name' => $name . 'Brand-' . $store->id,
            'slug' => Str::slug($name) . '-brand-' . $store->id,
            'status' => 1, 'store_id' => $store->id, 'created_by' => $store->created_by,
        ])->id;

        /* `forceCreate`: `brand_id` is not on `$fillable`. `track_stock` is NOT NULL. There is no
           `sub_category_id` column -- see CategoryBrandReportCurrencyTest for both measurements. */
        return Product::forceCreate([
            'name' => $name . ' product ' . $store->id,
            'slug' => Str::slug($name) . '-product-' . $store->id,
            'category_id' => $categoryId,
            'brand_id' => $brandId,
            'product_stock' => 100, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function orderFor(
        Store $store,
        Product $product,
        float $amount,
        string $currency,
        ?\Carbon\Carbon $when = null
    ): Order {
        $order = Order::create([
            'product_order_id' => 'ORD-' . strtoupper($this->uniqueFixtureRef()),
            'order_date' => $when ?? now()->startOfMonth()->addHours(9),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'name' => $product->name,
                'qty' => 1,
                'quantity' => 1,
                'price' => $amount,
                'final_price' => $amount,
            ]]),
            'product_id' => (string) $product->id,
            'product_price' => $amount,
            'coupon_price' => 0,
            'delivery_price' => 0,
            'tax_price' => 0,
            'final_price' => $amount,
            'payment_type' => 'cod',
            'payment_status' => 'Paid',
            'delivered_status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        /* `currency_code` has a model default and `Order` is `Cachable`; both measured in
           CategoryBrandReportCurrencyTest. */
        DB::table('orders')->where('id', $order->id)->update(['currency_code' => $currency]);
        (new Order)->flushCache();

        $this->assertSame($currency, DB::table('orders')->where('id', $order->id)->value('currency_code'),
            'premise failed: the order does not carry the currency this case is about');

        return $order->fresh();
    }

    /** @return array{html: string, series: mixed} */
    private function chart(User $actor, string $query = 'chart_data=this-month'): array
    {
        $response = $this->actingAs($actor->fresh())->get('/product-order-reports?' . $query);

        $this->assertNotSame(404, $response->status(), 'the route does not exist');
        $this->assertSame(200, $response->status(),
            "the handler did not run (HTTP {$response->status()})");

        $payload = $response->json();
        $this->assertIsArray($payload, 'the handler did not return the chart JSON');

        $html = (string) ($payload['html'] ?? '');
        $this->assertNotSame('', $html,
            'the chart partial rendered empty, so an "is absent" assertion proves nothing');

        return ['html' => $html, 'series' => $payload['NetSaleTotal'] ?? []];
    }
}
