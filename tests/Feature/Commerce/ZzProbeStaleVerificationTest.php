<?php

namespace Tests\Feature\Commerce;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

class ZzProbeStaleVerificationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const BASKET = 100.00;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $planId = DB::table('plans')->insertGetId([
            'name' => 'pp-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');
        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
            DB::table('settings')->insert(['name' => $n, 'value' => $v, 'created_by' => $merchant->id,
                'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        (new \App\Models\Setting)->flushCache();
        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'S', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'D', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function shopperWithBasket($store): Customer
    {
        $customer = Customer::forceCreate([
            'first_name' => 'A', 'last_name' => 'B', 'email' => 'pp-' . uniqid() . '@e.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('x'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        $product = Product::forceCreate([
            'name' => 'p', 'slug' => 'p-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => self::BASKET, 'sale_price' => self::BASKET,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
        Cart::forceCreate(['customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'qty' => 1, 'price' => self::BASKET, 'store_id' => $store->id]);

        return $customer;
    }

    private function checkoutState($store, $customerId): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customerId,
            'payment_type' => 'Paiementpro', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => self::BASKET, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101', 'billing_state' => '1',
                'billing_city' => '1', 'billing_company_name' => '', 'delivery_address' => 'x',
                'delivery_postcode' => '1', 'delivery_country' => '101', 'delivery_state' => '1',
                'delivery_city' => '1',
            ],
        ];
    }

    public function test_plant_then_redeem(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);

        $before = (int) DB::table('orders')->max('id');

        // STEP 1 (plant): responsecode=0 with NO stored checkout state.
        $r1 = $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                ['txnid' => 'plant-' . uniqid(), 'responsecode' => '0']);

        $planted = session()->get(\App\Services\PaymentVerificationService::SESSION_KEY);
        fwrite(STDERR, "\nPLANTED: " . var_export($planted, true) . "\n");
        $ordersAfterPlant = (int) DB::table('orders')->where('id', '>', $before)->count();
        fwrite(STDERR, "ORDERS AFTER PLANT: {$ordersAfterPlant}\n");

        // STEP 2 (redeem): genuine checkout state, NO responsecode at all.
        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                ['txnid' => 'redeem-' . uniqid()]);

        $order = DB::table('orders')->where('id', '>', $before)->orderBy('id', 'desc')->first();
        fwrite(STDERR, "REDEEM ORDER STATUS: " . var_export($order->payment_status ?? null, true) . "\n");

        $this->assertNotNull($order, 'no order written on redeem');
        $this->assertSame('Pending', (string) $order->payment_status,
            'PLANT-THEN-REDEEM BYPASS: a refused return left a redeemable verification record.');
    }

    public function test_control_declined_leaves_nothing(): void
    {
        [, $store] = $this->tenant();
        $customer = $this->shopperWithBasket($store);
        $before = (int) DB::table('orders')->max('id');

        $this->actingAs($customer, 'customers')
            ->post('/' . $store->slug . '/get-payment-paiementpro',
                ['txnid' => 'c-' . uniqid(), 'responsecode' => '1']);
        $planted = session()->get(\App\Services\PaymentVerificationService::SESSION_KEY);
        fwrite(STDERR, "\nCONTROL PLANTED: " . var_export($planted, true) . "\n");

        $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->checkoutState($store, $customer->id)])
            ->post('/' . $store->slug . '/get-payment-paiementpro', ['txnid' => 'c2-' . uniqid()]);

        $order = DB::table('orders')->where('id', '>', $before)->orderBy('id', 'desc')->first();
        fwrite(STDERR, "CONTROL ORDER STATUS: " . var_export($order->payment_status ?? null, true) . "\n");
        $this->assertSame('Pending', (string) ($order->payment_status ?? ''));
    }
}
