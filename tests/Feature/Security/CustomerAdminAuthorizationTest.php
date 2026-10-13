<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * NEW-customer-status-unauthorized, NEW-customer-timeline-unauthorized,
 * NEW-customer-show-cross-tenant.
 *
 * Three CustomerController actions sat behind ['web','auth','xss','setlocate',
 * 'verified'] -- measured from the real router, not inferred from brace
 * positions in routes/web.php -- with no authorization beyond authentication:
 *
 *   customerStatus    no permission, no ownership, no null guard. Any logged-in
 *                     web user, including a staff member with no permissions at
 *                     all, could DISABLE any customer account in any store and
 *                     lock a shopper out of the store they bought from.
 *   customerTimeline  no permission, no ownership. Any logged-in user could
 *                     read any customer's activity log, total spend and profile
 *                     by walking the id in the URL.
 *   show              checked 'Show Customer' but NOT ownership, so a
 *                     permission every merchant holds read another store's
 *                     customer and their orders.
 *
 * destroy() in the same controller already had the right predicate. These three
 * now use it, so the controller has ONE shape for customer ownership rather
 * than four.
 */
class CustomerAdminAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn(Store $store, array $attributes = []): Customer
    {
        return Customer::forceCreate(array_merge([
            'first_name' => 'Cust',
            'last_name' => 'Omer',
            'email' => 'cust' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'store_id' => $store->id,
            'status' => 1,
            'billable_id' => $store->created_by,
            'billable_type' => \App\Models\User::class,
        ], $attributes));
    }

    public function test_a_merchant_cannot_disable_another_stores_customer(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        // Merchant A genuinely holds the permission, so this isolates the
        // OWNERSHIP defect from the permission defect.
        $this->grantPermission($merchantA, 'Manage Customer');

        $victim = $this->makeCustomerIn($storeB, ['status' => 1]);

        $this->actingAs($merchantA)
            ->get('/customer-status?customer_id=' . $victim->id . '&status=0');

        $this->assertSame(
            1,
            (int) $victim->fresh()->status,
            'Another store\'s customer was disabled, locking that shopper out of '
            . 'the store they bought from.'
        );
    }

    /**
     * THE CONVERSE: the merchant's own customer must still be toggleable, or
     * the customer list screen stops working.
     */
    public function test_a_merchant_can_still_disable_their_own_customer(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Customer');

        $own = $this->makeCustomerIn($storeA, ['status' => 1]);

        $this->actingAs($merchantA)
            ->get('/customer-status?customer_id=' . $own->id . '&status=0')
            ->assertStatus(200);

        $this->assertSame(
            0,
            (int) $own->fresh()->status,
            'The merchant can no longer disable their own customer. The predicate '
            . 'is too tight -- that is a defect, not a fix.'
        );
    }

    /**
     * A user with NO permission must be refused outright. Authentication was
     * the only control before, so this is the half that was entirely missing.
     */
    public function test_a_user_without_the_permission_cannot_disable_any_customer(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $staff = $this->makeStaff($merchantA);

        $own = $this->makeCustomerIn($storeA, ['status' => 1]);

        $this->actingAs($staff)
            ->get('/customer-status?customer_id=' . $own->id . '&status=0');

        $this->assertSame(
            1,
            (int) $own->fresh()->status,
            'A staff member holding no permissions disabled a customer.'
        );
    }

    /**
     * The null guard: an unmatched id used to dereference null.
     */
    public function test_customer_status_does_not_500_on_an_unknown_id(): void
    {
        [$merchantA] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Manage Customer');

        $response = $this->actingAs($merchantA)
            ->get('/customer-status?customer_id=999999&status=0');

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'customerStatus still dereferences Customer::find() with no null check.'
        );
    }

    public function test_a_merchant_cannot_read_another_stores_customer_timeline(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Customer');

        $victim = $this->makeCustomerIn($storeB, ['first_name' => 'Secretname']);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $victim->id);

        $response->assertRedirect();
        $this->assertStringNotContainsString(
            'Secretname',
            $response->getContent(),
            'Another store\'s customer profile leaked through the timeline view.'
        );
    }

    public function test_a_merchant_can_still_read_their_own_customers_timeline(): void
    {
        // A platform operator must EXIST for any view that renders
        // layouts/app.blade.php to survive. Not a workaround for this change --
        // it is the get_file() defect the brief names, still live and still not
        // in this agent's files: app/Helper/helper.php:143-145 does
        //   $admin = User::where('type','super admin')->first();
        //   $settings = Setting::where('store_id', $admin->current_store)...
        // with no null check, so with no operator row the layout raises
        // "Attempt to read property current_store on null". helper.php belongs
        // to another agent and is reported as a cross-agent dependency; every
        // real install has an operator, so the fixture now matches production
        // rather than asserting around the defect.
        $this->makePlatformAdmin();

        [$merchantA, $storeA] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Customer');

        $own = $this->makeCustomerIn($storeA);

        $response = $this->actingAs($merchantA)->get('/customer-timeline/' . $own->id);

        $this->assertNotSame(
            500,
            $response->getStatusCode(),
            'The merchant\'s own customer timeline now errors.'
        );
        $response->assertOk();
    }

    public function test_a_merchant_cannot_view_another_stores_customer(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Customer');

        $victim = $this->makeCustomerIn($storeB);

        $this->actingAs($merchantA)
            ->get('/customer/' . $victim->id)
            ->assertRedirect();
    }

    public function test_a_merchant_can_still_view_their_own_customer(): void
    {
        // A platform operator must EXIST for any view that renders
        // layouts/app.blade.php to survive. Not a workaround for this change --
        // it is the get_file() defect the brief names, still live and still not
        // in this agent's files: app/Helper/helper.php:143-145 does
        //   $admin = User::where('type','super admin')->first();
        //   $settings = Setting::where('store_id', $admin->current_store)...
        // with no null check, so with no operator row the layout raises
        // "Attempt to read property current_store on null". helper.php belongs
        // to another agent and is reported as a cross-agent dependency; every
        // real install has an operator, so the fixture now matches production
        // rather than asserting around the defect.
        $this->makePlatformAdmin();

        [$merchantA, $storeA] = $this->makeTenant();

        $this->grantPermission($merchantA, 'Show Customer');

        $own = $this->makeCustomerIn($storeA);

        $this->actingAs($merchantA)
            ->get('/customer/' . $own->id)
            ->assertOk();
    }

    /**
     * And the platform operator must not be locked out of any of the three,
     * which is why each predicate carries the super-admin exemption that
     * destroy(), OrderPolicy::allow() and the finance gate already use.
     *
     * satisfyUpdaterGate() in SecurityTestCase is what makes a super-admin web
     * route test exercise the control at all -- without it XSS::handle
     * redirects to the updater and a green assertion would prove nothing.
     */
    public function test_the_platform_operator_is_not_locked_out(): void
    {
        $operator = $this->makePlatformAdmin();
        [, $storeB] = $this->makeTenant();

        $victim = $this->makeCustomerIn($storeB, ['status' => 1]);

        $response = $this->actingAs($operator)
            ->get('/customer-status?customer_id=' . $victim->id . '&status=0');

        $this->assertFalse(
            $response->isRedirect() && str_contains((string) $response->headers->get('Location'), 'update'),
            'The request was swallowed by the XSS updater gate, so this test would '
            . 'pass without exercising the control. satisfyUpdaterGate() should have '
            . 'prevented that.'
        );

        $this->assertSame(
            0,
            (int) $victim->fresh()->status,
            'The platform operator was locked out of customer administration.'
        );
    }
}
