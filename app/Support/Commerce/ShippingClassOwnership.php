<?php

namespace App\Support\Commerce;

use App\Models\Shipping;

/**
 * PHASE 02 LEDGER F114 — a shipping class id may not be written from caller input unless the
 * posted row belongs to the store the product belongs to.
 * =================================================================================================
 *
 * `docs/development/phase-2/PHASE-02-TASK-LEDGER.md:426`, RECORDED NOT FIXED since 2026-10-26, found
 * during the security review of the D-09 change on a line that change rewrote. **Not `P4-F114`**,
 * which is a closed 401 defect in a different register — two registers number findings from one
 * shared `F###` space with no namespace.
 *
 * NINE WRITERS READ A SHIPPING CLASS ID FROM THE REQUEST, and none validated it:
 *
 *     ProductController::store()    parent 3 sites, variant 1 site
 *     ProductController::update()   parent 2 sites, variant 1 site
 *     Api\DashboardController       AddProduct, UpdateProduct   (validated `nullable` only)
 *
 * The variant key family is `shipping_id_<combination>` — the suffix is
 * `str_replace('.', '_', $variant)`, arbitrary merchant text like `shipping_id_S-Red`, one key per
 * combination — so it is unbounded and cannot be enumerated in a validator. The question therefore
 * has to be asked where the value is read, and it is asked HERE, once, so the nine sites cannot
 * drift apart.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE GLOBAL SCOPE IS NOT THE CHECK
 *
 * `Shipping` carries `StoreScope` through `BelongsToStore`, so `getGlobalScopes()` answers
 * `[StoreScope]` and the model LOOKS protected. It is not in `config/tenancy.php`'s `scoped_models`,
 * so `StoreScope::isActiveFor()` short-circuits and it adds **no predicate for any principal** —
 * the same trap as `Product` and `Category`. And `null_tenant => 'open'` means an unresolved tenant
 * adds nothing either. The queries below are therefore written with their predicate explicit, and
 * `ProductShippingClassOwnershipTest` measures the premise against the booted container rather than
 * trusting this paragraph.
 *
 * -------------------------------------------------------------------------------------------------
 * THREE OUTCOMES, BECAUSE F283 ALREADY CHARGES DIFFERENTLY FOR THREE FACTS
 *
 * This guard had to be bounded by what the READER does, or closing it would have moved money. The
 * ledger's wording is *"and priced against"*, and that half is already closed on the read side:
 * `Api\ApiController::shippingClassOfStore()` (F283) distinguishes
 *
 *     null   "unclassified"                -> the caller charges `product_no_cost`
 *     false  "exists, not this store's"    -> the caller charges NOTHING (+0)
 *     model  this store's class            -> the caller charges its bucket
 *
 * and F283's own comment names this write hole as the still-open half: *"nothing validates that a
 * product's `shipping_id` belongs to its own store … and legacy rows already are"* in that state.
 * Two consequences, each of which rules out an obvious and wrong fix:
 *
 *   **A REFUSAL MUST NOT COERCE TO NULL.** `null` and `false` take DIFFERENT buckets, so rewriting a
 *   foreign id to null would move a charge from +0 to `product_no_cost`. The refusal therefore
 *   returns `$current` — the value already stored — which is the idiom
 *   `ProductController::ssVariantValue()` already uses for a blank, and which writes nothing new.
 *
 *   **AN ID THAT EXISTS NOWHERE MUST STILL PASS THROUGH.** F283 reads "unresolvable" as the no-cost
 *   bucket, so refusing it would move a price too. This guard asks the OWNERSHIP question only and
 *   is deliberately silent about existence.
 *
 * So the ONLY behaviour that changes is a cross-tenant reference being written. That is the defect,
 * and nothing else moves.
 *
 * -------------------------------------------------------------------------------------------------
 * THE NULL `store_id` ALLOWANCE IS NOT AN OVERSIGHT
 *
 * `shippings.store_id` is nullable (`2023_12_19_054936_create_shippings_table.php:19`) and legacy
 * rows may not carry it. F283's reader allows NULL explicitly, mirroring
 * `CartController::get_shipping_method()`'s P01-COMMERCE-SEC-01 predicate. A writer that refused
 * those rows would disagree with the reader and lock merchants out of their own legacy classes —
 * a lockout, not a tightening, which is the shape `FinancePermission` recorded for six finance
 * names. `ProductShippingClassOwnershipTest` drives a NULL-store class as a control.
 *
 * -------------------------------------------------------------------------------------------------
 * WHICH STORE, AND WHY NOT `getCurrentStore()`
 *
 * The caller passes the store; this class never resolves one, so it cannot be handed request input
 * by accident. The two call shapes:
 *
 *   `update()` passes **`$product->store_id`** — the PRODUCT'S store, not the caller's. That is the
 *   established pattern twenty lines below the variant writer, where the D-20 review found that
 *   stamping `getCurrentStore()` instead silently moved a store-7 product's variants to store 1,
 *   because `getCurrentStore()` returns the hardcoded literal **1** for a `super admin` with an
 *   empty `current_store` (`helper.php:636`).
 *
 *   `store()` passes **`getCurrentStore()`** — there the product is being created by this caller, so
 *   the caller's store IS the product's, which is the same reasoning the D-20 comment gives for
 *   leaving `store()`'s sibling line alone.
 *
 *   The admin API passes **`$store->id`** from `resolveOwnedStore($request->store_id)`, which has
 *   already verified ownership against the principal — so it is principal-derived, not the raw
 *   `store_id` from the request.
 *
 * MEASURED, so the operator question is settled rather than assumed: the platform operator cannot
 * reach ANY of these writers. Both `ProductController::update()` and `Api\DashboardController::
 * UpdateProduct` refuse them first with *"The platform operator can view a store's catalogue but
 * cannot change its products, prices, stock or variants"*. So the `getCurrentStore()`-returns-1 trap
 * cannot bite here today — but the store is taken from the product anyway, because that is correct
 * independently of which gate happens to run first, and a later relaxation of the catalogue rule
 * must not silently reintroduce it. The test pins that refusal so the reasoning cannot rot.
 *
 * -------------------------------------------------------------------------------------------------
 * DEFAULT-DENY WHEN NO STORE IS KNOWN
 *
 * With no store to vouch against, nothing can be verified, so a posted id is refused rather than
 * trusted. A store-less product is already broken, and the refusal keeps whatever it holds, so this
 * costs nothing real and is the direction the skill's rule 18 requires of a guard whose job is to
 * refuse.
 */
