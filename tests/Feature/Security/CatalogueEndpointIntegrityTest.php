<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-10 (a), (b) and (d) — the catalogue endpoints that could not work, and the dead code
 * behind them.
 * =============================================================================================
 *
 * Three items of one task row, none of them a cross-tenant breach on a reachable route, grouped
 * because each is smaller than its own package. The (c) and (e) items are in
 * MerchantProductOwnershipTest alongside the P1-INV-07 ownership tests for the same three routes.
 *
 * (a) `Api\DashboardController::AddVariant` wrote `$ProductVariant->name` and `->type`.
 *     `product_variants` has NEITHER column, so the INSERT raised `Unknown column 'name'` -- an
 *     unhandled QueryException, a 500 for every caller including the store's own owner. And the
 *     contract cannot be repaired in place: `product_id`, `variant`, `sku` and `price` are NOT
 *     NULL with no default and the request carries none of them. The endpoint refuses.
 *
 *     THE ROW NAMED ONE METHOD; THE DEREFERENCE NAMES TWO. `UpdateVariant` carried the same
 *     `->type` write behind `if (!empty($request->type))`, so it worked when the caller sent
 *     `name` alone and threw the moment `type` was sent. That half is repaired rather than
 *     refused, because `name` -> `variant` already worked and still does.
 *
 * (b) `PUT products/sku_combination/edit` pointed at `ProductController::sku_combination_edit`,
 *     which does not exist. Removed, and pinned by a ratchet over EVERY host-app route rather
 *     than by naming the one uri -- a spelling-keyed assertion is census-failure mode 1 and this
 *     project has hit it four times.
 *
 * (d) Two of the four `_backup` methods still keyed their per-row lookups on bare request input,
 *     although the file docblock claimed all four had been "repaired anyway so they cannot be
 *     wired up later and reintroduce the hole". Those two are deleted; the two that really are
 *     tenant-scoped stay.
 *
 * WHAT THESE TESTS DO NOT PROVE. No MySQL runs here (phpunit.xml pins SQLite `:memory:`), so the
 * `Unknown column` error these assertions rely on is SQLite's `no column named name`. The column
 * is absent in the migration, not merely mistyped by one engine, so the premise test asserts the
 * SCHEMA with `Schema::hasColumn()` and the behaviour test asserts the REFUSAL -- neither depends
 * on an engine's error text.
 */
class CatalogueEndpointIntegrityTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const API_CONTROLLER = 'app/Http/Controllers/Api/DashboardController.php';

    /**
     * Host-app route actions whose controller method does not exist, as measured by P1-INV-10.
     *
     * FIFTY-EIGHT carry one of the seven `Route::resource()` action names across seventeen
     * controllers -- a resource declared for a controller that implements only some of the seven
     * registers the rest anyway. The other NINE are ordinary named routes whose method simply does
     * not exist, and they are the more interesting half: `OrderController@place_order`
     * (routes/customer.php:161, the web `place.order` route -- dead, because no Blade template or
     * JavaScript references it and `OrderController::store()` is the live checkout writer),
     * `Api\ApiController@random_product` (a storefront API route), three `MyFatoorahController`
     * methods, both `ModuleController@AddOnSession*` add-on purchase routes,
     * `AppSettingController@image_delete` and `OrderController@allOrders`.
     *
     * Not this task's to fix -- see the sweep's docblock -- and asserted in BOTH directions so the
     * list cannot rot.
     *
     * @var list<string>
     */
    private const DEAD_ROUTE_ACTIONS = [
        'App\\Http\\Controllers\\AccountProfileController@destroy',
        'App\\Http\\Controllers\\AccountProfileController@edit',
        'App\\Http\\Controllers\\AccountProfileController@show',
        'App\\Http\\Controllers\\AccountProfileController@update',
        'App\\Http\\Controllers\\AddonController@edit',
        'App\\Http\\Controllers\\AddonController@show',
        'App\\Http\\Controllers\\AddonController@store',
        'App\\Http\\Controllers\\AddonController@update',
        'App\\Http\\Controllers\\Api\\ApiController@random_product',
        'App\\Http\\Controllers\\AppSettingController@destroy',
        'App\\Http\\Controllers\\AppSettingController@edit',
        'App\\Http\\Controllers\\AppSettingController@image_delete',
        'App\\Http\\Controllers\\AppSettingController@show',
        'App\\Http\\Controllers\\AppSettingController@update',
        'App\\Http\\Controllers\\BlogController@show',
        'App\\Http\\Controllers\\CustomerController@edit',
        'App\\Http\\Controllers\\CustomerController@update',
        'App\\Http\\Controllers\\ModuleController@AddOnSessionSave',
        'App\\Http\\Controllers\\ModuleController@AddOnSessioncreate',
        'App\\Http\\Controllers\\MyFatoorahController@callback',
        'App\\Http\\Controllers\\MyFatoorahController@checkout',
        'App\\Http\\Controllers\\MyFatoorahController@index',
        'App\\Http\\Controllers\\MyFatoorahController@webhook',
        'App\\Http\\Controllers\\OrderController@allOrders',
        'App\\Http\\Controllers\\OrderController@edit',
        'App\\Http\\Controllers\\OrderController@place_order',
        'App\\Http\\Controllers\\OrderController@update',
        'App\\Http\\Controllers\\OrderNoteController@create',
        'App\\Http\\Controllers\\OrderNoteController@edit',
        'App\\Http\\Controllers\\OrderNoteController@index',
        'App\\Http\\Controllers\\OrderNoteController@show',
        'App\\Http\\Controllers\\OrderNoteController@update',
        'App\\Http\\Controllers\\OrderRefundController@create',
        'App\\Http\\Controllers\\OrderRefundController@destroy',
        'App\\Http\\Controllers\\OrderRefundController@edit',
        'App\\Http\\Controllers\\OrderRefundController@store',
        'App\\Http\\Controllers\\OrderRefundController@update',
        'App\\Http\\Controllers\\PlanRequestController@create',
        'App\\Http\\Controllers\\PlanRequestController@destroy',
        'App\\Http\\Controllers\\PlanRequestController@edit',
        'App\\Http\\Controllers\\PlanRequestController@show',
        'App\\Http\\Controllers\\PlanRequestController@store',
        'App\\Http\\Controllers\\PlanRequestController@update',
        'App\\Http\\Controllers\\PosController@destroy',
        'App\\Http\\Controllers\\PosController@edit',
        'App\\Http\\Controllers\\PosController@show',
        'App\\Http\\Controllers\\PosController@update',
        'App\\Http\\Controllers\\ReportController@create',
        'App\\Http\\Controllers\\ReportController@destroy',
        'App\\Http\\Controllers\\ReportController@edit',
        'App\\Http\\Controllers\\ReportController@show',
        'App\\Http\\Controllers\\ReportController@store',
        'App\\Http\\Controllers\\ReportController@update',
        'App\\Http\\Controllers\\ShopifyCategoryController@create',
        'App\\Http\\Controllers\\ShopifyCategoryController@store',
        'App\\Http\\Controllers\\ShopifyProductController@create',
        'App\\Http\\Controllers\\ShopifyProductController@destroy',
        'App\\Http\\Controllers\\ShopifyProductController@store',
        'App\\Http\\Controllers\\ShopifyProductController@update',
        'App\\Http\\Controllers\\SupportTicketController@create',
        'App\\Http\\Controllers\\SupportTicketController@show',
        'App\\Http\\Controllers\\SupportTicketController@store',
        'App\\Http\\Controllers\\SupportTicketController@update',
        'App\\Http\\Controllers\\WoocomCategoryController@destroy',
        'App\\Http\\Controllers\\WoocomCategoryController@update',
        'App\\Http\\Controllers\\WoocomProductController@create',
        'App\\Http\\Controllers\\WoocomProductController@store',
    ];

    // ------------------------------------------------------------------ (a) the premise

    /**
     * THE PREMISE. Without this, every assertion below could hold for the wrong reason.
     *
     * If `product_variants` did carry `name` and `type`, the old code was fine and the refusal
     * this task adds is a regression. It does not: the create migration lists neither and no
     * later migration alters the table.
     */
    public function test_product_variants_has_neither_a_name_nor_a_type_column(): void
    {
        $this->assertTrue(Schema::hasTable('product_variants'), 'fixture: the table must exist');

        $this->assertFalse(
            Schema::hasColumn('product_variants', 'name'),
            'product_variants.name exists after all, so AddVariant was not broken and refusing '
            . 'it is a regression. Re-read P1-INV-10 (a) before trusting this file.'
        );
        $this->assertFalse(
            Schema::hasColumn('product_variants', 'type'),
            'product_variants.type exists after all, so the UpdateVariant repair removed a '
            . 'working write.'
        );

        // And the label column the API's `name` parameter really means.
        $this->assertTrue(Schema::hasColumn('product_variants', 'variant'));
    }

    /**
     * THE DEFECT, REPRODUCED. A `name` write to this table is a database error, not a no-op.
     *
     * This is what `AddVariant` did on every call. Asserted here rather than inferred from the
     * schema, because "the column is missing" and "the write throws" are two different claims and
     * only the second one is the 500.
     */
    public function test_the_premise_a_name_write_to_product_variants_is_a_database_error(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $variant = new \App\Models\ProductVariant();
        $variant->name = 'Colour';
        $variant->type = 'select';
        $variant->store_id = $store->id;

        $this->expectException(\Illuminate\Database\QueryException::class);
        $variant->save();
    }

    // ------------------------------------------------------------------ (a) the behaviour

    /**
     * A MERCHANT'S OWN CALL IS REFUSED CLEANLY INSTEAD OF RAISING A 500.
     *
     * The principal is the store's OWNER calling about their OWN store, so nothing but the
     * endpoint itself can be the reason for the refusal. `withoutExceptionHandling()` is
     * deliberately NOT used: the point is that the caller gets this file's error envelope, and
     * with exception handling off the pre-fix QueryException would surface as an exception rather
     * than as the 500 a real caller sees.
     */
    public function test_add_variant_refuses_instead_of_raising_a_database_error(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $before = DB::table('product_variants')->count();

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/addvariant', [
                'store_id' => $store->id,
                'name' => 'Colour',
                'type' => 'select',
            ]);

        $this->assertNotSame(500, $response->status(),
            'The endpoint still raises a database error instead of refusing. Body: '
            . substr($response->getContent(), 0, 300));
        $this->assertNotSame(404, $response->status(),
            'Route gone: a status assertion on a 404 proves nothing (census-failure mode 2).');
        $this->assertSame(200, $response->status(),
            'This file answers denials with HTTP 200 + status:0 on purpose -- a 401 is what '
            . 'repo-B lib/network/network.dart turns into a forced logout.');

        $this->assertSame(0, (int) $response->json('status'),
            'The refusal must be the error envelope, not a success. Body: '
            . substr($response->getContent(), 0, 300));

        $this->assertSame(
            $before,
            DB::table('product_variants')->count(),
            'A variant row was created, so the endpoint is doing something after all.'
        );
    }

    /**
     * THE REPAIRED HALF: `UpdateVariant` applies `name` EVEN WHEN `type` IS ALSO SENT.
     *
     * This is the test the `type` removal is for. Before it, the two assignments ran together and
     * the save threw on the second one, so the `variant` update was lost; a caller sending `name`
     * alone never saw it. Driven with BOTH fields, which is the shape that failed.
     */
    public function test_update_variant_applies_the_name_when_a_type_is_also_sent(): void
    {
        [$merchant, $store] = $this->makeTenant();

        /*
         * `Edit Products`, which this endpoint did not require until F202.
         *
         * `UpdateVariant` had NO permission check at all, and the field it writes -- `variant` --
         * is the key both of `ProductController::update()`'s rebuilds match their deletes on, so a
         * rename armed the owner's next save to hard-delete the row. It now reads the permission
         * against the parent product's store through `VariantRemovalPolicy::authorize()`. In
         * production every merchant holds the shared global `admin` role that carries this, so the
         * grant below is the fixture catching up with production rather than a loosened assertion;
         * this case is about the `type`-column repair, not about authorization.
         */
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Edit Products');
        $token = $merchant->createToken('auth_token')->plainTextToken;

        $variantId = DB::table('product_variants')->insertGetId([
            // A REAL product id: product_variants.product_id carries a foreign key to products,
            // and SQLite enforces it here even though the handler never reads the column. A
            // literal 1 made both of these tests die in the FIXTURE, which is census-failure
            // mode 3 -- the handler was never reached at all.
            'product_id' => $this->productIn($store)->id,
            'variant' => 'before-value', 'sku' => 'sku-' . uniqid(),
            'price' => 10, 'stock' => 3, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/updatevariant', [
                'store_id' => $store->id,
                'productvariant_id' => $variantId,
                'name' => 'after-value',
                'type' => 'select',
            ]);

        $this->assertNotSame(500, $response->status(),
            'Sending `type` still raises a database error. Body: '
            . substr($response->getContent(), 0, 300));
        $this->assertSame(1, (int) $response->json('status'),
            'The update was refused. Body: ' . substr($response->getContent(), 0, 300));

        $this->assertSame(
            'after-value',
            DB::table('product_variants')->where('id', $variantId)->value('variant'),
            'The `name` parameter did not reach product_variants.variant, which is the only '
            . 'column it can land in.'
        );
    }

    /**
     * POSITIVE CONTROL ON THE TENANCY THE REPAIR MUST NOT HAVE TOUCHED.
     *
     * `UpdateVariant` scopes its lookup to the resolved store (P1-SEC-04). Removing the `type`
     * write must not have widened that, so the cross-tenant call is driven here too.
     */
    public function test_update_variant_still_refuses_another_tenants_variant(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $token = $merchantA->createToken('auth_token')->plainTextToken;

        $victimId = DB::table('product_variants')->insertGetId([
            'product_id' => $this->productIn($storeB)->id,
            'variant' => 'victim-value', 'sku' => 'sku-' . uniqid(),
            'price' => 10, 'stock' => 3, 'store_id' => $storeB->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/admin/updatevariant', [
                'store_id' => $storeA->id,
                'productvariant_id' => $victimId,
                'name' => 'hijacked',
                'type' => 'select',
            ]);

        $this->assertSame(
            'victim-value',
            DB::table('product_variants')->where('id', $victimId)->value('variant'),
            "Another store's variant was renamed."
        );
    }

    // ------------------------------------------------------------------ (b) the dead route

    /**
     * EVERY HOST-APP ROUTE THAT POINTS AT A METHOD WHICH DOES NOT EXIST, AGAINST A DECLARED SET.
     *
     * (b) as the task row states it is one route. Keyed on `$route->getActionName()` instead of
     * on the uri spelling, it is SIXTY-NINE, and that is the measurement this item really made:
     * two of them were named in the security queue (`sku_combination_edit` as S38-3b,
     * `addToCartVariant` as an unrowed S36 observation) and are removed by this task; the other
     * sixty-seven are below -- fifty-eight `Route::resource()` actions their controllers never
     * implemented and nine ordinary named routes, enumerated on the constant. Every one answers
     * BadMethodCallException, a 500, on call. The first version of this docblock said all
     * sixty-seven came from one generator; they do not, and the split was measured on a second
     * look rather than assumed. That is census-failure mode 2 twice over: an inventory keyed on
     * `Route::post` lines misses `Route::resource`, and a generator named from a glance misses
     * the nine that do not come from it.
     *
     * DECLARED RATHER THAN ASSERTED-EMPTY, and the direction matters. Narrowing twenty-odd
     * resource declarations to `->only([...])` is a mechanical change across routes this task was
     * not asked to touch, and it is recorded as a finding instead
     * (docs/development/phase-1/PHASE-01-FINAL-DECISION-REPORT.md, section D). So this asserts
     * the SET in both directions, the way .github/known-test-outcomes.json does: a NEW dead route
     * fails it, and a route that quietly gets its method back fails it too and has to be removed
     * from the list. What it cannot do is pass while the two routes this task removed are back,
     * which is the assertion (b) actually needs.
     *
     * SCOPED TO `App\Http\Controllers`. `packages/workdo/` is vendored commercial code this
     * project must not edit (CLAUDE.md), so a module route with a missing method is not
     * actionable here. The reachability assertion is what stops the scoping making this vacuous.
     */
    public function test_host_app_routes_pointing_at_missing_methods_match_the_declared_set(): void
    {
        $checked = 0;
        $missing = [];

        foreach (Route::getRoutes() as $route) {
            $action = $route->getActionName();

            if (! str_contains($action, '@')) {
                continue;
            }

            [$class, $method] = explode('@', $action, 2);

            if (! str_starts_with($class, 'App\\Http\\Controllers')) {
                continue;
            }

            if (! class_exists($class)) {
                $missing[] = 'class missing: ' . $action;
                continue;
            }

            $checked++;

            if (! method_exists($class, $method)) {
                $missing[] = $action;
            }
        }

        $this->assertGreaterThan(
            200,
            $checked,
            'REACHABILITY: fewer host-app controller routes were examined than this application '
            . 'has, so the comparison below ran against almost nothing. A namespace rename or a '
            . 'route file that stopped loading would do this.'
        );

        $missing = array_values(array_unique($missing));
        sort($missing);

        $declared = self::DEAD_ROUTE_ACTIONS;
        sort($declared);

        $appeared = array_values(array_diff($missing, $declared));
        $fixed = array_values(array_diff($declared, $missing));

        $this->assertSame(
            [],
            $appeared,
            "NEW live routes that raise BadMethodCallException -- a 500 -- on every request:\n  "
            . implode("\n  ", $appeared)
        );

        $this->assertSame(
            [],
            $fixed,
            "These are no longer dead, so remove them from DEAD_ROUTE_ACTIONS -- the list is "
            . "asserted in both directions so it cannot quietly become fiction:\n  "
            . implode("\n  ", $fixed)
        );
    }

    /**
     * The two routes this task removed, asserted by name as well as by the set above.
     *
     * Belt and braces on purpose: the set assertion would also catch their return, but only as
     * "something new appeared". These say which.
     */
    public function test_the_two_removed_dead_routes_are_absent_by_name(): void
    {
        foreach (['products.sku_combination_edit', 'addToCartVariant'] as $name) {
            $this->assertNull(
                Route::getRoutes()->getByName($name),
                'The dead route ' . $name . ' is back.'
            );
        }

        foreach (['ProductController::sku_combination_edit', 'ProductController::addToCartVariant'] as $pair) {
            [, $method] = explode('::', $pair);
            $this->assertFalse(
                method_exists(\App\Http\Controllers\ProductController::class, $method),
                $method . ' now exists, so the route should have been repointed at it rather '
                . 'than removed. Re-read P1-INV-10 (b).'
            );
        }
    }

    // ------------------------------------------------------------------ (d) the dead code

    /**
     * THE TWO `_backup` METHODS WHOSE PER-ROW LOOKUPS WERE NEVER SCOPED ARE GONE.
     *
     * Comment lines are stripped before the search. The file docblock discusses both names at
     * length, and a prose mention satisfying a source ratchet is a failure this project has
     * already had.
     */
    public function test_the_two_unscoped_backup_methods_are_absent_from_the_controller(): void
    {
        $live = $this->liveSource(self::API_CONTROLLER);

        foreach (['ViewOrder_backup', 'DeleteProduct_backup'] as $name) {
            $this->assertStringNotContainsString(
                'function ' . $name . '(',
                $live,
                $name . ' is back. It resolves the store through resolveOwnedStore() but keys '
                . 'its per-row lookups on bare request input, so wiring it up reintroduces the '
                . 'cross-tenant hole the file docblock claims it cannot.'
            );
        }
    }

    /**
     * AND THE TWO THAT ARE GENUINELY TENANT-SCOPED ARE STILL THERE, STILL SCOPED.
     *
     * The other direction of (d). Deleting all four would have been the easier diff; the
     * acceptance criterion is "removed, or kept with a recorded reason", and the recorded reason
     * for these two is that their row predicates really do carry `$store->id`. If that stops
     * being true they belong with the other two.
     */
    public function test_the_two_tenant_scoped_backup_methods_are_still_present_and_scoped(): void
    {
        $live = $this->liveSource(self::API_CONTROLLER);

        foreach (['OrderList_backup', 'DeleteStore_backup'] as $name) {
            $this->assertStringContainsString('function ' . $name . '(', $live, $name . ' vanished');
        }

        $body = $this->methodBody($live, 'OrderList_backup');
        $this->assertStringContainsString("Order::where('store_id', \$store->id)", $body,
            'OrderList_backup no longer scopes its order query to the resolved store, so it is '
            . 'now in the same state as the two methods this task deleted.');

        /*
         * Every `$request->` in DeleteStore_backup must be the ONE that resolves the store. The
         * first version of this assertion forbade `$request->` outright and failed on
         * `resolveOwnedStore($request->store_id)` -- which is the correct, load-bearing read.
         */
        $body = $this->methodBody($live, 'DeleteStore_backup');
        $reads = preg_match_all('/\$request->(\w+)/', $body, $m) ? $m[1] : [];

        $this->assertSame(
            ['store_id'],
            array_values(array_unique($reads)),
            'DeleteStore_backup reads request input beyond the store id it resolves ownership '
            . 'from. Every delete in it must key on $store->id, which resolveOwnedStore() has '
            . 'already proved the caller owns; anything else puts it in the state of the two '
            . 'methods this task deleted. Read: ' . implode(', ', array_unique($reads))
        );
    }

    // ------------------------------------------------------------------ helpers

    /**
     * A product in the given store, because `product_variants.product_id` is a foreign key.
     *
     * A local copy rather than a move into SecurityTestCase: MerchantProductOwnershipTest's
     * version carries the full P1-INV-01 validator payload and a `$stock` argument this file has
     * no use for, and pulling either into the shared base class would change the fixtures of
     * every test that already extends it.
     */
    private function productIn($store): \App\Models\Product
    {
        return \App\Models\Product::forceCreate([
            'name' => 'p-' . uniqid(), 'slug' => 'p-' . uniqid(),
            'product_stock' => 5, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** A file's source with comment-only lines removed, so prose cannot satisfy a ratchet. */
    private function liveSource(string $relativePath): string
    {
        $out = [];

        foreach (file(base_path($relativePath)) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || str_starts_with($trimmed, '//')
                || str_starts_with($trimmed, '*') || str_starts_with($trimmed, '/*')) {
                continue;
            }

            $out[] = $line;
        }

        return implode('', $out);
    }

    /** Everything from a method's signature to the next method's, within already-stripped source. */
    private function methodBody(string $source, string $method): string
    {
        $start = strpos($source, 'function ' . $method . '(');
        $this->assertNotFalse($start, "method {$method} not found in the stripped source");

        $next = strpos($source, 'function ', $start + strlen('function ' . $method . '('));

        return $next === false
            ? substr($source, $start)
            : substr($source, $start, $next - $start);
    }
}
