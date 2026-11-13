<?php

namespace App\Support\Theme;

use App\Models\Store;
use App\Policies\UserPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

/**
 * PHASE 03 M3-C — WHO MAY TOUCH A STORE'S THEME CONFIGURATION, AND FOR WHICH STORE.
 * ================================================================================================
 *
 * THE DEFECT THIS EXISTS TO NOT INHERIT
 * ------------------------------------------------------------------------------------------------
 * `getCurrentStore()` is what every existing theme write uses, and it is not safe to build on.
 * Verbatim, `app/Helper/helper.php`:
 *
 *     $storeId = $user->current_store;
 *     if (! empty($storeId)) { return $storeId; }
 *     else {
 *         if ($user->type == 'super admin') { return 1; }       // "Default store for super admin"
 *         else { $store = Store::where('created_by', $user->id)->first();
 *                return $store ? $store->id : null; }
 *     }
 *     // and if $user is falsy the function returns NOTHING -- an implicit null
 *
 * Three measured consequences, each one a requirement here:
 *
 *   1. **An operator with an empty `current_store` is handed the literal `1`** -- and store 1
 *      belongs to a MERCHANT. A publish or rollback performed by the platform operator would write
 *      into that merchant's history and re-point their live storefront while appearing to act on
 *      the operator's own. No database constraint catches it, because store 1 is a real store and
 *      every foreign key is satisfied.
 *   2. A non-operator with no owned store gets **null**, and `where('store_id', null)` compiles to
 *      `store_id IS NULL`, so two different unresolved principals would share one bucket.
 *   3. An unauthenticated caller falls off the end and gets an implicit null, which looks identical
 *      to case 2.
 *
 * Also note `$user->type == 'super admin'` is a LOOSE, UN-NORMALISED comparison where this project
 * prescribes `strtolower(trim(...)) === 'super admin'` -- so an operator recorded as `Super Admin`
 * silently takes the merchant branch.
 *
 * WHAT THIS DOES INSTEAD
 * ------------------------------------------------------------------------------------------------
 * **It requires the store to be named explicitly and then proves the caller owns it.** There is no
 * "current store" fallback and no default. An operator with no store named gets a refusal, not
 * store 1.
 *
 * The ownership rule is the house one, copied rather than invented --
 * `Api\DashboardController::resolveOwnedStore()` and `StoreController::resolveOwnedStore()` are
 * identical in shape, and both normalise the type string, as do
 * `PlatformOperatorOnly::handle():61` and `PlanOrderPolicy::isPlatformAdmin()`.
 *
 * OPERATOR-FIRST ORDERING IS MANDATORY AND IS NOT OPTIONAL POLITENESS
 * ------------------------------------------------------------------------------------------------
 * This codebase uses Laratrust with `permissions_as_gates => false`, so `@can()` and `$user->can()`
 * are ALWAYS false, and there is **no super-admin bypass** -- `isAbleTo()` alone REFUSES the
 * platform operator. A permission check written as a bare `isAbleTo('Manage Themes')` is therefore
 * dead on arrival for the one principal most likely to need a support rollback. The operator is
 * tested first, by normalised string comparison on `users.type`.
 *
 * AND THE PERMISSION ITSELF IS HELD BY NOBODY
 * ------------------------------------------------------------------------------------------------
 * Measured at M3-A: the four theme READ actions gate on `isAbleTo('Manage Themes')`, a permission
 * **no role and no user holds in the fixture**, while the three WRITE actions
 * (`themeCustomizeUpdate`, `updateOrder`, `makeActiveTheme`) carry **no check at all** -- the
 * inversion recorded as `docs/discovery/03-FEATURE-MATRIX.md:223`, HIGH/P0. `Edit Theme`, which the
 * commented-out check named, does not exist as a permission either.
 *
 * So this gate accepts EITHER of the two permissions and treats the tenant ROOT as entitled: a
 * merchant who owns the store may edit their own storefront whether or not anyone has got round to
 * granting them a permission that no seeder creates. Narrowing that to a permission nobody holds
 * would lock every merchant out of their own theme editor, which is a worse failure than the one
 * being fixed and is not what "fail closed" means. The named permissions still gate STAFF.
 */
final class ThemeEditorGate
{
    /** Either of these entitles a user; neither exists in the fixture, hence `ownsStore()` below. */
    public const PERMISSIONS = ['Manage Themes', 'Edit Theme'];

