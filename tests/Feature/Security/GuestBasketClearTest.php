<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-23 -- a guest's WhatsApp/Telegram order cleared the wrong baskets.
 * =============================================================================================
 *
 * `PaymentController::whatsapp()` pins a guest's `customer_id` to the empty string and queues each ordered
 * line as `['customer_id' => '', product, variant, store]`; `clearCartLines()` then ran
 * `Cart::where('customer_id', '')->where(product)->where(variant)->where(store)->delete()`.
 *
 * Guest basket rows carry `customer_id = 0` and are told apart ONLY by `cookie_session_id`. So:
 *
 *   - on MySQL in production's non-strict mode (config/database.php:55) `customer_id = ''` is read as 0
 *     and ONE guest's order deleted EVERY guest's basket row for that product in that store. EXECUTED on
 *     MySQL 8.0.46: tests/Support/Security/bin/verify-guest-cart-clear-coercion-on-mysql.php -- two guests'
 *     rows deleted by one order, the other store's and the logged-in customer's kept;
 *   - in strict mode the statement is an error (1292), caught and logged per line, so nothing is cleared;
 *   - on SQLite `'' = 0` is false, so nothing is cleared.
 *
 * So the suite (SQLite) can only show the SECOND half of the contract this file pins -- the guest's OWN rows
 * are cleared -- and that half FAILS on the unfixed code on every engine; the first half (nobody else's rows
 * are touched) is the MySQL script's evidence, and holds here by construction of the fix. Both halves are
 * asserted so the test keeps its meaning on a MySQL-backed run.
 */
class GuestBasketClearTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'gbc-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->save();
        Cache::forget('admin_details');

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value, 'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return [$merchant, $store];
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'gbc-' . uniqid(), 'slug' => 'gbc-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function guestRow($store, Product $product, string $cookie): void
    {
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);
    }

    private function state($store, $customerId = ''): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customerId,
            'payment_type' => 'whatsapp', 'shipping_final_price' => 0, 'tax_price' => 0,
            'cartlist_final_price' => 100.00,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x', 'billing_postecode' => '1',
                'billing_country' => '101', 'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    private static function rowsFor(string $cookie): int
    {
        return (int) DB::table('carts')->where('cookie_session_id', $cookie)->count();
    }

    public function test_a_guest_order_clears_that_guests_basket_and_nobody_elses(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $mine = 'gbc-mine-' . uniqid();
        $other = 'gbc-other-' . uniqid();
        $this->guestRow($store, $product, $mine);
        $this->guestRow($store, $product, $other);

        $orders = (int) DB::table('orders')->count();

        $response = $this->withCookie('cart', $mine)
            ->withSession(['request_data' => $this->state($store)])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($orders + 1, (int) DB::table('orders')->count(),
            'Premise: no order was written (HTTP ' . $response->getStatusCode() . '), so nothing below is about the clearing step. Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(0, self::rowsFor($mine), 'The guest\'s own basket line for the ordered product was not cleared.');
        $this->assertSame(1, self::rowsFor($other), 'One guest\'s order cleared another guest\'s basket.');
    }

    /**
     * THE REAL GUEST FLOW. `processOrder` finds or creates a Customer from the billing e-mail the guest TYPES and
     * stores its id in the session, so `whatsapp()` queues a guest's lines with a POSITIVE customer id -- the id
     * of whoever's e-mail was typed. (Which order that id is written on is an owner decision, S32; what a line
     * clears is not.) Keyed on that id, a stranger who typed a registered customer's e-mail deleted the
     * customer's basket lines for every product in the stranger's own basket. The lines to clear belong to the
     * AUTHENTICATED principal; a guest clears their own, by cookie. Found by the review of P1-SEC-23.
     */
    public function test_a_guest_who_types_a_customers_email_clears_their_own_basket_not_the_customers(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $victim = Customer::forceCreate([
            'first_name' => 'Gbc', 'last_name' => 'Victim', 'email' => 'gbc-victim-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        Cart::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $mine = 'gbc-typed-' . uniqid();
        $this->guestRow($store, $product, $mine);

        $orders = (int) DB::table('orders')->count();

        // What a guest process-order leaves in the session: the customer id found by the typed e-mail.
        $response = $this->withCookie('cart', $mine)
            ->withSession(['request_data' => $this->state($store, $victim->id)])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($orders + 1, (int) DB::table('orders')->count(),
            'Premise: no order was written (HTTP ' . $response->getStatusCode() . '). Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(1, (int) DB::table('carts')->where('customer_id', $victim->id)->count(), 'A guest who typed a customer\'s e-mail deleted that customer\'s basket line.');
        $this->assertSame(0, self::rowsFor($mine), 'The guest\'s own basket line was not cleared.');
    }

    /** The numeric branch is untouched: a logged-in customer's order clears their own lines and not a stranger's. */
    public function test_a_logged_in_customers_order_still_clears_only_their_own_lines(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);

        $customer = Customer::forceCreate([
            'first_name' => 'Gbc', 'last_name' => 'Shopper', 'email' => 'gbc-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);

        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);

        $stranger = 'gbc-stranger-' . uniqid();
        $this->guestRow($store, $product, $stranger);

        $orders = (int) DB::table('orders')->count();

        $response = $this->actingAs($customer, 'customers')
            ->withSession(['request_data' => $this->state($store, $customer->id)])
            ->post('/' . $store->slug . '/whatsapp', ['wts_number' => '123456789']);

        $this->assertSame($orders + 1, (int) DB::table('orders')->count(),
            'Premise: no order was written (HTTP ' . $response->getStatusCode() . '). Body: ' . substr((string) $response->getContent(), 0, 300));

        $this->assertSame(0, (int) DB::table('carts')->where('customer_id', $customer->id)->count(), 'The customer\'s own basket line was not cleared.');
        $this->assertSame(1, self::rowsFor($stranger), 'A customer\'s order cleared a guest\'s basket.');
    }

    /**
     * The fallthrough. Neither HTTP arm can queue a line with no customer and no cookie (a guest has a cookie
     * cart or there is nothing to order; a customer has an id), so this is defence in depth -- driven directly.
     * Without the `continue`, such a line would reach `delete()` constrained only by product, variant and
     * store: every basket in the store for that product.
     */
    public function test_a_line_with_neither_a_customer_nor_a_cookie_clears_nothing(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $this->guestRow($store, $product, 'gbc-a');
        $this->guestRow($store, $product, 'gbc-b');

        $controller = new \App\Http\Controllers\PaymentController(app(\App\Services\PaymentService::class));
        $clear = new \ReflectionMethod($controller, 'clearCartLines');
        $clear->setAccessible(true);

        $line = ['product_id' => $product->id, 'variant_id' => 0, 'store_id' => $store->id];

        $clear->invoke($controller, [
            $line + ['customer_id' => '', 'cookie_session_id' => null],
            $line + ['customer_id' => 0, 'cookie_session_id' => ''],
            $line + ['customer_id' => null],
        ]);

        $this->assertSame(2, (int) DB::table('carts')->where('product_id', $product->id)->count(),
            'A line naming no customer and no cookie deleted baskets.');

        // And the same method DOES clear a guest's own rows when it is given their cookie (premise).
        $clear->invoke($controller, [$line + ['customer_id' => '', 'cookie_session_id' => 'gbc-a']]);
        $this->assertSame(0, self::rowsFor('gbc-a'));
        $this->assertSame(1, self::rowsFor('gbc-b'));
    }
}
