<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CORRECTED SCAFFOLD TEST. One assertion set was wrong about this product's contract and
 * is corrected; the other is left RED because it documents a live defect.
 *
 * THE CORRECTION, and the evidence the old assertion was wrong. Stock Breeze's
 * test_new_users_can_register posted only name/email/password/password_confirmation and
 * expected authentication. This product's registration is STORE registration -- it
 * provisions a merchant tenant -- and its validator requires a store name:
 *
 *   app/Http/Controllers/Auth/RegisteredUserController.php, store():
 *       $request->validate([
 *           'name'       => ['required', 'string', 'max:255'],
 *           'email'      => ['required', 'string', 'email', 'max:255', 'unique:'.User::class],
 *           'password'   => ['required', 'confirmed', Rules\Password::defaults()],
 *           'store_name' => ['required', 'string', 'max:255'],
 *       ]+$validation);
 *
 * and the body then does Store::create([...]) and sets $user->current_store. So the stock
 * payload could never authenticate by any route: it is rejected by validation before any
 * user is created. MEASURED, posting the stock payload:
 *       status=302  auth=NO  errors=["The store name field is required."]
 * The old test therefore reported "not authenticated" -- a true statement about a request
 * this product is SUPPOSED to reject, dressed up as a registration failure. That is a
 * wrong assertion, not a product defect, and it is corrected rather than deleted.
 *
 * COVERAGE RISES RATHER THAN FALLS. The rejected-payload case is not thrown away: it is
 * now asserted deliberately, as the contract it actually is, by
 * test_registration_is_rejected_without_a_store_name. And the corrected happy path
 * asserts what the product really does -- user, store, tenant pointer and role -- which
 * the stock version never checked.
 *
 * WHAT USED TO BE RED HERE, AND WHY IT IS NOT ANY MORE (GAP-014, 2026-11-26).
 * test_new_users_can_register was this suite's LAST declared failure in
 * `.github/known-test-outcomes.json`, and the entry has been deleted because it passes --
 * which that gate requires rather than permits.
 *
 * IT WAS A FIXTURE GAP SITTING ON TOP OF A REAL DEFECT, and both halves are addressed.
 *
 *   THE FIXTURE GAP. `RefreshDatabase` runs migrations and NOT seeders, so `roles` holds 0
 *   rows and `Role::where('name', 'admin')->first()` is null in every test -- a state no
 *   seeded install is ever in. This case is the HAPPY PATH, so it now seeds the role the
 *   way `PermissionTableSeeder` creates it (one global row, `store_id` null) and asserts
 *   the provisioning it was always about. It does not weaken anything: the absent-role
 *   state it used to drive by accident is now driven DELIBERATELY, with far stronger
 *   assertions, by tests/Feature/Auth/RegistrationRoleProvisioningTest.
 *
 *   THE DEFECT. With the role absent, registration did not die -- it SUCCEEDED and
 *   committed a half-provisioned merchant. An earlier version of this docblock reported a
 *   `TypeError ... called in RegisteredUserController.php on line 249`; that was corrected
 *   once already (line 249 is a comment, and both real call sites carried an
 *   `if ($role_r)` guard with a Log::warning), and the measured outcome was
 *
 *       status=302, auth=YES, users +1, stores +1, store_wallets +1, role_user 0
 *
 *   i.e. a merchant with a real store, a live session, no role and no error. The role
 *   lookup has moved ABOVE the first write and its absence now refuses the whole
 *   registration, so neither call site tolerates a null and the half-provisioned row is
 *   never created.
 */
class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RED, and the finding is app/Helper/helper.php:145 -- not this route. get_file()
     * dereferences a null super-admin, so every page render in a fresh test database
     * 500s. Seven of the suite's thirteen failures are that one line. See
     * PasswordResetTest's class docblock for the measurement.
     */
    public function test_registration_screen_can_be_rendered(): void
    {
        $response = $this->get('/register');

        $response->assertStatus(200);
    }

    /**
     * CORRECTED to the product's real contract, and GREEN since GAP-014 closed the fixture
     * gap described in the class docblock. Asserts the whole provisioning outcome, because
     * "is the user authenticated" was never the interesting half of this test in a
     * product where registering creates a tenant.
     */
    public function test_new_users_can_register(): void
    {
        /*
         * THE FIXTURE THE SEEDER WOULD HAVE PROVIDED. `RefreshDatabase` runs migrations and not
         * seeders, so without this `roles` is empty and registration is REFUSED -- correctly, and
         * that refusal is asserted in its own right by RegistrationRoleProvisioningTest. This is
         * the happy path, so it establishes the production precondition instead of driving a
         * broken install by accident. Created exactly as PermissionTableSeeder creates it: one
         * global row with a null `store_id`.
         */
        Role::create(['name' => 'admin', 'created_by' => null]);

        $this->assertSame(1, Role::where('name', 'admin')->count(),
            'premise: the admin role was not seeded, so this case drives the absent-role refusal '
            . 'and not the registration it is named for.');

        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
            'store_name' => 'Test Store',
        ]);

        $this->assertAuthenticated();

        $user = User::where('email', 'test@example.com')->first();
        $this->assertNotNull($user, 'Registration did not create the user row.');

        $store = Store::where('created_by', $user->id)->first();
        $this->assertNotNull($store, 'Registration did not create the merchant store.');

        $this->assertSame(
            $store->id,
            $user->fresh()->current_store,
            'The new merchant was not pointed at the store registration just created, so '
            . 'getCurrentStore() would resolve the wrong tenant.'
        );

        $this->assertTrue(
            $user->fresh()->hasRole('admin'),
            'The merchant was created and logged in but never got the admin role, although the '
            . 'role was seeded above -- so the assignment itself has broken. This assertion '
            . 'carried this suite\'s last declared CI failure until GAP-014 (2026-11-26). Two '
            . 'things kept it red and both are gone: the fixture never seeded the role (fixed '
            . 'above), and when the role was absent registration SUCCEEDED anyway -- 302, '
            . 'authenticated, users +1, stores +1, store_wallets +1, role_user 0 -- because both '
            . 'addRole call sites tolerated the null. The lookup now happens before the first '
            . 'write and refuses the registration, which is asserted by '
            . 'tests/Feature/Auth/RegistrationRoleProvisioningTest.'
        );

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * REPLACEMENT COVERAGE for the assertion corrected above, and it holds today.
     *
     * This is the contract the stock test was accidentally exercising: a registration
     * with no store name must be REJECTED, and must not half-create a merchant. Asserting
     * it deliberately keeps the behaviour covered instead of losing it, and pins the
     * tenant-provisioning rule that makes store_name required in the first place.
     *
     * MEASURED: status=302, auth=NO, errors=["The store name field is required."]
     */
    public function test_registration_is_rejected_without_a_store_name(): void
    {
        $response = $this->post('/register', [
            'name' => 'Test User',
            'email' => 'nostore@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertSessionHasErrors('store_name');
        $this->assertGuest();

        $this->assertNull(
            User::where('email', 'nostore@example.com')->first(),
            'A registration rejected for a missing store name still created the user row. '
            . 'That would leave an account with no tenant, which getCurrentStore() cannot '
            . 'resolve.'
        );
        $this->assertSame(
            0,
            Store::where('name', 'Test Store')->count(),
            'A rejected registration created a store.'
        );
    }
}
