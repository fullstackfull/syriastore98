<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Policies\UserPolicy;

/**
 * F195 / D-28c — THE ONE AUTHORITATIVE VARIANT-DELETION POLICY.
 * =================================================================================================
 *
 * WHY THIS CLASS EXISTS, in the owner's words: *"one authoritative deletion policy across every
 * reachable path — via a shared service or policy. No duplicated reference-check implementation,
 * and no calling a web controller from an API controller as a workaround."*
 *
 * THE THREE FIRST-PARTY DOORS that reach `ProductVariant::delete()`:
 *
 *   PUT    product/{id}                   ProductController::update()
 *   DELETE product-attribute-delete/{id}  ProductController::product_attribute_delete()
 *   DELETE api/admin/deletevariant        Api\DashboardController::DeleteVariant()
 *
 * Until this class, the reference checks lived in a **private** method of the web controller, so
 * the API door had none — its own comment recorded the gap and named the three ways out, all of
 * them worse than recording it: duplicate the queries (two copies of a control is how a guard and
 * the delete it guards come to ask different questions, and this project has a row for exactly
 * that), make the method public and instantiate a web controller from an API one (the workaround
 * the owner ruled out), or extract. This is the extraction.
 *
 * THE CONTROLLERS KEEP THEIR OWN THIN WRAPPERS, deliberately. `ProductController` still has
 * `ssRefuseVariantRemoval()` and `ssVariantsBeingRemoved()`, now one line each, because sixteen
 * tests drive them through `update()` and because a call site reads better naming the local
 * concern. What changed is that there is one implementation under them.
 *
 * TWO SEPARATE QUESTIONS, and conflating them is how an authorization bug becomes a data-loss bug:
 *
 *   authorize()             -- MAY this principal remove variants of this product?
 *   refuse()                -- is removing THESE variants safe, whatever the principal may do?
 *
 * `refuse()` is not an authorization control and never was: it answers about references and
 * history, so it holds for the platform operator exactly as for a merchant. `authorize()` is the
 * authorization control, and it is the one that had three different implementations.
 */
