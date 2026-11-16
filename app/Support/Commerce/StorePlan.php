<?php

namespace App\Support\Commerce;

use App\Models\Store;
use App\Models\User;

/**
 * WHOSE PLAN DECIDES THIS STORE'S MONEY.  P4-F014 / P4-F023 / P4-F024.
 * ====================================================================
 *
 * Twelve order writers resolved the plan that governs their shipping arithmetic like this:
 *
 *     $user = Cache::remember('admin_details', 3600, function () {
 *         return User::where('type','admin')->first();
 *     });
 *     if ($user->type == 'admin') { $plan = Plan::find($user->plan_id); }
 *
 * `User::where('type','admin')->first()` is **the first merchant in the shared database, chosen by
 * having the lowest id**. It has nothing to do with the store being bought from. So one arbitrary
 * tenant's subscription decided whether every other tenant's orders carry a delivery charge — and
 * because the result is cached under a single global key, which tenant that is does not even change
 * between requests.
 *
 * THE SHOPPER WAS QUOTED FROM THE RIGHT PLAN. That is what makes this a divergence inside one
 * request rather than merely a wrong number: `CartController::cart_list_sidebar:38-40` resolves
 * `User::find($store->created_by)` and that store owner's plan to decide whether the cart shows a
 * shipping row, and `PaymentController::processOrder:1845-1846` does the same before handing the
 * figure to `processShipping()`. Only the ORDER WRITER re-resolved it, from the wrong tenant. So
 * the order was written at a different total from the one the shopper was shown and, for COD, from
 * the one the driver was told to collect.
 *
 * THIS IS THE THIRD INSTANCE OF ONE SHAPE, which is why it is a seam and not twelve edits:
 *
 *   - `ThemeLanguage` fell back to the same `User::where('type','admin')->first()->language`, so
 *     every storefront answered commerce AJAX in the FIRST merchant's language. Fixed in 073de166.
 *   - `OrderTotalCouponTest` and its siblings already have to call `Cache::forget('admin_details')`
 *     in their fixtures — the suite knew the key was load-bearing and never asked whose row it was.
 *   - and these twelve writers.
 *
 * A single-tenant test cannot see any of them: when there is only one merchant, the first
 * `type='admin'` row IS the store's owner. It takes a second tenant whose plan DISAGREES.
 *
 * WHAT THIS DELIBERATELY DOES NOT CHANGE. Each call site keeps its own predicate — most read
 * `if ($user->type == 'admin')`, two of them null-guard it first — because that predicate decides
 * whether a plan exists at all, and "no plan means shipping is off" is existing behaviour on paths
 * that already resolve the owner correctly. All this changes is WHICH USER is asked. Widening or
 * narrowing the "is there a plan" rule is a separate decision with its own money consequences.
 *
 * `Cache` is not used. The old key cached one global row for an hour; a per-store answer keyed on a
 * global name is how this defect would come back. The read is `User::find()` on a primary key, and
 * `User` is `Cachable`, so it is already cheap.
 *
 * AND THERE IS NO STATIC MEMO, which the first version of this class had and which was a defect of
 * exactly the kind this codebase records under process-global pollution. `RefreshDatabase` restarts
 * ids at 1 for every test, while a static keyed on `store_id` survives the whole PHPUnit process --
 * so test N memoised store 1's owner and test N+1 got that stale `User` for its own, different
 * store 1. It surfaced as `PreTransactionStockGiveBackTest::test_the_plan_throw_happens_before_any_stock_is_taken`
 * failing in the full Feature suite and PASSING in isolation: its `breakThePlanLookup()` nulls
 * `plan_id`, flushes the `User` cache and forgets `admin_details`, and a static memo answers none of
 * those. I first wrote that off as a suite read across a mid-flight edit; it reproduced on a clean
 * tree, which is what made it worth chasing. Caching here would have to be request-scoped to be
 * safe, and the read is too cheap to be worth it.
 */
final class StorePlan
{
    /**
     * The merchant who owns this store — the user whose subscription governs it.
     *
     * @param  \App\Models\Store|int|null  $store
     */
    public static function ownerOf($store): ?User
    {
        $storeId = $store instanceof Store ? $store->id : $store;
        $createdBy = $store instanceof Store ? $store->created_by : null;

        if (empty($storeId)) {
            return null;
        }

        if (empty($createdBy)) {
            $createdBy = Store::withoutGlobalScopes()->whereKey($storeId)->value('created_by');
        }

        return empty($createdBy) ? null : User::find($createdBy);
    }

    /*
     * THERE IS DELIBERATELY NO `forStore(): ?Plan` HERE, and the reason is the promise above.
     *
     * A first draft of this class had one, resolving the owner and then applying `type == 'admin'`
     * itself. Independent review pointed out that it had NO production caller -- all thirteen sites
     * keep their own predicate -- and that wiring it would contradict the paragraph above: baking
     * the rule into the seam IS narrowing or widening "is there a plan", which is a separate
     * decision with its own money consequences. An uncalled method whose rule disagrees with the
     * thirteen live ones is a hazard rather than a convenience, so it is gone.
     */

}
