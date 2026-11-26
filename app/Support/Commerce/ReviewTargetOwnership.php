<?php

namespace App\Support\Commerce;

use App\Models\Category;
use App\Models\Product;

/**
 * DOES THIS REVIEW NAME A PRODUCT AND CATEGORY OF THE STORE IT BELONGS TO? ONE DEFINITION.
 * =================================================================================================
 * GAP-193 / `CUST-005`, and the F1 finding of its own review.
 *
 * WHY THIS IS A SHARED CLASS AND NOT A SECOND COPY OF THE PREDICATE — THE FINDING ITSELF IS THE
 * ARGUMENT. `testimonials.product_id` has at least six writers. Five of them scope it; each does so
 * in its own words, inline:
 *
 *     Api\ApiController::add_review          Product::where('id', …)->where('store_id', $store->id)
 *     Api\ApiController::product_rating      the same, spelled again
 *     Api\DashboardController::AddReview     the same, spelled again
 *     TestimonialController::store()         GAP-193's guard
 *     TestimonialController::update()        GAP-193's guard
 *
 * **And `Api\DashboardController::UpdateReview` did not** — so the GAP-193 change, which fixed two
 * of the panel writers and then argued in its own docblock that "restoring the premise makes every
 * existing reader correct at once", was WRONG BY ONE WRITER. A copy is how a family of writers
 * drifts into disagreement, and this family has now demonstrated it. So the panel pair and the admin
 * API door ask ONE question, in one place, and a seventh writer has something obvious to call.
 *
 * WHAT IT PROTECTS, and why a mis-scoped column is worth a class. `Api\ApiController::product_detail()`
 * and `::product_detail_guest()` read a product's reviews by `product_id` ALONE, resting — correctly —
 * on the product having been store-verified. And `Testimonial::AvregeRating()` is
 * `Testimonial::where('product_id', $id)` with no store predicate followed by `Product::find($id)`
 * and `$Product->save()`. So a row naming a foreign product is both SERVED on the victim's product
 * page and, through `AvregeRating()`, a **cross-tenant WRITE to `products.average_rating`**. Measured
 * through `POST api/admin/updatereview` by a merchant acting on their OWN store and their OWN review:
 * store B's `average_rating` went **5 -> 1**, HTTP 200 `{"status":1}`.
 *
 * VALIDATES, DOES NOT DERIVE. The three API writers derive `category_id` from the product; doing that
 * here would change behaviour for the product-less testimonial, which the panel form supports —
 * `product_id` and `category_id` are both commented OUT of both panel validators, so a general store
 * testimonial with neither is an ordinary save. An absent or empty id is left exactly as it was, and
 * only a NON-EMPTY foreign one is refused. That also makes this safe on `UpdateReview`, whose writes
 * are each behind `$request->has(...)` and must stay optional.
 *
 * `filter_var` STRICTLY, never `is_numeric` or a cast: MySQL coerces `'5abc'` to 5 in a `WHERE`
 * comparison, and a cast turns junk into 0 — a store id some rows really carry, and literally
 * `OperatorStoreSelection::NO_STORE`. Same spelling as `DocumentDirection::ownRow()`.
 *
 * `Product` AND `Category` BOTH CARRY `StoreScope` AND ARE BOTH ABSENT FROM `config/tenancy.php`'s
 * `scoped_models`, so the global scope adds NO predicate for any principal and the explicit
 * `where('store_id', …)` below is the WHOLE control — not defence in depth.
 *
 * TWO RESIDUALS ON THE `exists()` READS, recorded because an independent review settled them and the
 * second one is a live hazard for test authors rather than for production:
 *
 *   1. `exists()` IS cached by `laravel-model-caching` (`Buildable.php:32-41`), but the cache key
 *      folds the bound values in (`CacheKey.php:312-342`), so store A's `true` cannot be served for
 *      store B. Verified: there is **no raw write to `products` or `categories` anywhere in
 *      production code** — every `DB::table('products'|'categories')` site in `app/` and `packages/`
 *      is a read. A future raw write to either table re-opens this.
 *   2. **The tests are the real exposure, and the list is ONE fixture, not three.** Under
 *      `CACHE_STORE=array` the store IS taggable, so a fixture that repoints a row with
 *      `DB::table()` and then drives one of these doors can read a stale `true`. The one dangerous
 *      site is `StockAttributionTest.php:740`, which raw-updates `store_id` with no `flushCache()`.
 *      **An earlier version of this note also named `TopSalesReportSuiteReachabilityTest.php:447`
 *      and `:487` and was wrong on both:** they update `category_id`/`brand_id`, not `store_id`, and
 *      each is immediately followed by `(new Product)->flushCache()`, so neither can change the
 *      truth value this guard asks. Corrected because an overstated hazard list is exactly what a
 *      future author trims — and then trims the real entry with it. Write such a fixture through
 *      Eloquent, or `(new Product)->flushCache()` / `(new Category)->flushCache()`: the category arm
 *      has the identical exposure, and only `Product` used to be named here.
 *
 * THE STORE ID MUST BE PRINCIPAL-DERIVED, AND EACH CALLER SAYS SO AT ITS CALL SITE:
 * `TestimonialController` passes `getCurrentStore()` (the session), `UpdateReview` passes
 * `$store->id` from `resolveOwnedStore()` (already ownership-checked). Passing
 * `$request->store_id` straight in would make this a comparison of the caller's own two values,
 * which CLAUDE.md records as not a tenant check at all. On `UpdateReview` a mutation swapping the
 * two SURVIVES -- `resolveOwnedStore()` has already verified the request value, so for any caller
 * that reaches the line they are the same store, and where they could differ (MySQL coercing
 * `'1abc'` to 1 in the lookup while `filter_var` refuses it) the swap is STRICTER, not weaker, and
 * SQLite's affinity cannot show it. Recorded as equivalent rather than chased, because `$store->id`
 * says at a glance that the value is principal-derived.
 *
 * THAT EQUIVALENCE HOLDS ONLY WHILE THE GUARD SITS **BELOW** `resolveOwnedStore()`. Hoisted above
 * it, `$request->store_id` becomes a comparison of the caller's own two values — which CLAUDE.md
 * records as not a tenant check at all — so the call site's ORDER is load-bearing, not incidental.
 *
 * ONE MERCHANT-FACING STRING CHANGED WHEN THIS CLASS WAS EXTRACTED, AND IT IS DISCLOSED HERE
 * BECAUSE NOBODY DISCLOSED IT AT THE TIME. The inline panel version read *"…so a testimonial cannot
 * be saved"*; this one reads *"…so a review cannot be saved"*. Not a regression — neither wording is
 * in `en.json` or `ar.json`, so no Arabic translation was lost, and "review" is arguably righter for
 * a class that serves three doors. But a user-facing wording change arriving as a side effect of a
 * refactor is the class of thing this project watches for, so it is recorded rather than left to be
 * noticed.
 *
 * @see \App\Http\Controllers\TestimonialController::foreignRowRefusal()
 * @see \Api\DashboardController::UpdateReview()
 * @see \Tests\Feature\Security\Gap193ReviewPurchaseGateAndTenancyTest
 */
