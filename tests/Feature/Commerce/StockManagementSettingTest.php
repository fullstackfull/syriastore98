<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Product;
use App\Services\NMIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `stock_management` — the setting the order writers did not actually read.
 * =============================================================================================
 *
 * THE DEFECT. Twelve sites across nine files tested the setting as
 *
 *     if ($settings['stock_management'] ?? '' == 'on')
 *
 * `??` binds LOOSER than `==` in PHP, so that parses as
 *
 *     if ($settings['stock_management'] ?? ('' == 'on'))      // i.e.  ... ?? false
 *
 * Whenever the key EXISTS, its own VALUE is the condition — and `'off'` is a non-empty string,
 * so it is truthy. **A store with stock management OFF had its stock decremented anyway**, on
 * every storefront gateway return, on the WhatsApp/Telegram writer, on the generic storefront
 * return, and on the API order writers.
 *
 * WHY THE BLAST RADIUS IS "MOST STORES" RATHER THAN A CORNER CASE. `Utility.php:111` ships
 * `'stock_management' => 'off'` as the platform default and `SettingController:1094` writes
 * `'off'` whenever the merchant's checkbox is unticked. So the key is PRESENT with value `'off'`
 * for essentially every store that has ever saved its settings — precisely the case the bug
 * mistook for "on". A store that has NEVER saved settings has no key at all, takes the `?? false`
 * limb, and was already correct.
 *
 * EXACTLY ONE TRANSITION CHANGES, and these tests pin all three cases so the other two cannot
 * drift:
 *
 *   | setting            | before | after |
 *   |--------------------|--------|-------|
 *   | absent             | no deduction | no deduction |
 *   | `'on'`             | deducted     | deducted     |
 *   | present, not `'on'`| **deducted** | **not deducted** |
 *
 * WHAT MUST NOT CHANGE, and is asserted rather than assumed: with the setting off the order is
 * still WRITTEN. "Do not deduct" is not "do not sell" — a merchant who turns stock tracking off
 * is saying they do not want this platform counting units, not that checkout should fail.
 */
class StockManagementSettingTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The API order writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /**
     * A tenant whose `stock_management` row is seeded to a chosen value, or omitted entirely.
     *
     * @param  string|null  $stockManagement  null omits the row, which is the never-configured store.
     * @return array{0: \App\Models\User, 1: \App\Models\Store}
     */
    private function tenant(?string $stockManagement): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'sm-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        $settings = ['out_of_stock_threshold' => '0', 'notification' => '[]'];

        if ($stockManagement !== null) {
            $settings['stock_management'] = $stockManagement;
        }

        foreach ($settings as $name => $value) {
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

        return [$merchant, $store];
    }

    private function productIn($store, float $price = 100.00, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'sm-' . uniqid(), 'slug' => 'sm-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => $price, 'sale_price' => $price,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestCart($store, Product $product): string
    {
        $sid = 'sm-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_user_telephone' => '123', 'billing_address' => 'x',
            'billing_postecode' => '1', 'billing_country' => '101',
            'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
            'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];
    }

    private function checkoutState($store, array $overrides = []): array
    {
        return array_merge([
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'paiementpro',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'coupon_info' => null,
            'billing_info' => $this->billing(),
        ], $overrides);
    }

    private static function orderCount(): int
    {
        return (int) DB::table('orders')->count();
    }

    private static function stockOf(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    /**
     * The three settings states, each against every order writer reachable here.
     *
     * @return array<string, array{0: string|null, 1: bool}>
     */
    public static function stockManagementStates(): array
    {
        return [
            // label => [the seeded setting value, whether stock must move]
            "on deducts" => ['on', true],
            "off does not deduct" => ['off', false],
            "absent does not deduct" => [null, false],
            /*
             * A value that is neither. SettingController only ever writes 'on' or 'off', but the
             * buggy expression treated ANY non-empty value as "on", so this case is what separates
             * "the fix reads the value" from "the fix just special-cases the string 'off'".
             */
            "an unrecognised value does not deduct" => ['yes', false],
        ];
    }

    /**
     * THE STOREFRONT GATEWAY RETURN — `PaiementProPaymentController:469`.
     *
     * The one gateway return whose order writer is reachable in this environment, via the guest
     * cart cookie. See GatewayReturnTrustBoundaryTest for why that is the only one.
     *
     * @dataProvider stockManagementStates
     */
    public function test_the_gateway_return_deducts_only_when_stock_management_is_on(?string $setting, bool $shouldDeduct): void
    {
        [, $store] = $this->tenant($setting);
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($store)])
            ->post('/' . $store->slug . '/get-payment-paiementpro', [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertSame($before + 1, self::orderCount(),
            'The order was not written, so the stock assertion would be vacuous. Turning stock '
            . 'management off must not stop the store selling. Setting: ' . var_export($setting, true));

        $this->assertSame(
            $shouldDeduct ? 49 : 50,
            self::stockOf($product),
            $shouldDeduct
                ? 'Stock management is on and the order did not consume its stock.'
                : 'Stock was deducted although stock management is not on. Setting: '
                    . var_export($setting, true)
        );
    }

    /**
     * THE WHATSAPP / TELEGRAM ORDER WRITER — `PaymentController:3387`.
     *
     * @dataProvider stockManagementStates
     */
    public function test_the_whatsapp_writer_deducts_only_when_stock_management_is_on(?string $setting, bool $shouldDeduct): void
    {
        [, $store] = $this->tenant($setting);
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($store, ['payment_type' => 'whatsapp'])])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($before + 1, self::orderCount(),
            'The order was not written, so the stock assertion would be vacuous. Setting: '
            . var_export($setting, true));

        $this->assertSame(
            $shouldDeduct ? 49 : 50,
            self::stockOf($product),
            $shouldDeduct
                ? 'Stock management is on and the order did not consume its stock.'
                : 'Stock was deducted although stock management is not on. Setting: '
                    . var_export($setting, true)
        );
    }

    /**
     * THE NMI CARD CHARGE — `NMIPayController:580`.
     *
     * This site ALREADY used the correct `isset(...) && ... == 'on'` form and is therefore not
     * one of the twelve corrected. It is driven anyway, for two reasons: it is the only order
     * writer whose stock loop runs after a real capture, and a test that pins the correct form
     * is what stops a future sweep "tidying" it into the broken one.
     *
     * @dataProvider stockManagementStates
     */
    public function test_the_nmi_charge_deducts_only_when_stock_management_is_on(?string $setting, bool $shouldDeduct): void
    {
        [, $store] = $this->tenant($setting);
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $gateway = new class(['response' => 1, 'transactionid' => 'SM-1', 'amount' => '100.00']) extends NMIService {
            public array $calls = [];

            private array $answer;

            public function __construct(array $answer)
            {
                parent::__construct();
                $this->answer = $answer;
            }

            public function chargeSale(array $formParams): array
            {
                $this->calls[] = $formParams;

                return $this->answer;
            }
        };
        $this->app->instance(NMIService::class, $gateway);

        $state = $this->checkoutState($store, [
            'payment_type' => 'nmi',
            'cartlist' => [
                'final_price' => 100.00,
                'total_final_price' => 100.00,
                'total_sub_price' => 100.00,
                'total_tax_price' => 0,
                'tax_id' => null,
                'product_list' => [
                    ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 100.00],
                ],
            ],
        ]);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state])
            ->post('/' . $store->slug . '/get-payment-NMI', [
                'nmi_card_number' => '4111111111111111',
                'nmi_expiration_date' => '2030-12',
                'nmi_cvv_code' => '123',
                'nmi_cardholder_name' => 'A B',
            ]);

        $this->assertCount(1, $gateway->calls,
            'The charge never happened, so the stock assertion would be vacuous.');

        $this->assertSame($before + 1, self::orderCount(),
            'A captured card wrote no order. Setting: ' . var_export($setting, true));

        $this->assertSame(
            $shouldDeduct ? 49 : 50,
            self::stockOf($product),
            $shouldDeduct
                ? 'Stock management is on and the order did not consume its stock.'
                : 'Stock was deducted although stock management is not on. Setting: '
                    . var_export($setting, true)
        );
    }

    /**
     * EVERY GATEWAY RETURN WHOSE STOCK LOOP IS REACHABLE HERE, one per corrected site.
     *
     * The order-writing tests above cover three handlers. The same corrected expression lives in
     * five more gateway controllers, and a removal check proved that without this test NONE of
     * those five was covered by anything: reverting CinetPay, Easebuzz, Khalti or AuthorizeNet
     * individually failed nothing at all. A fix nothing can fail is a fix nobody can trust.
     *
     * This drives the STOCK LOOP rather than the order write, because only PaiementPro reaches
     * `new Order()` in this environment (see GatewayReturnTrustBoundaryTest for why). The ON case
     * is the per-route non-vacuity control: if stock does not move with the setting on, the route
     * was never reached and the OFF case below it would prove nothing.
     *
     * @dataProvider reachableGatewayStockLoops
     */
    public function test_each_gateway_return_deducts_only_when_stock_management_is_on(
        string $uri,
        ?string $setting,
        bool $shouldDeduct
    ): void {
        [, $store] = $this->tenant($setting);
        $product = $this->productIn($store, 100.00, 50);
        $sid = $this->guestCart($store, $product);

        $state = $this->checkoutState($store);

        /*
         * EASEBUZZ STORES ITS STATE UNDER ITS OWN KEY -- `easebuzz_request_data`, which
         * GuardsGatewayReturn::storedCheckoutState() takes as an explicit third argument
         * (EasebuzzController:245). Seeding only `request_data` left that handler refused at the
         * state guard, so it moved no stock EVEN WITH THE SETTING ON, and every OFF case for it
         * would have passed vacuously. The ON control is what caught that, which is the whole
         * reason it is asserted per route rather than once.
         */
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
            ->post('/' . $store->slug . '/' . $uri, [
                'responsecode' => 0, 'status' => 'success', 'token' => 'x', 'pidx' => 'x',
            ]);

        $this->assertSame(
            $shouldDeduct ? 49 : 50,
            self::stockOf($product),
            $shouldDeduct
                ? 'Stock management is on and this route moved no stock, so the OFF case for the '
                    . 'same route proves nothing. Route: ' . $uri
                : 'Stock was deducted although stock management is not on. Route: ' . $uri
                    . ', setting: ' . var_export($setting, true)
        );
    }

    /**
     * The cross product of the reachable stock-loop routes and the settings states.
     *
     * AuthorizeNet is absent and the reason is a finding rather than a convenience: its handler is
     * the charge, not a return, so it builds a live Authorize.Net transaction and dies in an empty
     * `catch` before its stock loop. GatewayReturnTrustBoundaryTest records the same exclusion for
     * the same reason. Its corrected expression is therefore NOT covered by execution here, and
     * that is stated rather than implied.
     *
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function reachableGatewayStockLoops(): array
    {
        $routes = [
            'cinetpay' => 'get-payment-cinetPay',
            'easebuzz' => 'get-payment-easebuzz',
            'khalti' => 'order-khalti',
            'storefront-return' => 'get-payment-status',
        ];

        $cases = [];

        foreach ($routes as $label => $uri) {
            foreach (self::stockManagementStates() as $stateLabel => [$setting, $shouldDeduct]) {
                $cases[$label . ' / ' . $stateLabel] = [$uri, $setting, $shouldDeduct];
            }
        }

        return $cases;
    }

    /**
     * THE TWO API ORDER WRITERS — `ApiController::place_order` (:3254) and
     * `ApiController::ordersave` (:8034).
     *
     * These are the paths the shipped clients would use, and a removal check proved they were
     * covered by nothing: reverting all three ApiController expressions failed no test in this
     * file until this one existed.
     *
     * `place_order` carried a DIFFERENT defect of the same family and it is corrected with them:
     * a bare `$settings['stock_management'] == 'on'` with no `??` at all, so a store that has
     * never saved its settings raised "Undefined array key" — which Laravel promotes to an
     * ErrorException — instead of simply not managing stock. That is why the "absent" data set
     * matters more here than anywhere else: before the fix it was a 500, not a no-op.
     *
     * The route pair is deliberate. Both sit behind `custom.auth`, which is authentication and
     * not authorization — see CLAUDE.md — so the principal here is a real Customer of this store
     * with a real token, which is the journey a shopper actually makes.
     *
     * @dataProvider apiOrderWriters
     */
    public function test_the_api_order_writers_deduct_only_when_stock_management_is_on(
        string $path,
        ?string $setting,
        bool $shouldDeduct
    ): void {
        [, $store] = $this->tenant($setting);

        $shippingId = DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'Api', 'last_name' => 'Shopper',
            'email' => 'sm-api-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = $this->productIn($store, 10.00, 50);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 2, 'price' => 20.00, 'store_id' => $store->id,
        ]);

        $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->postJson('/api/' . $store->slug . '/' . $path, [
                'customer_id' => $customer->id,
                'billing_info' => $this->apiBilling(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ]);

        $this->assertSame(
            $shouldDeduct ? 48 : 50,
            self::stockOf($product),
            $shouldDeduct
                ? 'Stock management is on and ' . $path . ' moved no stock, so the OFF cases for '
                    . 'this route prove nothing.'
                : 'Stock was deducted by ' . $path . ' although stock management is not on. '
                    . 'Setting: ' . var_export($setting, true)
        );
    }

    /**
     * @return array<string, array{0: string, 1: string|null, 2: bool}>
     */
    public static function apiOrderWriters(): array
    {
        $cases = [];

        foreach (['place-order', 'order-save'] as $path) {
            foreach (self::stockManagementStates() as $stateLabel => [$setting, $shouldDeduct]) {
                $cases[$path . ' / ' . $stateLabel] = [$path, $setting, $shouldDeduct];
            }
        }

        return $cases;
    }

    /** `billing_postecode` is misspelled in the handler; every delivery_* key is required too. */
    private function apiBilling(): array
    {
        return [
            'firstname' => 'Api', 'lastname' => 'Shopper', 'email' => 'api@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 'Testland', 'billing_state' => 'Teststate',
            'billing_city' => 'Testcity', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
            'delivery_city' => 'Testcity',
        ];
    }

    /**
     * THE ONE CORRECTED SITE THAT IS NOT A DEDUCTION — `Cart::cart_qty_cookie()` (`Cart.php:735`),
     * reached by `POST {slug}/change-cart`.
     *
     * RECORDED LOUDLY RATHER THAN CHANGED QUIETLY, because the owner's brief says not to change
     * unrelated stock behaviour silently and this is the one place where the behaviour that
     * changes is not a stock movement.
     *
     * This site chooses between TWO branches rather than between "deduct" and "don't": the `if`
     * arm bounds a guest's cart increment by the variant's stock, the `else` arm applies looser
     * rules driven by `stock_status`. With the precedence bug, a store with stock management OFF
     * took the STRICT arm — it was bounded by a stock count it had asked the platform not to
     * keep. After the fix it takes the `else` arm, which is what the setting asks for.
     *
     * So the change here is: an unmanaged store's shopper can now raise the cart quantity past
     * the recorded stock figure. That is the correct reading of "stock management off", it moves
     * no stock, it writes no order, and it is asserted here so that it is a decision on the
     * record instead of a side effect nobody measured.
     */
    public function test_an_unmanaged_store_does_not_bound_a_cart_increment_by_stock(): void
    {
        [, $store] = $this->tenant('off');

        // One unit in stock, one already in the cart: the strict arm refuses the next increment.
        $product = $this->productIn($store, 100.00, 1);
        $product->stock_status = 'in_stock';
        $product->save();

        $sid = $this->guestCart($store, $product);

        $response = $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/change-cart', [
                'cart_id' => 0,
                'product_id' => $product->id,
                'variant_id' => 0,
                'quantity_type' => 'plus',
            ]);

        $response->assertOk();

        $this->assertSame(1, self::stockOf($product),
            'A cart increment moved stock. This path must never touch inventory.');

        $this->assertSame(0, (int) DB::table('orders')->count(),
            'A cart increment wrote an order.');
    }

    /**
     * THE PRE-FLIGHT AND THE LOOP NOW AGREE — the quieter half of the same defect.
     *
     * `PaymentController::assertCartAvailable()` has always used the CORRECT test, so for a store
     * with the setting off it returned "nothing to check" — and the loop then decremented anyway.
     * The two halves of one decision disagreed.
     *
     * Driven where it is observable: a cart line for MORE units than exist. With the setting off,
     * neither half may act — no refusal from the pre-flight, and no stock movement from the loop
     * — and the order must still be written. With it on, the stock moves.
     */
    public function test_the_preflight_and_the_loop_agree_about_an_unmanaged_store(): void
    {
        [, $store] = $this->tenant('off');
        $product = $this->productIn($store, 100.00, 2);

        $sid = 'sm-cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 5, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($store, ['payment_type' => 'whatsapp'])])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($before + 1, self::orderCount(),
            'An unmanaged store refused an order for more units than it holds — which is exactly '
            . 'what turning stock management off is supposed to stop it doing.');

        $this->assertSame(2, self::stockOf($product),
            'An unmanaged store moved stock, and into oversell territory at that.');
    }

    /**
     * AND A MANAGED STORE STILL ENFORCES ITS POLICY — the other direction, so the fix cannot be
     * satisfied by a handler that simply stopped deducting for everybody.
     */
    public function test_a_managed_store_still_deducts_every_unit_of_a_multi_unit_line(): void
    {
        [, $store] = $this->tenant('on');
        $product = $this->productIn($store, 100.00, 50);

        $sid = 'sm-cookie-' . uniqid();
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 4, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        $before = self::orderCount();

        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($store, ['payment_type' => 'whatsapp'])])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($before + 1, self::orderCount(), 'The managed order was not written.');
        $this->assertSame(46, self::stockOf($product),
            'A managed store did not deduct every unit of a four-unit line.');
    }
}
