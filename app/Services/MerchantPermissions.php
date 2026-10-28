<?php

namespace App\Services;

use App\Models\User;
use App\Policies\UserPolicy;

/**
 * P2-07-13 / WS K13 — the one place that answers "what may this principal do in THIS store".
 * =================================================================================================
 *
 * WHY THIS EXISTS AND WHY IT IS NOT `allPermissions()`
 *
 * The established idiom for reading a principal's permission names is
 * `$user->allPermissions(['name'])->pluck('name')` (`RolePolicy::heldBy()`), and that is what this
 * class is built out of — with ONE correction it cannot make for itself.
 *
 * `allPermissions()` (vendor/santigarcor/laratrust/src/Traits/HasRolesAndPermissions.php:501) is
 * `$this->roles()->with('permissions')->get()` flat-mapped: it unions the permissions of EVERY
 * role the user holds, with NO store predicate anywhere in it. Laratrust's own team feature, which
 * would scope it, is disabled (`laratrust.teams.enabled` is false), so the `$team` argument is
 * dead. But `roles.store_id` exists and is indexed
 * (database/migrations/2023_12_08_100022_laratrust_setup_tables.php:25) and the application scopes
 * roles by it everywhere a merchant manages staff — `RoleController` and `UserController` both
 * read `Role::where('store_id', getCurrentStore())`. So a role IS per store, and
 * `allPermissions()` reads across all of them. For a merchant with two stores and one employee in
 * each, it reports the union: the employee of store A would be told they hold store B's
 * permissions too. That is the defect this class exists to not have, and it is the owner's
 * condition for this row — the permissions must belong to the current store.
 *
 * THE NULL STORE IS NOT A HOLE, it is the merchant themself. `PermissionTableSeeder` creates one
 * shared global `admin` role with `store_id` NULL, and it is that role — not a per-store one —
 * that both `RegisteredUserController` (:154) and `StoreController` (:379) hand every merchant,
 * for every store they create. So `store_id IS NULL` has to stay in the predicate or a merchant
 * would lose their own permissions the moment they were read per store. Staff roles, created
 * through `RoleController` with `store_id => getCurrentStore()`, are the ones the predicate
 * actually narrows. Measured, not assumed: `test_a_merchant_keeps_their_permissions_in_every_store`
 * fails without the null arm and `test_an_employee_of_another_store_holds_nothing_here` fails
 * without the store arm.
 *
 * THE OPERATOR COMES FIRST, because `isAbleTo()` REFUSES them — there is no super-admin bypass in
 * this codebase, operator status is a string on `users.type`, and CLAUDE.md records the ordering as
 * a standing rule. A permission list built on role membership alone would come back EMPTY for the
 * one principal who may do everything, and an app that hides what the list omits would then hide
 * every screen from the platform operator. `UserPolicy::isOperator()` is reused rather than
 * re-derived: it `rtrim`s SPACE only to match MySQL's PAD SPACE, a distinction `trim()` gets wrong,
 * and `OperatorCheckCensusTest` holds the line against new local copies of that comparison.
 *
 * `WILDCARD` IS THE OPERATOR'S ANSWER rather than a dump of the `permissions` table. It does not
 * depend on the table being seeded (a fresh install, or any test database, has no rows in it, and
 * an empty list would read as "may do nothing"), it is one rule for a client to implement, and it
 * is the same convention Sanctum already uses for an unrestricted token.
 *
 * THIS CLASS DOES NOT AUTHORIZE ANYTHING BY ITSELF. `allows()` is the server-side gate and
 * `names()` is what the client is told; a caller that only reports `names()` and then serves the
 * data anyway has built a hidden button, not a control.
 */
final class MerchantPermissions
{
    /** The operator's entry. A client reads it as "every permission". */
    public const WILDCARD = '*';

    /**
     * The permission names this principal holds in this store.
     *
     * PASS THE STORE WHOSE ROWS YOU ARE ABOUT TO SERVE, which is NOT always `current_store`.
     * `$storeId` is REQUIRED rather than defaulted for exactly that reason: almost every other
     * handler in `Api\DashboardController` keys its data on `resolveOwnedStore($request->store_id)`,
     * which admits ANY store in the caller's tenant by design. A gate that defaulted to
     * `current_store` would then evaluate store A's grants against store B's rows, and an employee
     * permitted in A would read B — the same defect shape as reading an id from request input,
     * arriving by omission instead. Making the caller name the store makes that mistake
     * unavailable rather than merely documented.
     *
     * @param  int|string|null  $storeId  the store the data belongs to
     * @return list<string>
     */
    public static function names($user, $storeId): array
    {
        // Customer and DeliveryBoy are NOT Laratrust subjects: `isAbleTo()` on a Customer is a
        // fatal, and a token belonging to either reaches handlers through `custom.auth`. Measured
        // the hard way -- an earlier version of this guard tested the guard rather than the class
        // and turned 112 storefront tests into 500s.
        if (! $user instanceof User) {
            return [];
        }

        if (UserPolicy::isOperator($user)) {
            return [self::WILDCARD];
        }

        $roles = $user->roles()->with('permissions:id,name')->get();

        $names = $roles
            ->filter(static function ($role) use ($storeId) {
                // The global role (null store) belongs to the merchant in every store; a
                // store-scoped role belongs only to its own store.
                if ($role->store_id === null) {
                    return true;
                }

                return $storeId !== null && (int) $role->store_id === (int) $storeId;
            })
            ->flatMap(static fn ($role) => $role->permissions->pluck('name'));

        // Direct `permission_user` grants carry no store of their own, so they are a grant to the
        // person rather than to a store, and `allPermissions()` counts them the same way.
        $direct = $user->permissions()->get(['permissions.id', 'permissions.name'])->pluck('name');

        return $names
            ->merge($direct)
            ->filter(static fn ($name) => is_string($name) && $name !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * THE GATE. Whether this principal may do this, in this store.
     *
     * @param  int|string|null  $storeId  the store the data belongs to — see names()
     */
    public static function allows($user, string $permission, $storeId): bool
    {
        return self::held(self::names($user, $storeId), [$permission]);
    }

    /** True when ANY of the named permissions is held — for a surface more than one role reaches. */
    public static function allowsAny($user, array $permissions, $storeId): bool
    {
        return self::held(self::names($user, $storeId), $permissions);
    }

    /**
     * The same question against a list ALREADY resolved, for a caller that needs several answers
     * from one principal.
     *
     * This exists because the first version of the dashboard gate said "resolved ONCE" in a
     * comment and then called `names()` FOUR times — once directly and once inside each of the
     * three `allows`/`allowsAny` calls — at two queries apiece. The comment was not true, which
     * this project treats as a defect in itself.
     *
     * @param  list<string>  $held  the output of names()
     * @param  list<string>  $wanted
     */
    public static function held(array $held, array $wanted): bool
    {
        if (in_array(self::WILDCARD, $held, true)) {
            return true;
        }

        foreach ($wanted as $permission) {
            if (in_array($permission, $held, true)) {
                return true;
            }
        }

        return false;
    }
}
