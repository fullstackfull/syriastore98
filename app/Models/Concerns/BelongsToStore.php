<?php

namespace App\Models\Concerns;

use App\Scopes\StoreScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * R4-04 / R4-01 — model-side tenancy machinery for tenant-owned models.
 *
 * PURPOSE. R4-04 is cross-tenant IDOR through implicit route-model binding: 68 route-bound
 * tenant-owned models, none of them ownership-checked. The controller-side fix for that lives in
 * the authorization workstream; this trait exists so that fix is a ONE-LINER at each call site
 * instead of a hand-rolled predicate repeated 68 times:
 *
 *     abort_unless($product->isOwnedByCurrentStore(), 403);   // route-bound model, R4-04
 *     Product::forStore($storeId)->paginate();                // list endpoint, R4-05 / 2.11.8
 *
 * USE 403, NEVER 401, FOR A TENANCY DENIAL. repo-B lib/network/network.dart:285-288 maps ANY 401
 * to `_handleUnauthorized()`, which at :333-341 wipes ALL local storage and force-navigates to
 * login. A 401 on a tenancy denial would log the merchant out of the Flutter app on every such
 * denial. This is cheap to get right and expensive to discover.
 *
 * NOT A PARALLEL SYSTEM. This adds no resolver, no tenancy service and no second source of truth.
 * The tenant comes from the single existing resolver, `getCurrentStore()`
 * (app/Helper/helper.php:357-381), via `App\Scopes\StoreScope::resolveStoreId()`. The trait is a
 * Laravel-native concern that wraps logic the codebase already repeats by hand 1,261 times.
 *
 * ATTACHING IT IS BEHAVIOUR-NEUTRAL; ALLOWLISTING IS NOT. This docblock previously said the
 * scope was "DISABLED by default", which is NO LONGER TRUE and is corrected here rather than left
 * to mislead: enforcement is per-model via `config/tenancy.php` -> `scoped_models`, and TEN models
 * are now really scoped -- Menu, SupportTicket, SupportConversion, FlashSaleCondition,
 * ThemeCustomizeOrder, NotifyUser (batch 1) and OrderRefund, OrderNote, Customer, Cart (batch 2).
 *
 * For a model that is NOT on that list, `apply()` adds no predicate at all, so attaching the trait
 * alone still leaves the emitted queries -- and therefore every `genealabs/laravel-model-caching`
 * cache key on the 78 models using `Cachable` -- byte-identical. SEVEN trait-carrying models are
 * not allowlisted and are in exactly that position: Category, DeliveryBoy, Product, Shipping,
 * StoreWallet, StoreWalletTransaction, StoreWithdrawal. (This sentence read "the 11" while the
 * one above it read "TEN models are now really scoped", which cannot both be true of 17
 * carriers -- it was left stale by the batch-2 revert and is corrected here by counting:
 * 17 carriers minus 10 allowlisted = 7. The count is asserted at runtime, not trusted, by
 * tests/Feature/Tenancy/TenantModelClassificationTest.)
 *
 * ============================================================================================
 *  TWO ELOQUENT METHODS ESCAPE THIS SCOPE ENTIRELY. NEITHER IS FIXED. BOTH ARE UNREACHED.
 * ============================================================================================
 *
 * Global scopes are applied only via `Builder::toBase()`
 * (vendor/laravel/framework/src/Illuminate/Database/Eloquent/Builder.php:1907-1910), so any
 * builder method that touches `$this->query` directly is a bypass. Enumerated mechanically over
 * the whole class, there are exactly two:
 *
 *   - `forceDelete()` (Builder.php:1397-1400, `return $this->query->delete()`) -- the ONLY
 *     method in the class whose body is a direct `$this->query->` call. `delete()` at
 *     :1381-1388 DOES go through `toBase()` and so is scoped.
 *   - `truncate()` via the `__call` fallback (Builder.php:2123) -- not a macro, not a named
 *     scope, and NOT in `$passthru` (:105-137, which does route through `toBase()`), so it is
 *     forwarded to the base query builder and empties the WHOLE TABLE for EVERY tenant.
 *
 * NOT FIXED HERE, and the reason is a fatal rather than a preference: the natural fix is
 * `newEloquentBuilder()` on this trait, but
 * vendor/genealabs/laravel-model-caching/src/Traits/ModelCaching.php:109 already declares that
 * method and `Cachable` composes `ModelCaching`, which every allowlisted model uses. Declaring
 * it here is a TRAIT METHOD COLLISION and fatals every model carrying both -- the same
 * composition-error class as the `Eloquent\Scopes\Scope` FQCN that fatalled eleven models here
 * once, and equally invisible to `php -l`.
 *
 * Proportionate because MEASURED UNREACHED: zero first-party `forceDelete()` call sites in app/,
 * packages/, routes/, resources/, themes/ or database/; three `truncate()` sites, all on
 * platform-global geographic reference models (City, State, Country -- no `store_id`, no trait).
 * Both are pinned, ratcheted and reported OPEN by
 * tests/Feature/Tenancy/EloquentScopeBypassInventoryTest.
 *
 * Everything the trait adds beyond the scope is a NEW named scope or a NEW helper method; nothing
 * existing is overridden. Verified: none of the models this trait is applied to already declares
 * `boot()`, `booted()`, `scopeForStore`, `scopeForCurrentStore`, `scopeWithoutStoreScope`,
 * `isOwnedByStore`, `isOwnedByCurrentStore` or `getStoreColumn`.
 *
 * ============================================================================================
 *  A DELIBERATE ASYMMETRY: THE SCOPE ADMITS EVERY STORE YOU OWN, THESE HELPERS DO NOT.
 * ============================================================================================
 *
 * `StoreScope::apply()` now filters on `{current_store} UNION {stores whose created_by =
 * creatorId()}`, because the admin API legitimately operates on a store that is not the caller's
 * `current_store` (see StoreScope::admissibleStoreIds() for the full argument).
 *
 * The helpers below were NOT widened to match, and that is a decision rather than an oversight.
 * `isOwnedByCurrentStore()` exists to be used as `abort_unless($model->isOwnedByCurrentStore(),
 * 403)` in controllers this workstream does not own. Widening it would relax an AUTHORIZATION
 * check -- it would start admitting requests that are denied today -- and that is a permission
 * change wearing a tenancy change's clothes. A call site that genuinely wants "any store this
 * merchant owns" must say so explicitly, which keeps the decision visible at the call site and in
 * review:
 *
 *     abort_unless($model->isOwnedByStore($request->store_id) && $callerOwns($request->store_id), 403);
 *
 * So: the scope is DEFENCE IN DEPTH and errs toward not blanking a working page; these helpers are
 * the CONTROL OF RECORD and err toward denial. They are supposed to differ.
 *
 * WHAT THIS TRAIT DELIBERATELY DOES NOT DO.
 *   - It does NOT auto-populate `store_id` on create. That would be a behaviour change with a
 *     wide blast radius (NEW-null-store-id-generators) and it would silently rewrite inserts that
 *     today legitimately carry a foreign store id. Separate increment, separate review.
 *   - It does NOT add a `store()` relation, to avoid colliding with relations already declared on
 *     individual models.
 *   - It does NOT remove or rewrite any existing `where('store_id', ...)` filter. Those stay as
 *     defence in depth; a scope AND an explicit filter on the same column with the same value
 *     compile to `store_id = 5 AND store_id = 5`, which is identical and which the optimiser
 *     collapses.
 *
 * DO NOT APPLY THIS TRAIT TO (documented DO-NOT-SCOPE list, with the reason):
 *   - `User` -- `users` has NO `store_id`. Membership is `users.current_store` plus
 *     `users.created_by`, and `current_store` is the MUTABLE, ATTACKER-WRITABLE switch pointer of
 *     R4-03. Scoping on it would be scoping on attacker-controlled state. `User` needs a Policy
 *     keyed on the creator id instead.
 *   - `ZidStore` / `ZidEvent` -- their `store_id` is an EXTERNAL Zid identifier typed as a STRING,
 *     not a foreign key into `stores.id` (app/Models/ZidEvent.php:44 declares
 *     `scopeForStoreAndEvent($query, string $storeId, string $eventName)`, and
 *     app/Console/Commands/ZidProcessEvents.php:78 casts the external payload with `(string)`).
 *     Column-name matching would compare a local integer against an external string and silently
 *     return zero rows, stopping the scheduled sync with no error anywhere.
 *   - PLATFORM-GLOBAL models with NO `store_id` column -- `Country`, `State`, `City`,
 *     `Currency`, `Plan`, `Permission`, `EmailTemplate`, `Template`, `Addon`. A scope on these
 *     BREAKS the platform: there is no column to filter on and every tenant must see all rows.
 *   - `Role`, `Faq` and `Testimonial`, which DO have a `store_id` column and must STILL not be
 *     scoped. The reason is different and matters, because "it has no store_id" is the test a
 *     future reader would apply: verified against the migrations, `roles`
 *     (2023_12_08_100022_laratrust_setup_tables.php), `faqs`
 *     (2023_12_18_101636_create_faqs_table.php) and `testimonials`
 *     (2023_12_26_095726_create_testimonials_table.php) all declare one. `Role` must not be
 *     scoped because the shared global `admin` role is created with a NULL `store_id` and is
 *     used by EVERY self-registered merchant, so a `store_id = ?` predicate would hide each
 *     merchant's own role from them. `Faq` and `Testimonial` are storefront-public content read
 *     by anonymous visitors, who resolve no tenant, so a scope buys no confidentiality while
 *     risking a blank section -- the same trap that blanked the storefront menu.
 */
