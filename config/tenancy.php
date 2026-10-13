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
 *   5. NO STOREFRONT READ SITE FILTERS IT BY A SLUG-RESOLVED STORE ID. This condition was
 *      missing from the list, and it is the one Cart and Customer fail.
 *
 *      Storefronts are path-prefixed ({storeSlug?}) on the SAME host, and routes/customer.php
 *      is `require`d from routes/web.php:1222, so storefront routes sit INSIDE the `web`
 *      middleware group -- confirmed from the router, which reports status.cancel as
 *      [web, ActiveTheme, themelanguage]. config/session.php:146 sets the cookie path to '/'.
 *      So a merchant or staff member with a live admin session is a POPULATED WEB-GUARD
 *      PRINCIPAL on every storefront request, and getCurrentStore() returns their ADMIN
 *      store -- not the store whose storefront they are viewing.
 *
 *      A storefront query of the form `where('store_id', $slugResolvedStore->id)` is then
 *      ANDed with this scope's predicate and returns zero rows, SILENTLY, because a find()
 *      or get() that returns nothing is indistinguishable from "no such record".
 *
 *      That is a broken storefront, and this project has already shipped that failure twice:
 *      a brace move behind `auth` killed newsletter signup on every store, and scoping Menu
 *      blanked the storefront navigation until an explicit withoutGlobalScope was added at
 *      app/Helper/helper.php. A control that breaks the storefront is a defect, not a fix.
 *
 * THE ADMIN-API CONSTRAINT IS NOW SOLVED, NOT DEFERRED. Batch 1 deferred Product, Order,
 * Category, Cart, Customer, Setting and Shipping on one argument: the admin API resolves its
 * tenant from the REQUEST (`$request->store_id`, ~100 sites in
 * app/Http/Controllers/Api/DashboardController.php), authorises it by OWNERSHIP via
 * `resolveOwnedStore()` (:3627-3641, `created_by = creatorId()`) and NOT by `current_store`, so a
 * `current_store` predicate would answer a legitimate multi-store request with zero rows.
 *
 * That argument was right, and its unstated premise is now PROVEN rather than assumed:
 * app/Http/Middleware/CustomAuth.php:80-81 logs a `User` token in with `auth()->login()` on the
 * DEFAULT web guard, so `auth()->user()` really is populated on every admin API call and the
 * scope really is live there. (Had it authenticated on another guard, the scope would have been a
 * no-op on that path and the whole deferral would have been unnecessary.)
 *
 * The fix is in App\Scopes\StoreScope::admissibleStoreIds(): the predicate admits
 * `{current_store} UNION {stores whose created_by = creatorId()}` -- the controller's OWN
 * authorisation test, plus today's value. `store_id = 7 AND store_id IN (5,7)` returns store 7's
 * rows, so the legitimate multi-store request works; `store_id = 9 AND store_id IN (5,7)` returns
 * nothing, so the ~70 remaining bare `Store::find($request->store_id)` sites have their IDOR
 * closed. Because `current_store` is always a member, the new predicate is a STRICT SUPERSET of
 * the old one and cannot blank a page that works today.
 *
 * SO WHY ARE Product, Category, Shipping, Setting AND Order STILL NOT HERE? A DIFFERENT AND
 * STRONGER REASON, measured for this batch: they are read on the PUBLIC STOREFRONT, and the scope
 * cannot tell a merchant reading their own admin panel from the same merchant browsing another
 * merchant's shop -- both are web-guard principals. Scoping them blanks the catalogue, the
 * category menu, the checkout shipping and tax options for a signed-in merchant browsing any
 * store they do not own, with no error, and buys no confidentiality at all because the anonymous
 * visitor those pages exist for resolves no tenant and was never filtered. That is exactly the
 * trap that blanked the storefront navigation when `Menu` was scoped, and it needed a
 * `withoutGlobalScope` escape at app/Helper/helper.php to undo. Per-model evidence sits against
 * each entry in the DEFERRED block at the bottom of this file.
 *
 * Deferred models keep the trait, so `forStore()`, `isOwnedByCurrentStore()` and the explicit
 * fail-closed predicates stay available to call sites that want them.
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

        /*
        |----------------------------------------------------------------------------------------
        | BATCH 2 -- unlocked by the owned-stores predicate, audited model by model
        |----------------------------------------------------------------------------------------
        |
        | Every entry below satisfies the four conditions above AND one more that batch 1 did not
        | have to state: IT IS NOT PUBLIC STOREFRONT CONTENT. Each is either merchant-admin data
        | or a customer's own record, never a page an anonymous visitor renders, so scoping it
        | cannot blank the shop. Each is proven by two independent merchants owning two
        | independent stores in tests/Feature/Tenancy/Batch2ModelIsolationTest -- negative
        | (A cannot read, update or delete B's row, asserted through raw DB::table() so the scope
        | cannot hide damage it failed to prevent) AND positive (A can still do all three to its
        | own), plus a super-admin cross-store read.
        |
        */

        // OrderRefund -- the cleanest case in the codebase. ZERO occurrences in
        // Api/DashboardController (measured: `grep -c 'OrderRefund::'` = 0), so the
        // request-supplied-store_id path does not reach it at all and the owned-stores predicate
        // is not even needed to make it safe.
        // WHAT THE SCOPE CLOSES: AccountProfileController.php:910 `OrderRefund::find($id)` and
        // OrderRefundController.php:253 `OrderRefund::where('order_id', $order['id'])->first()`
        // are both keyed on a bare id with no tenant predicate, and
        // BulkDeleteController.php:115 deletes `order-refunds` by id with none either.
        // WHAT AGREES WITH IT: OrderRefundController.php:69 and :233 both pass
        // `getCurrentStore()`.
        // Writer: AccountProfileController.php:983 sets `store_id = $store->id`.
        // Storefront note: the customer-facing reads at AccountProfileController.php:537 and
        // AuctionOrdersController.php:337 run on the `customers` guard or anonymously, where
        // `auth()->user()` on `web` is null and the scope adds nothing (MODE_UNRESOLVED).
        App\Models\OrderRefund::class,

        // OrderNote -- merchant-to-shopper and system text on an order, never rendered to an
        // anonymous visitor.
        // WHAT THE SCOPE CLOSES: BulkDeleteController does not carry it, but
        // SupportTicket-style bare-id access does: the notes read at
        // Api/DashboardController.php:1807 and :6834 are keyed on `order_id` plus a store that
        // came from the request, so before the ownership repair any token read any store's notes.
        // The scope is now the backstop behind that repair.
        // WHY IT NEEDED THE OWNED-STORES PREDICATE: all three admin-API sites filter on
        // `$store->id` where `$store = resolveOwnedStore($request->store_id)` (:125, :203), which
        // may legitimately differ from `current_store`. Under the old `= current_store` predicate
        // a multi-store merchant's notes list would have gone empty.
        // Writers: OrderNote.php:46 (`$data['store_id']`), OrderController.php:259 and
        // Api/DashboardController.php:5220/:5230 all set it explicitly.
        App\Models\OrderNote::class,

        // Customer -- the highest-value PII table that is NOT storefront-public. A customer's
        // record is read on the `customers` guard (their own account) or anonymously during
        // registration and login, and in BOTH of those the web guard has no principal, so the
        // scope adds no predicate and the storefront is untouched.
        // WHAT THE SCOPE CLOSES: BulkDeleteController.php:115 maps `customer` and deletes by bare
        // id across every store on the platform, and Api/DashboardController.php:4919 and :7439
        // read a customer keyed on `$request->customer_id`.
        // WHAT AGREES WITH IT: :4775, :6762 and :7591 all filter on a store derived from the
        // request, which the owned-stores predicate admits when the caller owns it and denies
        // when it does not.
        // Writers: ALL SIXTEEN insert paths set `store_id`, verified one by one --
        // Api/AuthController.php:88, CustomerController.php:39, PaymentController.php:1093,
        // Customer/Auth/CustomerLoginController.php:727, ShopifyCustomerController.php:185,
        // Api/DashboardController.php:5001/:6977 and the eight social-login packages
        // (GoogleLogin:123, SignInWithFacebook:186, SignInWithLinkedIn:129, Signinwithgithub:117,
        // SignInWithSlack:119, SignInWithTwitter:114, SignInWithBitbucket:109,
        // SignInWithOutlook:172).
        // ***** NOT ENABLED. The analysis above is kept because it is sound as far as it
        // ***** goes, but its premise -- that this model is "always" read on the `customers`
        // ***** guard or anonymously -- is FALSE, and condition 5 above explains why. A
        // ***** merchant with a live admin session is a populated WEB-GUARD principal on every
        // ***** storefront request (routes/customer.php is require'd from routes/web.php:1222,
        // ***** so storefront routes carry the `web` group; config/session.php:146 sets the
        // ***** cookie path to '/'), and getCurrentStore() then returns their ADMIN store.
        // ***** Enabling this model made a storefront read return zero rows, silently, for
        // ***** that principal on any store they do not own. Re-enable ONLY after every
        // ***** storefront read site has an explicit withoutGlobalScope escape AND a test
        // ***** drives the authenticated-web-guard case (the anonymous case fails open and so
        // ***** passes trivially).
        // ***** Breaking sites: CustomerLoginController.php:87, :585, :751, :796, :847.

        // Cart -- abandoned-cart rows. Read on the storefront and in every payment controller,
        // but always on the `customers` guard or anonymously by `cookie_session_id`, so the scope
        // contributes nothing there and cannot empty a shopper's basket.
        // WHAT THE SCOPE CLOSES: BulkDeleteController.php:113 deletes `abandoncart` by
        // `customer_id` -- not even by id -- with no tenant predicate, so one merchant could clear
        // another merchant's carts for any customer id.
        // WHY IT NEEDED THE OWNED-STORES PREDICATE: Api/DashboardController.php:2016 and :2105
        // run `Cart::where('product_id', ...)->delete()` as product-deletion cleanup on a store
        // that came from the request. Under `= current_store` that cleanup would have silently
        // stopped deleting for a multi-store merchant, orphaning cart rows against a deleted
        // product -- a data-integrity regression introduced by a security control, which is
        // exactly what this phase is told not to do.
        // Writers: Cart.php:162 and :851, Api/ApiController.php:1533, CartController.php:382,
        // and the FrequentlyProduct / BoostSales package inserts all set `store_id`.
        // ***** NOT ENABLED. The analysis above is kept because it is sound as far as it
        // ***** goes, but its premise -- that this model is "always" read on the `customers`
        // ***** guard or anonymously -- is FALSE, and condition 5 above explains why. A
        // ***** merchant with a live admin session is a populated WEB-GUARD principal on every
        // ***** storefront request (routes/customer.php is require'd from routes/web.php:1222,
        // ***** so storefront routes carry the `web` group; config/session.php:146 sets the
        // ***** cookie path to '/'), and getCurrentStore() then returns their ADMIN store.
        // ***** Enabling this model made a storefront read return zero rows, silently, for
        // ***** that principal on any store they do not own. Re-enable ONLY after every
        // ***** storefront read site has an explicit withoutGlobalScope escape AND a test
        // ***** drives the authenticated-web-guard case (the anonymous case fails open and so
        // ***** passes trivially).
        // ***** Breaking sites: app/Models/Cart.php:166 and :208.

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
    | Which stores the predicate admits for a resolved tenant
    |--------------------------------------------------------------------------
    |
    | 'owned_stores'  -- DEFAULT. `store_id IN ({current_store} UNION {stores whose created_by
    |                    equals the principal's creatorId()})`. This is the controller's own
    |                    authorisation test (Api/DashboardController::resolveOwnedStore, :3627)
    |                    rather than a second, disagreeing definition of "this merchant's data",
    |                    and it is what makes batch 2 possible -- see the header of this file and
    |                    App\Scopes\StoreScope::admissibleStoreIds() for the full argument.
    |
    | 'current_store' -- the pre-batch-2 predicate, `store_id = current_store`, exactly. Correct
    |                    and strictly narrower for a deployment where every merchant owns exactly
    |                    one store; it is what returns zero rows for a legitimate multi-store
    |                    admin API request.
    |
    | Because `current_store` is always a member of the 'owned_stores' set, that set is a STRICT
    | SUPERSET of 'current_store'. Switching to the default can therefore never make a query that
    | returns rows today return fewer -- the property that makes this safe to ship, asserted in
    | tests/Feature/Tenancy/OwnedStoresTenantResolutionTest rather than argued here.
    |
    | A single-store merchant resolves to a one-element set, and StoreScope::apply() emits the
    | same single `where()` it always has for that case, so the SQL and every model-caching key
    | are byte-identical for the common principal.
    |
    */

    'tenant_scope' => env('TENANCY_TENANT_SCOPE', 'owned_stores'),

    /*
    |--------------------------------------------------------------------------
    | How a STOREFRONT request resolves its tenant
    |--------------------------------------------------------------------------
    |
    | THIS KEY IS THE REPAIR FOR THE BATCH-2 REVERT. Condition 5 above describes a defect whose
    | cause was not that Cart and Customer are unscopeable -- it was that ONE predicate was being
    | asked to serve FIVE different contexts. A storefront request's tenant is the store the URL
    | names; a merchant-admin request's tenant is the stores that merchant owns. Resolving both
    | from `getCurrentStore()` alone is what returned zero rows on every storefront read made by a
    | principal with a live admin session.
    |
    | The contexts are now named constants on App\Scopes\StoreScope and each returns its own
    | admissible set (CONTEXT_SUPER_ADMIN, CONTEXT_MERCHANT_ADMIN, CONTEXT_STOREFRONT_WEB,
    | CONTEXT_STOREFRONT_PUBLIC, CONTEXT_EXPLICIT, CONTEXT_CONSOLE, CONTEXT_UNRESOLVED). This key
    | controls only the two storefront ones:
    |
    | 'additive'  -- DEFAULT. On a storefront request the slug-resolved store is ADDED to the
    |                admissible set: `{slug store} UNION {current_store} UNION {owned stores}`. An
    |                anonymous or `customers`-guard storefront request is left exactly as it is
    |                today -- fail-open, no predicate.
    |
    |                WHY ADDITIVE IS THE DEFAULT, and it is the same argument that made
    |                'owned_stores' safe: the set stays a STRICT SUPERSET of what the predicate
    |                admits today, so NO query that returns rows today can return fewer. A replace
    |                would NARROW every already-allowlisted model on every storefront request, and
    |                a silently blank storefront is the exact failure this project has already
    |                shipped twice (a brace move behind `auth` killed newsletter signup; scoping
    |                Menu blanked the navigation).
    |
    |                AND IT GRANTS NOTHING NEW. On the same URL an anonymous visitor resolves no
    |                tenant and so gets NO predicate at all, which means the slug store's rows are
    |                already fully readable by anyone who can load the page. Admitting that one
    |                store for an authenticated principal removes a predicate that was WRONG, not
    |                one that was holding anything back.
    |
    | 'slug_only' -- the strict per-context predicate: a storefront request is scoped to the
    |                slug-resolved store and NOTHING else, including for anonymous and
    |                `customers`-guard requests. Strictly stronger isolation -- it closes the
    |                storefront fail-open hole that `null_tenant` documents -- at the cost of
    |                being a narrowing, so it must be chosen deliberately. Not the default.
    |
    | Both branches are exercised by tests/Feature/Tenancy/StorefrontContextTenantResolutionTest,
    | so the choice is evidence-backed either way rather than a claim in a comment.
    |
    | THE SLUG IS READ FROM THE SAME PLACE THE STOREFRONT READS IT: the `storeSlug` route
    | parameter, which is what `getStore()` uses at app/Helper/helper.php:2332, falling back to the
    | first path segment ONLY for routes carrying the ActiveTheme middleware -- which is what
    | ActiveTheme itself does at app/Http/Middleware/ActiveTheme.php:21. There is no second
    | resolver and no new source of truth.
    |
    */

    'storefront_tenant' => env('TENANCY_STOREFRONT_TENANT', 'additive'),

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