    /** Why a request was refused, for the caller to log or surface. Never shown to a shopper. */
    private ?string $reason = null;

    public function reason(): ?string
    {
        return $this->reason;
    }

    /**
     * Resolve the store this principal may act on, or null.
     *
     * @param  int|string|null  $storeId  REQUIRED and explicit. There is no fallback.
     */
    public function resolveStore(mixed $storeId, ?Authenticatable $user = null): ?Store
    {
        $this->reason = null;
        $user ??= Auth::user();

        if (! $user) {
            $this->reason = 'no authenticated user';

            return null;
        }

        /*
         * FAIL CLOSED ON AN UNNAMED STORE. This is requirement 3 and residual risk 1 in one line:
         * no `getCurrentStore()` fallback, so an operator with an empty `current_store` is refused
         * rather than handed store 1. `'0'` is rejected too, because `empty('0')` is true in PHP and
         * a store id of 0 exists in no table -- the same falsy trap that made a store with slug `0`
         * unreachable in M0.
         */
        if ($storeId === null || $storeId === '' || (string) $storeId === '0') {
            $this->reason = 'no store was named; theme operations never infer one from the session, '
                . 'because getCurrentStore() hands an operator the literal store 1';

            return null;
        }

        if (! ctype_digit(ltrim((string) $storeId, '+'))) {
            /*
             * `WHERE id = '5abc'` returns row 5 on MySQL and no row on SQLite (P1-INV-14), so a
             * non-numeric id is refused here rather than handed to the query.
             */
            $this->reason = 'the store id is not numeric';

            return null;
        }

        $query = Store::query()->where('id', (int) $storeId);

        if (! $this->isOperator($user)) {
            /*
             * THE TENANT ROOT MUST BE A MERCHANT, NOT MERELY A NUMBER -- the correction a review
             * found here, and a real one.
             *
             * `creatorId()` returns `$this->id` for a type-`admin` row and `created_by` otherwise,
             * and EVERY merchant has `created_by = 1`. A merchant who demotes their own `type`
             * through the product -- `UserPolicy`'s docblock records this as a supported action,
             * since `RolePolicy::RESERVED_NAMES` blocks only the `admin`/`super admin` spellings --
             * therefore drops to a root of 1.
             *
             * This predicate compares `stores.created_by`, NOT `stores.id`. An earlier docblock here
             * argued a root of 1 was harmless because "store 1 is one store"; that confused the two
             * columns, and the one store it hands over is the PLATFORM OPERATOR'S: the installer
             * creates it with `'created_by' => $superadmin->id`
             * (`database/seeders/PermissionTableSeeder.php:41-47`), and the super admin is the first
             * user created. Measured in `ThemeEditorGateTest::
             * test_a_merchant_demoted_to_a_root_of_one_does_not_gain_the_operators_store`, which
             * fails without the guard below.
             *
             * `UserPolicy::tenantRootIsAMerchant()` is the predicate the house already built for
             * exactly this, after the same collapse was an account takeover
             * (`docs/security/P0-USER-DELETION-CASCADE.md` §7): a tenant root must be a type-`admin`
             * row, which excludes the operator AND the demoted user. It is reused rather than
             * copied, so there is one rule.
             */
            $root = $user->creatorId();

            if (! UserPolicy::tenantRootIsAMerchant($root)) {
                $this->reason = 'this principal resolves to no merchant tenant root';

                return null;
            }

            $query->where('created_by', $root);
        }

        $store = $query->first();

        if (! $store) {
            $this->reason = 'the store does not exist, or this principal does not own it';
        }

        return $store;
    }

    /**
     * May this principal edit the named store's theme configuration?
     *
     * Operator-first, then the store-ownership predicate, then entitlement.
     */
    public function allows(mixed $storeId, ?Authenticatable $user = null): bool
    {
        $user ??= Auth::user();
        $store = $this->resolveStore($storeId, $user);

        if (! $store || ! $user) {
            return false;
        }

        /* THE OPERATOR IS TESTED FIRST. isAbleTo() alone refuses them -- see the class docblock. */
        if ($this->isOperator($user)) {
            return true;
        }

        if ($this->ownsStore($user, $store)) {
            return true;
        }

        foreach (self::PERMISSIONS as $permission) {
            if (method_exists($user, 'isAbleTo') && $user->isAbleTo($permission)) {
                return true;
            }
        }

        $this->reason = 'this principal neither owns the store nor holds '
            . implode(' or ', self::PERMISSIONS);

        return false;
    }