trait BelongsToStore
{
    /**
     * Register the tenant scope. Eloquent calls this once per model class per process.
     *
     * Registered unconditionally even while the scope is disabled -- see the "WHY THE SCOPE IS
     * REGISTERED EVEN WHILE DISABLED" note in App\Scopes\StoreScope.
     */
    public static function bootBelongsToStore(): void
    {
        static::addGlobalScope(new StoreScope());
    }

    /**
     * The tenant foreign key on this model. Override `$storeColumn` on a model that differs.
     */
    public function getStoreColumn(): string
    {
        return property_exists($this, 'storeColumn') && is_string($this->storeColumn)
            ? $this->storeColumn
            : 'store_id';
    }

    /**
     * EXPLICIT predicate for a KNOWN store id. This is the primary control -- prefer it over
     * relying on the global scope, which is gated and fails open on a null tenant.
     *
     * FAILS CLOSED. A null or non-numeric `$storeId` yields ZERO rows rather than every row.
     * Calling this is a statement of intent to isolate, so an unresolvable tenant must deny, not
     * disclose. (This is the opposite of the global scope's null behaviour, and the difference is
     * deliberate: the scope must not empty the storefront, an explicit call must not leak.)
     *
     * Column is qualified, so this is safe on the 39 join sites.
     */
    public function scopeForStore(Builder $query, $storeId): Builder
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($query->getModel()->qualifyColumn($this->getStoreColumn()), (int) $storeId);
    }

    /**
     * EXPLICIT predicate for the current request's store. FAILS CLOSED -- see `scopeForStore`.
     *
     * NOT usable from a job, a command or the storefront: `StoreScope::resolveStoreId()` returns
     * null there (R4-02) and this will correctly return zero rows. Those contexts must resolve
     * their store themselves and call `forStore($id)`.
     */
    public function scopeForCurrentStore(Builder $query): Builder
    {
        return $this->scopeForStore($query, StoreScope::resolveStoreId());
    }

    /**
     * ESCAPE HATCH 2 -- drop the tenant scope for one query chain.
     *
     * Readable alias for `withoutGlobalScope(StoreScope::class)`, so a cross-tenant query is
     * self-documenting and greppable at review time. Leaves every other global scope intact --
     * in particular it does NOT lift `SoftDeletes` on `Customer`.
     */
    public function scopeWithoutStoreScope(Builder $query): Builder
    {
        return $query->withoutGlobalScope(StoreScope::class);
    }

    /**
     * Does this record belong to the given store? FAILS CLOSED on a null/blank tenant or a null
     * `store_id` on the record itself.
     *
     * An unscoped record (`store_id IS NULL`) is treated as NOT owned by anyone. That is the
     * conservative reading and it matters: NEW-null-store-id-generators means such rows exist.
     */
    public function isOwnedByStore($storeId): bool
    {
        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return false;
        }

        $own = $this->getAttribute($this->getStoreColumn());

        if ($own === null || $own === '' || ! is_numeric($own)) {
            return false;
        }

        return (int) $own === (int) $storeId;
    }

    /**
     * The R4-04 one-liner: `abort_unless($model->isOwnedByCurrentStore(), 403)`.
     *
     * FAILS CLOSED. Returns false when the tenant cannot be resolved -- which includes a super
     * admin, because `StoreScope::resolveStoreId()` returns null for one. A super-admin bypass is
     * an AUTHORIZATION decision and belongs in a Policy, not in a model helper; wiring it in here
     * would hide the platform-operator exception from `php artisan policy` review and from the
     * authorization tests. Callers that must admit super admins should say so explicitly:
     *
     *     abort_unless($user->isSuperAdmin() || $model->isOwnedByCurrentStore(), 403);
     */
    public function isOwnedByCurrentStore(): bool
    {
        return $this->isOwnedByStore(StoreScope::resolveStoreId());
    }
}
