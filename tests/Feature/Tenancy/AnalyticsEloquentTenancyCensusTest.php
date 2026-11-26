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
        'principal' => 187,
        'ownership_checked' => 50,
        'route_store' => 10,
        'parameter' => 14,
        'no_store_column' => 6,
        'request' => 11,
        'none' => 76,
    ];

    private const TOTAL_SITES = 354;

    /**
     * EVERY SITE SHAPE THAT IS NOT `principal`, WITH ITS COUNT AND ITS REASON.
     *
     * The key is `<file>|<Model>::<call>|<verdict>`. A reason beginning "NOT VOUCHED FOR" is an
     * honest declaration that this slice did not establish the site's safety -- the count is
     * published so nothing is hidden, and the row names which census owns it.
     */
    private const DISPOSITIONS = [
        'app/Http/Controllers/Api/DashboardController.php|Category::where|request' => [1,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|DeliveryAddress::create|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|DeliveryAddress::create|request' => [1,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|DeliveryAddress::where|ownership_checked' => [5,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::create|request' => [1,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|Order::find|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::orderBy|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_detail|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_detail|ownership_checked' => [2,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::order_status_change|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::select|none' => [3,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::where|none' => [3,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Order::where|request' => [6,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|OrderBillingDetail::create|no_store_column' => [1,
            'a WRITE at :9037, not a read: the billing row of an order this handler has just created, keyed on that order own id'],
        'app/Http/Controllers/Api/DashboardController.php|OrderBillingDetail::where|no_store_column' => [2,
            'two sites. DeleteOrder:4350 is the third leg of the gated transaction above. :11042 reads EVERY tenant billing row for one email address -- where(email, $customer->email) with no store column and no join -- and the containment is the NEXT statement, Order::where(store_id, $storeId)->whereIn(id, $order_id), so no foreign order survives. Same shape as OwnedPathRawQueryInventoryTest own disposition: the tenant control is the comparison two lines later. RESIDUAL NAMED: the intermediate array holds another tenant name, email and order ids in memory and is not returned; a future line that returned or logged it would disclose them'],
        'app/Http/Controllers/Api/DashboardController.php|OrderCouponDetail::where|no_store_column' => [1,
            'DeleteOrder:4349, the same transaction and the same gate. THIS IS THE ONLY DIRECT READ OF OrderCouponDetail IN THE CENSUSED FILES: the coupon-discount tile a merchant reads does NOT come through here -- CouponRedemption joins FROM Metric::orders($scope), so the detail rows it sums are reachable only through orders the scope owns, and its docblock states that order_coupon_details has no store_id column for exactly this reason'],
        'app/Http/Controllers/Api/DashboardController.php|OrderTaxDetail::where|no_store_column' => [1,
            'DeleteOrder:4348, where(order_id, $order->id)->delete() inside one transaction. No store_id column exists on order_tax_details, so the tenancy is the ORDER: resolveOwnedStore($request->store_id) gates the handler and the order is resolved through that owned store BEFORE the child deletes, which the handler own comment records was once a live platform-wide defect -- any admin token deleted any order and its tax, coupon and billing rows by sending one integer'],
        'app/Http/Controllers/Api/DashboardController.php|Product::Sub_image|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Product::where|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Product::where|request' => [1,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|ProductBrand::slugs|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductBrand::where|none' => [4,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductImage::where|ownership_checked' => [3,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductVariant::where|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|ProductVariant::withoutGlobalScopes|ownership_checked' => [2,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::create|request' => [1,
            'NOT VOUCHED FOR: request-sourced at the predicate itself with no gate in the same expression; a gate ELSEWHERE in the handler is NOT established either way'],
        'app/Http/Controllers/Api/DashboardController.php|Store::find|none' => [4,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::query|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::slugIsReserved|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::where|none' => [3,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Store::where|ownership_checked' => [3,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Testimonial::AvregeRating|ownership_checked' => [2,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Testimonial::find|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|User::find|none' => [6,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|User::find|ownership_checked' => [2,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|User::slugs|none' => [1,
            'NOT VOUCHED FOR: no store predicate in the window and no gate token in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|User::where|ownership_checked' => [2,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/Api/DashboardController.php|Wishlist::where|ownership_checked' => [1,
            'resolveOwnedStore()/resolveChangeableOrder() gate present in the handler'],
        'app/Http/Controllers/HomeController.php|Category::select|none' => [1,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|Category::where|none' => [3,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|Category::where|route_store' => [1,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|Category::with|none' => [1,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|DeliveryAddress::where|none' => [1,
            'NOT VOUCHED FOR: a shopper address read; the storefront census owns it'],
        'app/Http/Controllers/HomeController.php|Order::order_detail|route_store' => [1,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|Order::selectRaw|none' => [1,
            'dead builder: overwritten by the next line, which carries getCurrentStore()'],
        'app/Http/Controllers/HomeController.php|Order::where|none' => [1,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|OrderBillingDetail::where|no_store_column' => [1,
            'storefront order lookup at :1580: where(order_id, $candidate->id)->value(email) inside a loop over Order::where(product_order_id, ...)->where(store_id, $store->id), so every candidate is already scoped to the route resolved store'],
        'app/Http/Controllers/HomeController.php|Product::productSalesTag|route_store' => [2,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|Product::where|none' => [8,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|ProductBrand::where|none' => [3,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|ProductBrand::where|route_store' => [1,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|ProductVariant::where|route_store' => [5,
            'storefront: the route slug resolved the store'],
        'app/Http/Controllers/HomeController.php|Store::find|none' => [6,
            'host-to-store resolution, before any principal: the host is the tenant selector'],
        'app/Http/Controllers/HomeController.php|Store::query|none' => [1,
            'host-to-store resolution, before any principal: the host is the tenant selector'],
        'app/Http/Controllers/HomeController.php|Testimonial::where|none' => [1,
            'NOT VOUCHED FOR: storefront predicate on a $store this census could not trace in one step'],
        'app/Http/Controllers/HomeController.php|User::find|none' => [5,
            'the resolved store owner or the signed-in user own row, by id'],
        'app/Http/Controllers/HomeController.php|Wishlist::with|none' => [1,
            'REGISTERED SEPARATELY BY THE COORDINATOR AND NOT FIXED HERE: wishlist() reads EVERY wishlist row on the platform anonymously -- the auth(customers) guard and the where(customer_id) predicate are both commented out, the route carries no auth, and Wishlist has no scope. Latent only because the Blade never references the variable'],
        'app/Http/Controllers/ReportController.php|Category::find|ownership_checked' => [11,
            'merchant: id set narrowed to the current store by reportSelectionWithinCurrentStore(); operator: admitted unnarrowed by its own limb, same privilege level; GATE ATTRIBUTION IS PER-METHOD AND NOT PER-ARM -- a declared mutation in mutate-gap167.py shows that deleting one of the four gate calls in this one method moves no number'],
        'app/Http/Controllers/ReportController.php|Order::selectRaw|none' => [3,
            'dead builder: overwritten by the next line, which carries reportStoreId()'],
        'app/Http/Controllers/ReportController.php|ProductBrand::find|ownership_checked' => [10,
            'Category::find twin: ProductBrand carries NO scope at all, and the same reportSelectionWithinCurrentStore() gate narrows the id set; same operator limb; same per-method attribution limit, and this is the shape the declared mutation measures'],
        'app/Http/Controllers/ReportController.php|ProductVariant::where|none' => [3,
            'narrowed by a parent product already scoped to reportStoreId(); the variant store_id column is not also asserted'],
        'app/Services/Reports/ScheduledReportRunner.php|Store::find|none' => [1,
            'the stored schedule row own store_id; no request and no caller-supplied id'],
        'app/Services/Reports/ScheduledReportRunner.php|User::find|none' => [1,
            'the schedule owner, read to re-check the permission at send time'],
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Order::orderSourceLabel|none' => [1,
            'not a query: a static label helper on the model class'],
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Order::query|parameter' => [5,
            'where(store_id, $storeId); $storeId is the entry parameter, fed only from getCurrentStore() or a schedule row'],
        'app/Support/Metrics/MerchantAnalyticsFigures.php|Visit::query|parameter' => [3,
            'where(store_id, $storeId); $storeId is the entry parameter, fed only from getCurrentStore() or a schedule row'],
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Order::orderSourceLabel|none' => [1,
            'not a query: a static label helper on the model class'],
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Order::query|parameter' => [1,
            'whereIn(store_id, $storeIds) from the operator scope; MetricScope refuses an empty list'],
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Store::query|none' => [3,
            'deliberately cross-tenant: the operator screen, whose measurement IS every tenant'],
        'app/Support/Metrics/PlatformAnalyticsFigures.php|Visit::query|parameter' => [4,
            'whereIn(store_id, $storeIds) from the operator scope; MetricScope refuses an empty list'],
        'app/Support/Metrics/StoreInventoryValuation.php|Product::disableCache|parameter' => [1,
            'whereIn(products.store_id, $storeIds) from the metric scope'],
        'app/Support/Metrics/StoreInventoryValuation.php|ProductVariant::disableCache|none' => [1,
            'window ended at a semicolon inside the join closure; the statement carries two store predicates and a whereColumn'],
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

    /**
     * THE PATTERN CAN SEE EVERY SPELLING THE CODEBASE USES — and this is the premise that was
     * missing, not the one that was weak.
     *
     * `test_the_premise_that_the_census_reads_the_tree_and_finds_the_known_shapes` required
     * `Order::where`, `Order::query`, `Visit::query` and `Store::query` to be found SOMEWHERE. They
     * were — in the unqualified spelling — while `\App\Models\Order::where(...)` was invisible for
     * a single character: the lookbehind class contained a literal backslash. 34 reads were hidden,
     * 31 of them in the file this census claims to read whole, several with no store predicate at
     * all. An independent review found it; the premise could not.
     *
     * So the premise is now a SELF-TEST of the pattern against both spellings and against the
     * things it must still refuse. A census that cannot see its subject reads exactly like one that
     * looked and found nothing, which is the tenancy skill's §7 in its fourth dress.
     */
    public function test_the_premise_that_the_pattern_sees_every_spelling_and_refuses_the_rest(): void
    {
        $census = $this->census();

        /* The five spellings this repository really writes, measured: the bare class, the
           leading-backslash fully-qualified form, the same inside a chained expression, the
           imported form after a `(`, and one across a line break. */
        $mustSee = [
            "Order::where('store_id', 1)",
            "\\App\\Models\\Order::where('store_id', 1)",
            "\$x = \\App\\Models\\DeliveryAddress::where('customer_id', 1)->get()",
            "foo(Visit::query()->count())",
            /* The five money-bearing models promoted on 2026-11-26, in both spellings, because a
               pattern that cannot see the newest additions is the item-1 defect returning. */
            "OrderCouponDetail::where('order_id', 1)",
            "\\App\\Models\\OrderBillingDetail::where('email', \$e)",
            "Tax::where('store_id', 1)",
            "\\App\\Models\\Shipping::where('store_id', 1)",
            "Store::query()\n    ->orderBy('id')",
        ];

        foreach ($mustSee as $source) {
            $this->assertNotSame([], $this->matchesOf($census, $source), sprintf(
                "The census pattern cannot see this spelling, so every read written that way is\n"
                . "invisible and the totals are a lower bound by an unknown amount:\n  %s",
                str_replace("\n", ' ', $source)
            ));
        }

        /* And the exclusions that do real work. Each one is a false POSITIVE if it matches. */
        $mustRefuse = [
            "SomeOrder::where('x', 1)",
            "\$Order::where('x', 1)",
            "\$this->Order::where('x', 1)",
        ];

        foreach ($mustRefuse as $source) {
            $this->assertSame([], $this->matchesOf($census, $source), sprintf(
                'The census pattern matches "%s", which is not a read of a watched model. A false '
                . 'positive inflates the totals and sends a reader to a line that is not there.',
                $source
            ));
        }

        /* A SOURCE-LEVEL PIN ON THE EXACT DEFECT, because the self-test above would also pass if
           somebody replaced the pattern with something looser that happened to match. */
        $this->assertStringNotContainsString(
            '(?<![\\w\\\\\\\\$>])',
            file_get_contents(base_path('tests/Support/Analytics/AnalyticsTenancyCensus.php')),
            'the lookbehind excludes a literal backslash again, which hides every fully-qualified '
            . 'model reference'
        );
    }

    /**
     * The sites the census finds in one string of source, through the census's own code path.
     *
     * @return list<array<string, mixed>>
     */
    private function matchesOf(AnalyticsTenancyCensus $census, string $source): array
    {
        $file = tempnam(sys_get_temp_dir(), 'ws6census') . '.php';
        file_put_contents($file, "<?php\n" . $source . ";\n");

        try {
            $probe = new AnalyticsTenancyCensus(dirname($file));

            return $probe->sitesIn(basename($file));
        } finally {
            @unlink($file);
        }
    }

    /**
     * THE WATCH LIST COVERS EVERY UNSCOPED MODEL THESE FILES READ — item 3, and until an
     * independent review said so nothing measured it.
     *
     * `test_every_model_this_census_watches_really_adds_no_predicate` asserts the list contains
     * nothing SCOPED. Nothing asserted it contains everything UNSCOPED, and the proof was
     * `ProductBrand`: `Category`'s identical twin, read 17 times in `ReportController` with 10 of
     * them a bare `find()`, carrying no `BelongsToStore` and no `StoreScope` at all — strictly more
     * open than `Category`'s inert scope — and entirely uncounted, so removing one of the four gate
     * calls that narrow those ids would have moved no number.
     *
     * Every other model these files read is now either watched or exempted BY NAME WITH A REASON in
     * `AnalyticsTenancyCensus::NOT_WATCHED`. An exemption a reader can disagree with is a different
     * thing from a model nobody has looked at.
     */
    public function test_the_watch_list_covers_every_unscoped_model_these_files_read(): void
    {
        $census = $this->census();
        $scoped = array_map('strval', (array) config('tenancy.scoped_models', []));

        $this->assertNotSame([], $scoped, 'no scoped models are configured, so this test is vacuous');

        $read = [];

        foreach ($census->files() as $relative) {
            preg_match_all(
                '/(?<![\w$>])([A-Z][A-Za-z0-9]*)::(?:\w+)\s*\(/',
                $census->codeOnly($relative),
                $found
            );

            foreach ($found[1] as $short) {
                $read[$short] = true;
            }
        }

        $unscopedAndRead = [];

        foreach (array_keys($read) as $short) {
            $class = 'App\\Models\\' . $short;

            if (! class_exists($class)) {
                /* Not a first-party model: a facade, a support class, an exception, a vendored
                   class. `Visit` is the one vendored MODEL these files read and it is watched. */
                continue;
            }

            if (! is_subclass_of($class, \Illuminate\Database\Eloquent\Model::class)) {
                continue;
            }

            if (in_array($class, $scoped, true)) {
                continue;
            }

            $unscopedAndRead[] = $short;
        }

        sort($unscopedAndRead);

        $accounted = array_values(array_unique(array_merge(
            AnalyticsTenancyCensus::UNSCOPED_MODELS,
            array_keys(AnalyticsTenancyCensus::NOT_WATCHED)
        )));

        $unaccounted = array_values(array_diff($unscopedAndRead, $accounted));

        $this->assertSame([], $unaccounted, sprintf(
            "These models are read in the censused files, are Eloquent models, and are in neither\n"
            . "`config/tenancy.php`'s scoped_models NOR this census's own two lists:\n  %s\n\n"
            . "Each is a model whose reads add NO predicate for any principal and that this census\n"
            . "does not count. Watch it (UNSCOPED_MODELS) or exempt it by name with a reason\n"
            . "(NOT_WATCHED) — `ProductBrand` was exactly this, 17 reads and 10 bare find()s, and\n"
            . "nothing moved when it was missing.",
            implode(', ', $unaccounted) ?: '(none)'
        ));

        /* AND THE EXEMPTIONS ARE NOT DEAD TEXT: an exemption for a model these files no longer read
           is a claim outliving its subject, which is how a register becomes decoration. */
        $stale = array_values(array_diff(array_keys(AnalyticsTenancyCensus::NOT_WATCHED), array_keys($read)));

        $this->assertSame([], $stale, sprintf(
            'NOT_WATCHED exempts %s, which the censused files no longer read. Delete the entry.',
            implode(', ', $stale) ?: '(none)'
        ));
    }

    /**
     * THE `no_store_column` VERDICT IS A CLAIM ABOUT THE SCHEMA, SO IT IS MEASURED AGAINST ONE —
     * in both directions, on the live migrated database.
     *
     * The verdict says a store predicate is not MISSING at a site but IMPOSSIBLE, which is a
     * stronger statement than any other verdict here makes and the only one that would be a false
     * ACQUITTAL if it were wrong: a table that does carry `store_id` and is read without one is an
     * ordinary unscoped read, and calling it structural would excuse it. CLAUDE.md's rule is to
     * check `Schema::hasColumn()` rather than the migration's wording, so that is what this does.
     *
     * The converse matters as much: a model on the watch list that is NOT in the structural list
     * must really have the column, or the census is asking a question its table cannot answer and
     * every `none` verdict on it is a false accusation.
     */
    public function test_the_structural_list_is_measured_against_the_live_schema_in_both_directions(): void
    {
        foreach (AnalyticsTenancyCensus::TABLES_WITHOUT_STORE_COLUMN as $model => $table) {
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable($table), sprintf(
                '%s is declared structural against the table `%s`, which does not exist.', $model, $table
            ));

            $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn($table, 'store_id'), sprintf(
                "`%s` DOES carry a `store_id` column, so %s's reads are ordinary unscoped reads and\n"
                . "the `no_store_column` verdict is excusing them. That verdict is the only one here\n"
                . 'that would be a false ACQUITTAL if it were wrong -- move the model out of '
                . 'TABLES_WITHOUT_STORE_COLUMN and re-disposition its sites.',
                $table, $model
            ));

            /* AND THE ONLY PREDICATE ITS ROWS CAN CARRY IS THE ONE ITS DISPOSITIONS NAME. */
            $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn($table, 'order_id'), sprintf(
                '`%s` carries neither `store_id` nor `order_id`, so this census cannot say where its '
                . 'tenancy could come from at all.', $table
            ));
        }

        /* THE CONVERSE. Every watched model whose table is NOT declared structural must have the
           column, or its `none` verdicts accuse it of omitting something unavailable. */
        $tables = [
            'Order' => 'orders', 'Visit' => 'shetabit_visits', 'Store' => 'stores',
            'User' => 'users', 'Product' => 'products', 'Category' => 'categories',
            'ProductBrand' => 'product_brands', 'Wishlist' => 'wishlists',
            'Testimonial' => 'testimonials', 'ProductImage' => 'product_images',
            'DeliveryAddress' => 'delivery_addresses', 'ProductVariant' => 'product_variants',
            'Coupon' => 'coupons', 'Tax' => 'taxes', 'Shipping' => 'shippings',
        ];

        foreach (AnalyticsTenancyCensus::UNSCOPED_MODELS as $model) {
            if (array_key_exists($model, AnalyticsTenancyCensus::TABLES_WITHOUT_STORE_COLUMN)) {
                continue;
            }

            /*
             * `Store` AND `User` CARRY THEIR TENANT ON ANOTHER COLUMN, and this direction of the
             * test is what found that: `stores` has no `store_id` -- its `id` IS the tenant -- and
             * `users` carries `created_by`, the tenant ROOT. Their named column is asserted to
             * exist instead, and LIMIT 6 records that every `none` verdict on either means "no
             * `store_id` predicate" rather than "no tenant predicate".
             */
            if (array_key_exists($model, AnalyticsTenancyCensus::TENANT_COLUMN_IS_NOT_STORE_ID)) {
                $column = AnalyticsTenancyCensus::TENANT_COLUMN_IS_NOT_STORE_ID[$model];

                $this->assertTrue(
                    \Illuminate\Support\Facades\Schema::hasColumn($tables[$model] ?? '', $column),
                    sprintf('%s is declared to carry its tenant on `%s`, which its table does not have.', $model, $column)
                );

                $this->assertFalse(
                    \Illuminate\Support\Facades\Schema::hasColumn($tables[$model] ?? '', 'store_id'),
                    sprintf(
                        '`%s` DOES carry `store_id` after all, so %s should be asked the ordinary '
                        . 'question and the exemption is excusing it.',
                        $tables[$model] ?? '', $model
                    )
                );

                continue;
            }

            $this->assertArrayHasKey($model, $tables, sprintf(
                '%s is watched and this test does not know its table, so its structural status is '
                . 'unmeasured. Name the table here.', $model
            ));

            $this->assertTrue(
                \Illuminate\Support\Facades\Schema::hasColumn($tables[$model], 'store_id'),
                sprintf(
                    "`%s` has NO `store_id` column, so %s's reads cannot carry one and every `none`\n"
                    . 'verdict on it is a false accusation. Declare it in '
                    . 'TABLES_WITHOUT_STORE_COLUMN.',
                    $tables[$model], $model
                )
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
         * 108 VOUCHED, 59 DECLARED, 167 non-principal sites in all. Both figures are exact and both
         * are published, which is the point: the analytics and report producers are wholly accounted
         * for, and the two wide controllers GAP-167 also names -- `HomeController` (2,413 lines,
         * mostly storefront) and `Api/DashboardController` (12,492 lines, the admin app's whole CRUD
         * surface) -- are NOT, because censusing a 12,000-line controller's product and coupon
         * handlers is a different piece of work from the analytics subject this row asks for.
         * Nothing is hidden by that: every one of the 59 has its count and its reason above.
         *
         * THE VOUCHED FIGURE ROSE 72 -> 102 ON A CORRECTION, not on new work, and the correction is
         * worth more than the number. The old reason text on the request-sourced sites read "with no
         * `resolveOwnedStore()` in the handler", and that was FALSE: `source()` tested the request
         * tokens BEFORE the principal ones, so the admin API's documented shape -- gate the
         * requested id, then filter on the gated store -- classified as `request`, and the sentence
         * pointed the next reader at about forty-five sites of remediation work against a hole that
         * is not there. An independent review verified the gate at `DashboardController.php:1755`,
         * `:1806`, `:1934`, `:2065`, `:4373` and `:4860` and counted `resolveOwnedStore(` 144 times
         * in that file. Testing the principal first moved 35 sites from `request` to `principal`;
         * the 17 that remain are request-sourced at the predicate with no gate in the same
         * expression, and their reason now says only that, because a gate ELSEWHERE in the handler
         * is something this census does not establish either way.
         */
        $this->assertSame(108, $vouched, 'the vouched-for count moved');
        $this->assertSame(59, $declared, 'the declared-but-unvouched count moved');
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
