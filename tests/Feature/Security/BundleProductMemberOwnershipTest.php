<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER PLAN `PRD-005` (GAP-184), SECOND HALF — A BUNDLE CAN BE COMPOSED OF ANOTHER TENANT'S
 * PRODUCTS, AND THE STOREFRONT THEN RENDERS THEM.
 * ==============================================================================================
 *
 * Plan text: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:258`, 04-A —
 * «باقات ثابتة ومرنة ومنتجات مكملة.»
 *
 * `BundleProductOwnershipTest` closed the ROW question (whose bundle may I edit). This is the
 * REFERENCE question, which a guard keyed on a route parameter cannot ask: `store()` and `update()`
 * take the member ids from the request and write them with no check at all —
 *
 *     $bundle_product->product_id      = $request->product_id;
 *     $bundle_product->bundle_products = json_encode($request->bundle_products);
 *
 * — while `store_id` is set to `getCurrentStore()`, so the row lands in the CALLER's own tenant and
 * no `store_id` predicate anywhere notices. Exactly the shape of P1-INV-22's `lottery_products`.
 *
 * ---------------------------------------------------------------------------------------------
 * WHY IT MATTERS, AND IT IS NOT COSMETIC: THE READ IS UNSCOPED TOO.
 *
 * `Workdo\BundleProduct\app\Providers\ViewComposer::boot()` composes the storefront product-detail
 * page with
 *
 *     $bundle_products_data = Product::whereIn('id', $bundle_product_ids)->get();
 *
 * — **no store predicate**, and `Product` is absent from `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and it adds no predicate for any principal. The result
 * is pushed into the `bundleproductslider` stack and each row rendered through `<x-product-card>`,
 * which prints the product's name, image and price. So a forged member id puts another tenant's
 * catalogue entries on a PUBLIC storefront page.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT THIS CLOSES AND WHAT IT DOES NOT.
 *
 * The guard is on the WRITE side, so no new row can name a product the caller does not own. The
 * unscoped READ in that vendored `ViewComposer` is NOT closed here and must not be read as closed:
 * rows written before this guard existed would still render. The host-side vehicle for it is the
 * vendor view override the skill documents (`resources/views/vendor/bundle-product/...` resolves
 * before the module's own view), and it is named in the handover rather than left implicit.
 */
class BundleProductMemberOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'BundleProduct',
            'name' => 'BundleProduct',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'bundle-product',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['BundleProduct']);

        foreach (glob(base_path('packages/workdo/BundleProduct/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('bundle_products'), 'premise: the module migrations ran');
    }

    private function entitle(User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id, 'module' => 'BundleProduct',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertContains('BundleProduct', ActivatedModule($owner->id),
            'the entitlement fixture did not take, so PlanModuleCheck refuses before the guard is '
            . 'asked and every assertion below would hold either way');
    }

    private function product(int $storeId, int $createdBy, string $name): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'store_id' => $storeId, 'track_stock' => 0, 'created_by' => $createdBy,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{merchant:User,store:\App\Models\Store,mineA:int,mineB:int,foreign:int}
     */
    private function fixture(): array
    {
        /* Burn store id 1: `getCurrentStore()` answers the literal 1 for an operator with an empty
           `current_store`, so a store under test that IS store 1 collapses distinct numbers. */
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$merchant, $store] = $this->makeTenant();
        foreach (['Manage Bundle Product', 'Create Bundle Product', 'Edit Bundle Product'] as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }
        $merchant = User::find($merchant->id);
        $this->entitle($merchant);

        $outsider = $this->makeMerchant();
        $outsiderStore = $this->makeStore($outsider);

        $this->assertSame((int) $store->id, (int) getCurrentStore($merchant->id),
            'premise: the merchant is not acting in their own store, so the predicate under test '
            . 'is being asked about the wrong store');
        $this->assertNotSame((int) $store->id, (int) $outsiderStore->id,
            'fixture: both tenants resolved to one store');

        return [
            'merchant' => $merchant,
            'store' => $store,
            'mineA' => $this->product((int) $store->id, (int) $merchant->id, 'Mine A'),
            'mineB' => $this->product((int) $store->id, (int) $merchant->id, 'Mine B'),
            'foreign' => $this->product((int) $outsiderStore->id, (int) $outsider->id, 'Theirs'),
        ];
    }

    private function bundles(): array
    {
        return DB::table('bundle_products')->get()->all();
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /** `Product` IS NOT SCOPED, so the global scope cannot be what refuses. */
    public function test_product_is_not_scoped_so_the_scope_cannot_be_the_check(): void
    {
        $this->assertNotContains(\App\Models\Product::class, config('tenancy.scoped_models'),
            'Product has been added to scoped_models; re-measure before relying on this guard');
        $this->assertStringNotContainsString('store_id', \App\Models\Product::query()->toSql(),
            'Product::query() now carries a store_id predicate, so the premise has changed');
    }

    /** The vendored writers really do take the ids straight from the request. */
    public function test_the_vendored_writers_take_the_member_ids_from_the_request(): void
    {
        $source = $this->sourceWithoutComments(
            'packages/workdo/BundleProduct/src/app/Http/Controllers/BundleProductController.php'
        );

        $this->assertSame(2, substr_count($source, '->product_id = $request->product_id;'),
            'the number of unguarded `product_id` writes has changed from 2; re-read the '
            . 'controller before trusting this guard\'s target list');
        $this->assertSame(2, substr_count($source, 'json_encode($request->bundle_products)'),
            'the number of unguarded member-list writes has changed from 2');
    }

    /** And the storefront read really is unscoped — the reason the write side matters. */
    public function test_the_storefront_read_of_the_members_is_unscoped(): void
    {
        $source = $this->sourceWithoutComments(
            'packages/workdo/BundleProduct/src/app/Providers/ViewComposer.php'
        );

        $this->assertStringContainsString("Product::whereIn('id', \$bundle_product_ids)->get()", $source,
            'the vendored view composer no longer reads the bundle members without a store '
            . 'predicate. If upstream has scoped it, the "what this does not close" note on this '
            . 'file and on the guard can go — but re-read it first.');
    }

    /**
     * EVERY TARGET IS A REAL REGISTERED ROUTE THAT CARRIES THIS GUARD.
     *
     * A target keyed on a uri the module has renamed leaves the guard silently inert on a live
     * route, which is the vacuity `ModuleResourceOwnershipGuard` keeps its own version of this test
     * for. Both directions: six keys, and `store()`/`update()` reached by nothing the keys miss.
     */
    public function test_every_target_is_a_real_registered_route(): void
    {
        $this->enableModule();

        $targets = array_keys(
            (new \ReflectionClass(\App\Http\Middleware\BundleProductMemberOwnershipGuard::class))
                ->getConstant('TARGETS')
        );

        $this->assertCount(6, $targets,
            'the target count changed. Two uri spellings x (POST store, PUT update, PATCH update); '
            . 're-read the module\'s routes/web.php before changing this number.');

        $registered = [];
        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $registered[$method . ' ' . $route->uri()] = app('router')->gatherRouteMiddleware($route);
            }
        }

        foreach ($targets as $key) {
            $this->assertArrayHasKey($key, $registered,
                "The guard targets `{$key}`, which is registered by nothing — so it is inert on "
                . 'whatever route replaced it.');
            $this->assertContains(
                \App\Http\Middleware\BundleProductMemberOwnershipGuard::class,
                $registered[$key],
                "`{$key}` does not carry the guard."
            );
        }

        /* THE OTHER DIRECTION, keyed on the controller ACTION rather than the uri, so a prefix, a
           rename and a second uri on the same handler are all caught -- the fourth distinct way a
           census in this repository has missed what it claimed. */
        $unguarded = [];
        $seen = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            foreach (['BundleProductController@store', 'BundleProductController@update'] as $writer) {
                if (! str_ends_with($action, $writer)) {
                    continue;
                }

                $seen[$writer] = true;

                foreach ($route->methods() as $method) {
                    if (! in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
                        continue;
                    }

                    $key = $method . ' ' . $route->uri();

                    if (! in_array($key, $targets, true)) {
                        $unguarded[] = $key . '  -> ' . $action;
                    }
                }
            }
        }

        $this->assertSame([], $unguarded,
            "A route reaches a bundle member writer that this guard does not target:\n  "
            . implode("\n  ", $unguarded));

        $this->assertSame(['BundleProductController@store', 'BundleProductController@update'],
            array_keys($seen),
            'the action scan found neither writer, so it would pass without checking anything. '
            . 'Found: [' . implode(', ', array_keys($seen)) . ']');
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** CREATE — a member id belonging to another store is refused, and nothing is written. */
    public function test_a_bundle_cannot_be_created_naming_another_stores_product(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $response = $this->actingAs($f['merchant'])->post('/bundleproduct', [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['mineB'], (string) $f['foreign']],
        ]);

        $this->assertNotSame(404, $response->status(), 'the route does not exist, so the row '
            . 'assertion would be satisfied by a 404');

        $this->assertSame([], $this->bundles(),
            'A BUNDLE WAS CREATED NAMING ANOTHER STORE\'S PRODUCT. `store()` writes '
            . '`json_encode($request->bundle_products)` with no ownership check, and the row lands '
            . 'in the caller\'s own tenant so no store_id predicate notices. The vendored view '
            . 'composer then reads the members with `Product::whereIn(...)` and no store '
            . 'predicate, so they render on a public storefront page — PRD-005, GAP-184.');
    }

    /** CREATE — the PARENT product id is the other half of the same write. */
    public function test_a_bundle_cannot_be_created_whose_parent_is_another_stores_product(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $this->actingAs($f['merchant'])->post('/bundleproduct', [
            'product_id' => $f['foreign'],
            'bundle_products' => [(string) $f['mineA']],
        ]);

        $this->assertSame([], $this->bundles(),
            'a bundle was created whose PARENT is another store\'s product');
    }

    /** UPDATE — the same two writes on the other handler. */
    public function test_a_bundle_cannot_be_updated_to_name_another_stores_product(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $id = (int) DB::table('bundle_products')->insertGetId([
            'product_id' => $f['mineA'],
            'bundle_products' => json_encode([(string) $f['mineB']]),
            'created_by' => $f['merchant']->id,
            'store_id' => $f['store']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $before = DB::table('bundle_products')->where('id', $id)->first();

        $response = $this->actingAs($f['merchant'])->put('/bundleproduct/' . $id, [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['foreign']],
        ]);

        $this->assertNotSame(404, $response->status());

        $after = DB::table('bundle_products')->where('id', $id)->first();
        $this->assertSame((string) $before->bundle_products, (string) $after->bundle_products,
            'an existing bundle was REWRITTEN to name another store\'s product');
    }

    /** PATCH is a separate method+URI key, so it needs its own case. */
    public function test_the_patch_verb_is_guarded_too(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $id = (int) DB::table('bundle_products')->insertGetId([
            'product_id' => $f['mineA'],
            'bundle_products' => json_encode([(string) $f['mineB']]),
            'created_by' => $f['merchant']->id,
            'store_id' => $f['store']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($f['merchant'])->patch('/bundleproduct/' . $id, [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['foreign']],
        ]);

        $this->assertStringNotContainsString((string) $f['foreign'],
            (string) DB::table('bundle_products')->where('id', $id)->value('bundle_products'),
            'PATCH is unguarded, so the defect is reachable by changing the verb');
    }

    /** THE SECOND URI SPELLING. The module declares the resource twice. */
    public function test_the_hyphenated_spelling_is_guarded_too(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $response = $this->actingAs($f['merchant'])->post('/bundle-product', [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['foreign']],
        ]);

        $this->assertNotSame(404, $response->status(),
            'premise: POST /bundle-product is not routed, so this case proves nothing about the '
            . 'second spelling');
        $this->assertSame([], $this->bundles(),
            'the hyphenated spelling of the resource is unguarded, so the defect is reachable at '
            . 'the other URL');
    }

    /** An id that exists nowhere is refused: it is not one of the caller's products either. */
    public function test_a_member_id_that_exists_nowhere_is_refused(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $this->actingAs($f['merchant'])->post('/bundleproduct', [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['mineB'], '999999'],
        ]);

        $this->assertSame([], $this->bundles(),
            'a bundle was created naming a product id that exists nowhere. The question the guard '
            . 'asks is "is every id one of MY products", and a missing row is not.');
    }

    // ══════════════════════════════════════════════════════════════════ THE CONTROLS: NO LOCKOUT

    /** THE MERCHANT'S OWN BUNDLE IS STILL CREATED — the lockout this family has produced before. */
    public function test_a_bundle_of_the_merchants_own_products_is_still_created(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $response = $this->actingAs($f['merchant'])->post('/bundleproduct', [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['mineB']],
        ]);

        $this->assertNotSame(403, $response->status(),
            'the guard refused a merchant a bundle of their OWN products — a lockout, not a '
            . 'tightening');

        $rows = $this->bundles();
        $this->assertCount(1, $rows,
            'the merchant\'s own bundle was not created, so this guard is refusing everything '
            . 'rather than refusing a foreign reference');
        $this->assertSame((int) $f['mineA'], (int) $rows[0]->product_id);
        $this->assertSame(json_encode([(string) $f['mineB']]), (string) $rows[0]->bundle_products);
    }

    /** And their own bundle is still UPDATABLE. */
    public function test_a_bundle_of_the_merchants_own_products_is_still_updatable(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $id = (int) DB::table('bundle_products')->insertGetId([
            'product_id' => $f['mineA'],
            'bundle_products' => json_encode([(string) $f['mineA']]),
            'created_by' => $f['merchant']->id,
            'store_id' => $f['store']->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($f['merchant'])->put('/bundleproduct/' . $id, [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['mineB']],
        ]);

        $this->assertSame(json_encode([(string) $f['mineB']]),
            (string) DB::table('bundle_products')->where('id', $id)->value('bundle_products'),
            'the merchant could not change their own bundle\'s membership to another of their own '
            . 'products');
    }

    /**
     * A SUBMIT THAT NAMES NOTHING STILL GETS THE VALIDATOR'S OWN MESSAGE.
     *
     * The guard must not answer "permission denied" for an empty form: the handler's validator says
     * *"The product id field is required"*, which tells the merchant which field is wrong.
     * `LotterySettingProductOwnershipGuard` records that exact defect — every refusal reason
     * collapsed into one message, and a merchant who forgot a field was told they lacked
     * permission.
     */
    public function test_an_empty_submit_reaches_the_handlers_own_validator(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $response = $this->actingAs($f['merchant'])->post('/bundleproduct', []);

        $this->assertSame([], $this->bundles(), 'an empty submit created a row');

        $errors = (string) json_encode(session()->all());
        $this->assertStringContainsString('required', strtolower($errors),
            'an empty submit did not reach the handler\'s validator, so the merchant is told '
            . 'something other than which field is missing. Session: ' . substr($errors, 0, 300));
    }

    /**
     * NO OPERATOR PASS-THROUGH, and the premise is that the HANDLER refuses the operator anyway.
     *
     * `store()` is `auth()->user() && auth()->user()->isAbleTo('Create Bundle Product')` with no
     * operator limb, and this codebase has no super-admin bypass — so a `super admin` is refused by
     * the handler two statements later. A pass-through here would change nothing observable today
     * while creating a latent gap the moment upstream adds that limb, which is the argument
     * `LotterySettingProductOwnershipGuard` records and its removal check R8 measured.
     */
    public function test_the_operator_is_not_waved_past_the_ownership_question(): void
    {
        $this->enableModule();
        $f = $this->fixture();

        $operator = $this->makePlatformAdmin();
        $this->satisfyUpdaterGate();

        $this->actingAs($operator)->post('/bundleproduct', [
            'product_id' => $f['mineA'],
            'bundle_products' => [(string) $f['foreign']],
        ]);

        $this->assertSame([], $this->bundles(),
            'the platform operator wrote a bundle naming another store\'s product');

        /* The premise: the handler itself refuses the operator, so this case is not evidence that
           the guard is what stopped them — and the guard must not be read as the only thing
           standing there. */
        $this->assertFalse(
            User::find($operator->id)->isAbleTo('Create Bundle Product'),
            'the operator now holds the module permission, so the handler would let them in and '
            . 'this guard\'s no-pass-through decision needs re-reading'
        );
    }
}