final class VariantRemovalPolicy
{
    /**
     * MAY THIS PRINCIPAL REMOVE VARIANTS OF THIS PRODUCT? `null` means yes.
     *
     * `MerchantPermissions::allowsAny()` AND NOT `isAbleTo()`, which is the owner's second
     * condition and the one difference that changes an outcome. `isAbleTo()` resolves through
     * Laratrust's `allPermissions()`, which is `$this->roles()->with('permissions')->get()`
     * flat-mapped with **no store predicate anywhere in it** — the team feature that would scope it
     * is disabled. So for a merchant with two stores and one employee in each it reports the union:
     * the employee of store A is told they hold store B's permissions too. `MerchantPermissions`
     * resolves the permission against the store the data belongs to, keeps the merchant's own
     * null-store `admin` role (which is how every merchant holds anything at all), and answers the
     * platform operator with the wildcard — because `isAbleTo()` REFUSES the operator in this
     * codebase and an operator-blind check here would be a lockout rather than a tightening.
     *
     * OWNERSHIP FOLLOWS THE PARENT PRODUCT, not `ProductVariant.store_id`. The variant's own column
     * is nullable and is written from `getCurrentStore()` by paths that predate tenancy, so it is
     * not the authority on who owns the row; the product's store is. A product with no store
     * resolves NOTHING rather than everything.
     *
     * @return array<string,mixed>|null  null when allowed, else a refusal with a `status` the
     *                                   caller maps onto its own envelope
     */
    public static function authorize($user, ?Product $product): ?array
    {
        if (! $user instanceof User) {
            return self::denied();
        }

        /*
         * NO PRODUCT MEANS NO ANSWER. A variant whose parent is missing cannot have its ownership
         * established, so there is nothing to authorise against -- and "cannot establish" is a
         * refusal, never a pass. The FK on `product_variants.product_id` is ON DELETE CASCADE, so
         * on a schema with foreign keys enforced this is unreachable through the product's
         * deletion; it is reachable on a schema where they are not, and on a row that predates the
         * constraint.
         */
        if ($product === null) {
            return self::denied();
        }

        if (empty($product->store_id)) {
            return self::denied();
        }

        if (! \App\Services\MerchantPermissions::allowsAny($user, ['Edit Products'], $product->store_id)) {
            return self::denied();
        }

        /*
         * THE OPERATOR IS EXEMPT FROM THE OWNERSHIP QUESTION ONLY, never from the reference checks.
         * `MerchantPermissions` has already answered the permission question for them with the
         * wildcard; this limb is about WHOSE product it is, and the platform operator's answer is
         * "anyone's". `UserPolicy::isOperator()` is reused rather than re-derived -- it `rtrim`s
         * SPACE only, to match MySQL's PAD SPACE, a distinction `trim()` gets wrong.
         */
        if (UserPolicy::isOperator($user)) {
            return null;
        }

        /*
         * OWNERSHIP IS "THE PRODUCT'S STORE BELONGS TO THIS CALLER'S TENANT", AND NOT
         * "`current_store` EQUALS IT" -- and that distinction is the whole reason this limb is here
         * rather than copied from the web door.
         *
         * `MerchantPermissions` cannot answer it. Its own docblock says so: it reports what a
         * principal MAY DO in a store, not whose store it is, and a merchant's permissions come
         * from one global `store_id IS NULL` `admin` role that matches EVERY store id -- including
         * another tenant's. So permission alone would let merchant A delete merchant B's variant.
         *
         * The predicate is the one `resolveOwnedStore()` already carries for 102 call sites in
         * `Api\DashboardController`: the store's `created_by` is the caller's `creatorId()`. For a
         * merchant that is their own id; for staff it is their merchant's, so an employee inherits
         * their employer's tenant and the per-store permission check above is what narrows them to
         * one store inside it.
         *
         * `current_store` IS DELIBERATELY NOT USED. The admin API keys every handler on
         * `resolveOwnedStore($request->store_id)`, which admits any store in the caller's tenant by
         * design, so a merchant managing two stores operates on the one they NAME rather than the
         * one they last switched to. Comparing `current_store` here would have refused that -- a
         * regression invented by the guard, not a tightening. The web doors keep their own narrower
         * `current_store` checks, which is theirs to decide, not this policy's.
         */
        $ownsTheStore = \App\Models\Store::withoutGlobalScopes()
            ->whereKey($product->store_id)
            ->where('created_by', $user->creatorId())
            ->exists();

        return $ownsTheStore ? null : self::denied();
    }

    /** @return array<string,mixed> */
    private static function denied(): array
    {
        return [
            'flag' => 'error',
            'msg' => __('Permission denied.'),
            'status' => 403,
        ];
    }

    /**
     * THE PRODUCT A VARIANT BELONGS TO, read without scopes.
     *
     * `Product` registers a `StoreScope` but is NOT in `config/tenancy.php`'s `scoped_models`, so
     * the scope adds no predicate for any principal -- which means a plain `Product::find()` here
     * would look like a tenant read and not be one. Reading `withoutGlobalScopes()` says what is
     * actually happening, and the tenancy answer comes from `authorize()` instead.
     */
    public static function parentProduct(?ProductVariant $variant): ?Product
    {
        if ($variant === null || empty($variant->product_id)) {
            return null;
        }

        return Product::withoutGlobalScopes()->whereKey($variant->product_id)->first();
    }

    /**
     * THE EXISTING ROWS A POSTED COMBINATION SET WOULD DELETE. (F196)
     *
     * `$product->store_id` AND NOT `getCurrentStore()`, and that distinction is load-bearing rather
     * than tidy: `getCurrentStore()` returns the hardcoded literal 1 for a `super admin` whose
     * `current_store` is empty (`helper.php:636`), so for an operator editing a store-7 product it
     * found no variants, the removal set came back empty, the refusal returned null -- and the delete,
     * which carries no store predicate of its own, removed the real rows anyway. Detection and deletion
     * were asking different questions. They ask the same one here.
     *
     * @param  list<string>  $coveredNames
     */
    public static function variantsBeingRemoved($product, array $coveredNames)
    {
        return \App\Models\ProductVariant::where('product_id', $product->id)
            ->where('store_id', $product->store_id)
            ->get(['id', 'variant', 'sku', 'variation_price', 'price', 'stock'])
            ->reject(function ($row) use ($coveredNames) {
                return in_array((string) $row->variant, $coveredNames, true);
            })
            ->values();
    }

