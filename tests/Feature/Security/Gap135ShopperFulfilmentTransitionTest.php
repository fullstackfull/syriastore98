<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
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
 * A SHOPPER DECLARED THEIR OWN ORDER DELIVERED.  GAP-135 / `P04-P4-03`, finding `P4-F036`.
 * =================================================================================================
 *
 * `POST api/{slug}/order-status-change` (`routes/api.php:145` -> `Api\ApiController`) resolves WHO
 * the caller is and then takes WHICH status to write straight from the request:
 *
 *     $order = $this->resolveMutableOrder($request->order_id, $store);   // ownership: closed
 *     $data['order_status'] = $request->order_status;                    // vocabulary: open
 *
 * So a store's own customer, holding the Sanctum token self-registration hands out, could POST
 * `order_status=delivered` against their own Pending order and have the row read DELIVERED. Also
 * `confirmed`, `pickedup`, `shipped` and `return` -- every word the shared writer's branch chain
 * matches. The ownership fix (AUTHZ-3) says so itself: *"WHAT IS STILL OPEN ... WHICH status each
 * principal may set is not constrained. A customer can still mark their own order 'pickedup'."*
 *
 * WHY IT IS MONEY AND NOT A COSMETIC ROW. `Order::product_return()` refuses unless
 * `delivered_status == 1` and then CREDITS MERCHANT STOCK and REDUCES `final_price`; the storefront
 * refund request (`AccountProfileController::order_refund_request`) is gated on delivery the same
 * way. The shopper holds the token for `api/{slug}/product-return` too. So the two requests compose:
 * declare the goods delivered, then return them -- for goods that never moved.
 * `test_a_shopper_cannot_unlock_the_stock_credit_by_delivering_their_own_order` drives exactly that
 * pair and asserts the stock and the money.
 *
 * AND IT COMPOUNDS WITH OWNER DECISION H2, which is why this is the clause that was left.
 * `FORWARD_ONLY` made DELIVERED a state nothing leaves, so after H2 a shopper could push their own
 * order to DELIVERED and NOBODY -- merchant, driver or operator -- could move it back. H2's own
 * cost note says it plainly: *"closing `P4-F036` is what removes the sharp edge."*
 *
 * -------------------------------------------------------------------------------------------------
 * WHAT THIS FILE PINS, AND WHY EACH CASE IS SHAPED THE WAY IT IS
 * -------------------------------------------------------------------------------------------------
 *
 * THREE PRINCIPALS, NOT ONE. `App\Http\Middleware\CustomAuth` logs a Sanctum token's owner into
 * whichever guard matches its tokenable class -- `Customer` -> `customers`, `User` -> `web`,
 * `DeliveryBoy` -> `deliveryboy` -- so all three reach this handler, and CLAUDE.md records that a
 * customer-token probe cannot test a guard-class question at all. The refusal must fire for the
 * shopper and must NOT fire for the merchant, the assigned driver or the platform operator, who set
 * these statuses as part of their job. Every one of the four is driven here against the same
 * transition, so a guard that refuses everybody passes nothing.
 *
 * THE OPERATOR IS DRIVEN BECAUSE `isAbleTo()` REFUSES THEM. There is no super-admin bypass in this
 * codebase (operator status is a string comparison on `users.type`), so "the operator still works"
 * is a real question on every authorization change here and not a formality.
 *
 * READ RAW. `Order` and `Product` are both `Cachable` (79 of 95 models are), and CLAUDE.md records
 * three measured false readings where an Eloquent read served the pre-write row for the rest of a
 * test. Every state assertion in this file goes through `DB::table()`.
 *
 * ASSERT THE REFUSAL'S OWN REASON, NOT JUST "NOTHING CHANGED". Every denial on this door is HTTP
 * 200 with `status: 0` (`ApiResponser::error()`; a 401 would force a logout in the shipped Flutter
 * client), so "the row did not change" is equally satisfied by a 404, by an ownership refusal, by
 * the writer's own "that order status is not recognised" and by a crash the handler swallowed. Each
 * case therefore asserts the exact message -- `This action is not available.`, the string the sibling
 * SHOPPER door already answers (`OrderController::status_cancel`, finding `P4-F235`) and the one
 * refusal in this family that is already in `resources/lang/en.json` and `ar.json`, so an Arabic
 * shopper reads Arabic. A mutant that swaps one refusal for another is visible.
 *
 * AND THE MATRIX WOULD HAVE ALLOWED IT. `test_the_premise_*` drives the identical transition with
 * the MERCHANT's token on an identical order and asserts the row really does become DELIVERED --
 * `PERMITTED[PENDING]` holds `delivered`. Without that case, "the shopper's order stayed Pending"
 * could be `FulfilmentStateMachine` refusing the transition for everyone, and the guard this file
 * exists for could be deleted with every assertion still green.
 *
 * THE UNKNOWN-SLUG CASE IS NOT PADDING. `getStore()` answers null for a slug that names no store,
 * this handler does not refuse a null store, and `resolveStoreCustomer()` refuses a null store --
 * so a guard keyed on that helper ALONE leaves `/api/no-such-store/order-status-change` open to the
 * very principal it refuses one path over. Measured on this revision: the request reaches the
 * handler and `resolveMutableOrder()` admits the shopper on `orders.customer_id`. That case is what
 * pins the fallback to the authenticated principal.
 */
class Gap135ShopperFulfilmentTransitionTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The established refusal on the sibling shopper door; translated in `en.json` and `ar.json`. */
    private const REFUSED = 'This action is not available.';

    /** What the ownership refusal answers, so the two cannot be confused in an assertion. */
    private const NOT_YOURS = 'Order not found.';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    private function customerIn(Store $store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'gap135-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('shopper-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, Store $store, string $label): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver ' . $label,
            'email' => 'gap135-drv-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    /** Products have no factory in this project; the convention is forceCreate. */
    private function productIn(Store $store, User $owner, int $stock): Product
    {
        return Product::forceCreate([
            'name' => 'gap135-' . uniqid(), 'slug' => 'gap135-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 50, 'sale_price' => 50, 'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $owner->id,
        ]);
    }

    /**
     * A paid, PENDING order the shopper placed themselves.
     *
     * `is_guest = 0` matters: `resolveMutableOrder()` refuses a customer on an order nobody was
     * signed in to place (SEC-INTAKE #188), so a guest-shaped fixture would be refused by the
     * OWNERSHIP limb and every case below would pass without reaching the vocabulary at all.
     */
    private function ownOrderFor(Store $store, Customer $customer, array $attributes = []): Order
    {
        return $this->makeOrder($store, array_merge([
            'customer_id' => $customer->id,
            'is_guest' => 0,
            'payment_status' => 'Paid',
            'delivered_status' => SM::PENDING,
        ], $attributes));
    }

    private function changeStatus($store, $orderId, $status, ?string $bearer)
    {
        $slug = $store instanceof Store ? $store->slug : (string) $store;

        return $this->withHeaders($bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $slug . '/order-status-change', [
                'order_id' => $orderId,
                'order_status' => $status,
            ]);
    }

    private function returnProduct(Store $store, $orderId, $productId, ?string $bearer)
    {
        return $this->withHeaders($bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/product-return', [
                'order_id' => $orderId,
                'product_id' => $productId,
                'variant_id' => 0,
            ]);
    }

    /** The order row as the TABLE holds it -- never `fresh()`, because `Order` is `Cachable`. */
    private function row(Order $order): object
    {
        return DB::table('orders')->where('id', $order->id)->first();
    }

    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    /**
     * A refusal by THIS guard, told apart from every other status-0 answer this door gives.
     */
    private function assertRefusedByTheVocabularyGuard($response, string $because): void
    {
        $this->assertNotSame(404, $response->getStatusCode(),
            $because . ' -- the route answered 404, so nothing below is evidence about the handler.');
        $this->assertSame(200, $response->getStatusCode(),
            $because . ' -- the door answered ' . $response->getStatusCode() . '. Its established '
            . 'refusal shape is HTTP 200 with status 0; anything else is a crash or a new contract.');
        $this->assertSame(0, (int) $response->json('status'), $because . ' -- the request was accepted.');
        $this->assertSame(self::REFUSED, (string) $response->json('message'),
            $because . ' -- the refusal came from somewhere else. Expected ' . self::REFUSED
            . ' (the string the sibling shopper door already answers) and got '
            . var_export($response->json('message'), true) . '. "' . self::NOT_YOURS . '" means the '
            . 'OWNERSHIP limb refused and this case never reached the vocabulary; "That order status '
            . 'is not recognised." means the shared writer refused instead of this guard.');
    }

    private function assertStillPending(Order $order, string $because): void
    {
        $fresh = $this->row($order);

        $this->assertSame(SM::PENDING, (int) $fresh->delivered_status,
            $because . ' -- orders.delivered_status reads ' . var_export($fresh->delivered_status, true) . '.');
        $this->assertNull($fresh->delivery_date, $because . ' -- orders.delivery_date was stamped.');
        $this->assertNull($fresh->cancel_date, $because . ' -- orders.cancel_date was stamped.');
        $this->assertSame(0, (int) $fresh->return_status, $because . ' -- orders.return_status was set.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE PREMISES. Without these, every refusal below holds for the wrong reason.
    // ---------------------------------------------------------------------------------------------

    /**
     * THE MATRIX PERMITS `PENDING -> delivered`, so a shopper's order staying Pending is this
     * guard's doing and not `FulfilmentStateMachine`'s.
     *
     * Driven with the MERCHANT's token on the shopper's own order: same route, same body, same
     * from-state, different principal. This is the case that makes the whole file non-vacuous, and
     * it is also the regression the fix must not cause -- the merchant admin and the Flutter
     * merchant app legitimately mark orders delivered.
     */
    public function test_the_premise_the_same_transition_lands_for_the_owning_merchant(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'premise-matrix');
        $order = $this->ownOrderFor($store, $customer);

        $this->assertNull(SM::refusalFor(SM::PENDING, SM::DELIVERED),
            'FulfilmentStateMachine refuses PENDING -> delivered for everyone, so the refusals in '
            . 'this file would hold with the shopper guard deleted.');

        $response = $this->changeStatus($store, $order->id, SM::DELIVERED,
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'),
            'The owning merchant was refused: ' . var_export($response->json('message'), true));
        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status,
            'The owning merchant can no longer mark their own store\'s order delivered through the '
            . 'storefront door. That is a legitimate journey and the guard must not touch it.');
    }

    /**
     * THE SHOPPER REALLY DOES REACH THIS HANDLER, and their own self-service journey still works.
     *
     * `cancel` is the one word a shopper may use: the AUTHZ-3 docblock calls self-service
     * cancellation "a legitimate journey ... deliberately preserved", the sibling web door
     * (`OrderController::status_cancel`, `P4-F235`) serves exactly that one transition and refuses
     * every other with this file's own message, and the writer already refuses a cancel from any
     * non-Pending state. Must pass BEFORE and AFTER the fix.
     */
    public function test_the_premise_a_shopper_can_still_cancel_their_own_order(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'premise-cancel');
        $order = $this->ownOrderFor($store, $customer);

        $response = $this->changeStatus($store, $order->id, SM::CANCEL,
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'),
            'A shopper could not cancel their own Pending order: '
            . var_export($response->json('message'), true));

        $fresh = $this->row($order);

        $this->assertSame(SM::STATE_CANCELLED, (int) $fresh->delivered_status,
            'Self-service cancellation stopped working. It is the one transition this door exists '
            . 'to serve for a shopper and the fix must preserve it.');
        $this->assertNotNull($fresh->cancel_date, 'The cancellation was not stamped.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE DEFECT
    // ---------------------------------------------------------------------------------------------

    /**
     * `delivered` -- the state `Order::product_return()` requires before it REDUCES `final_price`.
     */
    public function test_a_shopper_cannot_mark_their_own_order_delivered(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'delivered');
        $order = $this->ownOrderFor($store, $customer);

        $because = 'A shopper POSTed order_status=delivered against their own order and the row '
            . 'became DELIVERED -- the state the return and refund workflows are gated on, and '
            . 'after owner decision H2 a state nothing can move an order out of';

        $this->assertRefusedByTheVocabularyGuard(
            $this->changeStatus($store, $order->id, SM::DELIVERED, $customer->createToken('t')->plainTextToken),
            $because
        );
        $this->assertStillPending($order, $because);
    }

    /**
     * `confirmed`, `pickedup` and `shipped` -- three assertions about goods that only the merchant
     * or the courier can make. Each is driven separately so a partial guard cannot hide behind one.
     */
    public function test_a_shopper_cannot_drive_the_fulfilment_progression(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'progression');
        $token = $customer->createToken('t')->plainTextToken;

        foreach ([SM::CONFIRMED, SM::PICKED_UP, SM::SHIPPED] as $word) {
            $order = $this->ownOrderFor($store, $customer);
            $because = 'A shopper POSTed order_status=' . $word . ' against their own order and it '
                . 'was written. Confirmation, pick-up and shipment are facts about goods that the '
                . 'merchant or the courier establishes';

            $this->assertRefusedByTheVocabularyGuard(
                $this->changeStatus($store, $order->id, $word, $token),
                $because
            );
            $this->assertStillPending($order, $because);
        }
    }

    /**
     * `return` -- and this one is a NARROWING that is disclosed rather than discovered.
     *
     * Today a shopper can POST `return` on any order that has left Pending, which writes
     * `delivered_status = 3` (TERMINAL -- nothing leaves it through the shared writer) and
     * `return_status = 1`, dropping the order into the merchant's return-approval workflow. Out of
     * SHIPPED that is a shopper declaring goods returned while the courier is still carrying them,
     * and approving such a request credits the stock.
     *
     * The shopper's own return journey is NOT this word and is untouched: `api/{slug}/product-return`
     * is the per-line door and is gated on `delivered_status == 1`, and the web refund request is
     * gated on delivery the same way. Both still work, for a genuinely delivered order, with the
     * same token.
     */
    public function test_a_shopper_cannot_push_their_own_order_into_the_return_workflow(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'return');
        $order = $this->ownOrderFor($store, $customer, ['delivered_status' => SM::STATE_SHIPPED]);

        $because = 'A shopper POSTed order_status=return against their own SHIPPED order and the '
            . 'row entered the return workflow (delivered_status 3, return_status 1) for goods the '
            . 'courier is still carrying';

        $this->assertRefusedByTheVocabularyGuard(
            $this->changeStatus($store, $order->id, SM::RETURNED, $customer->createToken('t')->plainTextToken),
            $because
        );

        $fresh = $this->row($order);

        $this->assertSame(SM::STATE_SHIPPED, (int) $fresh->delivered_status,
            $because . ' -- orders.delivered_status reads ' . var_export($fresh->delivered_status, true) . '.');
        $this->assertSame(0, (int) $fresh->return_status,
            $because . ' -- orders.return_status reads ' . var_export($fresh->return_status, true) . '.');
        $this->assertNull($fresh->return_date, $because . ' -- orders.return_date was stamped.');
    }

    /**
     * DEFAULT-DENY, which is the shape of the rule and not an accident of the list.
     *
     * `pre_order` and `partial_paid` are the two module vocabularies the shared writer handles
     * BELOW the six governed words, and they write `delivered_status` 8 and 7 -- a payment state and
     * an order type, neither of them a shopper's to name. No WorkDo module loads in any test run
     * (CLAUDE.md), so the ROW cannot move here either way: the distinguishing evidence is the
     * MESSAGE. Before the guard these words reached the writer and answered "That order status is
     * not recognised."; now the door refuses them first, and the row assertion is kept only as a
     * ratchet for the day a module is loaded in a test run.
     *
     * A PADDED WORD IS DELIBERATELY NOT ONE OF THESE CASES, and the reason was measured rather than
     * reasoned about. `' delivered '` cannot be driven through any HTTP door in this application:
     * Laravel's global `TrimStrings` middleware trims it before the handler, so the request arrives
     * as the bare governed word. A mutation that widened the shopper set to include `delivered`
     * accepted `'delivered '` for exactly that reason, which would have made a case named for
     * padding a second copy of the case above it. The machine's strict comparison is pinned where
     * it can be seen instead -- `Gap135ShopperStatusVocabularyTest`, over the predicate itself.
     */
    public function test_a_shopper_cannot_name_a_status_outside_the_governed_six(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'module-words');
        $token = $customer->createToken('t')->plainTextToken;

        foreach (['pre_order', 'partial_paid', 'canceled'] as $word) {
            $order = $this->ownOrderFor($store, $customer);
            $because = 'A shopper POSTed order_status=' . var_export($word, true) . ' and the door '
                . 'did not refuse it. The shopper vocabulary is default-deny: `cancel` and nothing '
                . 'else, whether or not the machine governs the word';

            $this->assertRefusedByTheVocabularyGuard(
                $this->changeStatus($store, $order->id, $word, $token),
                $because
            );
            $this->assertStillPending($order, $because);
        }
    }

    /**
     * AN UNKNOWN SLUG MUST NOT TURN A SHOPPER INTO AN UNCONSTRAINED PRINCIPAL.
     *
     * `getStore('no-such-store')` answers null, this handler does not refuse a null store, and
     * `resolveStoreCustomer()` refuses a null store -- so this is the request that separates a guard
     * keyed on that helper alone from one that falls back to the authenticated principal. Measured
     * before the fix: the request reaches the handler, `resolveMutableOrder()` admits the shopper on
     * `orders.customer_id`, and the order is written.
     */
    public function test_an_unknown_slug_does_not_lift_the_shopper_vocabulary(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'slugless');
        $order = $this->ownOrderFor($store, $customer);

        $because = 'A shopper reached this door through a slug that names no store and set their '
            . 'own order DELIVERED. getStore() answers null for such a slug, and a guard that asks '
            . 'resolveStoreCustomer() alone is lifted by it';

        $this->assertRefusedByTheVocabularyGuard(
            $this->changeStatus('gap135-no-such-store', $order->id, SM::DELIVERED,
                $customer->createToken('t')->plainTextToken),
            $because
        );
        $this->assertStillPending($order, $because);
    }

    // ---------------------------------------------------------------------------------------------
    //  THE MONEY AND THE GOODS -- what the refusal actually buys
    // ---------------------------------------------------------------------------------------------

    /**
     * THE TWO-REQUEST CHAIN `P4-F036` NAMES, driven end to end with ONE shopper token.
     *
     * Request 1 declares the order delivered. Request 2 marks a line returned, which
     * `Order::product_return()` serves only for `delivered_status == 1` -- and which CREDITS
     * `products.product_stock` and SUBTRACTS the line from `orders.final_price`. Before the guard
     * this pair answered 200 then 200 and moved both.
     *
     * The second refusal is the MODEL's (`delivered_status != 1`), not this guard's, so this case
     * asserts the stock and the money rather than the message: the point is that the chain cannot
     * complete, whichever rule stops the second half.
     */
    public function test_a_shopper_cannot_unlock_the_stock_credit_by_delivering_their_own_order(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'chain');
        $product = $this->productIn($store, $merchant, 5);
        $token = $customer->createToken('t')->plainTextToken;

        $order = $this->ownOrderFor($store, $customer, [
            'final_price' => 100,
            'product_json' => json_encode([[
                'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2, 'final_price' => 50,
            ]]),
        ]);

        $this->assertRefusedByTheVocabularyGuard(
            $this->changeStatus($store, $order->id, SM::DELIVERED, $token),
            'Chain step 1: the shopper marked their own order delivered'
        );

        $returnResponse = $this->returnProduct($store, $order->id, $product->id, $token);

        $this->assertSame(200, $returnResponse->getStatusCode(),
            'Chain step 2 crashed rather than refusing. Body: ' . $returnResponse->getContent());
        $this->assertSame(0, (int) $returnResponse->json('status'),
            'Chain step 2 SUCCEEDED: the shopper marked a line returned on an order that was never '
            . 'delivered. Message: ' . var_export($returnResponse->json('message'), true));

        $this->assertSame(5, $this->stockOf($product),
            'products.product_stock moved. The shopper credited the merchant 2 units for goods that '
            . 'never left the shelf -- the inventory half of P4-F036.');
        $this->assertEqualsWithDelta(100.0, (float) $this->row($order)->final_price, 0.005,
            'orders.final_price moved. Order::product_return() subtracts the returned line from the '
            . 'order total, so this is the money half of P4-F036.');

        $decoded = json_decode((string) $this->row($order)->product_json, true);
        $this->assertArrayNotHasKey('return', $decoded[0] ?? [],
            'The order line was stamped returned.');
        $this->assertArrayNotHasKey(Order::LINE_STOCK_RESTORED, $decoded[0] ?? [],
            'The order line was stamped with the stock-restored marker.');
    }

    // ---------------------------------------------------------------------------------------------
    //  THE PRINCIPALS THE GUARD MUST NOT TOUCH. A customer-token probe cannot see any of these.
    // ---------------------------------------------------------------------------------------------

    /**
     * THE ASSIGNED DRIVER. `CustomAuth` logs a `DeliveryBoy` token into the `deliveryboy` guard, so
     * `auth('customers')` is null for them and the shopper rule must not fire. Marking an order
     * delivered is the driver's entire job.
     */
    public function test_the_assigned_driver_can_still_mark_the_order_delivered(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'driver-door');
        $driver = $this->driverFor($merchant, $store, 'driver-door');
        $order = $this->ownOrderFor($store, $customer, ['deliveryboy_id' => $driver->id]);

        $response = $this->changeStatus($store, $order->id, SM::DELIVERED,
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'),
            'The assigned driver was refused: ' . var_export($response->json('message'), true)
            . '. A DeliveryBoy token leaves auth(\'customers\') null, so a guard that refuses them '
            . 'is reading the principal wrongly.');
        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status,
            'The assigned driver can no longer mark their own delivery delivered.');
    }

    /**
     * THE PLATFORM OPERATOR. `isAbleTo()` alone refuses them in this codebase -- there is no
     * super-admin bypass -- so `resolveViewableOrder()` admits them by a string comparison on
     * `users.type`, FIRST. A guard that keys "is this a shopper" on anything other than the
     * authenticated principal's class can lock them out.
     */
    public function test_the_platform_operator_can_still_mark_any_order_delivered(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $customer = $this->customerIn($store, 'operator-door');
        $order = $this->ownOrderFor($store, $customer);

        $response = $this->changeStatus($store, $order->id, SM::DELIVERED,
            $operator->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(1, (int) $response->json('status'),
            'The platform operator was refused: ' . var_export($response->json('message'), true));
        $this->assertSame(SM::STATE_DELIVERED, (int) $this->row($order)->delivered_status,
            'The platform operator can no longer mark an order delivered.');
    }

    /**
     * AND THE OTHER STOREFRONT STATUS DOOR IS NOT A WAY ROUND THIS ONE -- measured, not read.
     *
     * `POST api/{slug}/order-cancel` (`routes/api.php:166`) is the one other `{slug}` route that
     * writes `orders.delivered_status`, and it reaches `Api\DashboardController::orderCancel`, an
     * ADMIN controller. A guard on one door is worth what the other doors refuse, so this case
     * drives a shopper's own token at it and asserts the whole door is closed to them: its
     * `resolveChangeableOrder()` admits the assigned driver, the operator and a user whose tenant
     * owns the order's store, and reads the `web` guard -- which `CustomAuth` leaves null for a
     * `Customer` token, since it logs exactly one guard per tokenable class.
     *
     * The admin integer doors (`api/admin/orders/update-status`, `api/admin/status-change`) are shut
     * to a shopper for the same reason -- `updateStatus` requires `$actor instanceof User` and the
     * `updateStatus` ability before it writes -- and they are not driven here because they are
     * another package's surface with its own suites.
     */
    public function test_the_other_storefront_status_door_is_closed_to_a_shopper_entirely(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'cancel-door');
        $order = $this->ownOrderFor($store, $customer);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/order-cancel', ['order_id' => $order->id]);

        $this->assertNotSame(404, $response->getStatusCode(),
            'api/{slug}/order-cancel answered 404, so this case measures a missing route rather '
            . 'than a refusal.');

        /*
         * THE SHAPE IS ASSERTED BEFORE THE ROW, because the independent review found this case
         * could pass on a CRASH: a 500 satisfies both not-404 and "the row did not change", so the
         * case would have reported a refusal where it had only measured an exception. The door is
         * genuinely shut to a Customer token -- `resolveChangeableOrder()` reads the `web` guard
         * and has no `customers` branch -- so the OWNERSHIP limb is what must answer here, and
         * naming its message is what tells that apart from this guard and from a crash.
         */
        $this->assertSame(200, $response->getStatusCode(),
            'api/{slug}/order-cancel answered ' . $response->getStatusCode() . '. Its refusal shape '
            . 'is HTTP 200 with status 0; anything else is a crash, and a crash would satisfy the '
            . 'row assertions below while proving nothing about a refusal.');
        $this->assertSame(0, (int) $response->json('status'),
            'A shopper token was ACCEPTED by api/{slug}/order-cancel, the admin door.');
        /*
         * MEASURED, AND IT CORRECTED THE REVIEW. The review predicted this door answers
         * "Order not found." from reading `resolveChangeableOrder()`; driven, it answers the
         * generic `fail`. So the assertion pins what is true -- the door refuses with its OWN
         * message -- and what matters for this file: the refusal is NOT this guard's, so the case
         * is evidence about a second surface rather than a duplicate of the cases above.
         */
        $this->assertNotSame(self::REFUSED, (string) $response->json('message'),
            'api/{slug}/order-cancel answered with the vocabulary refusal string, which would '
            . 'mean this case duplicates the cases above instead of measuring a second door.');
        $this->assertSame('fail', (string) $response->json('message'),
            'api/{slug}/order-cancel changed its refusal message to '
            . var_export($response->json('message'), true) . '. It is the ADMIN controller reached '
            . 'by a storefront path, so a change here is worth noticing.');

        $this->assertSame(SM::PENDING, (int) $this->row($order)->delivered_status,
            'A shopper cancelled their own order through api/{slug}/order-cancel, which reaches the '
            . 'ADMIN controller. The storefront vocabulary guard sits on order-status-change; if '
            . 'this door admits a shopper it is a second surface that needs its own answer.');
        $this->assertNull($this->row($order)->cancel_date,
            'The cancellation was stamped through the admin door by a shopper\'s token.');
    }

    /**
     * AND THE OWNERSHIP LIMB STILL ANSWERS FIRST, with its own string.
     *
     * A shopper of store B naming store A's order must get `Order not found.` -- "not yours" is
     * deliberately indistinguishable from "no such order" so the door cannot enumerate order ids.
     * If the vocabulary guard ran first it would answer `This action is not available.` to a caller
     * who has no business learning anything about that order at all.
     */
    public function test_the_ownership_refusal_still_comes_first_and_keeps_its_own_message(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $owner = $this->customerIn($storeA, 'victim');
        $attacker = $this->customerIn($storeB, 'attacker');
        $order = $this->ownOrderFor($storeA, $owner);

        $response = $this->changeStatus($storeB, $order->id, SM::DELIVERED,
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->getStatusCode(), 'Body: ' . $response->getContent());
        $this->assertSame(0, (int) $response->json('status'), 'A foreign shopper was accepted.');
        $this->assertSame(self::NOT_YOURS, (string) $response->json('message'),
            'The ownership refusal no longer answers first: a caller with no claim on this order '
            . 'learned that the order exists and that only the STATUS was the problem.');
        $this->assertStillPending($order, 'A shopper of another store moved a foreign order');
    }
}
