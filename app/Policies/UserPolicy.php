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
     *
     * ═════════════════════════════════════════════════════════════════════════════════════════
     * P0-TENANT-ROOT (2026-11-10) — "CREATED BY THE PLATFORM" IS NOT A TENANT.
     * ═════════════════════════════════════════════════════════════════════════════════════════
     *
     * Found by the project's `tenant-authz-reviewer` on the P0 deletion change, and then MEASURED
     * end to end through the real routes rather than reasoned about. It is a PRE-EXISTING defect;
     * nothing in the deletion change introduced it, and the deletion change is what made somebody
     * read this method.
     *
     * `User::creatorId()` returns `$this->id` only for type `admin` / `super admin`, and
     * `created_by` for everything else. Self-registration writes
     * `['type' => 'admin', 'created_by' => 1]` (RegisteredUserController :124/:133, :261/:269), so
     * EVERY merchant on the platform has `created_by = 1`. A principal whose type is anything else
     * therefore resolves `creatorId()` to 1 — and both limbs below then match every merchant on
     * the platform, plus user 1 itself.
     *
     * AND A MERCHANT CAN PUT THEMSELVES IN THAT STATE WITH TWO ORDINARY REQUESTS. Measured, each
     * line driven through the HTTP route and the real controller:
     *
     *     attacker id=2 type=admin created_by=1          creatorId() = 2
     *     POST a role named "Manager" in their own store (an ordinary merchant capability)
     *     PUT /users/{self} with role=<it>  -> 302       type now "Manager"
     *                                                    creatorId() = 1      <- collapsed
     *     sameTenant(attacker, OTHER MERCHANT) = true
     *     UserPolicy::update(attacker, that merchant) = true
     *     PUT /users/{that merchant} -> their email is now the attacker's
     *
     * `UserController::update` derives `type` server-side from a tenant-SCOPED role (:299-306),
     * which is the right shape, and `RolePolicy::RESERVED_NAMES` blocks only the two `admin`
     * spellings — so "Manager" is accepted and the demotion is a supported action, not a bypass.
     * Owning another merchant's email address owns every recovery flow, so this is cross-tenant
     * account takeover, reachable by any merchant.
     *
     * THE FIX IS ONE QUESTION: is the row this actor's tenant resolves to actually a tenant, or
     * is it the platform? A tenant root that is the platform operator is not a tenant, and an
     * actor rooted there is in NO tenant rather than in everybody's. Fail-closed, and it cannot
     * refuse the operator: `update()`, `resetPassword()` and `delete()` all admit
     * `isPlatformAdmin($actor)` BEFORE they reach this method, and an actor acting on their own
     * row returns true above.
     *
     * WHO LOSES AUTHORITY, stated so the blast radius is not a guess: only a non-operator whose
     * `creatorId()` is the operator — a self-demoted merchant (the attack) and a staff row the
     * OPERATOR created. The second is not a merchant's staff member; it belongs to no merchant
     * tenant, and treating it as a member of every tenant was the defect, not a feature.
     * A merchant (type `admin`) resolves to their own id and is unaffected; their staff resolve
     * to the merchant and are unaffected.
     *
     * Pinned by `tests/Feature/Security/TenantRootIsNotThePlatformTest`, which drives the whole
     * chain above through the routes and fails if any link in it reopens.
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

        if (! self::tenantRootIsAMerchant($actorTenant)) {
            return false;
        }

        // The target is either a row the tenant owner created, or the tenant
        // owner themselves.
        return (int) $target->created_by === (int) $actorTenant
            || (int) $target->id === (int) $actorTenant;
    }

    /**
     * Does this tenant root resolve to an actual merchant?
     *
     * A TENANT IS ROOTED AT A TYPE-`admin` USER, and `User::creatorId()` says so from the other
     * side: it returns `$this->id` for `admin`/`super admin` and `created_by` for everything else.
     * So a legitimate root is one of those two types, and `super admin` is the platform rather
     * than a tenant — every caller admits the operator before reaching `sameTenant()`.
     *
     * THE FIRST VERSION OF THIS ASKED THE NARROWER QUESTION "is the root the operator", and the
     * regression suite caught it being incomplete within the hour. In a fixture where the
     * collapsed actor's own id happens to equal their `created_by`, the root resolved to the
     * ACTOR — not the operator, so the narrow check passed it — and the two limbs below then
     * matched every row carrying that same `created_by`. Asking whether the root is a MERCHANT
     * closes that and the operator case together, because neither a demoted user nor the platform
     * is type `admin`.
     *
     * `withoutGlobalScopes()` deliberately: `User` is not in `config/tenancy.php`'s
     * `scoped_models`, so there is no scope to drop today — but a future scope must not be able to
     * turn this read into `null` and hand back "yes". A root that cannot be read at all is NOT a
     * merchant, which is the refusing direction.
     */
    protected static function tenantRootIsAMerchant(int|string $actorTenant): bool
    {
        $root = User::withoutGlobalScopes()->whereKey($actorTenant)->first();

        if ($root === null || self::isOperator($root)) {
            return false;
        }

        // Normalised the same way `isOperator()` normalises, for the same reason: MySQL's
        // utf8mb4_unicode_ci ignores a trailing space but not a leading one or a tab.
        return strtolower(rtrim((string) $root->type, ' ')) === 'admin';
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

        /* P2.5-SEC-VERIFY (2026-11-10) -- THROUGH THE SHARED HELPER, NOT THE LITERAL.
           This read `$target->type === self::PLATFORM_ADMIN`. MySQL's utf8mb4_unicode_ci treats
           `'Super Admin'` and `'super admin '` as EQUAL to that literal while PHP's `===` does
           not, so a row typed either way was the operator to the database and a stranger to this
           method. `delete()` was already using `isOperator()`; these three were not, and the
           asymmetry was an oversight rather than a decision.

           A TIGHTENING, NOT A REORDER. Normalising the TARGET test can only refuse more than it
           did; it admits nobody it previously refused, and it does not move the operator check
           past the permission check on any path -- which the owner's closure instruction
           explicitly forbids. Measured in both directions by
           `CrossTenantPolicyGridTest::test_no_path_may_act_on_a_platform_operator_however_the_row_is_spelled`. */
        // Nobody below platform-admin may edit a platform admin.
        if (self::isOperator($target)) {
            return false;
        }

        return $this->sameTenant($actor, $target);
    }

    /**
     * P0-DELETE. THE SAME DEFECT SEC-F4 FIXED ABOVE, LEFT OPEN ON THE DESTRUCTIVE OPERATION.
     *
     * `UserController::destroy()` was `if (auth()->user()->isAbleTo('Delete User')) { ...
     * $user->delete(); }` on a `User` bound from the URL -- the shape `update()` had before
     * SEC-F4, with no ownership test at all. `User` is not in `config/tenancy.php`'s
     * `scoped_models` (that list holds six models), so the global scope adds no predicate and
     * route-model binding reaches every tenant's users. A permission is not an ownership test.
     *
     * AND DELETE IS NOT EDIT. `users` has no soft delete and no observer, and `stores.created_by`
     * references `users.id` ON DELETE CASCADE (storesyria_ecommercego.sql:37441, declared at
     * database/migrations/..._create_stores_table.php:25), with twelve further foreign keys
     * cascading off `stores.id` -- `store_wallets`, `store_wallet_transactions`,
     * `store_withdrawals` and `store_order_financials` among them. Exactly one of the schema's 89
     * foreign keys is not CASCADE and none is RESTRICT, so the database never refuses. There is a
     * live button at resources/views/users/index.blade.php:124.
     *
     * The limbs mirror `update()` deliberately, so the two cannot drift:
     * permission first, then operator, then no-one-below-operator-touches-an-operator, then same
     * tenant.
     *
     * WHAT THIS DOES NOT DO. It does not refuse the deletion of a user who OWNS STORES -- a
     * merchant removing their own tenant root would still take the cascade with them. That is a
     * change to destructive-data behaviour rather than an authorization repair, and it is held
     * open as a release blocker for the owner's decision rather than decided here. Pinned by
     * tests/Feature/Security/UserDeletionAuthorizationTest.
     */
    public function delete(User $actor, User $target): bool
    {
        // OPERATOR FIRST, because `isAbleTo()` alone refuses the platform operator in this
        // codebase -- there is no super-admin bypass, operator status is a string comparison on
        // users.type. Measured before this limb existed: the operator could not delete anybody.
        if ($this->isPlatformAdmin($actor)) {
            return true;
        }

        if (!$actor->isAbleTo('Delete User')) {
            return false;
        }

        // Nobody below platform-admin may delete a platform admin.
        if (self::isOperator($target)) {
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

        /* P2.5-SEC-VERIFY (2026-11-10) -- THROUGH THE SHARED HELPER, NOT THE LITERAL.
           This read `$target->type === self::PLATFORM_ADMIN`. MySQL's utf8mb4_unicode_ci treats
           `'Super Admin'` and `'super admin '` as EQUAL to that literal while PHP's `===` does
           not, so a row typed either way was the operator to the database and a stranger to this
           method. `delete()` was already using `isOperator()`; these three were not, and the
           asymmetry was an oversight rather than a decision.

           A TIGHTENING, NOT A REORDER. Normalising the TARGET test can only refuse more than it
           did; it admits nobody it previously refused, and it does not move the operator check
           past the permission check on any path -- which the owner's closure instruction
           explicitly forbids. Measured in both directions by
           `CrossTenantPolicyGridTest::test_no_path_may_act_on_a_platform_operator_however_the_row_is_spelled`. */
        if (self::isOperator($target)) {
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

        /* Same normalisation as the three above, and it matters most here: SEC-F6 is platform
           takeover, and one operator account becoming another leaves no trace of which one
           acted. A `'Super Admin'` row was impersonable. */
        return ! self::isOperator($target);
    }
}
