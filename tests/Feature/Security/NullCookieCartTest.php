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
 * THE TENANT DOES NOT BOUND IT, and an earlier version of this note said it did. MEASURED on the unfixed code: one
 * anonymous `cart-clear` on store 1's slug deleted the logged-in customers' baskets of EVERY store (two baskets in two
 * stores -> none). The belief came from tests that `actingAs($customer, 'customers')`: that also makes `customers` the
 * DEFAULT guard, so a tenant resolves and StoreScope adds a `store_id` predicate. In production the default guard is
 * `web`, a storefront request resolves no tenant, and with `null_tenant => 'open'` the scope adds nothing. So the
 * assertions below that another STORE's basket survives are only meaningful for the ANONYMOUS tests; the logged-in ones
 * prove same-store behaviour.
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

        $response = $this->post('/' . $w['store']->slug . '/change-cart', ['product_id' => $w['p']->id, 'variant_id' => 0, 'quantity_type' => 'increase']);

        // The answer, not only the row: with the cookie matching nothing the handler must say so (HTTP 200,
        // status 0) rather than dereference a missing row and 500 -- and a 500 would leave the row untouched too.
        $response->assertOk();
        $this->assertSame(0, (int) $response->json('status'), 'Premise: the guest\'s quantity change found no basket.');
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

    // -- the logged-in arm of cart-remove: the row id is the REQUEST's ---------------------------

    /**
     * `cart_remove`'s logged-in arm did `Cart::where('id', $request->cart_id)->first()` and deleted it, with no check
     * that the row is the caller's. Cart ids are sequential integers and Cart is store-scoped, so any logged-in
     * customer could delete any other customer's basket line in their own store. (change-cart is NOT open: it resolves
     * the principal and refuses -- probed; this one never did.)
     */
    public function test_a_logged_in_customer_cannot_remove_another_customers_cart_line_by_id(): void
    {
        $w = $this->world();
        $row = Cart::forceCreate(['customer_id' => $w['b']->id, 'product_id' => $w['p']->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $w['store']->id]);

        $response = $this->actingAs($w['a'], 'customers')->post('/' . $w['store']->slug . '/cart-remove', ['cart_id' => $row->id]);

        $response->assertOk();
        $this->assertTrue(DB::table('carts')->where('id', $row->id)->exists(), 'A customer deleted another customer\'s basket line by its id.');
        $this->assertSame(1, self::rows($w['a']), 'Premise: the caller\'s own line is untouched too.');
    }

    public function test_a_logged_in_customer_can_still_remove_their_own_cart_line_by_id(): void
    {
        $w = $this->world();
        $own = (int) DB::table('carts')->where('customer_id', $w['a']->id)->value('id');

        $this->actingAs($w['a'], 'customers')->post('/' . $w['store']->slug . '/cart-remove', ['cart_id' => $own])->assertOk();

        $this->assertFalse(DB::table('carts')->where('id', $own)->exists(), 'The customer\'s own line was not removed (control).');
    }

    /** The count a guest's quantity change returns is THEIR basket's, not a request-chosen customer's (an oracle for any customer id). */
    public function test_a_guests_quantity_change_reports_their_own_basket_count_not_a_customers(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);
        Cart::forceCreate(['customer_id' => $w['a']->id, 'product_id' => $w['p']->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $w['store']->id]);
        Cart::forceCreate(['customer_id' => $w['a']->id, 'product_id' => $w['p']->id, 'variant_id' => 1, 'qty' => 1, 'price' => 10, 'store_id' => $w['store']->id]);

        $response = $this->withCookie('cart', self::GUEST_COOKIE)
            ->post('/' . $w['store']->slug . '/change-cart', ['product_id' => $w['p']->id, 'variant_id' => 0, 'quantity_type' => 'increase', 'user_id' => $w['a']->id]);

        $response->assertOk();
        $this->assertSame(1, (int) $response->json('status'), 'Premise: the quantity change succeeded and reached the count (a decrease from 1 returns early with a constant count).');
        $this->assertSame(1, (int) $response->json('count'), 'The count returned was not the guest\'s own basket (one row): a customer\'s line count leaked through user_id.');
    }

    // -- change-cart's logged-in arm -----------------------------------------------------------

    public function test_a_logged_in_customer_changing_a_foreign_or_unknown_cart_id_gets_a_clean_refusal(): void
    {
        $w = $this->world();
        $row = Cart::forceCreate(['customer_id' => $w['b']->id, 'product_id' => $w['p']->id, 'variant_id' => 0, 'qty' => 3, 'price' => 10, 'store_id' => $w['store']->id]);

        foreach ([$row->id, 99999999] as $cartId) {
            $response = $this->actingAs($w['a'], 'customers')->post('/' . $w['store']->slug . '/change-cart', ['cart_id' => $cartId, 'quantity_type' => 'increase']);

            $response->assertOk();   // not a 500: an unknown id used to dereference null
            $this->assertSame(0, (int) $response->json('status'));
        }

        $this->assertSame(3, (int) DB::table('carts')->where('id', $row->id)->value('qty'), 'Another customer\'s basket row was altered.');
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

    /** The cookie is the guest's across every store; the customer belongs to one. Their other store's basket stays theirs. */
    public function test_the_login_merge_moves_only_this_stores_rows(): void
    {
        $w = $this->world();
        $this->guestBasket($w['store'], $w['p']);
        $otherProduct = Product::withoutGlobalScopes()->where('store_id', $w['other']->id)->first();
        Cart::forceCreate(['customer_id' => 0, 'product_id' => $otherProduct->id, 'variant_id' => 0, 'qty' => 1, 'price' => 10, 'store_id' => $w['other']->id, 'cookie_session_id' => self::GUEST_COOKIE]);

        $response = $this->withCookie('cart', self::GUEST_COOKIE)->post('/' . $w['store']->slug . '/login', ['email' => $w['b']->email, 'password' => 'secret123']);

        $this->assertStringContainsString('/home', (string) $response->headers->get('Location'), 'Premise: the login succeeded.');
        $this->assertSame(1, self::rows($w['b']), 'Premise: this store\'s guest row was merged.');
        $this->assertSame(1, (int) DB::table('carts')->where('store_id', $w['other']->id)->where('cookie_session_id', self::GUEST_COOKIE)->count(),
            'The guest\'s basket in ANOTHER store was moved onto this store\'s customer.');
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
     * RATCHET. Every host query on `cookie_session_id` goes through Cart::forGuestCookie(), whose only job is to refuse a
     * missing cookie. Comments are stripped with the tokenizer, so a docblock quoting the old statement is not a hit, and
     * the match survives a line break between `where(` and the column, the array form, a qualified `carts.` column and
     * raw SQL. `DB::table('carts')` is forbidden outright: it would bypass both the scopes and Cart's own global scope.
     */
    public function test_no_host_query_names_cookie_session_id_outside_the_scope(): void
    {
        $hits = [];
        $rawTable = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $code = '';
            foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
                if (is_array($token)) {
                    if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                        continue;
                    }
                    $code .= $token[1];
                } else {
                    $code .= $token;
                }
            }

            $relative = str_replace(base_path() . '/', '', $file->getPathname());

            if (preg_match("/DB::table\(\s*['\"]carts['\"]/", $code)) {
                $rawTable[] = $relative;
            }

            preg_match_all("/(?:where\w*|orWhere\w*)\s*\(\s*(?:\[\s*)?['\"](?:\w+\.)?cookie_session_id['\"]|whereRaw\([^)]*cookie_session_id/s", $code, $m);
            foreach ($m[0] as $_) {
                $hits[] = $relative;
            }
        }

        $this->assertSame([], $rawTable, 'DB::table(\'carts\') bypasses the Cart scopes: ' . implode(', ', $rawTable));
        $this->assertSame(['app/Models/Cart.php'], $hits,
            'A query names cookie_session_id outside Cart::scopeForGuestCookie() (it appears once, in Cart.php): ' . implode(', ', $hits));
    }
}
