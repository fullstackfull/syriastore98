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

    /**
     * @return array<string, array{0: string}>
     */
    public static function unverifiedGatewayRoutes(): array
    {
        /*
         * KHALTI IS DELIBERATELY ABSENT, and its absence is asserted by its own case below rather
         * than left as a gap. `PaymentVerificationService` holds three provider verifiers;
         * PaiementPro, CinetPay and Easebuzz are none of them, so a return on those three is
         * unproven BY CONSTRUCTION and reaches the basket guard. Khalti's guest arm verifies
         * FIRST -- D-22 moved the verification above the cart read -- so with no gateway response
         * faked it is refused at verification and the basket guard is never reached.
         */
        return [
            'paiementpro' => ['get-payment-paiementpro'],
            'cinetpay' => ['get-payment-cinetPay'],
            'easebuzz' => ['get-payment-easebuzz'],
        ];
    }

    /**
     * D-22's SECOND HALF, ON THE GATEWAYS THAT VERIFY NOTHING.
     *
     * Those three gateways have no verifier at all, so the money may well have been taken before
     * the redirect. The honest capture state is `unknown` -- neither `confirmed` nor `rejected` --
     * and the merchant still needs to be told, because a capture nobody checks is worse than one
     * that is merely uncertain.
     *
     * @dataProvider unverifiedGatewayRoutes
     */
    public function test_a_capture_that_produced_no_order_reaches_the_merchant(string $uri): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, $uri, $sid);
        $this->assertReachedTheHandler($uri, $outcome);

        $row = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->first();

        $this->assertNotNull(
            $row,
            $uri . ': the return refused and left the merchant nothing to act on. On a gateway'
            . ' with no verifier the money may already be taken, which is exactly when a merchant'
            . ' needs to know.'
        );

        $data = json_decode((string) $row->data, true);

        $this->assertSame(
            'unknown',
            $data['capture_state'] ?? null,
            $uri . ': a gateway with no verifier must report its capture state as unknown, not'
            . ' claim one. (The first version of the mapping collapsed `unverifiable` into'
            . ' `rejected`, so an unreachable gateway would have told the merchant nothing while'
            . ' the card may well have been charged.)'
        );
        $this->assertSame($uri === 'get-payment-cinetPay' ? 'cinetpay' : ($uri === 'get-payment-easebuzz' ? 'easebuzz' : 'paiementpro'), $data['gateway'] ?? null);

        $this->assertSame('payment', $row->reference_type, $uri . ': this is not an order notification');
        $this->assertNull($row->reference_id, $uri . ': there is no order to reference');
        $this->assertSame(0, $outcome['orders'], $uri . ': and recording it is not writing the order');
    }

    /**
     * A VARYING TOTAL MUST NOT MULTIPLY THE BELL — the hardening the independent review asked
     * for, driven rather than reasoned.
     *
     * The review traced a reachable path to the `unknown` state: `getValidPaymentTypes()` is a
     * static list of ~50 gateway names rather than the store's enabled set, so any gateway name
     * passes on any store; `PaymentService::processCinetPay` puts the checkout state in the
     * session BEFORE its cURL call and whether or not the store has credentials; the return route
     * is `Route::any`, so it is reachable by GET with no gateway round trip. An amount-keyed
     * dedupe would therefore let a shopper vary the basket total and multiply both rows and FCM
     * pushes — a nuisance primitive, not a cross-tenant one, but one worth closing.
     *
     * So for `unknown` the key is the store and the gateway, and the amount is part of it only
     * for `confirmed`, which cannot be reached without a verifier positively answering VERIFIED.
     */
    public function test_a_varying_total_does_not_multiply_the_bell_on_an_unverified_gateway(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        /*
         * Three returns whose STORED TOTAL differs, which is the shape the dedupe has to survive.
         * The amount the refusal records is `serverPayableAmount()`, read from the session state's
         * `cartlist_final_price` -- so the state is seeded directly here rather than through
         * `drive()`, which hardcodes one total. (The first version of this case tried to vary it
         * through `carts.quantity`; that column is `qty`, and varying it would not have moved the
         * recorded amount anyway, since the amount comes from the stored state and not from the
         * basket.)
         */
        $this->withoutExceptionHandling();

        foreach ([100.00, 250.00, 999.00] as $total) {
            $state = $this->checkoutState($store);
            $state['cartlist_final_price'] = $total;

            try {
                $this->withCookie('cart', $sid)
                    ->withSession(['request_data' => $state, 'easebuzz_request_data' => $state])
                    ->post('/' . $store->slug . '/get-payment-cinetPay', []);
            } catch (\Throwable $e) {
                $this->fail('a varied-total return must not throw: ' . get_class($e) . ': ' . $e->getMessage());
            }
        }

        $amounts = DB::table('store_notifications')
            ->where('store_id', $store->id)
            ->where('type', 'payment_needs_attention')
            ->pluck('data')
            ->map(fn ($d) => json_decode((string) $d, true)['amount'] ?? null)
            ->all();

        $this->assertNotSame([], $amounts, 'the premise: at least one return reached the refusal');

        $this->assertSame(
            1,
            (int) DB::table('store_notifications')
                ->where('store_id', $store->id)
                ->where('type', 'payment_needs_attention')
                ->count(),
            'three unverified returns with three different totals must leave ONE row (recorded'
            . ' amounts: ' . implode(', ', array_map(fn ($a) => var_export($a, true), $amounts)) . ').'
            . " The merchant action for an unknown capture is \"check the gateway\", which is one"
            . ' action however many totals were tried, and every occurrence is still in the'
            . ' critical log.'
        );
    }

    /**
     * WHY KHALTI'S GUEST ARM IS NOT IN THE PROVIDER ABOVE — asserted, not assumed.
     *
     * D-22 moved the verification ABOVE the cart read, so on Khalti an unverified return is
     * refused before the basket guard is ever asked. With no gateway response faked, that is what
     * happens here: the handler refuses at verification, writes no order, and writes no
     * `payment_needs_attention` row because the guard that writes it was never reached.
     *
     * That is the hoist working, not a gap in this half — and it is pinned so that a later change
     * which moves the verification back below the cart read fails here rather than silently
     * restoring the crash D-22 removed. (`GatewayReturnMissingProductTest` drives the Khalti arm
     * WITH a faked response and asserts the notification is written there.)
     */
    public function test_khalti_refuses_at_verification_before_the_basket_guard_is_reached(): void
    {
        [, $store] = $this->tenant();
        $product = $this->productIn($store);
        $sid = $this->guestCart($store, $product);

        DB::table('products')->where('id', $product->id)->delete();

        $outcome = $this->drive($store, 'order-khalti', $sid);

        $this->assertNull($outcome['exception'], 'the refusal must still not be an exception');
        $this->assertSame(0, $outcome['orders'], 'and it must write no order');
        $this->assertSame(
            0,
            (int) DB::table('store_notifications')
                ->where('store_id', $store->id)
                ->where('type', 'payment_needs_attention')
                ->count(),
            'khalti verifies BEFORE the cart read, so an unverified return never reaches the'
            . ' basket guard and there is no capture to record. If this starts writing a row, the'
            . ' verification has moved back below the cart read and the 500 D-22 removed is'
            . ' reachable again.'
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
