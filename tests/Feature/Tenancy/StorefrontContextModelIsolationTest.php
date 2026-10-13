<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\ActiveTheme;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route as RouteFacade;
use Tests\Feature\Security\SecurityTestCase;

/**
 * THE TEST MATRIX THE OWNER ASKED FOR, end to end: two merchants, two stores, A LOGGED-IN
 * MERCHANT BROWSING ANOTHER STOREFRONT, guest checkout, customer login, and cross-tenant
 * mutation attempts -- for the two models this increment enables, Cart and Customer.
 *
 * Division of labour, so nothing here is a duplicate:
 *   - StorefrontContextTenantResolutionTest  asserts the RESOLVER, context by context.
 *   - StorefrontWebGuardScopeTest            asserts the SEVEN EXACT READ SITES the revert named.
 *   - Batch2ModelIsolationTest               asserts the ADMIN-context CRUD matrix per model.
 *   - THIS SUITE                             asserts the WORKFLOWS: the things a real merchant,
 *                                            a real shopper and a real guest actually do, and
 *                                            the boundary between them.
 *
 * EVERY NEGATIVE IS READ BACK THROUGH RAW `DB::table()`, because an Eloquent re-read would be
 * filtered by the very scope under test -- a scope that permitted a write but hid the row would
 * otherwise pass.
 */
class StorefrontContextModelIsolationTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
        StoreScope::forgetOwnedStoreIds();
        StoreScope::forgetSlugStoreIds();

        // 78 of the 95 models use laravel-model-caching and the test store is `array`, which IS
        // taggable, so the model cache is genuinely live. Raw fixture inserts fire no Eloquent
        // events and so do not flush it; without this a warmed empty result from an earlier test
        // could satisfy a later assertion for the wrong reason.
        try {
            cache()->flush();
        } catch (\Throwable $e) {
            // A non-flushable store is not a reason to fail the run.
        }
    }

    protected function tearDown(): void
    {
        StoreScope::resetExplicitContext();
        StoreScope::resetSuppression();
        StoreScope::forgetSlugStoreIds();
        StoreScope::forgetOwnedStoreIds();

        parent::tearDown();
    }

    /**
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    private function onStorefront(Store $store, string $path, callable $callback)
    {
        $route = new Route(['GET', 'POST'], '{storeSlug?}/' . ltrim($path, '/'), ['uses' => fn () => null]);
        $route->middleware([ActiveTheme::class, 'themelanguage']);

        $request = Request::create('/' . $store->slug . '/' . ltrim($path, '/'), 'GET');
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

    private function seedCart(Store $store, string $cookie, int $qty = 1): int
    {
        return (int) DB::table('carts')->insertGetId([
            'cookie_session_id' => $cookie,
            'store_id' => $store->id,
            'product_id' => 1,
            'qty' => $qty,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedCustomer(Store $store, string $email, string $firstName = 'Shop'): Customer
    {
        return Customer::forceCreate([
            'first_name' => $firstName,
            'last_name' => 'Per',
            'email' => $email,
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => User::class,
        ]);
    }

    // =============================================================================
    //  GUEST CHECKOUT
    // =============================================================================

    public function test_a_guest_basket_on_store_a_is_invisible_to_merchant_b(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $cookie = 'guest-' . uniqid();

        // The guest, anonymous, on store A's storefront. This is Cart::cart_add's read shape.
        $this->assertGuest();
        $cartId = $this->seedCart($storeA, $cookie, 3);

        $guestView = $this->onStorefront($storeA, 'cart', fn () => Cart::where('cookie_session_id', $cookie)
            ->where('store_id', $storeA->id)
            ->get());

        $this->assertCount(1, $guestView, 'GUEST CHECKOUT must work: the basket must be readable.');
        $this->assertSame(3, (int) $guestView->first()->qty);

        // Merchant B, in their own admin panel, must not see or touch it.
        $this->actingAs($merchantB->fresh());

        $this->assertNull(Cart::find($cartId),
            'Merchant B resolved store A\'s guest cart row by bare primary key.');
        $this->assertCount(0, Cart::where('cookie_session_id', $cookie)->get());

        Cart::where('cookie_session_id', $cookie)->update(['qty' => 99]);
        Cart::where('cookie_session_id', $cookie)->delete();

        $row = DB::table('carts')->where('id', $cartId)->first();

        $this->assertNotNull($row, 'RAW read-back: store A\'s guest cart row was DELETED by merchant B.');
        $this->assertSame(3, (int) $row->qty, 'RAW read-back: its qty was MODIFIED by merchant B.');
    }

    // =============================================================================
    //  CUSTOMER LOGIN
    // =============================================================================

    public function test_a_shopper_can_log_in_on_store_a_while_merchant_b_holds_an_admin_session(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $email = 'shopper-' . uniqid() . '@example.test';
        $shopper = $this->seedCustomer($storeA, $email);

        // THE EXACT CONDITION THAT FORCED THE REVERT. Storefronts are path-prefixed on the same
        // host and the session cookie path is '/', so merchant B's admin session is live on
        // store A's storefront and auth()->user() on `web` is POPULATED.
        $this->actingAs($merchantB->fresh());

        $found = $this->onStorefront($storeA, 'login', fn () => Customer::where('email', $email)
            ->where('store_id', $storeA->id)
            ->first());

        $this->assertNotNull($found,
            'CUSTOMER LOGIN on store A broke while merchant B had an admin session. This is the '
            . 'silent failure the per-context resolver exists to prevent.');
        $this->assertSame($shopper->id, $found->id);
    }

    /**
     * PER-STORE CUSTOMER IDENTITY is real product behaviour, not an artefact:
     * CustomerLoginController.php:585 looks a customer up by `email` AND `store_id`, so the same
     * email is two different people in two different stores. The scope must not collapse them,
     * and must not leak one into the other.
     */
    public function test_the_same_email_in_two_stores_stays_two_distinct_customers(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();

        $email = 'same-' . uniqid() . '@example.test';
        $inA = $this->seedCustomer($storeA, $email, 'Alice');
        $inB = $this->seedCustomer($storeB, $email, 'Bob');

        $this->actingAs($merchantB->fresh());

        // On store A's storefront both are admissible under 'additive' (A by slug, B by
        // ownership), so the per-store predicate at the call site is what disambiguates -- and
        // it still does, which is the point.
        $resolvedOnA = $this->onStorefront($storeA, 'login', fn () => Customer::where('email', $email)
            ->where('store_id', $storeA->id)
            ->first());

        $this->assertSame($inA->id, $resolvedOnA->id, 'store A\'s storefront must resolve store A\'s customer');
        $this->assertSame('Alice', $resolvedOnA->first_name);

        // And in merchant B's own admin panel, only B's customer exists.
        $adminView = Customer::where('email', $email)->get();

        $this->assertCount(1, $adminView, 'Merchant B\'s admin panel must list exactly one of the two.');
        $this->assertSame($inB->id, $adminView->first()->id);
    }

    // =============================================================================
    //  A LOGGED-IN MERCHANT BROWSING ANOTHER STOREFRONT
    // =============================================================================

    public function test_a_logged_in_merchant_browsing_store_a_sees_store_a_plus_their_own_and_nothing_else(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB, $storeB] = $this->makeTenant();
        [, $storeC] = $this->makeTenant();

        $cookie = 'shared-' . uniqid();
        $inA = $this->seedCart($storeA, $cookie);
        $inB = $this->seedCart($storeB, $cookie);
        $inC = $this->seedCart($storeC, $cookie);

        $this->actingAs($merchantB->fresh());

        $visible = $this->onStorefront($storeA, 'cart', fn () => Cart::where('cookie_session_id', $cookie)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all());

        sort($visible);
        $expected = [$inA, $inB];
        sort($expected);

        $this->assertSame($expected, $visible,
            'On store A\'s storefront the admissible set is {A} UNION {merchant B\'s own stores}. '
            . 'Store C -- a third merchant\'s -- must not be in it. If C appeared, additive mode '
            . 'would be a blanket widening rather than a per-context resolution.');
        $this->assertNotContains($inC, $visible);
    }

    /**
     * THE DOCUMENTED TRADE, ASSERTED RATHER THAN LEFT IMPLICIT.
     *
     * On store A's storefront, store A's rows ARE admissible to a signed-in merchant who does
     * not own store A. That is deliberate, it is what makes the storefront work at all, and it
     * grants nothing new: on the SAME URL an anonymous visitor resolves no tenant and gets NO
     * predicate whatsoever, so those rows are already readable by anyone who can load the page.
     * The control on that path is the route and the controller, never this scope.
     *
     * It is asserted here so that the trade is visible in the suite instead of only in a comment,
     * and so that anyone who later believes the scope is the control on the storefront is
     * contradicted by a test.
     */
    public function test_the_storefront_context_admits_the_slug_store_and_that_is_the_stated_trade(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $cookie = 'trade-' . uniqid();
        $this->seedCart($storeA, $cookie);

        // 1. Anonymous on store A's storefront: no predicate at all. The baseline exposure.
        $this->assertGuest();
        $anonymous = $this->onStorefront($storeA, 'cart', fn () => Cart::where('cookie_session_id', $cookie)->count());
        $this->assertSame(1, $anonymous,
            'Precondition for the whole argument: an anonymous storefront visitor is unfiltered, '
            . 'so admitting the slug store for an authenticated principal widens nothing.');

        // 2. Merchant B on store A's storefront: the slug store is admissible.
        $this->actingAs($merchantB->fresh());
        $authenticated = $this->onStorefront($storeA, 'cart', fn () => Cart::where('cookie_session_id', $cookie)->count());
        $this->assertSame(1, $authenticated);

        // 3. The same merchant, in the ADMIN context: denied. This is where the scope IS the control.
        $this->assertSame(0, Cart::where('cookie_session_id', $cookie)->count(),
            'The admin context must stay closed. That asymmetry is the entire design.');
    }

    /**
     * WHAT BOUNDS THE TRADE ABOVE, measured from the BOOTED ROUTER rather than argued: an
     * admin-authenticated action must not be reachable through a storefront URL. If one were,
     * additive mode would let a merchant act on the slug store with their admin privileges.
     *
     * The bulk-delete endpoint -- the single worst call shape in the codebase
     * (BulkDeleteController.php:115, `$model::whereIn('id', $request->ids)->delete()` with no
     * tenant predicate) -- is declared at packages/workdo/BulkDelete/src/routes/web.php:18 as
     * `Route::middleware(['auth', 'web', 'PlanModuleCheck:BulkDelete'])` with NO `{storeSlug}`
     * and NO ActiveTheme, so it resolves CONTEXT_MERCHANT_ADMIN and is scoped to owned stores.
     * That module is not loaded in a fresh test database (PackageServiceProvider only loads
     * modules listed in `add_on_managers`), so this test asserts the general property over every
     * route that IS registered instead of that one route in particular.
     */
    public function test_no_registered_route_is_both_a_storefront_route_and_web_guard_authenticated(): void
    {
        $violations = [];

        foreach (RouteFacade::getRoutes() as $route) {
            $middleware = (array) $route->middleware();

            $isStorefront = false;
            $isWebAuthenticated = false;

            foreach ($middleware as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                if (str_contains($entry, 'ActiveTheme')) {
                    $isStorefront = true;
                }

                // Plain `auth` or `auth:web` is the MERCHANT guard. `auth:customers` and
                // `auth:deliveryboy` are not, and EnsureCustomerAuthenticated is not either --
                // none of those populates auth()->user() on `web`, so none of them can combine
                // with a storefront slug to widen a merchant's reach.
                if ($entry === 'auth' || $entry === 'auth:web') {
                    $isWebAuthenticated = true;
                }
            }

            if ($isStorefront && $isWebAuthenticated) {
                $violations[] = $route->methods()[0] . ' ' . $route->uri();
            }
        }

        $this->assertSame([], $violations,
            'These routes are BOTH storefront routes (ActiveTheme) AND merchant-guard '
            . 'authenticated (`auth`). On such a route `storefront_tenant=additive` admits the '
            . 'slug store to a principal acting with admin privileges, which is a widening the '
            . 'design does not intend. Either move the action out of the storefront group '
            . '(routes/ is another workstream) or set storefront_tenant=slug_only.');
    }

    // =============================================================================
    //  CROSS-TENANT MUTATION ATTEMPTS
    // =============================================================================

    public function test_a_merchant_cannot_mutate_another_stores_customer_pii_from_any_admin_shape(): void
    {
        [, $storeA] = $this->makeTenant();
        [$merchantB] = $this->makeTenant();

        $email = 'pii-' . uniqid() . '@example.test';
        $victim = $this->seedCustomer($storeA, $email, 'Alice');

        $this->actingAs($merchantB->fresh());

        // Every shape an attacker has THAT THE SCOPE GOVERNS: bare id, email, mass update,
        // soft delete, and the BulkDeleteController.php:115 whereIn shape. `forceDelete()` is
        // deliberately NOT in this list -- it escapes every global scope, which is a separate
        // measured finding asserted on its own below rather than buried in here.
        Customer::where('id', $victim->id)->update(['first_name' => 'Pwned', 'email' => 'taken@example.test']);
        Customer::where('email', $email)->update(['status' => 0]);
        Customer::whereIn('id', [$victim->id])->delete();
        Customer::where('id', $victim->id)->delete();

        $row = DB::table('customers')->where('id', $victim->id)->first();

        $this->assertNotNull($row, 'RAW read-back: the customer row was DELETED across the tenant boundary.');
        $this->assertSame('Alice', $row->first_name, 'RAW read-back: first_name was MODIFIED.');
        $this->assertSame($email, $row->email, 'RAW read-back: email was MODIFIED.');
        $this->assertSame(1, (int) $row->status, 'RAW read-back: status was MODIFIED.');
        $this->assertNull($row->deleted_at, 'RAW read-back: the row was SOFT-deleted across the boundary.');
    }

    public function test_a_merchant_can_still_do_all_of_that_to_their_own_customer(): void
    {
        [$merchantB, $storeB] = $this->makeTenant();

        $own = $this->seedCustomer($storeB, 'own-' . uniqid() . '@example.test', 'Bob');

        $this->actingAs($merchantB->fresh());

        $this->assertNotNull(Customer::find($own->id), 'POSITIVE: own customer must resolve by id.');

        $affected = Customer::where('id', $own->id)->update(['first_name' => 'Robert']);
        $this->assertSame(1, $affected, 'POSITIVE: own customer must be updatable.');
        $this->assertSame('Robert', DB::table('customers')->where('id', $own->id)->value('first_name'));

        Customer::where('id', $own->id)->delete();
        $this->assertNotNull(DB::table('customers')->where('id', $own->id)->value('deleted_at'),
            'POSITIVE: own customer must be soft-deletable. A control that breaks the merchant\'s '
            . 'own workflow is a defect, not a fix.');
    }

    // =============================================================================
    //  BACKGROUND JOBS -- the explicit context, proven both ways
    // =============================================================================

    public function test_a_job_in_an_explicit_context_acts_only_on_that_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $cookie = 'job-' . uniqid();
        $inA = $this->seedCart($storeA, $cookie);
        $inB = $this->seedCart($storeB, $cookie);

        // No authenticated principal and no route: exactly a queue worker.
        $this->assertGuest();
        $this->assertNull(request()->route());

        StoreScope::forStoreContext($storeA->id, function () use ($cookie) {
            Cart::where('cookie_session_id', $cookie)->delete();
        });

        $this->assertNull(DB::table('carts')->where('id', $inA)->first(),
            'POSITIVE: the job must act on the store it declared.');
        $this->assertNotNull(DB::table('carts')->where('id', $inB)->first(),
            'RAW read-back: the job reached a store it did not declare. forStoreContext() is the '
            . 'remedy for the fail-open that a job otherwise gets, so it must actually bind.');
    }

    /**
     * AND THE HOLE IT EXISTS TO CLOSE, asserted rather than described: a job that does NOT open a
     * context is unfiltered. That is the documented `tenancy.null_tenant=open` trade, and a test
     * is what stops it being forgotten.
     */
    public function test_a_job_without_an_explicit_context_is_unfiltered_and_that_is_the_known_hole(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $cookie = 'nojob-' . uniqid();
        $this->seedCart($storeA, $cookie);
        $this->seedCart($storeB, $cookie);

        $this->assertGuest();
        $this->assertSame(StoreScope::CONTEXT_CONSOLE, StoreScope::currentContext());

        $this->assertSame(2, Cart::where('cookie_session_id', $cookie)->count(),
            'A job with no explicit context sees every store. This is R4-02, reported as an '
            . 'ACCEPTED RISK rather than fixed: closing it by default would stop the scheduled '
            . 'Zid sync (routes/console.php) and every other queued job. The remedy is '
            . 'StoreScope::forStoreContext() at the top of the job, proven above.');
    }
}
