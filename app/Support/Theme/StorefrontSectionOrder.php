<?php

namespace App\Support\Theme;

use App\Models\ThemeCustomizeOrder;

/**
 * GAP-099 / P03-B-06 — THE READ HALF OF SECTION ORDERING.
 * =================================================================================================
 *
 * WHAT WAS MISSING, MEASURED BEFORE THIS CLASS EXISTED. `ThemeCustomizeController::updateOrder()`
 * has always written `theme_customize_orders` keyed on `(store_id, theme_id, page_slug)`, with the
 * posted tokens validated against the theme. NOTHING READ IT BACK FOR RENDERING. A census over
 * `app/`, `themes/`, `resources/`, `config/` and `routes/` with PHP and Blade comments STRIPPED
 * found four files naming the model, the resolver or the table — `config/tenancy.php`, the model
 * itself, the editor controller, and the editor view — and `ThemeCustomizeOrder::resolveFor()` had
 * exactly ONE caller: `ThemeCustomizeController::changeOrder()`, the editor screen redisplaying
 * what it had just saved. So a merchant dragged sections, got *"Order change successfully."* and the
 * shop rendered unchanged. The write half worked; the read half did not exist.
 *
 * WHY THIS DOES NOT CALL `ThemeCustomizeOrder::resolveFor()` — AND THE FIRST VERSION OF THIS NOTE
 * WAS WRONG ABOUT IT, IN THE DIRECTION THAT MATTERS. It claimed the global scope would hand a
 * storefront renderer `null` for the store it is rendering, quoting the model's own docblock:
 * *"a storefront renderer handed a slug — gets `null` from the scope and would read it as 'no
 * ordering chosen' rather than 'filtered'."* That hazard is NOT LIVE, and a mutation proved it:
 * putting the scope back (`ThemeCustomizeOrder::query()`) left all eleven end-to-end tests GREEN.
 *
 * MEASURED FROM INSIDE A REAL STOREFRONT REQUEST, with a merchant who owns store 2 loading store
 * 1's shop — the exact case the claim was about:
 *
 *     StoreScope::resolveTenant()  mode=tenant  store_ids=[1,2]
 *     reason="storefront request; slug-resolved store 1 admitted alongside the owned stores"
 *     a scoped read of store 1's row  =>  'testimonial,bestseller,category,product,slider'
 *
 * `StoreScope::resolveTenant()` has a STOREFRONT BRANCH (`app/Scopes/StoreScope.php:656-686`) that
 * admits the slug-resolved store alongside the owned ones, and its own comment at `:640` names
 * `ThemeCustomizeOrder` as one of the eight models it exists for, because *"this project has
 * shipped a storefront-blanking control twice already."* `storefrontStoreId()` resolves from the
 * `storeSlug` route parameter, which every route that renders `main_file` carries — the
 * `theme-draft/{storeSlug}/{version}/preview` path included. Both storefront modes (`slug_only` and
 * the default owned-plus-slug) admit it. So `resolveFor()` would work today.
 *
 * THE EXPLICIT PREDICATE STAYS ANYWAY, and for a stated reason rather than caution — the same
 * reason the model keeps its own: it is config-independent. `resolveFor()`'s correctness here rests
 * on `tenancy.enforcement_enabled`, on `tenancy.storefront_tenant_mode`, and on
 * `storefrontStoreId()` matching the slug — three settings outside this file, two of them operator
 * -tunable, and CLAUDE.md's rule is that the scope must never be a handler's only tenant check.
 * This is defence in depth, NOT the fix for a live defect, and it must not be described as one.
 *
 * THE CALLER CONTRACT, because failing closed inside this class is not enough. `$storeId` must be
 * the store the RENDERER resolved — `ViewServiceProvider`'s own `$store`. It must never come from
 * request INPUT, which would make the predicate a comparison of the caller's own two values rather
 * than a tenant check.
 *
 * AN EARLIER VERSION OF THIS PARAGRAPH SAID "never from `getCurrentStore()`", AND THAT WAS FALSE
 * OF ONE OF THE THREE LIVE CALLERS — corrected after an independent review measured it rather than
 * deleted, because the sentence above it ("every route that renders `main_file` carries
 * `storeSlug`") was wrong in the same way and a reader needs to know which claim moved.
 *
 * `packages/workdo/ThemeCustomizer/.../routes/web.php:23` declares
 * `theme-preview-content/{theme?}` with NO `storeSlug`, and its controller does
 * `getStoreById(getCurrentStore())`. So on that route `ViewServiceProvider:63-68` takes its SECOND
 * branch, `Store::find(getCurrentStore())`, and the ordering store really is the caller's own
 * `current_store`.
 *
 * THAT IS NOT AN EXPOSURE, and the reason is where the confinement actually lives: the only
 * first-party writer of `current_store` a caller can drive is `StoreController::changeStore()`,
 * which confines a non-operator to their own tenant through `UserPolicy::merchantTenantRoot()`
 * (P1-SEC-66). Every other datum on that preview page — products, categories, blogs,
 * `$themeSettings` — already derives from the same id, so the ordering string adds nothing to a
 * page that is wholly that store's already. The contract is therefore "the renderer's store",
 * which is the route's store on a storefront route and the caller's own on that preview.
 *
 * THE PRECEDENCE RULE IS `resolveFor()`'s, deliberately duplicated rather than shared: a
 * theme-specific row wins, and a legacy `theme_id IS NULL` row is the fallback for any theme with
 * no row of its own. Sharing it would mean exporting a scope-free query from the model, which is
 * the thing the model's docblock argues against.
 */
