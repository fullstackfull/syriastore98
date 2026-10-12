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
 * ATTACHING IT IS BEHAVIOUR-NEUTRAL TODAY. The global scope it registers is DISABLED by default
 * under GATE G2 (see App\Scopes\StoreScope) and adds no SQL predicate while disabled, so the
 * emitted queries -- and therefore every `genealabs/laravel-model-caching` cache key on the 78
 * models using `Cachable` -- are unchanged. Everything the trait adds beyond that is a NEW named
 * scope or a NEW helper method; nothing existing is overridden. Verified: none of the models this
 * trait is applied to already declares `boot()`, `booted()`, `scopeForStore`, `scopeForCurrentStore`,
 * `scopeWithoutStoreScope`, `isOwnedByStore`, `isOwnedByCurrentStore` or `getStoreColumn`.
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
