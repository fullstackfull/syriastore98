<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Setting;
use App\Models\Store;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\StripsSourceComments;
use Tests\TestCase;

/**
 * GAP-014 / `P01-DEFECT-REG` -- A HALF-PROVISIONED MERCHANT MUST NOT SURVIVE.
 * ==========================================================================
 *
 * The gap: "Needs a null guard plus a decision about what to do when the role is absent -- the
 * half-provisioned row should not survive." The expected behaviour its traceability row states is
 * "A merchant who registers is provisioned with a role, or registration fails loudly."
 *
 * WHAT WAS THERE. `RegisteredUserController::store()` has two provisioning branches, one per
 * `email_verification` setting, and each ended with
 *
 *     $role_r = Role::where('name', 'admin')->first();
 *     if ($role_r) { $user->addRole($role_r); }
 *     else         { Log::warning('Registration completed without a role: ...'); }
 *
 * at :179 and :310. The guard stopped the TypeError that an unguarded `addRole(null)` raises --
 * which was the right first move, because that TypeError fired AFTER the user row, the store row,
 * the store wallet, `users.current_store`, `Auth::login()`, `defaultSetting()` and
 * `pageDefaultData()`, none of them in a transaction. But it only moved the damage: registration
 * then REPORTED SUCCESS and committed a merchant with a real store, a live session and no role at
 * all. MEASURED at HEAD, on a fresh test database where `roles` holds 0 rows:
 *
 *     POST /register  ->  302, authenticated, users +1, stores +1, store_wallets +1, roles_user 0
 *
 * THE DECISION, AND WHY IT IS THE ONE THE REGISTER ALREADY NAMES. Three answers were available.
 * Creating the missing role is refused by the controller's own comment and would be a
 * privilege-granting choice rather than an engineering one: the `admin` role is a single SHARED
 * global row with a null `store_id` that every self-registered merchant is attached to, so
 * inventing one here makes the known hazard worse. Rolling the provisioning back after the fact
 * needs a transaction around `Auth::login()` and a verification e-mail. CHECKING FIRST needs
 * neither: the lookup moves ABOVE the first write, so when the role is absent nothing is created,
 * nobody is signed in, no mail is sent, and the visitor is told. The half-provisioned row cannot
 * survive because it is never born.
 *
 * ONE GUARD, ABOVE THE BRANCH SPLIT, NOT TWO COPIES. Both `addRole` sites now receive a role the
 * method has already proven exists, so neither tolerates null any more -- which is what the gap
 * asks for -- and there is no second copy of the condition to drift. `ProductDataTable` records
 * what this codebase's duplicated predicates cost.
 *
 * LATENT IN PRODUCTION, LIVE IN TEST, AND STILL WORTH FIXING. `PermissionTableSeeder` creates the
 * role, so a seeded install never takes this path; `RefreshDatabase` runs migrations and not
 * seeders, which is why the suite has carried a declared failure for it. Reachable in production
 * on any install where the seeder has not run or the role was renamed.
 *
 * WHAT IS NOT DECIDED HERE, and is reported rather than taken: giving each store its OWN admin
 * role. The controller records it as an owner action and a `role_user` + `permission_role` data
 * migration against the live database.
 */