    /**
     * The store a principal is acting on for THEIR OWN shop, when no id is in the request.
     *
     * The merchant web form posts no store id -- there is only ever one shop it could mean -- so a
     * resolver is unavoidable there. This is `getCurrentStore()` **with the operator branch
     * removed**, and nothing else:
     *
     *     current_store, when set                     -> that store          (same as before)
     *     a non-operator with no current_store        -> their own store     (same as before)
     *     an OPERATOR with no current_store           -> NULL, not store 1   (the change)
     *     no user                                     -> null                (explicit, not implicit)
     *
     * The merchant branch is kept deliberately. Dropping it too would refuse every merchant whose
     * `current_store` happens to be empty, which is a worse failure than the one being fixed and is
     * not what "fail closed" means -- the directive also warns against rewriting legacy tenancy
     * behaviour without measuring the callers, and this rewrites none of it: `getCurrentStore()` is
     * untouched and its other call sites are unaffected.
     *
     * `allows()` still proves ownership of whatever comes back, so this resolves a CANDIDATE and
     * never an entitlement.
     */
    public function storeIdForSelfService(?Authenticatable $user = null): ?int
    {
        $user ??= Auth::user();

        if (! $user) {
            return null;
        }

        $current = $user->current_store ?? null;

        if (! empty($current) && (string) $current !== '0') {
            return (int) $current;
        }

        if ($this->isOperator($user)) {
            /* THE WHOLE POINT. getCurrentStore() returns the literal 1 here, and store 1 belongs to
             * a merchant. An operator must name the store they mean. */
            $this->reason = 'the platform operator must name the store explicitly; theme operations '
                . 'never default to store 1';

            return null;
        }

        $owned = Store::query()->where('created_by', $user->id)->first();

        if (! $owned) {
            $this->reason = 'this principal owns no store';
        }

        return $owned?->id;
    }

    /**
     * ONE OPERATOR TEST, and it is the house's -- not a local copy.
     *
     * This used `strtolower(trim(...))`, which CLAUDE.md prescribes. `UserPolicy::isOperator()`
     * deliberately uses `rtrim` instead, and its docblock shows the measurement: MySQL's
     * `utf8mb4_unicode_ci` is PAD SPACE, so it folds a TRAILING space but not a LEADING one.
     * `' super admin'` is therefore NOT the operator to any SQL query, while `trim()` would have
     * said it was -- **wider than the engine**, in the direction that grants rather than refuses.
     *
     * Latent either way, because no write path produces that value. But this gate is the single
     * worst place to be wider than the database, since what it grants is cross-tenant access to
     * every store. The narrower, engine-matching predicate is used here, and the divergence from
     * CLAUDE.md's wording is deliberate and recorded rather than silent.
     */
    private function isOperator(Authenticatable $user): bool
    {
        return UserPolicy::isOperator($user);
    }

    /**
     * Is this user the tenant root of the store?
     *
     * `creatorId()` returns `$this->id` for a type `admin`/`super admin` row and `created_by`
     * otherwise, so a merchant and their staff resolve to the same root. The comparison is made
     * against `stores.created_by`, which is what `resolveOwnedStore()` filters on, so this agrees
     * with the predicate above rather than introducing a second notion of ownership.
     *
     * NOTE, because it has been an account-takeover here before: `created_by = 1` is NOT a tenant.
     * A merchant can demote their own `type` through the product, which drops `creatorId()` to 1 --
     * see `docs/security/P0-USER-DELETION-CASCADE.md` §7. That is why this compares the store's
     * `created_by` to the user's resolved root and does not treat a root of 1 as universal: store 1
     * is one store, so a demoted merchant matches only store 1, not every store.
     */
    private function ownsStore(Authenticatable $user, Store $store): bool
    {
        $root = method_exists($user, 'creatorId') ? (int) $user->creatorId() : 0;

        /* The same merchant-root requirement as `resolveStore()`. Both sites, or neither: a caller
         * that reached `allows()` by another route must not get a weaker rule. */
        return $root > 0
            && UserPolicy::tenantRootIsAMerchant($root)
            && (int) $store->created_by === $root;
    }
}
