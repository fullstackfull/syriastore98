<?php

namespace App\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * R4-01 / R4-02 / R4-04 / R4-05 — the tenant-isolation global scope.
 *
 * Laravel-native: one `Illuminate\Database\Eloquent\Scope` (NOT
 * `Illuminate\Database\Eloquent\Scopes\Scope`, which DOES NOT EXIST in Laravel 11 -- that
 * exact FQCN was shipped once in this project and fatalled all 11 trait-carrying models,
 * and `php -l` cannot catch it, so it is spelled out here rather than left to memory),
 * attached by the
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
     * THE REQUEST CONTEXTS, which are the answer to why batch 2 had to be reverted.
     *
     * ============================================================================================
     *  ONE PREDICATE SERVED FIVE CONTEXTS. THAT -- NOT "Cart is unscopeable" -- WAS THE DEFECT.
     * ============================================================================================
     *
     * Cart and Customer were allowlisted last increment and reverted, and the measured reason is
     * recorded in tests/Feature/Tenancy/StorefrontWebGuardScopeTest. The mechanism is structural:
     *
     *   - routes/customer.php is `require`d from routes/web.php:1222, so every storefront route
     *     sits INSIDE the `web` middleware group (the router reports status.cancel as
     *     [web, ActiveTheme, themelanguage]);
     *   - config/session.php:146 sets the session cookie path to '/';
     *   - storefronts are path-prefixed (`{storeSlug?}`, 106 declarations in routes/customer.php)
     *     on the SAME host.
     *
     * So a merchant or staff member with a live admin session is a POPULATED WEB-GUARD PRINCIPAL
     * on every storefront request, and `getCurrentStore()` returns their ADMIN store -- never the
     * store whose storefront they are looking at. The storefront's own reads filter on the
     * SLUG-resolved store (app/Models/Cart.php:166 and :208;
     * app/Http/Controllers/Customer/Auth/CustomerLoginController.php:87, :585, :751, :796, :847,
     * all via `getStore()` at app/Helper/helper.php:2332, which reads
     * `request()->route('storeSlug')`). ANDing an admin-derived predicate onto those produced
     * `store_id = <slug store> AND store_id = <admin store>` -> ZERO ROWS, silently.
     *
     * The repair is to stop asking one predicate to serve five different questions. The tenant is
     * now resolved PER CONTEXT, and each context is a named constant so the decision is visible in
     * `resolveTenant()`'s return value and assertable in a test rather than implied.
     */
    public const CONTEXT_SUPER_ADMIN = 'super-admin';
    public const CONTEXT_MERCHANT_ADMIN = 'merchant-admin';
    public const CONTEXT_STOREFRONT_WEB = 'storefront-web';
    public const CONTEXT_STOREFRONT_PUBLIC = 'storefront-public';
    public const CONTEXT_EXPLICIT = 'explicit';
    public const CONTEXT_CONSOLE = 'console';
    public const CONTEXT_UNRESOLVED = 'unresolved';

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
     * The stack of explicit tenant contexts opened by `forStoreContext()`.
     *
     * A STACK, not a single value, so a nested block cannot have its exit re-open the outer
     * block's tenant, and so an exception inside one cannot leave an ambient tenant behind. It is
     * empty by default and is NEVER populated implicitly, which is what makes the stale-context
     * trap -- a queue worker serving job N+1 with job N's tenant -- structurally impossible.
     *
     * @var array<int, array<int, int>>
     */
    protected static array $explicitContextStack = [];

    /**
     * Memo of store slug => store id (or null for "no such slug") for the current application
     * instance. See `storeIdForSlug()` for why it is keyed on the application object.
     *
     * @var array<string, int|null>
     */
    protected static array $slugStoreIdMemo = [];

    /**
     * The application instance `$slugStoreIdMemo` belongs to.
     */
    protected static ?int $slugStoreIdMemoAppId = null;

    /**
     * Guard against re-entering the slug `stores` lookup from inside itself.
     */
    protected static bool $resolvingStorefrontStore = false;

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
        $qualified = $model->qualifyColumn($column);

        $storeIds = $tenant['store_ids'];

        // ONE ADMISSIBLE STORE -> the SAME single-column equality this scope has always
        // emitted, bindings included. That is not a micro-optimisation: it is what keeps the
        // emitted SQL -- and therefore every `genealabs/laravel-model-caching` cache key on the
        // 78 models using `Cachable` -- byte-for-byte identical for a single-store merchant,
        // which is the overwhelming majority of principals. See `admissibleStoreIds()`.
        if (count($storeIds) === 1) {
            $builder->where($qualified, $storeIds[0]);

            return;
        }

        $builder->whereIn($qualified, $storeIds);
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
     * @return array{mode: string, store_id: int|null, store_ids: array<int, int>, reason: string}
     */
    public static function resolveTenant(): array
    {
        // CONTEXT 1 -- AN EXPLICIT CONTEXT, which is the ONLY tenant a queued job, a scheduled
        // command or a test ever gets, and it always wins. It is a stack restored in a `finally`
        // (see forStoreContext()), so it cannot leak out of the block that opened it.
        $explicit = static::explicitStoreIds();

        if ($explicit !== null) {
            return [
                'mode' => self::MODE_TENANT,
                'store_id' => $explicit[0],
                'store_ids' => $explicit,
                'reason' => 'explicit context opened by forStoreContext()',
                'context' => self::CONTEXT_EXPLICIT,
            ];
        }

        try {
            $user = auth()->user();
        } catch (Throwable $e) {
            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'store_ids' => [],
                'reason' => 'no auth container (console bootstrap or plain unit test)',
                'context' => self::CONTEXT_CONSOLE,
            ];
        }

        // The slug-resolved store: the tenant the STOREFRONT is actually serving. Null on every
        // non-storefront request and in console. Resolved here, once, for both branches below.
        $slugStoreId = static::storefrontStoreId();

        if ($user && static::isSuperAdmin($user)) {
            // THE ONE PLATFORM-OPERATOR BYPASS, unchanged and deliberately ahead of the storefront
            // branch: narrowing a super admin to the storefront they happen to be viewing would
            // hide the platform behind what looks like missing data, which is the same failure
            // mode this whole class is built to avoid.
            return [
                'mode' => self::MODE_SUPER_ADMIN,
                'store_id' => null,
                'store_ids' => [],
                'reason' => 'platform operator: deliberate cross-store visibility',
                'context' => self::CONTEXT_SUPER_ADMIN,
            ];
        }

        // CONTEXT 2 -- PUBLIC / CUSTOMER STOREFRONT. No web-guard principal: an anonymous
        // visitor, a shopper on the `customers` guard, a delivery boy on `deliveryboy`, a queued
        // job or a console command. See the R4-02 trap.
        //
        // WHY THE `customers` GUARD IS NOT CONSULTED HERE, and this is a correctness constraint
        // rather than a simplification: the customers guard's provider loads App\Models\Customer,
        // Customer is an allowlisted model, so that load runs through this very scope -- calling
        // `auth('customers')->check()` from inside `resolveTenant()` would re-enter
        // `resolveTenant()` and recurse without bound. The web guard is safe to consult because
        // `User` carries no `store_id` and is on the documented DO-NOT-SCOPE list
        // (app/Models/Concerns/BelongsToStore.php), so it is never scoped and never re-enters.
        // The customer and anonymous cases therefore share one context, which is accurate: the
        // `web` guard genuinely cannot tell them apart.
        if (! $user) {
            if ($slugStoreId !== null) {
                // 'slug_only' TIGHTENS this from fail-open to the slug store. It is NOT the
                // default, because that is a NARROWING of queries that work today and this
                // project has already shipped a storefront-blanking control twice. See
                // `tenancy.storefront_tenant`.
                if (static::storefrontTenantMode() === 'slug_only') {
                    return [
                        'mode' => self::MODE_TENANT,
                        'store_id' => $slugStoreId,
                        'store_ids' => [$slugStoreId],
                        'reason' => 'public/customer storefront, scoped to the slug-resolved store',
                        'context' => self::CONTEXT_STOREFRONT_PUBLIC,
                    ];
                }

                return [
                    'mode' => self::MODE_UNRESOLVED,
                    'store_id' => null,
                    'store_ids' => [],
                    'reason' => 'public/customer storefront (slug-resolved store ' . $slugStoreId
                        . '); fail-open by tenancy.storefront_tenant=additive',
                    'context' => self::CONTEXT_STOREFRONT_PUBLIC,
                ];
            }

            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'store_ids' => [],
                'reason' => 'no web-guard principal (job, command, storefront, or customers/deliveryboy guard)',
                'context' => static::hasResolvedRoute() ? self::CONTEXT_UNRESOLVED : self::CONTEXT_CONSOLE,
            ];
        }

        // `current_store` is read through the one existing resolver so there is exactly one
        // definition of "the current tenant" in the codebase.
        $storeId = function_exists('getCurrentStore') ? getCurrentStore() : ($user->current_store ?? null);

        if ($storeId === null || $storeId === '' || ! is_numeric($storeId)) {
            // A principal with no usable `current_store` ON A STOREFRONT still has a tenant: the
            // store the URL names. Falling back to "unresolved" here would be fail-open on a
            // request where the correct answer is known.
            if ($slugStoreId !== null) {
                return [
                    'mode' => self::MODE_TENANT,
                    'store_id' => $slugStoreId,
                    'store_ids' => [$slugStoreId],
                    'reason' => 'storefront request by a principal with no resolvable current_store',
                    'context' => self::CONTEXT_STOREFRONT_WEB,
                ];
            }

            return [
                'mode' => self::MODE_UNRESOLVED,
                'store_id' => null,
                'store_ids' => [],
                'reason' => 'authenticated user has no resolvable current_store',
                'context' => self::CONTEXT_MERCHANT_ADMIN,
            ];
        }

        $ownedIds = static::admissibleStoreIds($user, (int) $storeId);

        // CONTEXT 3 -- STOREFRONT, WEB-GUARD PRINCIPAL. THE CASE THAT BROKE BATCH 2.
        //
        // The admissible set ADMITS THE SLUG-RESOLVED STORE, so a storefront read of the form
        // `where('store_id', $slugStore->id)` returns its rows again instead of nothing.
        //
        // WHY THE SLUG STORE IS *ADDED* TO THE OWNED SET RATHER THAN REPLACING IT (default
        // 'additive'), and this is the single most important safety property of this change:
        //
        //   1. IT CANNOT BLANK A PAGE THAT WORKS TODAY. The set stays a STRICT SUPERSET of the
        //      owned-stores set, which is itself a strict superset of `= current_store`. That is
        //      the same by-construction argument that made batch 2 shippable, and it is what a
        //      replace would throw away -- replacing would NARROW every already-allowlisted model
        //      (Menu, SupportTicket, SupportConversion, FlashSaleCondition, ThemeCustomizeOrder,
        //      NotifyUser, OrderRefund, OrderNote) on storefront requests, and this project has
        //      shipped a storefront-blanking control twice already.
        //   2. IT GRANTS NO READ THAT IS NOT ALREADY GRANTED. On the very same URL an ANONYMOUS
        //      visitor resolves no tenant and gets NO predicate at all (`tenancy.null_tenant` =
        //      'open'), so the slug store's rows are already fully admissible to anyone who can
        //      load the page. Admitting that one store for an authenticated principal therefore
        //      widens nothing: it removes a predicate that was WRONG, not one that was holding.
        //   3. THE ADMIN-CONTEXT DENIAL IS UNAFFECTED. An admin route carries no `storeSlug` and
        //      no ActiveTheme middleware, so `$slugStoreId` is null there and the admissible set
        //      is the owned set exactly. A merchant reading another merchant's cart from the admin
        //      panel or the admin API is still denied -- which is the whole point of enabling
        //      these models.
        //
        // 'slug_only' is available for an operator who wants the strictly stronger per-context
        // predicate and accepts the narrowing. Both branches are exercised by
        // tests/Feature/Tenancy/StorefrontContextTenantResolutionTest.
        if ($slugStoreId !== null) {
            if (static::storefrontTenantMode() === 'slug_only') {
                return [
                    'mode' => self::MODE_TENANT,
                    'store_id' => $slugStoreId,
                    'store_ids' => [$slugStoreId],
                    'reason' => 'storefront request, scoped to the slug-resolved store only',
                    'context' => self::CONTEXT_STOREFRONT_WEB,
                ];
            }

            $ids = $ownedIds;

            if (! in_array($slugStoreId, $ids, true)) {
                $ids[] = $slugStoreId;
                sort($ids);
            }

            return [
                'mode' => self::MODE_TENANT,
                // `store_id` stays the ADMIN store: it is what `resolveStoreId()` has always
                // returned and what the BelongsToStore helpers compare against, and widening an
                // authorization helper is a permission change wearing a tenancy change's clothes.
                'store_id' => (int) $storeId,
                'store_ids' => $ids,
                'reason' => 'storefront request; slug-resolved store ' . $slugStoreId
                    . ' admitted alongside the owned stores',
                'context' => self::CONTEXT_STOREFRONT_WEB,
            ];
        }

        // CONTEXT 4 -- MERCHANT ADMINISTRATION. The owned-stores predicate, unchanged.
        return [
            'mode' => self::MODE_TENANT,
            'store_id' => (int) $storeId,
            'store_ids' => $ownedIds,
            'reason' => 'resolved from the web guard',
            'context' => self::CONTEXT_MERCHANT_ADMIN,
        ];
    }

    /**
     * Which named context the current request is in. For assertions, logging and reporting.
     */
    public static function currentContext(): string
    {
        return static::resolveTenant()['context'] ?? self::CONTEXT_UNRESOLVED;
    }

    /**
     * 'additive' (default) or 'slug_only'. Anything unrecognised means 'additive', for the same
     * reason `nullTenantPolicy()` treats a typo as 'open': a config mistake must not be able to
     * take the shop offline.
     */
    public static function storefrontTenantMode(): string
    {
        return strtolower(trim((string) static::configValue('tenancy.storefront_tenant', 'additive'))) === 'slug_only'
            ? 'slug_only'
            : 'additive';
    }

    /**
     * THE STOREFRONT TENANT: the store id the current REQUEST names, or null if this is not a
     * storefront request.
     *
     * Two resolution steps, in order, and nothing else:
     *
     *   1. the `storeSlug` ROUTE PARAMETER. This is the one parameter the storefront group uses
     *      (106 declarations in routes/customer.php, plus package storefront routes), and it is
     *      the exact value `getStore()` reads at app/Helper/helper.php:2332, so the scope and the
     *      storefront's own reads resolve the SAME store from the SAME source. `slug` is
     *      deliberately NOT consulted: on `{storeSlug?}/article/{slug}` and
     *      `{storeSlug?}/product/{product_slug}` that parameter is the ARTICLE or PRODUCT slug, so
     *      reading it would resolve a confidently WRONG tenant. (EnsureCustomerAuthenticated.php:63
     *      does fall back to `slug`; that file is another workstream's and is not copied here.)
     *   2. failing that -- `{storeSlug?}` is OPTIONAL, so a storefront route can match with the
     *      parameter absent -- the FIRST PATH SEGMENT, but ONLY for a route that carries the
     *      ActiveTheme middleware. That is exactly what ActiveTheme itself does
     *      (app/Http/Middleware/ActiveTheme.php:21 `Store::where('slug', request()->segment(1))`),
     *      and gating it on the middleware is what stops `/admin/...`, `/api/...` or `/login`
     *      from ever being read as a store slug.
     *
     * Returns null -- never a guess -- when there is no request, no route, no slug, or no store
     * with that slug.
     */
    protected static function storefrontStoreId(): ?int
    {
        // Re-entrancy belt: the `stores` lookup below must never be able to ask for itself.
        if (static::$resolvingStorefrontStore) {
            return null;
        }

        try {
            $request = request();

            if (! $request) {
                return null;
            }

            // THE CONSOLE / QUEUE-WORKER DISCRIMINATOR, and it is `route() === null` rather than
            // `app()->runningInConsole()` FOR A MEASURED REASON. Laravel answers
            // runningInConsole() from PHP_SAPI, which is 'cli' under PHPUnit, so gating on it
            // would have switched this entire resolver off in every test while leaving it on in
            // production -- a vacuous suite proving nothing. A queue worker and an artisan command
            // both have a Request synthesised from $_SERVER, so `request()` is never null there,
            // but NO ROUTE WAS MATCHED, so `route()` is null. That discriminator behaves
            // identically in a test and in production, which is the only kind worth having.
            $route = $request->route();

            if (! $route) {
                return null;
            }

            $slug = $route->parameter('storeSlug');

            // Route::bind('storeSlug') exists in app/Providers/StoreRedirectServiceProvider.php
            // and can return a model or a RedirectResponse rather than a string. That provider is
            // NOT registered in bootstrap/providers.php today, so the parameter is a plain string
            // -- but the type check below means this resolver stays correct if it ever is.
            if (! is_string($slug) || $slug === '') {
                $slug = static::routeIsStorefront($route) ? $request->segment(1) : null;
            }

            if (! is_string($slug) || $slug === '') {
                return null;
            }

            return static::storeIdForSlug($slug);
        } catch (Throwable $e) {
            // A scope must never be the reason a request fails. No storefront tenant resolved
            // means the other branches decide, exactly as before this method existed.
            return null;
        }
    }

    /**
     * Does this route belong to the storefront group? Measured from the route's own middleware,
     * not from a URL pattern, because the group is declared once --
     * `Route::middleware([ActiveTheme::class, 'themelanguage'])` at routes/customer.php:49 --
     * and every storefront route inherits it.
     */
    protected static function routeIsStorefront($route): bool
    {
        try {
            foreach ((array) $route->middleware() as $middleware) {
                if (is_string($middleware) && str_contains($middleware, 'ActiveTheme')) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            return false;
        }

        return false;
    }

    /**
     * slug -> store id, through a raw `DB::table()` query.
     *
     * RAW ON PURPOSE, three reasons: `Store` uses `Cachable`, so an Eloquent read here would
     * enter the model-cache key machinery from inside query compilation; a raw query carries no
     * global scopes, so it cannot re-enter this class; and it is one indexed lookup.
     *
     * Memoised per application instance for the same reason `$ownedStoreIdMemo` is -- PHPUnit
     * keeps statics for the whole process while RefreshDatabase rebuilds the schema and boots a
     * new application per test, so a memo that outlived its database would hand one test the
     * store ids of another test's dropped tables. A null result is memoised too: an unknown slug
     * must not cost a query per model per request.
     */
    protected static function storeIdForSlug(string $slug): ?int
    {
        static::forgetSlugMemoIfStale();

        if (array_key_exists($slug, static::$slugStoreIdMemo)) {
            return static::$slugStoreIdMemo[$slug];
        }

        static::$resolvingStorefrontStore = true;

        try {
            $id = DB::table('stores')->where('slug', $slug)->value('id');
            $id = is_numeric($id) ? (int) $id : null;
        } catch (Throwable $e) {
            // No database, no `stores` table, or a connection mid-migration. Do NOT memoise a
            // failure as "no such store": the next call may well succeed.
            static::$resolvingStorefrontStore = false;

            return null;
        } finally {
            static::$resolvingStorefrontStore = false;
        }

        return static::$slugStoreIdMemo[$slug] = $id;
    }

    /**
     * Drop the slug memo when the application it was built against has been replaced.
     */
    protected static function forgetSlugMemoIfStale(): void
    {
        try {
            $appId = spl_object_id(app());
        } catch (Throwable $e) {
            static::$slugStoreIdMemo = [];
            static::$slugStoreIdMemoAppId = null;

            return;
        }

        if (static::$slugStoreIdMemoAppId !== $appId) {
            static::$slugStoreIdMemo = [];
            static::$slugStoreIdMemoAppId = $appId;
        }
    }

    /**
     * Test-support only: forget the slug memo. Needed by a test that renames or creates a store
     * slug after a scoped query has already run in the same application instance.
     */
    public static function forgetSlugStoreIds(): void
    {
        static::$slugStoreIdMemo = [];
        static::$slugStoreIdMemoAppId = null;
    }

    /**
     * Did the router match a route for this process at all?
     *
     * FALSE means console, queue worker, or a plain unit test with no request lifecycle -- the
     * contexts that have no request and therefore no storefront. See the note in
     * `storefrontStoreId()` for why this and not `app()->runningInConsole()`.
     */
    protected static function hasResolvedRoute(): bool
    {
        try {
            $request = request();

            return $request !== null && $request->route() !== null;
        } catch (Throwable $e) {
            return false;
        }
    }

    /**
     * ESCAPE HATCH 3 -- RUN A CALLBACK IN AN EXPLICIT TENANT CONTEXT.
     *
     * THIS IS THE ANSWER TO THE BACKGROUND-JOB / CONSOLE QUESTION, and it is a decision rather
     * than a default. A queued job and a scheduled command have no request, no slug and no
     * web-guard principal, so `resolveTenant()` returns MODE_UNRESOLVED and
     * `tenancy.null_tenant` = 'open' means NO PREDICATE. That fail-open is retained as the
     * DEFAULT because 'closed' would silently stop every job (routes/console.php drives the Zid
     * sync), and a control that stops the scheduled work would simply be switched off.
     *
     * But fail-open on a scoped Product or Customer is a much bigger hole than on a scoped Menu,
     * so the fix is not a different default -- it is giving a job a way to SAY which tenant it is
     * acting for, in one line, at the top of its handle():
     *
     *     StoreScope::forStoreContext($this->storeId, fn () => $this->sync());
     *
     * Inside the callback the admissible set is exactly the ids given, for every model, whoever
     * happens to be authenticated. It is a STACK restored in a `finally`, so:
     *   - an exception cannot leave a context behind;
     *   - nesting cannot have an inner block re-open an outer one's tenant on exit;
     *   - and NO CONTEXT IS EVER INHERITED. A job that does not open one gets no context, so the
     *     stale-context trap -- a worker process serving job N+1 with job N's tenant -- cannot
     *     occur: there is no ambient state to go stale. That property is asserted directly by
     *     StorefrontContextTenantResolutionTest::test_an_explicit_context_does_not_leak_past_its_block.
     *
     * It is also how a test drives a context deterministically, which the request-derived
     * branches cannot do from a plain Eloquent call with no route.
     *
     * @template TReturn
     * @param  int|array<int, int>  $storeIds
     * @param  callable():TReturn  $callback
     * @return TReturn
     */
    public static function forStoreContext($storeIds, callable $callback)
    {
        $ids = array_values(array_unique(array_map(
            static fn ($id) => (int) $id,
            is_array($storeIds) ? $storeIds : [$storeIds]
        )));

        if ($ids === []) {
            throw new \InvalidArgumentException(
                'StoreScope::forStoreContext() needs at least one store id. An empty context '
                . 'would compile to `store_id IN ()`, which is a silent zero-row query -- say '
                . 'what you mean with allowCrossTenant() instead.'
            );
        }

        sort($ids);

        static::$explicitContextStack[] = $ids;

        try {
            return $callback();
        } finally {
            array_pop(static::$explicitContextStack);
        }
    }

    /**
     * The innermost explicit context, or null when none is open.
     *
     * @return array<int, int>|null
     */
    protected static function explicitStoreIds(): ?array
    {
        $depth = count(static::$explicitContextStack);

        return $depth === 0 ? null : static::$explicitContextStack[$depth - 1];
    }

    /**
     * True while inside a `forStoreContext()` block. For assertions and logging.
     */
    public static function hasExplicitContext(): bool
    {
        return static::$explicitContextStack !== [];
    }

    /**
     * Test-support only: forget any explicit context left behind by a failed test.
     */
    public static function resetExplicitContext(): void
    {
        static::$explicitContextStack = [];
    }

    /**
     * THE SET OF STORES THE PREDICATE ADMITS. This is the answer to the constraint that defeated
     * the previous increment, so the argument is written out in full.
     *
     * ============================================================================================
     *  THE PROBLEM, RE-ESTABLISHED FROM SOURCE RATHER THAN INHERITED.
     * ============================================================================================
     *
     * The admin API resolves its tenant FROM THE REQUEST, not from the session:
     * app/Http/Controllers/Api/DashboardController.php takes `$request->store_id` at ~100 sites
     * and authorises it through `resolveOwnedStore()` (:3627-3641), which filters
     * `Store::where('created_by', $user->creatorId())` -- that is, BY OWNERSHIP, and deliberately
     * NOT by `current_store`.
     *
     * And that request really does carry a web-guard principal, which is the fact the earlier
     * deferral left unproven: app/Http/Middleware/CustomAuth.php:80-81 answers a `User` token with
     * `auth()->login($tokenable)` on the DEFAULT guard. So `auth()->user()` is populated,
     * `resolveTenant()` returns MODE_TENANT, and the scope is live on every admin API call.
     *
     * A predicate of `store_id = current_store` therefore FIGHTS the controller: a merchant who
     * owns stores 5 and 7, whose `current_store` is 5, calling `GET /productlist?store_id=7`
     * passes `resolveOwnedStore()` and then meets `store_id = 7 AND store_id = 5` -> zero rows.
     * A legitimate, authorised request answers empty with no error. That is why Product, Order,
     * Category, Cart, Customer, Setting and Shipping were all deferred.
     *
     * ============================================================================================
     *  THE FIX: SCOPE TO WHAT THE PRODUCT ITSELF CALLS AUTHORISED.
     * ============================================================================================
     *
     * The predicate admits `{current_store} UNION {stores whose created_by = creatorId()}`, which
     * is exactly `resolveOwnedStore()`'s own test plus today's value. Two properties follow, and
     * both are asserted in tests/Feature/Tenancy/OwnedStoresTenantResolutionTest:
     *
     *   1. IT IS A STRICT SUPERSET OF THE OLD PREDICATE, because `current_store` is always a
     *      member. So no query that returns rows today can return fewer under it. That is the
     *      property that makes this safe to ship: the failure mode this scope has already caused
     *      twice in this project -- a silently blank page, not an error -- is ruled out by
     *      construction rather than by hoping the test suite covers every page.
     *
     *   2. IT STILL DENIES A STORE THE PRINCIPAL DOES NOT OWN. `store_id = 9 AND store_id IN (5,7)`
     *      is zero rows, so the ~70 sites still doing a bare `Store::find($request->store_id)`
     *      with no ownership check have their IDOR closed by the scope rather than widened by it.
     *
     * WHAT IT DOES NOT FIX, stated plainly. `current_store` is a mutable, attacker-writable column
     * (R4-03), and including it keeps the scope keyed partly on attacker-controlled state. That is
     * NOT a regression -- the old predicate was `= current_store` and so was keyed ENTIRELY on it,
     * so exposure on that axis is unchanged and strictly reduced for multi-store owners. Fixing
     * `current_store` integrity is the authorization workstream's, not this scope's.
     *
     * WHY `creatorId()` AND NOT `created_by`. User.php:95-104 returns `$this->id` for an `admin` or
     * `super admin` and `$this->created_by` otherwise, so STAFF resolve to their employer and keep
     * access to their employer's stores. Using `created_by` directly would lock every staff member
     * out of every store. This is the same helper the controller authorises with, on purpose:
     * two different definitions of "this merchant's stores" is how the previous attempt failed.
     *
     * @param  mixed  $user
     * @return array<int, int> at least one id, ascending, de-duplicated
     */
    public static function admissibleStoreIds($user, int $currentStoreId): array
    {
        // 'current_store' restores the pre-batch-2 predicate exactly, for an operator who serves a
        // single store per merchant and wants the strictest possible filter. Both modes are
        // exercised in OwnedStoresTenantResolutionTest, so neither is a claim in a comment.
        if (static::tenantScopeMode() === 'current_store') {
            return [$currentStoreId];
        }

        $ids = [$currentStoreId];

        foreach (static::ownedStoreIds($user) as $ownedId) {
            if (! in_array($ownedId, $ids, true)) {
                $ids[] = $ownedId;
            }
        }

        // Ascending and de-duplicated so the compiled bindings -- and so the model-caching key
        // derived from them (CacheKey::getBindingsSlug) -- are stable for a given principal
        // regardless of the order `stores` happens to come back in.
        sort($ids);

        return $ids;
    }

    /**
     * 'owned_stores' (default) or 'current_store'. An unrecognised value means 'owned_stores',
     * because that is the branch that cannot blank a working page -- the same reasoning as
     * `nullTenantPolicy()`, where a config typo must not take the shop offline.
     */
    public static function tenantScopeMode(): string
    {
        return strtolower(trim((string) static::configValue('tenancy.tenant_scope', 'owned_stores'))) === 'current_store'
            ? 'current_store'
            : 'owned_stores';
    }

    /**
     * Memo of creatorId => owned store ids, so a request pays for this lookup ONCE however many
     * scoped models it touches.
     *
     * @var array<int, array<int, int>>
     */
    protected static array $ownedStoreIdMemo = [];

    /**
     * The application instance the memo above belongs to.
     *
     * THE MEMO MUST NOT OUTLIVE ITS DATABASE. PHPUnit keeps static state for the whole process
     * while RefreshDatabase rebuilds the schema and boots a new application per test, so a memo
     * keyed only on creatorId would hand test N+1 the store ids of test N's dropped tables -- a
     * tenancy test passing or failing on stale data, which is the worst kind of green. Keying on
     * the application's object id makes the memo request-scoped (and test-scoped) without needing
     * any cooperation from tests/TestCase.php, which this workstream does not own.
     */
    protected static ?int $ownedStoreIdMemoAppId = null;

    /**
     * Guard against re-entering the `stores` lookup from inside itself.
     *
     * `Store` carries no `store_id` column and is asserted platform-global by
     * TenantModelClassificationTest, so it cannot carry this scope and cannot recurse through
     * Eloquent. The lookup below is nonetheless a raw `DB::table()` query -- no Eloquent, no
     * global scopes, no `Cachable` -- so it cannot re-enter the model-cache key machinery either.
     * The flag is the third, cheap belt for a method that runs inside query compilation.
     */
    protected static bool $resolvingOwnedStoreIds = false;

    /**
     * The store ids this principal owns, per `stores.created_by` = `creatorId()`.
     *
     * Fails to an EMPTY array on any problem -- no container, no `stores` table (early migration,
     * console bootstrap), an unresolvable creator. Empty means `admissibleStoreIds()` falls back to
     * `[current_store]`, i.e. exactly the old predicate, so a failure here degrades to today's
     * behaviour and never to zero rows and never to no predicate at all.
     *
     * @param  mixed  $user
     * @return array<int, int>
     */
    protected static function ownedStoreIds($user): array
    {
        if (static::$resolvingOwnedStoreIds) {
            return [];
        }

        $creatorId = static::creatorIdOf($user);

        if ($creatorId === null) {
            return [];
        }

        static::forgetOwnedStoreMemoIfStale();

        if (array_key_exists($creatorId, static::$ownedStoreIdMemo)) {
            return static::$ownedStoreIdMemo[$creatorId];
        }

        static::$resolvingOwnedStoreIds = true;

        try {
            $ids = array_values(array_map(
                static fn ($id) => (int) $id,
                DB::table('stores')->where('created_by', $creatorId)->pluck('id')->all()
            ));
        } catch (Throwable $e) {
            // No database, no `stores` table, or a connection that is mid-migration. Degrade to
            // the old predicate rather than to a broken one.
            $ids = [];
        } finally {
            static::$resolvingOwnedStoreIds = false;
        }

        return static::$ownedStoreIdMemo[$creatorId] = $ids;
    }

    /**
     * `creatorId()` when the principal has it (User.php:95), else the conservative equivalent.
     *
     * @param  mixed  $user
     */
    protected static function creatorIdOf($user): ?int
    {
        try {
            if (is_object($user) && method_exists($user, 'creatorId')) {
                $creatorId = $user->creatorId();

                return is_numeric($creatorId) ? (int) $creatorId : null;
            }
        } catch (Throwable $e) {
            // Fall through to the attribute reading below.
        }

        $fallback = $user->created_by ?? ($user->id ?? null);

        return is_numeric($fallback) ? (int) $fallback : null;
    }

    /**
     * Drop the memo when the application it was built against has been replaced.
     */
    protected static function forgetOwnedStoreMemoIfStale(): void
    {
        try {
            $appId = spl_object_id(app());
        } catch (Throwable $e) {
            // No container: do not memoise at all rather than memoise against nothing.
            static::$ownedStoreIdMemo = [];
            static::$ownedStoreIdMemoAppId = null;

            return;
        }

        if (static::$ownedStoreIdMemoAppId !== $appId) {
            static::$ownedStoreIdMemo = [];
            static::$ownedStoreIdMemoAppId = $appId;
        }
    }

    /**
     * Test-support only: forget the owned-store memo. Needed by a test that creates a store
     * AFTER a scoped query has already run in the same application instance.
     */
    public static function forgetOwnedStoreIds(): void
    {
        static::$ownedStoreIdMemo = [];
        static::$ownedStoreIdMemoAppId = null;
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
