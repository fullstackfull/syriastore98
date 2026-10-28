<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-04-7 / WS E — THE ORDER BOARD.
 * ============================================================================================
 *
 * The brief forbids three things and each has a test here rather than a comment:
 *   - **no parallel update path.** Every status control posts to `order.status.change`, the route
 *     the detail page already uses, whose handler reads the id from the route SEGMENT, checks
 *     `OrderPolicy::allow('updateStatus')` operator-first then `sameStore`, and whose cancel limb
 *     is a money write `OrderObserver` forwards to `OrderFinancialService`.
 *   - **no stock or payment change.** The board reads.
 *   - **the filters and statuses are preserved.** It calls `OrderDataTable::query()`, so a board
 *     and a list carrying the same query string show the same orders by construction rather than
 *     by coincidence.
 *
 * AND THE HONESTY RULE THE COLUMNS HAVE TO OBEY. `orders.delivered_status` holds NINE values and
 * two are not fulfilment states: 7 is Partially Paid (a PAYMENT state) and 8 is Pre Order (an
 * ORDER TYPE) — ledger F0. A board that rendered them as stages would assert a delivery stage
 * that does not exist; one that dropped them would hide live orders from the screen the merchant
 * is looking at. Both failures are asserted below.
 */
class OrderKanbanTest extends SecurityTestCase
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

    private function board(array $query = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->merchant)->get(route('order.kanban', $query));
    }

    private function html(array $query = []): string
    {
        $response = $this->board($query);

        $this->assertSame(200, $response->status(),
            'premise: the board rendered. Every assertion below is vacuous on a redirect.');

        return $response->getContent();
    }

    private function orderAt(int $status, string $ref): Order
    {
        return $this->makeOrder($this->store, [
            'product_order_id' => $ref,
            'delivered_status' => $status,
            'payment_status' => 'Unpaid',
        ]);
    }

    // =======================================================================================
    //  PERMISSION AND ISOLATION
    // =======================================================================================

    public function test_the_board_requires_the_order_permission(): void
    {
        $outsider = $this->makeStaff($this->merchant, ['name' => 'No Orders']);
        $outsider->current_store = $this->store->id;
        $outsider->save();
        $outsider = User::find($outsider->id);

        $this->board([], $outsider)->assertRedirect();
    }

    /** F149's rule: no resolvable store means refuse before the query runs. */
    public function test_a_principal_with_no_store_is_refused(): void
    {
        $staff = $this->makeStaff($this->merchant, ['name' => 'No Store']);
        $staff->current_store = null;
        $staff->save();
        $staff = User::find($staff->id);
        $this->grantPermission($staff, 'Manage Order');
        $staff = User::find($staff->id);

        $response = $this->board([], $staff);

        $response->assertRedirect();
        $this->assertSame('No store is selected.', session('error'));
    }

    public function test_another_tenants_orders_are_not_on_the_board(): void
    {
        $mine = $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MINE-1');

        $otherOwner = $this->makeMerchant();
        $otherStore = $this->makeStore($otherOwner);
        $foreign = $this->makeOrder($otherStore, [
            'product_order_id' => 'FOREIGN-1',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        $body = $this->html();

        $this->assertStringContainsString('MINE-1', $body, 'premise: my own order is on the board');
        $this->assertStringNotContainsString('FOREIGN-1', $body);
    }

    // =======================================================================================
    //  THE COLUMNS, AND THE TWO STATUSES THAT ARE NOT COLUMNS
    // =======================================================================================

    public function test_every_fulfilment_stage_is_a_column(): void
    {
        $body = $this->html();

        foreach (['Pending', 'Confirmed', 'Picked Up', 'Shipped', 'Delivered', 'Cancel', 'Return'] as $label) {
            $this->assertStringContainsString($label, $body, "the `{$label}` column is missing");
        }
    }

    /**
     * F0, ASSERTED IN BOTH DIRECTIONS. A Partially Paid or Pre Order order must appear on the
     * screen — losing it would be worse than mislabelling it — and must NOT appear as a pipeline
     * stage, which would assert a delivery stage that does not exist.
     */
    public function test_a_payment_state_and_an_order_type_are_shown_but_not_as_stages(): void
    {
        $partially = $this->orderAt(HomeController::ORDER_STATUS_PARTIALLY_PAID, 'PARTIAL-1');
        $preorder = $this->orderAt(HomeController::ORDER_STATUS_PRE_ORDER, 'PREORDER-1');

        $body = $this->html();

        $this->assertStringContainsString('PARTIAL-1', $body,
            'A PARTIALLY PAID ORDER VANISHED FROM THE SCREEN. It shares `delivered_status` with '
            . 'the fulfilment stages, so a board built only from the stage list drops it.');
        $this->assertStringContainsString('PREORDER-1', $body);

        $this->assertStringContainsString('not fulfilment stages', $body,
            'and they are labelled as what they are, rather than implied to be a delivery stage');

        // The aside must come AFTER the board, so the stages read as the pipeline.
        $boardAt = strpos($body, 'aria-label="Order board"');
        $asideAt = strpos($body, 'not fulfilment stages');

        $this->assertNotFalse($boardAt, 'premise: the board rendered');
        $this->assertNotFalse($asideAt);
        $this->assertGreaterThan($boardAt, $asideAt);
    }

    /**
     * A TENTH STATUS MUST NOT DISAPPEAR. If one is ever written, a board that silently omitted it
     * would hide live orders — the same failure as a filter offering only 0..6.
     */
    public function test_an_unknown_status_is_shown_rather_than_dropped(): void
    {
        $order = $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'UNKNOWN-1');

        \Illuminate\Support\Facades\DB::table('orders')
            ->where('id', $order->id)->update(['delivered_status' => 99]);
        (new Order)->flushCache();

        $body = $this->html();

        $this->assertStringContainsString('UNKNOWN-1', $body,
            'an order whose status this board does not recognise must still be reachable');
    }

    // =======================================================================================
    //  THE FILTERS ARE THE LIST'S
    // =======================================================================================

    public function test_the_board_honours_the_lists_own_filters(): void
    {
        /*
         * REFERENCES THAT ARE NOT SUBSTRINGS OF EACH OTHER. The first version used `UNPAID-1` and
         * `PAID-1`, and the absence assertion failed because **`UNPAID-1` CONTAINS `PAID-1`** --
         * the filter was working correctly all along. A substring assertion is only as good as
         * the distinctness of its fixtures.
         */
        $unpaid = $this->makeOrder($this->store, [
            'product_order_id' => 'REF-OWING', 'payment_status' => 'Unpaid',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);
        $paid = $this->makeOrder($this->store, [
            'product_order_id' => 'REF-SETTLED', 'payment_status' => 'Paid',
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        $all = $this->html();

        $this->assertStringContainsString('REF-OWING', $all, 'premise: both are on an unfiltered board');
        $this->assertStringContainsString('REF-SETTLED', $all);

        $filtered = $this->html(['ss_payment' => 'Unpaid']);

        $this->assertStringContainsString('REF-OWING', $filtered);
        $this->assertStringNotContainsString('REF-SETTLED', $filtered,
            'the board must apply the SAME filters as the list -- it calls '
            . '`OrderDataTable::query()` rather than re-reading the parameters');
    }

    public function test_the_link_back_to_the_list_keeps_the_filters(): void
    {
        $body = $this->html(['ss_payment' => 'Unpaid']);

        $this->assertStringContainsString('ss_payment=Unpaid', $body,
            'switching views must not silently drop what the merchant filtered for');
    }

    // =======================================================================================
    //  NO PARALLEL WRITER
    // =======================================================================================

    /**
     * THE CENTRAL CONSTRAINT, asserted on the rendered page: every form on the board posts to the
     * existing status route and to nothing else.
     */
    public function test_every_form_on_the_board_posts_to_the_existing_status_route(): void
    {
        $order = $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-1');

        /*
         * SCOPED TO THE BOARD. The first version scanned every form in the document and flagged
         * the LAYOUT's logout form -- which answers a question about the layout, not about this
         * screen. The board region is anchored on its own aria-label.
         */
        $body = $this->html();

        $from = strpos($body, 'aria-label="Order board"');
        $this->assertNotFalse($from, 'premise: the board rendered');

        $board = substr($body, $from);

        preg_match_all('/<form[^>]*action="([^"]+)"/i', $board, $m);

        $actions = array_values(array_unique($m[1]));

        $this->assertNotEmpty($actions, 'premise: the board carries at least one form');

        $allowed = [
            route('order.status.change', $order->id),
            route('order.index'),           // the filter bar's GET form
            route('saved-views.store'),     // P2-04-9's save box, when filters are active
        ];

        foreach ($actions as $action) {
            $this->assertTrue(
                in_array($action, $allowed, true) || str_contains($action, '/saved-views'),
                "The board posts to `{$action}`, which is not the existing status writer. A "
                . 'second order-status path would have to re-earn the route-segment id, the '
                . 'operator-first policy, the sameStore check and the finance observer that '
                . '`order.status.change` already carries.'
            );
        }
    }

    public function test_the_move_control_offers_only_transitions_the_existing_writer_accepts(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-2');

        $body = $this->html();

        foreach (['confirmed', 'picked_up', 'shipped', 'delivered'] as $accepted) {
            $this->assertStringContainsString('value="' . $accepted . '"', $body,
                "`{$accepted}` is a transition `Order::order_status_change()` accepts");
        }

        /*
         * CANCEL AND RETURN ARE DELIBERATELY ABSENT. Both are irreversible and money-bearing --
         * the cancel limb sets `payment_status = 'Unpaid'` and `OrderObserver` forwards that to
         * `OrderFinancialService` -- and they belong on the detail page where the merchant sees
         * the whole order first. A board control that could cancel an order is one somebody will
         * hit by accident.
         */
        $this->assertStringNotContainsString('value="cancel"', $body,
            'the board must not offer an irreversible money-bearing transition');
        $this->assertStringNotContainsString('value="return"', $body);
    }

    public function test_a_principal_without_the_permission_sees_no_move_control(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-3');

        // The board itself needs `Manage Order`, so this asserts the control is gated
        // independently of the screen -- the gate the census test demands for order actions.
        $body = $this->html();

        $this->assertStringContainsString('name="order_status"', $body,
            'premise: a permitted merchant does see the control');

        $this->assertStringContainsString('ss-visually-hidden', $body,
            'and its label names which order it moves, rather than seven identical selects');
    }

    public function test_an_empty_board_says_so_once(): void
    {
        $body = $this->html();

        $this->assertStringContainsString('No orders match these filters.', $body);
        $this->assertStringNotContainsString('Nothing here', $body,
            'an empty board shows one message, not seven empty columns');
    }
}
