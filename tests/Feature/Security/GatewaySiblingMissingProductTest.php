<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * D-22 / F121 ON THE THREE SIBLING GATEWAYS, AND ON THE GUEST ARM.
 *
 * `GatewayReturnMissingProductTest` drives Khalti's AUTHENTICATED arm, which is the arm that can be
 * paired with a real verification outcome. This file covers what that one cannot:
 *
 *   - the GUEST arm, which reads `Cart::cart_list_cookie()` rather than `ApiController::cart_list()`
 *     -- a different function with its own copy of the unguarded dereference;
 *   - PaiementPro, CinetPay and Easebuzz, the other three handlers D-18 names.
 *
 * THESE THREE DIFFER FROM KHALTI IN A WAY THAT MATTERS TO THIS FIX. None of them verifies anything:
 * `PaymentVerificationService` holds three provider verifiers and none of them is theirs, so every
 * return they receive is "before proof of collection" by construction. The guard therefore applies
 * to the whole path rather than to one branch of it, and the refusal carries no verification
 * outcome because there is none to carry.
 *
 * WHAT IS BEING FIXED IS THE 500, NOT THE LOST ORDER. Before: an unhandled `ErrorException` from the
 * cart read, no order, no decrement. After: a structured refusal, no order, no decrement. The
 * customer of a gateway that has already collected still has no order -- that half is the owner's
 * decision, recorded in the register -- but the failure is now a message and a `critical` log rather
 * than a stack trace.
 *
 * @see GatewayReturnMissingProductTest for the authenticated arm, the verified-capture half and the
 *      bite matrix.
 */
class GatewaySiblingMissingProductTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: string}>
     */
    public static function gatewayReturnRoutes(): array
    {
        return [
            'paiementpro' => ['get-payment-paiementpro'],
            'cinetpay' => ['get-payment-cinetPay'],
            'easebuzz' => ['get-payment-easebuzz'],
            'khalti (guest arm)' => ['order-khalti'],
        ];
    }

    /**
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_dangling_guest_basket_refuses_without_an_unhandled_exception(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull(
            $outcome['exception'],
            $uri . ': a basket naming a deleted product must not produce an unhandled exception'
        );
        $this->assertSame(0, $outcome['orders'], $uri . ': and it must write no order');
    }

    /**
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_dangling_variant_reference_refuses_without_an_unhandled_exception(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product, 987654);

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull(
            $outcome['exception'],
            $uri . ': a basket naming a variant row that does not exist must not throw'
        );
        $this->assertSame(
            50,
            (int) DB::table('products')->where('id', $product->id)->value('product_stock'),
            $uri . ': and it must take no stock'
        );
    }

    /**
     * THE CONTROL, per route. A guard that refused every basket would satisfy both cases above on
     * every row. This asserts the ordinary guest basket still reaches the handler's own logic --
     * which for these gateways means a decrement, since `StockManagementSettingTest` already
     * establishes that cinetpay, easebuzz and khalti reach their stock loops from here.
     *
     * PaiementPro is asserted on the weaker property (no refusal of this kind) rather than on the
     * decrement: its own stock behaviour is pinned by `StockManagementSettingTest`, and duplicating
     * that here would couple this file to a second gateway's pricing path for nothing.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_an_intact_guest_basket_is_not_refused_by_the_new_guard(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        $outcome = $this->drive($store, $uri, $sid);

        $this->assertReachedTheHandler($uri, $outcome);
        $this->assertNull($outcome['exception'], $uri . ': an ordinary basket must not throw');
        $this->assertStringNotContainsString(
            'no longer available',
            (string) session('error'),
            $uri . ': the dangling-row guard must not fire for a basket with nothing missing'
        );
    }

    /**
     * THE GUARD MUST NOT SWEEP THE STORE WHEN THE GUEST HAS NO COOKIE. Found by the project's
     * reviewer, in the guard's own first cut: a null cookie passed to
     * `where('cookie_session_id', $guestCookie)` becomes `whereNull()`, and `cookie_session_id` is
     * nullable with no default -- so every REGISTERED customer's row in the store carries NULL
     * there. The predicate silently became "this store's customer baskets", and one deleted
     * catalogue product would have refused every cookie-less guest, after their capture had been
     * classified.
     *
     * Driven here with the trigger in place: another customer in the same store holds a basket
     * naming a deleted product, and the cookie-less guest must NOT be refused for it.
     *
     * @dataProvider gatewayReturnRoutes
     */
    public function test_a_cookieless_guest_is_not_refused_for_another_customers_dangling_basket(string $uri): void
    {
        [, $store] = $this->tenant();

        // The trigger: a different principal's basket in the same store, naming a deleted product.
        $doomed = $this->productIn($store);
        $other = \App\Models\Customer::forceCreate([
            'first_name' => 'Other', 'last_name' => 'Shopper',
            'email' => 'd22s-other-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
        Cart::forceCreate([
            'customer_id' => $other->id, 'product_id' => $doomed->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        DB::table('products')->where('id', $doomed->id)->delete();

        $this->assertNull(
            DB::table('carts')->where('customer_id', $other->id)->value('cookie_session_id'),
            'premise: a customer basket row really does carry NULL there, which is what made the '
            . 'null cookie match it'
        );

        $exception = null;
        $this->withoutExceptionHandling();

        try {
            // No cart cookie at all.
            $state = $this->checkoutState($store);

            $this->withSession([
                    'request_data' => $state,
                    'easebuzz_request_data' => $state,
                ])
                ->post('/' . $store->slug . '/' . $uri, []);
        } catch (\Throwable $e) {
            $exception = get_class($e) . ': ' . $e->getMessage();
        }

        $this->assertNull($exception, $uri . ': a cookie-less guest must not throw');
        $this->assertStringNotContainsString(
            'no longer available',
            (string) session('error'),
            $uri . ': the guard matched another principal\'s basket through a null cookie'
        );
    }

    // ---------------------------------------------------------------- fixture

    private function drive($store, string $uri, string $sid): array
    {
        $exception = null;
        $this->withoutExceptionHandling();

        try {
            $state = $this->checkoutState($store);

            $this->withCookie('cart', $sid)
                ->withSession([
                    'request_data' => $state,
                    // Easebuzz reads its OWN session key and does NOT fall back to `request_data`
                    // (storedCheckoutState's third argument), so seeding only the shared one made
                    // its rows refuse at the entry guard and pass for the wrong reason. Both keys
                    // are seeded and the premise below makes a repeat of that impossible.
                    'easebuzz_request_data' => $state,
                ])
                ->post('/' . $store->slug . '/' . $uri, []);
        } catch (\Throwable $e) {
            $exception = get_class($e) . ': ' . $e->getMessage();
        }

        return [
            'exception' => $exception,
            'orders' => (int) DB::table('orders')->where('store_id', $store->id)->count(),
            'error' => (string) session('error'),
        ];
    }

    /**
     * THE PREMISE EVERY ROW IN THIS FILE DEPENDS ON, asserted rather than assumed.
     *
     * The first version of this file seeded only `request_data`. Easebuzz reads
     * `easebuzz_request_data` with no fallback, so its rows never got past the entry guard and
     * passed because the handler refused for an unrelated reason -- a vacuous green, which is the
     * failure mode CLAUDE.md names first. This asserts the handler did NOT stop at that guard.
     */
    private function assertReachedTheHandler(string $uri, array $outcome): void
    {
        $this->assertStringNotContainsString(
            'could not confirm your checkout details',
            $outcome['error'],
            $uri . ': the handler refused at the stored-state entry guard, so this row proves '
            . 'nothing about the cart read'
        );
    }

    private function guestCart($store, Product $product, int $variantId = 0): string
    {
        $sid = 'd22-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $product->id, 'variant_id' => $variantId,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'd22s-' . uniqid(), 'slug' => 'd22s-' . uniqid(),
            'product_stock' => 50, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 100.00,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function checkoutState($store): array
    {
        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'paiementpro',
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
        ];
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Store} */
    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'd22s-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
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
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        foreach ([
            ['countries', ['id' => 101, 'name' => 'Syria']],
            ['states', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101]],
            ['cities', ['id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1]],
        ] as [$table, $row]) {
            DB::table($table)->insertOrIgnore($row + ['created_at' => now(), 'updated_at' => now()]);
        }

        return [$merchant, $store];
    }
}
