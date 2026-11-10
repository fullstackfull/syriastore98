<?php

namespace Tests\Feature\Security;

use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-SEC-VERIFY — the four user-management paths, checked as one grid.
 * =================================================================================================
 *
 * The owner's closure instruction asks for cross-tenant protection to be verified across
 * **update, delete, password reset and impersonation** together, rather than one at a time. They
 * are four methods on one policy, they share one tenancy predicate, and they have historically
 * DRIFTED APART — which is the thing a grid catches and four separate files do not.
 *
 * Measured drift at the time this file was written:
 *
 *     method           operator admitted   permission        target-is-operator test
 *     update()         AFTER permission    Edit User         $target->type === 'super admin'
 *     delete()         FIRST               Delete User       self::isOperator($target)
 *     resetPassword()  FIRST               Reset Password    $target->type === 'super admin'
 *     impersonate()    operator-only       --                $target->type !== 'super admin'
 *
 * TWO OF THOSE COLUMNS ARE DELIBERATE AND ONE WAS NOT.
 *
 *   * `update()` testing the PERMISSION before operator status is an owner decision of
 *     2026-11-10: *"Do not grant operators unrestricted user-editing privileges. Preserve
 *     least-privilege authorization. Do not reorder the policy merely to bypass a permission
 *     denial."* It is pinned below so that a future "consistency" pass cannot quietly reorder it.
 *   * `impersonate()` being operator-only is SEC-F6 and is pinned below as well.
 *   * The target-is-operator column was NOT deliberate. Three of the four compared
 *     `$target->type` against the literal `'super admin'`, and MySQL's `utf8mb4_unicode_ci`
 *     treats `'Super Admin'` and `'super admin '` as EQUAL to that literal while PHP's `===`
 *     does not. So a row typed either way was the operator to the database and a stranger to
 *     three of these four methods. `UserPolicy::isOperator()` exists precisely to normalise it
 *     and only `delete()` was using it.
 *
 * WHY THAT IS A TIGHTENING AND NOT A REORDER. Normalising the TARGET test can only refuse more
 * than it did; it cannot admit anyone it previously refused, and it does not move the operator
 * check past the permission check on any path. It is exactly what the closure instruction asks
 * for under *"preserve cross-tenant protection across update, delete, password reset and
 * impersonation paths"*.
 *
 * WHAT THIS FILE DOES NOT RE-TEST. The collapsed-tenant-root attack and its repair live in
 * `TenantRootIsNotThePlatformTest`, which drives it through the HTTP routes. Repeating it here
 * would be two files claiming one proof.
 */
class CrossTenantPolicyGridTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Every spelling of the operator type that MySQL's collation treats as the platform operator.
     *
     * `rtrim(' ')` only, matching MySQL's PAD SPACE: a trailing space is ignored by the engine, a
     * leading one is not, and neither is a tab. That is what `UserPolicy::isOperator()` implements
     * and what these three spellings are chosen to exercise.
     */
    private const OPERATOR_SPELLINGS = [
        'canonical' => 'super admin',
        'title case' => 'Super Admin',
        'trailing space' => 'super admin ',
    ];

    private function policy(): UserPolicy
    {
        return new UserPolicy();
    }

    /** A merchant in their own tenant, holding every user-management permission. */
    private function armedMerchant(string $email): User
    {
        [$merchant] = $this->makeTenant();
        $merchant->forceFill(['email' => $email])->save();

        $role = $this->grantPermission($merchant, 'Edit User');

        foreach (['Delete User', 'Reset Password'] as $permission) {
            $role->givePermission(
                \App\Models\Permission::firstOrCreate(
                    ['name' => $permission],
                    ['display_name' => $permission]
                )
            );
        }

        $merchant->flushCache();

        return $merchant->fresh();
    }

    // =============================================================================================
    //  1. CROSS-TENANT REFUSAL, all four methods against one shared fixture
    // =============================================================================================

    public function test_premise_the_two_tenants_really_are_different(): void
    {
        // SecurityTestCase::makeMerchant hardcodes created_by => 1, and the FIRST merchant a test
        // creates IS id 1 -- so two naive makeTenant() calls produce a genuine same-tenant pair
        // and every refusal case below would pass for the wrong reason. The filler occupies id 1.
        $this->makeMerchant(['email' => 'grid-filler@example.test']);

        $attacker = $this->armedMerchant('grid-attacker-premise@example.test');
        [$victim] = $this->makeTenant();

        $this->assertNotSame(
            (int) $attacker->creatorId(),
            (int) $victim->creatorId(),
            'the two fixtures share a tenant root, so every cross-tenant case in this file is '
            . 'measuring nothing'
        );
    }

    public function test_no_user_management_path_crosses_a_tenant_boundary(): void
    {
        $this->makeMerchant(['email' => 'grid-filler2@example.test']);

        $attacker = $this->armedMerchant('grid-attacker@example.test');
        [$victim] = $this->makeTenant();
        $victimStaff = $this->makeStaff($victim, ['email' => 'grid-victim-staff@example.test']);

        $policy = $this->policy();

        foreach ([['owner', $victim], ['staff', $victimStaff]] as [$what, $target]) {
            $this->assertFalse(
                $policy->update($attacker, $target->fresh()),
                "a merchant holding Edit User may update another tenant's $what"
            );
            $this->assertFalse(
                $policy->delete($attacker, $target->fresh()),
                "a merchant holding Delete User may delete another tenant's $what"
            );
            $this->assertFalse(
                $policy->resetPassword($attacker, $target->fresh()),
                "a merchant holding Reset Password may reset another tenant's $what"
            );
            $this->assertFalse(
                $policy->impersonate($attacker, $target->fresh()),
                "a merchant may impersonate another tenant's $what -- impersonation is "
                . 'operator-only (SEC-F6)'
            );
        }
    }

    /**
     * THE SAME BOUNDARY, THROUGH THE ROUTES. A policy that returns false is not a refusal until
     * the controller asks it, and this project has shipped a correct policy nobody called.
     */
    public function test_the_routes_refuse_a_cross_tenant_write(): void
    {
        $this->makeMerchant(['email' => 'grid-filler3@example.test']);

        $attacker = $this->armedMerchant('grid-attacker2@example.test');
        [$victim] = $this->makeTenant();
        $victimStaff = $this->makeStaff($victim, ['email' => 'grid-victim-staff2@example.test']);

        $before = $victimStaff->email;

        $this->actingAs($attacker)->put("/users/{$victimStaff->id}", [
            'name' => 'Taken Over',
            'email' => 'grid-attacker-owns-this@example.test',
        ]);

        $this->assertSame(
            $before,
            User::withoutGlobalScopes()->whereKey($victimStaff->id)->value('email'),
            'PUT /users/{id} rewrote another tenant\'s email address'
        );

        $this->actingAs($attacker)->delete("/users/{$victimStaff->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($victimStaff->id)->exists(),
            'DELETE /users/{id} removed another tenant\'s staff member'
        );
    }

    // =============================================================================================
    //  2. THE OPERATOR AS A TARGET -- in every spelling the database treats as the operator
    // =============================================================================================

    public function test_premise_the_three_spellings_are_one_operator_to_the_helper(): void
    {
        foreach (self::OPERATOR_SPELLINGS as $label => $spelling) {
            $this->assertTrue(
                UserPolicy::isOperator($spelling),
                "`$spelling` ($label) is not recognised as the operator by the shared helper, so "
                . 'the cases below are measuring the wrong set of spellings'
            );
        }
    }

    /**
     * THE TARGET IS INSIDE THE ATTACKER'S OWN TENANT, AND THAT IS THE WHOLE POINT.
     *
     * The first version of this case made the operator a separate merchant, and it PASSED against
     * the unrepaired policy -- because `sameTenant()` refused it anyway and the target-is-operator
     * limb was never reached. A case that is satisfied by a limb it is not testing proves nothing
     * about the limb it names; this project has been bitten by that shape three times.
     *
     * So the operator row here is a STAFF ROW THE ATTACKER CREATED. `sameTenant()` returns true
     * for it, and the only thing that can refuse is the operator test.
     */
    public function test_no_path_may_act_on_a_platform_operator_however_the_row_is_spelled(): void
    {
        $this->makeMerchant(['email' => 'grid-filler4@example.test']);

        $attacker = $this->armedMerchant('grid-attacker3@example.test');
        $policy = $this->policy();

        foreach (self::OPERATOR_SPELLINGS as $label => $spelling) {
            $operator = $this->makeStaff($attacker, [
                'email' => 'grid-op-' . md5($spelling) . '@example.test',
            ]);
            $operator->forceFill(['type' => $spelling])->save();
            $operator = $operator->fresh();

            $this->assertSame(
                $spelling,
                $operator->type,
                "the fixture did not keep the `$label` spelling, so this case is not testing it"
            );

            // THE PREMISE that makes this case about the operator limb and nothing else.
            $reflected = new \ReflectionMethod($policy, 'sameTenant');
            $reflected->setAccessible(true);

            $this->assertTrue(
                $reflected->invoke($policy, $attacker, $operator),
                "the `$label` fixture is not in the attacker's tenant, so sameTenant() would "
                . 'refuse it on its own and this case cannot isolate the operator limb'
            );

            $this->assertFalse(
                $policy->update($attacker, $operator),
                "a merchant may UPDATE a platform operator whose type is spelled `$spelling` "
                . "($label). MySQL's utf8mb4_unicode_ci treats that row as the operator; "
                . 'PHP\'s === against the literal does not. Use UserPolicy::isOperator().'
            );
            $this->assertFalse(
                $policy->delete($attacker, $operator),
                "a merchant may DELETE a platform operator spelled `$spelling` ($label)"
            );
            $this->assertFalse(
                $policy->resetPassword($attacker, $operator),
                "a merchant may RESET THE PASSWORD of a platform operator spelled `$spelling` "
                . "($label)"
            );
        }
    }

    public function test_an_operator_may_not_impersonate_another_operator_however_spelled(): void
    {
        $this->makeMerchant(['email' => 'grid-filler5@example.test']);

        $actingOperator = $this->makeMerchant(['email' => 'grid-acting-op@example.test']);
        $actingOperator->forceFill(['type' => 'super admin'])->save();
        $actingOperator = $actingOperator->fresh();

        $policy = $this->policy();

        foreach (self::OPERATOR_SPELLINGS as $label => $spelling) {
            $target = $this->makeMerchant(['email' => 'grid-imp-' . md5($spelling) . '@example.test']);
            $target->forceFill(['type' => $spelling])->save();

            $this->assertFalse(
                $policy->impersonate($actingOperator, $target->fresh()),
                "the operator may impersonate another platform operator spelled `$spelling` "
                . '($label). SEC-F6 is platform takeover: one operator account becoming another '
                . 'leaves no trace of which one acted.'
            );
        }
    }

    // =============================================================================================
    //  3. LEAST PRIVILEGE, pinned as the owner's decision rather than left as an accident
    // =============================================================================================

    /**
     * Owner instruction, 2026-11-10: *"Do not grant operators unrestricted user-editing
     * privileges. Preserve least-privilege authorization. Use existing explicit roles and
     * permissions. Do not reorder the policy merely to bypass a permission denial."*
     *
     * `update()` therefore tests `Edit User` BEFORE operator status, unlike its three siblings.
     * That asymmetry was reported as a possible defect and has been DECIDED as a control, so it
     * is pinned here: a reorder would make this case fail, which is the point.
     */
    public function test_an_operator_without_edit_user_may_not_update_anyone(): void
    {
        $this->makeMerchant(['email' => 'grid-filler6@example.test']);

        $operator = $this->makeMerchant(['email' => 'grid-bare-op@example.test']);
        $operator->forceFill(['type' => 'super admin'])->save();

        [$merchant] = $this->makeTenant();

        $this->assertFalse(
            $this->policy()->update($operator->fresh(), $merchant->fresh()),
            'an operator holding NO `Edit User` permission may edit a merchant. That is the '
            . 'unrestricted-operator privilege the owner declined on 2026-11-10; user editing '
            . 'stays behind an explicit role.'
        );
    }

    public function test_an_operator_holding_edit_user_may_still_do_the_work(): void
    {
        // The other half: least privilege must not mean no privilege.
        $this->makeMerchant(['email' => 'grid-filler7@example.test']);

        $operator = $this->makeMerchant(['email' => 'grid-armed-op@example.test']);
        $operator->forceFill(['type' => 'super admin'])->save();

        $this->grantPermission($operator, 'Edit User');

        [$merchant] = $this->makeTenant();

        $this->assertTrue(
            $this->policy()->update($operator->fresh(), $merchant->fresh()),
            'an operator who HOLDS `Edit User` is refused, so the permission is not the control '
            . 'it is meant to be'
        );
    }

    public function test_only_the_operator_may_impersonate(): void
    {
        $this->makeMerchant(['email' => 'grid-filler8@example.test']);

        $operator = $this->makeMerchant(['email' => 'grid-imp-op@example.test']);
        $operator->forceFill(['type' => 'super admin'])->save();

        [$merchant] = $this->makeTenant();

        $this->assertTrue(
            $this->policy()->impersonate($operator->fresh(), $merchant->fresh()),
            'the platform operator may no longer impersonate a merchant, so support access is '
            . 'broken'
        );

        $this->assertFalse(
            $this->policy()->impersonate($operator->fresh(), $operator->fresh()),
            'the operator may impersonate themselves, which is a no-op that still mints an '
            . 'impersonation session'
        );
    }

    // =============================================================================================
    //  4. LEGITIMATE STAFF MANAGEMENT -- so none of the above can be satisfied by refusing all
    // =============================================================================================

    public function test_a_merchant_may_still_manage_their_own_staff_on_every_path(): void
    {
        $this->makeMerchant(['email' => 'grid-filler9@example.test']);

        $owner = $this->armedMerchant('grid-owner@example.test');
        $staff = $this->makeStaff($owner, ['email' => 'grid-own-staff@example.test']);

        $policy = $this->policy();

        $this->assertTrue(
            $policy->update($owner, $staff->fresh()),
            'a merchant can no longer edit their own staff'
        );
        $this->assertTrue(
            $policy->resetPassword($owner, $staff->fresh()),
            'a merchant can no longer reset their own staff member\'s password'
        );
        $this->assertTrue(
            $policy->delete($owner, $staff->fresh()),
            'a merchant can no longer remove their own staff'
        );
    }

    public function test_and_the_routes_let_them(): void
    {
        $this->makeMerchant(['email' => 'grid-filler10@example.test']);

        $owner = $this->armedMerchant('grid-owner2@example.test');
        $staff = $this->makeStaff($owner, ['email' => 'grid-own-staff2@example.test']);

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => 'Renamed By Owner',
            'email' => $staff->email,
        ]);

        $this->assertSame(
            'Renamed By Owner',
            User::withoutGlobalScopes()->whereKey($staff->id)->value('name'),
            'PUT /users/{id} refuses a merchant editing their own staff, so the grid above is '
            . 'passing by refusing everything'
        );

        $this->actingAs($owner)->delete("/users/{$staff->id}");

        $this->assertFalse(
            User::withoutGlobalScopes()->whereKey($staff->id)->exists(),
            'DELETE /users/{id} refuses a merchant removing a dependency-free staff row'
        );
    }
}