final class ShippingClassOwnership
{
    /**
     * The value a product or variant writer may store, given the store that owns the row.
     *
     * @param  mixed  $posted   the request value (`shipping_id`, or `shipping_id_<combination>`)
     * @param  mixed  $storeId  the OWNING store's id — never a raw request value
     * @param  mixed  $current  what is stored now, returned when the posted id is somebody else's
     * @return mixed
     */
    public static function keepOwned($posted, $storeId, $current = null)
    {
        /*
         * Not a claim on a shipping row: nothing posted, a cleared select, or the application's own
         * "same as parent" literal. These are passed through untouched — deciding what they mean is
         * the writer's business and this guard must not change it.
         */
        if ($posted === null || $posted === '' || $posted === 'same_as_parent') {
            return $posted;
        }

        /*
         * An array or object cannot be a select value. The old `!empty()` idiom would have let one
         * through to bind against a numeric column; refusing is both safer and closer to what the
         * form can actually produce.
         */
        if (! is_scalar($posted)) {
            return $current;
        }

        /*
         * STRICT PARSE, NOT `is_numeric`. `is_numeric` accepts `'5.9'` and rejects `'5abc'`, while
         * MySQL coerces `'5abc'` to row 5 in a `WHERE id = ?` comparison — so a value this guard
         * could not parse might still be an id by the time the database sees it. Anything that is
         * not cleanly an integer is therefore refused rather than guessed at.
         */
        $id = filter_var($posted, FILTER_VALIDATE_INT);

        if ($id === false) {
            return $current;
        }

        if (self::storeOwns($id, $storeId)) {
            return $posted;
        }

        /*
         * Exists, but is somebody else's -> refuse the write and keep what is stored.
         * Exists nowhere -> out of scope, and refusing it would move a price (see the class
         * docblock). The existence probe reads no columns.
         */
        return Shipping::where('id', $id)->exists() ? $current : $posted;
    }

    /**
     * Does this shipping class belong to this store?
     *
     * Asked of the ROWS, with the predicate explicit, because no global scope applies to this
     * model. The NULL allowance mirrors the reader; see the class docblock.
     *
     * @param  mixed  $storeId
     */
    private static function storeOwns(int $id, $storeId): bool
    {
        if (empty($storeId)) {
            return false;
        }

        return Shipping::where('id', $id)
            ->where(function ($q) use ($storeId) {
                $q->where('store_id', $storeId)->orWhereNull('store_id');
            })
            ->exists();
    }
}
