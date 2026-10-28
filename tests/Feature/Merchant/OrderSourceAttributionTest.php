<?php

namespace Tests\Feature\Merchant;

use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-11 / WS E8 -- `orders.order_source`: WHICH PATH CREATED THE ORDER.
 * ============================================================================================
 *
 * The row's acceptance is that a manually entered order is DISTINGUISHABLE from a storefront
 * order in the list and on the detail page. Three things have to be true for that, and each one
 * is a separate way the slice could look finished and not be:
 *
 *   1. THE WRITERS RECORD IT. Not the UI, not a later inference -- the handler that creates the
 *      order. There are SIXTEEN host-`Order` writers in `app/` and a first census of them
 *      returned eleven, because the grep's output was truncated at forty lines and five gateway
 *      controllers fell off the end. `OrderSourceWriterCensusTest` is the ratchet that keeps
 *      that from happening silently again; the three tests here drive the three writers a
 *      merchant actually reaches and assert the stored value.
 *
 *   2. THE SCREEN SHOWS IT HONESTLY. Every order written before the column existed carries NULL.
 *      The one failure mode that matters is presenting those as STOREFRONT orders -- a claim
 *      nobody established, about a commercial record. Two tests below assert the negative
 *      directly (the list cell and the detail row), because a default of 'storefront' in the
 *      migration or a `?? Order::SOURCE_STOREFRONT` in a view would pass every positive test in
 *      this file and fail only these.
 *
 *   3. THE FILTER CANNOT LEAK OR WIDEN. It is a merchant-facing predicate on a column that is
 *      NOT tenant-scoped by the framework (`Order` is absent from `config/tenancy.php`'s
 *      `scoped_models`, so `Order::find()` adds no predicate for ANY principal), and an
 *      unrecognised value must add no predicate at all rather than matching everything.
 *
 * PERMISSIONS. Both screens require `Manage Order` / `Show Order`, and the detail page is
 * additionally behind `OrderDocumentAccessGuard`. `setUp()` grants them, or every assertion
 * below would hold against a redirect to the dashboard and prove nothing.
 */
class OrderSourceAttributionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private User $merchant;
    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->merchant = $this->makeMerchant();
        $this->store = $this->makeStore($this->merchant, ['default_language' => 'en']);
        $this->merchant->current_store = $this->store->id;
        $this->merchant->save();

        $this->grantPermission(User::find($this->merchant->id), 'Manage Order');
        $this->grantPermission(User::find($this->merchant->id), 'Show Order');

        $this->merchant = User::find($this->merchant->id);
    }

    // =======================================================================================
    //  THE PREMISE
    // =======================================================================================

    /**
     * Without this, every assertion in the file could be answering against a table that has no
     * such column -- sqlite would reject the insert and the test would error rather than lie,
     * but the *absence* assertions would pass for the wrong reason on a partial schema.
     */
    public function test_the_column_exists_and_holds_no_default(): void
    {
        $this->assertTrue(Schema::hasColumn('orders', 'order_source'),
            'premise: the migration has run');

        $column = collect(Schema::getColumns('orders'))->firstWhere('name', 'order_source');

        $this->assertNotNull($column);
        $this->assertTrue($column['nullable'], 'an order with no recorded source must be legal');
        $this->assertNull($column['default'],
            'A DEFAULT IS THE DEFECT. With one, every writer this change failed to reach would '
            . 'silently CLAIM a provenance nobody established -- and so would every row inserted '
            . 'by a path added later. The owner\'s approval requires old rows to stay '
            . 'unattributed; no default is that requirement expressed in DDL.');
    }

    // =======================================================================================
    //  1. THE WRITERS
    // =======================================================================================

    /**
     * The merchant web order form. Driven through the ROUTE, not by calling the controller, so
     * the validator, the permission gate and the mass-assignment path are all in play -- the
     * last of which is the real risk here: `order_source` is passed to `Order::create()` by key,
     * and Laravel SILENTLY DISCARDS an unfillable key. That is the trap `created_by` fell into
     * on this very handler, where the column existed, the controller passed it, and the value
     * never landed.
     */
    public function test_the_merchant_order_form_records_a_manual_source(): void
    {
        // BOTH SIDES SCOPED TO THE SAME STORE. This compared an UNSCOPED `count()` against a
        // store-scoped collection, which held only because the fixture has one store -- so it
        // would have started lying the moment a second store appeared in setUp().
        $before = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->count();

        $this->actingAs($this->merchant)->post('order', $this->validOrderPayload());

        $orders = Order::withoutGlobalScopes()->where('store_id', $this->store->id)->get();

        $this->assertCount($before + 1, $orders, 'premise: the form actually wrote an order');
        $this->assertSame(Order::SOURCE_MANUAL, $orders->first()->order_source,
            'the web order form is a merchant typing an order, which is what `manual` records');
    }

    /**
     * The admin app's order writer, `POST api/admin/createOrderViaApi`. The SAME source as the
     * web form on purpose: the column records the PATH that created the order, not the device
     * that reached it. A separate `manual_app` value would answer a question no merchant asks on
     * this screen and would double the vocabulary the filter and the labels have to carry.
     */
    public function test_the_admin_app_order_writer_records_a_manual_source(): void
    {
        $payload = $this->validOrderPayload();

        $token = $this->merchant->createToken('t')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/createOrderViaApi?store_id=' . $this->store->id, [
                'customer_id' => $payload['customer_id'],
                'products' => [[
                    'product_id' => $payload['products'][0]['product_id'],
                    'quantity' => 1,
                    'price' => 10,
                ]],
                'payment_type' => 'cod',
                'payment_status' => 'Unpaid',
                'first_name' => 'A', 'last_name' => 'B',
                'email' => 'a@example.test', 'telephone' => '1',
                'address' => 'x',
            ]);

        // 201, which is this endpoint's own success code -- NOT the `{status: 0}`-at-HTTP-200
        // shape `ApiResponser::error()` uses elsewhere in this controller. Asserted exactly,
        // because `assertNotSame(422, ...)` here would also be satisfied by a 500.
        $this->assertSame(201, $response->status(),
            'premise: the admin api accepted the order. Body: ' . $response->getContent());

        $order = Order::withoutGlobalScopes()->where('store_id', $this->store->id)
            ->orderByDesc('id')->first();

        $this->assertNotNull($order, 'premise: the api wrote an order');
        $this->assertSame(Order::SOURCE_MANUAL, $order->order_source);
    }

    /**
     * THE VOCABULARY ONLY -- AND THE NAME THIS TEST USED TO HAVE WAS A LIE ABOUT ITS SCOPE.
     *
     * It was called "the till and the form are not the same source", which reads as a statement
     * about `PosController`. It is not: it compares three constants to each other and never
     * drives the till, so **deleting the till's stamp left it green**. Found by an independent
     * audit of this file, not by the test failing.
     *
     * What actually guards the till's stamp is
     * `OrderSourceWriterCensusTest::test_every_writer_stamps_the_constant_its_path_warrants`,
     * added for this reason: it reads each writer's own source and requires POS from the POS
     * controller, MANUAL from the two merchant-manual writers and STOREFRONT from the rest.
     * Bitten by stamping the till STOREFRONT, which turns it red.
     *
     * This test keeps a narrow and honest job: the three values are distinct and in the order the
     * filter offers them.
     */
    public function test_the_three_sources_are_distinct_values_in_a_fixed_order(): void
    {
        $this->assertNotSame(Order::SOURCE_POS, Order::SOURCE_MANUAL);
        $this->assertNotSame(Order::SOURCE_POS, Order::SOURCE_STOREFRONT);
        $this->assertNotSame(Order::SOURCE_MANUAL, Order::SOURCE_STOREFRONT);

        $this->assertSame(
            ['pos', 'manual', 'storefront'],
            Order::SOURCES,
            'the vocabulary the filter, the bar and the labels all read from'
        );
    }

    // =======================================================================================
    //  2. THE SCREEN
    // =======================================================================================

    /**
     * THE ONE THAT CATCHES A FABRICATED DEFAULT. A legacy row is written with the column left
     * alone, exactly as the migration leaves every pre-existing row, and the list must not name
     * a source for it.
     *
     * Asserted on the RENDERED CELL, not on the model: a view that reached for
     * `?? Order::SOURCE_STOREFRONT` would leave the column NULL and still put the word on screen.
     */
    public function test_a_legacy_order_is_not_presented_as_a_storefront_order(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'LEGACY-1']);

        // Written by raw query so no model hook or $attributes default can reach the column --
        // this row is as close to a pre-migration row as a test can build.
        DB::table('orders')->where('id', $order->id)->update(['order_source' => null]);
        // `Order` is Cachable. A raw write leaves Eloquent reads serving the PRE-WRITE row, and
        // under CACHE_STORE=array that stale entry lives for the rest of the test -- two measured
        // false readings in this project came from exactly this. Safe as written (each raw write
        // precedes the first read of its row and the makeOrder() above flushes), but one line
        // makes it immune to a later edit that reads first.
        (new Order)->flushCache();

        $cell = $this->sourceCellFor($order->id);

        $this->assertStringNotContainsString('Storefront', $cell,
            'AN ABSENCE OF EVIDENCE IS NOT A STOREFRONT ORDER. This row predates the column; '
            . 'naming a source for it is a claim about a commercial record that nobody made.');
        $this->assertStringNotContainsString('Point of sale', $cell);
        $this->assertStringNotContainsString('Entered manually', $cell);
        $this->assertStringContainsString('was not recorded', $cell,
            'and it must say the source is missing, rather than leaving a bare gap');
        $this->assertStringNotContainsString('before the source was recorded', $cell,
            'WITHOUT INFERRING THE ORDER\'S AGE -- see '
            . 'test_a_recent_order_with_no_source_is_not_described_as_old');
    }

    #[DataProvider('sourceLabelProvider')]
    public function test_a_recorded_source_is_named_in_the_list(string $source, string $label): void
    {
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'SRC-' . $source,
            'order_source' => $source,
        ]);

        $this->assertSame($source, Order::withoutGlobalScopes()->find($order->id)->order_source,
            'premise: the fixture stored the source');

        $cell = $this->sourceCellFor($order->id);

        $this->assertStringContainsString($label, $cell);
        $this->assertStringContainsString('ss-badge', $cell,
            'a recorded source renders as a badge; an unrecorded one renders as a dash, so the '
            . 'two differ in KIND and not only in colour');
    }

    public static function sourceLabelProvider(): array
    {
        return [
            'till' => [Order::SOURCE_POS, 'Point of sale'],
            'typed' => [Order::SOURCE_MANUAL, 'Entered manually'],
            'storefront' => [Order::SOURCE_STOREFRONT, 'Storefront'],
        ];
    }

    /**
     * OWNER INSTRUCTION, 2026-10-28 (§2): an order with no recorded source must NOT be presented
     * as having been created before the column existed. That is an inference about the record's
     * AGE, and it is false for the case F148 identified -- `packages/workdo/LotteryPrizes` writes
     * real orders that the host may not edit and therefore cannot stamp, so an order created this
     * morning carries NULL and the old wording called it older than a column it is younger than.
     *
     * THIS TEST STANDS IN FOR THAT WRITER rather than invoking it: the module needs an
     * `add_on_managers` row and a plan entitlement to load at all (CLAUDE.md: no WorkDo module
     * loads in any test run unless the test inserts that row), and what is under test here is the
     * HOST's presentation of an unstamped row, which is identical however the row arrived. The
     * census test is what keeps the writer itself declared.
     */
    public function test_a_recent_order_with_no_source_is_not_described_as_old(): void
    {
        // Created NOW, with no source -- exactly the shape a LotteryPrizes order has.
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'RECENT-1',
            'order_date' => now(),
        ]);

        DB::table('orders')->where('id', $order->id)->update(['order_source' => null]);
        (new Order)->flushCache();

        $cell = $this->sourceCellFor($order->id);
        $detailRow = $this->sourceRowOf($this->detailHtml($order->id));

        /*
         * SCOPED TO THE TWO PLACES THIS SLICE RENDERS, not to the whole document. The first
         * version asserted the word "older" was absent from the entire detail page -- 1498 lines
         * of it -- and failed on `placeholder`. Asserting a vocabulary against a whole page
         * answers a question about the page, not about the row under test; this project has
         * recorded that mistake before and this is one more.
         */
        foreach ([['list cell', $cell], ['detail Source row', $detailRow]] as [$where, $html]) {
            $this->assertStringNotContainsString('before the source was recorded', $html,
                "the {$where} claims this order predates the column. It was created in this test, "
                . 'so that is a false statement about a commercial record -- and the orders this '
                . 'really affects (F148) are created today by a writer the host may not edit.');

            foreach (['older', 'legacy', 'predates', 'historic'] as $ageWord) {
                $this->assertStringNotContainsString($ageWord, strtolower($html),
                    "the {$where} describes the order's AGE. The absence of a source says "
                    . 'nothing about when the order was created.');
            }
        }

        $this->assertStringContainsString('was not recorded', $cell,
            'it states the fact: the source is not recorded. Nothing about when.');
        $this->assertStringContainsString('Not recorded', $detailRow);
    }

    /**
     * FILTER COMPATIBILITY for an unrecorded order, asserted in both directions: it is reachable
     * on the unfiltered list, and NO source filter claims it. An unstamped writer's orders must
     * not become invisible to the merchant who has to work them.
     */
    public function test_an_unrecorded_order_is_listed_but_claimed_by_no_source_filter(): void
    {
        $unrecorded = $this->makeOrder($this->store, ['product_order_id' => 'UNREC-1']);
        DB::table('orders')->where('id', $unrecorded->id)->update(['order_source' => null]);
        (new Order)->flushCache();

        $this->assertContains($unrecorded->id, $this->dataTableRows([]),
            'THE MERCHANT MUST STILL SEE IT. An order whose source nobody recorded is still an '
            . 'order; a provenance column that hides orders would be worse than no column.');

        foreach (Order::SOURCES as $source) {
            $this->assertNotContains($unrecorded->id, $this->dataTableRows(['ss_source' => $source]),
                "`{$source}` must not claim an order whose source is unknown -- that is the "
                . 'fabrication the whole column is shaped to avoid');
        }
    }

    public function test_the_detail_page_names_a_recorded_source(): void
    {
        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'DET-1',
            'order_source' => Order::SOURCE_MANUAL,
        ]);

        $body = $this->detailHtml($order->id);

        $this->assertStringContainsString('Entered manually', $body);
    }

    /**
     * THE PLATFORM OPERATOR MUST SEE THE TRUTH, and the first version of this slice lied to them.
     *
     * `OrderDocumentAccessGuard` admits the operator to ANY store's order -- its operator limb
     * returns before any store comparison -- but `order_view` read the source with
     * `where('store_id', getCurrentStore())`, which for an operator is NOT the order's store. The
     * read fell to null and the Source row stated in words that the order "was created before the
     * source was recorded", about an order created in this test. That is the same fabrication the
     * whole slice exists to prevent, pointing the other way; found by independent review, not by
     * any test here.
     *
     * The fix removed the predicate: row ownership on these URIs belongs to the guard, which this
     * controller's own `mayUseOrderScreen()` docblock already states, and which `shippinglabel()`
     * and `order_receipt()` already follow for the same reason. Reverting it turns this red.
     */
    public function test_the_platform_operator_is_told_the_real_source(): void
    {
        $operator = $this->makePlatformAdmin();

        // A DIFFERENT store from the order's, which is the whole point: an operator whose
        // current_store happened to match would pass under the broken predicate too.
        $elsewhere = $this->makeStore($operator);
        $operator->current_store = $elsewhere->id;
        $operator->save();
        $operator = User::find($operator->id);

        $order = $this->makeOrder($this->store, [
            'product_order_id' => 'OP-1',
            'order_source' => Order::SOURCE_POS,
        ]);

        $this->assertNotSame((int) $elsewhere->id, (int) $this->store->id,
            'premise: the operator is looking at an order outside their own current store');

        $response = $this->actingAs($operator)->get('/order-view/' . Crypt::encrypt($order->id));

        $this->assertSame(200, $response->status(),
            'premise: the guard admitted the operator to another store\'s order, which is the '
            . 'behaviour this test depends on. Body: ' . substr($response->getContent(), 0, 200));

        $body = $response->getContent();

        $this->assertStringContainsString('Point of sale', $body,
            'the operator is told the source that was actually recorded');
        $this->assertStringNotContainsString('was not recorded', $body,
            'AND NOT TOLD A FALSEHOOD about an order created moments ago');
    }

    public function test_the_detail_page_of_a_legacy_order_invents_no_source(): void
    {
        $order = $this->makeOrder($this->store, ['product_order_id' => 'DET-2']);
        DB::table('orders')->where('id', $order->id)->update(['order_source' => null]);
        // `Order` is Cachable. A raw write leaves Eloquent reads serving the PRE-WRITE row, and
        // under CACHE_STORE=array that stale entry lives for the rest of the test -- two measured
        // false readings in this project came from exactly this. Safe as written (each raw write
        // precedes the first read of its row and the makeOrder() above flushes), but one line
        // makes it immune to a later edit that reads first.
        (new Order)->flushCache();

        $body = $this->detailHtml($order->id);

        $this->assertStringContainsString('Not recorded', $body,
            'the detail page has the room to say so in words rather than showing a dash');
        $this->assertStringNotContainsString('Entered manually', $body);

        // 'Storefront' can legitimately appear elsewhere on this page, so the assertion is
        // scoped to the row the slice added rather than to the whole document.
        $this->assertStringNotContainsString(
            '<b>Storefront</b>',
            $body,
            'the Source row must not name a provenance for an order that carries none'
        );
    }

    // =======================================================================================
    //  3. THE FILTER
    // =======================================================================================

    public function test_the_source_filter_narrows_to_one_source(): void
    {
        $pos = $this->makeOrder($this->store, ['product_order_id' => 'F-1', 'order_source' => Order::SOURCE_POS]);
        $manual = $this->makeOrder($this->store, ['product_order_id' => 'F-2', 'order_source' => Order::SOURCE_MANUAL]);
        $legacy = $this->makeOrder($this->store, ['product_order_id' => 'F-3']);
        DB::table('orders')->where('id', $legacy->id)->update(['order_source' => null]);
        // `Order` is Cachable. A raw write leaves Eloquent reads serving the PRE-WRITE row, and
        // under CACHE_STORE=array that stale entry lives for the rest of the test -- two measured
        // false readings in this project came from exactly this. Safe as written (each raw write
        // precedes the first read of its row and the makeOrder() above flushes), but one line
        // makes it immune to a later edit that reads first.
        (new Order)->flushCache();

        $unfiltered = $this->dataTableRows([]);

        $this->assertContains($pos->id, $unfiltered, 'premise: all three are on the screen');
        $this->assertContains($manual->id, $unfiltered);
        $this->assertContains($legacy->id, $unfiltered);

        $filtered = $this->dataTableRows(['ss_source' => Order::SOURCE_POS]);

        $this->assertSame([$pos->id], $filtered,
            'the till filter shows the till order and nothing else -- not the typed order, and '
            . 'not the legacy row, whose source is unknown rather than "not pos"');
    }

    /**
     * `Order` IS NOT IN `scoped_models`, so nothing in the framework stops this query reaching
     * another tenant. The list's own `where('store_id', getCurrentStore())` is the only predicate,
     * and this asserts the filter cannot be the thing that gets round it.
     *
     * READ IT FOR WHAT IT PINS, WHICH IS NOT THIS SLICE'S WORK. The predicate it exercises is
     * `OrderDataTable::query()`'s pre-existing `where('store_id', …)`; the filter adds only an
     * AND. It is worth having -- a filter that reached past the tenant predicate is exactly the
     * defect class CLAUDE.md calls this codebase's most productive -- but it is NOT evidence that
     * P2-04-11 added an isolation control, and an independent audit was right to say so.
     */
    public function test_the_source_filter_cannot_reach_another_stores_orders(): void
    {
        $otherMerchant = $this->makeMerchant();
        $otherStore = $this->makeStore($otherMerchant);
        $foreign = $this->makeOrder($otherStore, [
            'product_order_id' => 'FOREIGN-1',
            'order_source' => Order::SOURCE_POS,
        ]);

        $this->assertSame(Order::SOURCE_POS,
            Order::withoutGlobalScopes()->find($foreign->id)->order_source,
            'premise: the foreign order really does carry the source being filtered for');

        $rows = $this->dataTableRows(['ss_source' => Order::SOURCE_POS]);

        $this->assertNotContains($foreign->id, $rows);
    }

    /**
     * A filter can only ever NARROW. An unrecognised value must add no predicate -- not match
     * everything, and not match nothing: the list is unchanged.
     */
    public function test_an_unrecognised_source_value_adds_no_predicate(): void
    {
        $a = $this->makeOrder($this->store, ['product_order_id' => 'U-1', 'order_source' => Order::SOURCE_POS]);
        $b = $this->makeOrder($this->store, ['product_order_id' => 'U-2']);
        DB::table('orders')->where('id', $b->id)->update(['order_source' => null]);
        // `Order` is Cachable. A raw write leaves Eloquent reads serving the PRE-WRITE row, and
        // under CACHE_STORE=array that stale entry lives for the rest of the test -- two measured
        // false readings in this project came from exactly this. Safe as written (each raw write
        // precedes the first read of its row and the makeOrder() above flushes), but one line
        // makes it immune to a later edit that reads first.
        (new Order)->flushCache();

        $baseline = $this->dataTableRows([]);

        /*
         * `'pos '` IS NOT IN THIS LIST, AND THAT IS NOT AN OVERSIGHT -- it was here first and
         * this test failed on it. `Illuminate\Foundation\Http\Middleware\TrimStrings` is in the
         * global stack, so a trailing space is gone before `applyFilters()` sees the value and
         * `?ss_source=pos%20` is genuinely the `pos` filter. `''` stays: the neighbouring
         * `ConvertEmptyStringsToNull` turns it into NULL, `is_string(null)` is false, and no
         * predicate is added -- which is what "Any source" has to do. Both behaviours are the
         * framework's, and `test_surrounding_whitespace_is_trimmed_before_the_filter_reads_it`
         * pins the first so nobody later "hardens" it back into a bug.
         */
        foreach (['', 'POS', 'storefront\'', '1', 'manual; drop table orders', 'pos,manual'] as $value) {
            $this->assertSame($baseline, $this->dataTableRows(['ss_source' => $value]),
                "`?ss_source={$value}` must leave the list exactly as it was");
        }

        $this->assertNotEmpty($baseline, 'premise: the comparison above is not against an empty list');
        $this->assertContains($a->id, $baseline);
        $this->assertContains($b->id, $baseline);
    }

    /**
     * SURROUNDING WHITESPACE IS TRIMMED BY THE FRAMEWORK, not by this filter.
     *
     * Pinned because it is surprising and because the obvious "fix" is wrong: `TrimStrings` is
     * global, so by the time `applyFilters()` runs there is no space to reject. Rejecting
     * `'pos '` would mean reading the RAW query string behind the framework, which would make
     * this one filter behave differently from every other control on the page.
     */
    public function test_surrounding_whitespace_is_trimmed_before_the_filter_reads_it(): void
    {
        $pos = $this->makeOrder($this->store, ['product_order_id' => 'W-1', 'order_source' => Order::SOURCE_POS]);
        $other = $this->makeOrder($this->store, ['product_order_id' => 'W-2', 'order_source' => Order::SOURCE_MANUAL]);

        $this->assertSame([$pos->id], $this->dataTableRows(['ss_source' => ' pos ']));
        $this->assertNotContains($other->id, $this->dataTableRows(['ss_source' => 'pos ']));
    }

    /** An array value must not crash the list -- `in_array()` on a non-string. */
    public function test_an_array_source_value_does_not_crash_the_list(): void
    {
        $this->makeOrder($this->store, ['product_order_id' => 'A-1', 'order_source' => Order::SOURCE_POS]);

        $response = $this->actingAs($this->merchant)->get(route('order.index', [
            'ss_source' => ['pos'], 'draw' => 1, 'start' => 0, 'length' => 10,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $this->assertSame(200, $response->status());
    }

    /**
     * The bar must offer exactly the vocabulary the query accepts. A select offering a fourth
     * option, or missing one, is a control that lies about what the screen can do.
     */
    public function test_the_filter_bar_offers_exactly_the_vocabulary_the_query_reads(): void
    {
        $body = $this->actingAs($this->merchant)->get(route('order.index'))->getContent();

        $this->assertStringContainsString('name="ss_source"', $body,
            'premise: the bar carries the parameter the DataTable reads');

        foreach (Order::SOURCES as $source) {
            $this->assertStringContainsString('value="' . $source . '"', $body);
            $this->assertStringContainsString((string) Order::orderSourceLabel($source), $body);
        }

        $this->assertStringContainsString('Any source', $body, 'and a way to clear it');

        // No option for the absence. See the view's own comment.
        $this->assertStringNotContainsString('value="unknown"', $body);
        $this->assertStringNotContainsString('value="not_recorded"', $body);
    }

    // =======================================================================================
    //  HELPERS
    // =======================================================================================

    private function detailHtml(int $orderId): string
    {
        $response = $this->actingAs($this->merchant)->get('/order-view/' . Crypt::encrypt($orderId));

        $this->assertSame(200, $response->status(),
            'premise: the detail page rendered rather than redirecting on a permission or '
            . 'ownership refusal, which would make every assertion below vacuous');

        return $response->getContent();
    }

    /** The rendered `order_source` cell for one order, taken from the list's own AJAX leg. */
    private function sourceCellFor(int $orderId): string
    {
        $payload = $this->dataTablePayload([]);

        foreach ($payload as $row) {
            if ((int) ($row['id'] ?? 0) === $orderId) {
                $this->assertArrayHasKey('order_source', $row,
                    'premise: the list renders a source cell at all');

                return (string) $row['order_source'];
            }
        }

        $this->fail("order {$orderId} is not on the list, so its cell cannot be read");
    }

    /**
     * The Source row of the detail page, anchored on its own label. Not the whole document: the
     * page is ~1500 lines and a vocabulary assertion against all of it answers a question about
     * the page rather than about this slice's row.
     */
    private function sourceRowOf(string $body): string
    {
        $start = strpos($body, __('Source'));

        $this->assertNotFalse($start, 'premise: the Source row was found on the detail page');

        $end = strpos($body, '</li>', $start);

        return substr($body, $start, $end === false ? 400 : ($end - $start));
    }

    private function dataTablePayload(array $query): array
    {
        $response = $this->actingAs($this->merchant)->get(route('order.index', $query + [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertIsArray($payload, 'premise: the DataTable answered with JSON');
        $this->assertArrayHasKey('data', $payload, 'premise: the DataTable returned rows');

        return $payload['data'];
    }

    private function dataTableRows(array $query): array
    {
        return array_values(array_filter(array_map(
            static fn ($row) => isset($row['id']) ? (int) $row['id'] : null,
            $this->dataTablePayload($query)
        )));
    }

    private function validOrderPayload(): array
    {
        $product = \App\Models\Product::forceCreate([
            'name' => 'src', 'slug' => 'src-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            'product_stock' => 9, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $this->store->id, 'created_by' => $this->store->created_by,
        ]);

        $customer = \App\Models\Customer::forceCreate([
            'first_name' => 'Src', 'last_name' => 'Buyer',
            'email' => 'src' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $this->store->id,
        ]);

        return [
            'customer_id' => $customer->id,
            'products' => [['product_id' => $product->id, 'quantity' => 1, 'price' => 10]],
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'final_price' => 10,
            'first_name' => 'Src', 'last_name' => 'Buyer',
            'email' => 'src@example.test', 'telephone' => '1',
            'address' => 'x', 'city' => 'y', 'state' => 'z', 'country' => 'q',
        ];
    }
}
