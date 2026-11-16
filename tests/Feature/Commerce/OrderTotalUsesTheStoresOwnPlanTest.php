<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use App\Support\Commerce\StorePlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * ONE ARBITRARY TENANT'S PLAN DECIDED EVERY OTHER TENANT'S ORDER TOTALS.  P4-F014 / P4-F023 / P4-F024.
 * ===================================================================================================
 *
 * Twelve order writers resolved the plan governing their shipping arithmetic as
 *
 *     $user = Cache::remember('admin_details', 3600, fn () => User::where('type','admin')->first());
 *
 * — the first merchant in the shared database, by lowest id, cached under one global key. The
 * shopper, meanwhile, was QUOTED from the right plan (`CartController::cart_list_sidebar:38-40`,
 * `PaymentController::processOrder:1845-1846`), so this is a divergence inside a single request:
 * the order is written at a different total from the one the shopper was shown, and for COD from
 * the one the driver is told to collect.
 *
 * WHY NO EXISTING TEST SAW IT, and this is the part worth keeping. Every fixture in this suite
 * builds ONE tenant, and with one merchant the first `type='admin'` row IS that store's owner.
 * Several fixtures even call `Cache::forget('admin_details')` — the suite knew the key was
 * load-bearing and never asked whose row it was. It takes a second tenant whose plan DISAGREES.
 *
 * THE SYMPTOM THIS DRIVES is `ordersave`'s, because it needs no shipping-method fixture to show.
 * `Api\ApiController::ordersave` at `:9668-9669`:
 *
 *     if ($plan->shipping_method == "on") {
 *         $order->final_price = (float) $order_array['delivery_charge'] + $order_array['tax']['tax_price'];
 *     }
 *
 * The product subtotal is **not in that expression at all** (P4-F023, fixed in the same change).
 * So when the foreign plan says `on` and this store charges no delivery, the order total is
 * `0 + tax` — the basket is simply gone. Both halves are needed: resolving the right plan stops
 * the wrong branch being taken, and repairing the branch stops the subtotal vanishing when a store
 * legitimately has shipping on.
 *
 * WHICH TEST PROVES WHICH HALF — MEASURED, by reverting each fix separately, because the first
 * version of this file would have overclaimed. The two defects partly mask each other:
 *
 *     state of the code                     test_an_order_is_priced_...   test_a_shipping_on_store_...
 *     ----------------------------------    ---------------------------   ---------------------------
 *     both defects present                  RED   0.00 vs 100.00          RED   100.00 vs 125.00
 *     plan read reverted, F023 fixed        green 100.00 (see below)      RED   100.00 vs 125.00
 *     both fixed                            green                         green
 *
 * So `test_a_shipping_on_store_still_charges_for_the_products` is the one that **isolates the plan
 * resolution**, and the other is red only against the combination. The reason is worth stating,
 * because it is also why the delivery charge in the fixture is load-bearing: `ordersave` resolves
 * the plan CORRECTLY at `:9151` to decide whether to compute a delivery price at all, and only the
 * total arithmetic at `:9594` read the wrong tenant. When the store's own plan says shipping is
 * off, no delivery is computed, so once F023 puts the subtotal back into both branches the two
 * branches agree at `basket + 0 + tax` and the wrong read becomes invisible. It takes a store whose
 * OWN plan charges delivery for the foreign plan's "off" to drop a real 25.00.
 *
 * THE FIRST MERCHANT IS CREATED FIRST ON PURPOSE. `->first()` with no order clause returns the
 * lowest id, so tenant A must exist before tenant B or the fixture does not reproduce anything.
 * `test_the_fixture_really_makes_the_foreign_plan_the_one_the_old_code_would_pick` pins that, and
 * it is not decoration: if tenant A stopped being the first row, every assertion below would pass
 * against the defect.
 */
class OrderTotalUsesTheStoresOwnPlanTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;
    private const DELIVERY = 25.00;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::forget('admin_details');
    }

    /**
     * @param  string  $shipping  'on' or 'off' for this tenant's own plan
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: int}
     */
    private function tenantWithPlan(string $shipping): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'plan-' . $shipping . '-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => $shipping,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();

        /*
         * A REAL DELIVERY CHARGE, and it is load-bearing rather than fixture dressing.
         *
         * Once P4-F023 puts the product subtotal back into both branches, the only remaining
         * difference between them is `+ delivery_charge`. With a charge of 0.00 the two branches
         * agree and the wrong-tenant read is UNOBSERVABLE: the first version of this file passed
         * with the plan fix reverted, which is the trap this project keeps catching. `cost` must be
         * non-zero for the assertions below to be about which plan was consulted.
         *
         * `shipping_methods.zone_id` is NOT NULL with a foreign key, so the zone comes first --
         * the shape `DeliveryCommissionIdempotencyTest:77-93` already uses.
         */
        $zoneId = (int) DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'plan-fixture-zone', 'shipping_method' => 'flat rate',
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = (int) DB::table('shipping_methods')->insertGetId([
            'method_name' => 'Flat Rate', 'zone_id' => $zoneId, 'cost' => (string) self::DELIVERY,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $methodId];
    }

    private function shopperWithBasket($store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'sp-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id]);

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

    private function saveOrder($store, Customer $customer, int $shippingId)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/order-save', [
                'customer_id' => $customer->id,
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ]);
    }

    private function orderIn($store): ?Order
    {
        return Order::withoutGlobalScopes()->where('store_id', $store->id)->latest('id')->first();
    }

    /** The fixture premise. Without it every assertion below could pass against the defect. */
    public function test_the_fixture_really_makes_the_foreign_plan_the_one_the_old_code_would_pick(): void
    {
        [$first] = $this->tenantWithPlan('on');
        [$second, $storeB] = $this->tenantWithPlan('off');

        $oldCodeWouldPick = \App\Models\User::where('type', 'admin')->first();

        $this->assertSame($first->id, $oldCodeWouldPick->id,
            'The first type=admin row is not tenant A, so the old resolution would have picked the '
            . 'store\'s own owner by accident and this file proves nothing.');
        $this->assertNotSame($second->id, $oldCodeWouldPick->id,
            'Tenant B IS the first admin row, so there is no foreign plan to confuse.');
        $this->assertSame($second->id, StorePlan::ownerOf($storeB)->id,
            'StorePlan did not resolve store B to its own owner.');
    }

    /**
     * THE DEFECT, END TO END. Store B charges no delivery; the foreign plan says it does.
     */
    public function test_an_order_is_priced_from_its_own_stores_plan(): void
    {
        $this->tenantWithPlan('on');                                  // tenant A, the foreign plan
        [, $storeB, $shippingId] = $this->tenantWithPlan('off');      // the store being bought from
        $customer = $this->shopperWithBasket($storeB);

        $this->saveOrder($storeB, $customer, $shippingId);
        $order = $this->orderIn($storeB);

        $this->assertNotNull($order, 'Premise: order-save wrote an order.');
        $this->assertEqualsWithDelta(0.0, (float) $order->delivery_price, 0.005,
            'Premise: store B charges no delivery, so none was computed.');
        $this->assertEqualsWithDelta(self::BASKET, (float) $order->final_price, 0.005,
            'The order total is ' . ($order->final_price ?? '?') . ' on a 100.00 basket with no '
            . "delivery. Another tenant's plan said shipping was on, so the writer added a "
            . 'delivery component this store does not charge.');
    }

    /**
     * AND THE REPAIRED BRANCH. When the store's OWN plan has shipping on, the subtotal must still
     * be in the total — the half of this that is not about which tenant was asked.
     */
    public function test_a_shipping_on_store_still_charges_for_the_products(): void
    {
        $this->tenantWithPlan('off');                                 // tenant A, the foreign plan
        [, $storeB, $shippingId] = $this->tenantWithPlan('on');
        $customer = $this->shopperWithBasket($storeB);

        $this->saveOrder($storeB, $customer, $shippingId);
        $order = $this->orderIn($storeB);

        $this->assertNotNull($order, 'Premise: order-save wrote an order.');
        $this->assertEqualsWithDelta(self::DELIVERY, (float) $order->delivery_price, 0.005,
            'Premise: store B\'s own plan has shipping on, so a 25.00 delivery charge was '
            . 'computed. ordersave resolves the plan CORRECTLY at :9151 for that decision -- it '
            . 'was only the total arithmetic that read the wrong tenant.');
        $this->assertEqualsWithDelta(self::BASKET + self::DELIVERY, (float) $order->final_price, 0.005,
            'The order total is ' . ($order->final_price ?? '?') . ' for a 100.00 basket plus a '
            . '25.00 delivery charge. Either the writer consulted the foreign plan (which says '
            . 'shipping is off, so it dropped the delivery the store did charge) or the '
            . 'shipping-on arm dropped the product subtotal. Both were true before P4-F023.');
    }

    /**
     * A FRACTIONAL BASKET MUST SURVIVE INTO THE ORDER.  P4-F121, pre-existing, found by review of
     * the expression this package rewrote.
     *
     * `SetNumber()` is applied at `ApiController.php:9616` to a value that is ALREADY a
     * `SetNumber()` string, and its string branch keeps only the first run of digits
     * (`helper.php:292-295`: `preg_match('/\d+/', $number, $matches)`). EXECUTED:
     *
     *     SetNumber(99.99)              = 99.99
     *     SetNumber("99.99")            = 99.00      <- the cents are gone
     *     SetNumber(SetNumber(99.99))   = 99.00
     *
     * And `$cartlist_final_price` IS a string on both paths: `:8981` takes `$cartlist['final_price']`,
     * published through `SetNumber()` at `:1037`, and `:9135` takes `$apply_coupon['final_price']`,
     * published through `SetNumber()` at `:4922`. So `orders.product_price` and `orders.final_price`
     * lost up to 0.99 on every order placed through `api/{slug}/order-save` -- shopper-favourable,
     * and `product_price` is the merchant's ledger.
     *
     * WHY NO TEST SAW IT: every order-writer fixture in this suite uses an integer-valued basket,
     * where the truncation is a no-op. That is the third variant of one shape in this package --
     * the order-dependence was invisible because no test varied row order, the negative-line
     * overshoot because no test built a malformed line, and this because no test priced in cents.
     */
    public function test_a_fractional_basket_is_not_truncated_to_whole_units(): void
    {
        $this->tenantWithPlan('off');
        [, $storeB, $methodId] = $this->tenantWithPlan('off');

        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'frac-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $storeB->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => 99.99, 'sale_price' => 99.99,
            'status' => 1, 'stock_status' => 'in_stock',
            'store_id' => $storeB->id, 'created_by' => $storeB->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 99.99, 'store_id' => $storeB->id]);

        $this->saveOrder($storeB, $customer, $methodId);
        $order = $this->orderIn($storeB);

        $this->assertNotNull($order, 'Premise: order-save wrote an order.');
        $this->assertEqualsWithDelta(99.99, (float) $order->product_price, 0.005,
            'orders.product_price is ' . ($order->product_price ?? '?') . ' for a 99.99 basket. '
            . 'SetNumber() was handed its own output, and its string branch keeps only the integer '
            . 'part, so the cents were dropped from the merchant\'s ledger.');
        $this->assertEqualsWithDelta(99.99, (float) $order->final_price, 0.005,
            'orders.final_price is ' . ($order->final_price ?? '?') . ' for a 99.99 basket.');
    }

    /** The seam itself, including the null case the twelve call sites now guard. */
    public function test_the_seam_refuses_to_guess(): void
    {
        $this->assertNull(StorePlan::ownerOf(null),
            'A null store resolved an owner.');
        $this->assertNull(StorePlan::ownerOf(0),
            'A zero store id resolved an owner.');

        [$merchant, $store] = $this->tenantWithPlan('on');
        $this->assertSame($merchant->id, StorePlan::ownerOf($store)->id,
            'The seam did not resolve a store to its own owner.');
        $this->assertSame($merchant->id, StorePlan::ownerOf($store->id)->id,
            'The seam did not resolve a store id to its own owner.');
    }
}
