<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
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
 *  ENFORCEMENT IS PER-MODEL, AND IT IS NOW REALLY ON FOR A REVIEWED ALLOWLIST.
 * ============================================================================================
 *
 * The switch is no longer one boolean. `config/tenancy.php` carries:
 *   - `enforcement_enabled` -- a kill switch, default TRUE;
 *   - `scoped_models`       -- the reviewed per-model allowlist, which is the ONLY production
 *                              path to a predicate. BATCH 1 holds 6 models;
 *   - `null_tenant`         -- 'open' (default) or 'closed';
 * and that file argues each choice with the measurement behind it. `static::$enabled` remains
 * the ALL-MODELS flip and remains FALSE: 785 of the 1,282 existing `where('store_id', ...)`
 * filters pass a store id other than the current one, so a blanket rollout would compile
 * `store_id = A AND store_id = B` at those sites and return zero rows.
 *
 * For a model that is NOT allowlisted, `apply()` adds no predicate at all, so its SQL -- and
 * therefore its model-cache key -- is byte-for-byte what it was. Attaching the trait without
 * allowlisting stays behaviour-neutral.
 *
 * GATE G2 (the model-cache question) IS SETTLED, GREEN, AND MEASURED.
 *   - 78 of the 95 models use `GeneaLabs\LaravelModelCaching\Traits\Cachable`, and
 *     `config/laravel-model-caching.php` does not exist, so package defaults apply. The open
 *     question was whether its cache keys incorporate global-scope constraints; if they did not,
 *     enabling this scope would make tenant B serve tenant A's warmed rows and so CREATE a
 *     cross-tenant read where none existed.
 *   - `vendor/genealabs` is no longer empty, so the derivation was read rather than guessed:
 *     Traits/Caching.php:160 calls `applyScopesToInstance()`, which at :51-83 invokes
 *     `$scope->apply($this, $this->getModel())` for every global scope BEFORE the key is built.
 *     CacheKey::make() then folds the resulting wheres into the key at CacheKey.php:57 via
 *     `getWhereClauses()`, and the bound store id again at :63 via `getBindingsSlug()`.
 *     So two tenants produce two different keys.
 *   - Proven end to end, not just read: tests/Feature/Tenancy/ModelCacheTenantIsolationTest
 *     warms tenant A, proves the cache is genuinely serving (a stale read after a raw delete),
 *     then asserts tenant B sees only B's row.
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
 * So: a null tenant means NO PREDICATE IS ADDED, by default. This scope therefore FAILS OPEN, and
 * that is an honest statement of what it is -- DEFENCE IN DEPTH behind explicit per-call-site
 * ownership predicates, never a substitute for them. In those four contexts the control of record
 * is, and remains, the explicit predicate at the call site. Anyone reading this class as
 * "tenancy is enforced everywhere" has misread it: it is enforced for the models on
 * `tenancy.scoped_models`, and in the four contexts above even those fall back to the call site.
 *
 * The behaviour is now a stated, configurable DECISION rather than an emergent property:
 * `tenancy.null_tenant` is 'open' (default) or 'closed', and all four contexts are proven under
 * BOTH settings in tests/Feature/Tenancy/NullTenantContextTest. 'closed' is implemented and
 * deliberately not the default -- it empties the storefront, the customer API and every queued
 * job, which that test measures rather than asserts in prose.
 *
 * ============================================================================================
 *  WHAT THIS SCOPE CANNOT SEE -- measured, not assumed.
 * ============================================================================================
 *   - `DB::table()` sites bypass Eloquent entirely, so NO global scope reaches them
 *     (app/Http/Controllers/RoleController.php:182 is a live example). Proven, not assumed, by
 *     EnforcedModelIsolationTest::test_raw_db_table_queries_bypass_the_scope_entirely. The remedy
 *     at those sites is an explicit predicate, and those files are other workstreams'.
 *   - `orWhere` sites WOULD be escapes if the framework did not group the pre-existing where
 *     clauses before appending the scope's. The previous note here marked that UNVERIFIED because
 *     vendor source was unavailable. IT IS NOW VERIFIED, by execution rather than by reading:
 *     EnforcedModelIsolationTest::test_an_or_where_chain_cannot_escape_the_predicate asserts the
 *     compiled SQL nests the disjunction, so the scope cannot be OR-ed away.
 *   - 785 of the 1,282 existing `where('store_id', ...)` filters pass a store id OTHER than the
 *     current one (a storefront `$store->id`, a related row's `store_id`, a request parameter, an
 *     external Zid string, or the hardcoded `1` at app/Models/Utility.php:289 and :896). Against
 *     those, a scope keyed on the CURRENT store compiles `store_id = A AND store_id = B` -> zero
 *     rows. Re-measured 2026-10-12 over app/ packages/ resources/ routes/; the earlier figure of
 *     780 of 1,261 was taken over a narrower file set and is superseded, not contradicted.
 *     This is precisely why the trait is applied SELECTIVELY and why `scoped_models` is a
 *     per-model allowlist rather than a boolean.
 *
 *     NOT every one of those 785 is a hazard, and the distinction is what made batch 1 possible.
 *     A foreign-store-id filter only breaks under the scope when a WEB-GUARD, non-super-admin
 *     principal runs it. The storefront, the customer API and the `customers`/`deliveryboy`
 *     guards have no web-guard principal at all, so the scope adds nothing there and those sites
 *     are untouched. Worse, in the admin API a foreign store id usually arrives from the REQUEST
 *     -- and there the scope turning the query into zero rows is the IDOR being CLOSED, not a
 *     regression. The genuinely dangerous case is narrow: a merchant legitimately operating on a
 *     non-current store they own. See config/tenancy.php for why that defers the big models.
 *
 * Coexistence with the other 497 filters, which do pass the current store, is safe: a scope plus
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
     * The three tenant contexts, kept DISTINCT on purpose.
     *
     * Before this increment `resolveStoreId()` returned null for both "this is the platform
     * operator, show them everything" and "nobody is authenticated, we have no idea who this
     * is". Those are opposite intentions that happened to share a return value, so the
     * super-admin bypass was an ACCIDENT OF A NULL TENANT rather than a decision -- exactly what
     * the brief's item 5 calls out. They are now separate modes with separate handling, and
     * `resolveTenant()` is the single place either one is decided.
     */
    public const MODE_TENANT = 'tenant';
    public const MODE_SUPER_ADMIN = 'super-admin';
    public const MODE_UNRESOLVED = 'unresolved';

    /**
     * THE ALL-MODELS FLIP. Still FALSE, and it should stay that way.
     *
     * This is not the enforcement switch any more. Enforcement is per-model and lives in
     * `config/tenancy.php` -> `scoped_models`. This flag means "constrain EVERY model carrying
     * the trait", which is the unaudited blanket rollout that 785 foreign-store-id call sites
     * make unsafe (see `config/tenancy.php` for the measurement). It is kept because the tests
     * use it to exercise the mechanism on a model that is deliberately NOT allowlisted, and
     * because `withoutGlobalScope(StoreScope::class)` must stay meaningful in every state.
     */
    public static bool $enabled = false;

    /**
     * Depth of the current `allowCrossTenant()` suppression block. A counter rather than a
     * boolean so nested blocks cannot have an inner one re-enable the scope on exit.
     */
    protected static int $suppressionDepth = 0;

    /**
     * Apply the scope.
     *
     * Four outcomes, each one an explicit branch rather than a fallthrough:
     *   - not enforced for this model  -> no predicate (and the SQL is byte-identical to before)
     *   - platform operator            -> no predicate, by deliberate decision
     *   - no resolvable tenant         -> whatever `tenancy.null_tenant` says
     *   - a real tenant                -> the qualified `store_id` predicate
     */
    public function apply(Builder $builder, Model $model): void
    {
        if (! static::isActiveFor($model)) {
            return;
        }

        $tenant = static::resolveTenant();

        if ($tenant['mode'] === self::MODE_SUPER_ADMIN) {
            // THE ONE PLATFORM-OPERATOR BYPASS. Deliberate, named, and distinguishable from an
            // unresolved tenant -- a super admin administers every store, so narrowing their
            // queries to one would hide the platform behind what looks like missing data.
            return;
        }

        if ($tenant['mode'] === self::MODE_UNRESOLVED) {
            static::applyNullTenantPolicy($builder, $model, $tenant['reason']);

            return;
        }

        $column = method_exists($model, 'getStoreColumn') ? $model->getStoreColumn() : 'store_id';

        // Qualified on purpose -- see "AMBIGUOUS COLUMN" in the class docblock.
        $builder->where($model->qualifyColumn($column), $tenant['store_id']);
    }

    /**
     * Is enforcement on for THIS model? The per-model opt-in of the brief's item 2.
     *
     * Precedence, and why:
     *   1. an `allowCrossTenant()` block always wins -- it is the explicit cross-tenant hatch;
     *   2. `tenancy.enforcement_enabled` is a kill switch, so when it is off NOTHING is scoped,
     *      including the all-models flip. A kill switch with exceptions is not a kill switch;
     *   3. the reviewed allowlist in `tenancy.scoped_models`;
     *   4. failing all that, the legacy all-models flip, which is off by default.
     *
     * NOTE. Allowlisting a model that does NOT use the BelongsToStore trait silently achieves
     * nothing, because the trait is what registers this scope at all. That trap is closed by
     * EnforcedModelIsolationTest::test_every_allowlisted_model_actually_carries_the_trait.
     */
    public static function isActiveFor(Model|string $model): bool
    {
        if (static::$suppressionDepth > 0) {
            return false;
        }

        if (! static::configValue('tenancy.enforcement_enabled', true)) {
            return false;
        }

        if (static::isAllowlisted($model)) {
            return true;
        }

        return static::isActive();
    }

    /**
     * Is $model named in `tenancy.scoped_models`?
     */
    public static function isAllowlisted(Model|string $model): bool
    {
        $class = ltrim(is_string($model) ? $model : get_class($model), '\\');

        $allowlist = static::configValue('tenancy.scoped_models', []);

        if (! is_array($allowlist)) {
            return false;
        }

        foreach ($allowlist as $allowed) {
            if (strcasecmp(ltrim((string) $allowed, '\\'), $class) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The whole reviewed allowlist, for reporting and for tests.
     *
     * @return array<int, string>
     */
    public static function allowlistedModels(): array
    {
        $allowlist = static::configValue('tenancy.scoped_models', []);

        return is_array($allowlist)
            ? array_values(array_map(fn ($class) => ltrim((string) $class, '\\'), $allowlist))
            : [];
    }

    /**
     * What to do when there is no tenant to scope to. See `tenancy.null_tenant` for the full
     * argument; the short version is that 'open' is required for the storefront to work at all
     * and is therefore the default, and that this is an acknowledged hole rather than a fix.
     */
    protected static function applyNullTenantPolicy(Builder $builder, Model $model, string $reason): void
    {
        if (static::configValue('tenancy.log_null_tenant', false)) {
            try {
                \Illuminate\Support\Facades\Log::debug('tenancy.null_tenant', [
                    'model' => get_class($model),
                    'reason' => $reason,
                    'policy' => static::nullTenantPolicy(),
                ]);
            } catch (Throwable $e) {
                // Logging must never be the reason a query fails.
            }
        }

        if (static::nullTenantPolicy() === 'closed') {
            $builder->whereRaw('1 = 0');
        }

        // 'open': add NOTHING. Never `where(store_id, null)` -- see the R4-02 trap above.
    }

    /**
     * 'open' or 'closed'. Anything unrecognised is treated as 'open', because the alternative is
     * that a typo in a config file silently empties the storefront.
     */
    public static function nullTenantPolicy(): string
    {
        return strtolower(trim((string) static::configValue('tenancy.null_tenant', 'open'))) === 'closed'
            ? 'closed'
            : 'open';
    }

    /**
     * Read config without assuming a container exists. A plain unit test and early bootstrap
     * both run with no application, and a global scope must not explode there.
     *
     * @param  mixed  $default
     * @return mixed
     */
    protected static function configValue(string $key, $default)
    {
        try {
            $value = config($key);

            return $value === null ? $default : $value;
        } catch (Throwable $e) {
            return $default;
        }
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
        $tenant = static::resolveTenant();

        return $tenant['mode'] === self::MODE_TENANT ? $tenant['store_id'] : null;
    }

    /**
     * THE SINGLE TENANT DECISION POINT. Everything about "who is the tenant, and if there isn't
     * one, why not" is decided here and nowhere else.
     *
     * `resolveStoreId()` above collapses this to `?int` and keeps its original contract (null for
     * a super admin AND null for an unresolved tenant), because the BelongsToStore helpers and
     * the existing tests depend on that shape. `apply()` uses the richer form so that the
     * platform-operator bypass is a named branch instead of a shared null.
     *
     * @return array{mode: string, store_id: int|null, reason: string}
     */
    public static function resolveTenant(): array
    {
        try {
            $user = auth()->user();
        } catch (Throwable $e) {
            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'reason' => 'no auth container (console bootstrap or plain unit test)',
            ];
        }

        // Anonymous, or authenticated on the `customers` / `deliveryboy` guard, or a job or
        // command with no authenticated principal at all. See the R4-02 trap.
        if (! $user) {
            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'reason' => 'no web-guard principal (job, command, storefront, or customers/deliveryboy guard)',
            ];
        }

        if (static::isSuperAdmin($user)) {
            return [
                'mode' => self::MODE_SUPER_ADMIN,
                'store_id' => null,
                'reason' => 'platform operator: deliberate cross-store visibility',
            ];
        }

        // `current_store` is read through the one existing resolver so there is exactly one
        // definition of "the current tenant" in the codebase.
        $storeId = function_exists('getCurrentStore') ? getCurrentStore() : ($user->current_store ?? null);

        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'reason' => 'authenticated user has no resolvable current_store',
            ];
        }

        return [
            'mode' => self::MODE_TENANT,
            'store_id' => (int) $storeId,
            'reason' => 'resolved from the web guard',
        ];
    }

    /**
     * Which of the three modes the current request is in. For assertions and reporting.
     */
    public static function tenantMode(): string
    {
        return static::resolveTenant()['mode'];
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