final class ReviewTargetOwnership
{
    /**
     * The message to refuse with, or null when every named row belongs to this store.
     *
     * Returns a STRING rather than a bool so that one definition serves both envelopes: the panel
     * flashes it with `->with('error', …)` and the admin API puts it in `$this->error()`. Neither
     * may answer 401 — the shipped Flutter app force-clears its session on any 401.
     *
     * @param  int|string|null  $storeId     the store the review belongs to, derived from the
     *                                       PRINCIPAL or the route, never from request input
     * @param  mixed  $productId             as posted; an absent or empty value is not a refusal
     * @param  mixed  $categoryId            as posted; likewise
     */
    public static function refusalFor($storeId, $productId, $categoryId): ?string
    {
        $storeId = filter_var($storeId, FILTER_VALIDATE_INT);

        if ($storeId === false || $storeId <= 0) {
            return __('No store is selected, so a review cannot be saved. Open the store it belongs to and try again.');
        }

        $candidates = [
            [$productId, Product::class, __('The selected product is not available in this store.')],
            [$categoryId, Category::class, __('The selected category is not available in this store.')],
        ];

        foreach ($candidates as [$posted, $model, $message]) {
            // An absent or empty id is left alone: a review naming no product is an ordinary save,
            // and every caller writes these columns conditionally.
            if (empty($posted)) {
                continue;
            }

            $id = filter_var($posted, FILTER_VALIDATE_INT);

            if ($id === false || $id <= 0
                || ! $model::where('id', $id)->where('store_id', $storeId)->exists()) {
                return $message;
            }
        }

        return null;
    }
}
