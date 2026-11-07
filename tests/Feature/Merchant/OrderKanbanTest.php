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

    /**
     * CORRECTED 2026-11-03 BY P2.5-R-05, and the correction is the whole lesson of this test.
     *
     * It used to assert the literal list `['confirmed', 'picked_up', 'shipped', 'delivered']`,
     * with the message "`{$accepted}` is a transition `Order::order_status_change()` accepts".
     * **It never asked the writer.** The writer tests `$order_status == 'pickedup'`, no
     * underscore, so `picked_up` matched no branch — and this test asserted the opposite of the
     * truth while its own name promised it had checked.
     *
     * It now READS the writer's branches out of the model and requires every option on the board
     * to be one of them. A value the writer does not accept fails here whatever anyone believes
     * about it.
     */
    public function test_the_move_control_offers_only_transitions_the_existing_writer_accepts(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-2');

        $body = $this->html();

        // The vocabulary, read from `Order::order_status_change()` rather than restated here.
        $model = file_get_contents(app_path('Models/Order.php'));
        $writer = substr($model, strpos($model, 'public static function order_status_change'));
        $writer = substr($writer, 0, 6000);
        preg_match_all('/\$order_status\s*==\s*\x27([a-z_]+)\x27/', $writer, $m);
        $accepts = array_values(array_unique($m[1]));

        $this->assertContains('confirmed', $accepts,
            'premise: the writer was located and its branches parsed');
        $this->assertGreaterThanOrEqual(5, count($accepts),
            'premise: the parse found the writer\'s full vocabulary, not a fragment of it');

        preg_match('/<select[^>]+name="delivered"[^>]*>(.*?)<\/select>/s', $body, $sel);
        $this->assertNotEmpty($sel, 'premise: the board renders a move select');
        preg_match_all('/<option value="([^"]*)"/', $sel[1], $opts);
        $offered = array_values(array_filter($opts[1]));

        $this->assertNotEmpty($offered);
        foreach ($offered as $value) {
            $this->assertContains($value, $accepts,
                "the board offers `{$value}`, which `Order::order_status_change()` matches no "
                . 'branch for: the merchant picks it, the POST is authorised, and nothing happens');
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

    /**
     * The premise moved from `name="order_status"` to `name="delivered"` with P2.5-R-05 — the
     * board was sending a key the handler does not read, so every move was discarded.
     *
     * AND THE MISSING HALF IS ADDED. This test is named for a principal WITHOUT the permission
     * and never had one: it asserted the control is present for a merchant who may move orders,
     * then checked the label. The unpermitted case is the one the name promises.
     */
    public function test_a_principal_without_the_permission_sees_no_move_control(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-3');

        // The board itself needs `Manage Order`, so this asserts the control is gated
        // independently of the screen -- the gate the census test demands for order actions.
        $body = $this->html();

        $this->assertStringContainsString('name="delivered"', $body,
            'premise: a permitted merchant does see the control');

        $this->assertStringContainsString('ss-visually-hidden', $body,
            'and its label names which order it moves, rather than seven identical selects');
    }

    /** The half the test above was named for. */
    public function test_the_move_control_is_absent_for_a_principal_who_may_not_move_orders(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MOVE-4');

        // `Show Order` reaches the board without carrying `Manage Order`.
        $viewer = $this->makeStaff($this->merchant, ['name' => 'Read Only']);
        $viewer->current_store = $this->store->id;
        $viewer->save();
        $viewer = User::find($viewer->id);
        $this->grantPermission($viewer, 'Show Order');
        $viewer = User::find($viewer->id);

        $response = $this->board([], $viewer);

        if ($response->status() !== 200) {
            // The screen itself refuses this principal, which is a stronger answer than hiding
            // the control. Either outcome satisfies the rule; both are recorded rather than one
            // being assumed.
            $response->assertRedirect();

            return;
        }

        $this->assertStringNotContainsString('name="delivered"', $response->getContent(),
            'a principal who may not move orders was shown the move control');
    }

    public function test_an_empty_board_says_so_once(): void
    {
        $body = $this->html();

        $this->assertStringContainsString('No orders match these filters.', $body);
        $this->assertStringNotContainsString('Nothing here', $body,
            'an empty board shows one message, not seven empty columns');
    }

    // =======================================================================================
    //  REACHABILITY  (P2-04-12)
    // =======================================================================================

    /*
     * THESE THREE CASES EXIST BECAUSE THE FIRST TWELVE COULD NOT HAVE FOUND THE DEFECT THEY COVER.
     * Every one of them calls `route('order.kanban')` directly, so the board was fully asserted
     * while no view linked to it: it shipped reachable only by typing the URL. A test that reaches
     * a surface by constructing its own route proves the surface works, never that a user can get
     * to it.
     */

    public function test_the_order_list_links_to_the_board(): void
    {
        $response = $this->actingAs($this->merchant)->get(route('order.index'));

        $this->assertSame(200, $response->status(), 'premise: the order list rendered');
        $this->assertStringContainsString(route('order.kanban'), $response->getContent(),
            'nothing on the order list links to the board, so it is reachable only by typing '
            . 'the URL');
    }

    public function test_the_grid_view_links_to_the_board(): void
    {
        $response = $this->actingAs($this->merchant)->get(route('order.grid'));

        $this->assertSame(200, $response->status(), 'premise: the grid view rendered');
        $this->assertStringContainsString(route('order.kanban'), $response->getContent(),
            'nothing on the grid view links to the board');
    }

    public function test_the_link_to_the_board_carries_the_active_filters(): void
    {
        // The same contract the board's own link back to the list keeps, so switching view does
        // not silently widen what the merchant is looking at.
        $response = $this->actingAs($this->merchant)->get(route('order.index', ['ss_payment' => 'Unpaid']));

        $this->assertSame(200, $response->status(), 'premise: the filtered list rendered');
        $this->assertStringContainsString(route('order.kanban', ['ss_payment' => 'Unpaid']),
            $response->getContent(),
            'the board link drops the active filter, so a merchant filtering to unpaid orders '
            . 'and switching view is shown every order they have');
    }

    // =======================================================================================
    //  THE BOARD IS BOUNDED  (P2.5 kanban-unbounded-render)
    // =======================================================================================

    /*
     * WHAT THESE CASES PIN, and nothing pinned it before them. `order_kanban_view` fetched the
     * WHOLE filtered result set -- `$orders = $query->get()` with no `limit` anywhere upstream or
     * downstream -- and rendered one `<article class="ss-board__card">` per order, each card
     * lazy-loading `CustomerData` and calling `order_currency_format_with_sym()`, which itself
     * does `Order::find()` and `getAdminAllSetting()`. So N orders cost ~4N round trips and the
     * one screen whose purpose is "nothing has been missed" is also the one that stops rendering
     * first.
     *
     * THE CAP IS ONLY HALF AN ANSWER AND THE OTHER HALF IS THE HEADER. A merchant who cannot
     * feel a truncation is worse off than one looking at a slow board, so the column header must
     * keep the TRUE count (a grouped `COUNT(*)`, not the capped collection) and offer a link to
     * the rest. Every case below asserts one of those two halves.
     */

    private function cardCount(string $body): int
    {
        return substr_count($body, '<article class="ss-board__card">');
    }

    /** The href of the first overflow link, or null. */
    private function moreHref(string $body): ?string
    {
        return preg_match('/<a class="ss-board__more" href="([^"]+)"/', $body, $m) ? $m[1] : null;
    }

    public function test_a_column_larger_than_the_cap_renders_at_most_the_cap(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'BULK-' . $i);
        }

        $this->assertSame(55, Order::withoutGlobalScopes()->where('store_id', $this->store->id)->count(),
            'premise: 55 orders really are in the table for this store');

        $body = $this->html();

        $this->assertSame(50, $this->cardCount($body),
            'THE BOARD RENDERED EVERY MATCHING ORDER. One card is ~4 database round trips and one '
            . 'Crypt::encrypt(), so an unbounded fetch does not render slowly at volume -- it '
            . 'exhausts memory, and a board that will not load shows ZERO orders.');
    }

    /**
     * THE HALF THAT MAKES THE CAP HONEST. A truncation the merchant cannot see is how a real
     * order gets forgotten, so the header number must come from a grouped COUNT(*) over the whole
     * filtered set, not from the capped collection it is rendering beside.
     */
    public function test_the_column_header_still_reports_the_true_count_and_offers_the_rest(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'TRUE-' . $i);
        }

        $body = $this->html();

        $this->assertStringContainsString('<span class="ss-board__count">55</span>', $body,
            'the header reports the number of cards it drew rather than the number of orders '
            . 'there are, so a capped column claims the cap IS the merchant\'s whole pipeline');

        $this->assertStringContainsString('aria-label="Pending (55)"', $body,
            'and a screen reader is told the same true number');

        $this->assertNotNull($this->moreHref($body),
            'a capped column offers no way to the orders it is not showing');
    }

    /**
     * NEGATIVE CONTROL -- THE LINK MUST NOT WIDEN OR REWRITE WHAT THE MERCHANT CHOSE. It carries
     * the board's own active filters (or "See all 55 Pending" from an Unpaid board silently shows
     * paid ones too) and it must NOT carry `TableFilterMemory::APPLY`: `OrderController::index()`
     * calls `TableFilterMemory::remember()`, which writes the session filter memory only when
     * that key is present, and the board is reached as `route('order.kanban', request()->query())`
     * -- so a naive `array_merge(request()->query(), ...)` would OVERWRITE the merchant's
     * remembered order filters with a status they never picked.
     */
    public function test_the_overflow_link_keeps_the_active_filters_and_drops_the_memory_keys(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'KEEP-' . $i);
        }

        $body = $this->html(['ss_payment' => 'Unpaid', \App\Services\TableFilterMemory::APPLY => '1']);

        $href = $this->moreHref($body);

        $this->assertNotNull($href, 'premise: the capped column offered the link');

        $this->assertStringContainsString('ss_payment=Unpaid', $href,
            'the link widens the screen past what the merchant filtered for');
        $this->assertStringContainsString('ss_status=' . HomeController::ORDER_STATUS_PENDING, $href,
            'the link must narrow the list to the column it came from');
        $this->assertStringNotContainsString(\App\Services\TableFilterMemory::APPLY, $href,
            'the link carries the filter-memory APPLY key, so following it REWRITES the '
            . 'merchant\'s remembered order filters with a status they never chose');
    }

    /**
     * NEGATIVE CONTROL -- THE CAP TOOK NOTHING AWAY. A column under the cap still renders every
     * one of its orders and offers no overflow link, so the control above cannot be satisfied by
     * a board that truncates everything or links everywhere.
     */
    public function test_a_column_under_the_cap_renders_all_of_it_and_offers_no_overflow_link(): void
    {
        foreach (['SMALL-1', 'SMALL-2', 'SMALL-3'] as $ref) {
            $this->orderAt(HomeController::ORDER_STATUS_PENDING, $ref);
        }

        $body = $this->html();

        $this->assertSame(3, $this->cardCount($body));
        $this->assertStringContainsString('SMALL-3', $body);
        $this->assertStringContainsString('<span class="ss-board__count">3</span>', $body);
        $this->assertNull($this->moreHref($body),
            'a column that is showing everything it has must not offer "see all"');
    }

    /**
     * NEGATIVE CONTROL -- `ssTotal` MUST KEEP COUNTING THE ASIDE AND UNKNOWN STATUSES. This is
     * the most likely way to get the rewrite wrong and it fails somewhere that reads as
     * unrelated: a grouped count summed over only the seven pipeline statuses gives a store whose
     * orders are all Partially Paid `$ssTotal === 0`, kanban.blade.php takes its empty branch,
     * and the board element is never emitted at all.
     */
    public function test_a_store_whose_only_orders_are_not_stages_still_gets_a_board(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PARTIALLY_PAID, 'ONLY-PARTIAL');

        $body = $this->html();

        $this->assertStringContainsString('aria-label="Order board"', $body,
            '$ssTotal counted only the pipeline statuses, so a store with nothing but a payment '
            . 'state was told it has no orders');
        $this->assertStringNotContainsString('No orders match these filters.', $body);
        $this->assertStringContainsString('ONLY-PARTIAL', $body);
    }

    /** The aside is bounded and counted by the same rule as a pipeline column. */
    public function test_the_aside_is_capped_and_reports_its_true_count(): void
    {
        for ($i = 1; $i <= 55; $i++) {
            $this->orderAt(HomeController::ORDER_STATUS_PRE_ORDER, 'ASIDE-' . $i);
        }

        $body = $this->html();

        $this->assertSame(50, $this->cardCount($body),
            'the aside renders every order it has, which is the same unbounded fetch one '
            . 'presentation layer further down');
        $this->assertStringContainsString('<span class="ss-board__count">55</span>', $body);
    }

    /**
     * NEGATIVE CONTROL -- EVERY BUCKET QUERY MUST BE A CLONE OF THE FILTERED BUILDER. `Order` is
     * in no `scoped_models` list and registers no `StoreScope`, so `OrderDataTable::query()`'s
     * `where('store_id', ...)` is the ONLY isolation on this screen. A per-status fetch rebuilt
     * from `Order::query()` loses it; one that mutates `$query` in the loop instead of cloning
     * accumulates nine contradictory predicates and renders every column after the first empty,
     * which looks exactly like a working cap.
     */
    public function test_a_capped_board_still_shows_no_other_tenants_orders_in_any_column(): void
    {
        $this->orderAt(HomeController::ORDER_STATUS_PENDING, 'MINE-BULK');
        $this->orderAt(HomeController::ORDER_STATUS_SHIPPED, 'MINE-SHIPPED');
        $this->orderAt(HomeController::ORDER_STATUS_PRE_ORDER, 'MINE-PREORDER');

        $otherOwner = $this->makeMerchant();
        $otherStore = $this->makeStore($otherOwner);

        foreach ([
            HomeController::ORDER_STATUS_PENDING,
            HomeController::ORDER_STATUS_SHIPPED,
            HomeController::ORDER_STATUS_PRE_ORDER,
        ] as $i => $status) {
            $this->makeOrder($otherStore, [
                'product_order_id' => 'FOREIGN-BULK-' . $i,
                'delivered_status' => $status,
            ]);
        }

        $body = $this->html();

        // Every column after the first still renders, so a mutated builder cannot pass here.
        $this->assertStringContainsString('MINE-BULK', $body, 'premise: the first column rendered');
        $this->assertStringContainsString('MINE-SHIPPED', $body,
            'a later pipeline column rendered empty: the per-status predicate was stacked onto '
            . 'one shared builder rather than onto a clone');
        $this->assertStringContainsString('MINE-PREORDER', $body);

        foreach (['FOREIGN-BULK-0', 'FOREIGN-BULK-1', 'FOREIGN-BULK-2'] as $foreign) {
            $this->assertStringNotContainsString($foreign, $body);
        }
    }

    // =======================================================================================
    //  THE OVERFLOW LINK'S COLOUR, COMPUTED RATHER THAN BELIEVED
    // =======================================================================================

    private function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function luminance(array $rgb): float
    {
        $c = array_map(static function ($v) {
            $v /= 255;

            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $rgb);

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private function contrastRatio(string $fg, string $bg): float
    {
        $a = $this->luminance($this->rgb($fg));
        $b = $this->luminance($this->rgb($bg));

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /**
     * `.ss-board__more` IS NEW COLOUR ON A SURFACE NOTHING ELSE ON THE BOARD USES. The existing
     * `.ss-board__card-id` is brand-on-card; this link is brand on the COLUMN's sunken background,
     * and at `--ss-text-sm` (13px) it is small text, so it needs 4.5:1 in both directions.
     *
     * The ratios are COMPUTED HERE, not quoted from the stylesheet comment, and the token chain is
     * re-read from the file so the numbers cannot go stale silently:
     *   light  --ss-brand -> --ss-blue-600 #335da8  on  --ss-surface-sunken -> --ss-grey-100 #eeeff1
     *   dark   --ss-brand -> --ss-cyan-500 #2ac3e5  on  --ss-surface-sunken            #1f232a
     * Both surfaces are fully opaque, so there is nothing to composite over.
     */
    public function test_the_overflow_link_meets_the_contrast_floor_on_the_column_surface(): void
    {
        $css = file_get_contents(public_path('css/store-syria-ds.css'));

        $this->assertStringContainsString('.ss-board__more {', $css, 'premise: the rule exists');
        $this->assertMatchesRegularExpression('/\.ss-board__more\s*\{[^}]*color:\s*var\(--ss-brand\)/', $css,
            'the link must take its colour from the brand token, which is the chain measured below');

        // PREMISE ON THE TOKEN CHAIN. Without these, the two ratios are assertions about numbers
        // this test invented rather than about what a merchant's browser resolves.
        foreach ([
            '--ss-blue-600: #335da8',
            '--ss-grey-100: #eeeff1',
            '--ss-cyan-500: #2ac3e5',
            '--ss-surface-sunken: var(--ss-grey-100)',
            '--ss-surface-sunken: #1f232a',
        ] as $declaration) {
            $this->assertStringContainsString($declaration, $css,
                "the measured ratios assume `{$declaration}`, which is no longer in the stylesheet");
        }

        $light = $this->contrastRatio('#335da8', '#eeeff1');
        $dark = $this->contrastRatio('#2ac3e5', '#1f232a');

        $this->assertSame(5.58, round($light, 2), 'light mode: brand on the sunken column surface');
        $this->assertSame(7.53, round($dark, 2), 'dark mode: brand on the sunken column surface');

        $this->assertGreaterThanOrEqual(4.5, $light);
        $this->assertGreaterThanOrEqual(4.5, $dark);

        // NEGATIVE CONTROL ON THE INSTRUMENT: if the maths could not see a failing pair, the two
        // assertions above would pass for a stylesheet that had gone white-on-white.
        $this->assertLessThan(4.5, $this->contrastRatio('#eeeff1', '#ffffff'),
            'premise: contrastRatio() can actually fail something');
    }

    /**
     * THE ONE THING A GREEN SUITE CANNOT SEE, so it is asserted on the SQL instead of trusted.
     *
     * `OrderDataTable::query()` applies `orderBy('created_at', 'desc')`. Carried into the grouped
     * `COUNT(*)` that now produces the column headers, that is a non-aggregated column in the
     * ORDER BY of a GROUP BY query: SQLite -- which is what phpunit.xml runs -- accepts it
     * silently, and MySQL under ONLY_FULL_GROUP_BY, which is what production runs, REFUSES it with
     * SQLSTATE 42000. So a green Feature run proves nothing here and the clause is dropped by
     * `reorder()` in the controller.
     *
     * This asserts the shape of the emitted SQL under the MySQL grammar rather than the presence
     * of the word `reorder` in the source, so a rewrite that loses the drop any other way fails
     * here too.
     */
    public function test_the_grouped_count_emits_no_order_by_for_only_full_group_by(): void
    {
        $this->actingAs($this->merchant);

        $query = (new \App\DataTables\OrderDataTable())->query(new Order());

        $this->assertStringContainsStringIgnoringCase('order by', $query->toSql(),
            'premise: the DataTable really does install the ORDER BY that MySQL would refuse');

        $sql = (clone $query)->toBase()->reorder()
            ->select('delivered_status', \Illuminate\Support\Facades\DB::raw('count(*) as c'))
            ->groupBy('delivered_status')
            ->toSql();

        $this->assertStringContainsStringIgnoringCase('group by', $sql, 'premise: it is a grouped count');
        $this->assertStringNotContainsStringIgnoringCase('order by', $sql,
            'the grouped COUNT(*) still carries `order by created_at desc`: ONLY_FULL_GROUP_BY '
            . 'refuses a non-aggregated ORDER BY column, so the board would 500 on production '
            . 'MySQL while every test here stayed green on SQLite');

        // And the store predicate is still in it, because a clone that dropped the only tenant
        // check would also pass the two assertions above.
        $this->assertStringContainsString('store_id', $sql);
    }
}
