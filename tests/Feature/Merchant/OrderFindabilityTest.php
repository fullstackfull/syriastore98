<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2.5-ORD-5 / ORD-10 — a merchant could not find an order on either order screen.
 * =================================================================================================
 *
 * The two confirmed findings the audit ranked straight after the notification defect, because
 * finding one order is the commonest reason a merchant opens these screens at all: a customer
 * telephones and gives their NAME, not an order number.
 *
 *  #5  THE LIST'S SEARCH BOX LIED. `grep -c filterColumn app/DataTables/OrderDataTable.php` was
 *      **0**, while five columns are `editColumn`'d onto a display value that is not the stored
 *      value: `customer_id` renders a name and mobile from an integer FK, `delivered_status` a
 *      translated badge from an int 0–8, `payment_type` a resolved gateway label from a lowercase
 *      id, `order_date` a localised date from a datetime. With no `filterColumn`, yajra compiled
 *      `orders.customer_id LIKE '%Omar%'` and `orders.delivered_status LIKE '%معلق%'`, which match
 *      nothing. The merchant typed the name printed on the row in front of them and the screen
 *      answered "no matching records". The same file proved the author knew the rule and applied
 *      it to two columns only: `shipping_method` and `order_source` carry `'searchable' => false`
 *      with a comment naming exactly this hazard. The sibling lists carry the fix this one lacked
 *      — `OrderRefundDataTable:104-130` and `AbandonCartDataTable:56-61`.
 *      It is worse in Arabic, where four of the eight columns are translated and therefore all
 *      four were unsearchable.
 *
 *  #10 THE CARD VIEW HAD NO FILTER, NO SEARCH AND NO SORT, AND IT DISCARDED THE FILTER THE
 *      MERCHANT ARRIVED WITH. `order_grid_view(Request $request)` never read `$request`; the whole
 *      query was `Order::where('store_id', $storeId)->orderBy('created_at','desc')->paginate(16)`.
 *      The two sibling view links were written differently — `index.blade.php:33` carried
 *      `route('order.kanban', request()->query())` while `:23` was a bare `route('order.grid')` —
 *      and the aggravator is `TableFilterMemory`: `/order` RESTORES a remembered filter even on a
 *      bare URL, so list → cards → list came back filtered while the cards never were, with
 *      nothing on the card screen stating its scope.
 *
 * HOW #10 IS FIXED, AND WHY IT IS NOT A SECOND FILTER IMPLEMENTATION. The ORDER BOARD already
 * solved this: `order_kanban()` builds its rows from `(new OrderDataTable())->query(new Order())`,
 * with the comment "calling it rather than re-reading the `ss_*` parameters here is what makes
 * 'the board and the list agree' a property of one implementation instead of a coincidence between
 * two". The card view now does the same. That carries the store predicate, the F149 `1 = 0`
 * refusal for a principal with no store, and every `ss_*` filter — including ones added later —
 * without this handler knowing any of their names.
 *
 * WHAT IS DELIBERATELY NOT DONE HERE. No new filter vocabulary, no new sort parameter, no change
 * to what any `ss_*` value means, and no change to the list. A filter can only narrow, which is
 * the property `applyFilters()` already documents and the reason reusing it cannot widen what a
 * merchant can see.
 */
