<?php

namespace Tests\Feature\Accounts;

use App\Models\Customer;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * SUSPENDING AN ACCOUNT REVOKED NOTHING: ITS TOKEN AND ITS SESSION BOTH KEPT WORKING.
 *
 * Phase 06 item 2A, and the measurement that produced it. Suspension was enforced at exactly TWO
 * places in the whole application, and both are login FORMS:
 *
 *   Auth\AuthenticatedSessionController:68    $user->is_active == 0
 *   Auth\AuthenticatedSessionController:72    $user->is_enable_login == 0
 *   Customer\Auth\CustomerLoginController:862 $customer->status == 1 to proceed
 *
 * NOWHERE ELSE. `CustomAuth` resolved the Sanctum token, checked only `expires_at` and that the
 * tokenable was non-null, and logged its owner in -- and CLAUDE.md records that Sanctum tokens in
 * this platform NEVER EXPIRE (there is no `config/sanctum.php`), so `expires_at` is null for every
 * token ever minted. So disabling an account stopped the next SIGN-IN and nothing else.
 *
 * DRIVEN THROUGH REAL AUTHENTICATION PATHS, which is the point: a real `createToken()` against a
 * real `custom.auth` route, and a real session against a real web route. A unit test of the
 * predicate would not have caught a middleware that never calls it.
 *
 * EVERY CASE HAS ITS CONTROL, in the same test. "Suspended gets 401" proves nothing on its own --
 * the route might answer 401 for an unrelated reason, or the fix might refuse everybody. Each
 * assertion is therefore a PAIR against the same route: suspended refused, active admitted.
 */
class SuspendedAccountCannotActTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A `custom.auth` route a `User` token reaches (`routes/api.php:207`). */
    private const ADMIN_ROUTE = '/api/admin/notification';

    private function tokenFor(mixed $principal): string
    {
        return $principal->createToken('auth_token')->plainTextToken;
    }

    private function callAdminRouteWith(string $token): \Illuminate\Testing\TestResponse
    {
        return $this->withHeader('Authorization', 'Bearer ' . $token)->getJson(self::ADMIN_ROUTE);
    }

    /**
     * Sign a customer into the `customers` guard while leaving `web` as the DEFAULT guard.
     *
     * The shape this project settled on (`PlatformUploaderDoorsTest:670`). The second line is the
     * load-bearing one: `actingAs($c, 'customers')` alone makes `customers` the default, and
     * CLAUDE.md records that as what hides defects in handlers that read the default guard.
     */
    private function asAShopper(Customer $customer): self
    {
        $this->actingAs($customer, 'customers');
        $this->app['auth']->shouldUse('web');

        return $this;
    }

    private function makeCustomer(array $attributes = []): Customer
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant);

        return Customer::create(array_merge([
            'name' => 'Shopper',
            'email' => 'shopper' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('secret-probe'),
            'store_id' => $store->id,
            'created_by' => $merchant->id,
        ], $attributes));
    }

    // ---------------------------------------------------------------------------------------------
    // the token half -- CustomAuth
    // ---------------------------------------------------------------------------------------------

    /**
     * THE DEFECT. A deactivated merchant's existing token must stop working.
     */
    #[Test]
    public function test_a_deactivated_merchants_token_is_refused(): void
    {
        $active = $this->makeMerchant();
        $suspended = $this->makeMerchant();

        $activeToken = $this->tokenFor($active);
        $suspendedToken = $this->tokenFor($suspended);

        // Through Eloquent, so the Cachable model's cache is flushed -- CLAUDE.md records that a raw
        // DB::table() write leaves route-model binding serving the pre-write row.
        $suspended->is_active = 0;
        $suspended->save();

        $this->assertNotSame(
            401,
            $this->callAdminRouteWith($activeToken)->status(),
            'premise: an ACTIVE merchant must not be refused on this route, or the suspended '
            . 'assertion below would be satisfied by something other than the suspension.'
        );

        $this->callAdminRouteWith($suspendedToken)->assertStatus(401);
    }

    /** The second flag, which the login form checks separately. */
    #[Test]
    public function test_a_login_disabled_merchants_token_is_refused(): void
    {
        $active = $this->makeMerchant();
        $suspended = $this->makeMerchant();

        $activeToken = $this->tokenFor($active);
        $suspendedToken = $this->tokenFor($suspended);

        $suspended->is_enable_login = 0;
        $suspended->save();

        $this->assertNotSame(401, $this->callAdminRouteWith($activeToken)->status(), 'premise');

        $this->callAdminRouteWith($suspendedToken)->assertStatus(401);
    }

    /**
     * A DISABLED CUSTOMER'S TOKEN TOO -- the same defect, a different flag and a different guard.
     *
     * `CustomAuth` logs a `Customer` tokenable into the `customers` guard, so this is the branch a
     * customer-token probe takes and it was equally unchecked.
     */
    #[Test]
    public function test_a_disabled_customers_token_is_refused(): void
    {
        $active = $this->makeCustomer();
        $suspended = $this->makeCustomer();

        $activeToken = $this->tokenFor($active);
        $suspendedToken = $this->tokenFor($suspended);

        $suspended->status = 0;
        $suspended->save();

        $this->assertNotSame(401, $this->callAdminRouteWith($activeToken)->status(), 'premise');

        $this->callAdminRouteWith($suspendedToken)->assertStatus(401);
    }

    /**
     * THE PLATFORM OPERATOR IS EXEMPT, AND MUST BE.
     *
     * The login form exempts `type = 'super admin'` from both flags. Per-request enforcement has to
     * exempt them too, and for a sharper reason than parity: an operator refused on EVERY REQUEST
     * cannot sign in to undo it. This is the assertion that stops the fix from being able to brick
     * the platform.
     */
    #[Test]
    public function test_the_platform_operators_token_is_not_refused_even_when_its_flags_are_off(): void
    {
        $operator = $this->makePlatformAdmin();
        $token = $this->tokenFor($operator);

        $operator->is_active = 0;
        $operator->is_enable_login = 0;
        $operator->save();

        $this->assertNotSame(
            401,
            $this->callAdminRouteWith($token)->status(),
            'The platform operator was locked out by its own suspension flags. An operator refused '
            . 'on every request cannot sign in to undo it.'
        );
    }

    /**
     * AND THE EXEMPTION SURVIVES A DIFFERENT LETTER CASE.
     *
     * A deliberate divergence from the login form, which compares `type != 'super admin'` raw. The
     * fail-safe direction is the right one here, and CLAUDE.md records a letter-case reconciliation
     * defect (P5-R9) as a real one in this codebase.
     */
    #[Test]
    public function test_the_operator_exemption_is_not_case_sensitive(): void
    {
        $operator = $this->makePlatformAdmin();
        $token = $this->tokenFor($operator);

        $operator->type = 'Super Admin';
        $operator->is_active = 0;
        $operator->save();

        $this->assertNotSame(
            401,
            $this->callAdminRouteWith($token)->status(),
            'A mis-cased operator type locked the platform operator out.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the session half -- SuspendedAccountGuard
    // ---------------------------------------------------------------------------------------------

    /**
     * A SUSPENDED MERCHANT'S EXISTING SESSION MUST END.
     *
     * ASSERTED ON THE REDIRECT TARGET, NOT THE STATUS CODE, and that is the second version of this
     * test. The first asserted `302` for the suspended case and `not 302` for the active one -- and
     * the CONTROL failed before any suspension: an ACTIVE merchant fixture already gets a 302 from
     * `/dashboard` for its own reasons (the operator fixture does not, because `type = 'super admin'`
     * takes a different branch). Probed rather than adjusted, per this project's rule, and the answer
     * is that the status code does not discriminate on this route while the DESTINATION does: a
     * principal the suspension guard logged out is unauthenticated when `Authenticate` runs, and
     * `Authenticate` sends an unauthenticated visitor to the login route specifically.
     *
     * That is also the assertion that proves the ORDERING is right, which cost three probes to get
     * there: with the guard running after `Authenticate` (the shipped middleware-group position), the
     * suspended request reached the controller with `auth()->user()` null and answered HTTP 500 --
     * worse than the defect. `bootstrap/app.php` records why the priority anchor has to be the
     * interface rather than the concrete class.
     */
    #[Test]
    public function test_a_deactivated_merchants_existing_session_is_sent_to_the_login_route(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->is_active = 0;
        $merchant->save();

        $this->actingAs($merchant->fresh());
        $response = $this->get('/dashboard');

        $this->assertTrue($response->isRedirect(), 'a suspended principal must not be served the page');
        $this->assertStringEndsWith(
            '/login',
            (string) $response->headers->get('Location'),
            'A deactivated merchant kept an authenticated session. Suspension was enforced only at '
            . 'the login form, so flipping the flag did nothing to a principal already signed in.'
        );
    }

    /** The same for the second flag. */
    #[Test]
    public function test_a_login_disabled_merchants_existing_session_is_sent_to_the_login_route(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $merchant->is_enable_login = 0;
        $merchant->save();

        $this->actingAs($merchant->fresh());
        $response = $this->get('/dashboard');

        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    /**
     * THE CONTROL. An active merchant must NOT be sent to the login route -- otherwise the guard
     * signs every merchant out of the panel, which is the regression that matters most here.
     *
     * THE FIXTURE IS A PROVISIONED TENANT, AND THAT IS THE THIRD VERSION OF THIS TEST. The note on
     * the deactivated case above records the second: the status code stopped discriminating, so the
     * assertion moved to the DESTINATION. GAP-013 then took the destination away too. It added a
     * null-store guard at `HomeController::index()` (`:689-695`) that signs out a merchant or staff
     * member whose `current_store` resolves to nothing -- correct in itself, and it replaced an
     * HTTP 500 -- but `makeMerchant()` creates no store, so after GAP-013 a storeless ACTIVE
     * merchant is sent to `/login` by the STORE guard and this control went red against a
     * suspension guard that was behaving correctly.
     *
     * IT ALSO MEANS ALL THREE SESSION CASES HAD STOPPED TESTING THE SUSPENSION GUARD. The two
     * suspended cases above assert a `/login` redirect, and a storeless merchant gets one whether
     * or not the guard exists -- so they would have passed with `SuspendedAccountGuard` deleted
     * outright. And the control only ever passed because the pre-GAP-013 dashboard answered 500 for
     * this fixture: a 500 carries no `Location` header, so `(string) null` is `''` and
     * `assertStringNotContainsString('/login', '')` was satisfied by the absence of a response
     * rather than by the guard admitting anybody.
     *
     * `makeTenant()` fixes all three at once and makes them STRICTER, not looser: measured, a
     * provisioned active merchant gets HTTP 200 from `/dashboard` with no `Location` at all and
     * `auth('web')->check()` still true, so a `/login` redirect can now only come from this guard.
     * The mutation evidence in the class docblock is what holds that.
     */
    #[Test]
    public function test_an_active_merchants_session_is_not_sent_to_the_login_route(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $this->actingAs($merchant);
        $response = $this->get('/dashboard');

        $this->assertStringNotContainsString(
            '/login',
            (string) $response->headers->get('Location'),
            'The guard logged out an account that is not suspended.'
        );
    }

    /**
     * THE BEHAVIOUR THE FIXTURE CHANGE ABOVE MOVED OUT OF THE WAY, PINNED WHERE IT BELONGS.
     *
     * A storeless merchant IS sent to the login route, and by a different mechanism: GAP-013's
     * null-store guard, not suspension. This is asserted here so the fixture change above subtracts
     * nothing from the suite, and so that whoever changes `HomeController::index()`'s storeless arm
     * is told by a failing test which two behaviours share this destination.
     *
     * NOT A CLAIM THAT THE LOCKOUT IS RIGHT. A merchant whose store was deleted cannot reach the
     * panel at all -- they sign in, are bounced, and sign in again. Registration always creates a
     * store, so the reachable population is narrow; it is recorded as GAP-264 rather than changed
     * here, because what a storeless merchant should see is a product decision and not engineering's
     * to take.
     */
    #[Test]
    public function test_a_storeless_merchant_is_sent_to_the_login_route_by_the_null_store_guard(): void
    {
        $merchant = $this->makeMerchant();

        $this->assertNull(
            $merchant->current_store,
            'premise: this fixture must have no resolvable store, or it is not the case being pinned.'
        );
        $this->assertNull(
            \App\Support\Accounts\AccountSuspension::reasonFor($merchant),
            'premise: the merchant must NOT be suspended, or this would pin the suspension guard '
            . 'and the two mechanisms would be indistinguishable again.'
        );

        $this->actingAs($merchant);
        $response = $this->get('/dashboard');

        $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'));
    }

    /**
     * A SUSPENDED CUSTOMER'S STOREFRONT SESSION IS ENDED TOO.
     *
     * THIS TEST EXISTS BECAUSE A MUTATION PROVED IT WAS MISSING. Narrowing the guard's
     * `SESSION_GUARDS` from `['web', 'customers', 'deliveryboy']` to `['web']` SURVIVED the whole
     * suite: the customer branch was covered only at the TOKEN seam, so the session seam had nothing
     * pinning it and a shopper whose merchant disabled them would have kept browsing signed in.
     *
     * ASSERTED ON THE GUARD'S STATE, NOT ON A ROUTE, deliberately. The storefront is `/{slug}/...`
     * and standing up a credible store fixture would make this test about the storefront rather than
     * about the suspension; `/login` is a plain web-group route, so the middleware runs, and
     * `auth('customers')->check()` afterwards is the thing that actually matters -- the shopper is
     * either still signed in or they are not.
     *
     * `asAShopper()` is this project's helper shape (`PlatformUploaderDoorsTest:670`): it signs the
     * customer into the `customers` guard and then restores `web` as the DEFAULT, because CLAUDE.md
     * records that leaving `customers` as the default is what hides this whole class of defect.
     */
    #[Test]
    public function test_a_disabled_customers_storefront_session_is_ended(): void
    {
        $suspended = $this->makeCustomer();
        $suspended->status = 0;
        $suspended->save();

        $this->asAShopper($suspended->fresh());
        $this->get('/login');

        $this->assertFalse(
            auth('customers')->check(),
            'A disabled customer kept a signed-in storefront session. The suspension guard must '
            . 'cover the customers guard, not only web.'
        );
    }

    /** The control: an active shopper stays signed in. */
    #[Test]
    public function test_an_active_customers_storefront_session_is_untouched(): void
    {
        $active = $this->makeCustomer();

        $this->asAShopper($active->fresh());
        $this->get('/login');

        $this->assertTrue(
            auth('customers')->check(),
            'The guard signed out a customer who is not disabled.'
        );
    }

    /** And the operator, for the same reason as its token: it must never be locked out. */
    #[Test]
    public function test_the_platform_operators_session_is_not_sent_to_the_login_route(): void
    {
        $operator = $this->makePlatformAdmin();

        $operator->is_active = 0;
        $operator->is_enable_login = 0;
        $operator->save();

        $this->actingAs($operator->fresh());
        $response = $this->get('/dashboard');

        $this->assertStringNotContainsString(
            '/login',
            (string) $response->headers->get('Location'),
            'The platform operator was signed out of the panel by its own suspension flags.'
        );
    }
}
