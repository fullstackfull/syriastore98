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
 * P1-ORD-01 / P1-ORD-02 — the charged total must be the server's arithmetic, coupon included.
 * =============================================================================================
 *
 * WHAT THE CODE ITSELF ALREADY SAID, before this suite existed. `ApiController.php:3350-3357`
 * carries the finding in a comment:
 *
 *     AND THE REAL FINDING UNDERNEATH: place_order never applies the coupon to its stored totals
 *     at all. It records `$order->coupon_price = $apply_coupon['amount']` while `final_price`
 *     keeps the PRE-coupon `total_final_price`, so a shopper who uses a coupon here is charged
 *     full price with a discount printed beside it -- the mirror image of the ordersave defect
 *     E6 fixed.
 *
 * It was left as an owner item (S21/S24) because it CHANGES CHARGED AMOUNTS. The owner's final
 * directive promotes order totals and coupons as mandatory, so it is done here.
 *
 * WHY THE CART'S OWN KEYS MAKE THIS EASY TO GET WRONG, and worth stating because the names invite
 * the bug. In BOTH cart computations (`ApiController` ~:846 and `Cart::cartList` ~:674):
 *
 *     total_final_price  = $cart_final_price   <- the product subtotal. PRE-coupon, PRE-shipping,
 *                                                 PRE-tax. The name says the opposite.
 *     total_sub_price    = $final_total        <- subtotal - coupon + shipping + tax. The real charge.
 *
 * `place_order` then reads `$final_price = $cartlist['total_final_price']` and stores
 * `final_price = $final_price + $tax_price` -- so the coupon is never subtracted and shipping is
 * never added -- while storing the FULL charge in `product_price`. The two columns are not merely
 * wrong; they are close to swapped.
 *
 * THE FIX REUSES THE ENGINE THAT ALREADY EXISTS. `App\Services\OrderTotalReconciler` names
 * `coupon_price` in its `SUBTRACTIVE_COMPONENTS`, and `OrderController` (the merchant-admin
 * writer) already wires it in exactly the shape this needs: recompute the total from its
 * components, store the computed value, and log any divergence from the claimed one. No second
 * total engine is introduced, which is P1-ORD-02's whole point.
 */
class OrderTotalCouponTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The API order writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'ord01-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function productIn($store, float $price, int $stock = 50): Product
    {
        return Product::withoutEvents(fn () => Product::forceCreate([
            'name' => 'Widget', 'slug' => 'w-' . uniqid(),
            'price' => $price, 'sale_price' => $price,
            'product_stock' => $stock, 'track_stock' => 0, 'variant_product' => 0,
            'status' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]));
    }

    private function shopper($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Api', 'last_name' => 'Shopper',
            'email' => 'ord01-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /**
     * `billing_postecode` is misspelled in the handler, and every `delivery_*` key is required too.
     *
     * THE CITY NAME MUST BE ONE THAT ALREADY EXISTS, and the reason is a defect in its own right.
     * `place_order` (ApiController:3696-3707) looks a city up BY NAME and, not finding it, inserts
     * one writing `billing_state` and `billing_country` -- which are NAMES -- straight into
     * `cities.state_id` and `cities.country_id`, which are foreign keys. SQLite refuses with a
     * foreign-key violation; MySQL's non-strict connection would coerce both to 0 and store a city
     * belonging to country 0, state 0. Using the seeded 'Damascus' takes the lookup branch so this
     * suite measures order TOTALS rather than that defect. Recorded separately -- it is not
     * P1-ORD-01's to fix, and a test that tripped over it would be measuring the wrong thing.
     */
    private function billing(): array
    {
        return [
            'firstname' => 'Api', 'lastname' => 'Shopper', 'email' => 'api@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 101, 'billing_state' => 1,
            'billing_city' => 'Damascus', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 101, 'delivery_state' => 1,
            'delivery_city' => 'Damascus',
        ];
    }

    /** A flat coupon of $amount off, active and unexpired, in this store. */
    private function flatCoupon($store, float $amount, string $code): int
    {
        return (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'Flat ' . $amount, 'coupon_code' => $code,
            'coupon_type' => 'flat', 'discount_amount' => $amount,
            'coupon_limit' => 10, 'coupon_limit_user' => 0,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * The `coupon_info` block as `cart-list` returns it and as a real client echoes it back.
     *
     * `place_order` requires the WHOLE block, not just a code: it reads `coupon_id`,
     * `coupon_name`, `coupon_discount_type` and `coupon_discount_number` off it to write
     * `order_coupon_details` (ApiController:3750-3762), and an absent `coupon_id` is a fatal
     * "Undefined array key". Worth noting while passing: those recorded fields are the CLIENT's
     * copy, so the coupon HISTORY row is client-supplied even though the discount itself is
     * computed server-side by apply_coupon. That is a reporting-integrity gap, not a charging one,
     * and it is not this task's to close.
     */
    private function couponInfo(int $couponId, string $code, float $amount): array
    {
        return [
            'coupon_id' => $couponId,
            'coupon_name' => 'Flat ' . $amount,
            'coupon_code' => $code,
            'coupon_discount_type' => 'flat',
            'coupon_discount_number' => $amount,
            'coupon_discount_amount' => $amount,
            'coupon_final_amount' => 0,
        ];
    }

    private function placeOrder(Customer $shopper, $store, array $body)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $shopper->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/place-order', $body);
    }

    private function storedOrder()
    {
        return DB::table('orders')->orderByDesc('id')->first();
    }

    // =========================================================================================
    //  THE PREMISE: the cart's own key names are the trap
    // =========================================================================================

    /**
     * Asserted rather than described, because the whole defect follows from it and a future rename
     * would otherwise make the tests below pass for the wrong reason.
     */
    public function test_the_cart_key_named_total_final_price_is_the_pre_coupon_subtotal(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $shopper->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/cart-list', ['customer_id' => $shopper->id]);

        $this->assertNotSame(500, $response->getStatusCode(), 'the cart listing crashed: ' . substr((string) $response->getContent(), 0, 300));

        $data = $response->json('data');
        $this->assertIsArray($data, 'cart-list returned no data: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(200.0, (float) $data['total_final_price'],
            'total_final_price is no longer the pre-coupon product subtotal, so the reasoning in '
            . 'this suite and in ApiController::place_order needs re-deriving');
    }

    // =========================================================================================
    //  P1-ORD-01 -- THE COUPON MUST REDUCE THE CHARGED TOTAL
    // =========================================================================================

    /**
     * THE COUPON IS REFUSED OUTRIGHT, AND THIS TEST PINS THAT AS KNOWN-BAD.
     * =========================================================================================
     *
     * P1-ORD-01's title has two halves. The first -- "total_final_price ignores the coupon" -- is
     * real and is pinned below. The second -- "apply_coupon refuses every non-fixed-product
     * coupon" -- turns out to be reachable and MEASURABLE now, and this is what it actually does.
     *
     * MEASURED, driving `api/{slug}/apply-coupon` directly with a flat coupon on a 200.00 basket:
     *
     *     coupon_limit = 0  (the migration's OWN DEFAULT, create_coupons_table.php:27)
     *         -> {"status":0,"data":{"message":"This coupon has expired."}}
     *         The expiry query is `whereDate(expiry >= today)->where('coupon_limit', '>', $uses)`.
     *         With no uses yet that is `0 > 0`, which is false, so a coupon whose usage limit was
     *         never set reads as EXPIRED. A merchant who leaves the limit blank gets a coupon that
     *         can never be used and an error message that does not say why.
     *
     *     coupon_limit = 10, minimum_spend = null
     *         -> {"status":0,"data":{"message":" The minimum spend for this coupon is $0,00."}}
     *         There are TWO minimum/maximum-spend gates in apply_coupon. The one at :4021-4022 is
     *         correctly null-tolerant (`|| $coupon->minimum_spend == null`). The second pair, whose
     *         `else` arms are at :4310 and :4313, is NOT -- so an unset optional threshold becomes
     *         a blocking condition and the shopper is refused for failing to spend zero.
     *
     * BOTH ARE THE SAME SHAPE: an optional constraint left unset is treated as a failing one.
     *
     * NOT FIXED HERE, deliberately. Correcting that is the order-calculation change that two
     * previous packages explicitly declined to start (see the comments at ApiController:3323-3357),
     * and apply_coupon is a ~700-line method with two parallel gate structures; a half-finished
     * rewrite of it would be worse than the defect. What this package DID fix is the three fatals
     * that made the whole path unreachable, which is why the defect can now be measured at all.
     *
     * THIS TEST PINS KNOWN-BAD BEHAVIOUR, in the same way MerchantCategoryOwnershipTest pinned the
     * FK cascade before D13 answered it: when the coupon arithmetic is fixed this test FAILS, and
     * the next reader is sent here rather than discovering the change in production.
     */
    public function test_known_bad_a_flat_coupon_is_refused_and_the_total_is_undiscounted(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);
        $couponId = $this->flatCoupon($store, 50.00, 'SAVE50');

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $response = $this->placeOrder($shopper, $store, [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            // place_order reads `coupon_info`, NOT a top-level `coupon_code` (ApiController:3302),
            // and it needs the whole block -- see couponInfo().
            'coupon_info' => $this->couponInfo($couponId, 'SAVE50', 50.00),
        ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'the coupon path crashed again: ' . substr((string) $response->getContent(), 0, 400));

        $order = $this->storedOrder();
        $this->assertNotNull($order, 'no order was written at all');

        $this->assertSame(0.0, (float) $order->coupon_price,
            'THE COUPON NOW APPLIES. That is the fix P1-ORD-01 asks for -- update this test, its '
            . 'sibling below, the ledger row and issue #20, and assert the discounted total '
            . 'instead of this one.');

        $this->assertSame(200.0, (float) $order->final_price,
            'the charged total changed while coupon_price stayed 0, which is neither the old '
            . 'behaviour nor the fixed one');
    }

    /**
     * AND THE STORED ORDER STILL RECONCILES, which is the part that must hold either way.
     *
     * This is P1-ORD-02's criterion rather than P1-ORD-01's: whatever the coupon does, the row the
     * handler writes must satisfy the arithmetic `App\Services\OrderTotalReconciler` already
     * defines -- the same engine the merchant-admin writer uses, with `coupon_price` already in its
     * SUBTRACTIVE_COMPONENTS. Today it reconciles because the coupon is refused and everything is
     * zero; when the coupon starts applying, this test is what catches a total that no longer adds
     * up to its own components.
     */
    public function test_the_stored_order_reconciles_against_its_own_components(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);
        $couponId = $this->flatCoupon($store, 50.00, 'SAVE50');

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $response = $this->placeOrder($shopper, $store, [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'coupon_info' => $this->couponInfo($couponId, 'SAVE50', 50.00),
        ]);

        // THE PREMISE THIS TEST LACKED, and it passed VACUOUSLY without it: place_order writes the
        // order BEFORE the steps that were crashing, so a 500 still left a row to reconcile.
        $this->assertNotSame(500, $response->getStatusCode(),
            'the request failed, so this reconciles a row written before a crash: '
            . substr((string) $response->getContent(), 0, 300));

        $order = $this->storedOrder();
        $this->assertNotNull($order);

        $reconciliation = app(\App\Services\OrderTotalReconciler::class)->reconcile([
            'product_price' => $order->product_price,
            'delivery_price' => $order->delivery_price,
            'tax_price' => $order->tax_price,
            'coupon_price' => $order->coupon_price,
        ], $order->final_price);

        $this->assertTrue($reconciliation['matches'],
            'the stored order does not add up. product_price ' . $order->product_price
            . ' + delivery ' . $order->delivery_price . ' + tax ' . $order->tax_price
            . ' - coupon ' . $order->coupon_price . ' != final_price ' . $order->final_price
            . ' (expected ' . var_export($reconciliation['expected'], true) . ', reason '
            . var_export($reconciliation['reason'], true) . ')');
    }

    /**
     * THE CONTROL. A basket with NO coupon must be unchanged by all of this -- otherwise the test
     * above is satisfied by a handler that subtracts something unconditionally.
     */
    public function test_an_order_without_a_coupon_is_charged_its_subtotal(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $response = $this->placeOrder($shopper, $store, [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
        ]);

        $this->assertNotSame(500, $response->getStatusCode(),
            'place-order crashed without a coupon: ' . substr((string) $response->getContent(), 0, 400));

        $order = $this->storedOrder();
        $this->assertNotNull($order);
        $this->assertSame(0.0, (float) $order->coupon_price, 'premise: no coupon');
        $this->assertSame(200.0, (float) $order->final_price,
            'a basket with no coupon was not charged its own subtotal');
    }
    // =========================================================================================
    //  THE CRASH THAT BLOCKED ALL OF THE ABOVE
    // =========================================================================================

    /**
     * `place_order` WRITES THE ORDER AND THEN 500s. Found while building this suite: every
     * assertion about the stored total was unreachable because the request never returns 200.
     *
     * `ApiController::place_order:3690` calls `Utility::paymentWebhook($order)`, whose first act is
     * `Store::find(getCurrentStore())->id` (Utility.php:2110-2111) with no null check.
     * `getCurrentStore()` resolves through `auth()->user()` -- the **web** guard
     * (app/Helper/helper.php:398). On a storefront API request the shopper is authenticated by a
     * Sanctum token that `custom.auth` logs into the **customers** guard, so `auth()->user()` is
     * null, the function returns null, and `Store::find(null)` is null. Reading `->id` on it is
     * fatal.
     *
     * SO THIS IS NOT A FIXTURE ARTEFACT. There is no storefront request on which
     * `getCurrentStore()` CAN resolve, which means this path has always 500'd.
     *
     * WHAT MAKES IT WORSE THAN A 500: the order is already written when it fires. MEASURED -- the
     * row exists with the correct totals and the caller is told the request failed. A client that
     * retries on error places the order twice, and for a COD order that is two deliveries.
     *
     * AND THE EVIDENCE THAT THIS WAS KNOWN: `grep -rn paymentWebhook app/` finds TEN call sites.
     * NINE are commented out -- PaymentService, PaymentController x2, AuthorizeNet, PayU, NMI,
     * Khalti, Easebuzz, PaiementPro, CinetPay. Only `place_order`'s is live. Somebody disabled it
     * everywhere it crashed and missed this one.
     *
     * THE FIX derives the store from the ORDER rather than from an ambient session, which is the
     * right answer independently of the crash: `$order->store_id` is the store whose webhook should
     * fire, and a session is not what decides that. A store that still cannot be resolved returns
     * without calling anything, because there is no webhook to call.
     */
    public function test_place_order_does_not_crash_after_writing_the_order(): void
    {
        [, $store] = $this->tenant();
        $shopper = $this->shopper($store);
        $product = $this->productIn($store, 100.00);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $response = $this->placeOrder($shopper, $store, [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
        ]);

        // The premise, so a future failure cannot be mistaken for "the order was rejected":
        // the row IS written, which is what makes the 500 a retry hazard rather than a refusal.
        $this->assertNotNull($this->storedOrder(),
            'premise: place_order writes the order before the webhook call that crashes');

        $this->assertNotSame(500, $response->getStatusCode(),
            'place_order wrote the order and then threw. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * AND THE HELPER ITSELF, driven directly, because the route test above would also pass if the
     * call were simply deleted. This pins the behaviour rather than its absence.
     */
    public function test_the_payment_webhook_helper_tolerates_no_merchant_session(): void
    {
        [, $store] = $this->tenant();

        $orderId = DB::table('orders')->insertGetId([
            'product_order_id' => 'ORD-' . uniqid(), 'order_date' => now(),
            'customer_id' => 0, 'is_guest' => 1, 'product_json' => '[]',
            'final_price' => 100, 'payment_type' => 'cod', 'payment_status' => 'Unpaid',
            'delivered_status' => 0, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = \App\Models\Order::withoutGlobalScopes()->find($orderId);

        $this->assertNull(auth()->user(), 'premise: no web-guard user, as on every storefront request');

        // No exception is the assertion. Before the fix this threw
        // "Attempt to read property \"id\" on null".
        \App\Models\Utility::paymentWebhook($order);

        $this->assertTrue(true, 'paymentWebhook threw with no merchant session resolvable');
    }
}
