<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ActiveTheme;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE PRINCIPAL THE BATCH-2 ANALYSIS MISSED: an AUTHENTICATED WEB-GUARD user on a storefront
 * they do not own. This suite is the per-site proof for the five Customer reads and the two Cart
 * reads that the revert named as "breaking sites".
 *
 * ============================================================================================
 *  WHAT CHANGED IN THIS SUITE, AND WHY THAT IS A CORRECTION AND NOT A WEAKENING.
 * ============================================================================================
 *
 * The previous version of this file asserted
 *     test_cart_and_customer_are_deliberately_not_allowlisted
 * and
 *     test_allowlisting_them_demonstrably_breaks_both_storefront_reads
 * and it was RIGHT about the code it was written against. Those two assertions encoded a
 * CONSEQUENCE of a specific implementation -- that the predicate was derived from
 * `getCurrentStore()` on every request, including storefront requests -- as though it were a
 * property of the two models. It is not. The models are scopeable; the resolver was wrong.
 *
 * The mechanism of the original defect, unchanged and still true:
 *
 *   - routes/customer.php is `require`d from routes/web.php:1222, so storefront routes sit
 *     INSIDE the `web` middleware group. Confirmed from the router: status.cancel reports
 *     middleware [web, ActiveTheme, themelanguage].
 *   - config/session.php:146 sets the session cookie path to '/'.
 *   - Storefronts are path-prefixed ({storeSlug?}) on the SAME host.
 *
 * So a merchant or staff member with a live admin session is a POPULATED web-guard principal on
 * every storefront request, and `getCurrentStore()` returns their ADMIN store rather than the
 * store whose storefront they are viewing. The storefront's own reads filter on the SLUG-resolved
 * store, so ANDing an admin-derived predicate returned zero rows -- silently, because nothing
 * distinguishes that from "no such record".
 *
 * THE RESOLVER NOW ANSWERS PER CONTEXT (App\Scopes\StoreScope::resolveTenant()), so on a
 * storefront request the slug-resolved store is admissible. Every read below therefore WORKS
 * while both models are enforced. The property the old assertions protected -- "these reads must
 * not silently return nothing" -- is not dropped; it is asserted directly, read by read, at the
 * exact file:line each one lives at, which is strictly more coverage than a pin on a config
 * array. And the cross-tenant denial those models were enabled FOR is asserted in the admin
 * context in the same tests, through raw DB::table().
 *
 * EVIDENCE THE OLD ASSERTION WAS WRONG RATHER THAN INCONVENIENT: with the per-context resolver
 * reverted (`$slugStoreId = null` in resolveTenant()), the storefront reads below go back to
 * returning zero rows and this suite goes red. The defect was in the resolver, and it is the
 * resolver that was changed.
 */
class StorefrontWebGuardScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
        StoreScope::forgetSlugStoreIds();
        StoreScope::forgetOwnedStoreIds();

        parent::tearDown();
    }

    private function makeCustomerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => User::class,
        ]);
    }

    /**
     * Put the container's request into the state a real storefront request is in: matched to a
     * route that carries the `storeSlug` parameter and the ActiveTheme middleware. `Route::bind()`
     * is what the router itself calls to populate parameters from the URI.
     *
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function onStorefront(string $slug, string $path, callable $callback)
    {
        $route = new Route(['GET', 'POST'], '{storeSlug?}/' . ltrim($path, '/'), ['uses' => fn () => null]);
        $route->middleware([ActiveTheme::class, 'themelanguage']);

        $request = Request::create('/' . $slug . '/' . ltrim($path, '/'), 'GET');
        $route->bind($request);
        $request->setRouteResolver(fn () => $route);

        $original = app('request');
        app()->instance('request', $request);

        try {
            return $callback();
        } finally {
            app()->instance('request', $original);
            StoreScope::forgetSlugStoreIds();
        }
    }

    /** The decision itself, pinned so it cannot be undone without this suite failing. */
    public function test_cart_and_customer_are_enforced_again(): void
    {
        foreach ([Cart::class, Customer::class] as $class) {
            $this->assertTrue(
                StoreScope::isAllowlisted($class),
                $class . ' is NOT on config/tenancy.php `scoped_models`. It should be: with the '
                . 'tenant resolved per context, the storefront reads that forced the batch-2 '
                . 'revert work again (proven in this suite), and leaving it off keeps '
                . 'BulkDeleteController.php:113/:115 able to delete across the tenant boundary '
                . 'by bare id and by customer_id.'
            );
        }
    }

    // =============================================================================
    //  THE TWO CART SITES THE REVERT NAMED: app/Models/Cart.php:166 and :208
    // =============================================================================

    /** THE STOREFRONT CART must work for an admin-session visitor on a foreign store. */
    public function test_a_merchant_with_an_admin_session_can_read_a_foreign_storefronts_cart(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $cookie = 'cookie-' . uniqid();
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $foreignStore->id,
            'product_id' => 1, 'qty' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant->fresh());

        // The exact shape of app/Models/Cart.php:166 (Cart::cart_add) and :208
        // (Cart::cart_list_cookie), where `$store_id` comes from `Store::find($storeid)` --
        // i.e. the SLUG-resolved store.
        $rows = $this->onStorefront($foreignStore->slug, 'cart', fn () => Cart::where('cookie_session_id', $cookie)
            ->where('store_id', $foreignStore->id)
            ->get());

        $this->assertCount(1, $rows,
            'The storefront cart read returned nothing for an authenticated web-guard visitor. '
            . 'That is the silent basket-emptying failure that forced the batch-2 revert, and '
            . 'the per-context resolver exists to prevent it.');
    }

    /**
     * THE DENIAL THE ENABLEMENT IS FOR. The same row, the same principal, read from the ADMIN
     * context instead -- which is what BulkDeleteController and the admin API run in.
     */
    public function test_the_same_merchant_cannot_reach_that_cart_row_from_the_admin_context(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $cookie = 'cookie-' . uniqid();
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $foreignStore->id,
            'product_id' => 1, 'qty' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($merchant->fresh());

        // No storefront request: this is the admin panel / admin API shape.
        $this->assertCount(0, Cart::where('cookie_session_id', $cookie)->get(),
            'A merchant must not read another store\'s cart rows from the admin context. If this '
            . 'returns a row, enabling Cart bought nothing.');

        // BulkDeleteController.php:113 deletes `abandoncart` by customer_id with no tenant
        // predicate. Eloquent's delete() goes through toBase() and so carries global scopes.
        Cart::where('cookie_session_id', $cookie)->delete();

        $this->assertSame(1, (int) DB::table('carts')->where('cookie_session_id', $cookie)->count(),
            'Asserted through RAW DB::table() on purpose: a scoped read could hide damage the '
            . 'scope failed to prevent. The foreign row must still be there.');
    }

    // =============================================================================
    //  THE FIVE CUSTOMER SITES THE REVERT NAMED
    //  CustomerLoginController.php:87, :585, :751, :796, :847
    // =============================================================================

    /**
     * :87  -- the registration UNIQUENESS check
     * :585 -- the login lookup
     * :796 -- the forgot-password lookup
     * :847 -- the reset lookup
     *
     * All four are `Customer::where('email', ...)->where('store_id', $store->id)` where `$store`
     * came from `getStore($storeSlug)` (app/Helper/helper.php:2332). One shape, four sites, so
     * they are asserted as one shape against each site's own semantics below.
     */
    public function test_a_merchant_with_an_admin_session_can_resolve_a_foreign_storefronts_customer(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $shopper = $this->makeCustomerIn($foreignStore);

        $this->actingAs($merchant->fresh());

        [$count, $first] = $this->onStorefront($foreignStore->slug, 'login', fn () => [
            Customer::where('email', $shopper->email)->where('store_id', $foreignStore->id)->count(),
            Customer::where('email', $shopper->email)->where('store_id', $foreignStore->id)->first(),
        ]);

        $this->assertSame(1, $count,
            'CustomerLoginController.php:87. A zero here means the storefront registration '
            . 'uniqueness check would admit a DUPLICATE customer for that store.');
        $this->assertNotNull($first,
            'CustomerLoginController.php:585/:796/:847. A null here means login reports "no such '
            . 'customer" and password reset reports "no account found" for a customer that '
            . 'exists -- the silent storefront break.');
        $this->assertSame($shopper->id, $first->id);
    }

    /** :751 -- the post-registration `Auth::guard('customers')->attempt()` credential lookup. */
    public function test_the_customers_guard_can_still_authenticate_on_a_foreign_storefront(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $shopper = $this->makeCustomerIn($foreignStore);

        $this->actingAs($merchant->fresh());

        // The guard's user provider resolves App\Models\Customer, which is now a scoped model --
        // so the guard itself runs through this scope. If the predicate were wrong, a shopper
        // could not sign in on their own storefront while any merchant had an admin session.
        $resolved = $this->onStorefront($foreignStore->slug, 'login', fn () => auth()
            ->guard('customers')
            ->getProvider()
            ->retrieveById($shopper->id));

        $this->assertNotNull($resolved,
            'CustomerLoginController.php:751. The customers guard could not retrieve the shopper, '
            . 'so Auth::guard(\'customers\')->attempt() would fail on a live storefront.');
        $this->assertSame($shopper->id, $resolved->id);
    }

    /** THE DENIAL: the same PII row, read from the admin context, through raw DB for the negative. */
    public function test_the_same_merchant_cannot_reach_that_customer_from_the_admin_context(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $shopper = $this->makeCustomerIn($foreignStore);

        $this->actingAs($merchant->fresh());

        // Api/DashboardController.php:4919 and :7439 read a customer keyed on
        // `$request->customer_id` with no ownership check. This is that read.
        $this->assertNull(Customer::find($shopper->id),
            'A merchant must not read another store\'s customer by bare id from the admin '
            . 'context. Customer is the highest-value PII table on the allowlist.');

        Customer::where('id', $shopper->id)->update(['first_name' => 'Pwned']);
        Customer::where('id', $shopper->id)->delete();

        $row = DB::table('customers')->where('id', $shopper->id)->first();

        $this->assertNotNull($row, 'Asserted through RAW DB::table(): the row must still exist.');
        $this->assertSame('Shop', $row->first_name, 'and must be unmodified.');
    }

    /**
     * THE ANONYMOUS STOREFRONT, which the previous suite proved and which must keep working.
     * It passes trivially under the default fail-open policy, and it is kept precisely so a
     * future change to `tenancy.null_tenant` or `tenancy.storefront_tenant` cannot break guest
     * checkout unnoticed.
     */
    public function test_the_anonymous_storefront_still_reads_its_own_carts_and_customers(): void
    {
        [, $store] = $this->makeTenant();

        $cookie = 'cookie-' . uniqid();
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $store->id,
            'product_id' => 1, 'qty' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shopper = $this->makeCustomerIn($store);

        $this->assertGuest();

        [$carts, $customers] = $this->onStorefront($store->slug, 'cart', fn () => [
            Cart::where('cookie_session_id', $cookie)->where('store_id', $store->id)->count(),
            Customer::where('email', $shopper->email)->where('store_id', $store->id)->count(),
        ]);

        $this->assertSame(1, $carts, 'guest checkout must still see its basket');
        $this->assertSame(1, $customers, 'anonymous customer login must still resolve');
    }

    /**
     * AND THE SAME READS UNDER THE TIGHTER POLICY. `storefront_tenant=slug_only` narrows the
     * anonymous storefront from fail-open to the slug store, which is the stronger isolation an
     * operator may choose. It must not break the storefront it is protecting.
     */
    public function test_slug_only_mode_keeps_the_anonymous_storefront_working(): void
    {
        [, $store] = $this->makeTenant();
        [, $other] = $this->makeTenant();

        config(['tenancy.storefront_tenant' => 'slug_only']);

        $cookie = 'cookie-' . uniqid();
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $store->id,
            'product_id' => 1, 'qty' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $other->id,
            'product_id' => 1, 'qty' => 9, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertGuest();

        $rows = $this->onStorefront($store->slug, 'cart', fn () => Cart::where('cookie_session_id', $cookie)->get());

        $this->assertCount(1, $rows,
            'slug_only must leave the slug store readable; it narrows to that store, it does not '
            . 'empty it.');
        $this->assertSame($store->id, (int) $rows->first()->store_id,
            'and it must drop the OTHER store\'s row even though the cookie matches -- which is '
            . 'the fail-open hole this mode exists to close.');
    }
}
