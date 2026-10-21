<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Services\NMIService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P1-SEC-24 -- the gateway returns cleared the buyer's basket by a customer id that was not the buyer's.
 * =============================================================================================
 *
 * Seven gateway return handlers (AuthorizeNet, PayU, NMI, Khalti, Easebuzz, PaiementPro, CinetPay) cleared a
 * basket with `Cart::where('customer_id', $request->customer_id)...->delete()`. In a return,
 * `$request->customer_id` is the STORED CHECKOUT STATE -- adoptStoredCheckoutState() pins it -- and that is:
 *
 *   - `''` for a pure guest. MySQL in production's non-strict mode reads `'' = 0`, so the statement matched every
 *     guest's rows (the per-line delete: every guest's row for the product; EXECUTED on MySQL 8.0.46 by
 *     tests/Support/Security/bin/verify-guest-cart-clear-coercion-on-mysql.php). SQLite matches nothing, so the
 *     suite could never show it;
 *   - the id of the Customer processOrder() found or created from the billing e-mail the guest TYPED. Keyed on
 *     that, a stranger who typed a registered customer's e-mail deleted that customer's basket.
 *
 * Now the clear is keyed on the AUTHENTICATED customer (Cart::forCustomer(auth('customers')->id())); a guest's own
 * basket is cleared by the cart-cookie delete that already follows (Cart::forGuestCookie).
 *
 * NMI is the representative: it is the one return that can be driven end to end without a live gateway (its service
 * is a seam). The other six carry the identical statement and are held by the census in NullCookieCartTest and
 * CartScopesTest.
 */
class GatewayReturnBasketClearTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->plan_id = DB::table('plans')->insertGetId([
            'name' => 'grb-plan-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
            'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
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
            'name' => 'grb-' . uniqid(), 'slug' => 'grb-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Grb', 'last_name' => $label, 'email' => "grb-$label-" . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function guestRow($store, Product $product, string $cookie): void
    {
        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 100.00,
            'store_id' => $store->id, 'cookie_session_id' => $cookie,
        ]);
    }

    private function customerRow($store, Product $product, Customer $customer): void
    {
        Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 1,
            'price' => 100.00, 'store_id' => $store->id,
        ]);
    }

    private function state($store, Product $product, $customerId): array
    {
        return [
            'slug' => $store->slug, 'store_id' => $store->id, 'customer_id' => $customerId, 'payment_type' => 'nmi',
            'shipping_final_price' => 0, 'tax_price' => 0, 'cartlist_final_price' => 100.00, 'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test', 'billing_user_telephone' => '123',
                'billing_address' => 'x', 'billing_postecode' => '1', 'billing_country' => '101', 'billing_state' => '1',
                'billing_city' => '1', 'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
            'cartlist' => [
                'final_price' => 100.00, 'total_final_price' => 100.00, 'total_sub_price' => 100.00,
                'total_tax_price' => 0, 'tax_id' => null,
                'product_list' => [['product_id' => $product->id, 'variant_id' => 0, 'qty' => 1, 'price' => 100.00, 'name' => $product->name]],
            ],
        ];
    }

    /** Substitute only NMI's ANSWER; everything else in the return is the real handler. */
    private function fakeNmi(): void
    {
        $this->app->instance(NMIService::class, new class extends NMIService {
            public function chargeSale(array $formParams): array
            {
                return ['response' => 1, 'transactionid' => 'T-1', 'amount' => '100.00'];
            }
        });
    }

    private function nmiReturn($store, array $state, ?string $cookie = null, ?Customer $as = null)
    {
        $this->fakeNmi();

        $request = $as ? $this->actingAs($as, 'customers') : $this;
        if ($cookie !== null) {
            $request = $request->withCookie('cart', $cookie);
        }

        return $request->withSession(['request_data' => $state])->post('/' . $store->slug . '/get-payment-NMI', [
            'nmi_card_number' => '4111111111111111', 'nmi_expiration_date' => '2030-12',
            'nmi_cvv_code' => '123', 'nmi_cardholder_name' => 'A B',
        ]);
    }

    private static function orders(): int
    {
        return (int) DB::table('orders')->count();
    }

    private static function customerRows(Customer $c): int
    {
        return (int) DB::table('carts')->where('customer_id', $c->id)->count();
    }

    private static function cookieRows(string $cookie): int
    {
        return (int) DB::table('carts')->where('cookie_session_id', $cookie)->count();
    }

    public function test_a_guest_return_that_names_a_registered_customer_does_not_clear_that_customers_basket(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $victim = $this->customerIn($store, 'victim');
        $this->customerRow($store, $product, $victim);
        $mine = 'grb-mine-' . uniqid();
        $this->guestRow($store, $product, $mine);

        $before = self::orders();
        $response = $this->nmiReturn($store, $this->state($store, $product, $victim->id), $mine);

        $this->assertSame($before + 1, self::orders(), 'Premise: no order was written (HTTP ' . $response->getStatusCode() . '). Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(1, self::customerRows($victim), 'A guest who typed a customer\'s e-mail cleared that customer\'s basket through a gateway return.');
        $this->assertSame(0, self::cookieRows($mine), 'The guest\'s own basket was not cleared.');
    }

    public function test_a_pure_guest_return_clears_their_own_basket_and_no_other_guests(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $mine = 'grb-mine-' . uniqid();
        $other = 'grb-other-' . uniqid();
        $this->guestRow($store, $product, $mine);
        $this->guestRow($store, $product, $other);

        $before = self::orders();
        $response = $this->nmiReturn($store, $this->state($store, $product, ''), $mine);

        $this->assertSame($before + 1, self::orders(), 'Premise: no order was written (HTTP ' . $response->getStatusCode() . ').');
        $this->assertSame(0, self::cookieRows($mine), 'The guest\'s own basket was not cleared.');
        $this->assertSame(1, self::cookieRows($other), 'One guest\'s gateway return cleared another guest\'s basket.');
    }

    public function test_a_logged_in_customers_return_clears_their_own_lines_and_no_one_elses(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $buyer = $this->customerIn($store, 'buyer');
        $other = $this->customerIn($store, 'other');
        $this->customerRow($store, $product, $buyer);
        $this->customerRow($store, $product, $other);

        $before = self::orders();
        $response = $this->nmiReturn($store, $this->state($store, $product, $buyer->id), null, $buyer);

        $this->assertSame($before + 1, self::orders(), 'Premise: no order was written (HTTP ' . $response->getStatusCode() . '). Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(0, self::customerRows($buyer), 'The buyer\'s own basket was not cleared.');
        $this->assertSame(1, self::customerRows($other), 'A customer\'s gateway return cleared another customer\'s basket.');
    }
}
