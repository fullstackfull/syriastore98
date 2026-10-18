<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the SECOND axis, found by the adversarial review of this package's own fix rather
 * than by the fix. Deriving the CUSTOMER from the session closed nothing about the PRODUCT.
 *
 * `Product::find($request->product_id)` has no store predicate in addtocart, wishlist or
 * variant_list, and the enumeration of this family missed it because it was looking at
 * `customer_id`.
 *
 * WHY NOTHING UNDERNEATH CAUGHT IT, and this is stronger than the open-tenant reasoning that
 * applies elsewhere in the package: `Product` carries StoreScope (app/Models/Product.php uses
 * BelongsToStore, so getGlobalScopes() lists it) but is NOT in config/tenancy.php's
 * `scoped_models` -- it sits in the file's own "STILL NOT ENABLED" block. So
 * StoreScope::isActiveFor() short-circuits and NO predicate is added for ANY principal: not a
 * customer, not a merchant, not the platform operator, and not an admin-context request. The
 * open-tenant rule does not even come into it.
 *
 * WHAT THE REMOVAL PASS ESTABLISHED, and it changed the fix: closing addtocart alone is NOT
 * enough. A cart row can reach the checkout loop from the web storefront's own CartController, or
 * already exist from before the gate -- and with only the entry gate in place, a planted cart row
 * still drained another merchant's stock (10 -> 6). The decrement loops in place_order and
 * ordersave needed the predicate too. Four of the five predicates bite; variant_list's is
 * labelled below as one that cannot be isolated.
 *
 * THE SHARPEST CONSEQUENCE is not a read. addtocart is the entry point of the cart -> order
 * chain: cart_list prices whatever sits in the cart and place_order/ordersave decrement its stock,
 * neither with a store predicate. So a customer of store A could put store B's product into their
 * store-A cart and have checkout consume MERCHANT B's inventory, with the order, the money row
 * and the wallet touch landing in store A. That is asserted below end to end, because reasoning
 * about it is not the same as watching the stock column move.
 */
class StorefrontCrossTenantProductTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function orderableTenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'test-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // Through Eloquent: User is Cachable, so a raw write leaves plan_id reading null.
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        DB::table('settings')->insert([
            'name' => 'stock_management', 'value' => 'on', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
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
            'email' => 'xt-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store, int $stock = 10): Product
    {
        return Product::forceCreate([
            'name' => 'FOREIGN-PRODUCT-' . uniqid(),
            'slug' => 'foreign-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function callApi($store, string $path, array $payload, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    private static function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
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

    // ===================================================================================
    // THE ENTRY POINT
    // ===================================================================================

    public function test_a_foreign_product_cannot_be_added_to_a_cart(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $attacker = $this->customerIn($storeA, 'a1');
        $foreign = $this->productIn($storeB);

        $response = $this->callApi($storeA, 'add-cart', [
            'product_id' => $foreign->id, 'variant_id' => 0, 'qty' => 2,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, Cart::withoutGlobalScopes()->where('product_id', $foreign->id)->count(),
            "Store B's product was added to a store-A cart.");
        $this->assertSame(0, $response->json('status'));
    }

    public function test_a_shopper_can_still_add_their_own_stores_product(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a2');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-cart', [
            'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2,
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(1, Cart::withoutGlobalScopes()->where('product_id', $product->id)->count(),
            'A shopper can no longer add their own store\'s product to their cart.');
    }

    // ===================================================================================
    // THE STOCK CONSEQUENCE, END TO END
    // ===================================================================================

    /**
     * The whole point. A cart row referencing a foreign product is planted directly -- bypassing
     * addtocart, so this measures the CHECKOUT behaviour and not the entry gate -- and then
     * checkout is run. Merchant B's stock must not move.
     */
    public function test_checkout_cannot_decrement_another_merchants_stock(): void
    {
        [, $storeA, $shippingA] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();

        $attacker = $this->customerIn($storeA, 'b1');
        $foreign = $this->productIn($storeB, 10);

        Cart::forceCreate([
            'customer_id' => $attacker->id, 'product_id' => $foreign->id, 'variant_id' => 0,
            'qty' => 4, 'price' => 40, 'store_id' => $storeA->id,
        ]);

        $this->callApi($storeA, 'place-order', [
            'customer_id' => $attacker->id, 'billing_info' => $this->billing(),
            'payment_type' => 'cod', 'delivery_id' => $shippingA, 'method_id' => $shippingA,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(10, self::stockOf($foreign),
            "Checkout in store A decremented MERCHANT B's stock. The cart -> order chain prices "
            . 'and decrements whatever product id the cart holds, with no store predicate.');
    }

    /** The same for ordersave, which decrements before its own 500. */
    public function test_ordersave_cannot_decrement_another_merchants_stock(): void
    {
        [, $storeA, $shippingA] = $this->orderableTenant();
        [, $storeB] = $this->orderableTenant();

        $attacker = $this->customerIn($storeA, 'b2');
        $foreign = $this->productIn($storeB, 10);

        Cart::forceCreate([
            'customer_id' => $attacker->id, 'product_id' => $foreign->id, 'variant_id' => 0,
            'qty' => 3, 'price' => 30, 'store_id' => $storeA->id,
        ]);

        $this->callApi($storeA, 'order-save', [
            'customer_id' => $attacker->id, 'billing_info' => $this->billing(),
            'payment_type' => 'cod', 'delivery_id' => $shippingA, 'method_id' => $shippingA,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(10, self::stockOf($foreign),
            "order-save in store A decremented MERCHANT B's stock.");
    }

    /** And a legitimate checkout must still move the caller's own store's stock. */
    public function test_checkout_still_decrements_its_own_stores_stock(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $customer = $this->customerIn($store, 'b3');
        $product = $this->productIn($store, 10);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20, 'store_id' => $store->id,
        ]);

        $this->callApi($store, 'place-order', [
            'customer_id' => $customer->id, 'billing_info' => $this->billing(),
            'payment_type' => 'cod', 'delivery_id' => $shippingId, 'method_id' => $shippingId,
        ], $customer->createToken('t')->plainTextToken);

        $this->assertSame(1, Order::withoutGlobalScopes()->where('customer_id', $customer->id)->count(),
            'A legitimate checkout stopped creating the order, so the stock assertion below would '
            . 'pass for the wrong reason.');
        $this->assertSame(8, self::stockOf($product),
            'A legitimate checkout no longer decrements its own store\'s stock.');
    }

    // ===================================================================================
    // THE TWO READS
    // ===================================================================================

    public function test_a_foreign_product_cannot_be_wishlisted(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $attacker = $this->customerIn($storeA, 'c1');
        $foreign = $this->productIn($storeB);

        // `customer_id` is still a required field on this handler -- inert, but required -- so
        // it has to be sent or the refusal is a validation error rather than the store predicate.
        $response = $this->callApi($storeA, 'wishlist', [
            'customer_id' => $attacker->id,
            'product_id' => $foreign->id, 'wishlist_type' => 'add',
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, Wishlist::withoutGlobalScopes()->where('product_id', $foreign->id)->count(),
            "Store B's product was added to a store-A wishlist.");
        $this->assertStringNotContainsString($foreign->name, $response->getContent(),
            "The response disclosed store B's product name.");
    }

    public function test_a_shopper_can_still_wishlist_their_own_stores_product(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'c2');
        $product = $this->productIn($store);

        $this->callApi($store, 'wishlist', [
            'customer_id' => $customer->id,
            'product_id' => $product->id, 'wishlist_type' => 'add',
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(1, Wishlist::withoutGlobalScopes()->where('product_id', $product->id)->count(),
            'A shopper can no longer wishlist their own store\'s product.');
    }

    /**
     * NOT A BITE, and labelled rather than counted. Reverting variant_list's store predicate does
     * not fail this: the handler answers 'Variant not found.' until a ProductVariant matching the
     * parsed `variant` string exists, so it discloses nothing about a foreign product either way.
     * Constructing that variant is more fixture than the finding is worth. The predicate stays
     * because the lookup was genuinely unscoped and would disclose another tenant's price, sale
     * price, tax and stock state the moment a matching variant did exist.
     */
    public function test_variant_list_cannot_read_a_foreign_products_pricing(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $attacker = $this->customerIn($storeA, 'd1');
        $foreign = $this->productIn($storeB);

        $response = $this->callApi($storeA, 'variant-list', [
            'product_id' => $foreign->id, 'variant' => 'Color:Red', 'quantity' => 1,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString($foreign->name, $response->getContent(),
            "variant-list disclosed store B's product through store A's slug.");
        $this->assertSame(0, $response->json('status'));
    }
}
