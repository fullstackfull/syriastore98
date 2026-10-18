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
     * The six storefront gateway-return routes, as routes/customer.php declares them.
     *
     * PayU is deliberately absent and that is a finding rather than an omission: its storefront
     * route (`/store-payu-payment/status` -> storeGetPayUStatus) writes no order at all -- it
     * redirects to route('store.payment.status'), which is PaymentController::getProductStatus --
     * and PayUPaymentController::getProductStatus, which DOES carry the fallback shape, is not
     * routed anywhere and so is dead code. See the report.
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
}
