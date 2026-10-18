<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-COMMERCE-SEC-01 -- what a gateway-return handler may trust.
 *
 * THE DEFECT. Five of the six storefront gateway-return handlers opened with
 *
 *     $requests_data = Session::get('request_data');
 *     if ($requests_data == null) { $requests_data = $request->all(); }
 *     $slug = !empty($requests_data['slug']) ? $requests_data['slug'] : '';
 *     $store = getStore($slug);
 *     $customer_id = $requests_data['customer_id'] ?? '';
 *
 * An attacker does not send the session cookie. The fallback then hands them every field the
 * handler goes on to trust -- the prices, the shipping, the coupon, the cart contents, the STORE
 * the order is written to and the CUSTOMER it is written for. So it is not only an amount defect:
 * it is a cross-store, cross-customer order writer with no ownership predicate on the path.
 *
 * EASEBUZZ WAS THE INVERSE, and worse: it read the state from a REQUEST parameter first and used
 * the session only as a fallback, so no expired session was even needed.
 *
 * WHAT THESE TESTS DO AND DO NOT PROVE, stated plainly because the distinction is the whole point
 * of the brief:
 *
 *   - They prove the TRUST BOUNDARY: with no server-stored checkout state, these routes write no
 *     order and move no stock, and the browser cannot supply that state.
 *   - They do NOT prove that any payment was verified. None of these six asks its gateway whether
 *     money moved; that is a separate, credential-blocked problem recorded in
 *     docs/security/P01-SECURITY-WORK-QUEUE.md. A test here that claimed otherwise would be
 *     exactly the "simulated validation mistaken for real gateway verification" the brief warns
 *     against.
 *
 * Every assertion below fails when the corresponding guard is removed -- the reverts are recorded
 * in the package report.
 */
class GatewayReturnTrustBoundaryTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * The guard's own refusal text, from GuardsGatewayReturn::gatewayReturnRefusal().
     *
     * WHY EVERY TEST BELOW ASSERTS THIS and not only "no order was written": the no-order
     * assertion PASSES AGAINST THE VULNERABLE CODE. Measured -- with the old
     * `$requests_data = $request->all()` fallback restored, the three denial tests here still
     * passed, because the handler goes on to raise "Undefined array key data"
     * (AuthorizeNetPaymnetController.php:271) and answers 500, so no order is written either way.
     * That is precisely the "tests that pass merely because the route crashes" the brief forbids.
     * The refusal message is the one observable that only this guard produces.
     */
    private const REFUSAL = 'We could not confirm your checkout details';

    /**
     * The storefront order-writing return routes, as routes/customer.php declares them.
     *
     * WHERE PAYU IS, AND A CORRECTION. PayU has no entry of its own, and tracing why found a
     * SEVENTH handler with the same defect. `/store-payu-payment/status` -> storeGetPayUStatus
     * writes no order: it redirects to route('store.payment.status'). That is
     * PaymentController::getProductStatus -- the GENERIC storefront return, the writer for PayU
     * and for every other provider whose initiation ends at that URL -- and it carried the same
     * `if ($requests_data == null) { $requests_data = $request->all(); }` fallback, 65 lines below
     * its session read. An earlier commit message in this package asserted that handler "reads the
     * session only"; that was wrong, and `storefront-return` below is the entry that covers it.
     *
     * PayUPaymentController::getProductStatus also carries the fallback shape and IS dead code --
     * no route declares it -- so it gets no state guard and no quantity guard, and is neither
     * fixed nor deleted. (Its literal 'Paid' was wired along with the rest in 950a9830; on an
     * unrouted method that is a no-op.) If it is ever routed it must be guarded first.
     *
     * @return array<string, array{0: string, 1: string}>  label => [uri template, method]
     */
    public static function gatewayReturnRoutes(): array
    {
        return [
            'khalti' => ['{slug}/order-khalti', 'post'],
            'authorizenet' => ['{slug}/authorizenet-status', 'post'],
            'paiementpro' => ['{slug}/get-payment-paiementpro', 'post'],
            'cinetpay' => ['{slug}/get-payment-cinetPay', 'post'],
            'easebuzz' => ['{slug}/get-payment-easebuzz', 'post'],
            'storefront-return' => ['{slug}/get-payment-status', 'post'],
        ];
    }

    /**
     * The routes whose stock loop can actually be REACHED in this environment.
     *
     * AuthorizeNet is excluded and the reason is a finding, not a convenience.
     * AuthorizeNetPaymnetController::getOrderPaymentStatus is not a gateway return -- it is the
     * charge -- so before it reaches the stock loop it builds a live Authorize.Net
     * `authCaptureTransaction`. With no SDK transaction possible here, the builder throws into an
     * EMPTY `catch (\Exception $e) {}` at :298-299 and the next statement raises
     * "Undefined variable $requestNet" at :332. So its quantity guard is placed correctly (before
     * the stock loop, before any order row) but is NOT verified by execution, and the empty catch
     * is itself a pre-existing defect that makes the handler 500 whenever the SDK setup throws.
     * Both are recorded as blockers in the package report rather than guessed around.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function reachableStockLoopRoutes(): array
    {
        $routes = self::gatewayReturnRoutes();
        unset($routes['authorizenet']);

        return $routes;
    }

    /**
     * The returns whose ORDER WRITER is reachable in this environment, via the guest cart cookie.
     *
     * This is the only provider here that gets past the cart fetch to `new Order()`, and it is
     * what makes the identity test below non-vacuous. The guest branch of each handler reads its
     * basket from `Cart::cart_list_cookie()`, i.e. `Cart::where('cookie_session_id',
     * Cookie::get('cart'))` (app/Models/Cart.php:198-208) -- not from the authenticated
     * customer -- so seeding a row with a `cookie_session_id` and sending the matching (encrypted)
     * `cart` cookie drives a genuine guest checkout all the way to the write.
     *
     * EXCLUDED, with reasons:
     *   - authorizenet: fatals at "Undefined variable $requestNet" (:332) because the SDK setup
     *     throws into an empty `catch (\Exception $e) {}` (:298-299), so it never reaches the
     *     writer here. It carries the same code shape and the same fix; that fix is NOT verified
     *     by execution and is recorded as a blocker in the report.
     *   - storefront-return: PaymentController::getProductStatus merges the stored state
     *     unconditionally and so never had this defect; it is covered by the four tests above.
     *
     * paiementpro is deliberately INCLUDED although it was already fixed in 8a981bb0 (its
     * `$request->merge($requests_data)` sits above the branch, at :255). It is the POSITIVE
     * CONTROL: it passed this test before the fix in this commit and after it, which is what shows
     * the test is reading the identity and not merely "the handler did nothing".
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function guestWriterRoutes(): array
    {
        return [
            'khalti' => ['{slug}/order-khalti', 'post'],
            'paiementpro' => ['{slug}/get-payment-paiementpro', 'post'],
            'cinetpay' => ['{slug}/get-payment-cinetPay', 'post'],
            'easebuzz' => ['{slug}/get-payment-easebuzz', 'post'],
        ];
    }

    /**
     * The two handlers that reach `new Order()` here AND consult a shipping method.
     *
     * khalti stops at verifyPayment() and authorizenet fatals at :332, so neither reaches the
     * delivery_price write; cinetpay and easebuzz reach the writer but PaiementPro is the cleanest
     * of that family to drive. storefront-return is the one that mattered -- see the test.
     *
     * @return array<string, array{0: string}>
     */
    public static function shippingWriterRoutes(): array
    {
        return [
            'paiementpro' => ['{slug}/get-payment-paiementpro'],
            'storefront-return' => ['{slug}/get-payment-status'],
        ];
    }

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'gw-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        /*
         * `out_of_stock_threshold` and `notification` are seeded because without them these
         * handlers raise "Undefined array key" and answer 500 -- they read a raw settings pluck
         * that supplies no defaults, which is the SAME pre-existing defect P01-SEC-14 C3 fixed in
         * ordersave. Seeding them here keeps this file measuring the trust boundary; the crash
         * itself is fixed in its own commit and asserted in its own test.
         */
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

        /*
         * PaymentController::getProductStatus INSERTS a `cities` row from billing_info, so without
         * these FK parents it raises a FOREIGN KEY violation and 500s BEFORE the order write --
         * which would make every assertion against that handler vacuous. insertOrIgnore because
         * several tests build two tenants.
         */
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

        return [$merchant, $store];
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Gw', 'last_name' => $label,
            'email' => 'gw-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function productIn($store, float $price = 100.00, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'gw-' . uniqid(), 'slug' => 'gw-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** The shape PaymentService stores, with every money field the handlers read. */
    private function forgedCheckoutState($store, $customerId, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => $customerId,
            'payment_type' => 'khalti',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ], $overrides);
    }

    private static function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    // =====================================================================================
    //  THE TRUST BOUNDARY
    // =====================================================================================

    /**
     * WITH NO SERVER-STORED STATE, A FORGED RETURN WRITES NOTHING -- on every one of the five.
     *
     * The posted body is a complete, plausible checkout: a real store slug, a real customer id, a
     * real cart, and money fields of the attacker's choosing. Before the fix each handler took it
     * as the checkout state. There is no session, which is the only thing an attacker has to
     * arrange: just omit the cookie.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_forged_return_with_no_stored_state_writes_no_order(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $customer = $this->customerIn($store, 'victim');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $before = self::orderCount();
        $stockBefore = (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;

        $payload = $this->forgedCheckoutState($store, $customer->id, [
            'shipping_final_price' => -900,
            'responsecode' => 0,
            'status' => 'success',
            // Easebuzz read the whole state from this request field.
            'request_data' => json_encode($this->forgedCheckoutState($store, $customer->id, [
                'shipping_final_price' => -900,
            ])),
        ]);

        session()->forget('error');
        $this->{$method}('/' . str_replace('{slug}', $store->slug, $uri), $payload);

        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The guard did not refuse, so the assertions below would be satisfied by the handler '
            . 'crashing rather than by the control. Session error was: '
            . json_encode(session('error')));
        $this->assertSame($before, self::orderCount(),
            'A forged return with no server-stored checkout state wrote an order.');
        $this->assertSame($stockBefore, (int) Product::withoutGlobalScopes()
            ->find($product->id)->product_stock,
            'A forged return with no server-stored checkout state moved stock.');
    }

    /**
     * AND IT CANNOT NAME ANOTHER STORE OR ANOTHER CUSTOMER.
     *
     * The same forged return, but the state names store B and a customer of store B while the
     * route is store A's. Before the fix the handler took `slug` and `customer_id` from that body,
     * so the route's own store was irrelevant -- an order could be written into any tenant, for
     * any shopper, from one unauthenticated request.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_forged_return_cannot_name_another_store_or_customer(string $uri, string $method): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $victim = $this->customerIn($storeB, 'othertenant');
        $product = $this->productIn($storeB);
        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $storeB->id,
        ]);

        $before = self::orderCount();

        $payload = $this->forgedCheckoutState($storeB, $victim->id, [
            'responsecode' => 0,
            'status' => 'success',
            'request_data' => json_encode($this->forgedCheckoutState($storeB, $victim->id)),
        ]);

        // Posted at store A's route while naming store B in the body.
        session()->forget('error');
        $this->{$method}('/' . str_replace('{slug}', $storeA->slug, $uri), $payload);

        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The guard did not refuse, so the cross-tenant assertion below would be satisfied by a '
            . 'crash. Session error was: ' . json_encode(session('error')));
        $this->assertSame($before, self::orderCount(),
            'A forged return wrote an order while naming another store and another customer.');
        $this->assertSame(0, (int) DB::table('orders')->where('customer_id', $victim->id)->count(),
            "An order was written in another store's customer's name.");
    }

    /**
     * A REPLAYED RETURN IS STILL ONE ORDER AT MOST.
     *
     * The stored state is single-use: each handler calls Session::forget() after reading it, so a
     * second identical return finds nothing and is now refused rather than falling through to the
     * request. This asserts the REPLAY property that follows from the fix; it does not claim the
     * handlers have an idempotency key, which they do not -- see the report.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_replaying_a_return_cannot_multiply_orders(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $customer = $this->customerIn($store, 'replay');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $payload = $this->forgedCheckoutState($store, $customer->id, [
            'responsecode' => 0,
            'status' => 'success',
            'request_data' => json_encode($this->forgedCheckoutState($store, $customer->id)),
        ]);

        $before = self::orderCount();

        for ($i = 0; $i < 3; $i++) {
            session()->forget('error');
            $this->{$method}($path, $payload);
        }

        $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
            'The guard did not refuse the replay, so the order-count assertion below would be '
            . 'satisfied by a crash. Session error was: ' . json_encode(session('error')));
        $this->assertSame($before, self::orderCount(),
            'Replaying a forged return multiplied orders.');
    }

    /**
     * A NEGATIVE CART LINE CANNOT BE PRICED OR SHIPPED BY A GATEWAY RETURN.
     *
     * The stored state is present and legitimate -- this is a genuine shopper coming back from
     * the gateway -- and the cart has acquired a line with qty -5 since initiation. That is not
     * hypothetical: the cart stays mutable across the round trip (the rows are only deleted when
     * the order is written on the way back), and two vendored module routes write `carts.qty`
     * straight from the request with no validation
     * (packages/workdo/BoostSales/.../BoostSalesController.php:59 and
     * packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88 -- both under
     * plain `web`, both unfixable in place under the vendor guard).
     *
     * If it reaches the loop, `$remain_stock = $Product->product_stock - $qtyy` ADDS stock, and
     * the same line reduces every total it is summed into. The basket is worth 1000.00 and nets
     * positive on purpose, so a guard that only catches a basket summing to nothing would not
     * satisfy this.
     *
     * @dataProvider reachableStockLoopRoutes
     */
    public function test_a_negative_cart_line_is_refused_on_the_return(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $customer = $this->customerIn($store, 'negqty');
        $big = $this->productIn($store, 1000.00, 50);
        $cheap = $this->productIn($store, 50.00, 20);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $big->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 1000.00, 'store_id' => $store->id,
        ]);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $cheap->id, 'variant_id' => 0,
            'qty' => -5, 'price' => -250.00, 'store_id' => $store->id,
        ]);

        $state = $this->forgedCheckoutState($store, $customer->id);
        $before = self::orderCount();

        session()->forget('error');
        $this->actingAs($customer, 'customers')
            ->withSession([
                'request_data' => $state,
                'easebuzz_request_data' => $state,
            ])
            ->{$method}('/' . str_replace('{slug}', $store->slug, $uri), [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringContainsString('Your cart contains an invalid quantity',
            (string) session('error'),
            'The quantity guard did not fire, so the assertions below would be satisfied by the '
            . 'handler crashing instead. Session error was: ' . json_encode(session('error')));

        $this->assertSame($before, self::orderCount(),
            'An order was written for a basket holding a negative line.');
        $this->assertSame(20, (int) Product::withoutGlobalScopes()->find($cheap->id)->product_stock,
            "The merchant's stock moved for a refused order. It goes UP, not down, because the "
            . 'decrement subtracts a negative quantity.');
        $this->assertSame(50, (int) Product::withoutGlobalScopes()->find($big->id)->product_stock,
            'Stock moved for the legitimate line of a refused basket.');
    }

    /**
     * THE GUARD IS AN A/B ON ONE INPUT: the stored state, and nothing else.
     *
     * Each route is driven TWICE with the same body -- once with no stored state and once with it
     * present -- and the only thing asserted is whether the guard's own refusal fired. That is
     * deliberate, and it is what makes the test honest about what it can and cannot see:
     *
     *   - it cannot assert "the order completed", because completing a real return needs each
     *     gateway's own response (Khalti, for instance, calls verifyPayment() with a token from
     *     the return payload at KhaltiPaymnetController.php:597-603), and this environment has no
     *     gateway;
     *   - it must not assert "no 500", because several of these handlers raise pre-existing errors
     *     further down the happy path -- AuthorizeNetPaymnetController.php:271 reads
     *     `json_decode($input['data'])['get_amount']` and raises "Undefined array key data"
     *     without it. A 500 from a later stage says nothing about this guard, and treating it as
     *     evidence would make the test measure the wrong thing.
     *
     * So the property asserted is exactly the property added: with server-stored state the guard
     * stands aside, and without it the guard refuses. The absent-state half of the pair is what
     * fails if the guard is removed; the present-state half is what fails if the guard is a
     * blanket refusal. Neither can be satisfied by a crash.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_the_guard_refuses_only_when_the_stored_state_is_absent(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $customer = $this->customerIn($store, 'ab');
        $product = $this->productIn($store);
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $body = ['responsecode' => 0, 'status' => 'success'];
        $refusal = self::REFUSAL;
        $state = $this->forgedCheckoutState($store, $customer->id);

        // A: no stored state. The guard must refuse, by its own message.
        session()->forget('error');
        $this->{$method}($path, $body);
        $this->assertStringContainsString($refusal, (string) session('error'),
            'With no server-stored checkout state the guard did not refuse. Session error was: '
            . json_encode(session('error')));

        // B: the same request, with the state the server stores. The guard must stand aside.
        session()->forget('error');
        $this->withSession([
            'request_data' => $state,
            'easebuzz_request_data' => $state,
        ])->{$method}($path, $body);

        $this->assertStringNotContainsString($refusal, (string) session('error'),
            'With server-stored state present the guard still refused, so it blocks legitimate '
            . 'returns as well as forged ones. Session error was: ' . json_encode(session('error')));
    }

    // =====================================================================================
    //  THE IDENTITY ON THE RETURN
    // =====================================================================================

    /**
     * A GUEST RETURN CANNOT BE CLAIMED FOR ANOTHER CUSTOMER, AND CANNOT EMPTY THEIR BASKET.
     *
     * THE DEFECT, separate from the stored-state one and not closed by it. Each handler splits on
     * `if (!auth('customers')->user()) { ... } elseif (!empty($customer_id)) { ... }` and
     * `$request->merge($requests_data)` sits inside the SECOND arm only
     * (KhaltiPaymnetController.php:306, CinetPayController.php:472, EasebuzzController.php:347,
     * AuthorizeNetPaymnetController.php:409). So on the GUEST arm the request is never overwritten
     * by the stored state, and every `$request->customer_id` read further down is the POSTED
     * value: the order's owner (`$order->customer_id`), its `product_order_id`, its UserCoupon
     * row, and -- worst -- `Cart::where('customer_id', $request->customer_id)->delete()`.
     *
     * The stored-state guard does not close this. A guest doing a real checkout HAS valid stored
     * state; they simply add `customer_id=<victim>` to the return they are already making.
     *
     * MEASURED BEFORE THE FIX, by this fixture: cinetpay and easebuzz wrote an order owned by the
     * victim AND emptied the victim's cart in both stores; khalti emptied the victim's cart in
     * both stores. paiementpro did neither, because its merge is above the branch.
     *
     * @dataProvider guestWriterRoutes
     */
    public function test_a_guest_return_cannot_be_claimed_for_another_customer(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        [, $otherStore] = $this->tenant();
        $victim = $this->customerIn($store, 'claimvictim');
        $product = $this->productIn($store, 100.00, 50);
        $otherProduct = $this->productIn($otherStore, 70.00, 50);

        // The guest's own basket, found by cookie -- this is what makes the checkout genuine.
        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        // The victim's baskets: one in this store, one in a DIFFERENT store. The second is what
        // the missing store predicate on the delete reaches.
        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $otherProduct->id, 'variant_id' => 0,
            'qty' => 3, 'price' => 70.00, 'store_id' => $otherStore->id,
        ]);

        $state = $this->forgedCheckoutState($store, '');   // a GUEST state: no customer_id

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}('/' . str_replace('{slug}', $store->slug, $uri), [
                'responsecode' => 0,
                'status' => 'success',
                'customer_id' => $victim->id,      // the whole attack
            ]);

        $this->assertSame(0, (int) DB::table('orders')->where('customer_id', $victim->id)->count(),
            "A guest return was recorded as another customer's order by posting their id.");

        $this->assertSame(1, (int) DB::table('carts')
            ->where('customer_id', $victim->id)->where('store_id', $store->id)->count(),
            "A guest return emptied another customer's basket in this store.");

        // STATED HONESTLY: this row survives because the identity pin above stops the delete
        // running at all, NOT because the delete is store-scoped -- it is not. A store predicate
        // was tried here and REVERTED: removing it changes nothing on either principal that
        // reaches the line, because Cart's StoreScope already supplies one. Measured both ways.
        // So this assertion is a "nothing else was destroyed" check and is NOT evidence for a
        // store predicate; the unscoped delete is recorded as a residual in the package report.
        $this->assertSame(1, (int) DB::table('carts')
            ->where('customer_id', $victim->id)->where('store_id', $otherStore->id)->count(),
            'A guest return destroyed cart rows outside the store it was placed in.');
    }

    /**
     * THE POSITIVE CONTROL: a legitimate guest return STILL WRITES ITS ORDER.
     *
     * Without this, every refusal above could be satisfied by a handler that refuses everything.
     * This is the brief's "normal checkout / genuine completion" case, as far as it can honestly
     * be driven here: the stored state is the server's, the basket is the guest's own cookie
     * basket, nothing is forged -- and an order is written, owned by nobody (customer_id 0,
     * is_guest 1), for the SERVER's price.
     *
     * It is asserted on paiementpro alone, and that is a finding rather than a choice. Of the six,
     * it is the only one that reaches `new Order()` in this environment without a live gateway:
     * khalti stops at `$khalti->verifyPayment()` (:590), authorizenet fatals at :332, cinetpay and
     * easebuzz reach the writer but only after their own response checks, and
     * PaymentController::getProductStatus stops earlier still. What this test therefore proves is
     * that the guards added in this package do not block a legitimate return; it does not prove
     * any gateway verified a payment, which none of them does -- see the report.
     */
    public function test_a_legitimate_guest_return_still_writes_its_order(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $state = $this->forgedCheckoutState($store, '', ['payment_type' => 'paiementpro']);
        $before = self::orderCount();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-paiementpro', [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringNotContainsString(self::REFUSAL, (string) session('error'),
            'A legitimate guest return was refused by one of this package\'s guards. Session '
            . 'error was: ' . json_encode(session('error')));

        $this->assertSame($before + 1, self::orderCount(),
            'A legitimate guest return wrote no order, so every refusal asserted above could be '
            . 'satisfied by a handler that refuses everything.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(0, (int) $order->customer_id, 'A guest order acquired an owner.');
        $this->assertSame(1, (int) $order->is_guest, 'A guest order was not marked as a guest order.');
        $this->assertSame(100.00, round((float) $order->final_price, 2),
            "The order did not carry the SERVER's price for the guest's own basket.");
        $this->assertSame(49, (int) Product::withoutGlobalScopes()->find($product->id)->product_stock,
            'A completed order did not consume its stock.');
    }

    /**
     * THE POSTED MONEY DOES NOT REACH THE ORDER, even on a return that is otherwise genuine.
     *
     * Every other amount test in this file works by REMOVING the stored state, so it proves the
     * handler refuses rather than that it ignores the browser. This one is the opposite and is the
     * brief's "manipulated positive shipping amount" / "negative shipping amount" case directly:
     * the stored state is present and correct (one 100.00 line, zero shipping, zero tax), the
     * checkout is a real guest checkout that completes and writes its order -- and the return POST
     * carries the attacker's figures for every money field the handlers read.
     *
     * Both directions are driven, because they are different attacks and a guard that catches one
     * need not catch the other: inflate (a merchant paid for shipping nobody bought, and the
     * shopper's own wallet/COD figure follows the order total) and deflate below zero (which
     * `SetNumber()` at app/Helper/helper.php:131 silently turns into its absolute value, so a
     * negative is not even reliably a negative downstream).
     *
     * The order must come out at exactly the server's 100.00 either way.
     *
     * WHAT THIS TEST IS, EXACTLY. It is a REGRESSION FENCE, not evidence for a guard added in this
     * package, and the difference was measured rather than assumed: stripping BOTH merges from
     * PaiementProPaymentController (its pre-existing one at :255 and
     * adoptStoredCheckoutState()) leaves all three cases PASSING. The reason is structural -- the
     * guest arm never consults `$request` for money at all. It reads the basket's totals from
     * `Cart::cart_list_cookie()` and `tax_price` from the stored state (:333-341), and
     * `$order->final_price` is built from those and from the stored `cartlist_final_price`
     * (:686-707). So there is no guard here to remove, and a posted figure has nowhere to enter.
     *
     * It is kept because that structure is exactly what a future edit could break silently, and
     * because the brief asks for these two cases by name. It is NOT counted as proof of a fix.
     * The one money field a posted value really did reach is method_id, and that has its own test
     * below -- which does fail when its guard is removed.
     *
     * @dataProvider manipulatedMoney
     */
    public function test_posted_money_cannot_change_the_order_total(string $label, array $money): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $state = $this->forgedCheckoutState($store, '', ['payment_type' => 'paiementpro']);
        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                array_merge(['responsecode' => 0, 'status' => 'success'], $money));

        $this->assertSame($before + 1, self::orderCount(),
            'The return wrote no order, so the total assertions below would be vacuous. '
            . 'Manipulation set: ' . $label);

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertSame(100.00, round((float) $order->final_price, 2),
            "The browser set the order's final payable (" . $label . '). The server computed '
            . '100.00 for this basket.');
        $this->assertSame(0.00, round((float) $order->delivery_price, 2),
            "The browser set the order's shipping price (" . $label . ').');
        $this->assertGreaterThanOrEqual(0, (float) $order->final_price,
            'The order carries a negative payable total (' . $label . ').');
        $this->assertGreaterThanOrEqual(0, (float) $order->delivery_price,
            'The order carries a negative shipping price (' . $label . ').');
    }

    /**
     * The money fields these handlers read, set to an attacker's figures.
     *
     * `cartlist_final_price` is the full payable PaymentController::prepareFinalRequestData()
     * writes; `shipping_final_price` is the server's delivery price; `tax_price` and `final_price`
     * are read in the guest arm. `coupon_info` is included because the discount is applied from
     * whichever array wins.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function manipulatedMoney(): array
    {
        return [
            'inflated shipping' => ['inflated shipping', [
                'shipping_final_price' => 5000,
                'cartlist_final_price' => 5100,
                'final_price' => 5100,
                'tax_price' => 900,
            ]],
            'negative shipping' => ['negative shipping', [
                'shipping_final_price' => -900,
                'cartlist_final_price' => -800,
                'final_price' => -800,
                'tax_price' => -50,
            ]],
            'free basket' => ['free basket', [
                'shipping_final_price' => 0,
                'cartlist_final_price' => 0,
                'final_price' => 0,
                'tax_price' => 0,
                'coupon_info' => ['coupon_id' => 1, 'discount_amount' => 100],
            ]],
        ];
    }

    /**
     * THE BROWSER CANNOT CHOOSE THE SHIPPING METHOD ON THE RETURN -- not even another tenant's.
     *
     * This is the one money field a posted value really did reach, and it survived the stored-state
     * guard. `$request->merge($requests_data)` can only overwrite a key the state CARRIES, and a
     * checkout where the shopper picked no shipping method carries no `method_id`. The handlers
     * then act on the posted one:
     *
     *     if (!empty($request->method_id)) { ... CartController::get_shipping_method($request) }
     *
     * and that resolves `ShippingMethod::find($request->method_id)` (CartController.php:433) with
     * NO store predicate -- and ShippingMethod is not in config/tenancy.php's scoped_models, so
     * the global scope adds none either. Any tenant's method, at any tenant's cost.
     *
     * `cost` is a merchant-controlled string column, so a merchant on the platform can publish a
     * NEGATIVE one. The fixture below does exactly that, with the store's currency symbol in the
     * `post` position -- the Arabic default, which this platform ships for -- so
     * `currency_format_with_sym()` returns a string that LEADS with the number and casts cleanly
     * to a negative float.
     *
     * MEASURED before the method_id pin: orders.delivery_price held `-500.00SYP`. Another tenant's
     * figure, negative, and not numeric, in a money column. On SQLite it stores as that text; a
     * MySQL DECIMAL column takes -500.00, so the merchant's shipping revenue goes negative in
     * every report that sums it.
     *
     * WHAT IT DID NOT DO, stated because the difference decides the severity: the shopper's
     * payable was unaffected. $order->final_price is built from the stored state's
     * cartlist_final_price and the server's own cart figures and never from $delivery_price
     * (PaiementProPaymentController.php:686-707 against :682), so this was a shipping-price and
     * data-integrity defect, not a way to pay less. It is fixed regardless, because the brief puts
     * shipping price on the list of things the browser must not decide.
     *
     * DRIVEN ON BOTH REACHABLE WRITERS, and the second one is why this test grew a provider.
     * PaymentController::getProductStatus -- the generic storefront return, and PayU's real order
     * writer -- kept its own pre-existing bare `$request->merge($requests_data)` when the five
     * gateway controllers were switched to adoptStoredCheckoutState(). A bare merge does not pin,
     * so the defect stayed LIVE there after it was closed in the five: this test measured
     * delivery_price -500.0 on `{slug}/get-payment-status` while the five already read 0.00.
     *
     * Reaching that handler at all needs the countries/states/cities rows the fixture now seeds:
     * it INSERTS a cities row from billing_info, and without the FK parents it 500s before the
     * order write, which would have hidden the defect behind a crash.
     *
     * @dataProvider shippingWriterRoutes
     */
    public function test_the_browser_cannot_choose_the_shipping_method_on_the_return(string $uri): void
    {
        [, $store] = $this->tenant();
        [, $otherStore] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        // The plan must permit shipping methods, or the handler skips the block entirely and this
        // test would pass without reading anything.
        DB::table('plans')->update(['shipping_method' => 'on']);

        // A currency whose symbol follows the number, so the formatted string casts to a float.
        foreach ([
            'site_currency_symbol_position' => 'post',
            'decimal_separator' => 'dot',
            'thousand_separator' => 'dot',
            'defult_currancy_symbol' => 'SYP',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $store->created_by, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Cache::flush();

        // ANOTHER STORE's shipping method, at a negative cost its own merchant set.
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'other-zone', 'shipping_method' => 'Local pickup',
            'store_id' => $otherStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreignMethodId = DB::table('shipping_methods')->insertGetId([
            'method_name' => 'Local pickup', 'zone_id' => $zoneId, 'cost' => '-500',
            'calculation_type' => 1, 'store_id' => $otherStore->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        // The server's checkout state names NO shipping method -- nothing was charged for it.
        $state = $this->forgedCheckoutState($store, '', ['payment_type' => 'paiementpro']);
        unset($state['method_id']);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . str_replace('{slug}', $store->slug, $uri), [
                'responsecode' => 0,
                'status' => 'success',
                'method_id' => $foreignMethodId,     // the whole attack
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'The return wrote no order, so the assertions below would be vacuous.');

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertSame(0.00, round((float) $order->delivery_price, 2),
            "The browser chose the order's shipping price by posting a method_id the server never "
            . 'selected. Value written: ' . var_export($order->delivery_price, true));
        $this->assertGreaterThanOrEqual(0, (float) $order->delivery_price,
            'A negative shipping price reached a money column.');
        $this->assertSame(100.00, round((float) $order->final_price, 2),
            "The payable moved as well, which would make this a payable manipulation and not "
            . 'only a shipping-price one.');
    }
}