class RegistrationRoleProvisioningTest extends TestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const PAYLOAD = [
        'name' => 'Role Guard Merchant',
        'email' => 'roleguard@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'store_name' => 'Role Guard Store',
    ];

    /**
     * THE PREMISE OF EVERY REFUSAL CASE: `roles` really is empty, so the guard is being driven and
     * not stepped over. Asserted rather than assumed -- `Role` is one of the sixteen models in
     * `app/Models/` that is NOT `Cachable`, so this read is the table.
     */
    private function assertNoAdminRoleExists(): void
    {
        $this->assertSame(
            0,
            Role::where('name', 'admin')->count(),
            'premise: an `admin` role exists, so the absent-role path is not being driven and '
            . 'every assertion below would be vacuous.'
        );
    }

    private function assertNothingWasProvisioned(): void
    {
        $this->assertGuest();

        $this->assertNull(
            User::where('email', self::PAYLOAD['email'])->first(),
            'A registration refused for the missing `admin` role still created the user row. '
            . 'That is the half-provisioned merchant the gap says must not survive.'
        );

        $this->assertSame(
            0,
            Store::where('name', self::PAYLOAD['store_name'])->count(),
            'A refused registration created the merchant store, so the tenant exists with no '
            . 'owner who can administer it.'
        );

        $this->assertSame(
            0,
            DB::table('store_wallets')->count(),
            'A refused registration provisioned a store wallet, so the rollback is partial and '
            . 'the orphan rows the gap names are still being written.'
        );
    }

    public function test_registration_is_refused_when_the_admin_role_is_absent(): void
    {
        $this->assertNoAdminRoleExists();

        $response = $this->post('/register', self::PAYLOAD);

        $this->assertNothingWasProvisioned();
    }

    /**
     * AND THE REFUSAL IS AUDIBLE. "Fails loudly" is half the stated expected behaviour, and a
     * refusal nobody can read is the complaint `OperatorImplicitStoreFinancialGuardTest` makes
     * about a guard that redirects to an error page.
     */
    public function test_the_refused_visitor_is_told_why_and_keeps_what_they_typed(): void
    {
        $this->assertNoAdminRoleExists();

        $response = $this->post('/register', self::PAYLOAD);

        $response->assertRedirect('/register');
        $response->assertSessionHas('status');

        $this->assertStringNotContainsStringIgnoringCase(
            self::PAYLOAD['password'],
            (string) session('status'),
            'the refusal message carries the submitted password.'
        );

        $this->assertSame(
            self::PAYLOAD['email'],
            session('_old_input')['email'] ?? null,
            'the refused visitor lost what they typed, so they must retype the whole form to '
            . 'retry something that is not their fault.'
        );
        $this->assertArrayNotHasKey(
            'password',
            session('_old_input') ?? [],
            'the submitted password was flashed to the session with the old input.'
        );
    }

    /**
     * THE SECOND BRANCH. `store()` splits on the `email_verification` setting and provisions a
     * merchant twice, once per branch (:179 and :310 are the two `addRole` sites). The guard sits
     * ABOVE the split, so this case asserts the branch is selected and the refusal still holds --
     * which is the evidence that one guard really does cover both call sites.
     */
    public function test_the_refusal_also_covers_the_email_verification_branch(): void
    {
        Setting::create([
            'name' => 'email_verification',
            'value' => 'on',
            'created_by' => 1,
            'store_id' => 1,
        ]);

        $this->assertSame(
            'on',
            Utility::Setting()['email_verification'] ?? null,
            'premise: the e-mail-verification branch is not selected for an anonymous visitor, so '
            . 'this case would drive the same branch as the one above.'
        );

        $this->assertNoAdminRoleExists();

        $this->post('/register', self::PAYLOAD);

        $this->assertNothingWasProvisioned();
    }

    /**
     * THE POSITIVE CONTROL, and it is what stops this guard being a lockout: with the role present
     * -- the production state, since `PermissionTableSeeder` creates it -- registration provisions
     * the merchant exactly as before.
     */
    public function test_a_registration_with_the_role_present_still_provisions_the_merchant(): void
    {
        // Created the way PermissionTableSeeder creates it: a single global row, `store_id` null.
        Role::create(['name' => 'admin', 'created_by' => null]);

        $response = $this->post('/register', self::PAYLOAD);

        $this->assertAuthenticated();

        $user = User::where('email', self::PAYLOAD['email'])->first();
        $this->assertNotNull($user, 'Registration did not create the user row.');

        $store = Store::where('created_by', $user->id)->first();
        $this->assertNotNull($store, 'Registration did not create the merchant store.');

        $this->assertSame($store->id, $user->fresh()->current_store);
        $this->assertTrue(
            $user->fresh()->hasRole('admin'),
            'The role was present and the merchant still did not get it.'
        );

        $response->assertRedirect(route('dashboard', absolute: false));
    }

    /**
     * NEITHER CALL SITE TOLERATES A NULL ROLE ANY MORE -- the gap's actual words. Read from the
     * source with comments stripped, because the prose above and in the controller both quote the
     * `if ($role_r)` this asserts is gone, and CLAUDE.md records four censuses in one day that a
     * comment satisfied or broke.
     */
    public function test_neither_add_role_call_site_tolerates_a_null_role(): void
    {
        $code = $this->sourceWithoutComments('app/Http/Controllers/Auth/RegisteredUserController.php');

        $this->assertSame(
            2,
            substr_count($code, '$user->addRole($role_r);'),
            'The two role-assignment call sites this gap names are no longer both there. If one '
            . 'moved, the guard above must still dominate it.'
        );

        $this->assertSame(
            0,
            substr_count($code, 'if ($role_r)'),
            'A call site is tolerating a null role again. The point of GAP-014 is that the role '
            . 'is proven to exist BEFORE anything is written, so a null can no longer reach '
            . 'addRole and a tolerant branch there would be a silent half-provisioning.'
        );

        /*
         * AND THE GUARD DOMINATES EVERY WRITE. A lookup that refuses correctly but sits below
         * `User::create()` would be the defect again with a different message, so the ORDER is
         * asserted and not just the presence.
         */
        $guardAt = strpos($code, "Role::where('name', 'admin')->first()");
        $this->assertNotFalse($guardAt, 'The admin-role lookup is gone from the controller.');

        $this->assertSame(
            1,
            substr_count($code, "Role::where('name', 'admin')->first()"),
            'The admin-role lookup exists more than once, so the two branches can drift apart '
            . 'again -- which is how :179 and :310 came to hold byte-identical tolerant guards.'
        );

        $firstWriteAt = strpos($code, 'User::create([');
        $this->assertNotFalse($firstWriteAt, 'The user row is no longer created here.');

        $this->assertLessThan(
            $firstWriteAt,
            $guardAt,
            'The admin-role lookup happens AFTER the first row is written, so a missing role '
            . 'still leaves a half-provisioned merchant behind -- exactly what GAP-014 refuses.'
        );
    }
}
