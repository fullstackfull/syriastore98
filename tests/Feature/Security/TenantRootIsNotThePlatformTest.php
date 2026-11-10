<?php

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P0-TENANT-ROOT — a merchant could take over any other merchant's account in two requests.
 * =================================================================================================
 *
 * PRE-EXISTING, and found by the project's `tenant-authz-reviewer` while it was reviewing the P0
 * deletion guard. Nothing in that change introduced this; the change is what made somebody read
 * `UserPolicy::sameTenant()`.
 *
 * THE CHAIN, measured end to end through the real routes before a line of it was fixed:
 *
 *   `User::creatorId()` returns `$this->id` only for type `admin`/`super admin`, and `created_by`
 *   for everything else. Self-registration writes `['type' => 'admin', 'created_by' => 1]`, so
 *   EVERY merchant on the platform carries `created_by = 1`. A principal whose type is anything
 *   else therefore resolves `creatorId()` to 1 — and `sameTenant()`'s two limbs then match every
 *   merchant on the platform, plus user 1 itself.
 *
 *   A merchant can enter that state using the product exactly as designed: create a role in their
 *   own store, then `PUT /users/{self}` with it. `UserController::update` derives `type`
 *   server-side from the SCOPED role (:299-306) — the right shape — and `RolePolicy::RESERVED_NAMES`
 *   blocks only the two `admin` spellings, so a role called "Manager" is accepted.
 *
 *       attacker id=2 type=admin created_by=1            creatorId() = 2
 *       PUT /users/{self} role=Manager    -> 302         type "Manager", creatorId() = 1
 *       sameTenant(attacker, other merchant)  = true
 *       UserPolicy::update(attacker, that merchant) = true
 *       PUT /users/{that merchant}        -> their email is now the attacker's
 *
 *   Owning the address owns every recovery flow. That is account takeover, available to any
 *   merchant, against any other.
 *
 * THE FIX asks one question: is the row this actor's tenant resolves to a tenant, or the platform?
 * A root that is the operator is not a tenant, so an actor rooted there is in NO tenant rather
 * than in everybody's.
 *
 * WHAT THIS FILE REFUSES TO DO. It does not assert the fix by calling `sameTenant()` and stopping
 * there. Every case below drives an HTTP route and asserts a ROW — because the question is not
 * "what does the predicate return", it is "can one merchant change another merchant's email".
 * The predicate is checked too, but only as the premise that the case is measuring the right
 * thing.
 */
class TenantRootIsNotThePlatformTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Put the attacker into the collapsed state THROUGH THE PRODUCT, and prove it happened.
     *
     * @return User the attacker, refreshed, with `creatorId()` resolving to the platform
     */
    private function demoteThroughTheRoute(User $attacker): User
    {
        $role = Role::forceCreate([
            'name' => 'Manager',
            'display_name' => 'Manager',
            'guard_name' => 'web',
            'created_by' => $attacker->creatorId(),
            'store_id' => $attacker->current_store,
        ]);

        $this->actingAs($attacker)->put("/users/{$attacker->id}", [
            'name' => $attacker->name,
            'email' => $attacker->email,
            'role' => $role->id,
        ]);

        $fresh = $attacker->fresh();

        // THE PREMISE. If the demotion stops working — a wider RESERVED_NAMES, a role lookup that
        // refuses, a `type` that stops being derived — then every case below is measuring a
        // refusal it would get anyway, and should be re-pointed rather than left to pass.
        $this->assertSame(
            'Manager',
            $fresh->type,
            'the self-demotion no longer happens, so these cases cannot reach the state they '
            . 'exist to test. Re-point them at whatever the route does now.'
        );

        $this->assertSame(
            1,
            (int) $fresh->creatorId(),
            'the attacker\'s tenant root is no longer the platform, so the collapse this file '
            . 'guards against is not being reproduced'
        );

        return $fresh;
    }

    public function test_premise_every_merchant_is_created_by_the_platform(): void
    {
        // The fixture matches production: self-registration writes created_by = 1.
        [$merchant] = $this->makeTenant();

        $this->assertSame(1, (int) $merchant->created_by);
        $this->assertSame(
            (int) $merchant->id,
            (int) $merchant->creatorId(),
            'a type-admin merchant must root at their own id, or there is no tenant at all'
        );
    }

    public function test_a_self_demoted_merchant_cannot_edit_another_merchant(): void
    {
        $operator = $this->makeMerchant(['email' => 'root-operator@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit User');

        [$victim] = $this->makeTenant();
        $victimEmail = $victim->email;

        $collapsed = $this->demoteThroughTheRoute($attacker);

        $this->actingAs($collapsed)->put("/users/{$victim->id}", [
            'name' => 'Taken Over',
            'email' => 'attacker-owns-this@example.test',
        ]);

        $this->assertSame(
            $victimEmail,
            User::withoutGlobalScopes()->whereKey($victim->id)->value('email'),
            'ACCOUNT TAKEOVER: a merchant demoted their own account type through '
            . 'PUT /users/{self}, which collapsed creatorId() to the platform, and then rewrote '
            . 'another merchant\'s email address. Owning the address owns every recovery flow.'
        );
    }

    public function test_a_self_demoted_merchant_cannot_reset_another_merchants_password(): void
    {
        [$attacker] = $this->makeTenant();
        // `Edit User` as well: the demotion itself goes through PUT /users/{self}, which
        // UserPolicy::update() gates on that permission. Without it the self-PUT is refused and
        // the premise below fails -- which is what happened on the first run.
        $role = $this->grantPermission($attacker, 'Edit User');
        $role->givePermission(\App\Models\Permission::firstOrCreate(['name' => 'Reset Password'], ['display_name' => 'Reset Password']));
        $attacker->flushCache();

        [$victim] = $this->makeTenant();

        $collapsed = $this->demoteThroughTheRoute($attacker);

        $this->assertFalse(
            (new UserPolicy())->resetPassword($collapsed, $victim->fresh()),
            'a merchant rooted at the platform may still reset another merchant\'s password'
        );
    }

    /**
     * THIS ONE PASSES WITH THE REPAIR REVERTED, AND THAT IS WORTH SAYING.
     *
     * Measured: revert `sameTenant()`'s root check and the edit and reset cases redden while this
     * one stays green — because the victim is a merchant, a merchant owns a store, and
     * `UserDeletionSafety::blockers()` refuses a store owner whatever the policy decided. The
     * right outcome reached by the wrong layer.
     *
     * It is kept because that is exactly what defence in depth should look like from the outside,
     * and because the day the data-safety guard is narrowed, this is the case that notices. It
     * must not be read as evidence that the POLICY was ever correct here: the two cases above are
     * what measure that.
     */
    public function test_a_self_demoted_merchant_cannot_delete_another_merchant(): void
    {
        [$attacker] = $this->makeTenant();
        $role = $this->grantPermission($attacker, 'Edit User');
        $role->givePermission(\App\Models\Permission::firstOrCreate(['name' => 'Delete User'], ['display_name' => 'Delete User']));
        $attacker->flushCache();

        [$victim] = $this->makeTenant();

        $collapsed = $this->demoteThroughTheRoute($attacker);

        $this->actingAs($collapsed)->delete("/users/{$victim->id}");

        $this->assertTrue(
            User::withoutGlobalScopes()->whereKey($victim->id)->exists(),
            'a merchant rooted at the platform deleted another merchant'
        );
    }

    /**
     * A staff row the OPERATOR created is in no merchant's tenant, and used to be in all of them.
     */
    public function test_operator_created_staff_are_in_no_merchant_tenant(): void
    {
        $operator = $this->makeMerchant(['email' => 'platform-root@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        $attacker = $this->makeStaff($operator, ['email' => 'platform-staff@example.test']);
        $this->grantPermission($attacker, 'Edit User');

        [$victim] = $this->makeTenant();
        $victimEmail = $victim->email;

        $this->actingAs($attacker->fresh())->put("/users/{$victim->id}", [
            'name' => 'Taken Over',
            'email' => 'platform-staff-owns-this@example.test',
        ]);

        $this->assertSame(
            $victimEmail,
            User::withoutGlobalScopes()->whereKey($victim->id)->value('email'),
            'a staff row the operator created edited a merchant it has no relationship to'
        );
    }

    // =============================================================================================
    //  AND THE LEGITIMATE PATHS, because "refuse everything" would pass every case above
    // =============================================================================================

    public function test_a_merchant_may_still_edit_their_own_staff(): void
    {
        [$owner] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit User');

        $staff = $this->makeStaff($owner, ['email' => 'own-staff@example.test']);

        $this->actingAs($owner)->put("/users/{$staff->id}", [
            'name' => 'Renamed By Owner',
            'email' => $staff->email,
        ]);

        $this->assertSame(
            'Renamed By Owner',
            User::withoutGlobalScopes()->whereKey($staff->id)->value('name'),
            'the merchant can no longer edit their own staff, so the repair is too wide'
        );
    }

    public function test_a_merchants_staff_may_still_edit_a_sibling(): void
    {
        [$owner] = $this->makeTenant();

        $actor = $this->makeStaff($owner, ['email' => 'staff-actor@example.test']);
        $sibling = $this->makeStaff($owner, ['email' => 'staff-sibling@example.test']);

        $this->grantPermission($actor, 'Edit User');

        $this->actingAs($actor->fresh())->put("/users/{$sibling->id}", [
            'name' => 'Renamed By Sibling',
            'email' => $sibling->email,
        ]);

        $this->assertSame(
            'Renamed By Sibling',
            User::withoutGlobalScopes()->whereKey($sibling->id)->value('name'),
            'staff rooted at a real merchant can no longer manage their own tenant, so the '
            . 'repair caught a legitimate principal'
        );
    }

    /**
     * THE OPERATOR HERE HOLDS `Edit User`, AND THAT IS NOT INCIDENTAL — it is a finding.
     *
     * `UserPolicy::update()` tests the permission BEFORE it tests operator status
     * (`:130` then `:134`), which is the opposite order from its two siblings: `delete()` and
     * `resetPassword()` both admit `isPlatformAdmin($actor)` first. CLAUDE.md records why the
     * operator-first order is the right one in this codebase -- Laratrust's
     * `permissions_as_gates` is false and operator status is a string on `users.type`, so
     * `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR.
     *
     * Measured: with an operator holding no Laratrust role, this case fails -- and it fails
     * identically with the tenant-root repair reverted, so it is PRE-EXISTING and nothing here
     * caused it. It is reported rather than fixed, because reordering `update()` would hand every
     * operator edit rights they do not hold today, and that is a product decision rather than
     * part of closing this vulnerability. See docs/security/P0-USER-DELETION-CASCADE.md.
     *
     * The permission is granted here so that this case measures what it is named after: that the
     * repair does not reach the operator.
     */
    public function test_the_operator_may_still_edit_a_merchant(): void
    {
        $operator = $this->makeMerchant(['email' => 'real-operator@example.test']);
        $operator->type = 'super admin';
        $operator->save();

        [$merchant] = $this->makeTenant();

        $this->grantPermission($operator, 'Edit User');
        $operator = $operator->fresh();

        $this->actingAs($operator->fresh())->put("/users/{$merchant->id}", [
            'name' => 'Renamed By Operator',
            'email' => $merchant->email,
        ]);

        $this->assertSame(
            'Renamed By Operator',
            User::withoutGlobalScopes()->whereKey($merchant->id)->value('name'),
            'the platform operator can no longer edit a merchant. Every one of update(), '
            . 'resetPassword() and delete() admits the operator BEFORE sameTenant() runs, so if '
            . 'this fails the repair has reached somewhere it must not.'
        );
    }
}
