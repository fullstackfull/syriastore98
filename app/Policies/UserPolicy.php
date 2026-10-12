<?php

namespace App\Policies;

use App\Models\User;

/**
 * Authorization for user-account administration.
 *
 * Covers SEC-F4 (cross-tenant user edit), SEC-F1 (password-reset takeover) and
 * SEC-F6 (impersonation platform takeover).
 *
 * `users` has no `store_id` column, and `users.current_store` is the mutable,
 * caller-writable switch pointer of R4-03 -- so tenancy for a User is keyed on
 * `creatorId()` (User.php:95), never on `current_store`. That is also why User
 * is on the explicit DO-NOT-SCOPE list and needs a Policy instead.
 */
class UserPolicy
{
    /**
     * The platform-operator account type. Compared as a literal because that is
     * what every existing consumer does (StoreController:43, AddonController:21
     * and :153, EmailIsVerified:46, User::creatorId():97).
     */
    public const PLATFORM_ADMIN = 'super admin';

    /**
     * Is the target account inside the actor's own tenant?
     *
     * A merchant admin owns the staff rows they created; a staff member
     * resolves to the same owning admin. The actor's own row always matches.
     */
    protected function sameTenant(User $actor, User $target): bool
    {
        if ($actor->id === $target->id) {
            return true;
        }

        $actorTenant = $actor->creatorId();

        if (empty($actorTenant)) {
            return false;
        }

        // The target is either a row the tenant owner created, or the tenant
        // owner themselves.
        return (int) $target->created_by === (int) $actorTenant
            || (int) $target->id === (int) $actorTenant;
    }

    protected function isPlatformAdmin(?User $actor): bool
    {
        return $actor !== null && $actor->type === self::PLATFORM_ADMIN;
    }

    /**
     * SEC-F4. UserController::update checked only the 'Edit User' permission,
     * which every merchant holds for their own tenant, and then operated on
     * User::findOrFail($id) with no ownership test at all -- so any merchant
     * could edit any user on the platform, including user id 1.
     */
    public function update(User $actor, User $target): bool
    {
        if (!$actor->isAbleTo('Edit User')) {
            return false;
        }

        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        // Nobody below platform-admin may edit a platform admin.
        if ($target->type === self::PLATFORM_ADMIN) {
            return false;
        }

        return $this->sameTenant($actor, $target);
    }

    /**
     * SEC-F1. StoreController::storeResetPasswordUpdate had NO authorization of
     * any kind, and UserController::reset/updatePassword checked the permission
     * but not ownership -- so any holder of 'Reset Password' could set the
     * password of any account on the platform. The password cast is 'hashed'
     * (User.php:77-80), so the write produced a WORKING credential.
     *
     * The explicit platform-admin limb keeps the legitimate support workflow
     * (a super admin resetting a merchant's password) working.
     */
    public function resetPassword(User $actor, User $target): bool
    {
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        if (!$actor->isAbleTo('Reset Password')) {
            return false;
        }

        if ($target->type === self::PLATFORM_ADMIN) {
            return false;
        }

        return $this->sameTenant($actor, $target);
    }

    /**
     * SEC-F6 -- IMPERSONATION, platform takeover.
     *
     * StoreController::LoginWithAdmin gated `Impersonate::take()` on
     * `auth()->check()` alone: "is ANYONE logged in", not "may YOU do this".
     * The `decrypt($id)` route parameter is NOT a control -- APP_KEY is in git
     * history, so an encrypted parameter is forgeable for any user id. Public
     * self-registration grants type=admin, so the chain was:
     * register -> forge an encrypted id -> become any account, including the
     * platform super admin.
     *
     * This ability is the control. It is enforced in the controller AND
     * mirrored by canImpersonate()/canBeImpersonated() on the User model, so
     * neither the vendor trait's defaults nor a single forgotten call site can
     * reopen the hole on its own.
     */
    public function impersonate(User $actor, User $target): bool
    {
        // Only the platform operator may impersonate.
        if (!$this->isPlatformAdmin($actor)) {
            return false;
        }

        // Never impersonate yourself, and never another platform admin.
        if ($actor->id === $target->id) {
            return false;
        }

        return $target->type !== self::PLATFORM_ADMIN;
    }
}
