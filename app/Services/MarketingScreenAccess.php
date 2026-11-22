<?php

namespace App\Services;

use App\Models\User;
use App\Policies\UserPolicy;

/**
 * GAP-218 — WHO MAY READ A MARKETING LIST SCREEN.
 *
 * Four read doors gated on `$user->isAbleTo('Manage X')` ALONE. Laratrust has no super-admin
 * bypass, and on a database built from source no role holds those names, so the platform operator
 * was refused screens they are responsible for.
 *
 * THIS IS NOT A NEW DECISION. `CouponController::couponReadRefusal()` has carried exactly this
 * shape since an owner decision of 2026-10-28, and `FlashSaleController::flashSaleReadRefusal()`
 * copied it. This extracts the shape the two of them already prove, so the remaining doors stop
 * being the odd ones out.
 *
 * ----------------------------------------------------------------------------------------------
 * IT DELIBERATELY HAS NO TENANT-OWNER PASS, AND THAT IS THE WHOLE REASON IT IS NOT `ReportAccess`.
 *
 * `ReportAccess::permits()` admits a user whose `creatorId() === id` — the tenant owner — without
 * the permission row, on the stated grounds that a merchant created before a permission name
 * existed should not be locked out of their own screen. The analogy to these screens is tempting
 * and WRONG:
 *
 *     `SavedViewPolicy:84` declares `OWNER_ADMITTED_SCREENS = ['analytics']`, and its docblock at
 *     `:55` states that none of the Marketing screens belongs there and that this is DELIBERATE —
 *     admitting one would make SAVING a view more permissive than SEEING the list it describes.
 *     `SavedViewIsolationTest:219` pins that constant by name.
 *
 * So the owner pass is an OWNER DECISION, recorded the other way, and engineering does not take it
 * here. `MarketingScreenOperatorAccessTest` holds that line in both directions.
 *
 * ----------------------------------------------------------------------------------------------
 * READ ONLY. No write gate uses this. `FlashSaleTenancyTest:209` pins the operator being REFUSED a
 * flash-sale WRITE, non-vacuously — it grants `Edit Flash Sale` first so the operator limb is what
 * the assertion measures. Admitting the operator to a list is not admitting them to an edit.
 */
final class MarketingScreenAccess
{
    /** Flashed when the principal may not see the screen at all. */
    public const DENIED = 'Permission denied.';

    /** Flashed when the principal is entitled but no store is in scope to show. */
    public const NO_STORE = 'No store is selected.';

    /**
     * The message to flash, or NULL when the screen may be rendered.
     *
     * A STRING RATHER THAN A BOOL, because the two refusals are not interchangeable: one says
     * "you may not", the other says "you may, but choose a store first", and a merchant who reads
     * the wrong one goes looking for the wrong problem.
     */
    public static function refusal(?User $user, string $permission): ?string
    {
        if ($user === null) {
            // These routes sit behind `auth`, so this is defence in depth -- but
            // `CartController::abandon_carts_handled` called `->isAbleTo()` straight on a possibly
            // null user, so the guard is not theoretical for every caller.
            return self::DENIED;
        }

        $isOperator = UserPolicy::isOperator($user);

        if (! $isOperator && ! $user->isAbleTo($permission)) {
            return self::DENIED;
        }

        /*
         * THE STORE GUARDS ARE PART OF ADMITTING THE OPERATOR, NOT A SEPARATE CHANGE.
         *
         * `getCurrentStore()` hands an operator with no `current_store` the hardcoded literal 1,
         * and `getStoreById(1)` normally resolves -- so admitting the operator WITHOUT this would
         * serve them store 1's marketing data by default, to somebody who chose no store at all.
         * That is GAP-223(b). Both existing copies of this predicate carry the same two guards.
         */
        if ($isOperator && empty($user->current_store)) {
            return self::NO_STORE;
        }

        if (getStoreById(getCurrentStore()) === null) {
            return self::NO_STORE;
        }

        return null;
    }
}