    /**
     * D-20. REFUSE A NARROWING THAT WOULD DESTROY VARIANTS, NAME WHAT WOULD BE LOST, AND MAKE THE
     * DELETE A SEPARATE, CONFIRMED ACT.
     *
     * Removing a term from an attribute makes the posted combination set narrower than the rows
     * that exist, and the rebuild then hard-deleted every uncovered row — not soft-deleted, so a
     * raw table read could not see it either. Measured in the browser: a product with 8 variants
     * saved with a 4-combination option set came back with 4 rows, the other 4 gone with their
     * prices, SKUs and stock. The owner's ruling is to refuse, name the data, and require an
     * independent confirmation.
     *
     * CONDITION 4 — "check the references that could make a delete dangerous" — IS THE PART THAT
     * CANNOT BE FULLY SATISFIED, and the honest answer shapes the whole design. Only `carts` and
     * `wishlists` carry a `variant_id` COLUMN. An order records what it contained in
     * `orders.product_json`, and the storefront writer's line shape has **no `variant_id` key at
     * all** — measured on the development fixture, all 14 order lines carry
     * `final_price, name, price, product_id, qty, total_orignal_price` and nothing else. So
     * "this variant appears in no order" is **not provable**, and by condition 5 an unprovable
     * delete must be refused rather than performed silently.
     *
     * Hence two different refusals, and the difference matters to the merchant:
     *   - a reference we CAN see (a cart or wishlist row, or an order line that does carry the
     *     id) is a hard refusal — confirming does not make it safe;
     *   - otherwise the merchant is told exactly which variants would go and what they carry, and
     *     must come back with an explicit confirmation. The confirmation is not a rubber stamp:
     *     it is the acknowledgement that order history may reference what is about to be deleted
     *     and cannot be checked.
     *
     * It returns the handler's own `['flag' => 'error', 'msg' => ...]` shape, which
     * `edit.blade.php`'s success handler renders, and `null` to proceed.
     *
     * @param  \Illuminate\Support\Collection  $removed  the variant rows the posted set no longer covers
     * @return array<string,mixed>|null
     */
    /**
     * F195 -- THE CONFIRMATION IS NOW A PARAMETER, NOT A REQUEST READ, AND THAT IS THE WHOLE
     * REFACTOR THAT LETS A SECOND CALLER USE THIS GUARD.
     *
     * `$request` was used for exactly ONE thing in this method -- `$request->boolean(
     * 'ss_confirm_variant_removal')` at the last condition -- so taking a request at all tied the
     * guard to a path that HAS one. `product_attribute_delete($id)` does not: it is a
     * `DELETE product-attribute-delete/{id}` handler with no request parameter and no confirmation
     * field, and it reached `ProductVariant::delete()` with none of these checks. Reading the key at
     * the CALL SITES instead makes this a pure function of (product, removed, confirmed), so that
     * handler can ask the same question.
     *
     * WHY THE TRASH PATH PASSES `true`. D-20 condition 3 is *a deliberate delete is a separate
     * operation requiring explicit confirmation*. `product_attribute_delete` IS that separate
     * operation -- a dedicated per-variant endpoint reached by its own button, not a side effect of
     * a Save that happens to narrow a term list. So it needs the BLOCKS (the cart and wishlist
     * references, the store-less refusals, the order lines carrying a variant_id, and the
     * unverifiable order history) WITHOUT the confirmation refusal, which would otherwise fail
     * every ordinary trash click on a reference-free variant. Bolting the guard on without that
     * distinction is the naive fix and it breaks the common case.
     */
    /**
     * THE FOURTH PARAMETER: WHICH CONSENT KEY THIS DOOR ACTUALLY READS, AND WHAT IT WANTS IN IT.
     *
     * Found by independent review of F184, inside F184's own change. This method hardcoded
     * `'confirm_with' => 'ss_confirm_variant_removal'`, which is the BULK save door's key and
     * carries a BOOLEAN (`$request->boolean()`). The two per-variant doors read different keys and
     * want a different value — the trash door `ss_confirm_variant`, the API door
     * `confirm_variant`, both compared against the stored variant NAME — and `DeleteVariant()`
     * forwards this array to the client verbatim. So the signal the owner required to be
     * *verifiable* was telling an API client to retry with a key that door does not read, carrying
     * a value it would reject anyway.
     *
     * A descriptor rather than a key alone, because the key is useless without the value shape:
     * `['key' => 'confirm_variant', 'value' => 'variant_name']`. Default: the bulk door, so every
     * existing call site keeps its current contract untouched.
     *
     * @param  array{key:string,value:string}|null  $consent  the calling door's consent contract
     */
    public static function refuse($product, $removed, bool $confirmed, ?array $consent = null): ?array
    {
        if ($removed->isEmpty()) {
            return null;
        }

        $names = $removed->pluck('variant')->map(fn ($v) => (string) $v)->all();
        $ids = $removed->pluck('id')->map(fn ($v) => (int) $v)->all();

        /* --- condition 4: the references that ARE checkable ------------------------------- */
        $blockers = [];

        $inCarts = \App\Models\Cart::withoutGlobalScopes()->whereIn('variant_id', $ids)->count();
        if ($inCarts > 0) {
            $blockers[] = __(':count shopper cart line(s)', ['count' => $inCarts]);
        }

        /* The Eloquent model, not `DB::table('wishlists')`. The raw form works, but
           `RawQueryTenancyInventoryTest` counts raw entry points against tenant-discriminated
           tables and a new one fails three of its assertions — correctly, because a raw query is
           exactly where a tenant predicate goes missing. `Wishlist` carries no StoreScope either,
           so this is not a scoping claim; it is staying inside the shape the inventory can see. */
        $inWishlists = \App\Models\Wishlist::withoutGlobalScopes()->whereIn('variant_id', $ids)->count();
        if ($inWishlists > 0) {
            $blockers[] = __(':count wishlist entry(ies)', ['count' => $inWishlists]);
        }

        /* An order line only sometimes carries the VARIANT id; when it does, it is a real
           reference and this finds it. The PRODUCT id, by contrast, is on every line — measured,
           14 of 14 in the fixture — and that asymmetry is what makes a provable case possible
           at all. See $ordersNamingProduct below. */
        /*
         * THE PRODUCT'S STORE, NOT THE CALLER'S — and the difference is a live defect, not a
         * tidy-up. Both order queries below were `->where('store_id', getCurrentStore())`. The
         * product being edited is the subject of the question and its references live in its own
         * store; the caller's current store coincides only by accident.
         *
         * It does not coincide for the platform operator. `getCurrentStore()` returns the
         * HARDCODED LITERAL 1 for a `super admin` whose `current_store` is empty
         * (`helper.php:636`), so an operator narrowing a store-7 product had its history checked
         * against STORE 1's orders, found none, and was offered a confirmation for a delete whose
         * safety had never been established. Measured by driving exactly that.
         *
         * And `getCurrentStore()` can answer NULL for a merchant with no resolvable store, which
         * Laravel compiles to `whereNull('store_id')` — matching nothing, so the check passes for
         * the same wrong reason. A product with no store is refused outright below rather than
         * queried, because an unscoped reference check is worse than no check: it reports safety
         * it did not establish.
         */
        $ssProductStore = $product->store_id ?? null;

        /*
         * THE BLOCKERS FOUND ABOVE ARE NAMED EVEN HERE. A store-less product whose variant IS in
         * a cart was getting the vaguer "nothing can establish whether anything refers to them"
         * instead of "a shopper cart line holds it" -- both refuse, but one of them tells the
         * merchant what to clear and the other does not.
         */
        if (empty($ssProductStore) && $blockers !== []) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be deleted: :names. They are referenced by :blockers. Deleting them would break that reference and destroy their prices, SKUs and stock. Nothing has been changed.', [
                    'names' => implode(', ', $names),
                    'blockers' => implode(', ', $blockers),
                ]),
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'blocked' => 'live_reference',
                ],
            ];
        }

        if (empty($ssProductStore)) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be deleted: the product has no store, so nothing can establish whether anything refers to them. Nothing has been changed.'),
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'blocked' => 'unresolvable_store',
                ],
            ];
        }

        $inOrders = 0;
        foreach (\App\Models\Order::withoutGlobalScopes()
            ->where('store_id', $ssProductStore)
            ->whereNotNull('product_json')
            ->pluck('product_json') as $json) {
            $lines = json_decode((string) $json, true);

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (is_array($line) && ! empty($line['variant_id']) && in_array((int) $line['variant_id'], $ids, true)) {
                    $inOrders++;
                    break;
                }
            }
        }

        if ($inOrders > 0) {
            $blockers[] = __(':count order(s)', ['count' => $inOrders]);
        }

        if ($blockers !== []) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be removed because they are still referenced: :names. Referenced by: :refs. Remove or complete those references first.', [
                    'names' => implode(', ', $names),
                    'refs' => implode('; ', $blockers),
                ]),
                /*
                 * `ss_variant_removal` WAS MISSING FROM THIS BRANCH ALONE, and it is the branch a
                 * merchant hits most. Every other refusal carries it, so the API door reported
                 * `ss_variant_removal: null` and its log line recorded `blocked: null` for exactly
                 * the cases that most need naming. Found by the mandated review of the F195
                 * completion. The names and count are what a client needs to show which variants
                 * are stuck; `blocked` is what a log needs to tell the three refusals apart.
                 */
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'blocked' => 'live_reference',
                    'referenced_by' => $blockers,
                ],
            ];
        }

        /*
         * --- THE OWNER'S CORRECTION, 2026-10-27: CONFIRMATION DOES NOT SUBSTITUTE FOR PROOF ----
         *
         * > لا أوافق على الحذف النهائي لمتغير قد تكون له مراجع تاريخية غير قابلة للتحقق، حتى مع
         * > تأكيد التاجر. التأكيد شرط للتغييرات المسموحة، لكنه لا يلغي شرط إثبات السلامة.
         *
         * The first version of this guard let an explicit confirmation carry a delete whose safety
         * was unprovable. That was wrong: a merchant agreeing to a warning does not make order
         * history checkable, and what is lost is commercial data.
         *
         * WHAT MAKES A PROVABLE CASE POSSIBLE AT ALL is an asymmetry in the order line shape. The
         * VARIANT id is absent from the storefront writer's lines entirely, so "no order used this
         * variant" cannot be established. The PRODUCT id is on every line — measured, 14 of 14
         * across the fixture's 7 orders — so "no order ever contained this PRODUCT" CAN be
         * established, and that entails no order contained any of its variants.
         *
         * So two genuinely different situations, kept apart rather than blurred into one warning:
         *
         *   - NO order names this product  -> deletion is provably safe; an explicit confirmation
         *                                     may carry it (condition 3, below).
         *   - ANY order names this product -> the variant may be in that history and nothing can
         *                                     show otherwise. HARD BLOCK, and no confirmation is
         *                                     offered, because there is nothing a merchant could
         *                                     usefully confirm.
         */
        $ordersNamingProduct = 0;

        foreach (\App\Models\Order::withoutGlobalScopes()
            ->where('store_id', $ssProductStore)
            ->whereNotNull('product_json')
            ->pluck('product_json') as $json) {
            $lines = json_decode((string) $json, true);

            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (is_array($line) && (int) ($line['product_id'] ?? 0) === (int) $product->id) {
                    $ordersNamingProduct++;
                    break;
                }
            }
        }

        if ($ordersNamingProduct > 0) {
            return [
                'flag' => 'error',
                'msg' => __('These variants cannot be deleted: :names. This product appears in :count past order(s), and an order line does not record which variant was bought — so nothing can show that these variants are not part of that history. Deleting them would destroy their prices, SKUs and stock with no way to recover what an order referred to. Nothing has been changed. To stop selling them while keeping the record, set them out of stock instead.', [
                    'names' => implode(', ', $names),
                    'count' => $ordersNamingProduct,
                ]),
                'ss_variant_removal' => [
                    'variants' => $names,
                    'count' => count($names),
                    'blocked' => 'unverifiable_order_history',
                    'orders_naming_product' => $ordersNamingProduct,
                ],
            ];
        }

        /* --- condition 3: a deliberate delete is a separate, confirmed act ----------------- */
        if (! $confirmed) {
            /* Condition 2: the names, the count, and what each one carries, so the merchant is
               deciding about real data rather than about a number. */
            $detail = $removed->map(function ($v) {
                return trim(sprintf(
                    '%s (%s)',
                    (string) $v->variant,
                    implode(', ', array_filter([
                        $v->sku !== null && $v->sku !== '' ? __('SKU :sku', ['sku' => $v->sku]) : null,
                        $v->variation_price !== null ? __('price :price', ['price' => $v->variation_price]) : null,
                        __('stock :stock', ['stock' => (int) $v->stock]),
                    ]))
                ));
            })->all();

            return [
                'flag' => 'error',
                'msg' => __('This change would permanently delete :count variant(s): :detail. Their prices, SKUs and stock would be lost. No past order names this product, so nothing in order history refers to them. Nothing has been changed. To proceed, confirm the removal explicitly.', [
                    'count' => count($names),
                    'detail' => implode(' | ', $detail),
                ]),
                'ss_variant_removal' => self::consentAdvice($consent, $names, $removed),
            ];
        }

        return null;
    }

    /**
     * What the merchant's client should send back, named so it is actionable.
     *
     * `confirm_value` is advertised only when there is exactly ONE variant to name, which is the
     * per-variant doors by construction. A bulk narrowing covers many variants at once and its
     * door reads a boolean, so there `confirm_value` is the literal `'true'` and the names stay in
     * `variants` where the merchant reads them.
     *
     * @param  array{key:string,value:string}|null  $consent
     * @param  array<int,string>  $names
     * @return array<string,mixed>
     */
    private static function consentAdvice(?array $consent, array $names, $removed): array
    {
        $key = is_string($consent['key'] ?? null) && $consent['key'] !== ''
            ? $consent['key']
            : 'ss_confirm_variant_removal';

        $wantsName = ($consent['value'] ?? 'true') === 'variant_name';

        $advice = [
            'variants' => $names,
            'count' => count($names),
            'confirm_with' => $key,
        ];

        /* A token can only be advertised when exactly one variant is being removed. Advertising
           the first of several would invite a client to confirm one variant and delete the rest,
           which is the replay the consent signal exists to prevent. */
        if ($wantsName && count($names) === 1) {
            $advice['confirm_value'] = self::consentToken($removed->first());
        }

        /* AND NOTHING IS ADVERTISED FOR THE BOOLEAN DOOR. Review's point, and it is about what the
           server TELLS a client rather than what it enforces: that door reads
           `$request->boolean()`, which `filter_var(..., FILTER_VALIDATE_BOOLEAN)` satisfies with
           '1', 'true', 'yes' or 'on'. Publishing `confirm_value: 'true'` would be the server
           advertising that a bare literal is sufficient — writing the asymmetry into the protocol
           rather than leaving it as a recorded finding (F205) for the owner to decide. There is
           nothing actionable to advertise: the flag is already a field in the form. */

        return $advice;
    }

    /**
     * THE CONSENT TOKEN FOR ONE VARIANT: what the merchant is shown, and what they must echo back.
     *
     * One implementation, used by both per-variant doors AND by the two variant panels that render
     * the trash button, so the dialog cannot name one thing while the request confirms another.
     *
     * `Str::trim()`, not PHP `trim()`, and that is the whole point of the method existing. Laravel's
     * global `TrimStrings` middleware normalises every incoming string with `Str::trim()`, whose
     * charlist is Unicode `\s` **plus** `Str::INVISIBLE_CHARACTERS` — NBSP, ZWSP, BOM, the
     * ideographic space and **RLM/ALM**. PHP's `trim()` strips only `" \t\n\r\0\x0B"`. Comparing a
     * middleware-trimmed echo against a `trim()`-ed stored name could never match for such a name,
     * so the variant became permanently undeletable: measured, `"Rouge\u{00A0}"` arrives as
     * `"Rouge"` and was compared against `"Rouge\u{00A0}"`. RLM is what makes this real in a
     * bilingual catalogue rather than theoretical.
     *
     * AND A NAMELESS VARIANT STILL NEEDS A TOKEN. `product_variants.variant` is NOT NULL with no
     * default, and F199's measured outcome was a product whose variants were replaced by a single
     * row named `''`. An empty echo cannot be consent — `ConvertEmptyStringsToNull` turns it into
     * `null` before a handler sees it — so such a row would have been undeletable, which is a
     * capability F184 would have TAKEN AWAY rather than guarded. `#<id>` is shown in the dialog and
     * accepted by the doors: still verifiable, because the merchant is told exactly what to echo.
     */
    public static function consentToken($variant): string
    {
        $name = \Illuminate\Support\Str::trim((string) ($variant->variant ?? ''));

        /* The token must SURVIVE THE REQUEST PIPELINE, or the merchant can never send it back.
           `App\Http\Middleware\XSS` rewrites every request string on the web door — it strips
           `alert`, `javascript`, `&lt;` and `&gt;` — so a variant named exactly `alert` normalises
           to nothing and there is no value the merchant could type. Then the id is the only thing
           they can echo, and showing it to them is what keeps the signal verifiable. */
        return self::normaliseConsent($name) !== '' ? $name : '#' . (int) ($variant->id ?? 0);
    }

    /**
     * Does this echo confirm THIS variant? The echo is normalised the same way the token is, so the
     * comparison is symmetric whatever the middleware did to it on the way in.
     */
    public static function consentSatisfied($sent, $variant): bool
    {
        if (! is_string($sent)) {
            return false;
        }

        $sent = self::normaliseConsent($sent);

        return $sent !== '' && $sent === self::normaliseConsent(self::consentToken($variant));
    }

    /**
     * ONE NORMALISER, APPLIED TO BOTH SIDES, SO THE COMPARISON IS SYMMETRIC WHATEVER THE REQUEST
     * PIPELINE DID ON THE WAY IN.
     *
     * Two middlewares rewrite an incoming string before a handler sees it, and neither touches the
     * stored column — so comparing the echo against the raw name could never match for a whole
     * class of legitimate names, and the variant became permanently undeletable:
     *
     *   - `TrimStrings` runs `Str::trim()`, whose charlist is Unicode `\s` **plus**
     *     `Str::INVISIBLE_CHARACTERS` — NBSP, ZWSP, BOM, the ideographic space and **RLM/ALM**.
     *     Measured: `"Rouge\u{00A0}"` arrives as `"Rouge"`.
     *   - `App\Http\Middleware\XSS` (on the web door's route group, NOT on the API door's) runs
     *     `htmlspecialchars_decode()` then strips `&lt;`, `&gt;`, `javascript` and `alert`.
     *     Measured: `"alert-Red"` arrives as `"-Red"`.
     *
     * Both are applied here, to the echo and to the token alike. The API door has no `xss` and the
     * web door does, so normalising both sides is what lets ONE comparison serve both — the
     * alternative is a per-door comparison, which is the duplicated implementation §4 forbids.
     *
     * This does not weaken the signal. It is idempotent (`str_replace` of the same needles twice
     * changes nothing), and a caller still has to know the stored name to produce anything that
     * normalises to the token: the row acted on is selected by its own id, so a collision between
     * two names cannot delete a variant the merchant did not confirm.
     */
    private static function normaliseConsent(string $value): string
    {
        $value = htmlspecialchars_decode($value);
        $value = str_replace(['&lt;', '&gt;', 'javascript', 'alert'], '', $value);

        return \Illuminate\Support\Str::trim($value);
    }
}
