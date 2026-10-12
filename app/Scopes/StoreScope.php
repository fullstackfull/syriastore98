<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scopes\Scope;
use Throwable;

/**
 * R4-01 / R4-02 / R4-04 / R4-05 — the tenant-isolation global scope.
 *
 * Laravel-native: one `Illuminate\Database\Eloquent\Scopes\Scope`, attached by the
 * `App\Models\Concerns\BelongsToStore` trait. It REPLACES nothing and runs ALONGSIDE the 1,261
 * hand-written `where('store_id', ...)` filters already in the codebase. It is not a second
 * tenancy system, a second resolver or a second database: the tenant still comes from the one
 * existing resolver, `getCurrentStore()` at app/Helper/helper.php:357-381.
 *
 * ============================================================================================
 *  IT IS DISABLED BY DEFAULT, AND THAT IS DELIBERATE. DO NOT ENABLE IT TO "FINISH" THIS WORK.
 * ============================================================================================
 *
 * GATE G2 (07-OWNER-APPROVAL-GATES.md) is a STANDING gate that is not yet green:
 *   - 78 of the 95 models in app/Models use `GeneaLabs\LaravelModelCaching\Traits\Cachable`;
 *   - `config/laravel-model-caching.php` does not exist, so package defaults apply;
 *   - `CACHE_STORE=file` means ONE shared file bucket per host.
 * If that package's cache keys do NOT incorporate global-scope constraints, then enabling this
 * scope would make tenant B serve tenant A's warmed result set -- it would CREATE a cross-tenant
 * read where none exists today. `vendor/genealabs` is one of the 237 empty vendor directories in
 * this container, so the key derivation could not be verified and the question remains open.
 *
 * THE ONE TEST THAT CLEARS THE GATE: tenant A warms `Product::all()`, tenant B runs the same
 * query, assert B receives ONLY B's rows -- run with `CACHE_STORE=file`, NOT the test default of
 * `array`, because `array` hides the production failure mode. The specification is in
 * tests/Feature/Tenancy/ModelCacheTenantIsolationTest.php and it is currently skipped, loudly.
 *
 * While disabled, `apply()` adds no predicate at all, so the generated SQL -- and therefore every
 * model-cache key -- is byte-for-byte what it is today. Attaching the trait is behaviour-neutral.
 *
 * WHY THE SCOPE IS REGISTERED EVEN WHILE DISABLED, rather than conditionally attached: Eloquent
 * boots a model class once per process, so a conditional `addGlobalScope` would freeze whatever
 * the flag happened to be at first boot and could never be toggled afterwards. Registering
 * always and short-circuiting inside `apply()` keeps the flag live, keeps
 * `withoutGlobalScope(StoreScope::class)` meaningful in every state, and keeps tests able to
 * exercise both states in one process.
 *
 * ============================================================================================
 *  THE R4-02 TRAP: A NULL TENANT MUST NOT BECOME `store_id = NULL`.
 * ============================================================================================
 *
 * `getCurrentStore()` resolves from the `web` guard ONLY. It returns NULL for an anonymous
 * request, for a customer or delivery boy (they authenticate on the `customers` / `deliveryboy`
 * guards, which `auth()->user()` on the default `web` guard does not see), for a queued job and
 * for a scheduled command. A naive scope would then compile `store_id = NULL`, which matches NO
 * row in SQL, so EVERY storefront page, EVERY customer API call and EVERY job would silently
 * return empty. That is the single most important constraint on this design.
 *
 * So: a null tenant means NO PREDICATE IS ADDED. This scope therefore FAILS OPEN by design, and
 * that is an honest statement of what it is -- DEFENCE IN DEPTH behind explicit per-call-site
 * ownership predicates, never a substitute for them. The primary control for the contexts above
 * is, and remains, the explicit predicate at the call site (Increment 14). Anyone reading this
 * class as "tenancy is now enforced" has misread it.
 *
 * ============================================================================================
 *  WHAT THIS SCOPE CANNOT SEE -- measured, not assumed.
 * ============================================================================================
 *   - 146 `DB::table()` sites bypass Eloquent entirely, so no global scope reaches them
 *     (app/Http/Controllers/RoleController.php:182 is a live example).
 *   - 40 `orWhere` sites are potential escapes if the framework does not group pre-existing
 *     where clauses before appending the scope. That grouping is documented framework behaviour
 *     but could NOT be confirmed from vendor source in this container -- treat it as UNVERIFIED.
 *   - 780 of the 1,261 existing `where('store_id', ...)` filters pass a FOREIGN store id
 *     (a storefront `$store->id`, a related row's `store_id`, a request parameter, an external
 *     Zid string, or the hardcoded `1` at app/Models/Utility.php:289 and :896). Against those,
 *     a scope keyed on the CURRENT store compiles `store_id = A AND store_id = B` -> zero rows.
 *     This is precisely why enabling the scope is gated and why the trait is applied
 *     SELECTIVELY to a classified list rather than to all 95 models.
 *
 * Coexistence with the OTHER 481 filters, which do pass the current store, is safe: a scope plus
 * an explicit `where` on the same column with the SAME value compiles to `store_id = 5 AND
 * store_id = 5`, which is logically identical and which any optimiser collapses. Those filters
 * are therefore REDUNDANT, not harmful, and must be LEFT IN PLACE as defence in depth. Do not
 * sweep them out.
 *
 * AMBIGUOUS COLUMN. The predicate is always written through `Model::qualifyColumn()`, i.e.
 * `"products"."store_id" = ?` and never a bare `store_id = ?`. There are 39 join sites in the
 * codebase and an unqualified predicate would raise "Column 'store_id' in where clause is
 * ambiguous" on every one of them.
 */
