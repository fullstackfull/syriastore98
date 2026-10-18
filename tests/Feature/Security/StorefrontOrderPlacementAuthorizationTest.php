<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the three storefront ORDER-PLACEMENT handlers: place_order (routed twice, as
 * place-order and place-order-guest), confirm_order and ordersave. The highest-value group in the
 * package, because one request here moves stock and money.
 *
 * What a single call did with a victim's id and no relationship to them:
 *
 *   - read their cart (through cart_list, which took the same posted id);
 *   - DECREMENTED real Product and ProductVariant stock;
 *   - DELETED their cart rows;
 *   - created an Order in their name carrying the ATTACKER's billing name, email, phone and
 *     address, which OrderObserver turns into a row in the financial ledger and a wallet touch;
 *   - sent "Order Created" mail to the attacker's address and to the store owner.
 *
 * place_order is the worst of the family and differs from its siblings: it never consulted the
 * session for its subject on ANY branch, so it was exploitable with an ORDINARY CUSTOMER TOKEN,
 * not only by a wrong-class principal. confirm_order and ordersave carry the guard-class trap, so
 * there a merchant's or driver's token is the key.
 *
 * `place-order-guest` is not a guest route. It is the same handler and also carries custom.auth,
 * which returns 401 for a missing bearer before the handler runs. The `-guest` suffix in this file
 * means "the handler has an auth('customers') fallback", not "the route is public" -- and that
 * fallback was the defect.
 *
 * ---------------------------------------------------------------------------------------------
 * PREMISE, AND WHY THIS CLASS SPENDS SO MUCH FIXTURE ON IT.
 *
 * All three handlers 500 or bail out in a fresh database for reasons that have nothing to do with
 * authorization, and a test asserting "no order was created for the victim" then passes against
 * the vulnerable code. Four separate premises had to be satisfied before any assertion here meant
 * anything, each established by running the code and reading the error:
 *
 *   1. `$user = Cache::remember('admin_details', ...)` then `$plan = Plan::find($user->plan_id)`
 *      then `$plan->shipping_method`. No admin user, or no resolvable Plan, and every call dies
 *      on a null read. makeTenant() creates a type='admin' merchant, but no Plan.
 *   2. App\Models\User uses GeneaLabs\LaravelModelCaching\Traits\Cachable, so assigning plan_id
 *      with a raw DB::table() update leaves `User::where(...)->first()` serving the pre-write row
 *      -- plan_id still null, $plan still null, still a 500. The assignment must go through
 *      Eloquent, which flushes the model cache. This cost a diagnostic round to find.
 *   3. The `admin_details` cache entry itself must be forgotten between the fixture and the
 *      request, or the request re-reads a User captured before the plan existed.
 *   4. billing_info's postcode key is `billing_postecode`, misspelled, and the delivery_* keys
 *      are all separately required. A missing one returns a plain validation refusal that looks
 *      exactly like an authorization refusal.
 *
 * TWO PRE-EXISTING FAILURES REMAIN, and the assertions are written around them rather than
 * pretending they are not there:
 *
 *   - place_order returns 500 from `Utility::paymentWebhook($order)` AFTER `$order->save()`. The
 *     order row exists. So this class asserts on the `orders` TABLE, not on the response status:
 *     the security question is whose order was created, and that is observable either way.
 *   - ordersave reads `$plan->shipping_method` a second time from the store owner rather than
 *     from the cached admin, so it needs the owner to be type='admin' with a live plan too.
 *
 * ---------------------------------------------------------------------------------------------
 * WHICH CONTROL ACTUALLY STOPS WHAT. All three handlers read the cart through cart_list(), which
 * this package fixed in its own commit, so there are two layers here and it would be easy to
 * credit the wrong one. Measured by reverting each separately:
 *
 *   revert cart_list alone                 -> nothing fails. Each handler's own check refuses.
 *   revert place_order alone               -> 2 fail: the customer-on-customer forgery and the
 *                                             tampered id. cart_list never protected against a
 *                                             CUSTOMER caller, because it always took its safe
 *                                             branch for one -- so place_order's own check is
 *                                             the only thing standing between a shopper and an
 *                                             order forged in another shopper's name.
 *   revert place_order AND cart_list       -> 4 fail: the merchant and driver cases join.
 *   revert confirm_order alone             -> nothing fails.
 *   revert confirm_order AND cart_list     -> 1 fails.
 *   revert ordersave alone                 -> nothing fails.
 *   revert ordersave AND cart_list         -> 1 fails.
 *
 * So for confirm_order and ordersave, cart_list is the control and their own checks are defence
 * in depth; for place_order its own check is load-bearing on its own. Stated rather than implied,
 * because "16 tests pass" would otherwise suggest three independently proven fixes.
 */
class StorefrontOrderPlacementAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These handlers mail the customer and the store owner on success.
        Mail::fake();
    }

    /**
     * A tenant that can actually take an order. See the PREMISE block above for why each step is
     * here; without all four these tests measure crashes.
     */
    private function orderableTenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'test-plan-' . uniqid(),
            'price' => 0,
            'themes' => '[]',
            'storage_limit' => '100',
            'shipping_method' => 'off',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Through Eloquent, NOT DB::table(): User is Cachable, and a raw write leaves
        // User::where()->first() serving plan_id = null, which puts every call back on a 500.
        $merchant->plan_id = $planId;
        $merchant->save();

        // The handlers read the admin through Cache::remember('admin_details'). With
        // CACHE_STORE=array a stale entry outlives the fixture.
        Cache::forget('admin_details');

        // Without this the stock-movement assertions are vacuous: place_order's decrement is
        // gated on `$settings['stock_management'] == 'on'` (ApiController:3096) and
        // Utility::Setting() does not default it on, so stock never moves and "stock unchanged"
        // passes whether or not the order was placed.
        DB::table('settings')->insert([
            'name' => 'stock_management', 'value' => 'on',
            'created_by' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $shippingId];
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'ord-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function productIn($store, int $stock = 10): Product
    {
        return Product::forceCreate([
            'name' => 'ord-product-' . uniqid(),
            'slug' => 'ord-product-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function cartFor($store, Customer $customer, Product $product, int $qty = 2): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10 * $qty, 'store_id' => $store->id,
        ]);
    }

    /** `billing_postecode` is misspelled in the handler; every delivery_* key is required too. */
    private function billing(): array
    {
        return [
            'firstname' => 'ATTACKER', 'lastname' => 'NAME',
            'email' => 'attacker@evil.test',
            'billing_address' => 'ATTACKER STREET',
            'billing_user_telephone' => '555000999',
            'billing_postecode' => '11111',
            'billing_country' => 'Testland', 'billing_state' => 'Teststate',
            'billing_city' => 'Testcity',
            'delivery_address' => 'ATTACKER STREET', 'delivery_postcode' => '11111',
            'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
            'delivery_city' => 'Testcity',
        ];
    }

    private function place($store, string $path, $customerId, int $shippingId, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, [
            'customer_id' => $customerId,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ]);
    }

    private static function ordersFor($customerId)
    {
        return Order::withoutGlobalScopes()->where('customer_id', $customerId);
    }

    private static function cartRows($customerId)
    {
        return Cart::withoutGlobalScopes()->where('customer_id', $customerId);
    }

    private static function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    // ===================================================================================
    // THE PREMISE — if these fail, nothing below means anything
    // ===================================================================================

    public function test_a_shopper_can_place_their_own_order(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'premise1');
        $product = $this->productIn($store);
        $this->cartFor($store, $customer, $product, 2);

        $this->place($store, 'place-order', $customer->id, $shippingId,
            $customer->createToken('t')->plainTextToken);

        // Asserted on the table, not the response: place_order returns 500 from
        // Utility::paymentWebhook() AFTER the order is saved, which is pre-existing.
        $this->assertSame(1, self::ordersFor($customer->id)->count(),
            'A shopper can no longer place their own order, so every denial below is measuring a '
            . 'handler that fails for everyone.');
        $this->assertSame(8, self::stockOf($product),
            'Stock was not decremented, so the order did not really go through.');
        $this->assertSame(0, self::cartRows($customer->id)->count(),
            'The cart was not cleared, so the order did not really go through.');
    }

    public function test_a_shopper_can_confirm_their_own_order(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'premise2');
        $product = $this->productIn($store);
        $this->cartFor($store, $customer, $product, 2);

        $this->place($store, 'confirm-order', $customer->id, $shippingId,
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);
    }

    /**
     * ordersave CANNOT COMPLETE FOR ANYONE, and that is a measured finding rather than a fixture
     * problem, so it is asserted here instead of being left as a failing premise.
     *
     * `$order->coupon_price = $order_array['coupon_info']['discount_amount']`
     * (ApiController:7608) reads a key that is only ever set inside the coupon branch, and
     * `coupon_info` is null for an order placed without a coupon -- confirm_order's own response
     * shows `"coupon_info":null`. So the handler raises "Trying to access array offset on null"
     * and returns 500 BEFORE `$order->save()`, for a legitimate shopper as readily as for an
     * attacker. The enumeration of this family also found ordersave's coupon branch unreachable
     * (`isset($coupon_data['coupon_id'])` is never satisfied), so there is no payload that gets
     * past this line.
     *
     * BUT THE HOLE WAS STILL EXPLOITABLE, and this correction matters: the 500 happens AFTER the
     * stock decrement and the cart delete. Driven with its fix reverted, a merchant's token
     * against a victim's id decrements that victim's product stock and empties their cart, then
     * dies. So ordersave's defect is destructive rather than order-creating -- an attacker could
     * not forge an order through it, but could repeatedly destroy a shopper's basket and move a
     * merchant's stock. The stock and cart assertions in the trap tests below are the ones that
     * bite for this endpoint; the order-count assertion does not.
     *
     * Repairing the 500 is out of scope here: it is a functional defect in order totals, not an
     * authorization one.
     */
    public function test_ordersave_cannot_complete_for_anyone(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'premise3');
        $product = $this->productIn($store);
        $this->cartFor($store, $customer, $product, 2);

        $response = $this->place($store, 'order-save', $customer->id, $shippingId,
            $customer->createToken('t')->plainTextToken);

        $this->assertSame(500, $response->getStatusCode(),
            'order-save now completes, so its denial tests below have become real measurements '
            . 'and this test should be replaced by a proper premise assertion.');
        $this->assertSame(0, self::ordersFor($customer->id)->count(),
            'order-save created an order, so the 500 is no longer before the save.');
    }

    // ===================================================================================
    // place_order — exploitable with an ORDINARY CUSTOMER TOKEN
    // ===================================================================================

    public function test_one_shopper_cannot_place_an_order_in_anothers_name(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 'p1-victim');
        $attacker = $this->customerIn($store, 'p1-attacker');
        $product = $this->productIn($store);
        $victimCart = $this->cartFor($store, $victim, $product, 2);

        // The ATTACKER needs a cart of their own. cart_list has always taken the safe branch for
        // a customer caller, so with an empty attacker cart place_order answers 'Cart is empty.'
        // and returns before creating anything -- and this test would then pass against the
        // unfixed handler. With a cart, the pre-fix handler creates an order carrying the
        // attacker's basket and the VICTIM's customer_id, which is the forgery being measured.
        $this->cartFor($store, $attacker, $this->productIn($store), 1);

        $this->place($store, 'place-order', $victim->id, $shippingId,
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "One shopper created an order in another's name -- which also writes the financial "
            . 'ledger row OrderObserver produces.');
        $this->assertNotNull(Cart::withoutGlobalScopes()->find($victimCart->id),
            "The victim's cart was emptied.");
        $this->assertSame(10, self::stockOf($product),
            "Stock was decremented by an order the victim never placed.");
    }

    public function test_a_merchant_token_cannot_place_an_order_for_a_shopper(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 'p2');
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        $this->place($store, 'place-order', $victim->id, $shippingId,
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "A merchant's token created an order in a shopper's name.");
    }

    public function test_a_driver_token_cannot_place_an_order_for_a_shopper(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 'p3');
        $driver = $this->driverFor($merchant, $store);
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        $this->place($store, 'place-order', $victim->id, $shippingId,
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "A driver's token created an order in a shopper's name.");
    }

    /** place-order-guest is the same handler on a second route, so it needs the same proof. */
    public function test_the_guest_route_is_not_a_second_way_in(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 'p4-victim');
        $attacker = $this->customerIn($store, 'p4-attacker');
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        $this->place($store, 'place-order-guest', $victim->id, $shippingId,
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            'place-order-guest placed an order in another shopper\'s name.');
    }

    public function test_a_shopper_of_another_store_cannot_place_an_order(): void
    {
        [, $storeA, $shippingA] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();

        $victim = $this->customerIn($storeA, 'p5-victim');
        $attacker = $this->customerIn($storeB, 'p5-attacker');
        $product = $this->productIn($storeA);
        $this->cartFor($storeA, $victim, $product, 2);

        $this->place($storeA, 'place-order', $victim->id, $shippingA,
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "A shopper of store B created an order for a customer of store A.");
    }

    /** The posted id must be inert: a tampered value acts on the caller's own account. */
    public function test_a_tampered_customer_id_places_the_callers_own_order(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $other = $this->customerIn($store, 'p6-other');
        $caller = $this->customerIn($store, 'p6-caller');
        $product = $this->productIn($store);
        $this->cartFor($store, $caller, $product, 2);

        $this->place($store, 'place-order', $other->id, $shippingId,
            $caller->createToken('t')->plainTextToken);

        $this->assertSame(1, self::ordersFor($caller->id)->count(),
            "A tampered customer_id stopped the caller's own order from being placed.");
        $this->assertSame(0, self::ordersFor($other->id)->count(),
            'The tampered customer_id still steered the order.');
    }

    // ===================================================================================
    // confirm_order and ordersave — the guard-class trap on an order-creating handler
    // ===================================================================================

    public static function trapEndpoints(): array
    {
        // For 'order-save' it is the STOCK and CART assertions that bite, not the order count:
        // see test_ordersave_cannot_complete_for_anyone(). For 'confirm-order' none of the three
        // bites, because that handler persists no order at all -- its exposure is measured by
        // test_a_merchant_token_cannot_read_a_shoppers_basket_through_confirm_order().
        return ['confirm_order' => ['confirm-order'], 'ordersave' => ['order-save']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trapEndpoints')]
    public function test_a_merchant_token_cannot_create_an_order_for_a_shopper(string $path): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 't1');
        $product = $this->productIn($store);
        $victimCart = $this->cartFor($store, $victim, $product, 2);

        $this->place($store, $path, $victim->id, $shippingId,
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "A merchant's token created an order in a shopper's name through $path.");
        $this->assertNotNull(Cart::withoutGlobalScopes()->find($victimCart->id),
            "The victim's cart was emptied by $path.");
        $this->assertSame(10, self::stockOf($product),
            "Stock was decremented by $path for an order the victim never placed.");
    }

    /**
     * confirm_order PERSISTS NO ORDER -- measured, not assumed: driven with its fix reverted it
     * answers "Order has been confirmed successfully." while `orders` stays empty. It is a pricing
     * and confirmation step that returns a computed summary. So an order-count assertion proves
     * nothing about it, and what the trap actually exposed there is the victim's BASKET: their
     * products and their totals, read through cart_list and returned to the caller.
     *
     * That is what this asserts. Kept separate from the shared provider above so the two
     * endpoints are not credited with the same evidence.
     */
    public function test_a_merchant_token_cannot_read_a_shoppers_basket_through_confirm_order(): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 't1b');
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        $response = $this->place($store, 'confirm-order', $victim->id, $shippingId,
            $merchant->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString($product->name, $response->getContent(),
            "A merchant's token read a shopper's basket through confirm-order.");
        $this->assertSame(0, $response->json('status'),
            'confirm-order answered a merchant token with success.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trapEndpoints')]
    public function test_a_driver_token_cannot_create_an_order_for_a_shopper(string $path): void
    {
        [$merchant, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 't2');
        $driver = $this->driverFor($merchant, $store);
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        $this->place($store, $path, $victim->id, $shippingId,
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            "A driver's token created an order in a shopper's name through $path.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('trapEndpoints')]
    public function test_a_tampered_id_acts_on_the_caller_only(string $path): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $other = $this->customerIn($store, 't3-other');
        $caller = $this->customerIn($store, 't3-caller');
        $product = $this->productIn($store);
        $this->cartFor($store, $caller, $product, 2);

        $this->place($store, $path, $other->id, $shippingId,
            $caller->createToken('t')->plainTextToken);

        $this->assertSame(0, self::ordersFor($other->id)->count(),
            "The posted customer_id still steered $path.");
    }

    public function test_an_anonymous_caller_cannot_place_an_order(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $victim = $this->customerIn($store, 'z1');
        $product = $this->productIn($store);
        $this->cartFor($store, $victim, $product, 2);

        // A premise check on custom.auth, which refuses a missing bearer before any of these
        // handlers runs -- so it passes against the unfixed body too. Recorded because every
        // denial above assumes it, and because place-order-GUEST invites the opposite assumption.
        foreach (['place-order', 'place-order-guest', 'confirm-order', 'order-save'] as $path) {
            $this->place($store, $path, $victim->id, $shippingId, null);
        }

        $this->assertSame(0, self::ordersFor($victim->id)->count(),
            'An anonymous caller created an order.');
        $this->assertSame(10, self::stockOf($product));
    }
}