final class StorefrontSectionOrder
{
    /**
     * The block sequence a storefront page should render, after applying the merchant's saved order.
     *
     * `$blocks` is the theme's own default sequence of top-level blocks, in file order, declared by
     * the theme's `main_file.blade.php` beside the markup it names — so the sequence and the markup
     * cannot drift. `$orderable` is the subset a merchant may permute; a block outside it (a module
     * hook anchor, a newsletter strip the theme does not expose as a section) keeps its slot.
     *
     * @param  list<string>  $blocks
     * @param  list<string>  $orderable
     * @return list<string>
     */
    public static function sequence(
        ?int $storeId,
        string $themeId,
        string $pageSlug,
        array $blocks,
        array $orderable
    ): array {
        return self::permute($blocks, $orderable, self::savedOrdering($storeId, $themeId, $pageSlug));
    }

    /**
     * ONLY THE SECTIONS THE SAVED ORDER NAMES MOVE, AND THEY MOVE ONLY AMONG THE SLOTS THEY ALREADY
     * OCCUPY. This is the whole of the ordering rule, and the alternative is worse in a way that
     * would have shipped as a surprise.
     *
     * The obvious rule — "named sections first, in saved order, then the rest" — relayouts the page
     * for every store that has ever saved an ordering, because the shipped `orders` strings DO NOT
     * NAME EVERY SECTION. Measured against the real theme data:
     *
     *     stylique/home   declares 9 tokens; 5 of them name a top-level block of main_file.blade.php
     *                     (slider, category, product, bestseller, testimonial). The other 4
     *                     (top_category, more_offer, article, logos) name no top-level block, and
     *                     4 real blocks (logo, collection, blog, service) are not declared at all.
     *     techzonix/home  declares the same 9; 6 name a block (slider, category, product,
     *                     more_offer, article, testimonial); collections and logo are undeclared.
     *     greentic/home   carries no `is_order`, so nothing can be saved for it.
     *
     * Under "named first" a merchant who had saved the theme's own shipped default would find
     * `logo`, `collection`, `blog` and `service` shunted to the bottom of their home page the day
     * this class shipped. Under the slot rule the same row moves `product` and `category` past each
     * other and nothing else — because the shipped string happens to list `category` before
     * `product` while the Blade renders product first — which is a one-swap change a merchant can
     * look at and understand.
     *
     * The permutation is total and injective: `$slots` is the ascending list of positions held by
     * named blocks and `$named` has exactly one entry per such position, so every block is emitted
     * exactly once. Saving the default order therefore reproduces the default page byte for byte,
     * which is the property that makes this safe to switch on for every existing store.
     *
     * A token that names no block of this theme is DROPPED rather than refused. `updateOrder()`
     * accepts "what the theme declares" UNION "the sections that really exist", so a stored row can
     * legitimately hold a token this page has no block for; refusing the whole row over one such
     * token would discard an ordering the merchant really chose.
     *
     * @param  list<string>  $blocks
     * @param  list<string>  $orderable
     * @return list<string>
     */
    public static function permute(array $blocks, array $orderable, ?string $saved): array
    {
        $blocks = array_values($blocks);

        if ($saved === null || trim($saved) === '') {
            return $blocks;
        }

        $movable = array_values(array_intersect(array_values($orderable), $blocks));

        if ($movable === []) {
            return $blocks;
        }

        $named = [];

        foreach (explode(',', $saved) as $token) {
            $token = trim($token);

            /* Duplicates are collapsed for the same reason `updateOrder()` collapses them: a
             * reorder is a permutation, so a repeated token is a client bug and not an intent to
             * render a section twice. A row written before that validation existed can still hold
             * one. */
            if ($token !== '' && in_array($token, $movable, true) && ! in_array($token, $named, true)) {
                $named[] = $token;
            }
        }

        if (count($named) < 2) {
            /* Nothing to permute: one named block stays in its own slot, and zero named blocks
             * means the row holds only tokens this theme has no block for. */
            return $blocks;
        }

        $slots = [];

        foreach ($blocks as $index => $block) {
            if (in_array($block, $named, true)) {
                $slots[] = $index;
            }
        }

        foreach ($slots as $position => $index) {
            $blocks[$index] = $named[$position];
        }

        return $blocks;
    }

