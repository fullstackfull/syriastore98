<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P1-SEC-24 -- cart queries keyed on a cookie that is not there.
 * =============================================================================================
 *
 * A guest's basket is identified by `carts.cookie_session_id`, taken from the `cart` cookie. A LOGGED-IN
 * customer's basket is identified by `carts.customer_id`, and its rows carry `cookie_session_id` NULL
 * (CartController:503-505 sets the cookie column only for a guest; CustomerLoginController nulls it on
 * login). Laravel turns `where('cookie_session_id', null)` into `whereNull`, so every query that read the
 * cookie with `Cookie::get('cart')` and used it unchecked selected EVERY logged-in customer's rows the
 * moment the cookie was absent -- which, for a logged-in customer, is the normal case.
 *
 * MEASURED before the fix, each through the real route:
 *   - an ANONYMOUS `cart-clear` with no cookie: another customer's whole basket deleted;
 *   - an ANONYMOUS `cart-remove` with no cookie: that customer's line deleted;
 *   - an ANONYMOUS `change-cart` (quantity) with no cookie: that customer's row quantity changed;
 *   - a customer LOGGING IN with no cookie: every other customer's basket in the store reassigned to them
 *     (`UPDATE ... SET customer_id = <login>` over the NULL-cookie rows) -- they then see and can order it;
 *   - a logged-in customer's COD order with no cookie: every other customer's basket in the store deleted,
 *     on the hot path of every order. Eight order-completion handlers carry the same statement
 *     (PaymentService:3013, PaymentController:2843, and the AuthorizeNet, PayU, NMI, Easebuzz, PaiementPro
 *     and CinetPay returns); the census test below pins that none remains.
 *
 * The tenant bounds it (Cart is in `scoped_models`): the other STORE's baskets survive. Within a store it is
 * every logged-in customer.
 *
 * Cart assertions read the raw table: Cart is store-scoped and a model read answers for the resolved tenant.
 */
class NullCookieCartTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const GUEST_COOKIE = 'nullcookie-guest-cookie';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    /** @return array{store: \App\Models\Store, other: \App\Models\Store, p: Product, a: Customer, b: Customer, c: Customer} */
    private function world(): array
    {
        $stores = [];
        foreach ([1, 2] as $_) {
            [$merchant, $store] = $this->makeTenant();
            $merchant->plan_id = DB::table('plans')->insertGetId([
                'name' => 'nc-' . uniqid(), 'price' => 0, 'themes' => '[]', 'storage_limit' => '100',
                'shipping_method' => 'off', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $merchant->save();
            foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $n => $v) {
                DB::table('settings')->insert([
                    'name' => $n, 'value' => $v, 'created_by' => $merchant->id, 'store_id' => $store->id,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $stores[] = $store;
        }
        Cache::forget('admin_details');

        DB::table('countries')->insertOrIgnore(['id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('states')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cities')->insertOrIgnore(['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1, 'created_at' => now(), 'updated_at' => now()]);

        [$store, $other] = $stores;

        $product = fn ($s) => Product::forceCreate([
            'name' => 'nc-' . uniqid(), 'slug' => 'nc-' . uniqid(), 'product_stock' => 50, 'track_stock' => 1,
            'variant_product' => 0, 'price' => 10, 'sale_price' => 10, 'store_id' => $s->id, 'created_by' => $s->created_by,
        ]);
        $customer = fn ($s, $l) => Customer::forceCreate([
            'first_name' => 'Nc', 'last_name' => $l, 'email' => "nc-$l-" . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999), 'password' => bcrypt('secret123'),
            'store_id' => $s->id, 'status' => 1, 'register_type' => 'email',
        ]);

        $p = $product($store);
        $pOther = $product($other);
        $a = $customer($store, 'A');
        $b = $customer($store, 'B');
        $c = $customer($other, 'C');

        foreach ([[$a, $store, $p], [$c, $other, $pOther]] as [$cu, $s, $pr]) {
            Cart::forceCreate(['customer_id' => $cu->id, 'product_id' => $pr->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $s->id]);
        }

        return compact('store', 'other', 'p', 'a', 'b', 'c');
    }

    private static function rows(Customer $c): int
    {
        return (int) DB::table('carts')->where('customer_id', $c->id)->count();
    }

    private function guestBasket($store, Product $p): void
    {
        Cart::forceCreate(['customer_id' => 0, 'product_id' => $p->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $store->id, 'cookie_session_id' => self::GUEST_COOKIE]);
    }

    // -- the anonymous guest arms ------------------------------------------------------------

    public function test_an_anonymous_cart_clear_without_a_cookie_removes_nothing(): void
    {
        $w = $this->world();

        $response = $this->post('/' . $w['store']->slug . '/cart-clear');

        $response->assertOk();
        $this->assertSame(1, self::rows($w['a']), 'An anonymous cart-clear deleted a logged-in customer\'s basket.');
        $this->assertSame(1, self::rows($w['c']), 'Premise-of-scope: the other store\'s basket must survive too.');
    }

    public function test_an_anonymous_cart_remove_without_a_cookie_removes_nothing(): void
    {
        $w = $this->world();

        $response = $this->post('/' . $w['store']->slug . '/cart-remove', ['product_id' => $w['p']->id, 'variant_id' => 0]);

        $response->assertOk();
        $this->assertSame(1, self::rows($w['a']), 'An anonymous cart-remove deleted a logged-in customer\'s basket line.');
    }

    public function test_an_anonymous_quantity_change_without_a_cookie_touches_no_customers_row(): void
    {
        $w = $this->world();

        $this->post('/' . $w['store']->slug . '/change-cart', ['product_id' => $w['p']->id, 'variant_id' => 0, 'quantity_type' => 'increase']);

        $this->assertSame(1, (int) DB::table('carts')->where('customer_id', $w['a']->id)->value('qty'), 'An anonymous quantity change altered a logged-in customer\'s basket row.');
    }

    /** The legitimate halves: a guest WITH a cookie still clears and removes their own basket, and only theirs. */
    public function test_a_guest_with_a_cookie_still_clears_only_their_own_basket(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);

        $this->withCookie('cart', self::GUEST_COOKIE)->post('/' . $w['store']->slug . '/cart-clear')->assertOk();

        $this->assertSame(0, (int) DB::table('carts')->where('cookie_session_id', self::GUEST_COOKIE)->count(), 'The guest\'s own basket was not cleared (control).');
        $this->assertSame(1, self::rows($w['a']), 'A guest\'s cart-clear reached a customer\'s basket.');
    }

    public function test_a_guest_with_a_cookie_still_removes_their_own_line(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);

        $this->withCookie('cart', self::GUEST_COOKIE)->post('/' . $w['store']->slug . '/cart-remove', ['product_id' => $w['p']->id, 'variant_id' => 0])->assertOk();

        $this->assertSame(0, (int) DB::table('carts')->where('cookie_session_id', self::GUEST_COOKIE)->count(), 'The guest\'s own line was not removed (control).');
        $this->assertSame(1, self::rows($w['a']), 'A guest\'s cart-remove reached a customer\'s basket line.');
    }

    // -- login -------------------------------------------------------------------------------

    public function test_logging_in_without_a_cookie_takes_over_nobody_elses_basket(): void
    {
        $w = $this->world();

        $response = $this->post('/' . $w['store']->slug . '/login', ['email' => $w['b']->email, 'password' => 'secret123']);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/home', (string) $response->headers->get('Location'), 'Premise: the login succeeded.');
        $this->assertSame(1, self::rows($w['a']), 'A customer logging in took over another customer\'s basket.');
        $this->assertSame(0, self::rows($w['b']), 'The logging-in customer was handed another customer\'s basket.');
    }

    public function test_logging_in_with_a_cookie_still_merges_that_guests_basket(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);

        $response = $this->withCookie('cart', self::GUEST_COOKIE)->post('/' . $w['store']->slug . '/login', ['email' => $w['b']->email, 'password' => 'secret123']);

        $this->assertStringContainsString('/home', (string) $response->headers->get('Location'), 'Premise: the login succeeded.');
        $this->assertSame(1, self::rows($w['b']), 'The guest basket was not merged into the customer\'s on login (control).');
        $this->assertNull(DB::table('carts')->where('customer_id', $w['b']->id)->value('cookie_session_id'));
        $this->assertSame(1, self::rows($w['a']), 'The merge also moved another customer\'s basket.');
    }

    // -- order completion --------------------------------------------------------------------

    private function codCheckout(array $w)
    {
        $billing = [
            'firstname' => 'A', 'lastname' => 'B', 'email' => $w['a']->email, 'billing_user_telephone' => '123',
            'billing_address' => 'x', 'billing_postecode' => '1', 'billing_country' => '101', 'billing_state' => '1',
            'billing_city' => '1', 'billing_company_name' => '', 'delivery_address' => 'x', 'delivery_postcode' => '1',
            'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
        ];

        return $this->actingAs($w['a'], 'customers')
            ->post('/' . $w['store']->slug . '/process-order', ['payment_type' => 'cod', 'billing_info' => $billing] + $billing);
    }

    public function test_a_logged_in_customers_order_without_a_cookie_leaves_other_baskets_alone(): void
    {
        $w = $this->world();
        // B has a basket of their own in the same store.
        Cart::forceCreate(['customer_id' => $w['b']->id, 'product_id' => $w['p']->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $w['store']->id]);

        $orders = (int) DB::table('orders')->count();
        $response = $this->codCheckout($w);

        $this->assertSame($orders + 1, (int) DB::table('orders')->count(), 'Premise: no order was written (HTTP ' . $response->getStatusCode() . ').');
        $this->assertSame(1, self::rows($w['b']), 'One customer\'s order deleted another customer\'s basket in the same store.');
        $this->assertSame(1, self::rows($w['c']), 'An order reached another store\'s basket.');
        $this->assertSame(0, self::rows($w['a']), 'Control: the buyer\'s own ordered line was not cleared.');
    }

    // -- the choke point and the census ------------------------------------------------------

    public function test_the_guest_cookie_scope_matches_nothing_for_a_missing_cookie(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);

        foreach ([null, '', [], 0, false] as $missing) {
            $this->assertSame(0, Cart::withoutGlobalScopes()->forGuestCookie($missing)->count(), 'A missing cookie selected rows: ' . var_export($missing, true));
        }

        $this->assertSame(1, Cart::withoutGlobalScopes()->forGuestCookie(self::GUEST_COOKIE)->count(), 'Control: a real cookie selects its basket.');
        $this->assertSame(0, Cart::withoutGlobalScopes()->forGuestCookie('some-other-cookie')->count());
    }

    /**
     * RATCHET. Every host query on `cookie_session_id` goes through Cart::forGuestCookie(), whose only job is to
     * refuse a missing cookie. A new `where('cookie_session_id', ...)` is the same defect again.
     */
    public function test_no_host_query_names_cookie_session_id_outside_the_scope(): void
    {
        $hits = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/->where\(\s*[\'"]cookie_session_id[\'"]|::where\(\s*[\'"]cookie_session_id[\'"]|whereNull\(\s*[\'"]cookie_session_id/', $line)) {
                    $hits[] = str_replace(base_path() . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }

        $this->assertSame(['app/Models/Cart.php:' . $this->scopeLine()], $hits,
            'A query names cookie_session_id outside Cart::scopeForGuestCookie(): ' . implode(', ', array_diff($hits, ['app/Models/Cart.php:' . $this->scopeLine()])));
    }

    private function scopeLine(): int
    {
        foreach (file(base_path('app/Models/Cart.php')) as $n => $line) {
            if (preg_match('/->where\(\s*[\'"]cookie_session_id[\'"]/', $line)) {
                return $n + 1;
            }
        }

        return -1;
    }
}
