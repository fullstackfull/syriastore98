<?php

namespace App\Support;

use App\Models\User;

/**
 * WS C — who may see which merchant-dashboard region, answered in ONE place.
 * =============================================================================
 *
 * WHY THIS CLASS EXISTS, and it is not tidiness.
 *
 * `/dashboard` renders TWO files: `resources/views/dashboard.blade.php` (the
 * vendored WorkDo page) and `resources/views/dashboard/overview.blade.php`
 * (the Phase 02 region it `@include`s at the top). The route carries
 * `['auth','xss','setlocate','verified']` and NO permission middleware
 * (routes/web.php:398-400), so every principal on the `web` guard in the
 * tenant reaches both — including a staff member whose role grants only, say,
 * Manage Blog.
 *
 * The overview gates itself. The WorkDo page below it did not, and the two
 * therefore DISAGREED about the same question on one screen: the overview
 * withheld "Order value" from a staff member without `Manage Order` while
 * "Top Category — By Sales" and "Top Brand — By Sales" showed that same money
 * to them a few hundred pixels lower, and the overview withheld "Latest
 * orders" while "Recent Orders" listed the identical `$new_orders` rows. A
 * flag defined inside an `@include` is not visible to the parent, so the only
 * way the parent could ask the question was to answer it a second time — and a
 * second answer is how the two came apart in the first place.
 *
 * THE TWO RULES THIS CODEBASE GETS WRONG BY DEFAULT, both already paid for:
 *
 *  1. OPERATOR-FIRST. `isAbleTo()` ALONE REFUSES THE PLATFORM OPERATOR —
 *     operator status is a string on `users.type`, not a Laratrust role — so
 *     an order check that asks only `isAbleTo('Manage Order')` hides orders
 *     from the operator. That was F223, found on this very screen.
 *
 *  2. `Manage Products` is PLURAL. `Manage Product` singular exists NOWHERE:
 *     not in Utility's seeded `$companyNewPermission`, not in the permissions
 *     table, not in any module seeder. A permission with no row can be held by
 *     no role, so the singular spelling evaluates FALSE for everybody,
 *     including the tenant owner. `Tests\Unit\Dashboard\DashboardPermissionNamesTest`
 *     is the ratchet that keeps the names real.
 *
 * The two spellings being different is NOT a typo in one of them: `Manage
 * Order` is singular and `Manage Products` is plural, and both are the real
 * seeded row names.
 *
 * `@can()` would be silently false here — this project sets Laratrust's
 * `permissions_as_gates => false` — so these go through `isAbleTo()`, never
 * through Laravel's Gate.
 *
 * ONE DELIBERATE CHANGE IN BEHAVIOUR, stated rather than slipped in: the
 * overview's `$ssMayOrders` was operator-first (F223 made it so) but its
 * `$ssMayProducts` and `$ssMayCustomers` were not. All three are now, because
 * the rule is the codebase's, not this screen's. On `/dashboard` the
 * difference is unobservable — `HomeController::index()` routes `super admin`
 * to `superadmin.dashboard`, so the operator never renders this view — and
 * where it IS observable the change withholds nothing it used to show.
 */
final class MerchantDashboardAbilities
{
    public const PERMISSION_ORDERS = 'Manage Order';

    public const PERMISSION_PRODUCTS = 'Manage Products';

    public const PERMISSION_CUSTOMERS = 'Manage Customer';

    public const PERMISSION_STORE_SETTINGS = 'Manage Store Setting';

    /**
     * Orders, and the money derived from them.
     *
     * Operator-first, for the reason in the class docblock. The operator does
     * not in fact reach `dashboard.blade.php` — `HomeController::index()` sends
     * `super admin` to `superadmin.dashboard` — but this method is also called
     * from `top_brand_category_chart()`, which any authenticated principal can
     * POST, so the ordering is load-bearing there even though it is belt-and-
     * braces on the page itself.
     */
    public static function mayManageOrders(): bool
    {
        return self::holds(self::PERMISSION_ORDERS);
    }

    public static function mayManageProducts(): bool
    {
        return self::holds(self::PERMISSION_PRODUCTS);
    }

    public static function mayManageCustomers(): bool
    {
        return self::holds(self::PERMISSION_CUSTOMERS);
    }

    /**
     * The onboarding checklist's "Name your store and set its details" step.
     *
     * It was the LAST bare `isAbleTo()` on the dashboard, and it carried F223's defect
     * unchanged: an operator with no Laratrust role was shown the step greyed out on a store
     * they own the platform of. `Manage Store Setting`, SINGULAR on both words, is the seeded
     * name — `app-setting.index` is the route it leads to.
     */
    public static function mayManageStoreSettings(): bool
    {
        return self::holds(self::PERMISSION_STORE_SETTINGS);
    }

    /**
     * Operator status is a string comparison on `users.type`, lower-cased and
     * trimmed because the column is free text and has been seen both ways.
     */
    public static function isOperator(): bool
    {
        $user = auth()->user();

        /* `type` is read rather than called, so a principal without the attribute yields null and
           compares false — but the same `instanceof` narrowing is applied here anyway, because a
           `Customer` row with a `type` column of its own would otherwise be asked a question
           about the merchant platform. */
        return $user instanceof User
            && strtolower(trim((string) $user->type)) === 'super admin';
    }

    /**
     * THE `instanceof User` GUARD IS NOT DEFENSIVE PROGRAMMING — IT IS A RECORDED INCIDENT.
     *
     * `isAbleTo()` comes from Laratrust's `HasRolesAndPermissions`, which ONLY `App\Models\User`
     * uses. `Customer` and `DeliveryBoy` do not have the method, so calling it on one is a fatal,
     * not a `false`.
     *
     * "But `auth()` with no argument is the `web` guard and a customer never lands there" is the
     * exact reasoning that failed last time. It is true of `CustomAuth`, which logs a `Customer`
     * into the `customers` guard — and false of `actingAs($customer)`, which puts a Customer on
     * `web` because Laravel never type-checks the model against the provider. When
     * `MerchantPageDirectory` made this call without the guard, the fatal landed on the
     * STOREFRONT through a global middleware and turned **112 security tests into 500s**
     * (P2-06-7 in the Phase 02 ledger). This class is called from a Blade include and from a
     * controller rather than from middleware, so the blast radius is smaller — but the call is
     * identical and so is the fix.
     */
    private static function holds(string $permission): bool
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            return false;
        }

        return self::isOperator() || $user->isAbleTo($permission);
    }
}