    /**
     * The stored ordering string for one store's page under one theme, or null.
     *
     * `withoutGlobalScopes()` WITH AN EXPLICIT `store_id` PREDICATE, for the reason in the class
     * docblock: the scope answers for the `web` guard, and this is read on behalf of the store in
     * the route. The predicate is the tenant boundary here, and it is the only one — which is why
     * a non-positive id returns null rather than becoming `where store_id = 0` next to a
     * `whereNull` that would match every ownerless row.
     */
    public static function savedOrdering(?int $storeId, string $themeId, string $pageSlug): ?string
    {
        if ($storeId === null || $storeId <= 0 || $themeId === '' || $pageSlug === '') {
            return null;
        }

        $base = static fn () => ThemeCustomizeOrder::withoutGlobalScopes()
            ->where('store_id', $storeId)
            ->where('page_slug', $pageSlug);

        /*
         * THIS READ MUST NEVER BE THE REASON A SHOP DOES NOT RENDER.
         *
         * It is the first database read these three templates perform during render, so an
         * unwrapped failure here answers 500 for the home page of EVERY store rather than falling
         * back to the theme's own order. Added after an independent review pointed out that the
         * codebase already contemplated exactly this case twice and not here:
         * `StoreScope::storefrontStoreId()` wraps its own lookup with *"A scope must never be the
         * reason a request fails"*, and the `theme_id` migration tolerates a missing table because
         * *"an installation that has never had it has nothing to order."*
         *
         * HARDENING, NOT A LIVE DEFECT: a first-party migration creates the table, so a fully
         * migrated install always has it. But the fallback already exists for every other failure
         * in this class — return null, render the theme's own order — and the alternative failure
         * mode is the whole storefront. Deliberately catching `Throwable` and not just
         * `QueryException`: a `Cachable` model can fail in the cache layer too, and the correct
         * answer to any of it is the same.
         */
        try {
            $row = $base()->where('theme_id', $themeId)->first()
                ?? $base()->whereNull('theme_id')->first();
        } catch (\Throwable $e) {
            report($e);

            return null;
        }

        return $row === null ? null : (string) $row->orders;
    }
}
