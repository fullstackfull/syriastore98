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
     * (the capture-amount line this package replaced) and answers 500, so no order is written either way.
     * That is precisely the "tests that pass merely because the route crashes" the brief forbids.
     * The refusal message is the one observable that only this guard produces.
     */
    private const REFUSAL = 'We could not confirm your checkout details';

    /** P1-PAY-02: the replay refusal, distinct from the state refusal above so a test can tell them apart. */
    private const REPLAY_REFUSAL = 'This payment has already been recorded against an order';

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
     * EMPTY `catch (\Exception $e) {}` at :335-336 and the next statement raises
     * "Undefined variable $requestNet" at :338. So its quantity guard is placed correctly (before
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
     *   - authorizenet: fatals at "Undefined variable $requestNet" (:338) because the SDK setup
     *     throws into an empty `catch (\Exception $e) {}` (:335-336), so it never reaches the
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
     * khalti stops at verifyPayment() and authorizenet fatals at :338, so neither reaches the
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
    /**
     * A KHALTI VERIFIER THAT CONFIRMS, BOUND SO THESE ROWS STILL REACH WHAT THEY MEASURE.
     *
     * D-13 moved the Khalti verification ABOVE the cart read and the stock loop, so a return whose
     * capture is not confirmed now stops before either. That is the fix; it also means a khalti row
     * that binds no verifier stops at the payment and never reaches the guard or the loop this test
     * is about. Before the fix those rows reached the stock loop WITH NO PAYMENT EVIDENCE AT ALL,
     * which is the exposure D-13 closed -- so the rows were only ever reachable because of the
     * defect.
     *
     * Binding a confirming double is therefore not a relaxation: it makes the fixture a LEGITIMATE
     * PAID return, which is the only kind that should reach a stock loop, and the row's own claim
     * is unchanged and now stronger -- the guard fires, or the setting gates the deduction, EVEN
     * when the payment is good.
     *
     * The canned response carries no `amount`, deliberately: `verifyKhaltiCapture()` treats a 200
     * with no amount field as verified (its own test pins that), so this double is independent of
     * whatever total the fixture happens to price, and will not start failing if one changes.
     *
     * Harmless for the other gateways in these data sets: none of them resolves `Khalti` at all.
     */
    private function bindConfirmingKhalti(): void
    {
        $this->app->instance(\App\Khalti\Khalti::class, new class extends \App\Khalti\Khalti {
            public function __construct()
            {
            }

            public function verifyPayment($secret, $token, $amount)
            {
                return ['status_code' => 200, 'data' => ['idx' => 'trust-boundary-double']];
            }
        });
    }

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
     * second identical return finds nothing and is refused rather than falling through to the
     * request.
     *
     * THIS TEST WAS REWRITTEN AFTER AN ADVERSARIAL REVIEW, because the first version did not test
     * replay at all. It never called withSession(), so NO stored state was present on any of its
     * three requests -- every one of them was simply the forged-return case again, and deleting
     * every Session::forget() from all six handlers would not have failed it. It now drives a
     * GENUINE first return (the guest's own cookie basket, the server's own state) and replays
     * THAT, so the state has to be consumed by request 1 for requests 2 and 3 to be refused.
     *
     * It does NOT claim the handlers have an idempotency key. They do not: the only thing stopping
     * a replay is that the session entry is gone. A real duplicate server-to-server notification,
     * which none of these handlers accepts today, would need one. See the report.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_replaying_a_return_cannot_multiply_orders(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        // The first request is a GENUINE return: the guest's own cookie basket and the server's
        // own stored state. That is what makes this a replay test rather than a second copy of
        // the forged-return test -- the state must be CONSUMED by request 1 for requests 2 and 3
        // to find nothing.
        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $state = $this->forgedCheckoutState($store, '');
        $body = ['responsecode' => 0, 'status' => 'success'];

        $before = self::orderCount();

        // Request 1: the state is present, exactly once, as the server left it.
        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}($path, $body);

        $afterFirst = self::orderCount();

        // Requests 2 and 3: the same return, replayed. Session::forget() in the handler means the
        // state is gone, and the caller cannot put it back -- that is the property under test.
        for ($i = 0; $i < 2; $i++) {
            session()->forget('error');
            $this->withCookie('cart', $sid)->{$method}($path, $body);

            $this->assertStringContainsString(self::REFUSAL, (string) session('error'),
                'Replay ' . ($i + 2) . ' was not refused, so the order-count assertion below '
                . 'could be satisfied by a crash instead of by the guard. Session error was: '
                . json_encode(session('error')));
        }

        $this->assertSame($afterFirst, self::orderCount(),
            'Replaying a consumed return wrote a further order. At most the first request may.');
        $this->assertLessThanOrEqual($before + 1, self::orderCount(),
            'Three identical returns produced more than one order.');
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
     * If it reaches the loop, the same line reduces every total it is summed into. The basket is
     * worth 1000.00 and nets positive on purpose, so a guard that only catches a basket summing
     * to nothing would not satisfy this.
     *
     * THE INVENTORY HALF OF THIS IS NOW WEAKER THAN IT WAS, and that is stated rather than left
     * for someone to discover. It used to be that `$remain_stock = $Product->product_stock -
     * $qtyy` would ADD stock for a negative quantity, so the stock assertion below could fail on
     * its own if the guard were removed. D-13 routed four of these six handlers through
     * `ProductStockService`, whose `normaliseQuantity()` returns null for a non-positive
     * quantity — so on those four the decrement is now SKIPPED, and "stock did not move" is true
     * whether the guard fires or not. The test still fails on guard removal, via the refusal
     * message, the order count, and `$big`'s stock going 50 -> 49; but the `$cheap` assertion is
     * a corroborating check on those four rather than an independent one.
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
        $this->bindConfirmingKhalti();

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
            "The merchant's stock moved for a refused order. On the two handlers still carrying a "
            . 'raw `stock - qty` it would go UP, because the decrement subtracts a negative; on '
            . 'the four D-13 routed through ProductStockService the quantity is normalised away '
            . 'and nothing moves either way. See the note in this method\'s docblock about which '
            . 'assertions here are independent and which corroborate.');
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
     *     further down the happy path -- AuthorizeNet's old capture-amount line read
     *     `json_decode($input['data'])['get_amount']` and raised "Undefined array key data"
     *     without it. That line is gone, but the handler still fatals further down -- see the
     *     reachableStockLoopRoutes() docblock. A 500 from a later stage says nothing about this guard, and treating it as
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
     * WHAT THIS TEST DOES NOT PROVE, and the name is narrower than it reads. It proves a return
     * cannot be claimed BY POSTING THE ID ON THE RETURN. The same OUTCOME is still reachable
     * through the INITIATION leg and is NOT closed: PaymentController::handleCustomerRegistration
     * does `Customer::where('email', $billing['email'])->first()` (:1310) -- find-or-create by a
     * posted e-mail, with no proof of control -- and that customer's id becomes the state's
     * `customer_id`, which this fix then faithfully restores. MEASURED: a guest whose only input
     * is the victim's e-mail in the ordinary checkout form got
     * `orders.customer_id = <victim>` and the victim's saved basket deleted. That is a
     * pre-existing guest-attribution defect, it is an owner decision rather than a guard (the
     * platform deliberately links a guest order to a matching account), and it is recorded in
     * docs/security/P01-SECURITY-WORK-QUEUE.md. An adversarial review of the committed package
     * found it; this docblock exists so the test is not read as covering more than it does.
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
     * khalti stops at `$khalti->verifyPayment()` (:637), authorizenet fatals at :338, cinetpay and
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

    // =====================================================================================
    //  THE OTHER LEG: WHAT THE SERVER ITSELF WAS TALKED INTO COMPUTING
    // =====================================================================================

    /**
     * A SHIPPING METHOD CANNOT PRICE A CHECKOUT IT DOES NOT BELONG TO, AND CANNOT PRICE IT BELOW ZERO.
     *
     * THIS IS THE DEFECT EVERY OTHER TEST IN THIS FILE WAS STRUCTURALLY UNABLE TO SEE, and it was
     * the most serious one. All of them attack the RETURN. This one attacks the INITIATION, one leg
     * earlier, and then lets the return behave perfectly.
     *
     * The chain, entirely server-side after the first request:
     *
     *   POST {slug}/process-order with a method_id
     *     -> PaymentController::processShipping() (:1689, gated only on !empty($request->method_id))
     *     -> CartController::get_shipping_method() -> ShippingMethod::find($request->method_id)
     *        -- which had NO store predicate, and ShippingMethod is not in scoped_models
     *     -> for 'Local pickup' the merchant's `cost` column is used VERBATIM
     *     -> PaymentController::prepareFinalRequestData() writes it to the state as
     *        shipping_final_price AND FOLDS IT INTO cartlist_final_price (:1721,
     *        $cartlist_final_price + $delivery_price + $tax_price) -- the payable
     *     -> the gateway return reads the state, as it is supposed to, and writes the order
     *
     * So the browser never has to post a money field on the return at all. Every guard in this
     * package stands aside correctly, because the stored state really IS the server's. The server
     * had been talked into computing a poisoned figure one leg earlier. `cost` is a
     * merchant-controlled string column that ShippingMethodController::update() writes with no
     * validation, so a negative one needs no exotic access -- any merchant on the platform can
     * publish one, and the method did not even have to be theirs.
     *
     * MEASURED before the fix, both legs driven through the real routes:
     *   step 1, posting ONLY method_id=<store B's 'Local pickup', cost '-50'>:
     *           state shipping_final_price '-50', cartlist_final_price 50.0
     *   step 2, posting NO money fields:
     *           orders.product_price 100.0, orders.final_price 50.0
     * For AuthorizeNet the same cartlist_final_price is what
     * GuardsGatewayReturn::serverPayableAmount() hands to the card capture, so this is the amount
     * charged, not merely the amount recorded.
     *
     * TWO GUARDS, AND EACH PROVIDER BELOW ISOLATES ONE OF THEM:
     *   'foreign positive'  -- another store's method at cost 500. The FLOOR cannot catch this
     *                          (500 is positive); only the store predicate can. Free-or-cheap
     *                          shipping, or an inflated charge, from any tenant's price list.
     *   'foreign negative'  -- another store's method at cost -50. Either guard catches it; this
     *                          is the measured attack above.
     *   'own negative'      -- THIS store's own method at cost -50. The store predicate cannot
     *                          catch this; only the floor can.
     *
     * @dataProvider foreignShippingMethods
     */
    public function test_a_shipping_method_cannot_price_a_checkout_it_does_not_own(
        string $label,
        bool $foreign,
        string $cost
    ): void {
        [, $store] = $this->tenant();
        [, $otherStore] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        DB::table('plans')->update(['shipping_method' => 'on']);

        $owner = $foreign ? $otherStore : $store;
        $zoneId = DB::table('shipping_zones')->insertGetId([
            'zone_name' => 'z-' . uniqid(), 'shipping_method' => 'Local pickup',
            'store_id' => $owner->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $methodId = DB::table('shipping_methods')->insertGetId([
            'method_name' => 'Local pickup', 'zone_id' => $zoneId, 'cost' => $cost,
            'calculation_type' => 1, 'store_id' => $owner->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        // ---- LEG 1: initiation. The ONLY attacker input is method_id. ----
        $this->withCookie('cart', $sid)->post('/' . $store->slug . '/process-order', [
            'payment_type' => 'Paiementpro',
            'billing_info' => $this->guestBilling(),
            'method_id' => $methodId,
        ]);

        $state = session('request_data');

        $this->assertIsArray($state,
            'Initiation stored no checkout state, so leg 2 below would prove nothing. '
            . 'Set: ' . $label . '. Session error: ' . json_encode(session('error')));

        $this->assertGreaterThanOrEqual(0, (float) ($state['shipping_final_price'] ?? 0),
            "The server computed a NEGATIVE shipping charge into its own checkout state ("
            . $label . '). Value: ' . var_export($state['shipping_final_price'] ?? null, true));

        $this->assertSame(100.00, round((float) ($state['cartlist_final_price'] ?? 0), 2),
            'The server folded a foreign or negative shipping charge into the PAYABLE it stored ('
            . $label . '). Value: ' . var_export($state['cartlist_final_price'] ?? null, true)
            . ' -- the basket is 100.00 of goods with no shipping this store sold.');

        // ---- LEG 2: the return, behaving perfectly. NO money fields posted. ----
        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                ['responsecode' => 0, 'status' => 'success']);

        $this->assertSame($before + 1, self::orderCount(),
            'The return wrote no order, so the assertions below would be vacuous. Set: ' . $label);

        $order = DB::table('orders')->orderByDesc('id')->first();

        $this->assertSame(100.00, round((float) $order->final_price, 2),
            'The order was priced by a shipping method this store does not sell, or below zero ('
            . $label . '). final_price=' . var_export($order->final_price, true)
            . ' product_price=' . var_export($order->product_price, true));
        $this->assertGreaterThanOrEqual(0, (float) $order->delivery_price,
            'A negative shipping price reached orders.delivery_price (' . $label . ').');
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: string}>  label => [label, foreign?, cost]
     */
    public static function foreignShippingMethods(): array
    {
        return [
            // isolates the STORE PREDICATE -- the floor cannot catch a positive cost
            'foreign positive' => ['foreign positive', true, '500'],
            // either guard catches this one; it is the measured attack
            'foreign negative' => ['foreign negative', true, '-50'],
            // isolates the FLOOR -- the store predicate cannot catch this store's own method
            'own negative' => ['own negative', false, '-50'],
        ];
    }

    /** The billing block the guest checkout form posts, with FK-valid geo ids. */
    private function guestBilling(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B',
            'email' => 'guest-' . uniqid() . '@example.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x',
            'billing_postecode' => '1', 'billing_country' => '101',
            'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    /**
     * THE PINS THEMSELVES, ISOLATED — because nothing else in this file isolates them.
     *
     * HOW THIS TEST CAME TO EXIST, because the omission is the lesson. `adoptStoredCheckoutState()`
     * does two things: it runs a merge on the guest arm (where none ran before), and it PINS
     * `customer_id` and `method_id`. Three tests above fail when the whole method is reverted, so
     * the merge is proven. The pins were not: every fixture in this file builds its state from
     * forgedCheckoutState(), which SETS `customer_id`, and a merge alone is sufficient whenever the
     * state carries the key. Measured — replacing the method's body with a plain
     * `$request->merge($state)` left all 42 tests in this file GREEN.
     *
     * The pins exist for the case `merge()` cannot reach: a key the state OMITS. That is the case
     * this test builds, by unsetting it. Without the pin the posted value stands and becomes
     * `$order->customer_id`; with it the value is `''`, which every handler reads as "a guest".
     *
     * AND A CORRECTION ABOUT THE OTHER PIN. `method_id` is pinned by the same two lines and is NOT
     * isolated by anything, here or elsewhere, because commit 0ad3f7e0 made it unreachable:
     * once CartController::get_shipping_method refuses another tenant's method and floors the
     * charge at zero, a posted `method_id` has no attacker-beneficial case left. A foreign method
     * is refused by the predicate, a negative cost by the floor, and this store's own positive-cost
     * method only makes the shopper pay MORE. So the removal checks recorded for 7484b290 and
     * 57c8953c were valid when they were run and are STALE NOW — a later, deeper fix subsumed
     * them. The pin is kept as defence in depth at zero cost, and is documented as unproven rather
     * than claimed as a guard.
     */
    public function test_the_identity_pin_covers_a_key_the_state_omits(): void
    {
        [, $store] = $this->tenant();
        $victim = $this->customerIn($store, 'pinvictim');
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);
        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 4, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        // The state OMITS customer_id entirely. This is what merge() cannot overwrite, and it is
        // the whole reason the pin exists.
        $state = $this->forgedCheckoutState($store, '', ['payment_type' => 'paiementpro']);
        unset($state['customer_id']);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-paiementpro', [
                'responsecode' => 0,
                'status' => 'success',
                'customer_id' => $victim->id,      // the whole attack
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'The return wrote no order, so the assertions below would be vacuous.');

        $this->assertSame(0, (int) DB::table('orders')->where('customer_id', $victim->id)->count(),
            "A return was recorded as another customer's order by posting their id against a "
            . 'stored state that omits the key. This is the case merge() cannot reach.');

        $order = DB::table('orders')->orderByDesc('id')->first();
        $this->assertSame(0, (int) $order->customer_id,
            'A state that names no customer produced an order with an owner. Value: '
            . var_export($order->customer_id, true));

        $this->assertSame(4, (int) DB::table('carts')
            ->where('customer_id', $victim->id)->sum('qty'),
            "Another customer's basket was emptied by the cart delete, which keys on "
            . '$request->customer_id.');
    }

    /**
     * AN ABSURD QUANTITY CANNOT MOVE STOCK EITHER — the guard had a floor and no ceiling.
     *
     * The negative-quantity test above covers `qty < 1`. This covers the other end, and the
     * omission was a hole rather than a simplification: MEASURED, before the ceiling, a cart line
     * of qty 2147483647 on an otherwise LEGITIMATE PaiementPro return took a product seeded at 50
     * units to `product_stock` **-2147483597**, and wrote the order. The quantity is not negative,
     * so the floor waves it through; the result is as negative as stock gets.
     *
     * It needs no payment and no account. `carts.qty` is written straight from the request with no
     * validation by two vendored module routes under plain `web`
     * (packages/workdo/BoostSales/.../BoostSalesController.php:59 and
     * packages/workdo/FrequentlyProduct/.../FrequentlyProductController.php:88 — both unfixable in
     * place under the vendor guard), and CinetPay, Easebuzz and PaiementPro verify nothing, so the
     * stock moves whether or not money did. One anonymous request corrupts any merchant's
     * inventory, with no order anybody intends to pay for.
     *
     * The product is priced at 1.00 so the basket's total stays representable; the attack is about
     * the stock column, not the money.
     *
     * @dataProvider reachableStockLoopRoutes
     */
    public function test_an_absurd_quantity_is_refused_on_the_return(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 1.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2147483647, 'price' => 1.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $state = $this->forgedCheckoutState($store, '');
        $before = self::orderCount();
        $this->bindConfirmingKhalti();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}('/' . str_replace('{slug}', $store->slug, $uri), [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringContainsString('Your cart contains an invalid quantity',
            (string) session('error'),
            'The quantity guard did not fire on an absurd quantity, so the stock assertion below '
            . 'could be satisfied by a crash. Session error was: ' . json_encode(session('error')));

        $this->assertSame(50, (int) Product::withoutGlobalScopes()
            ->find($product->id)->product_stock,
            "The merchant's stock moved for a refused basket. Before the ceiling this read "
            . '-2147483597.');

        $this->assertSame($before, self::orderCount(),
            'An order was written for a basket holding an unusable quantity.');
    }

    /**
     * ANOTHER MERCHANT'S STOCK CANNOT BE DRAINED THROUGH THIS STORE'S CART.
     *
     * The deepest defect this package found, and the only one that needs no payment at all.
     *
     * `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
     * short-circuits for it and `Product::find($id)` adds **no predicate for anybody** — not a
     * customer, not a guest, not the operator. `CartController::product_cartlist` (the storefront
     * add-to-cart route, `POST {slug}/product_cart`, no auth) resolved its product that way and
     * wrote whatever came back into THIS store's cart.
     *
     * MEASURED end to end through these two routes alone, with no account and no payment:
     *
     *   POST /{storeA}/product_cart   product_id = <a store-B product>, qty 7
     *     -> HTTP 200, "added successfully", carts row product_id=<B's>, store_id=<A's>
     *   POST /{storeA}/get-payment-paiementpro   (an ordinary, legitimate return)
     *     -> store B's products.product_stock 90 -> 83
     *
     * The gateway-return loops cannot catch this and a guard there would be the wrong place: they
     * decrement whatever `product_id` the cart row holds, and by then the row looks entirely
     * ordinary. Nor can the quantity guard see it — the quantity is a perfectly reasonable 7.
     * CinetPay, Easebuzz and PaiementPro verify no payment, so the stock moves whether or not any
     * money did.
     *
     * Same family as the S19 cross-tenant stock drain, and the same lesson that file records: a
     * `customer_id` sweep cannot find it, because the attacker-controlled identifier is the
     * PRODUCT id.
     *
     * THIS TEST DRIVES THE REAL ADD-TO-CART ROUTE and not a `forceCreate`, deliberately: planting
     * the row directly would prove the loops decrement it, which was never in doubt, and would
     * leave the only interesting question — whether a caller can get such a row written at all —
     * untested. That question is what makes this live rather than latent.
     */
    public function test_another_stores_product_cannot_enter_this_stores_cart(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $mine = $this->productIn($storeA, 100.00, 50);
        $theirs = $this->productIn($storeB, 100.00, 90);

        // LEG 1: the real storefront add-to-cart route, naming another tenant's product.
        $response = $this->post('/' . $storeA->slug . '/product_cart', [
            'product_id' => $theirs->id,
            'qty' => 7,
        ]);

        $this->assertSame(0, (int) DB::table('carts')
            ->where('product_id', $theirs->id)->count(),
            "Another store's product was written into this store's cart. Response: "
            . substr((string) $response->getContent(), 0, 200));

        // LEG 2: a legitimate return. Even if a row had been planted another way, assert the
        // victim's stock is untouched, so this test keeps measuring the outcome and not only the
        // refusal.
        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $mine->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $storeA->id,
            'cookie_session_id' => $sid,
        ]);

        $state = $this->forgedCheckoutState($storeA, '', ['payment_type' => 'paiementpro']);

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $storeA->slug . '/get-payment-paiementpro',
                ['responsecode' => 0, 'status' => 'success']);

        $this->assertSame(90, (int) Product::withoutGlobalScopes()
            ->find($theirs->id)->product_stock,
            "Another merchant's stock moved for a checkout on this store. Before the fix this "
            . 'read 83.');
        $this->assertSame(49, (int) Product::withoutGlobalScopes()
            ->find($mine->id)->product_stock,
            "This store's own legitimate line did not consume its stock, so the assertion above "
            . 'may be passing because the checkout did nothing at all.');
    }

    // =====================================================================================
    //  P1-PAY-02 -- IDEMPOTENCY. ONE CHECKOUT, ONE PAYMENT, AT MOST ONE ORDER.
    // =====================================================================================

    /**
     * The routes whose ORDER WRITER is reached here, so an order COUNT means something.
     *
     * khalti is excluded from the count assertions and the reason is already recorded in
     * guestWriterRoutes(): it stops at verifyPayment() in this environment, so it never reaches
     * `new Order()` and "no second order" would hold whatever the claim did. It is still driven
     * by the refusal test below, because the claim is taken ABOVE the gateway call.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function claimCountingRoutes(): array
    {
        $routes = self::guestWriterRoutes();
        unset($routes['khalti']);

        return $routes;
    }

    /** A checkout state carrying the server's one-time identifier, as PaymentService now stamps it. */
    private function statefulCheckout($store, array $overrides = []): array
    {
        return $this->forgedCheckoutState($store, '', array_merge([
            'checkout_nonce' => 'nonce-' . uniqid('', true),
        ], $overrides));
    }

    /**
     * THE STATE THE SERVER STORES CARRIES A ONE-TIME IDENTIFIER, AND TWO CHECKOUTS DO NOT SHARE IT.
     *
     * This is the premise every other test in this group stands on, and it is asserted against
     * the REAL initiation rather than against a hand-built array: `/process-order` through
     * PaymentService::process(). Without it the claim has nothing to key on and every refusal
     * below would be the fail-open path instead.
     *
     * The second half matters as much as the first. A nonce that is stable across checkouts would
     * refuse a shopper's legitimate second purchase, which is a worse defect than the one being
     * closed -- so "they differ" is asserted, not assumed.
     */
    public function test_initiation_stamps_a_distinct_one_time_identifier_on_each_checkout(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $nonces = [];

        foreach ([0, 1] as $round) {
            $sid = 'cookie-' . uniqid();
            Cart::forceCreate([
                'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
                'cookie_session_id' => $sid,
            ]);

            $this->withCookie('cart', $sid)->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'Paiementpro',
                'billing_info' => $this->guestBilling(),
            ]);

            $state = session('request_data');

            $this->assertIsArray($state,
                'Initiation stored no checkout state on round ' . $round . ', so this test would '
                . 'prove nothing. Session error: ' . json_encode(session('error')));

            $this->assertArrayHasKey('checkout_nonce', $state,
                'PaymentService::process() stored a checkout state with NO one-time identifier. '
                . 'Every claim below then falls back to "nothing to claim" and the replay control '
                . 'is the session alone again. Keys present: ' . implode(',', array_keys($state)));

            $this->assertIsString($state['checkout_nonce']);
            $this->assertNotSame('', trim($state['checkout_nonce']),
                'The stored identifier is blank, which claimGatewayReturn() treats as absent.');

            $nonces[] = $state['checkout_nonce'];
        }

        $this->assertNotSame($nonces[0], $nonces[1],
            'Two separate checkouts were stamped with the SAME identifier. The claim would then '
            . 'refuse a shopper\'s legitimate second purchase -- a worse defect than the replay '
            . 'this closes. Value: ' . $nonces[0]);
    }

    /**
     * TWO RETURNS THAT OVERLAP ARE REFUSED ON EVERY GATEWAY -- the session cannot do this.
     *
     * WHAT IS BEING STAGED, precisely, because it is not the same thing as
     * test_replaying_a_return_cannot_multiply_orders above. That test replays a CONSUMED return:
     * request 1 takes the state, `Session::forget()` removes it, and requests 2 and 3 arrive with
     * nothing. It is the sequential case, and the session closes it.
     *
     * The case here is two returns that OVERLAP. Laravel holds no session lock for the life of
     * these requests, so both read the state before either writes the forget -- a shopper's
     * double submit, a browser retry, a gateway poking the return URL while the browser is also
     * on it. Both therefore hold a VALID copy of the state. That is what the second request
     * carries here: the same state, still present, exactly as a concurrent reader would have it.
     * The session has nothing left to say, so whatever refuses the second request is not the
     * session.
     *
     * Driven on all six, including the three that do not reach an order writer in this
     * environment, because the claim is taken above the gateway call and must hold there too.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_an_overlapping_return_is_refused_on_every_gateway(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $state = $this->statefulCheckout($store);
        $body = ['responsecode' => 0, 'status' => 'success'];

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}($path, $body);

        $this->assertStringNotContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'The FIRST return was refused as a replay, so the second being refused below would '
            . 'prove nothing. Session error: ' . json_encode(session('error')));

        // The overlapping second reader: the same state, still present, as a concurrent request
        // would have found it.
        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}($path, $body);

        $this->assertStringContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'A second return holding a VALID copy of the same checkout state was not refused. '
            . 'Session::forget() cannot close this case -- both readers have the state. '
            . 'Session error was: ' . json_encode(session('error')));
    }

    /**
     * AND IT IS AN ORDER THAT DOES NOT GET WRITTEN, not merely a message.
     *
     * The refusal test above asserts the guard fired. This one asserts the consequence on the
     * three handlers that actually reach `new Order()` here, because a guard that fires after the
     * write would satisfy the first test and still double the orders.
     *
     * @dataProvider claimCountingRoutes
     */
    public function test_an_overlapping_return_cannot_write_a_second_order(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $state = $this->statefulCheckout($store);
        $body = ['responsecode' => 0, 'status' => 'success'];

        $before = self::orderCount();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}($path, $body);

        $this->assertSame($before + 1, self::orderCount(),
            'The FIRST return wrote no order, so "no second order" below would hold whatever the '
            . 'claim did. Session error: ' . json_encode(session('error')));

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->{$method}($path, $body);

        $this->assertSame($before + 1, self::orderCount(),
            'A second return holding a valid copy of the same checkout state wrote a SECOND '
            . 'order for one payment. Session error: ' . json_encode(session('error')));
    }

    /**
     * A REPLAYED PAYMENT IS REFUSED EVEN WHEN THE CHECKOUT IS GENUINELY NEW.
     *
     * THE CASE THE CHECKOUT NONCE CANNOT ANSWER, and the reason there are two keys rather than
     * one. A shopper completes checkout A and pays. They then start checkout B -- a real, fresh
     * basket with a real, fresh nonce -- and replay A's gateway return. The stored state is
     * B's and is perfectly valid; the nonce is B's and has never been claimed. Only the gateway's
     * own transaction reference ties the two returns to one payment.
     *
     * CinetPay is the gateway driven here because it is one of the two whose return carries a
     * reference this repository's own code reads: `cpm_trans_id`, which notify_url reads and
     * sends back as `transaction_id` (CinetPayController:214, :228) and which initiation
     * generates per payment (:93, :153). khalti is the other, and it is not driven because it
     * stops at verifyPayment() here. The remaining four read no reference at all, which
     * gatewayTransactionReference() records as a finding rather than papering over.
     */
    public function test_a_replayed_payment_reference_is_refused_even_with_a_fresh_checkout(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $path = '/' . $store->slug . '/get-payment-cinetPay';
        $reference = 'CPM' . strtoupper(uniqid('', true));
        $body = ['responsecode' => 0, 'status' => 'success', 'cpm_trans_id' => $reference];

        $before = self::orderCount();

        // Checkout A: its own basket, its own nonce, and the payment whose reference this is.
        $sidA = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sidA,
        ]);
        session()->forget('error');
        $this->withCookie('cart', $sidA)
            ->withSession(['request_data' => $this->statefulCheckout($store)])
            ->post($path, $body);

        $this->assertSame($before + 1, self::orderCount(),
            'Checkout A wrote no order, so the replay below would prove nothing. Session error: '
            . json_encode(session('error')));

        // Checkout B: a genuinely different checkout -- new basket, new nonce, never claimed --
        // replaying A's payment.
        $sidB = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $sidB,
        ]);
        session()->forget('error');
        $this->withCookie('cart', $sidB)
            ->withSession(['request_data' => $this->statefulCheckout($store)])
            ->post($path, $body);

        $this->assertStringContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'A second order was accepted against a payment reference that had already produced '
            . 'one. The checkout nonce cannot catch this -- checkout B is genuinely new. Session '
            . 'error was: ' . json_encode(session('error')));
        $this->assertSame($before + 1, self::orderCount(),
            'One payment reference produced two orders.');
    }

    /**
     * ONE STORE'S PAYMENT REFERENCE CANNOT DENY ANOTHER STORE'S ORDER.
     *
     * P1-PAY-02, and this one pins a HARDENING rather than a measured defect -- said plainly
     * because the distinction matters. The payment key is
     * `gateway-return-claim:payment:<gateway>:<store>:<sha1(reference)>`, and a review traced
     * both references this platform reads and found neither can collide across tenants today:
     * `cpm_trans_id` is minted here per payment (PaymentService:4092) and khalti's token is the
     * gateway's own per-payment value. So nothing is broken without the store term.
     *
     * It is still worth a test, because the class it closes is cheap to reintroduce and
     * expensive to find: a gateway added later whose reference is a per-merchant sequence -- an
     * "order id 1001" that every merchant also has -- would let one tenant's completed payment
     * refuse another tenant's. The cache namespace is installation-wide (config/cache.php),
     * not per-store, so without the term that denial would be silent and cross-tenant.
     *
     * Two stores, the same reference, both legitimate. Both must write their order.
     */
    public function test_one_stores_payment_reference_cannot_deny_another_stores(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();

        $reference = 'SHARED-' . strtoupper(uniqid('', true));
        $body = ['responsecode' => 0, 'status' => 'success', 'cpm_trans_id' => $reference];
        $before = self::orderCount();

        foreach ([$storeA, $storeB] as $index => $store) {
            $product = $this->productIn($store, 100.00, 50);
            $sid = 'cookie-' . uniqid();
            Cart::forceCreate([
                'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
                'cookie_session_id' => $sid,
            ]);

            session()->forget('error');
            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $this->statefulCheckout($store)])
                ->post('/' . $store->slug . '/get-payment-cinetPay', $body);

            $this->assertSame($before + $index + 1, self::orderCount(),
                'Store ' . ($index + 1) . ' of two DIFFERENT tenants, each with its own checkout '
                . 'and its own nonce, did not get its order. The two share only a payment '
                . "reference, so one tenant's payment is denying another's. Session error: "
                . json_encode(session('error')));
        }
    }

    /**
     * A PAYMENT REPORTED *PENDING* MUST STILL BE ABLE TO COMPLETE.
     *
     * P1-PAY-02, AND A REGRESSION THE FIRST VERSION OF THAT CHANGE INTRODUCED. `get-payment-status`
     * is the generic return for some twenty payment methods, and three of them -- PhonePe,
     * toyyibpay and Tap -- are asked for their verdict before anything is written. Those blocks
     * return EARLY AND LEAVE THE STORED STATE IN PLACE, on purpose: toyyibpay `status_id == 2` is
     * flashed as a SUCCESS ("Your transaction on pending"), because the payment is not finished
     * and the shopper has to be able to come back.
     *
     * Claiming above those exits burned the claim on a visit that wrote nothing, so the later
     * completion met "This payment has already been recorded against an order" for an order that
     * was never written -- a money-losing false refusal rather than a cosmetic one. An
     * independent review of this change found it; the claim now sits below those checks.
     *
     * The second leg deliberately omits `status_id`, which is how a completed toyyibpay return
     * differs from a pending one here: neither the fail nor the pending branch fires, and the
     * handler proceeds to the writer.
     */
    public function test_a_pending_gateway_verdict_does_not_consume_the_checkout(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $path = '/' . $store->slug . '/get-payment-status';
        $state = $this->statefulCheckout($store, ['payment_type' => 'toyyibpay']);
        $before = self::orderCount();

        // Leg 1: toyyibpay says the payment is still pending. Nothing is written, and the
        // handler returns with the state intact so the shopper can come back.
        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post($path, ['status_id' => 2]);

        $this->assertSame($before, self::orderCount(),
            'A payment reported PENDING wrote an order, so this test is not driving the early '
            . 'exit it means to. Session error: ' . json_encode(session('error')));

        // Leg 2: the same payment, now complete. It must not be refused as a replay.
        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post($path, ['responsecode' => 0, 'status' => 'success']);

        $this->assertStringNotContainsString(self::REPLAY_REFUSAL, (string) session('error'),
            'A payment that was PENDING on its first return was refused as a replay when it '
            . 'completed -- for an order that had never been written. Session error: '
            . json_encode(session('error')));
        $this->assertSame($before + 1, self::orderCount(),
            'The completed payment wrote no order after a pending verdict on the same checkout. '
            . 'Session error: ' . json_encode(session('error')));
    }

    /**
     * AND THE CONTROL DOES NOT BREAK A SHOPPER WHO SIMPLY BUYS TWICE.
     *
     * The positive control, and it is not optional: every assertion above is satisfied by a claim
     * that refuses EVERYTHING, which would stop the storefront taking money at all. Two separate
     * checkouts, two separate nonces, no shared payment reference -- both must write their order.
     *
     * @dataProvider claimCountingRoutes
     */
    public function test_two_distinct_checkouts_each_write_their_own_order(string $uri, string $method): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store, 100.00, 50);

        $path = '/' . str_replace('{slug}', $store->slug, $uri);
        $body = ['responsecode' => 0, 'status' => 'success'];
        $before = self::orderCount();

        foreach ([0, 1] as $round) {
            $sid = 'cookie-' . uniqid();
            Cart::forceCreate([
                'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
                'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
                'cookie_session_id' => $sid,
            ]);

            $state = $this->statefulCheckout($store);

            session()->forget('error');
            $this->withCookie('cart', $sid)
                ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
                ->{$method}($path, $body);

            $this->assertSame($before + $round + 1, self::orderCount(),
                'Checkout ' . ($round + 1) . ' of two DISTINCT checkouts did not write its order. '
                . 'The replay control is refusing legitimate trade. Session error: '
                . json_encode(session('error')));
        }
    }
}
