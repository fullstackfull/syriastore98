<?php

/**
 * TENANT ISOLATION CONFIGURATION.
 *
 * This file is referenced by App\Scopes\StoreScope::isActive() (was StoreScope.php:138) and did
 * NOT exist before this increment, so the scope fell through to its static default on every call
 * and the config read was dead code. It exists now, and it is the single place where tenant
 * enforcement is turned on or off.
 *
 * ============================================================================================
 *  THE ARCHITECTURE IS UNCHANGED. ONE shared database, ONE `store_id` discriminator, ONE tenant
 *  resolver (`getCurrentStore()` at app/Helper/helper.php:357-381). This file adds no second
 *  tenancy mechanism, no second resolver and no schema change. It only decides which models the
 *  EXISTING global scope is allowed to constrain.
 * ============================================================================================
 *
 * WHY A PER-MODEL ALLOWLIST AND NOT A SINGLE BOOLEAN.
 *
 * `StoreScope::$enabled = true` would have constrained every model carrying the BelongsToStore
 * trait at once. That is unsafe here, and the reason is measured rather than assumed:
 *
 *   $ grep -rn "where('store_id'" app/ packages/ resources/ routes/ --include=*.php | wc -l
 *   1282
 *   $ ... | grep -c "getCurrentStore()"
 *   497
 *   $ ... | grep -vc "getCurrentStore()"
 *   785
 *
 * 785 of the 1,282 existing `store_id` filters pass a store id that is NOT the current store. A
 * scope keyed on the current store composes with those into `store_id = A AND store_id = B`,
 * which matches no row. Enabling everything at once would therefore turn working pages into
 * silently empty ones. An allowlist makes enabling one model a reviewed, one-line decision that
 * cannot accidentally become 95.
 *
 * WHAT A MODEL MUST SATISFY BEFORE IT GOES ON `scoped_models`. All four, with evidence:
 *   1. Its table really has a `store_id` column. Verified against the LIVE migrated schema by
 *      tests/Feature/Tenancy/TenantModelClassificationTest, not from the model's $fillable.
 *   2. Every insert path populates `store_id`. A row with a NULL `store_id` matches no
 *      `store_id = ?` predicate, so scoping a model whose writers leave it null makes existing
 *      rows invisible to everyone -- a silent outage that looks like data loss.
 *   3. No call site filters it by a FOREIGN store id (the 785 above).
 *   4. It is NOT reachable through the admin API's request-supplied `store_id` path. See the
 *      DEFERRED note below -- this is the condition that excludes the big commerce models.
 *
 * WHY Product, Order, Category, Cart, Customer, Setting AND Shipping ARE DEFERRED.
 *
 * app/Http/Controllers/Api/DashboardController.php resolves its tenant from the REQUEST, not
 * from the session: `$storeId = $request->query('store_id')` at 17 sites, and
 * `$store = Store::find($request->store_id)` / `$this->resolveOwnedStore($request->store_id)`
 * at 70 more. `resolveOwnedStore()` (:3590-3605) authorises by `created_by`, NOT by
 * `current_store`, so a merchant who owns several stores may legitimately operate on a store
 * that is not their `current_store`. A scope keyed on `current_store` would return zero rows for
 * that request. `ChangeStore()` (:3619-3656) does keep `current_store` in step when the client
 * calls it, so this breaks only when the client passes a `store_id` it has not switched to --
 * which nothing in the server enforces.
 *
 * Closing that gap means binding the admin API's requested store to the session's tenant, and
 * app/Http/** belongs to another workstream. Until then these models keep the trait (so the
 * helpers `forStore()`, `isOwnedByCurrentStore()` and the explicit predicates stay available)
 * but are deliberately absent from `scoped_models`.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Master kill switch
    |--------------------------------------------------------------------------
    |
    | TRUE lets the per-model allowlist below take effect. FALSE disables tenant scoping
    | everywhere in one move, whatever the allowlist says, and is the operational escape hatch if
    | enforcement ever turns out to break a merchant workflow in production.
    |
    | This is NOT the "scope everything" switch. Even when true, ONLY the models named in
    | `scoped_models` are constrained. The all-models flip remains App\Scopes\StoreScope::$enabled,
    | which stays false -- see the class docblock there.
    |
    | No .env change is required or implied: the deployed .env is owner-accepted as-is and is not
    | modified by this increment. The default below is the shipped behaviour.
    |
    */

    'enforcement_enabled' => env('TENANCY_ENFORCEMENT_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Per-model allowlist -- BATCH 1, audited model by model
    |--------------------------------------------------------------------------
    |
    | Only these models get the `store_id` predicate. Each line below records what the scope
    | CLOSES, because every one of these models is reachable today by bare primary key with no
    | ownership check at all.
    |
    | packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php:115 runs
    |     $model::whereIn('id', $request->ids)->delete();
    | over a 40-entry model map with NO tenant predicate. For an allowlisted model the scope
    | constrains that DELETE, because Eloquent's Builder::delete() goes through toBase() and so
    | carries global scopes. tests/Feature/Tenancy/EnforcedModelIsolationTest proves exactly that
    | rather than trusting the framework's documented behaviour.
    |
    */

    'scoped_models' => [

        // Menu -- app/Helper/helper.php:2319 `Menu::with('store')->find($menuId)`,
        // app/Http/Controllers/MenuItemsController.php:19,46,105,132,159 `Menu::find($menu_id)`
        // and app/Http/Controllers/MenuController.php:111 `Menu::findOrFail($id)->delete()` all
        // take a bare id with no ownership check. The one store-filtered read,
        // helper.php:2259 `Menu::where('store_id', getCurrentStore())`, AGREES with the scope.
        // Writer: MenuController.php:60 sets `store_id = getCurrentStore()`.
        App\Models\Menu::class,

        // SupportTicket -- app/Http/Controllers/SupportTicketController.php:133
        // `SupportTicket::where('id', $id)->delete()` deletes by id with no tenant predicate, and
        // the model has NO `where('store_id', ...)` filter anywhere in the codebase.
        // Writer: AccountProfileController.php:606 sets `store_id = $store->id`.
        App\Models\SupportTicket::class,

        // SupportConversion -- SupportTicketController.php:132
        // `SupportConversion::where('ticket_id', $id)->delete()` is keyed only on a foreign id,
        // so another tenant's ticket thread is deletable. No store filter exists anywhere.
        // Writers: SupportTicketController.php:80 (`getCurrentStore()`) and
        // AccountProfileController.php:810 (`$store->id`) both set it.
        App\Models\SupportConversion::class,

        // FlashSaleCondition -- app/Http/Controllers/FlashSaleController.php:303 `->first()` and
        // :471 `->delete()` are keyed only on `flashsale_id`. The one store-filtered read at
        // :254 uses `getCurrentStore()` and AGREES with the scope.
        // Writer: FlashSaleController.php:85 sets `store_id = getCurrentStore()`.
        App\Models\FlashSaleCondition::class,

        // ThemeCustomizeOrder -- both call sites already pass `getCurrentStore()`
        // (ThemeCustomizeController.php:165 and the updateOrCreate at :178), so the scope is
        // redundant here rather than behaviour-changing. It is allowlisted because that makes it
        // the control of record instead of two hand-written filters. The updateOrCreate's match
        // array carries `store_id => getCurrentStore()`, which agrees with the predicate, so it
        // still UPDATEs and does not start INSERTing duplicates.
        App\Models\ThemeCustomizeOrder::class,

        // NotifyUser -- app/Http/Controllers/ProductController.php:1611,1619 and
        // packages/workdo/AuctionProduct/.../AuctionProductController.php:1467,1475 read and
        // delete keyed only on `product_id`, with no store filter anywhere.
        // Writer: app/Http/Controllers/Api/ApiController.php:6517 sets `store_id = $store->id`
        // on the raw insert. That insert is a DB::table() call and so is not scope-governed,
        // which does not matter for a write that supplies the column explicitly.
        App\Models\NotifyUser::class,

    ],

    /*
    |--------------------------------------------------------------------------
    | Null-tenant policy
    |--------------------------------------------------------------------------
    |
    | What the scope does when no tenant can be resolved. `getCurrentStore()` reads the `web`
    | guard only, so the tenant is legitimately absent in FOUR contexts, all of which are proven
    | in tests/Feature/Tenancy/NullTenantContextTest:
    |
    |   1. a queued job            (no authenticated principal)
    |   2. a console command       (routes/console.php:9 drives the Zid sync)
    |   3. the storefront          (store resolved by slug, not by session)
    |   4. the `customers` and `deliveryboy` guards (invisible to auth()->user() on `web`)
    |
    | 'open'   -- add NO predicate. All rows remain visible to that query.
    | 'closed' -- add `1 = 0`. The query returns nothing.
    |
    | THE DEFAULT IS 'open', AND IT IS A DELIBERATE, UNCOMFORTABLE TRADE, NOT AN OVERSIGHT.
    |
    | 'open' means the scope is DEFENCE IN DEPTH behind the explicit per-call-site predicates,
    | never a substitute for them: in those four contexts it contributes nothing, and the control
    | of record remains the explicit `where('store_id', ...)` at the call site. That is a real,
    | acknowledged isolation hole and it is reported, not papered over.
    |
    | 'closed' is nonetheless the WRONG default here, because `store_id = NULL` semantics are not
    | the issue -- reachability is. Every storefront page, every customer API response and every
    | queued job resolves its store WITHOUT a web-guard principal, so 'closed' would empty the
    | entire storefront and silently stop the scheduled Zid sync. A control that takes the shop
    | offline would simply be switched off, and then there would be no control at all.
    |
    | Operators who serve no storefront from this deployment can set 'closed' for strictly
    | stronger isolation. Both branches are exercised by the tests above, so the choice is
    | evidence-backed either way rather than a claim in a comment.
    |
    */

    'null_tenant' => env('TENANCY_NULL_TENANT', 'open'),

    /*
    |--------------------------------------------------------------------------
    | Null-tenant observability
    |--------------------------------------------------------------------------
    |
    | TRUE logs a debug line every time an ALLOWLISTED model is queried with no resolvable
    | tenant, which is how the fail-open hole above stops being silent and becomes measurable.
    |
    | It defaults to FALSE on purpose: the storefront hits this path on essentially every query,
    | so leaving it on in production writes a log line per query. Turn it on temporarily to find
    | out which jobs and commands touch tenant-owned models without a tenant -- that inventory is
    | the input to the next batch.
    |
    */

    'log_null_tenant' => env('TENANCY_LOG_NULL_TENANT', false),

];
