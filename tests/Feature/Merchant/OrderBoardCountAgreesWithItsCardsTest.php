<?php

namespace Tests\Feature\Merchant;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-254 — THE BOARD COUNTED AN ORDER IT COULD NEVER DRAW.
 * =================================================================================================
 * `order_kanban_view()` builds its column counts with one grouped query and its cards with one
 * query per column. The counts used to be keyed `(int) $s`, and `Api\ApiController` writes the
 * literal `'-'` into `orders.delivered_status` for a storefront order placed without one — so
 * `(int) '-'` is 0 and such a row was FOLDED into Pending's count, while the bucket query is
 * `where('delivered_status', $status)` and `'-' = 0` is FALSE on both engines. The Pending header
 * read `(1)`, a "See all 1 in the list" overflow link appeared, and no card rendered.
 *
 * TWO RULES HAVE TO HOLD TOGETHER, AND THE FIRST DRAFT OF THE FIX BROKE THE SECOND. Routing the
 * count through `FulfilmentStateMachine::stateOf()` — which answers null for anything outside 0..8
 * — made the count agree with the bucket by DROPPING the row, and
 * `OrderKanbanTest::test_an_unknown_status_is_shown_rather_than_dropped` refused it: this board
 * deliberately iterates `$counts->keys()` because that is "every value the table actually holds",
 * so an unrecognised status gets its own bucket and the order stays reachable. Dropping it would
 * have traded a wrong count for a missing order.
 *
 * Keeping the RAW key satisfies both: the value gets its own entry, so the back door renders it,
 * and Pending's count no longer includes it. This file asserts both halves, because a fix that
 * satisfies one is the shape of the two drafts that came before it.
 *
 * NOT A CLAIM ABOUT THE LABEL. What an unrecognised bucket should be CALLED is the half of GAP-254
 * that is a product decision, and nothing here asserts it.
 */
final class OrderBoardCountAgreesWithItsCardsTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The literal one shipped handler really writes. */
    private const UNRECOGNISED = '-';

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

    private function html(): string
    {
        $response = $this->actingAs($this->merchant)->get(route('order.kanban'));

        $this->assertSame(200, $response->status(),
            'premise: the board rendered. Every assertion below is vacuous on a redirect.');

        return $response->getContent();
    }

    /** An order parked at Pending and then moved to the unrecognised value by a raw write. */
    private function orderAtTheUnrecognisedValue(string $reference): Order
    {
        $order = $this->makeOrder($this->store, [
            'product_order_id' => $reference,
            'delivered_status' => HomeController::ORDER_STATUS_PENDING,
        ]);

        DB::table('orders')->where('id', $order->id)
            ->update(['delivered_status' => self::UNRECOGNISED]);
        (new Order)->flushCache();

        $this->assertSame(self::UNRECOGNISED, (string) DB::table('orders')
            ->where('id', $order->id)->value('delivered_status'),
            'premise: the column really holds the non-numeric literal -- SQLite stores it, which is '
            . 'what makes this path reachable in the suite at all');

        return $order;
    }

    /**
     * THE PREMISE, in two parts, because each one is a reason an earlier draft proved nothing.
     *
     * First: the machine really does refuse to name this value, which is what makes it the
     * interesting fixture rather than just an odd integer. Second: `(int)` of it really is 0, which
     * is the whole mechanism — if PHP ever stopped coercing it the defect would vanish and this file
     * would be asserting against a bug that cannot happen.
     */
    public function test_the_premise_that_the_fixture_value_casts_to_pending_and_names_no_state(): void
    {
        $this->assertNull(FulfilmentStateMachine::stateOf(self::UNRECOGNISED),
            'the machine now names a state for this value, so it is no longer unrecognised and this '
            . 'file needs a different fixture rather than a passing grade');

        $this->assertSame(HomeController::ORDER_STATUS_PENDING, (int) self::UNRECOGNISED,
            'the cast no longer folds this value into Pending, so the defect this file is about '
            . 'cannot occur and these assertions are vacuous');

        $this->assertNotSame(self::UNRECOGNISED, (string) HomeController::ORDER_STATUS_PENDING,
            'premise: the raw value and Pending are distinct keys, which is what the fix relies on');
    }

    /**
     * THE DEFECT: Pending must not count a row whose status it does not hold.
     *
     * Asserted on the RENDERED page rather than on the controller's variables, because the count a
     * merchant reads is the one in the heading. The order is created at Pending and then moved to
     * the unrecognised value through a raw write, so the fixture's own creation path cannot be what
     * produces the state.
     */
    public function test_an_unrecognised_status_is_not_counted_in_the_pending_column(): void
    {
        $this->orderAtTheUnrecognisedValue('GAP254-NOT-COUNTED');

        $html = $this->html();

        /*
         * The Pending column's own count, read from its aria-label rather than from anywhere the
         * number also appears for another reason. A whole-page search for `(1)` would be satisfied
         * by any other column that legitimately holds one order.
         */
        $this->assertMatchesRegularExpression('/aria-label="[^"]*\(0\)"/', $html,
            'no column reports a count of zero, so the Pending column is still claiming the '
            . 'unrecognised row. `(int) \'-\'` is 0 and the bucket query matches nothing, so the '
            . 'heading counted an order the board cannot draw.');
    }

    /**
     * AND THE OTHER RULE: the order is still reachable. This is the one the first draft broke.
     *
     * `OrderKanbanTest::test_an_unknown_status_is_shown_rather_than_dropped` establishes it for the
     * integer 99; this asserts it for the literal the shipped writer actually produces, because a
     * fix that keyed the count on `stateOf()` would have passed that case — 99 and `'-'` are both
     * unrecognised, but only one of them casts to 0 — and stranded this row.
     */
    public function test_an_order_at_an_unrecognised_status_is_still_reachable_on_the_board(): void
    {
        $reference = 'GAP254-REACHABLE';

        $this->orderAtTheUnrecognisedValue($reference);

        $this->assertStringContainsString($reference, $this->html(),
            'the order vanished from the board. Making the count agree with the bucket by DROPPING '
            . 'unrecognised values trades a wrong count for a missing order, and this board\'s '
            . 'established rule is the opposite: `$counts->keys()` is every value the table holds, '
            . 'so such a row keeps its own bucket and stays reachable.');
    }
}
