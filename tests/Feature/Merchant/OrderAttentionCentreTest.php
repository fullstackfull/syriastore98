<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-13 / WS E9 — the order screen's "needs attention" centre, and the counts it links to.
 * =================================================================================================
 *
 * THE ROW IS NARROWER THAN IT READS: `x-ss.attention` and `x-ss.attention-item` were written and
 * tested under P2-02 and are in production on the dashboard. What was missing was a second caller
 * — and one filter.
 *
 * THE PROPERTY THAT MAKES THE BLOCK WORTH READING is that a count links to a list which really
 * contains it. Measuring that turned up TWO mismatches on the dashboard, and they are the reason
 * this file asserts the pairing rather than just the rendering:
 *
 *  1. THE DASHBOARD'S "RETURNS" COUNT IS A DIFFERENT COLUMN. `$ssReturnOrders` comes from
 *     `getOrderCounts()`'s `return` bucket — `where('delivered_status', ORDER_STATUS_RETURN)`,
 *     the FULFILMENT status (HomeController.php:412). `orders.return_status` is a separate column
 *     holding the return-REQUEST workflow (`0 none, 1 request, 2 approve, 3 cancel`,
 *     2024_01_23_061635_create_orders_table.php:38). The first version of this slice filtered on
 *     `return_status` and would have sent a merchant clicking that count to a list that does not
 *     contain the orders it counted.
 *
 *  2. THE DASHBOARD'S STOCK COUNT AND THE PRODUCT LIST'S FILTER DISAGREE. The count includes
 *     untracked products the merchant marked out of stock; `ss_stock=out` requires
 *     `track_stock = 1` on all three of its states, because a product the system was told not to
 *     count has no stock state at all — a partition `ProductListFilterTest` asserts. So that link
 *     is deliberately left UNFILTERED rather than given false precision, and the disagreement is
 *     recorded rather than papered over. `test_the_stock_link_is_not_given_false_precision` pins
 *     that choice so a later change does not quietly add a parameter that narrows wrongly.
 *
 * AND THE FILTER HAD TO REACH THE MEMORY, not only the DataTable. `TableFilterMemory`'s own
 * docblock records what happens to a key its vocabulary does not know: `describe()` returns `[]`,
 * the Clear button's render condition is `describe(…) !== []`, and the merchant gets a sticky
 * filter, no receipt and no way out — the "my orders are gone" screen the restore is only safe if
 * it prevents.
 */
