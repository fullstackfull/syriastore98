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
