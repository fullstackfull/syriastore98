<?php

namespace Tests\Feature\Tenancy;

use App\Models\Cart;
use App\Models\Customer;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE PRINCIPAL THAT THE BATCH-2 ANALYSIS MISSED: an AUTHENTICATED WEB-GUARD user on a
 * storefront they do not own.
 *
 * Cart and Customer were enabled on the premise that they are "always" read on the
 * `customers` guard or anonymously, so a tenant predicate could not empty a shopper's
 * basket. That premise is false, and the mechanism is structural rather than incidental:
 *
 *   - routes/customer.php is `require`d from routes/web.php:1222, so storefront routes sit
 *     INSIDE the `web` middleware group. Confirmed from the router: status.cancel reports
 *     middleware [web, ActiveTheme, themelanguage].
 *   - config/session.php:146 sets the session cookie path to '/'.
 *   - Storefronts are path-prefixed ({storeSlug?}) on the SAME host.
 *
 * So a merchant or staff member with a live admin session is a POPULATED web-guard principal
 * on every storefront request, and getCurrentStore() returns their ADMIN store rather than
 * the store whose storefront they are viewing. The storefront's own reads filter on the
 * SLUG-resolved store (app/Models/Cart.php:166 and :208;
 * CustomerLoginController.php:87, :585, :751, :796, :847), so ANDing a tenant predicate
 * returns zero rows -- silently, because nothing distinguishes that from "no such record".
 *
 * The previous suite proved only the ANONYMOUS storefront case, which fails open and so
 * passes trivially. This suite drives the case that actually breaks, in both directions.
 */
class StorefrontWebGuardScopeTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shop', 'last_name' => 'Per',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id, 'status' => 1,
            'billable_id' => $store->created_by, 'billable_type' => \App\Models\User::class,
        ]);
    }

    /** The decision itself, pinned so it cannot be undone without this suite failing. */
    public function test_cart_and_customer_are_deliberately_not_allowlisted(): void
    {
        foreach ([Cart::class, Customer::class] as $class) {
            $this->assertFalse(
                StoreScope::isAllowlisted($class),
                $class . ' is on config/tenancy.php `scoped_models`. It must not be: every '
                . 'storefront read site filters it by a SLUG-resolved store id, so an '
                . 'authenticated web-guard principal on a foreign storefront gets zero rows '
                . 'and the storefront silently breaks. Re-enable only after every storefront '
                . 'read has an explicit withoutGlobalScope escape AND this suite is extended '
                . 'to prove it. See condition 5 in config/tenancy.php.'
            );
        }
    }

    /** THE STOREFRONT CART must still work for an admin-session visitor on a foreign store. */
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

        // The exact shape of app/Models/Cart.php:208.
        $rows = Cart::where('cookie_session_id', $cookie)
            ->where('store_id', $foreignStore->id)
            ->get();

        $this->assertCount(1, $rows,
            'The storefront cart read returned nothing for an authenticated web-guard '
            . 'visitor. That is the silent basket-emptying failure condition 5 describes.');
    }

    /** STOREFRONT CUSTOMER LOGIN and the registration uniqueness check must still work. */
    public function test_a_merchant_with_an_admin_session_can_resolve_a_foreign_storefronts_customer(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $shopper = $this->makeCustomerIn($foreignStore);

        $this->actingAs($merchant->fresh());

        // The exact shape of CustomerLoginController.php:87.
        $count = Customer::where('email', $shopper->email)
            ->where('store_id', $foreignStore->id)
            ->count();

        $this->assertSame(1, $count,
            'The storefront customer lookup returned 0 for an authenticated web-guard '
            . 'visitor. Login would report "no such customer", and the registration '
            . 'uniqueness check would admit a duplicate.');
    }

    /**
     * TEETH, in the direction that matters: demonstrate that enabling the scope on these two
     * models DOES break those exact reads. Without this the suite above could pass simply
     * because the scope does nothing at all.
     */
    public function test_allowlisting_them_demonstrably_breaks_both_storefront_reads(): void
    {
        [$merchant] = $this->makeTenant();
        [, $foreignStore] = $this->makeTenant();

        $cookie = 'cookie-' . uniqid();
        DB::table('carts')->insert([
            'cookie_session_id' => $cookie, 'store_id' => $foreignStore->id,
            'product_id' => 1, 'qty' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $shopper = $this->makeCustomerIn($foreignStore);

        $this->actingAs($merchant->fresh());

        // Sanity: both reads work while the models are unscoped.
        $this->assertCount(1, Cart::where('cookie_session_id', $cookie)->where('store_id', $foreignStore->id)->get());
        $this->assertSame(1, Customer::where('email', $shopper->email)->where('store_id', $foreignStore->id)->count());

        // Now enable exactly what was removed. isAllowlisted() reads config at call time.
        config(['tenancy.scoped_models' => array_merge(
            config('tenancy.scoped_models', []),
            [Cart::class, Customer::class]
        )]);

        $this->assertTrue(StoreScope::isAllowlisted(Cart::class), 'the toggle must take effect');

        $this->assertCount(0, Cart::where('cookie_session_id', $cookie)->where('store_id', $foreignStore->id)->get(),
            'Expected the scoped read to return nothing -- that is the defect being pinned.');
        $this->assertSame(0, Customer::where('email', $shopper->email)->where('store_id', $foreignStore->id)->count(),
            'Expected the scoped lookup to return 0 -- that is the defect being pinned.');

        // And the rows are still there: the scope hid them, it did not delete them.
        $this->assertSame(1, (int) DB::table('carts')->where('cookie_session_id', $cookie)->count());
        $this->assertSame(1, (int) DB::table('customers')->where('email', $shopper->email)->count());
    }
}
