<?php

namespace Tests\Feature\Security;

use App\Models\Permission;
use App\Models\Store;
use App\Models\User;
use App\Support\Tenancy\OperatorStoreSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * GAP-223(b) -- A PLATFORM OPERATOR WHO HAS CHOSEN NO STORE MUST SEE NO TENANT'S DATA.
 * =================================================================================================
 *
 * Owner direction, 2026-11-21. `getCurrentStore()` ends in a HARD-CODED LITERAL 1 for a user whose
 * `type` is `'super admin'` and whose `current_store` is empty. Store 1 is not a platform scope: it
 * is an ordinary merchant's tenant, and this project's own words at `StoreFinanceController:833`
 * are that "store 1 belongs to a merchant who did not ask for it". So the default was never "show
 * everything" -- it was "show one unlucky merchant's books to whoever signs in without choosing".
 *
 * THE DECOY IS STORE 1, WITH DISTINCTIVE FIGURES, and that is the whole design of this file. Every
 * earlier version of a test like this used the first tenant of a fresh database -- which IS store 1
 * -- so a handler that read the wrong tenant passed anyway. Here store 1 is a different merchant
 * with recognisable amounts, and the operator under test owns nothing.
 *
 * WHAT THE FIX IS, AND WHAT IT DELIBERATELY IS NOT. The handlers narrow their SCOPE to
 * `OperatorStoreSelection::NO_STORE` rather than refusing the request. Three reasons, all measured:
 * the six chart views test their series with `Array.isArray`, which is false for a string or null,
 * so an invented payload shape would break them; the shipped Flutter app FORCES A LOGOUT ON ANY 401,
 * so inventing a status would be a user-visible regression in another product; and a scope of
 * `NO_STORE` lets each handler build its OWN correctly-shaped empty answer instead of one this slice
 * had to guess six times.
 *
 * `NO_STORE` IS 0 AND NOT NULL, which is not a style choice. `where('store_id', null)` compiles to
 * `store_id IS NULL`, and this database HAS orphaned rows with a null `store_id` -- there is a
 * standing report script for them. A null sentinel would match those, which is a different leak
 * rather than a refusal. `stores.id` is a positive auto-increment, so 0 is unreachable.
 *
 * NOTHING HERE BROADENS ACCESS: every outcome is strictly narrower than the literal 1 it replaces,
 * and the legitimate merchant-owned-store fallback is asserted to still work.
 */
class OperatorUnchosenStoreReportsTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    /** Store 1's own revenue, which no other principal's screen may ever show. */
    private const DECOY_TOTAL = '54321.99';

    private const DECOY_SHOWN = '54,321.99';

    /**
     * THE REPORT ROUTES THAT CAN ACTUALLY EXECUTE UNDER THE SUITE, and the list is short for a
     * measured reason rather than a convenient one.
     *
     * MEASURED, by driving all five candidates as a storeless operator:
     *   /category-order-reports   200  clean
     *   /brand-order-reports      200  clean
     *   /order-reports-chart      500  `no such function: MONTH`
     *   /product-order-reports    500  `no such function: MONTH`
     *   /order-report             404  (not a route; it was a guess)
     *
     * The two 500s are the standing SQLite limitation -- those handlers use `MONTH(` in `selectRaw`
     * and SQLite has no such function -- and their DEBUG ERROR PAGE dumps the failing query's rows,
     * so the decoy amount appears in 672 KB of stack trace. That is a test artifact, NOT a leak: in
     * production `APP_DEBUG` is false and the same request is a plain 500. Asserting over that page
     * would be asserting about Laravel's error renderer, so those two handlers are covered by the
     * SOURCE CENSUS and the resolver assertions in this file instead -- which is the same split
     * `LastMonthWindowOverflowTest` uses, for the same reason.
     */
    private const REPORT_URIS = [
        '/category-order-reports',
        '/brand-order-reports',
    ];

    /** Store 1 as a DECOY tenant with recognisable money, plus a second real tenant. */
    private function decoyStoreOne(): Store
    {
        [, $decoy] = $this->makeTenant();

        $this->assertSame(1, (int) $decoy->id,
            'premise: the decoy really is store 1, the literal getCurrentStore() used to return');

        $order = $this->makeOrder($decoy, ['order_date' => now()->subDays(3)->toDateTimeString()]);
        /* `final_price` is the column the report handlers actually sum (4 sites); `orders` has no
           `total_amount` at all -- measured off the create migration after an earlier version of
           this fixture failed with "no such column". */
        DB::table('orders')->where('id', $order->id)->update(['final_price' => self::DECOY_TOTAL]);
        (new \App\Models\Order)->flushCache();

        return $decoy;
    }

    private function operatorWithNoStore(): User
    {
        $operator = $this->makePlatformAdmin(['current_store' => null]);

        foreach (['Manage Order Reports', 'Manage Reports'] as $permission) {
            Permission::firstOrCreate(['name' => $permission], ['display_name' => $permission]);
        }

        Cache::forget('admin_details');

        return User::find($operator->id);
    }

    // ---------------------------------------------------------------------------------------------
    // NEGATIVE: the storeless operator
    // ---------------------------------------------------------------------------------------------

    public function test_the_resolver_refuses_a_store_to_an_operator_who_has_chosen_none(): void
    {
        $this->decoyStoreOne();
        $operator = $this->operatorWithNoStore();

        $this->actingAs($operator);

        // The premise: the UNGUARDED helper really does still answer 1 for this principal.
        $this->assertSame(1, (int) getCurrentStore(),
            'premise: getCurrentStore() returns the literal 1 here, which is the defect');

        $this->assertTrue(OperatorStoreSelection::unchosen(), 'this principal is the one the rule is about');
        $this->assertSame(OperatorStoreSelection::NO_STORE, OperatorStoreSelection::reportStoreId(),
            'a report must be scoped to no store, never to store 1');
    }

    public function test_no_store_matches_no_row_and_is_not_null(): void
    {
        $this->decoyStoreOne();

        $this->assertSame(0, OperatorStoreSelection::NO_STORE);
        $this->assertNotNull(OperatorStoreSelection::NO_STORE,
            'null would compile to `store_id IS NULL` and match the orphaned rows this database holds');

        // And it really selects nothing, against real rows.
        $this->assertSame(0, \App\Models\Order::withoutGlobalScopes()
            ->where('store_id', OperatorStoreSelection::NO_STORE)->count());
        $this->assertGreaterThan(0, \App\Models\Order::withoutGlobalScopes()
            ->where('store_id', 1)->count(), 'premise: store 1 does have rows to leak');
    }

    /** @dataProvider reportUriProvider */
    public function test_a_storeless_operator_is_never_shown_store_ones_figures(string $uri): void
    {
        $this->decoyStoreOne();
        $operator = $this->operatorWithNoStore();

        $response = $this->actingAs($operator)->get($uri);

        /*
         * NOT asserting a status. These five differ -- some render a view, some answer JSON -- and the
         * rule is about the DATA, not the shape. A 500 would also fail the assertion below, which is
         * the outcome that matters either way.
         */
        $body = html_entity_decode((string) $response->getContent(), ENT_QUOTES | ENT_HTML5);

        $this->assertStringNotContainsString(self::DECOY_SHOWN, $body,
            "{$uri}: store 1's revenue is on a storeless operator's screen");
        $this->assertStringNotContainsString(self::DECOY_TOTAL, $body,
            "{$uri}: store 1's raw revenue is on a storeless operator's screen");
    }

    public static function reportUriProvider(): array
    {
        return array_map(static fn (string $uri): array => [$uri], self::REPORT_URIS);
    }

    public function test_the_two_suite_unreachable_handlers_are_narrowed_in_source(): void
    {
        /*
         * `order_reports_chart` and `order_product_reports` answer 500 under SQLite (`no such
         * function: MONTH`), so no HTTP assertion can reach them. Their narrowing is therefore
         * asserted where it IS visible: in the source of the two methods, read comment-stripped.
         * Named individually rather than relying on the whole-file census, so that deleting one
         * handler's narrowing cannot hide behind the other 13 still being correct.
         */
        $source = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        foreach (['order_reports_chart', 'order_product_reports'] as $method) {
            $start = strpos($source, 'public function '.$method.'(');
            $this->assertNotFalse($start, "premise: {$method} is still in the controller");

            // To the next `public function`, which bounds the method well enough for a census.
            $next = strpos($source, 'public function ', $start + 20);
            $body = substr($source, $start, $next === false ? null : $next - $start);

            $this->assertStringNotContainsString('getCurrentStore()', $body,
                "{$method} reads the bare helper, which answers the literal 1 for a storeless operator");
            $this->assertStringContainsString('OperatorStoreSelection::reportStoreId()', $body,
                "{$method} should read the guarded resolver");
        }
    }

    public function test_the_selection_narrower_is_bypassed_for_an_operator_and_that_is_pre_existing(): void
    {
        $this->decoyStoreOne();
        $operator = $this->operatorWithNoStore();
        $this->actingAs($operator);

        $method = new \ReflectionMethod(\App\Http\Controllers\ReportController::class, 'reportSelectionWithinCurrentStore');
        $method->setAccessible(true);
        $controller = new \App\Http\Controllers\ReportController;

        /*
         * WHAT IS ACTUALLY TRUE HERE, measured rather than assumed -- an earlier version of this test
         * drove `/product-order-reports` and "found a leak" that was really Laravel's debug error
         * page dumping the failing query's rows (that route answers 500 under SQLite: `no such
         * function: MONTH`).
         *
         * `reportSelectionWithinCurrentStore()` returns `$selected` UNNARROWED for a canonically
         * spelled operator, via a bypass that predates GAP-223(b). So the selection was never
         * narrowed to store 1 for them, and narrowing the store resolver below that bypass changes
         * nothing for this principal. Asserted so the no-op is on the record instead of being
         * claimed as a fix.
         */
        $this->assertSame([7, 9], $method->invoke($controller, \App\Models\Product::class, [7, 9]),
            'the pre-existing operator bypass passes the selection through unnarrowed');
    }

    public function test_a_non_operator_with_no_resolvable_store_gets_an_empty_selection(): void
    {
        $this->decoyStoreOne();

        // A user who is neither operator nor store owner: nothing resolves, so nothing is selectable.
        $orphan = User::factory()->create(['type' => 'admin', 'current_store' => null]);
        $this->actingAs($orphan);

        $method = new \ReflectionMethod(\App\Http\Controllers\ReportController::class, 'reportSelectionWithinCurrentStore');
        $method->setAccessible(true);

        $out = $method->invoke(new \App\Http\Controllers\ReportController, \App\Models\Product::class, [7, 9]);

        /*
         * AN UNRESOLVED TENANT IS OUTSIDE, NOT EVERYWHERE -- `StoreScope` fails OPEN on a null tenant
         * (`null_tenant => 'open'`), so a predicate whose whole job is to hold where the scope adds
         * nothing must not inherit that. `reportStoreId()` answers 0 here and `empty(0)` is true.
         */
        $this->assertInstanceOf(\Illuminate\Support\Collection::class, $out);
        $this->assertTrue($out->isEmpty(), 'an unresolved tenant selects nothing, not everything');
    }

    // ---------------------------------------------------------------------------------------------
    // POSITIVE: nobody legitimate loses anything
    // ---------------------------------------------------------------------------------------------

    public function test_an_operator_who_has_chosen_a_store_still_reads_it(): void
    {
        $this->decoyStoreOne();
        [, $chosen] = $this->makeTenant();

        $operator = $this->makePlatformAdmin(['current_store' => $chosen->id]);
        Cache::forget('admin_details');

        $this->actingAs(User::find($operator->id));

        $this->assertFalse(OperatorStoreSelection::unchosen(),
            'an operator who HAS chosen a store is not the principal this rule is about');
        $this->assertSame((int) $chosen->id, OperatorStoreSelection::reportStoreId(),
            'and they must still read the store they chose -- this change must not narrow them');
    }

    public function test_the_merchant_owned_store_fallback_is_preserved(): void
    {
        $this->decoyStoreOne();
        [$merchant, $owned] = $this->makeTenant();

        /*
         * THE FALLBACK THE OWNER ASKED TO KEEP. A merchant who owns a store but never switched to one
         * has an empty `current_store`, and `getCurrentStore()` resolves them by OWNERSHIP
         * (`Store::where('created_by', $user->id)`). That is legitimate and must survive: it is also
         * the case that made an earlier mutation of this limb survive, because dropping the operator
         * test left `empty($current_store)` true for this merchant too.
         */
        DB::table('users')->where('id', $merchant->id)->update(['current_store' => null]);
        (new User)->flushCache();

        $this->actingAs(User::find($merchant->id));

        $this->assertFalse(OperatorStoreSelection::unchosen(), 'a merchant is not an operator');
        $this->assertSame((int) $owned->id, OperatorStoreSelection::reportStoreId(),
            'the ownership fallback must still resolve a merchant who never switched store');
    }

    public function test_a_non_canonically_spelled_operator_is_still_caught(): void
    {
        $this->decoyStoreOne();

        /* A TRAILING space only. `UserPolicy::isOperator()` rtrims SPACE and nothing else, matching
           MySQL's PAD SPACE collation -- measured: a LEADING space is NOT ignored, and neither is a
           tab, which its own comment states. An earlier version of this test used
           `' Super Admin '` and failed for that reason: the premise was wrong, not the guard. */
        $operator = $this->makePlatformAdmin(['type' => 'Super Admin ', 'current_store' => null]);
        Cache::forget('admin_details');

        $this->actingAs(User::find($operator->id));

        /*
         * `UserPolicy::isOperator()` rtrims trailing SPACE only, matching MySQL's PAD SPACE
         * collation, so this spelling is the operator everywhere else in the application. A guard
         * that compared `=== 'super admin'` itself would MISS this principal and leave the defect
         * open for them -- which is why the rule reuses the policy rather than re-spelling it.
         */
        $this->assertTrue(OperatorStoreSelection::unchosen(),
            'a non-canonically spelled operator is still an operator and still has no store');
        $this->assertSame(OperatorStoreSelection::NO_STORE, OperatorStoreSelection::reportStoreId());
    }

    public function test_a_leading_space_spelling_lands_fail_closed_too_though_by_a_different_route(): void
    {
        $this->decoyStoreOne();

        $operator = $this->makePlatformAdmin(['type' => ' super admin', 'current_store' => null]);
        Cache::forget('admin_details');
        $this->actingAs(User::find($operator->id));

        /*
         * A LEADING space is deliberately NOT operator status (`isOperator` rtrims only), so this
         * principal is not caught by the guard -- and lands on NO_STORE anyway, by a different route:
         * `getCurrentStore()`'s own `$user->type == 'super admin'` is also false for this spelling, so
         * it falls to the OWNERSHIP branch, finds no store, and returns null, which casts to 0.
         *
         * Asserted because it is the difference between fail-closed by design and fail-closed by
         * accident, and the next reader should know which this is. If `isOperator` is ever widened to
         * trim leading space, this test keeps passing through the guard instead -- which is why it
         * asserts the OUTCOME and not the mechanism.
         */
        $this->assertFalse(OperatorStoreSelection::unchosen(),
            'premise: a leading space is not operator status, by UserPolicy design');
        $this->assertSame(OperatorStoreSelection::NO_STORE, OperatorStoreSelection::reportStoreId(),
            'and the outcome is still no store, never store 1');
    }

    public function test_the_platform_wide_path_is_where_cross_tenant_figures_belong(): void
    {
        /*
         * The owner's direction: platform-wide analytics keep a SEPARATELY AUTHORIZED, clearly
         * labelled path. That path is `PlatformAnalyticsController` (P5-7) and it is not touched by
         * this change -- asserted so that narrowing the reports cannot be read as having removed the
         * operator's legitimate cross-tenant view.
         */
        $this->assertTrue(class_exists(\App\Http\Controllers\PlatformAnalyticsController::class),
            'the sanctioned platform-wide path must exist, or narrowing the reports removes a capability');
        $this->assertTrue(class_exists(\App\Support\Metrics\PlatformAnalyticsFigures::class));
    }

    public function test_every_report_handler_reads_the_guarded_resolver_and_not_the_bare_helper(): void
    {
        $source = $this->sourceWithoutComments('app/Http/Controllers/ReportController.php');

        /*
         * THE CENSUS, comment-stripped -- this file and the controller both discuss `getCurrentStore()`
         * by name, and a census that reads comments can be satisfied by prose about the thing it
         * measures. 143 reads across 15 handlers were narrowed; a new one spelled the old way is a
         * surface that defaults to store 1 again.
         */
        $this->assertStringNotContainsString('getCurrentStore()', $source,
            'a report handler reads the bare helper, which answers the literal 1 for a storeless operator');
        $this->assertGreaterThanOrEqual(100, substr_count($source, 'OperatorStoreSelection::reportStoreId()'),
            'the narrowed reads should be present, not merely the old ones removed');
    }
}
