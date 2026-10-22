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
        return self::isOperator($actor);
    }

    /**
     * THE ONE OPERATOR TEST. P1-SEC-11, acceptance criterion 3 ("the operator is defined once:
     * normalised comparison, one helper"). Static and nullable-tolerant so the three sites the
     * criterion names -- OrderController::fileExport, OrderDocumentAccessGuard and
     * OrderPolicy::isPlatformAdmin -- can all reach it without an instance.
     *
     * WHY NORMALISED, measured rather than argued. `users.type` is a varchar under the project's
     * `utf8mb4_unicode_ci`, and on MySQL 8.0.46 that collation folds case AND trailing space:
     *
     *     SELECT 'Super Admin'  = 'super admin';   -- 1
     *     SELECT 'super admin ' = 'super admin';   -- 1
     *
     * So a row typed `'Super Admin'` IS the operator to every SQL query -- `User::where('type',
     * 'super admin')` returns it -- while a PHP `===` against the literal refuses it. The two layers
     * then disagree about who the operator is, in the direction that locks the operator out of their
     * own dashboard. `App\Http\Middleware\FinancePermission` records that exact lockout happening
     * once already, for six unseeded finance names.
     *
     * IT MATCHES THE ENGINE EXACTLY, AND `trim()` DID NOT. The first version of this helper used
     * `strtolower(trim($type))`, and measuring it side by side against MySQL showed `trim()` is WIDER
     * than the collation:
     *
     *     value            engine = 'super admin'   trim() helper
     *     'Super Admin'    1                        true    (agree)
     *     'super admin '   1                        true    (agree -- PAD SPACE ignores TRAILING space)
     *     ' super admin'   0                        true    <-- WIDER THAN THE ENGINE
     *     'superadmin'     0                        false   (agree)
     *     'super  admin'   0                        false   (agree -- inner whitespace is not folded)
     *
     * A LEADING space is not ignored by PAD SPACE, so `' super admin'` is NOT the operator to the
     * database -- and `trim()` would have granted operator status to a row every SQL query refuses.
     * That is the opposite failure from the lockout and a worse one. Since the whole rationale here is
     * to AGREE with the engine, the helper strips trailing spaces only, exactly as PAD SPACE does.
     * (Unreachable in practice either way -- `RolePolicy::RESERVED_NAMES` is checked through a gate
     * that trims, so no tenant can create such a role -- but a control whose rule differs from the
     * engine's is a control nobody can reason about.)
     *
     * So normalising cannot widen access: every value this accepts, the engine also treats as equal.
     *
     * HOW LIVE IS THE LOCKOUT, stated because it decides the scope: LATENT, not live. The only
     * writers of `users.type` are `'admin'` (RegisteredUserController:124, :261,
     * StoreController:265), `'customer'`, the seeder's exact `'super admin'`
     * (PermissionTableSeeder:33), and a role NAME at UserController:154/:305 -- and that last is
     * gated by `RolePolicy::RESERVED_NAMES` through a check that LOWERCASES THE NEEDLE, so no tenant
     * can name a role into any case variant of operator status. Reaching the lockout needs a
     * hand-edited row.
     *
     * That is why this is one helper at the named sites plus a census ratchet, and NOT a conversion
     * of the 101 non-normalised comparisons across ~35 files: a large blast radius for a latent
     * defect. The ratchet (OperatorCheckCensusTest) is what stops that count growing.
     *
     * @param  \App\Models\User|\Illuminate\Contracts\Auth\Authenticatable|string|null  $actor  a principal, or a type string
     */
    public static function isOperator($actor): bool
    {
        if ($actor === null) {
            return false;
        }

        $type = is_object($actor) ? ($actor->type ?? null) : $actor;

        // rtrim of SPACE only, matching MySQL's PAD SPACE: a trailing space is ignored by the engine,
        // a leading one is not, and neither is a tab. Measured -- see the table above.
        return $type !== null && strtolower(rtrim((string) $type, ' ')) === self::PLATFORM_ADMIN;
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
