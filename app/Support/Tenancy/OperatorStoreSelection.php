<?php

namespace App\Support\Tenancy;

use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Log;

/**
 * THE PLATFORM OPERATOR WHO HAS CHOSEN NO STORE -- GAP-223(b), owner direction of 2026-11-21.
 *
 * `getCurrentStore()` (`app/Helper/helper.php`) ends in a HARD-CODED LITERAL 1 for any user whose
 * `type` is `'super admin'` and whose `current_store` is empty. Store 1 is not a platform scope: it
 * is an ordinary merchant's tenant, and this project's own words at `StoreFinanceController:833` are
 * that "store 1 belongs to a merchant who did not ask for it". So the default was not "show
 * everything" -- it was "show one unlucky merchant's books to whoever logs in without choosing".
 *
 * THE OWNER'S RULE: an operator with no explicitly selected store must NEVER default to viewing
 * store 1's tenant data. The merchant-owned-store fallback stays (a merchant who owns a store but
 * never switched to one is legitimately resolved by ownership), and platform-wide figures keep their
 * own separately authorized, clearly labelled path (`PlatformAnalyticsController`, P5-7). Nothing
 * here broadens access: every outcome is narrower than the literal 1 it replaces.
 *
 * WHY `NO_STORE = 0` AND NOT `null`.
 *
 * `null` is the obvious choice and it is WRONG here, measurably so. `where('store_id', null)`
 * compiles to `store_id IS NULL`, and this database HAS rows with a null `store_id` -- there is a
 * standing report script for them (`tests/Support/Security/bin/report-null-store-orders.php`). So a
 * null sentinel would not match nothing; it would match the orphaned rows, which is a different leak
 * rather than a refusal. `stores.id` is a positive auto-increment, so 0 is unreachable by any real
 * store and `where('store_id', 0)` is the only value that provably selects nothing.
 *
 * THE TWO SHAPES, because the surfaces differ and a refusal has to fit the caller.
 *
 *  - A VIEW handler refuses: a redirect carrying `session('error')`, which is exactly what
 *    `StoreFinanceController` already does at nine call sites and what
 *    `ReportAuthorizationTest::refused()` recognises. SAFE TO ADD, verified: that test builds its
 *    operator with `['current_store' => $store->id]`, so it pins the operator who HAS chosen a
 *    store -- a disjoint principal from the one this guard fires on. Its twelve assertions are
 *    untouched.
 *  - A JSON chart endpoint narrows its SCOPE instead. It keeps HTTP 200 and its own key set, built
 *    by its own code over zero rows, so every series comes back as a correctly-shaped empty array.
 *    That matters twice: the six chart views test their series with `Array.isArray`, which is false
 *    for a string or a null, and the shipped Flutter app FORCES A LOGOUT ON ANY 401 -- so inventing
 *    a status here would be a user-visible regression in a different product.
 */
final class OperatorStoreSelection
{
    /**
     * A store id that matches no row. `stores.id` is a positive auto-increment, so no store can
     * carry it, and unlike `null` it cannot collide with the orphaned null-`store_id` rows this
     * database really holds.
     */
    public const NO_STORE = 0;

    /** Has the caller operator status AND no explicitly selected store? */
    public static function unchosen($caller = null): bool
    {
        $caller ??= auth()->user();

        /*
         * OPERATOR FIRST, and `UserPolicy::isOperator()` rather than a fresh comparison: it rtrims
         * trailing SPACE only, matching MySQL's PAD SPACE collation, so `' Super Admin '` is the
         * operator here exactly as it is everywhere else. A re-spelled check is how the operator
         * limb drifts out of agreement with the helper it is guarding.
         */
        return $caller !== null
            && UserPolicy::isOperator($caller)
            && empty($caller->current_store);
    }

    /**
     * The store id a REPORT may read.
     *
     * For everyone else this is `getCurrentStore()` unchanged -- including the merchant-owned-store
     * fallback, which is legitimate and stays. For an operator who has chosen nothing it is
     * `NO_STORE`, which selects no rows at all rather than store 1's.
     */
    public static function reportStoreId(): int
    {
        if (self::unchosen()) {
            return self::NO_STORE;
        }

        return (int) getCurrentStore();
    }

    /**
     * Record the refusal. Not a security control -- the control is the caller's early return -- but
     * an operator who sees an empty screen with no explanation files a bug against the report, and
     * the log is what tells the next reader it was a deliberate refusal.
     */
    public static function logRefusal(string $handler): void
    {
        Log::warning('Report refused: operator has selected no merchant store', [
            'handler' => $handler,
            'user_id' => auth()->id(),
        ]);
    }
}
