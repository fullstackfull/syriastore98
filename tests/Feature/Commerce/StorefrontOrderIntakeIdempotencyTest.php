<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F276 — E14's payment half, on the two storefront writers that had no control at all.
 * =================================================================================================
 *
 * The master prompt's Workstream E rule 14 is *"no duplication of payments or inventory
 * movements"*, and ledger row P2-04-17 has carried it as PARTIAL: the stock half is closed by
 * `RefundStockIdempotencyTest`, the payment half is not.
 *
 * `GatewayReplayGuardCensusTest` — which parses the tree rather than grepping it, after the
 * previous census was found to be wrong in every number it asserted — classifies exactly three
 * customer-order writers as `client-api/no-idempotency-control`:
 *
 *     Api\ApiController::place_order              POST api/{slug}/place-order{,-guest}
 *     Api\ApiController::ordersave                POST api/{slug}/order-save{,-guest}
 *     Api\DashboardController::createOrderViaApi  POST api/admin/createOrderViaApi
 *
 * The third was closed by F226. These two were not. A repeated post writes a SECOND order and
 * decrements stock a SECOND time, and the census's own note says so: *"nothing makes a repeated
 * post idempotent."*
 *
 * ------------------------------------------------------------------------------------------------
 * WHY A SECOND POST IS NOT HYPOTHETICAL HERE. These are the storefront API's checkout endpoints.
 * The client is a phone on a mobile network: a request that times out client-side has still
 * arrived, and a retry — automatic or by a shopper tapping Pay again — is the ordinary case, not
 * the attack. `ApiResponser::error()` returning HTTP 200 makes it worse, because a client cannot
 * tell a refusal from a success by status code alone.
 *
 * THE REPLAY IS DRIVEN WITH THE CART RE-SEEDED, deliberately. A successful order clears the
 * shopper's cart, so a bare second post finds nothing to sell and is stopped by that rather than by
 * any control — which proves nothing about duplication. Re-seeding models the case that matters:
 * two requests in flight together, each holding its own view of the basket. That is exactly the
 * shape F269 measured at the POS till, and the reason the control cannot live in the cart table.
 * ------------------------------------------------------------------------------------------------
 *
 * THE SAME PRIMITIVE AS F226, F269 AND F270: one atomic `Cache::add()` claim, fingerprinted on the
 * SERVER-RESOLVED store and customer (`ApiController::resolveStoreCustomer()`, the shared predicate
 * CLAUDE.md names) plus a canonical form of the basket. Never on `$request->customer_id`, which is
 * caller input and which this very handler was once exploitable through.
 *
 * WHAT THE SUITE CANNOT SHOW, stated rather than implied: `phpunit.xml` sets `CACHE_STORE=array`,
 * and `ArrayStore` has no `add()`, so `Cache::add()` here is `Repository::add()` — a `get()` then a
 * `put()`. These tests prove the control's LOGIC. That the second of two SIMULTANEOUS callers loses
 * because the DATABASE said so is measured on real MariaDB by
 * `tests/Support/Security/bin/verify-pos-sale-claim-concurrency-on-mysql.php`, which exercises the
 * same primitive against the same `cache` primary key.
 */
final class StorefrontOrderIntakeIdempotencyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const OPENING_STOCK = 50;

    protected function setUp(): void
    {
        parent::setUp();

        // Both writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /** @return array<string, array{0: string}> */
    public static function apiOrderWriters(): array
    {
        return [
            'place-order' => ['place-order'],
            'order-save' => ['order-save'],
        ];
    }

    // =============================================================================================
    //  1. THE POSITIVE CONTROL FIRST
    // =============================================================================================

    /**
     * @dataProvider apiOrderWriters
     */
    public function test_an_ordinary_checkout_still_writes_one_order_and_moves_stock(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $response = $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store),
            "`$path` wrote no order on the happy path, so every refusal below proves nothing. "
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "`$path` moved no stock on the happy path");
    }

    // =============================================================================================
    //  2. THE DEFECT
    // =============================================================================================

    /**
     * TWO POSTS OF ONE CHECKOUT MUST WRITE ONE ORDER.
     *
     * The cart is re-seeded before the second post because a completed order clears it — so
     * without the re-seed the second post is stopped by an empty basket rather than by a control,
     * and the case would pass against entirely unguarded code.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_repeated_checkout_post_does_not_write_a_second_order(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $first = $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store),
            'premise: the first post did not write an order. Body: '
            . substr((string) $first->getContent(), 0, 300));
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product), 'premise: no stock moved');

        $this->reseedCart($store, $customer, $product);

        $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(1, $this->ordersIn($store),
            "a repeated post to `$path` wrote a SECOND order for one checkout. On a mobile "
            . 'network a timed-out request has still arrived, so the retry is the ordinary case '
            . 'rather than the attack.');
        $this->assertSame(self::OPENING_STOCK - 2, $this->stockOf($product),
            "a repeated post to `$path` decremented stock a second time for one checkout");
    }

    // =============================================================================================
    //  3. THE CLAIM MUST NOT BLOCK REAL TRADE
    // =============================================================================================

    /**
     * A SHOPPER BUYING AGAIN IS NOT A DUPLICATE. A control that refused this would stop a store
     * selling, which is worse than the defect it fixes.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_a_genuinely_different_basket_is_not_refused(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        /* The same shopper, back for three more of the same item. */
        $this->reseedCart($store, $customer, $product, 3);

        $this->placeOrder($path, $store, $customer, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            "`$path` refused a genuinely different basket as a duplicate, so the store cannot "
            . 'take a repeat order');
        $this->assertSame(self::OPENING_STOCK - 5, $this->stockOf($product));
    }

    /**
     * AND ANOTHER SHOPPER'S IDENTICAL BASKET IS NOT A DUPLICATE EITHER. The fingerprint carries the
     * SERVER-RESOLVED customer, so one shopper cannot evict or collide with another's claim — the
     * standing rule in this codebase being that a predicate built from caller input is not a
     * control. This handler was once exploitable through exactly that input.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_another_shoppers_identical_basket_is_not_refused(string $path): void
    {
        [$store, $customer, $product, $shippingId] = $this->checkout();

        $this->placeOrder($path, $store, $customer, $shippingId);
        $this->assertSame(1, $this->ordersIn($store), 'premise');

        $second = Customer::forceCreate([
            'first_name' => 'Second', 'last_name' => 'Shopper',
            'email' => 'second-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        Cart::forceCreate([
            'customer_id' => $second->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        $this->placeOrder($path, $store, $second, $shippingId);

        $this->assertSame(2, $this->ordersIn($store),
            "`$path` refused a second shopper's identical basket as a duplicate of the first "
            . "shopper's order, so one customer's checkout is blocking another's");
        $this->assertSame(self::OPENING_STOCK - 4, $this->stockOf($product));
    }

    // ============================================================================== the fixture

    private function ordersIn($store): int
    {
        return (int) DB::table('orders')->where('store_id', $store->id)->count();
    }

    /** Read through the table, NOT the model: `Product` is `Cachable`. */
    private function stockOf(Product $product): int
    {
        return (int) DB::table('products')->where('id', $product->id)->value('product_stock');
    }

    private function reseedCart($store, Customer $customer, Product $product, int $qty = 2): void
    {
        DB::table('carts')->where('customer_id', $customer->id)->delete();

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => $qty, 'price' => 10.00 * $qty, 'store_id' => $store->id,
        ]);
        (new Cart)->flushCache();
    }

    private function placeOrder(string $path, $store, Customer $customer, int $shippingId)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('f276')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, [
            'customer_id' => $customer->id,
            'billing_info' => [
                'firstname' => 'F276', 'lastname' => 'Shopper', 'email' => 'f276@example.test',
                'billing_user_telephone' => '555111222',
                'billing_address' => 'Street', 'billing_postecode' => '11111',
                'billing_country' => 'Testland', 'billing_state' => 'Teststate',
                'billing_city' => 'Testcity', 'billing_company_name' => '',
                'delivery_address' => 'Street', 'delivery_postcode' => '11111',
                'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
                'delivery_city' => 'Testcity',
            ],
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ]);
    }

    /**
     * A store with stock management ON, a customer, a two-unit cart and a shipping row.
     *
     * @return array{0: \App\Models\Store, 1: Customer, 2: Product, 3: int}
     */
    private function checkout(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'f276-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through `Cache::remember('admin_details', ...)`,
           so without this they see the pre-fixture User. */
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'F276', 'last_name' => 'Shopper',
            'email' => 'f276-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'f276-' . uniqid(), 'slug' => 'f276-' . uniqid(),
            'product_stock' => self::OPENING_STOCK, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10.00, 'sale_price' => 10.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        return [$store, $customer, $product, $shippingId];
    }
}
