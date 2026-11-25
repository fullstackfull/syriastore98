<?php

namespace Tests\Feature\Commerce;

use App\Http\Controllers\HomeController;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * GAP-135 / `P04-P4-03` -- THE SHARED FULFILMENT WRITER HAS NO STATE MACHINE.
 * =================================================================================================
 *
 * `Order::order_status_change()` is the writer FIVE doors share:
 *
 *   OrderController::order_status_change()            the merchant web screen and the order board
 *   Api\ApiController::order_status_change()          the storefront customer's own order
 *   OrderReplacement::cancelAndReplace()              cancel-and-reissue
 *   LotteryPrizes\LotteryOrderController              vendored, must not be edited
 *   the PartialPayments / PreOrder module arms        inside the method itself
 *
 * It carried exactly TWO transition guards, and both are about one arm each:
 *
 *   cancel  refused when `delivered_status != 0`      (app/Models/Order.php:702-706)
 *   return  refused when `delivered_status == 0`      (app/Models/Order.php:757-761)
 *
 * The other four arms -- `confirmed`, `pickedup`, `shipped`, `delivered` -- asked NOTHING about
 * where the order already was. They assigned the integer and saved. So the two states that must be
 * terminal were not:
 *
 *   CANCELLED (2) -> DELIVERED (1).  This is the money leg, and it is the one P4-F036 names from
 *   the shopper's end. `Order::product_return()` refuses unless `delivered_status == 1`
 *   (app/Models/Order.php:1064), and the storefront refund request is gated on delivery too
 *   (AccountProfileController.php:1461-1467). So a CANCELLED order -- one whose merchant
 *   entitlement `OrderEntitlementReversal` has already walked back, and whose stock the cancel
 *   door has already credited -- could be marched straight back into the return workflow, where
 *   `product_return()` REDUCES `final_price` (app/Models/Order.php:1453, :1468-1471).
 *
 *   RETURN (3) -> DELIVERED (1).  A returned order could be made deliverable again, which is a
 *   second pass at the same return workflow on the same goods.
 *
 *   DELIVERED (1) -> CONFIRMED / PICKED UP / SHIPPED.  Delivery un-happens, silently, and
 *   `delivery_date` stays stamped.
 *
 * WHAT THIS FILE DOES NOT CLAIM. The per-line `Order::LINE_STOCK_RESTORED` protocol already stops
 * the goods being counted in twice on that chain, and it is not being re-measured here; the
 * damage these cases pin is the ORDER STATE and the MONEY column, which no marker protects.
 *
 * AND WHAT THE FIX DELIBERATELY DOES NOT RESTRICT -- see
 * `App\Support\Orders\FulfilmentStateMachine`: backwards moves AMONG the in-flight states
 * (Shipped -> Confirmed, say) stay permitted, because a merchant correcting a mis-click is a
 * workflow question and CLAUDE.md puts a material business-rule change in the escalate set. Only
 * the transitions that re-open a settled order are refused.
 */
class OrderFulfilmentStateMachineTest extends SecurityTestCase
{
    use RefreshDatabase;

    /*
    |--------------------------------------------------------------------------
    | PREMISE -- without these, every case below could pass for the wrong reason
    |--------------------------------------------------------------------------
    */

