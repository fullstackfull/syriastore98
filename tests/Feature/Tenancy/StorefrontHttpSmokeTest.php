<?php

namespace Tests\Feature\Tenancy;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Events\RouteMatched;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Security\SecurityTestCase;

/**
 * A REAL HTTP SMOKE OF THE STOREFRONT, THROUGH THE ACTUAL ROUTER AND MIDDLEWARE STACK.
 *
 * WHY THIS EXISTS WHEN StorefrontWebGuardScopeTest ALREADY PASSES. That suite proves the
 * per-site reads at the model layer, but it simulates a storefront request by binding a
 * SYNTHETIC Illuminate\Routing\Route and swapping app('request') (see its onStorefront()).
 * Nothing in it dispatches through the `web` group, the real router, ActiveTheme, the session
 * or the view layer. A storefront-breaking tenancy control has shipped TWICE on this project
 * and both times every model-layer assertion was green, so the gap this closes is not
 * hypothetical.
 *
 * WHAT HAS TEETH HERE, AND WHAT DOES NOT -- MEASURED, NOT ASSUMED. The first draft of this
 * file asserted that a storefront URL answers an anonymous visitor and a foreign signed-in
 * merchant with the SAME status code. That assertion is worthless: with the per-context
 * resolver reverted (`$slugStoreId = null` at app/Scopes/StoreScope.php:510) the cart page
 * still answered 200 to both, because an EMPTY basket renders the same ~46KB of chrome as a
 * full one. The draft passed with the defect reintroduced. That is precisely how the batch-2
 * defect escaped notice: it did not throw, it silently returned nothing.
 *
 * So the assertions below are on THE TENANT THE SCOPE RESOLVED while the product's own route
 * was matched, captured from the RouteMatched event during a real dispatch. With the defect
 * reintroduced these go red, which is verified in the commit that adds this file.
 */
class StorefrontHttpSmokeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Dispatch a URL for real and capture what StoreScope resolved when the route matched.
     */
    private function dispatchAndCapture(string $url, ?User $as = null): array
    {
        $captured = [];

        Event::listen(RouteMatched::class, function () use (&$captured) {
            $captured[] = StoreScope::resolveTenant();
        });

        try {
            $response = $as ? $this->actingAs($as)->get($url) : $this->get($url);
        } finally {
            StoreScope::forgetSlugStoreIds();
        }

        $this->assertNotEmpty(
            $captured,
            'No RouteMatched fired for ' . $url . ', so the real router never matched a product '
            . 'route and this test would prove nothing.'
        );

        return [$response->getStatusCode(), $captured[0]];
    }

    /**
     * Run a callback in the MERCHANT-ADMIN context, having left any storefront context behind.
     *
     * Both halves are required: app('request') must no longer be a storefront request, AND
     * StoreScope's slug memo must be dropped. Clearing only one leaves the previous dispatch's
     * tenant in force -- an earlier draft of this test asserted 0 without doing either and got
     * 1, which was the test's error and not the scope's, but it is the same shape as the leak
     * a long-lived worker could suffer (see the memo guards at StoreScope.php:830 and :1160).
     */
    private function inAdminContext(callable $callback)
    {
        $original = app('request');
        app()->instance('request', \Illuminate\Http\Request::create('/admin/dashboard', 'GET'));
        StoreScope::forgetSlugStoreIds();

        try {
            return $callback();
        } finally {
            app()->instance('request', $original);
            StoreScope::forgetSlugStoreIds();
        }
    }

    public function test_a_real_cart_dispatch_resolves_the_slug_store_for_a_foreign_merchant(): void
    {
        [, $storeA]  = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        [$status, $tenant] = $this->dispatchAndCapture('/' . $storeA->slug . '/cart', $merchantB);

        $this->assertSame(200, $status, 'GET {slug}/cart returned ' . $status);

        $this->assertSame(
            StoreScope::CONTEXT_STOREFRONT_WEB,
            $tenant['context'],
            'A signed-in merchant of ANOTHER store browsing store A\'s cart page resolved as '
            . $tenant['context'] . ' instead of storefront-web. reason: ' . $tenant['reason']
        );

        $this->assertContains(
            $storeA->id,
            $tenant['store_ids'],
            'Store A\'s own id is NOT admissible on store A\'s storefront. That is the batch-2 '
            . 'defect exactly: the storefront\'s own reads filter on the slug store, so a '
            . 'predicate excluding it ANDs every storefront query to zero rows -- silently. '
            . 'resolved: ' . json_encode($tenant)
        );
    }

    public function test_a_real_cart_dispatch_by_a_guest_resolves_a_storefront_context(): void
    {
        [, $storeA] = $this->makeTenant();

        [$status, $tenant] = $this->dispatchAndCapture('/' . $storeA->slug . '/cart');

        $this->assertSame(200, $status, 'GET {slug}/cart returned ' . $status . ' to a guest');

        $this->assertContains(
            $tenant['context'],
            [StoreScope::CONTEXT_STOREFRONT_PUBLIC, StoreScope::CONTEXT_UNRESOLVED],
            'An anonymous storefront visitor resolved as ' . $tenant['context']
            . '. reason: ' . $tenant['reason']
        );
    }

    public function test_a_real_product_list_dispatch_also_resolves_the_slug_store(): void
    {
        [, $storeA]  = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        [$status, $tenant] = $this->dispatchAndCapture(
            '/' . $storeA->slug . '/product-list',
            $merchantB
        );

        $this->assertSame(200, $status, 'GET {slug}/product-list returned ' . $status);
        $this->assertSame(StoreScope::CONTEXT_STOREFRONT_WEB, $tenant['context']);
        $this->assertContains($storeA->id, $tenant['store_ids']);
    }

    /**
     * The row-level half, across a real dispatch: a guest cart belonging to store A is not
     * disturbed by the dispatch and is NOT reachable from merchant B's own admin context.
     * Read back through raw DB::table() so the scope cannot hide damage it failed to prevent.
     */
    public function test_a_store_a_cart_row_survives_a_real_dispatch_and_stays_invisible_to_b(): void
    {
        [, $storeA]           = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $cookie = 'smoke-' . uniqid();
        DB::table('carts')->insert([
            'store_id'          => $storeA->id,
            'cookie_session_id' => $cookie,
            'product_id'        => 1,
            'variant_id'        => 0,
            'qty'               => 1,
            'price'             => 10,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->assertSame(
            1,
            DB::table('carts')->where('cookie_session_id', $cookie)->count(),
            'fixture did not land'
        );

        $this->actingAs($merchantB)->get('/' . $storeA->slug . '/cart');
        StoreScope::forgetSlugStoreIds();

        $this->assertSame(
            1,
            DB::table('carts')->where('cookie_session_id', $cookie)->count(),
            'a storefront GET mutated a cart row'
        );

        $merchantB->current_store = $storeB->id;
        $merchantB->save();

        $this->assertSame(
            0,
            $this->inAdminContext(fn () => Cart::where('cookie_session_id', $cookie)->count()),
            'merchant B resolved store A\'s cart row from their own admin context'
        );
    }

    /** A customer of store A is untouched by, and invisible to, a foreign admin session. */
    public function test_a_customer_of_store_a_is_untouched_by_a_foreign_admin_session(): void
    {
        [, $storeA]           = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $email = 'smoke-' . uniqid() . '@example.com';
        DB::table('customers')->insert([
            'store_id'   => $storeA->id,
            'first_name' => 'Smoke',
            'last_name'  => 'Tester',
            'email'      => $email,
            'password'   => bcrypt('secret'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($merchantB)->get('/' . $storeA->slug . '/cart');
        StoreScope::forgetSlugStoreIds();

        $this->assertSame(
            1,
            DB::table('customers')->where('email', $email)->count(),
            'a storefront dispatch under a foreign admin session altered a customer row'
        );

        $merchantB->current_store = $storeB->id;
        $merchantB->save();

        $this->assertSame(
            0,
            $this->inAdminContext(fn () => Customer::where('email', $email)->count()),
            'merchant B read store A\'s customer from their own admin context'
        );
    }
}
