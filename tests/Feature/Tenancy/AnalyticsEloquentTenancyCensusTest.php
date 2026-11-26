<?php

namespace Tests\Feature\Tenancy;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Analytics\AnalyticsTenancyCensus;

/**
 * GAP-167 / P05-19 — THE ANALYTICS TENANCY CENSUS. `RawQueryTenancyInventoryTest`'s RULE, APPLIED
 * TO ELOQUENT, AND TO WHERE THE PREDICATE'S VALUE COMES FROM.
 * =================================================================================================
 *
 * WHAT THE ROW ASKS FOR, verbatim: "A census of every read of `Order`, `Visit` and the other
 * unscoped models in the analytics and report controllers, asserting a store predicate DERIVED FROM
 * THE PRINCIPAL on each: the raw-query inventory's rule applied to Eloquent, and to where the
 * predicate's value comes from rather than to whether a column name appears."
 *
 * WHY IT IS NEEDED, and why the existing inventory cannot answer it. `RawQueryTenancyInventoryTest`
 * attributes `DB::table('literal')` sites and says of itself, as its own first limit, that it counts
 * a statement as tenant-aware when the WORD `store_id` appears in it. GAP-167 records what that cost:
 * the operator dashboard's visit-log read said `whereNotNull('store_id')` and `pluck('store_id')`,
 * was filed as aware, and filtered nothing. It also "does not see an Eloquent read of an unscoped
 * model at all" -- and for these models there is no scope to see it for them:
 * `StoreScope::isActiveFor()` short-circuits for any model absent from `config/tenancy.php`'s
 * `scoped_models`, and `null_tenant => 'open'` adds nothing when no tenant resolves either. So an
 * Eloquent `Order::where(...)` in a report handler is exactly as reachable as a raw statement.
 *
 * WHAT THIS FILE IS AND IS NOT. It is a MEASUREMENT with a disposition per site shape, in both
 * directions: an undispositioned shape fails it, and a disposition for a shape that no longer exists
 * fails it too. It is NOT a clean bill of health -- `AnalyticsTenancyCensus`' four stated limits
 * apply to every figure here, and the first of them is that `principal` says where the value came
 * from and not that the predicate is the right one.
 *
 * WHY THE DISPOSITIONS ARE KEYED ON SHAPE AND NOT ON `file:line`. CLAUDE.md §6b: four inventories in
 * this repository are keyed on `file:line` and "ANY edit above one breaks them" -- measured twice in
 * one session, a 25-line comment shifting three inventories at once. `RawQueryTenancyInventoryTest`
 * made the same call for the same reason and says so: a file:line ratchet over another workstream's
 * files "would go red on every unrelated edit above them and would be deleted within a phase". So a
 * site is identified by its FILE, its `Model::call` and its verdict, with a COUNT -- exact, and
 * stable under an insertion. The line numbers are in the census object for a reader who wants them.
 */
final class AnalyticsEloquentTenancyCensusTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE MEASURED TOTALS. Each verdict is defined in `AnalyticsTenancyCensus`' docblock.
     *
     * `principal` 126 is the figure the row asked to establish and it is a LOWER BOUND on safety,
     * not an upper bound on exposure: see LIMIT 1.
     */
    private const TOTALS = [
        'principal' => 126,
        'ownership_checked' => 24,
        'route_store' => 9,
        'parameter' => 13,
        'request' => 44,
        'none' => 64,
    ];

    private const TOTAL_SITES = 280;

    /**
     * EVERY SITE SHAPE THAT IS NOT `principal`, WITH ITS COUNT AND ITS REASON.
     *
     * The key is `<file>|<Model>::<call>|<verdict>`. A reason beginning "NOT VOUCHED FOR" is an
     * honest declaration that this slice did not establish the site's safety -- the count is
     * published so nothing is hidden, and the row names which census owns it.
     */
    private const DISPOSITIONS = [
        /* ----------------------------------------------------------------------------------------
         * ReportController — the report centre. 95 of its 112 sites are principal-derived
         * (`Order::where('store_id', OperatorStoreSelection::reportStoreId())`, 33 times as a
         * static call alone).
         * -------------------------------------------------------------------------------------- */

        // GATED, NOT UNSCOPED. `$selectedCategory` comes from
        // `reportSelectionWithinCurrentStore(Category::class, $request->selectedCategory)`, which
        // narrows a requested id set to the current store before any `find()` runs; the empty case
        // falls back to `Category::where('store_id', OperatorStoreSelection::reportStoreId())`.
        // `Category` is in no `scoped_models` entry, so the bare `find()` would reach every
        // tenant's categories on its own -- GAP-234 is the same shape, at `sub_category`. The gate
        // is the tenant check and it is principal-derived; LIMIT 1 applies, in that the gate being
        // in the function does not prove each of the eleven reads goes through it.
        // AND THE GATE HAS AN OPERATOR LIMB THAT IS NOT A NARROWING, which this reason has to say
        // or it overstates the control. `ReportController.php:4477` returns the requested set
        // UNNARROWED for `UserPolicy::PLATFORM_ADMIN`, so for the platform operator a
        // caller-supplied `selectedCategory` reaches any tenant's category name. That is the
        // operator's own privilege rather than a hole -- they may select any store, and
        // `OperatorStoreSelection::reportStoreId()` is whichever one they chose -- but it means the
        // disposition holds for a MERCHANT by narrowing and for the operator by privilege, which
        // are two different statements. Hardening at most, and named so the next reader does not
        // have to re-derive it.
        'app/Http/Controllers/ReportController.php|Category::find|ownership_checked' => [11,
            'merchant: id set narrowed to the current store by reportSelectionWithinCurrentStore(); operator: admitted unnarrowed by its own limb, same privilege level'],

        // A DEAD STATEMENT, AND THE EXECUTED READ IS PRINCIPAL-DERIVED. All three are the same
        // copy-paste:
        //
        //     $monthLists = Order::selectRaw('orders.*,DATE(order_date) as DATE,MONTH(order_date) as month');
        //     $monthLists = Order::whereDate('order_date', '>=', $form_date)
        //         ->whereDate('order_date', '<=', $to_date)->where('store_id', OperatorStoreSelection::reportStoreId());
        //
        // The first builder is overwritten on the very next line and never executed, so there is no
        // query to scope. DELIBERATELY NOT DELETED: removing three lines from `ReportController`
        // shifts every `file:line` citation below them in `ResidualTenancyExposureTest`,
        // `EloquentScopeBypassInventoryTest` and the observability inventory (CLAUDE.md §6b), for a
        // cleanup with no security benefit. It is recorded here instead so the next reader of those
        // lines knows the statement is dead rather than unscoped.
        'app/Http/Controllers/ReportController.php|Order::selectRaw|none' => [3,
            'dead builder: overwritten by the next line, which carries reportStoreId()'],

        // SCOPED BY THE PARENT ROW, not by its own column. `ProductVariant::where('product_id',
        // $product->id)` sits inside a loop over
        // `$products = Product::where('store_id', OperatorStoreSelection::reportStoreId())`, so the
        // parent is the principal's. THE RESIDUAL IS RECORDED RATHER THAN CLAIMED AWAY:
        // `product_variants` has its own `store_id`, and `StoreInventoryValuation`'s docblock states
        // that the two store columns are INDEPENDENT -- it joins on `product_id` AND
        // `whereColumn('product_variants.store_id', '=', 'products.store_id')` for exactly this
        // reason. A variant row carrying this `product_id` and another tenant's `store_id` would be
        // read here. That is a write-path integrity question, not one this census can settle.
        'app/Http/Controllers/ReportController.php|ProductVariant::where|none' => [3,
            'narrowed by a parent product already scoped to reportStoreId(); the variant store_id column is not also asserted'],

        /* ----------------------------------------------------------------------------------------
         * The metric layer and its report siblings — the producers.
         * -------------------------------------------------------------------------------------- */

        // THE PREDICATE IS THERE AND ITS VALUE IS A PARAMETER, which LIMIT 3 says this census does
        // not follow across files. The entry condition is the class's own, stated in its docblock:
        // "THE PERIOD IS CALLER-SUPPLIED AND THE STORE IS NOT. `$storeId` reaches this method from
        // `getCurrentStore()` only, and every query below still carries it." Both call sites agree:
        // `MerchantAnalyticsController` passes `(int) getCurrentStore()` and
        // `ScheduledReportRunner` passes the schedule row's own `store_id`. Asserted as a premise by
        // `test_the_merchant_figures_entry_point_takes_its_store_from_the_principal` below, so this
        // disposition is measured rather than quoted.
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Order::query|parameter' => [5,
            'where(store_id, $storeId); $storeId is the entry parameter, fed only from getCurrentStore() or a schedule row'],
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Visit::query|parameter' => [3,
            'where(store_id, $storeId); same entry parameter as the Order reads beside it'],

        // NOT A READ AT ALL. `Order::orderSourceLabel($key)` is a static VOCABULARY helper that maps
        // a stored source key to a translated label; it touches no table. The census pattern matches
        // `Model::method(` and cannot tell a query builder from a value object, which is a false
        // POSITIVE and therefore the safe direction for it to be wrong in.
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Order::orderSourceLabel|none' => [1,
            'not a query: a static label helper on the model class'],

        // DELIBERATELY CROSS-TENANT, OPERATOR-ONLY BY ROUTE. All three read `stores` across every
        // tenant: the id list that FEEDS `MetricScope::stores()`, and two name lookups for the
        // ranking panels. GAP-167's own re-trace names these two surfaces as "the one deliberately
        // cross-tenant surface" (there were two) and records that both now receive
        // `MetricScope::stores()` at the call site. The class is reached only from
        // `PlatformAnalyticsController`, which is operator-gated; a tenant predicate here would make
        // the platform figure wrong rather than safe.
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Store::query|none' => [3,
            'deliberately cross-tenant: the operator screen, whose measurement IS every tenant'],

        // The same parameter shape as the merchant producer, fed from a `MetricScope` the operator
        // controller built; `MetricScope` has a private constructor, no "every tenant" factory and
        // throws on an empty list.
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Visit::query|parameter' => [4,
            'whereIn(store_id, $storeIds) from the operator scope; MetricScope refuses an empty list'],

        'app/Support/Metrics/StoreInventoryValuation.php|Product::disableCache|parameter' => [1,
            'whereIn(products.store_id, $storeIds) from the metric scope'],

        // LIMIT 2, AND THE PREDICATE IS NINE LINES FURTHER ON. The statement is one chained
        // expression ending in `->first()`, and the `join()` CLOSURE contains a `;`, which ends the
        // census window early. The real statement carries
        // `whereIn('products.store_id', $storeIds)`, `whereIn('product_variants.store_id', $storeIds)`
        // AND `whereColumn('product_variants.store_id', '=', 'products.store_id')` -- strictly more
        // than this census asks for.
        'app/Support/Metrics/StoreInventoryValuation.php|ProductVariant::disableCache|none' => [1,
            'window ended at a semicolon inside the join closure; the statement carries two store predicates and a whereColumn'],

        // NO HTTP PRINCIPAL EXISTS IN A SCHEDULED RUN, and that is the point of the class. The tenant
        // is the stored schedule's own `store_id` (`Store::find((int) $schedule->store_id)`) and the
        // owner lookup is `User::find($schedule->created_by)`, read so the permission can be
        // RE-CHECKED now rather than trusted from when the schedule was made. Neither is a
        // caller-supplied id: a schedule row is reachable only through the merchant's own scoped
        // list. The runner's own docblock states the operator case: "the schedule's own `store_id`
        // is what scopes the figures -- an operator's schedule reads the store they made it for,
        // not store 1."
        'app/Services/Reports/ScheduledReportRunner.php|Store::find|none' => [1,
            'the stored schedule row own store_id; no request and no caller-supplied id'],
        'app/Services/Reports/ScheduledReportRunner.php|User::find|none' => [1,
            'the schedule owner, read to re-check the permission at send time'],

        /* ----------------------------------------------------------------------------------------
         * HomeController — the merchant dashboard AND the whole storefront, 2,413 lines. GAP-167
         * names `handleRegularUser()`; the file is censused WHOLE, because the tenancy skill's
         * fourth census failure is "an exemption scoped to the methods somebody happened to look
         * at". Its non-dashboard reads are therefore in this table, and most of them are declared
         * rather than vouched for.
         * -------------------------------------------------------------------------------------- */

        // THE STOREFRONT'S OWN TENANT, FROM THE PATH. These handlers bind `$store = getStore($slug)`
        // and filter on `$store->id`. CLAUDE.md states the rule and why it is not `getCurrentStore()`:
        // "a storefront handler takes its store from the route", because the web guard answers for a
        // merchant's ADMIN store on a storefront request -- P1-SEC-71. Reading store X's catalogue
        // through store X's slug is the feature.
        'app/Http/Controllers/HomeController.php|Product::productSalesTag|route_store' => [2,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|ProductVariant::where|route_store' => [5,
            'storefront: narrowed by a product the route-resolved store already selected'],
        'app/Http/Controllers/HomeController.php|Order::order_detail|route_store' => [1,
            'storefront: the route slug resolved the store'],

        // THE SAME STOREFRONT HANDLERS, whose predicate IS `$store->id` or `$store_id` but whose
        // enclosing function this census could not trace to `getStore()` in one step -- a nested
        // closure, or a store bound before the function the site sits in. NOT VOUCHED FOR as
        // principal-derived, and not claimed as a finding either: the predicate exists and its value
        // is a store, and which store is a storefront question this slice did not measure.
        'app/Http/Controllers/HomeController.php|Product::where|none' => [8,
            'NOT VOUCHED FOR: predicate on a $store->id this census could not trace in one step (storefront)'],
        'app/Http/Controllers/HomeController.php|Category::where|none' => [3,
            'NOT VOUCHED FOR: as Product::where above'],
        'app/Http/Controllers/HomeController.php|Category::where|route_store' => [1,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|Category::with|none' => [1,
            'NOT VOUCHED FOR: as Product::where above'],
        'app/Http/Controllers/HomeController.php|Category::select|none' => [1,
            'NOT VOUCHED FOR: as Product::where above'],
        'app/Http/Controllers/HomeController.php|Testimonial::where|none' => [1,
            'NOT VOUCHED FOR: as Product::where above'],
        'app/Http/Controllers/HomeController.php|Order::where|none' => [1,
            'NOT VOUCHED FOR: as Product::where above'],
        'app/Http/Controllers/HomeController.php|Wishlist::with|none' => [1,
            'NOT VOUCHED FOR: a shopper wishlist read; the storefront census owns it'],
        'app/Http/Controllers/HomeController.php|DeliveryAddress::where|none' => [1,
            'NOT VOUCHED FOR: a shopper address read; the storefront census owns it'],

        // THE DOMAIN AND SUBDOMAIN RESOLVERS, which run BEFORE any principal exists: the request
        // arrived on a custom host and the controller is deciding which store it names. A tenant
        // predicate is not available there by construction -- the host IS the tenant selector.
        'app/Http/Controllers/HomeController.php|Store::find|none' => [6,
            'host-to-store resolution, before any principal: the host is the tenant selector'],
        'app/Http/Controllers/HomeController.php|User::find|none' => [5,
            'the resolved store owner or the signed-in user own row, by id'],

        // THE DASHBOARD'S OWN DEAD BUILDER, the same copy-paste as ReportController's three: the
        // next line overwrites it with `Order::whereDate(...)->where('store_id', getCurrentStore())`.
        'app/Http/Controllers/HomeController.php|Order::selectRaw|none' => [1,
            'dead builder: overwritten by the next line, which carries getCurrentStore()'],

        /* ----------------------------------------------------------------------------------------
         * Api/DashboardController — the Flutter admin app's API, 12,492 lines. GAP-167 names it.
         * Censused whole, and almost all of it is DECLARED rather than vouched for: its tenancy
         * design is the one `config/tenancy.php` documents at length -- the request names a store
         * and `resolveOwnedStore()` authorises it against `UserPolicy::merchantTenantRoot($user)`,
         * which is why those models are deliberately absent from `scoped_models`.
         * -------------------------------------------------------------------------------------- */

        // THE OWNERSHIP GATE IS PRESENT IN THE HANDLER. `resolveOwnedStore($storeId)` loads the
        // store and, for a non-operator, adds `where('created_by', merchantTenantRoot($user))` --
        // returning null when the root is null, which every caller answers with a 403 envelope. A
        // read after that gate is reading a row the principal owns. LIMIT 1 applies: the gate being
        // in the function does not prove this read goes through it.
        'app/Http/Controllers/Api/DashboardController.php|ProductImage::where|ownership_checked' => [3,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductVariant::withoutGlobalScopes|ownership_checked' => [2,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::where|ownership_checked' => [2,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Testimonial::find|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Testimonial::AvregeRating|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Product::Sub_image|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|User::where|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Wishlist::where|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],

        // A `store_id` PREDICATE WHOSE VALUE IS THE REQUEST'S OWN, WITH NO OWNERSHIP GATE VISIBLE IN
        // THE HANDLER. This is the shape CLAUDE.md calls out -- "it only compares the caller's own
        // two values" -- and it is the admin API's documented pattern WITHOUT the documented gate.
        // NOT VOUCHED FOR AND NOT FIXED HERE: these are not analytics reads, they are the admin
        // app's product, coupon, order, review and variant CRUD, the file belongs to another
        // workstream, and a middleware-level answer for 45 sites is a contract change against a
        // shipped Flutter client. The exact counts are published so the next reader starts from a
        // number rather than a sample.
        'app/Http/Controllers/Api/DashboardController.php|Product::where|request' => [8,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Product::select|request' => [1,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Category::where|request' => [8,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::where|request' => [7,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::query|request' => [3,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_detail|ownership_checked' => [1,
            'resolveOwnedStore() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Coupon::where|request' => [6,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Testimonial::where|request' => [6,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductVariant::where|request' => [4,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::where|request' => [1,
            'NOT VOUCHED FOR: request store_id with no resolveOwnedStore() in the handler'],

        // NO STORE PREDICATE AT ALL, and no gate visible in the handler. NOT VOUCHED FOR, same
        // reasons; `Store::` and `User::` reads are often the principal's own row by id, which is
        // correct and which this census cannot distinguish from a foreign one.
        'app/Http/Controllers/Api/DashboardController.php|Order::where|none' => [3,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::select|none' => [3,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::find|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::orderBy|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_detail|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_status_change|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::where|none' => [3,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::find|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::query|none' => [1,
            'NOT VOUCHED FOR: no store predicate, no gate visible in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::create|none' => [1,
            'a WRITE, not a read: store creation, where no store exists to scope to'],
        'app/Http/Controllers/Api/DashboardController.php|User::find|none' => [5,
            'NOT VOUCHED FOR: a user row by id; the principal own row in most cases'],
        'app/Http/Controllers/Api/DashboardController.php|User::slugs|none' => [1,
            'NOT VOUCHED FOR: a slug helper on the model class'],
    ];

    private function census(): AnalyticsTenancyCensus
    {
        return new AnalyticsTenancyCensus(base_path());
    }

    /**
     * THE PREMISE, AND IT IS A REACHABILITY ASSERTION. The tenancy skill's §7: "Add a reachability
     * assertion, so a rename cannot make the scan pass vacuously." A census whose pattern stops
     * matching reads exactly like one that looked and found nothing -- which happened twice while
     * this file was being written, costing 79 then 11 false "unscoped" verdicts.
     */
    public function test_the_premise_that_the_census_reads_the_tree_and_finds_the_known_shapes(): void
    {
        $census = $this->census();

        $this->assertNotSame([], $census->files(), 'the census read no files at all');

        foreach ($census->files() as $relative) {
            $this->assertFileExists(base_path($relative));
        }

        $sites = $census->sites();

        $this->assertGreaterThan(200, count($sites),
            'the census found almost nothing, which is what a broken pattern looks like');

        /* The two shapes this codebase really writes, one static and one instance. The static form
           is 33 sites in `ReportController` alone and the first version of the pattern could not
           see it. */
        $shapes = [];

        foreach ($sites as $site) {
            $shapes[$site['model'] . '::' . $site['call']] = true;
        }

        foreach (['Order::where', 'Order::query', 'Visit::query', 'Store::query'] as $shape) {
            $this->assertArrayHasKey($shape, $shapes,
                "the census no longer finds any {$shape} site, so its pattern has stopped matching "
                . 'the code rather than the code having stopped doing it');
        }
    }

    /**
     * AND COMMENTS CANNOT SATISFY OR BREAK IT. CLAUDE.md §3 records eight occasions a census was
     * satisfied -- or broken -- by prose about the thing it measures, four of them on one day.
     */
    public function test_the_premise_that_comments_are_stripped_before_anything_is_counted(): void
    {
        $census = $this->census();

        $raw = (string) file_get_contents(base_path('app/Http/Controllers/ReportController.php'));
        $code = $census->codeOnly('app/Http/Controllers/ReportController.php');

        $this->assertLessThan(strlen($raw), strlen($code),
            'the stripper removed nothing from a file that is full of docblocks, so prose is being '
            . 'counted as code');

        /* AND IT DID NOT EAT THE CODE, which is the opposite failure and the dangerous one: a
           stripper that removes too much turns every absence verdict into a free pass. */
        $this->assertStringContainsString("Order::where('store_id'", $code,
            'the stripper removed real code, so every verdict below is unsafe');

        /* LINE NUMBERS SURVIVE, because each comment is replaced by exactly its own newlines. */
        $this->assertSame(
            substr_count($raw, "\n"),
            substr_count($code, "\n"),
            'the stripped source has a different number of lines, so every line number this census '
            . 'reports points at the wrong statement'
        );
    }

    /**
     * THE MODEL LIST IS A CLAIM ABOUT `config/tenancy.php`, SO IT IS MEASURED AGAINST IT.
     *
     * `AnalyticsTenancyCensus::UNSCOPED_MODELS` is a hardcoded list, and CLAUDE.md's standing
     * warning is that this project's scoped/unscoped table "has been wrong in BOTH directions" --
     * it said `DeliveryBoy` was unscoped until an adversarial verifier refused it, and was silent
     * about eight more. A census whose subject list is stale measures the wrong set and says so
     * nowhere, so both directions are asserted here against the booted container's own config.
     */
    public function test_every_model_this_census_watches_really_adds_no_predicate(): void
    {
        $scoped = array_map('strval', (array) config('tenancy.scoped_models', []));

        $this->assertNotSame([], $scoped,
            'config/tenancy.php declares no scoped models at all, so the comparison below is '
            . 'vacuous and every model would read as unscoped');

        foreach (AnalyticsTenancyCensus::UNSCOPED_MODELS as $short) {
            /* `Visit` is Shetabit's and in no allowlist; everything else is a first-party model. */
            $class = $short === 'Visit'
                ? \Shetabit\Visitor\Models\Visit::class
                : 'App\\Models\\' . $short;

            $this->assertTrue(class_exists($class),
                $class . ' does not exist, so the census is watching a model name that is gone');

            $this->assertNotContains($class, $scoped, sprintf(
                '%s is in config/tenancy.php\'s scoped_models, so StoreScope DOES attach a '
                . 'predicate for it and this census should not be counting its reads as the open '
                . 'door it exists to measure. Remove it from UNSCOPED_MODELS and re-point the '
                . 'totals, naming the slice that scoped it.',
                $class
            ));
        }

        /* THE CONVERSE, so the list cannot silently gain a model the scope really filters. Three
           of the eleven, chosen because each one's absence from this list is load-bearing. */
        foreach ([\App\Models\Customer::class, \App\Models\Cart::class, \App\Models\SupportTicket::class] as $class) {
            $this->assertContains($class, $scoped,
                $class . ' left scoped_models. If a model this census does NOT watch stopped being '
                . 'scoped, its analytics reads became the open door and belong in UNSCOPED_MODELS.');

            $this->assertNotContains(
                class_basename($class),
                AnalyticsTenancyCensus::UNSCOPED_MODELS,
                class_basename($class) . ' is really scoped and is in the census list, which would '
                . 'count a filtered read as an unfiltered one'
            );
        }
    }

    public function test_the_verdict_totals_are_exactly_these(): void
    {
        $counts = [];

        foreach ($this->census()->sites() as $site) {
            $counts[$site['verdict']] = ($counts[$site['verdict']] ?? 0) + 1;
        }

        ksort($counts);
        $expected = self::TOTALS;
        ksort($expected);

        $this->assertSame($expected, $counts,
            "The analytics Eloquent tenancy census moved. This is a ratchet, not a nuisance: a new\n"
            . "read of an unscoped model in an analytics surface must be looked at, and a read that\n"
            . "stopped being principal-derived must be looked at twice. Update the figures here AND\n"
            . "the disposition for the shape that moved, with the reason in the same change.\n");

        $this->assertSame(self::TOTAL_SITES, array_sum($counts));
    }

    /**
     * THE DISPOSITION TABLE, ASSERTED IN BOTH DIRECTIONS. An undispositioned shape fails this, and a
     * disposition for a shape that no longer exists fails it too -- which is the half that keeps the
     * table honest as the code moves.
     */
    public function test_every_non_principal_site_shape_is_dispositioned_with_its_count(): void
    {
        $measured = [];

        foreach ($this->census()->sites() as $site) {
            if ($site['verdict'] === 'principal') {
                continue;
            }

            $key = $site['file'] . '|' . $site['model'] . '::' . $site['call'] . '|' . $site['verdict'];
            $measured[$key] = ($measured[$key] ?? 0) + 1;
        }

        $declared = [];

        foreach (self::DISPOSITIONS as $key => [$count, $reason]) {
            $this->assertNotSame('', trim($reason), $key . ' is declared with no reason at all');
            $declared[$key] = $count;
        }

        ksort($measured);
        ksort($declared);

        $this->assertSame($declared, $measured,
            "The census and its dispositions disagree.\n\n"
            . "A key present in the MEASURED side and absent from DISPOSITIONS is a read of an\n"
            . "unscoped model in an analytics surface that nobody has said anything about. A key\n"
            . "present in DISPOSITIONS and absent from the measurement is a reason outliving the\n"
            . "thing it was written for, which is how a security assertion becomes decoration.\n"
            . "Either way the fix is to read the site, not to adjust the number.\n");
    }

    /**
     * AND THE REASONS SAY WHAT KIND OF STATEMENT THEY ARE. A disposition that only asserts safety is
     * indistinguishable from one that declares an open question, and GAP-167 asks for the second to
     * be visible: the row's own next action says a census that only measures is worth less than one
     * that measures and closes, so the two must be countable apart.
     */
    public function test_the_declared_but_unvouched_sites_are_counted_separately(): void
    {
        $vouched = 0;
        $declared = 0;

        foreach (self::DISPOSITIONS as [$count, $reason]) {
            if (str_starts_with($reason, 'NOT VOUCHED FOR')) {
                $declared += $count;

                continue;
            }

            $vouched += $count;
        }

        /*
         * 72 VOUCHED, 82 DECLARED. Both figures are exact and both are published, which is the
         * point: the analytics and report producers are wholly accounted for, and the two wide
         * controllers GAP-167 also names -- `HomeController` (2,413 lines, mostly storefront) and
         * `Api/DashboardController` (12,492 lines, the admin app's whole CRUD surface) -- are NOT,
         * because censusing a 12,000-line controller's product and coupon handlers is a different
         * piece of work from the analytics subject this row asks for. Nothing is hidden by that:
         * every one of the 84 has its count and its reason above.
         */
        $this->assertSame(72, $vouched, 'the vouched-for count moved');
        $this->assertSame(82, $declared, 'the declared-but-unvouched count moved');
        $this->assertSame(
            self::TOTAL_SITES - self::TOTALS['principal'],
            $vouched + $declared,
            'the two halves do not add up to the non-principal total'
        );
    }

    /**
     * THE ONE DISPOSITION THAT RESTS ON A CLAIM ABOUT ANOTHER FILE, MEASURED RATHER THAN QUOTED.
     *
     * Eight of `MerchantAnalyticsFigures`' sites are dispositioned on the entry condition "`$storeId`
     * reaches this method from `getCurrentStore()` only". That is a sentence in a docblock, and
     * CLAUDE.md's standing warning is that a docblock is evidence somebody understood something, not
     * evidence it is still true. So the call sites are read here.
     */
    public function test_the_merchant_figures_entry_point_takes_its_store_from_the_principal(): void
    {
        $census = $this->census();

        $controller = $census->codeOnly('app/Http/Controllers/MerchantAnalyticsController.php');

        $flat = (string) preg_replace('/\s+/', ' ', $controller);

        /*
         * THE SPELLING IS NOT `forStore((int) getCurrentStore())` AND ASSUMING IT WAS COST A RED
         * TEST. The controller resolves through a private `resolveStore()` that ALSO refuses a
         * platform operator who has chosen no store -- the GAP-077 / GAP-223(b) limb, without which
         * the screen would read store 1's figures for the operator. So the chain is asserted as it
         * is written: the helper derives the id from `getCurrentStore()`, refuses a non-positive
         * one, and every call to the figures class passes that one variable.
         */
        $this->assertStringContainsString('$storeId = (int) getCurrentStore();', $flat,
            'MerchantAnalyticsController::resolveStore() no longer derives the store from '
            . 'getCurrentStore(), so the eight parameter-dispositioned reads inside the figures '
            . 'class rest on nothing');

        $this->assertStringContainsString('if ($storeId <= 0) {', $flat,
            'the non-positive refusal is gone: a merchant with no store would reach the figures '
            . 'class with 0 and `where(store_id, 0)` is not a tenant check');

        $this->assertStringContainsString('UserPolicy::isOperator($caller) && empty($caller->current_store)', $flat,
            'the operator-with-no-store refusal is gone, which is how this screen would read '
            . 'store 1 for the platform operator');

        /* EVERY CALL, not the first one: there are two (the screen and its CSV export) and a
           census satisfied by one of them is the §7 failure "keyed on the methods somebody
           happened to look at". */
        $calls = [];
        preg_match_all('/MerchantAnalyticsFigures::forStore\(\s*([^,]+),/', $flat, $calls);

        $this->assertGreaterThanOrEqual(2, count($calls[1]),
            'the screen and its export both call the figures class; fewer calls than that means '
            . 'one of them has changed shape');

        foreach ($calls[1] as $argument) {
            $this->assertSame('$storeId', trim($argument),
                'a call to MerchantAnalyticsFigures::forStore() passes something other than the '
                . 'resolved $storeId, so the store it reads is no longer the principal\'s');
        }

        $runner = $census->codeOnly('app/Services/Reports/ScheduledReportRunner.php');

        $this->assertStringContainsString('MerchantAnalyticsFigures::forStore(', $runner,
            'the scheduled runner no longer calls the figures class; re-read the disposition');
        $this->assertStringContainsString('$schedule->store_id', $runner,
            'the scheduled runner no longer scopes by the schedule row own store');

        /* AND THERE IS NO THIRD CALLER, because a new one could pass anything. */
        $callers = [];

        foreach (['app', 'packages'] as $directory) {
            $found = [];
            exec('grep -rl --include=*.php ' . escapeshellarg('MerchantAnalyticsFigures::forStore(')
                . ' ' . escapeshellarg(base_path($directory)) . ' 2>/dev/null', $found);

            foreach ($found as $path) {
                $callers[] = ltrim(str_replace(base_path(), '', $path), '/');
            }
        }

        sort($callers);

        /* TWO, not three: the class itself calls its own helpers through `self::`, so it does not
           contain the string. Measured rather than assumed -- the first version of this assertion
           listed three and was red. */
        $this->assertSame([
            'app/Http/Controllers/MerchantAnalyticsController.php',
            'app/Services/Reports/ScheduledReportRunner.php',
        ], $callers,
            'a new caller of MerchantAnalyticsFigures::forStore() exists. The eight reads inside that '
            . 'class carry a predicate whose value is its $storeId parameter, so every caller is part '
            . 'of the tenancy argument: read the new one and either add it here or scope it.');
    }
}
