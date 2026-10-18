<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Authorization for role administration -- SEC-F3 and the RoleController limbs
 * of R4-04.
 *
 * RoleController::update was gated only on the 'Edit Role' permission, which
 * every merchant holds, and then operated on an implicitly bound Role with no
 * ownership test. Because Role declared `$guarded = []`, the subsequent
 * `$input['store_id'] = getCurrentStore(); $role->fill($input)->save();` also
 * STOLE the victim role into the attacker's store.
 *
 * Role tenancy is `roles.created_by` + `roles.store_id`, which is exactly the
 * predicate UserController::edit already applies correctly when listing roles
 * for a form -- proving omission rather than design.
 */
class RolePolicy
{
    public const PLATFORM_ADMIN = 'super admin';

    /**
     * Role names that may never be created or assigned through this controller,
     * because the application compares against these literals to decide
     * platform-operator status.
     */
    public const RESERVED_NAMES = [
        'super admin', 'superadmin', 'Super Admin', 'SuperAdmin', 'SUPER ADMIN',
        // S13-b. `admin` BELONGS HERE AND ITS ABSENCE WAS A LIVE ESCALATION.
        //
        // This list existed to stop a tenant naming a role into platform-operator status, and it
        // covered only the `super admin` spellings. But `admin` is an authority discriminator in
        // its own right -- the codebase compares users.type against 'admin' 47 times, against
        // 'super admin' 77 -- and writing it does three things at once:
        //
        //   App\Models\User::creatorId():100  returns $this->id for type 'admin', so the row
        //       becomes THE ROOT OF ITS OWN TENANT and every created_by = creatorId() predicate
        //       in the application starts answering about the actor instead of their merchant.
        //   StoreController::create():62, store():94  gate store creation on
        //       `type == 'admin'` with NO permission check at all, so the flip hands over a
        //       capability that answered "Permission denied" a moment earlier.
        //   Utility.php, helper.php, AuthenticatedSessionController and PaymentController all
        //       branch merchant-owner behaviour on the same literal.
        //
        // REPRODUCED before this change: a staff member holding 'Create Role' and 'Edit User' --
        // both grantable by any merchant -- created a tenant-scoped role named `admin`, assigned
        // it to themselves through the ALREADY-HARDENED UserController::update(), and watched
        // users.type go 'staff' -> 'admin', creatorId() go from the merchant's id to their own,
        // and GET /stores/create go from 302-deny to 200. Both of update()'s controls were
        // intact; the LIST was the hole.
        //
        // THE SEEDED MERCHANT ROLE IS ALSO LITERALLY NAMED `admin`, which sounds like a conflict
        // and is not: it carries store_id NULL, so the tenant-scoped role lookup in
        // UserController already refuses it on both write paths. Only a tenant-authored role of
        // that name was ever assignable, and that is precisely what must not be.
        //
        // The case variants are here because RoleController's validator uses
        // Rule::notIn(), which compares case-SENSITIVELY, while UserController lowercases first.
        // Both consumers are now covered by the same list.
        'admin', 'Admin', 'ADMIN',
    ];

    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null && $actor->type === self::PLATFORM_ADMIN;
    }

    protected function ownsRole(User $actor, Role $role): bool
    {
        $actorTenant = $actor->creatorId();

        if (empty($actorTenant) || empty($role->created_by)) {
            return false;
        }

        if ((int) $role->created_by !== (int) $actorTenant) {
            return false;
        }

        // The global `admin` role seeded by PermissionTableSeeder has a null
        // store_id and is SHARED by every self-registered merchant. It must
        // never be editable through a per-tenant screen, or one merchant
        // rewrites every merchant's role.
        if (empty($role->store_id)) {
            return false;
        }

        return (int) $role->store_id === (int) getCurrentStore();
    }

    /**
     * May the actor open the role-creation screen?
     *
     * NEW-ROLE-FORM-UNGATED-01. RoleController::create() carried no check of any
     * kind: any authenticated user could GET /roles/create and the view renders
     * `Permission::all()` (:54), i.e. the entire permission catalogue of the
     * platform, including the config/superadminaccess.php names. store() has
     * always required 'Create Role' (:67), so the form was reachable by people who
     * could not use it -- the disclosure was the whole of the exposure, but it is a
     * real one and it is the sibling-endpoint shape that SEC-F3 was.
     *
     * The permission name is NOT invented: 'Create Role' is seeded by
     * Utility::addNewData() (app/Models/Utility.php:2655 and :2910) and is the same
     * literal store() already tests. Inventing a name no seeder creates ('Edit
     * Order') was a real defect on this codebase, so this is checked rather than
     * assumed.
     */
    public function create(User $actor): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $actor->isAbleTo('Create Role');
    }

    public function update(User $actor, Role $role): bool
    {
        if (!$actor->isAbleTo('Edit Role')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $this->ownsRole($actor, $role);
    }

    public function delete(User $actor, Role $role): bool
    {
        if (!$actor->isAbleTo('Delete Role')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return $this->ownsRole($actor, $role);
    }

    /**
     * May the actor grant this permission name?
     *
     * config/superadminaccess.php lists the platform-operator permissions
     * (Create Plan, Manage Country, Manage Module, Manage currency, ...). They
     * were filtered ONLY in Blade (roles/create.blade.php:72,98 and
     * roles/edit.blade.php:92) and never server-side, so a crafted POST granted
     * them to any role.
     */
    public function grantPermission(User $actor, string $permissionName): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        return !in_array($permissionName, (array) config('superadminaccess', []), true);
    }
}