class OrderFindabilityTest extends SecurityTestCase
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
        $this->merchant = User::find($this->merchant->id);
    }

    private function customer(string $first, string $last, string $mobile): Customer
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'first_name' => $first,
            'last_name' => $last,
            'email' => strtolower($first . '.' . $last) . '@example.test',
            'password' => bcrypt('password'),
            'mobile' => $mobile,
            'store_id' => $this->store->id,
            'created_by' => $this->merchant->id,
        ]);

        (new Customer)->flushCache();

        return $customer;
    }

    private function order(array $attributes = []): Order
    {
        return $this->makeOrder($this->store, array_merge([
            'product_order_id' => 'FIND-' . uniqid(),
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
        ], $attributes));
    }

    /**
     * The order list's columns, in order, with the `searchable` flag the real client sends.
     *
     * WRITTEN OUT RATHER THAN READ FROM `getColumns()`, AND THAT IS NOT LAZINESS -- IT IS THE ONLY
     * WAY THIS FILE CAN DRIVE THE ROUTE AT ALL. `new OrderDataTable()` resolves yajra's
     * `Utilities\Request`, which the container holds as a SINGLETON bound to whatever request was
     * current when it was first resolved. Resolving it in the test process before issuing the HTTP
     * request leaves `render()` asking a stale, empty request whether it is AJAX; it answers no,
     * and the route returns the HTML PAGE instead of the table's JSON.
     *
     * MEASURED, both ways, in one run: with no `getColumns()` call first the route answered
     * `{"draw":1,"recordsTotal":2,"recordsFiltered":1,...}`; with one, the same request answered
     * `<!DOCTYPE html>`. That is the memoised-container-binding pollution `CLAUDE.md` and the
     * `systematic-debugging` skill both name, and it cost the first version of this file sixteen
     * red tests against a fix that was already working.
     *
     * The agreement between this list and the table's own columns is asserted in
     * `test_premise_the_column_payload_matches_the_tables_own_columns()`, which issues no request
     * and so may safely touch the DataTable: Laravel builds a fresh application per test method,
     * so the pollution cannot cross from there to here.
     */
    private const COLUMNS = [
        'id' => false,
        'product_order_id' => true,
        'order_date' => true,
        'customer_id' => true,
        'final_price' => true,
        'payment_type' => true,
        'shipping_method' => false,
        'order_source' => false,
        'delivered_status' => true,
        'action' => false,
    ];

    /**
     * Drive the list the way DataTables really does.
     *
     * THE `columns` ARRAY IS NOT OPTIONAL. yajra's `Request::searchableColumnIndex()` walks
     * `columns[i][searchable]` to decide which columns the global search touches, so a request
     * carrying only `draw`, `start`, `length` and `search[value]` -- which looks like a whole
     * DataTables request -- searches NOTHING. Measured: the final SQL was
     * `select * from "orders" where "store_id" = ?` with no search predicate, and
     * `recordsFiltered` equalled `recordsTotal`, so every assertion here compared against the
     * unfiltered set and three of them passed for that reason.
     *
     * @return list<string> the `product_order_id` of every row the table returned
     */
    private function search(string $keyword): array
    {
        $columns = [];
        $index = 0;

        foreach (self::COLUMNS as $name => $searchable) {
            $columns[$index++] = [
                'data' => $name,
                'name' => $name,
                'searchable' => $searchable ? 'true' : 'false',
                'orderable' => 'true',
                'search' => ['value' => '', 'regex' => 'false'],
            ];
        }

        /*
         * THE SINGLETON IS DROPPED BEFORE EVERY SEARCH, AND WITHOUT THIS LINE THIS FILE LIES.
         *
         * `DataTablesServiceProvider` registers `datatables.request` as a SINGLETON holding a
         * `Yajra\DataTables\Utilities\Request` that captured the HTTP request current when it
         * was first resolved. Laravel keeps one application per test METHOD, not per request, so
         * the second `$this->get()` inside a method is answered using the FIRST request's search
         * value. MEASURED: searching "Partially Paid" and then "Pre Order" returned the
         * Partially-Paid order twice; searching "Delivered" and then "Cancel" returned the
         * delivered order twice. Both reads were of a stale request, and both looked like a
         * filter that matched the wrong status -- a defect report this would have produced
         * against code that was correct.
         */
        $this->app->forgetInstance('datatables.request');

        $response = $this->actingAs($this->merchant)->get('/order?' . http_build_query([
            'draw' => 1,
            'start' => 0,
            'length' => 50,
            'columns' => $columns,
            'search' => ['value' => $keyword, 'regex' => 'false'],
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $response->assertOk();

        $body = ltrim((string) $response->getContent());

        $this->assertStringStartsWith('{', $body,
            'the route answered the HTML page rather than the table JSON. The usual cause is that '
            . 'something resolved yajra\'s Request singleton in this process before the request '
            . 'was issued -- see the note on self::COLUMNS. First 80 bytes: ' . substr($body, 0, 80));


        $response->assertOk();

        $data = $response->json('data');

        $this->assertIsArray($data, 'the order table did not answer with rows: '
            . substr((string) $response->getContent(), 0, 400));

        $refs = [];

        foreach ($data as $row) {
            if (preg_match('/#([A-Za-z0-9._-]+)/', (string) ($row['product_order_id'] ?? ''), $m) === 1) {
                $refs[] = $m[1];
            }
        }

        return $refs;
    }

    /**
     * Search for $keyword and assert exactly $expected came back -- having first proved that NO
     * seeded reference contains the keyword.
     *
     * THE TRAP THIS CLOSES COST A FALSE READING IN THIS VERY FILE. `product_order_id` was always
     * searchable, so an order referenced `FIND-ST-SHIPPED` is found by the word "Shipped" through
     * the REFERENCE column and not through the status column at all. Four tests here were written
     * that way and four of them passed against the unfixed code during the revert check -- they
     * were measuring the one column that already worked. The references are neutral now, and this
     * helper asserts that rather than trusting the next person to remember.
     *
     * @param list<string> $expected
     * @param list<string> $seeded every reference the test put in the fixture
     */
    private function assertSearchFinds(string $keyword, array $expected, array $seeded): void
    {
        foreach ($seeded as $reference) {
            $this->assertFalse(stripos($reference, $keyword) !== false,
                'the fixture reference "' . $reference . '" contains the search term "' . $keyword
                . '", so this test can be satisfied by the order-reference column, which was never '
                . 'broken. Rename the fixture.');
        }

        $this->assertSame($expected, $this->search($keyword));
    }

    // =============================================================================================
    //  THE PREMISE. If the table does not answer JSON rows at all, every assertion below passes or
    //  fails for a reason that has nothing to do with searching.
    // =============================================================================================

    public function test_premise_the_list_answers_rows_with_no_search_term(): void
    {
        $a = $this->order(['product_order_id' => 'FIND-PREMISE-1']);
        $b = $this->order(['product_order_id' => 'FIND-PREMISE-2']);

        $refs = $this->search('');

        $this->assertContains('FIND-PREMISE-1', $refs);
        $this->assertContains('FIND-PREMISE-2', $refs);
        $this->assertSame(2, count($refs), 'premise: exactly the two seeded orders');

        unset($a, $b);
    }

    public function test_premise_the_column_payload_matches_the_tables_own_columns(): void
    {
        /* THIS METHOD ISSUES NO HTTP REQUEST, which is what makes it safe to touch the DataTable:
         * resolving it poisons yajra's Request singleton for the rest of the process, and Laravel
         * gives each test method its own application. Keeping the comparison here and the driving
         * elsewhere is the whole reason self::COLUMNS is written out by hand. */
        $actual = [];

        foreach ((new \App\DataTables\OrderDataTable())->getColumns() as $column) {
            $definition = $column->toArray();
            $name = (string) ($definition['data'] ?? $definition['name'] ?? '');
            $actual[$name] = (bool) ($definition['searchable'] ?? true);
        }

        $this->assertSame(self::COLUMNS, $actual,
            'the list\'s columns have changed. Update self::COLUMNS, or the requests this file '
            . 'issues are searching a different set of columns than the real client does -- which '
            . 'is how a search test passes for a column nobody searches.');
    }

    public function test_premise_two_searches_in_one_test_do_not_answer_each_other(): void
    {
        /* The control on `forgetInstance('datatables.request')`. Without it the second search in a
         * method is answered with the first one's keyword, and several assertions in this file
         * would then be measuring the previous assertion. */
        $this->order(['product_order_id' => 'FIND-STALE-AAA']);
        $this->order(['product_order_id' => 'FIND-STALE-BBB']);

        $this->assertSame(['FIND-STALE-AAA'], $this->search('FIND-STALE-AAA'));
        $this->assertSame(['FIND-STALE-BBB'], $this->search('FIND-STALE-BBB'),
            'the second search answered with the first search\'s result, so yajra\'s request '
            . 'singleton is being reused across requests');
    }

    public function test_premise_the_harness_really_narrows_the_result_set(): void
    {
        /* The control on the paragraph above. If the request shape stopped reaching yajra's
         * searchable-column index, every assertion in this file would compare against the
         * unfiltered set -- and some of them would still pass. */
        $this->order(['product_order_id' => 'FIND-HARNESS-1']);
        $this->order(['product_order_id' => 'FIND-HARNESS-2']);

        $this->assertCount(2, $this->search(''), 'premise: both orders without a term');
        $this->assertCount(1, $this->search('FIND-HARNESS-1'),
            'the search term did not narrow anything, so this file cannot measure a search');
    }

    public function test_premise_the_order_reference_was_always_searchable(): void
    {
        /* The one thing that DID work, asserted so a regression in the filters cannot be mistaken
         * for the defect this slice repairs. */
        $this->order(['product_order_id' => 'FIND-REF-AAA']);
        $this->order(['product_order_id' => 'FIND-REF-BBB']);

        $this->assertSame(['FIND-REF-AAA'], $this->search('FIND-REF-AAA'));
    }

    // =============================================================================================
    //  #5 — the four columns whose display had moved off the stored value
    // =============================================================================================

    public function test_a_customer_can_be_found_by_first_name(): void
    {
        $omar = $this->customer('Omar', 'Khalil', '0991111111');
        $layla = $this->customer('Layla', 'Haddad', '0992222222');

        $this->order(['product_order_id' => 'FIND-C-1', 'customer_id' => $omar->id, 'is_guest' => 0]);
        $this->order(['product_order_id' => 'FIND-C-2', 'customer_id' => $layla->id, 'is_guest' => 0]);

        $this->assertSearchFinds('Omar', ['FIND-C-1'], ['FIND-C-1', 'FIND-C-2']);
    }

    public function test_a_customer_can_be_found_by_last_name(): void
    {
        $omar = $this->customer('Omar', 'Khalil', '0991111111');
        $this->customer('Layla', 'Haddad', '0992222222');

        $this->order(['product_order_id' => 'FIND-C-3', 'customer_id' => $omar->id, 'is_guest' => 0]);

        $this->assertSearchFinds('Khalil', ['FIND-C-3'], ['FIND-C-3']);
    }

    public function test_a_customer_can_be_found_by_full_name_as_the_row_prints_it(): void
    {
        /* The cell renders `first_name . ' ' . last_name`, so "Omar Khalil" is what the merchant
         * reads and therefore what they type. A per-column LIKE on either name alone cannot match
         * it; the keyword is split and every token must land somewhere on the same customer. */
        $omar = $this->customer('Omar', 'Khalil', '0991111111');
        $this->customer('Omar', 'Haddad', '0992222222');

        $this->order(['product_order_id' => 'FIND-C-4', 'customer_id' => $omar->id, 'is_guest' => 0]);
        $this->order(['product_order_id' => 'FIND-C-5', 'customer_id' => Customer::withoutGlobalScopes()
            ->where('last_name', 'Haddad')->first()->id, 'is_guest' => 0]);

        /* Both orders are an "Omar"; only one is "Omar Khalil". */
        $this->assertSearchFinds('Omar Khalil', ['FIND-C-4'], ['FIND-C-4', 'FIND-C-5']);
    }

    public function test_a_customer_can_be_found_by_mobile_number(): void
    {
        /* The cell prints the mobile under the name, so it is on screen and therefore searchable. */
        $omar = $this->customer('Omar', 'Khalil', '0991111111');
        $this->customer('Layla', 'Haddad', '0992222222');

        $this->order(['product_order_id' => 'FIND-C-6', 'customer_id' => $omar->id, 'is_guest' => 0]);

        $this->assertSearchFinds('0991111111', ['FIND-C-6'], ['FIND-C-6']);
    }

    public function test_an_order_can_be_found_by_the_status_label_the_badge_shows(): void
    {
        $this->order(['product_order_id' => 'FIND-S-6', 'delivered_status' => HomeController::ORDER_STATUS_SHIPPED]);
        $this->order(['product_order_id' => 'FIND-S-0', 'delivered_status' => HomeController::ORDER_STATUS_PENDING]);

        /* The badge reads "Shipped" and the column stores 6, so a LIKE on the raw column matched
         * nothing. */
        $this->assertSearchFinds('Shipped', ['FIND-S-6'], ['FIND-S-6', 'FIND-S-0']);
    }

    public function test_the_two_statuses_that_are_not_fulfilment_states_are_searchable_too(): void
    {
        /* F0: 7 is Partially Paid (a payment state) and 8 is Pre Order (an order type), both
         * sharing `delivered_status` with the seven fulfilment stages. A status search that
         * covered 0–6 only would make exactly those orders unfindable. */
        $seeded = ['FIND-S-7', 'FIND-S-8'];

        $this->order(['product_order_id' => 'FIND-S-7', 'delivered_status' => HomeController::ORDER_STATUS_PARTIALLY_PAID]);
        $this->order(['product_order_id' => 'FIND-S-8', 'delivered_status' => HomeController::ORDER_STATUS_PRE_ORDER]);

        $this->assertSearchFinds('Partially Paid', ['FIND-S-7'], $seeded);
        $this->assertSearchFinds('Pre Order', ['FIND-S-8'], $seeded);
    }

    public function test_a_status_search_does_not_match_a_different_status_by_substring(): void
    {
        /* "Return" is a substring of nothing here, but "Pending" and "Pre Order" both begin with
         * `P`, and `Delivered` contains `delivered`. A search that matched loosely would merge
         * states a merchant is trying to tell apart. */
        $seeded = ['FIND-S-1', 'FIND-S-2'];

        $this->order(['product_order_id' => 'FIND-S-1', 'delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);
        $this->order(['product_order_id' => 'FIND-S-2', 'delivered_status' => HomeController::ORDER_STATUS_CANCEL]);

        $this->assertSearchFinds('Delivered', ['FIND-S-1'], $seeded);
        $this->assertSearchFinds('Cancel', ['FIND-S-2'], $seeded);
    }

    public function test_an_order_can_be_found_by_the_payment_label_the_cell_shows(): void
    {
        /* The cell resolves `cod` to "Cash On Delivery" through `PaymentTypeLabel::for()`. The
         * stored value is the lowercase id, so the merchant's own words matched nothing. */
        $this->order(['product_order_id' => 'FIND-P-1', 'payment_type' => 'cod']);
        $this->order(['product_order_id' => 'FIND-P-2', 'payment_type' => 'stripe']);

        $this->assertSearchFinds('Cash On Delivery', ['FIND-P-1'], ['FIND-P-1', 'FIND-P-2']);
    }

    public function test_the_stored_payment_id_still_matches_as_well(): void
    {
        /* The raw LIKE stays as the first limb: a merchant who knows the gateway id, and every
         * saved search and bookmark that used it, must keep working. */
        $this->order(['product_order_id' => 'FIND-P-3', 'payment_type' => 'stripe']);
        $this->order(['product_order_id' => 'FIND-P-4', 'payment_type' => 'cod']);

        $this->assertSearchFinds('stripe', ['FIND-P-3'], ['FIND-P-3', 'FIND-P-4']);
    }

    public function test_an_order_can_be_found_by_the_date_as_the_row_prints_it(): void
    {
        $this->order(['product_order_id' => 'FIND-D-1', 'order_date' => '2026-03-17 10:00:00']);
        $this->order(['product_order_id' => 'FIND-D-2', 'order_date' => '2026-04-02 10:00:00']);

        $this->assertSearchFinds('2026-03-17', ['FIND-D-1'], ['FIND-D-1', 'FIND-D-2']);
    }

    public function test_a_search_that_matches_nothing_still_returns_nothing(): void
    {
        /* The repair must not turn an empty result into every order — a filter that stops
         * narrowing is worse than one that over-narrows, because it looks like it worked. */
        $this->order(['product_order_id' => 'FIND-NONE-1']);
        $this->order(['product_order_id' => 'FIND-NONE-2']);

        $this->assertSame([], $this->search('zzz-no-such-thing'));
    }

    public function test_a_search_cannot_reach_another_tenants_order(): void
    {
        /* The column filters run INSIDE yajra's global-search OR group, which is itself inside a
         * query already confined to the caller's store. An `orWhere` written at the wrong nesting
         * level is how a search widens a tenant boundary, so this is asserted rather than
         * reasoned about. */
        $victimMerchant = $this->makeMerchant();
        $victimStore = $this->makeStore($victimMerchant);

        $victim = Order::forceCreate([
            'product_order_id' => 'FIND-VICTIM',
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => '[]',
            'final_price' => 100,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'store_id' => $victimStore->id,
            'created_by' => $victimMerchant->id,
        ]);

        $this->order(['product_order_id' => 'FIND-MINE']);

        foreach (['FIND-VICTIM', 'Pending', 'Cash On Delivery', ''] as $keyword) {
            $this->assertNotContains('FIND-VICTIM', $this->search($keyword),
                'searching "' . $keyword . '" reached another store\'s order');
        }

        unset($victim);
    }

    // =============================================================================================
    //  #10 — the card view
    // =============================================================================================

    private function gridRefs(array $query = []): array
    {
        $response = $this->actingAs($this->merchant)->get('/order-grid' . ($query ? '?' . http_build_query($query) : ''));

        $response->assertOk();

        preg_match_all('/#([A-Za-z0-9._-]*GRID[A-Za-z0-9._-]*)/', (string) $response->getContent(), $m);

        return array_values(array_unique($m[1]));
    }

    public function test_premise_the_card_view_renders_the_orders_it_is_given(): void
    {
        $this->order(['product_order_id' => 'GRID-A', 'delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        $this->order(['product_order_id' => 'GRID-B', 'delivered_status' => HomeController::ORDER_STATUS_SHIPPED]);

        $refs = $this->gridRefs();

        $this->assertContains('GRID-A', $refs);
        $this->assertContains('GRID-B', $refs);
    }

    public function test_the_card_view_honours_the_status_filter_it_arrives_with(): void
    {
        $this->order(['product_order_id' => 'GRID-PENDING', 'delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        $this->order(['product_order_id' => 'GRID-SHIPPED', 'delivered_status' => HomeController::ORDER_STATUS_SHIPPED]);

        $this->assertSame(['GRID-SHIPPED'],
            $this->gridRefs(['ss_status' => (string) HomeController::ORDER_STATUS_SHIPPED]),
            'the card view silently discarded the filter the merchant arrived with and showed '
            . 'every order in the store');
    }

    public function test_the_card_view_honours_the_date_range(): void
    {
        $this->order(['product_order_id' => 'GRID-MARCH', 'order_date' => '2026-03-17 23:45:00']);
        $this->order(['product_order_id' => 'GRID-APRIL', 'order_date' => '2026-04-02 09:00:00']);

        $this->assertSame(['GRID-MARCH'],
            $this->gridRefs(['ss_from' => '2026-03-01', 'ss_to' => '2026-03-17']),
            'the end date must include the whole day — the fixture order is at 23:45 for exactly '
            . 'that reason');
    }

    public function test_the_card_view_honours_the_return_queue_filter(): void
    {
        $this->order(['product_order_id' => 'GRID-RETURN', 'return_status' => 1]);
        $this->order(['product_order_id' => 'GRID-NORETURN', 'return_status' => 0]);

        $this->assertSame(['GRID-RETURN'], $this->gridRefs(['ss_return' => '1']));
    }

    public function test_an_unrecognised_filter_value_adds_no_predicate_on_the_card_view(): void
    {
        /* The property `applyFilters()` documents — "a filter can only narrow" — asserted on the
         * new caller, because the way a reused filter goes wrong is by a value it does not
         * recognise emptying the screen. */
        $this->order(['product_order_id' => 'GRID-KEEP-1']);
        $this->order(['product_order_id' => 'GRID-KEEP-2']);

        $refs = $this->gridRefs(['ss_status' => 'banana', 'ss_payment' => 'banana', 'ss_source' => 'banana']);

        $this->assertContains('GRID-KEEP-1', $refs);
        $this->assertContains('GRID-KEEP-2', $refs);
    }

    public function test_the_card_view_still_refuses_a_principal_with_no_store(): void
    {
        /*
         * F149. `where('store_id', null)` is `WHERE store_id IS NULL`, so a principal with no
         * store context would be served every NULL-STORE order on the platform. Two layers stop
         * it: the handler's `resolvedCurrentStoreId()` guard before the query, and the
         * DataTable's `whereRaw('1 = 0')`.
         *
         * THE FIXTURE MUST BE A NULL-STORE ORDER, and the first version of this test used
         * `$this->order(...)`, which sets `store_id` to this merchant's store. `IS NULL` does not
         * match such a row, so the assertion passed with BOTH guards removed -- it was measuring
         * nothing, on the one test in this file whose subject is F149. Found by the tenant/authz
         * review of this change.
         *
         * WHAT IT PROVES, precisely: with this fixture the test goes red only if BOTH layers are
         * removed, because either one alone suppresses the row. The handler guard is asserted
         * separately below, by its own redirect and message, so a regression in that layer alone
         * is still caught.
         */
        $orphan = Order::forceCreate([
            'product_order_id' => 'GRID-GUARDED',
            'order_date' => now(),
            'customer_id' => 0,
            'is_guest' => 1,
            'product_json' => '[]',
            'final_price' => 100,
            'payment_type' => 'cod',
            'payment_status' => 'Unpaid',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
            'store_id' => null,
            'created_by' => $this->merchant->id,
        ]);

        $this->assertNull(Order::withoutGlobalScopes()->find($orphan->id)->store_id,
            'premise: the fixture really is a NULL-store order, which is the only row shape '
            . '`WHERE store_id IS NULL` can serve');

        $staff = $this->makeStaff($this->merchant, ['current_store' => null]);
        $this->grantPermission(User::find($staff->id), 'Manage Order');

        $response = $this->actingAs(User::find($staff->id))->get('/order-grid');

        $this->assertStringNotContainsString('GRID-GUARDED', (string) $response->getContent(),
            'a principal with no store context was served a NULL-store order');
    }

    public function test_the_handler_refuses_before_the_query_rather_than_narrowing_it(): void
    {
        /* The handler guard on its own, so a regression in THAT layer is caught even though the
         * DataTable's `1 = 0` would still suppress the rows. F149's requirement is a refusal
         * before the list query runs, not merely an empty list. */
        $staff = $this->makeStaff($this->merchant, ['current_store' => null]);
        $this->grantPermission(User::find($staff->id), 'Manage Order');

        $response = $this->actingAs(User::find($staff->id))->get('/order-grid');

        $response->assertRedirect();
        $response->assertSessionHas('error');
        $this->assertStringContainsString('No store is selected', (string) session('error'));
    }

    public function test_the_card_view_offers_the_same_filter_bar_as_the_list(): void
    {
        $this->order(['product_order_id' => 'GRID-BAR']);

        $html = (string) $this->actingAs($this->merchant)->get('/order-grid')->getContent();

        $this->assertStringContainsString('ss-filterbar', $html,
            'the card view still has no filter controls at all, so a merchant who lands here '
            . 'cannot re-filter and cannot search');
        $this->assertStringContainsString('name="ss_status"', $html);
        $this->assertStringContainsString(route('order.grid'), $html,
            'the filter form must post back to the card view, not to the list');
    }

    public function test_the_three_view_links_all_carry_the_active_filters(): void
    {
        /* The two sibling links were written differently — the board's carried
         * `request()->query()` and the cards' did not — which is how list → cards → list came
         * back filtered while the cards never were. */
        $this->order(['product_order_id' => 'GRID-LINKS', 'delivered_status' => HomeController::ORDER_STATUS_SHIPPED]);

        $filter = ['ss_status' => (string) HomeController::ORDER_STATUS_SHIPPED];

        $list = (string) $this->actingAs($this->merchant)->get('/order?' . http_build_query($filter))->getContent();
        $grid = (string) $this->actingAs($this->merchant)->get('/order-grid?' . http_build_query($filter))->getContent();

        $gridView = $this->hrefOfControlNamed($list, 'Grid View');

        $this->assertStringContainsString('ss_status=' . HomeController::ORDER_STATUS_SHIPPED,
            $gridView,
            'the list\'s Grid View link drops the active filter: ' . $gridView);

        /* And the board link, which already carried the filters before this slice -- the control
         * that the other two were measured against. */
        $boardView = $this->hrefOfControlNamed($list, 'Board View');

        $this->assertStringContainsString('ss_status=' . HomeController::ORDER_STATUS_SHIPPED,
            $boardView, 'the Board View link regressed: ' . $boardView);

        /*
         * THE LINK IS IDENTIFIED BY ITS ACCESSIBLE NAME, not by its href.
         *
         * A needle of `order` also matches `route('order.kanban', request()->query())`, which
         * carries the filter independently -- so the cards-to-list link could drop its query
         * string and the assertion would still pass (found by the tenant/authz review). Narrowing
         * to hrefs whose path ends at `/order` is not enough either: MEASURED, three do --
         * the breadcrumb (`http://localhost/order`, correctly unfiltered), the List View control
         * (`?ss_status=6`) and the filter bar's own clear link (`?ss_clear=1`, which must NOT
         * carry the filter). Only one of the three is the control this test is about, and what
         * distinguishes it is the name a merchant and a screen reader both use.
         */
        $listView = $this->hrefOfControlNamed($grid, 'List View');

        $this->assertStringContainsString('ss_status=' . HomeController::ORDER_STATUS_SHIPPED,
            $listView,
            'the card view\'s List View link drops the active filter: ' . $listView);
    }

    public function test_the_card_views_pagination_keeps_the_filter(): void
    {
        /* 16 cards a page. A filter that survives the first page and not the second is a filter
         * the merchant cannot trust. */
        for ($i = 0; $i < 18; $i++) {
            $this->order([
                'product_order_id' => 'GRID-PAGE-' . $i,
                'delivered_status' => HomeController::ORDER_STATUS_SHIPPED,
            ]);
        }

        $this->order(['product_order_id' => 'GRID-PAGE-OTHER', 'delivered_status' => HomeController::ORDER_STATUS_PENDING]);

        $html = (string) $this->actingAs($this->merchant)
            ->get('/order-grid?' . http_build_query(['ss_status' => (string) HomeController::ORDER_STATUS_SHIPPED]))
            ->getContent();

        /* `withQueryString()` carries the filter and Laravel appends `page` after it, so the
         * order in the href is `ss_status=6&page=2`. Asserted order-independently -- a regex that
         * pinned the order would fail on a Laravel change that is not a defect. */
        preg_match_all('/href="([^"]*page=2[^"]*)"/', $html, $links);

        $this->assertNotEmpty($links[1], 'premise: the card view paginates at all (18 shipped '
            . 'orders, 16 a page)');

        $kept = array_filter($links[1], fn ($href) => str_contains($href, 'ss_status=6'));

        $this->assertNotEmpty($kept,
            'the second page link drops the filter, so page 2 of a filtered set is unfiltered: '
            . implode(' | ', $links[1]));
    }

    /**
     * The `href` of the one anchor whose accessible name is $name.
     *
     * These three view-switch controls are icon-only, so `aria-label` is both their whole
     * accessible name and the only thing that tells them apart in the markup -- the hrefs do not,
     * as the comment in the test above records.
     */
    private function hrefOfControlNamed(string $html, string $name): string
    {
        preg_match_all('/<a\b[^>]*>/', $html, $tags);

        $matched = array_values(array_filter(
            $tags[0],
            fn ($tag) => str_contains($tag, 'aria-label="' . $name . '"')
        ));

        $this->assertCount(1, $matched,
            'premise: exactly one control named "' . $name . '"; found ' . count($matched));

        $this->assertSame(1, preg_match('/href="([^"]*)"/', $matched[0], $href),
            'the "' . $name . '" control has no href: ' . $matched[0]);

        return $href[1];
    }
}