class StoreScope implements Scope
{
    /**
     * Master switch. FALSE until GATE G2 is green. See the class docblock.
     *
     * Overridable at runtime via `config('tenancy.store_scope_enabled')` when a `config/tenancy.php`
     * is introduced; no such file is created by this increment, so the static default governs.
     */
    public static bool $enabled = false;

    /**
     * Depth of the current `allowCrossTenant()` suppression block. A counter rather than a
     * boolean so nested blocks cannot have an inner one re-enable the scope on exit.
     */
    protected static int $suppressionDepth = 0;

    /**
     * Apply the scope.
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! static::isActive()) {
            return;
        }

        $storeId = static::resolveStoreId();

        // THE R4-02 TRAP. Null tenant -> add nothing. NEVER `where(store_id, null)`.
        if ($storeId === null) {
            return;
        }

        $column = method_exists($model, 'getStoreColumn') ? $model->getStoreColumn() : 'store_id';

        // Qualified on purpose -- see "AMBIGUOUS COLUMN" in the class docblock.
        $builder->where($model->qualifyColumn($column), $storeId);
    }

    /**
     * Is the scope switched on at all?
     */
    public static function isActive(): bool
    {
        if (static::$suppressionDepth > 0) {
            return false;
        }

        try {
            $configured = config('tenancy.store_scope_enabled');

            if ($configured !== null) {
                return (bool) $configured;
            }
        } catch (Throwable $e) {
            // No container (plain unit test, early bootstrap): fall through to the static default.
        }

        return static::$enabled;
    }

    /**
     * Resolve the tenant for the CURRENT request, or null when it cannot be determined.
     *
     * Deliberately NOT a call to `getCurrentStore()` for the super-admin case. That resolver
     * returns the hardcoded literal `1` for a super admin whose `current_store` is empty
     * (app/Helper/helper.php:373-374) -- there is no "see all tenants" semantic anywhere in it.
     * Scoping a super admin to store 1 would break platform administration in a way that looks
     * like missing data rather than like an error, so super admins are resolved to null (= no
     * predicate) here instead.
     */
    public static function resolveStoreId(): ?int
    {
        try {
            $user = auth()->user();
        } catch (Throwable $e) {
            return null;
        }

        // Anonymous, or authenticated on the `customers` / `deliveryboy` guard, or a job or
        // command with no authenticated principal at all. See the R4-02 trap.
        if (! $user) {
            return null;
        }

        if (static::isSuperAdmin($user)) {
            return null;
        }

        // `current_store` is read through the one existing resolver so there is exactly one
        // definition of "the current tenant" in the codebase.
        $storeId = function_exists('getCurrentStore') ? getCurrentStore() : ($user->current_store ?? null);

        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return null;
        }

        return (int) $storeId;
    }

    /**
     * Platform operators are not a tenant and must not be scoped to one.
     *
     * NOTE for the authorization workstream: `users.type` is a plain string column that
     * self-registration can set (R4-03 / SEC-F6), so this is NOT a trustworthy authorization
     * boundary on its own. It is used here only to decide whether to NARROW a query, never to
     * widen a permission, so a forged value can at worst leave the query unscoped -- which is
     * exactly today's behaviour. The real fix for `users.type` belongs to the auth workstream.
     */
    protected static function isSuperAdmin($user): bool
    {
        return isset($user->type) && strtolower(trim((string) $user->type)) === 'super admin';
    }

    /**
     * ESCAPE HATCH 1 -- run a callback with the scope suppressed process-wide.
     *
     * This is the hatch for the contexts R4-02 identifies: queued jobs, scheduled commands
     * (routes/console.php:9 drives the Zid sync), storefront rendering that resolves its store
     * by slug through `getStore()` (app/Helper/helper.php:2301-2306), and any deliberate
     * cross-tenant platform report.
     *
     * The suppression is restored in a `finally`, so it survives an exception. It is
     * process-wide and NOT request-scoped, so keep the block as tight as possible, and never
     * wrap a whole job body in it when one query is what needs it.
     *
     * @template TReturn
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public static function allowCrossTenant(callable $callback)
    {
        static::$suppressionDepth++;

        try {
            return $callback();
        } finally {
            static::$suppressionDepth--;
        }
    }

    /**
     * True while inside an `allowCrossTenant()` block. For assertions and logging.
     */
    public static function isSuppressed(): bool
    {
        return static::$suppressionDepth > 0;
    }

    /**
     * Test-support only: forget any suppression left behind by a failed test.
     */
    public static function resetSuppression(): void
    {
        static::$suppressionDepth = 0;
    }
}