    /**
     * PREMISE 1: the writer is reached and a permitted transition really lands.
     *
     * If this is red the fixture never got to the state question and nothing else here means
     * anything.
     */
    public function test_premise_the_writer_is_reached_and_a_permitted_transition_lands(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_PENDING);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'confirmed',
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            'Pending -> Confirmed was refused, so the fixture never reached the transition '
            . 'question. Answer: ' . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_CONFIRMED, $this->rawStatus($order),
            'Pending -> Confirmed did not land. ' . $this->snapshot($order));
    }

    /**
     * PREMISE 2: the fixture really can reach `delivered_status = 2` in the stored row.
     *
     * Every cancelled-order case below seeds the state directly rather than driving a cancel, so
     * that it measures the TRANSITION and not the cancel door. This proves the seed works.
     */
    public function test_premise_an_order_can_be_seeded_cancelled(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_CANCEL);

        $this->assertSame(HomeController::ORDER_STATUS_CANCEL, $this->rawStatus($order),
            'the cancelled seed did not land, so no case below is about a cancelled order. '
            . $this->snapshot($order));
    }

    /**
     * PREMISE 3: `product_return()` really is unlocked by `delivered_status == 1` and nothing
     * else -- which is why a forged delivery on a cancelled order is a money defect and not a
     * cosmetic one.
     */
    public function test_premise_product_return_is_gated_on_delivered_and_moves_money(): void
    {
        [$store, $order] = $this->orderWithOneLine(HomeController::ORDER_STATUS_CANCEL, 3, 30.0);
        $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->firstOrFail();

        $refused = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('error', $refused['status'] ?? null,
            'product_return accepted a CANCELLED order, so the delivery gate this file relies on '
            . 'does not exist. Answer: ' . json_encode($refused));

        // And from Delivered it is accepted and it moves `final_price`.
        $this->setStatusRaw($order, HomeController::ORDER_STATUS_DELIVERED);

        $accepted = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('success', $accepted['status'] ?? null,
            'product_return refused a DELIVERED order, so the premise is wrong. Answer: '
            . json_encode($accepted));

        $this->assertLessThan(100.0, (float) $this->rawColumn($order, 'final_price'),
            'product_return did not reduce final_price, so the money consequence this file '
            . 'asserts does not exist. ' . $this->snapshot($order));
    }

    /*
    |--------------------------------------------------------------------------
    | REGRESSION PINS -- the two guards that already existed must survive the machine
    |--------------------------------------------------------------------------
    */

    public function test_cancel_is_still_refused_from_every_non_pending_state(): void
    {
        foreach ([
            HomeController::ORDER_STATUS_DELIVERED,
            HomeController::ORDER_STATUS_CANCEL,
            HomeController::ORDER_STATUS_RETURN,
            HomeController::ORDER_STATUS_CONFIRMED,
            HomeController::ORDER_STATUS_PICKED_UP,
            HomeController::ORDER_STATUS_SHIPPED,
        ] as $from) {
            $order = $this->orderInState($from);

            $answer = Order::order_status_change([
                'order_id' => $order->id,
                'order_status' => 'cancel',
            ]);

            $this->assertSame('error', $answer['status'] ?? null,
                "cancel was accepted from state {$from}. Answer: " . json_encode($answer));

            $this->assertSame($from, $this->rawStatus($order),
                "cancel from state {$from} wrote the row. " . $this->snapshot($order));
        }
    }

    public function test_return_is_still_refused_from_pending(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_PENDING);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'return',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            'return was accepted from Pending. Answer: ' . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_PENDING, $this->rawStatus($order),
            'return from Pending wrote the row. ' . $this->snapshot($order));
    }

    /** The forward path a merchant actually walks must stay walkable, every step. */
    public function test_the_whole_forward_fulfilment_path_is_still_permitted(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_PENDING);

        foreach ([
            ['confirmed', HomeController::ORDER_STATUS_CONFIRMED],
            ['pickedup', HomeController::ORDER_STATUS_PICKED_UP],
            ['shipped', HomeController::ORDER_STATUS_SHIPPED],
            ['delivered', HomeController::ORDER_STATUS_DELIVERED],
            ['return', HomeController::ORDER_STATUS_RETURN],
        ] as [$word, $expected]) {
            $answer = Order::order_status_change([
                'order_id' => $order->id,
                'order_status' => $word,
            ]);

            $this->assertSame('success', $answer['status'] ?? null,
                "the forward step '{$word}' was refused. Answer: " . json_encode($answer));

            $this->assertSame($expected, $this->rawStatus($order),
                "the forward step '{$word}' did not land. " . $this->snapshot($order));
        }
    }

    /** A merchant correcting a mis-click among the in-flight states is deliberately NOT refused. */
    public function test_a_correction_among_the_in_flight_states_is_not_refused(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_SHIPPED);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'confirmed',
        ]);

        $this->assertSame('success', $answer['status'] ?? null,
            'Shipped -> Confirmed was refused. That is a workflow restriction this change '
            . 'deliberately does not make -- see FulfilmentStateMachine. Answer: '
            . json_encode($answer));
    }

    /*
    |--------------------------------------------------------------------------
    | THE DEFECT -- a settled order must not be re-opened
    |--------------------------------------------------------------------------
    */

    /**
     * CASE 1, the money leg. A cancelled order must not be marked delivered.
     *
     * With the cancelled state terminal, `product_return()`'s delivery gate can never be satisfied
     * on a cancelled order, so the `final_price` reduction below it is unreachable too. Both are
     * asserted, because refusing the transition and then still letting the money move would be a
     * fix in name only.
     */
    public function test_a_cancelled_order_cannot_be_marked_delivered(): void
    {
        [$store, $order] = $this->orderWithOneLine(HomeController::ORDER_STATUS_CANCEL, 3, 30.0);
        $product = Product::withoutGlobalScopes()->where('store_id', $store->id)->firstOrFail();
        $priceBefore = (float) $this->rawColumn($order, 'final_price');

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'delivered',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            'a CANCELLED order was marked Delivered, which re-opens the return and refund '
            . 'workflow on an order whose entitlement has already been reversed. Answer: '
            . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_CANCEL, $this->rawStatus($order),
            'the refused delivery still wrote the row. ' . $this->snapshot($order));

        // AND THE CONSEQUENCE IS REALLY OUT OF REACH, not merely one step further away.
        $returnAnswer = Order::product_return([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame('error', $returnAnswer['status'] ?? null,
            'product_return ran on the cancelled order anyway. Answer: '
            . json_encode($returnAnswer));

        $this->assertSame($priceBefore, (float) $this->rawColumn($order, 'final_price'),
            'final_price moved on a cancelled order. ' . $this->snapshot($order));
    }

    /** CASE 2. A cancelled order must not enter the return workflow either. */
    public function test_a_cancelled_order_cannot_be_returned(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_CANCEL);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'return',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            'a CANCELLED order was moved into Return, where the storefront refund request '
            . 'becomes available. Answer: ' . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_CANCEL, $this->rawStatus($order),
            'the refused return still wrote the row. ' . $this->snapshot($order));

        $this->assertSame(0, (int) $this->rawColumn($order, 'return_status'),
            'return_status was stamped on a cancelled order. ' . $this->snapshot($order));
    }

    /**
     * CASE 3. Nor may a cancelled order be dragged back into the in-flight states, which is what
     * erases the cancellation from every list and board that reads `delivered_status`.
     *
     * @dataProvider inFlightWords
     */
    public function test_a_cancelled_order_cannot_re_enter_the_in_flight_states(string $word): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_CANCEL);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => $word,
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            "a CANCELLED order was moved to '{$word}'. Answer: " . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_CANCEL, $this->rawStatus($order),
            "the refused '{$word}' still wrote the row. " . $this->snapshot($order));
    }

    /** CASE 4. A returned order must not be made deliverable again. */
    public function test_a_returned_order_cannot_be_marked_delivered_again(): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_RETURN, ['return_status' => 2]);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => 'delivered',
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            'a RETURNED order was marked Delivered, which is a second pass at the return '
            . 'workflow on the same goods. Answer: ' . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_RETURN, $this->rawStatus($order),
            'the refused delivery still wrote the row. ' . $this->snapshot($order));
    }

    /**
     * CASE 5. Nor back into the in-flight states.
     *
     * @dataProvider inFlightWords
     */
    public function test_a_returned_order_cannot_re_enter_the_in_flight_states(string $word): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_RETURN, ['return_status' => 2]);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => $word,
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            "a RETURNED order was moved to '{$word}'. Answer: " . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_RETURN, $this->rawStatus($order),
            "the refused '{$word}' still wrote the row. " . $this->snapshot($order));
    }

    /**
     * CASE 6. Delivery does not un-happen.
     *
     * @dataProvider inFlightWords
     */
    public function test_a_delivered_order_cannot_be_moved_back_into_the_in_flight_states(string $word): void
    {
        $order = $this->orderInState(HomeController::ORDER_STATUS_DELIVERED);

        $answer = Order::order_status_change([
            'order_id' => $order->id,
            'order_status' => $word,
        ]);

        $this->assertSame('error', $answer['status'] ?? null,
            "a DELIVERED order was moved back to '{$word}'. Answer: " . json_encode($answer));

        $this->assertSame(HomeController::ORDER_STATUS_DELIVERED, $this->rawStatus($order),
            "the refused '{$word}' still wrote the row. " . $this->snapshot($order));
    }

    /** `confirmed`, `pickedup`, `shipped` -- the three in-flight words the writer accepts. */
    public static function inFlightWords(): array
    {
        return [
            'confirmed' => ['confirmed'],
            'pickedup' => ['pickedup'],
            'shipped' => ['shipped'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Fixtures and raw readers
    |--------------------------------------------------------------------------
    */

    private function orderInState(int $status, array $extra = []): Order
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);

        return $this->makeOrder($store, array_merge([
            'delivered_status' => $status,
            'return_status' => 0,
            'product_json' => '[]',
            'final_price' => 100,
        ], $extra));
    }

    /**
     * An order in `$status` carrying one tracked line, so that `product_return()` has something to
     * act on.
     *
     * Written through Eloquent, never `DB::table()->update()`: `Order` is `Cachable`, so a raw
     * write would leave `Order::find()` -- which is what the writer under test uses -- serving the
     * pre-write row for the rest of the test.
     *
     * @return array{0: Store, 1: Order}
     */
    private function orderWithOneLine(int $status, int $qty, float $lineTotal): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);

        $product = Product::forceCreate([
            'name' => 'returnable',
            'slug' => 'returnable-' . uniqid(),
            'price' => $lineTotal / max($qty, 1),
            'sale_price' => $lineTotal / max($qty, 1),
            'cost_price' => 1,
            'product_stock' => 10,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);

        $order = $this->makeOrder($store, [
            'delivered_status' => $status,
            'return_status' => 0,
            'final_price' => 100,
            /*
             * THE LINE SHAPE THE SHIPPED CART WRITES, not an invented one:
             * `ProductController::addToCart` builds every line with `final_price` and `return`
             * (ProductController.php:5088-5103), and `Order::performProductReturn()` reads both
             * WITHOUT a default (app/Models/Order.php:1352-1354). A line missing either is an
             * `ErrorException` there -- measured while writing this fixture, and recorded.
             */
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'variant_id' => 0,
                'qty' => $qty,
                'final_price' => $lineTotal,
                'return' => 0,
            ]]),
        ]);

        return [$store, $order];
    }

    /** Eloquent, so the `Cachable` cache is flushed -- see orderWithOneLine's note. */
    private function setStatusRaw(Order $order, int $status): void
    {
        $fresh = Order::withoutGlobalScopes()->findOrFail($order->id);
        $fresh->delivered_status = $status;
        $fresh->save();
    }

    private function rawStatus(Order $order): int
    {
        return (int) DB::table('orders')->where('id', $order->id)->value('delivered_status');
    }

    private function rawColumn(Order $order, string $column)
    {
        return DB::table('orders')->where('id', $order->id)->value($column);
    }

    /** Every figure a failure here needs, read off the stored row and never off the model. */
    private function snapshot(Order $order): string
    {
        $row = DB::table('orders')->where('id', $order->id)->first();

        return 'MEASURED ROW: delivered_status=' . var_export($row->delivered_status ?? null, true)
            . ' return_status=' . var_export($row->return_status ?? null, true)
            . ' final_price=' . var_export($row->final_price ?? null, true)
            . ' return_price=' . var_export($row->return_price ?? null, true)
            . ' cancel_date=' . var_export($row->cancel_date ?? null, true)
            . ' delivery_date=' . var_export($row->delivery_date ?? null, true);
    }
}
