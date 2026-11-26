<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use App\Support\Orders\FulfilmentStateMachine as SM;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * A STORE'S OWN CUSTOMER PICKED THEIR ORDER'S FULFILMENT STATE AT CREATION.  GAP-258 / `P04-P1-03`.
 * =================================================================================================
 *
 * `Api\ApiController::ordersave()` -- `POST api/{slug}/order-save` -- assigned
 *
 *     $order->delivered_status = !empty($request->delivered_status) ? $request->delivered_status : '-';
 *
 * straight from unvalidated request input. That handler's rule array holds exactly two keys,
 * `payment_type` and `delivery_id`, and neither is this one, so nothing between the socket and the
 * column asked what the value was.
 *
 * THE PRINCIPAL IS A REAL, ORDINARY SHOPPER. The route carries `custom.auth` and the handler
 * refuses unless `resolveStoreCustomer()` resolves, so this is not the guard-class trap: it needs a
 * genuine `Customer` of the store holding a Sanctum token, which self-registration hands out. That
 * is what makes it worth closing rather than scheduling -- the authorised path is the attack path.
 *
 * WHAT THE THREE HOSTILE VALUES BUY, and each is a separate case below:
 *
 *   `delivered_status = 1`  the order is born DELIVERED. `Order::product_return()` refuses unless
 *                           `delivered_status == 1` and then REDUCES `final_price`, and the
 *                           storefront refund request is gated on delivery the same way. So an
 *                           order for goods that have never moved enters the return-and-refund
 *                           workflow on arrival.
 *   `delivered_status = 3`  the row lands `(delivered_status = '3', return_status = 0)`. Both the
 *                           web Approve and Reject controls are gated on `return_status == 1`, so
 *                           the merchant cannot act on it from either direction -- a return that
 *                           exists and cannot be resolved.
 *   an arbitrary string     the column is a plain `integer` with no enum, constant or check
 *                           constraint (`2024_01_23_061635_create_orders_table.php:33`), and
 *                           nothing refused `'wat'`. This is the same door the literal `'-'`
 *                           already comes through, but under caller control and unbounded.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY EACH ASSERTION IS SHAPED THE WAY IT IS
 * -------------------------------------------------------------------------------------------------
 *
 * READ RAW. `Order` is `Cachable` (CLAUDE.md: 79 of 95 models are, and three measured false
 * readings came from an Eloquent read serving a pre-write row), so every state assertion here goes
 * through `DB::table('orders')`. The Eloquent read is used only where a value is being WRITTEN.
 *
 * ASSERT THE DEFAULT, NOT MERELY THE ABSENCE OF THE POSTED VALUE. `assertNotSame('1', ...)` is also
 * satisfied by a handler that 500s, by a route that does not exist, and by a refusal that writes no
 * order at all -- three of the ways CLAUDE.md records tests passing for the wrong reason. So each
 * hostile case asserts the exact value the column DOES hold, and `assertTheOrderWasReallyWritten()`
 * first establishes that an order was written, by this request, for this store, with the basket's
 * money on it. The handler wraps its own body in `try`/`catch`, so a 200 is not by itself evidence
 * that it ran to completion.
 *
 * AND PROVE THE ENGINE WOULD HAVE ACCEPTED THE HOSTILE VALUES.
 * `test_the_premise_the_column_really_does_accept_every_hostile_value` writes all three directly and
 * reads them back. Without it, "the posted value is not in the column" could be the database
 * refusing a string in an INTEGER column rather than the guard refusing it -- SQLite's affinity is
 * exactly the engine difference CLAUDE.md says hides real defects, and here it works the other way:
 * it is permissive enough to have stored the attack, which is what makes the fix load-bearing
 * rather than incidental.
 *
 * THE DEFAULT STAYS THE LITERAL `'-'`, DELIBERATELY, and that is a preservation rather than a
 * choice. It is what every request that does not post the field already receives, so the fix is a
 * no-op for legitimate traffic; and four separate prose citations elsewhere in the application name
 * this exact line as the one shipped handler that writes it
 * (`App\Support\Orders\FulfilmentStateMachine:68` and `:273`,
 * `Api\DeliverySyriaOrderController:276`, and this suite's own
 * `DeliverySyriaOrderTransitionTest:603`), with `FulfilmentStateMachine::stateOf()` answering `null`
 * for it on purpose and `OrderDataTable:334` branching on it. GAP-254 settled that an unrecognised
 * status keeps its own board bucket rather than being folded into Pending. Changing the default to
 * `0` here would be a second, product-visible change riding along inside a security fix, and would
 * falsify those four citations in files this package may not touch. It is reported as an open
 * question instead.
 */
