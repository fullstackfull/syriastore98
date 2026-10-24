<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require_once __DIR__ . '/../../Support/Midtrans/MT_Tests.php';

/**
 * P1-SEC-17 F2 -- the Midtrans SERVER key rendered into the page the shopper's browser receives.
 * =============================================================================================
 *
 * `resources/views/midtras/{order_payment,payment}.blade.php` printed `$data['midtrans_secret']` as
 * `data-client-key`, and then built the form action with `route($data['fallback_url'], $data)` --
 * so the same key was ALSO a query parameter of the action URL. `midtrans_secret` is the merchant's
 * `midtrans_secret_key`: the SERVER key. Midtrans has a separate client key; snap.js treats a missing
 * `data-client-key` as "custom UI configuration will not take effect" (it logs that and carries on --
 * read from the sandbox snap.js itself), and `snap.pay(token)` does not need it.
 *
 * Reaching the page: `process-order` with `payment_type=midtrans` (the allowlist checks no enabled
 * flag), so any shopper who can check out. Only sandbox keys can arrive today -- the handler sets
 * `Config::$isProduction = false`, so a production server key makes `getSnapToken()` throw before
 * the view renders -- which is why this is MEDIUM, not HIGH.
 *
 * The Midtrans API is stubbed through the SDK's own seam (tests/Support/Midtrans/MT_Tests.php), so
 * the request reaches the real view; the premise test pins that.
 *
 * Sentinels carry `FIXTURE` so the CI secret scan's generic-assigned-secret rule exempts them.
 */
class MidtransServerKeyDisclosureTest extends PlanPaymentTestCase
{
    use RefreshDatabase;

    private const SERVER_KEY = 'SB-Mid-server-FIXTURE-sentinel-0001';

    private const PLATFORM_KEY = 'SB-Mid-server-FIXTURE-platform-0002';

    private const SNAP_TOKEN = 'FIXTURE-snap-token-0001';

    protected function tearDown(): void
    {
        \Midtrans\MT_Tests::reset();

        parent::tearDown();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function merchantWithMidtrans(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'mid-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => 'on',
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
            'is_midtrans_enabled' => 'on',
            'midtrans_secret_key' => self::SERVER_KEY,
        ] as $name => $value) {
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

    private function checkoutWithMidtrans($store)
    {
        \Midtrans\MT_Tests::$stubHttp = true;
        \Midtrans\MT_Tests::$stubHttpResponse = json_encode([
            'token' => self::SNAP_TOKEN,
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/' . self::SNAP_TOKEN,
        ]);

        $customer = Customer::forceCreate([
            'first_name' => 'Mid', 'last_name' => 'Shopper',
            'email' => 'mid-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $product = Product::forceCreate([
            'name' => 'mid-' . uniqid(), 'slug' => 'mid-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $billing = $this->billing();

        return $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/process-order', [
                'payment_type' => 'midtrans',
                'billing_info' => $billing,
            ] + $billing);
    }

    /** THE PREMISE: the request reached the Midtrans page, with the stubbed snap token in it. */
    public function test_a_midtrans_checkout_renders_the_snap_page(): void
    {
        [, $store] = $this->merchantWithMidtrans();

        $response = $this->checkoutWithMidtrans($store);

        $response->assertOk();
        $html = $response->getContent();

        $this->assertStringContainsString('app.sandbox.midtrans.com/snap/snap.js', $html,
            'Premise: the Midtrans page was not rendered (HTTP ' . $response->getStatusCode() . '). Body: ' . substr($html, 0, 300));
        $this->assertStringContainsString("window.snap.pay('" . self::SNAP_TOKEN . "'", $html, 'The snap token must still reach the page.');
    }

    public function test_the_midtrans_page_does_not_contain_the_server_key(): void
    {
        [, $store] = $this->merchantWithMidtrans();

        $response = $this->checkoutWithMidtrans($store);

        $this->assertStringContainsString('snap.js', $response->getContent(), 'Premise: the Midtrans page was rendered.');
        $this->assertStringNotContainsString(self::SERVER_KEY, $response->getContent(), 'The Midtrans server key was rendered into the page.');
        $this->assertStringNotContainsString(rawurlencode(self::SERVER_KEY), $response->getContent(), 'The Midtrans server key was rendered URL-encoded (form action query).');
        $this->assertStringNotContainsString('data-client-key', $response->getContent(), 'A client-key attribute is still rendered with a value taken from the server key.');
    }

    /** The return form must still point at the status route with the order it belongs to. */
    public function test_the_midtrans_return_form_still_targets_the_payment_status_route(): void
    {
        [, $store] = $this->merchantWithMidtrans();

        $response = $this->checkoutWithMidtrans($store);

        $this->assertMatchesRegularExpression('#<form action="[^"]*get-payment-status[^"]*"#', $response->getContent());
        $this->assertStringContainsString('order_id=', $response->getContent());
    }

    // ---------------------------------------------------------------------------------------------
    // The PLAN-PURCHASE page (resources/views/midtras/payment.blade.php) -- the PLATFORM server key
    // ---------------------------------------------------------------------------------------------

    private function planPurchasePage()
    {
        \Midtrans\MT_Tests::$stubHttp = true;
        \Midtrans\MT_Tests::$stubHttpResponse = json_encode(['token' => self::SNAP_TOKEN]);

        $this->seedSuperAdminCurrency('USD', ['midtrans_secret_key' => self::PLATFORM_KEY]);
        $plan = $this->makePlan('499');
        $merchant = $this->makeMerchant();

        return $this->actingAs($merchant)
            ->post('/plan-pay-with-midtrans', ['plan_id' => \Illuminate\Support\Facades\Crypt::encrypt($plan->id)]);
    }

    public function test_the_plan_purchase_midtrans_page_renders_the_snap_page(): void
    {
        $response = $this->planPurchasePage();

        $this->assertStringContainsString("window.snap.pay('" . self::SNAP_TOKEN . "'", (string) $response->getContent(),
            'Premise: the plan-purchase Midtrans page was not rendered (HTTP ' . $response->getStatusCode() . '). Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    public function test_the_plan_purchase_midtrans_page_does_not_contain_the_platform_server_key(): void
    {
        $response = $this->planPurchasePage();
        $html = (string) $response->getContent();

        $this->assertStringContainsString('snap.js', $html, 'Premise: the page was rendered.');
        $this->assertStringNotContainsString(self::PLATFORM_KEY, $html, 'The PLATFORM Midtrans server key was rendered into a merchant\'s page.');
        $this->assertStringNotContainsString(rawurlencode(self::PLATFORM_KEY), $html);
        $this->assertStringNotContainsString('data-client-key', $html);
    }
}