class OrderAttentionCentreTest extends SecurityTestCase
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

    private function index(array $query = [])
    {
        return $this->actingAs($this->merchant)->get(route('order.index', $query));
    }

    // ---------------------------------------------------------------------------------------
    //  THE BLOCK
    // ---------------------------------------------------------------------------------------

    /**
     * NO EMPTY STATE, by the component's own documented contract: "a permanent '0 items need
     * attention' panel teaches merchants to stop looking at this part of the screen".
     */
    public function test_a_store_with_nothing_to_do_shows_no_attention_block(): void
    {
        $response = $this->index();

        $response->assertStatus(200);
        $this->assertStringNotContainsString('ss-attention', (string) $response->getContent(),
            'an attention block rendered with nothing in it, which teaches merchants to ignore it');
    }

    public function test_pending_orders_appear_with_a_link_that_filters_to_them(): void
    {
        $this->makeOrder($this->store, ['delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        $this->makeOrder($this->store, ['delivered_status' => HomeController::ORDER_STATUS_DELIVERED]);

        $body = (string) $this->index()->getContent();

        $this->assertStringContainsString('ss-attention', $body);
        $this->assertStringContainsString('ss_status=' . HomeController::ORDER_STATUS_PENDING, $body,
            'the pending count does not link to the pending list, so a merchant told "1 order is '
            . 'waiting" lands on every order they have ever taken');
    }

    public function test_return_requests_appear_with_a_link_that_filters_to_them(): void
    {
        $this->makeOrder($this->store, ['return_status' => 1]);

        $body = (string) $this->index()->getContent();

        $this->assertStringContainsString('ss_return=1', $body,
            'the return-request count does not link to the return-request queue');
    }

    public function test_unpaid_orders_appear_with_a_link_that_filters_to_them(): void
    {
        $this->makeOrder($this->store, ['payment_status' => 'Unpaid']);

        $body = (string) $this->index()->getContent();

        $this->assertStringContainsString('ss_payment=Unpaid', $body);
    }

    /**
     * COUNTS ARE STORE-SCOPED, and this is not decoration: `Order` is NOT in
     * `config/tenancy.php`'s `scoped_models`, so the handler's own `where('store_id')` is the only
     * thing keeping another tenant's orders out of these numbers.
     */
    public function test_another_tenants_orders_are_not_counted(): void
    {
        $other = $this->makeMerchant();
        $otherStore = $this->makeStore($other, ['default_language' => 'en']);

        for ($i = 0; $i < 3; $i++) {
            $this->makeOrder($otherStore, ['delivered_status' => HomeController::ORDER_STATUS_PENDING]);
        }

        $response = $this->index();

        $response->assertStatus(200);
        $this->assertStringNotContainsString('ss-attention', (string) $response->getContent(),
            "another tenant's pending orders were counted into this store's attention block");
    }

    // ---------------------------------------------------------------------------------------
    //  THE FILTER, end to end
    // ---------------------------------------------------------------------------------------

    /**
     * THE QUEUE IS THE UNDECIDED ONE. `return_status` holds four values and only a raised,
     * undecided request is actionable; 2 (approved) and 3 (cancelled) are decided.
     */
    public function test_the_return_filter_narrows_to_undecided_requests_only(): void
    {
        $awaiting = $this->makeOrder($this->store, ['return_status' => 1]);
        $approved = $this->makeOrder($this->store, ['return_status' => 2]);
        $none = $this->makeOrder($this->store, ['return_status' => 0]);

        $rows = $this->dataTableRows(['ss_return' => '1']);

        $this->assertContains($awaiting->id, $rows);
        $this->assertNotContains($approved->id, $rows, 'a decided return is not an open queue item');
        $this->assertNotContains($none->id, $rows);
    }

    /**
     * AND `ss_return=0` MUST NOT INVERT IT. A hand-typed 0 would otherwise mean "every order with
     * no return", which is almost the whole table presented as a queue.
     */
    public function test_a_hand_typed_zero_does_not_invert_the_queue(): void
    {
        $this->makeOrder($this->store, ['return_status' => 1]);
        $none = $this->makeOrder($this->store, ['return_status' => 0]);

        $rows = $this->dataTableRows(['ss_return' => '0']);

        // UNNARROWED is the property, not "contains the zero one". The first version of this test
        // asserted only that the no-return order was present -- which is true whether the value
        // is IGNORED or APPLIED as `where('return_status', 0)`, so the assertion could not tell
        // the two apart and the bite test passed against a handler that accepted any value. The
        // order WITH a return must be there too; that is what distinguishes ignored from filtered.
        $this->assertContains($none->id, $rows);
        $this->assertSame(2, count($rows),
            '`ss_return=0` narrowed the list, so it is being treated as a filter value rather '
            . 'than ignored -- which turns "every order with no return" into a queue');
    }

    /**
     * THE RECEIPT AND THE CLEAR BUTTON. Without `ss_return` in `TableFilterMemory`'s vocabulary
     * the screen narrows with nothing on it to say so and no way out.
     */
    public function test_the_return_filter_is_described_and_clearable(): void
    {
        $this->makeOrder($this->store, ['return_status' => 1]);

        $described = \App\Services\TableFilterMemory::describe(
            \Illuminate\Http\Request::create('/order', 'GET', ['ss_return' => '1']),
            'order',
            []
        );

        $this->assertNotSame([], $described,
            'the return filter is not described, so the Clear button does not render and the '
            . 'merchant has a narrowed screen with no way out');

        $this->assertStringContainsString('Awaiting a decision', implode(' ', $described),
            'the receipt does not say what the filter means');
    }

    /**
     * THE CHOICE NOT TO LINK THE STOCK COUNT, pinned so it is a decision and not an omission.
     * The dashboard's stock count and `ss_stock=out` disagree about untracked products, so the
     * link stays unfiltered. If someone later makes them agree, this test should be the thing
     * that asks them to.
     */
    public function test_the_stock_link_is_not_given_false_precision(): void
    {
        $overview = (string) file_get_contents(base_path('resources/views/dashboard/overview.blade.php'));

        // LOCATED BY THE ITEM'S OWN TEXT, not by a character window from a variable name. The
        // first version searched from `$ssOutOfStock > 0` -- which first occurs in the
        // `$ssNeedsAttention` expression near the top of the file, 170 lines above the item, so
        // the window never reached the href and the bite test passed against an injected
        // `ss_stock=out`. Found by reverting the fix and watching the test stay green.
        $anchor = strpos($overview, "trans_choice('product is at or below the stock threshold");

        $this->assertNotFalse($anchor,
            'premise: the stock attention item was not found, so this test measures nothing');

        $stockBlock = substr($overview, $anchor, 400);

        $this->assertStringContainsString("__('Review stock')", $stockBlock,
            'premise: the located block really is the stock item');

        $this->assertStringNotContainsString('ss_stock', $stockBlock,
            'the stock link was given an `ss_stock` parameter. The dashboard count includes '
            . 'untracked products marked out of stock and the filter excludes them, so that link '
            . 'would land on a list missing some of the products it counted. Make the two agree '
            . 'first, then change this test deliberately.');
    }

    /** @return list<int> */
    private function dataTableRows(array $query): array
    {
        $response = $this->actingAs($this->merchant)->get(route('order.index', $query + [
            'draw' => 1, 'start' => 0, 'length' => 100,
        ]), ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json']);

        $response->assertStatus(200);

        $payload = $response->json();

        $this->assertIsArray($payload, 'premise: the DataTable answered with JSON');
        $this->assertArrayHasKey('data', $payload, 'premise: the DataTable returned rows');

        return array_values(array_filter(array_map(
            static fn ($row) => isset($row['id']) ? (int) $row['id'] : null,
            $payload['data']
        )));
    }
}