class StorefrontOrderFulfilmentStateNotCallerChosenTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;

    /** The value every order-save order carries today when the field is not posted. */
    private const DEFAULT_STATUS = '-';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    /**
     * A merchant with a store, a shipping method, and the stock settings `ordersave` reads.
     *
     * @return array{0: User, 1: Store, 2: int}
     */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert([
                'name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        (new \App\Models\Setting)->flushCache();

        // shipping_methods.zone_id is NOT NULL with a foreign key, so the zone comes first.
        $zoneId = (int) DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'gap258-zone', 'shipping_method' => 'flat rate',
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (int) DB::table('shipping_methods')->insertGetId([
            'method_name' => 'Flat Rate', 'zone_id' => $zoneId, 'cost' => '0',
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $methodId];
    }

    private function shopperWithBasket(Store $store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'gap258-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id,
        ]);

        return $customer;
    }

    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_address' => '1 St', 'billing_user_telephone' => '555',
            'billing_postecode' => '11111', 'billing_country' => 'X',
            'billing_state' => 'Y', 'billing_city' => 'Z',
            'delivery_address' => '1 St', 'delivery_postcode' => '11111',
            'delivery_country' => 'X', 'delivery_state' => 'Y', 'delivery_city' => 'Z',
        ];
    }

    /**
     * Drive the real route with a real Sanctum token, with whatever extra keys the case is about.
     *
     * @param  array<string, mixed>  $extra
     */
    private function saveOrder(Store $store, Customer $customer, int $methodId, array $extra = [])
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/order-save', array_merge([
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $methodId,
                'method_id' => $methodId,
            ], $extra));
    }

    /** The order row as the table holds it -- never through Eloquent, because `Order` is `Cachable`. */
    private function rawOrderIn(Store $store): ?object
    {
        return DB::table('orders')->where('store_id', $store->id)->orderByDesc('id')->first();
    }

    /**
     * The premise every hostile case rests on: the request really did reach the writer and produce
     * an order. Without this, "the column does not hold 1" is satisfied by a 404, a 500, or a
     * refusal.
     */
    private function assertTheOrderWasReallyWritten($response, Store $store): object
    {
        $this->assertNotSame(404, $response->getStatusCode(),
            'order-save answered 404, so the route this case drives does not exist and nothing below '
            . 'is evidence about the handler.');
        $this->assertSame(200, $response->getStatusCode(),
            'order-save answered ' . $response->getStatusCode() . '. The handler wraps its body in '
            . 'try/catch, so anything other than 200 means the request did not reach the writer.');
        $this->assertSame(1, $response->json('status'),
            'order-save answered {status: 0} -- it refused the request, so no order was written and '
            . 'the column assertion below would hold for the wrong reason. Message: '
            . json_encode($response->json('message')));

        $order = $this->rawOrderIn($store);

        $this->assertNotNull($order, 'Premise: no order row exists for this store.');
        $this->assertEqualsWithDelta(self::BASKET, (float) $order->final_price, 0.005,
            'Premise: the order was written but not for this basket (final_price '
            . ($order->final_price ?? '?') . ' against a 100.00 basket), so the writer did not run '
            . 'the way production runs it.');

        return $order;
    }

    // ---------------------------------------------------------------------------------------------
    //  THE PREMISES
    // ---------------------------------------------------------------------------------------------

    /**
     * THE ENGINE WOULD HAVE TAKEN ALL THREE. If the column refused a string, the hostile cases
     * below would pass with the guard deleted and this file would be worthless.
     *
     * Written through Eloquent, not `DB::table()->update()`: `Order` is `Cachable` and CLAUDE.md
     * records three measured false readings from raw writes leaving Eloquent serving the pre-write
     * row for the rest of the test.
     */
    public function test_the_premise_the_column_really_does_accept_every_hostile_value(): void
    {
        [, $store] = $this->tenant();

        foreach (['1', '3', 'wat', '-'] as $value) {
            $order = $this->makeOrder($store);
            $order->delivered_status = $value;
            $order->save();

            $this->assertSame(
                $value,
                (string) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
                "The `orders.delivered_status` column did not store '{$value}'. The hostile cases in "
                . 'this file assert that a posted value is NOT in the column; if the engine refuses '
                . 'the value anyway, those assertions hold whether or not the handler guards it.'
            );
        }
    }

    /**
     * AND THE ROUTE REALLY IS CLOSED TO EVERYONE ELSE, so this file is measuring the right
     * principal. GAP-258 is not the guard-class trap: `ordersave` has no `auth('customers')`
     * fallback, it 401s through `resolveStoreCustomer()`. A merchant's token passes `custom.auth`
     * (CustomAuth logs a `User` into the `web` guard) and must still be refused here -- which is
     * why the hostile cases are driven with a genuine shopper's token and not a merchant's.
     */
    public function test_the_premise_only_a_store_customer_can_reach_this_writer(): void
    {
        [$merchant, $store, $methodId] = $this->tenant();
        $this->shopperWithBasket($store);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $merchant->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/order-save', [
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $methodId,
                'method_id' => $methodId,
                'delivered_status' => SM::STATE_DELIVERED,
            ]);

        $this->assertSame(401, $response->getStatusCode(),
            'A merchant token was not refused by order-save, so this handler DOES have a non-customer '
            . 'path and the hostile cases below are driving the wrong principal.');
        $this->assertNull($this->rawOrderIn($store),
            'A merchant token wrote an order through order-save.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE DEFECT
    // ---------------------------------------------------------------------------------------------

    /**
     * `delivered_status = 1` -- the order is born DELIVERED, which is the state
     * `Order::product_return()` requires before it REDUCES `final_price`.
     */
    public function test_a_shopper_cannot_have_their_order_born_delivered(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId, [
            'delivered_status' => SM::STATE_DELIVERED,
        ]);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertNotSame('1', (string) $order->delivered_status,
            'A shopper POSTed delivered_status=1 to api/{slug}/order-save and their order was born '
            . 'DELIVERED. Order::product_return() refuses unless delivered_status == 1 and then '
            . 'REDUCES final_price, so this order can be returned and refunded for goods that never '
            . 'moved.');
        $this->assertSame(self::DEFAULT_STATUS, (string) $order->delivered_status,
            'orders.delivered_status holds ' . var_export($order->delivered_status, true)
            . '. The handler must not read this column from the request at all; a new order keeps '
            . "the established default '" . self::DEFAULT_STATUS . "'.");
    }

    /**
     * `delivered_status = 3` -- a return the merchant cannot resolve from either direction, because
     * the Approve and Reject controls are both gated on `return_status == 1` and this row carries
     * `return_status = 0`.
     */
    public function test_a_shopper_cannot_have_their_order_born_in_the_return_state(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId, [
            'delivered_status' => SM::STATE_RETURN,
        ]);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertNotSame('3', (string) $order->delivered_status,
            'A shopper POSTed delivered_status=3 and their order was born in the RETURN state with '
            . 'return_status = ' . var_export($order->return_status, true) . '. Both the web '
            . 'Approve and Reject controls are gated on return_status == 1, so the merchant cannot '
            . 'act on this row from either direction.');
        $this->assertSame(self::DEFAULT_STATUS, (string) $order->delivered_status,
            'orders.delivered_status holds ' . var_export($order->delivered_status, true) . '.');
    }

    /**
     * AN ARBITRARY STRING. The column is a plain `integer` with no enum or check constraint, and
     * this is how a value outside the nine-value ladder gets in under caller control.
     */
    public function test_an_arbitrary_string_does_not_reach_the_fulfilment_column(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId, [
            'delivered_status' => 'wat',
        ]);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertNotSame('wat', (string) $order->delivered_status,
            'A shopper POSTed delivered_status=wat and the string reached orders.delivered_status, '
            . 'an integer column. FulfilmentStateMachine::stateOf() answers null for such a value '
            . 'and the order board cannot bucket it.');
        $this->assertSame(self::DEFAULT_STATUS, (string) $order->delivered_status,
            'orders.delivered_status holds ' . var_export($order->delivered_status, true) . '.');
    }

    /**
     * AND A VALUE INSIDE THE LADDER IS REFUSED TOO, which is the case that separates "the handler
     * validates against the nine constants" from "the handler does not read the request".
     *
     * `7` is `STATE_PARTIALLY_PAID` -- a PAYMENT state, not a fulfilment one -- so it is a legal
     * member of the vocabulary that a shopper still has no business naming. A fix that validated
     * against the column's own constants would let this through; this file pins the stronger shape.
     */
    public function test_even_a_vocabulary_value_is_not_the_shoppers_to_choose(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId, [
            'delivered_status' => SM::STATE_PARTIALLY_PAID,
        ]);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertSame(self::DEFAULT_STATUS, (string) $order->delivered_status,
            'orders.delivered_status holds ' . var_export($order->delivered_status, true)
            . ' after a shopper posted 7 (Partially Paid, a payment state). No posted value may '
            . 'reach this column, whether or not it is in the vocabulary.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE CONTROL
    // ---------------------------------------------------------------------------------------------

    /**
     * THE DEFAULT IS UNCHANGED FOR A CLIENT THAT POSTS NOTHING. This is the whole compatibility
     * claim of the fix, and it is also what makes the four assertions above meaningful rather than
     * tautological: the column's value is the SAME in both directions, so the hostile cases are
     * measuring that the posted value was discarded and not that the writer stopped running.
     *
     * The field is in no client and in no contract: the documented request body for this endpoint
     * (`packages/workdo/Api/src/documentation/theme.json:8360-8384`) does not list it, and the
     * shipped Flutter admin app sends `delivered_status` only to `/orders/update-status` and as an
     * `&delivered_status=` list filter -- never to this route, which it does not call at all.
     */
    public function test_an_order_placed_without_the_field_keeps_the_established_default(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertSame(self::DEFAULT_STATUS, (string) $order->delivered_status,
            "orders.delivered_status holds " . var_export($order->delivered_status, true)
            . " for an order placed with no delivered_status posted. The established default is '"
            . self::DEFAULT_STATUS . "' -- four prose citations elsewhere in the application name "
            . 'this handler as the one that writes it, FulfilmentStateMachine::stateOf() answers '
            . 'null for it on purpose, and GAP-254 settled that such a row keeps its own board '
            . 'bucket. Do not normalise it as part of this fix.');
    }

    /**
     * AND THE REST OF THE ORDER IS UNTOUCHED. A fix that stopped reading one request key must not
     * have stopped reading the neighbouring ones, which sit on the same four lines of the writer.
     */
    public function test_the_neighbouring_request_driven_columns_still_work(): void
    {
        [, $store, $methodId] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $response = $this->saveOrder($store, $customer, $methodId, [
            'delivered_status' => SM::STATE_DELIVERED,
            'shipping_comment' => 'leave at the gate',
            'additional_note' => 'ring twice',
            'payment_comment' => 'exact change',
        ]);
        $order = $this->assertTheOrderWasReallyWritten($response, $store);

        $this->assertSame('leave at the gate', (string) $order->delivery_comment,
            'orders.delivery_comment no longer reflects the posted shipping_comment, so the fix took '
            . 'a neighbouring line with it.');
        $this->assertSame('ring twice', (string) $order->additional_note,
            'orders.additional_note no longer reflects the posted additional_note.');
        $this->assertSame('exact change', (string) $order->payment_comment,
            'orders.payment_comment no longer reflects the posted payment_comment.');
        $this->assertSame((string) $methodId, (string) $order->delivery_id,
            'orders.delivery_id no longer reflects the posted method_id.');
    }
}
