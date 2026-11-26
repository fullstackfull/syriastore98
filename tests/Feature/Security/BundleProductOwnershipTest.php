<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MASTER PLAN `PRD-005` (GAP-184) — THE FIXED-BUNDLE SURFACE THE REQUIREMENT ASKS FOR EXISTS, AND
 * EVERY ROW IN IT IS READABLE, REWRITABLE AND DELETABLE BY ANY OTHER TENANT.
 * =========================================================================================================
 *
 * Plan text read: `docs/development/master-plan/MASTER-PRODUCT-PLAN-v1.0-WITH-AMENDMENTS.md:258`,
 * section 04-A (المنتجات والكتالوج) — «باقات ثابتة ومرنة ومنتجات مكملة.» / *fixed and flexible
 * bundles, and complementary products*.
 *
 * `GAP-184`'s register row says **"Fixed and flexible bundles are not located."** The FIXED half IS
 * located: `packages/workdo/BundleProduct` is a vendored module with its own `bundle_products` table
 * (`product_id` + a JSON list of member product ids), a DataTable, a menu entry, four seeded
 * permissions and two `Route::resource` declarations. That correction is the reason this file exists
 * — once the surface is located, it has to be read, and reading it found this.
 *
 * ---------------------------------------------------------------------------------------------------
 * THE DEFECT, FROM THE CONTROLLER'S OWN SOURCE.
 *
 * `Workdo\BundleProduct\app\Http\Controllers\BundleProductController` resolves the row by bare id in
 * three handlers and asks no ownership question in any of them:
 *
 *     edit($id)                BundleProduct::find($id)   — AND CHECKS NO PERMISSION AT ALL
 *     update(Request, $id)     BundleProduct::find($id)   — checks `Edit Bundle Product` only
 *     destroy($id)             BundleProduct::find($id)   — checks `Delete Bundle Product` only
 *
 * Nothing else supplies a predicate. `Workdo\BundleProduct\app\Models\BundleProduct` uses only
 * `HasFactory` and `Cachable` — **no `BelongsToStore`** — and is absent from `config/tenancy.php`'s
 * `scoped_models`, so `StoreScope::isActiveFor()` short-circuits and the model adds **no predicate
 * for any principal**. `test_the_bundle_model_is_not_scoped_so_the_scope_cannot_be_the_check`
 * measures both halves rather than trusting this paragraph.
 *
 * The module's own `index()` and `create()` DO scope (`where('store_id', getCurrentStore())`), so the
 * module knows these rows are tenant-scoped; the three row handlers simply do not ask.
 *
 * THE PERMISSIONS ARE NO BARRIER TO A MERCHANT. `BundleProduct`'s own
 * `database/seeders/PermissionTableSeeder.php` creates all four names and grants them to the global
 * `admin` role, which every self-registered merchant holds. They bite only on staff with a narrower
 * role — and `edit()` does not even check one.
 *
 * ---------------------------------------------------------------------------------------------------
 * THE VEHICLE, AND WHY IT IS THIS ONE.
 *
 * `packages/workdo/` is vendored and must not be edited, so the control is host-side:
 * `App\Http\Middleware\ModuleResourceOwnershipGuard`, which is this exact shape already — a
 * `Route::resource` in the module's own `['auth','web','PlanModuleCheck:…']` group, acting on a row
 * by a bare METHOD-ARGUMENT id, with a permission the module seeds onto the global `admin` role. It
 * closed AuctionProduct (P1-INV-17), DigitalProduct (P1-INV-23) and ProductReview the same way, and
 * its `test_every_guard_target_is_a_real_registered_route` asserts each key against the live route
 * table, so a rename upstream cannot leave the entries silently inert.
 *
 * TEN KEYS, NOT FIVE, BECAUSE THE MODULE DECLARES THE RESOURCE TWICE:
 *
 *     Route::resource('bundleproduct',  …)->names('bundleproduct');   parameter {bundleproduct}
 *     Route::resource('bundle-product', …);                           parameter {bundle_product}
 *
 * Both are live — measured with `tests/Support/Security/bin/route-census.php`, every module loaded
 * through the production loader: 16 bundle routes resolve, 10 of which carry a row-bearing
 * parameter. Guarding one spelling and not the other would leave the whole defect reachable at the
 * other URL, which is the census-failure mode CLAUDE.md §7 records ("keyed on the uri prefix").
 *
 * `show()` returns `view('bundle-product::show')` with no model read, so it leaks nothing today. It
 * is guarded anyway, for the reason the manifest gives for the three dead Appointment methods: a
 * rule must not depend on a handler staying a stub.
 *
 * ---------------------------------------------------------------------------------------------------
 * WHAT THIS FILE DOES NOT CLOSE, STATED SO IT IS NOT READ AS COVERED.
 *
 *   * **`store()` and `update()` take the MEMBER product ids from the request with no ownership
 *     check**, so a merchant can compose a bundle out of another tenant's products
 *     (`$bundle_product->product_id = $request->product_id` and
 *     `json_encode($request->bundle_products)`). The row lands in the attacker's own tenant, so no
 *     `store_id` predicate notices — the same shape as P1-INV-22's `lottery_products`, which needed
 *     a guard of its own (`LotterySettingProductOwnershipGuard`). A row guard keyed on a route
 *     parameter cannot ask that question; it is reported as a separate finding, not fixed here.
 *   * **Flexible bundles and bundle PRICING are not engineering's to invent.** `bundle_products`
 *     carries no per-member quantity and no price of any kind, and `PRICE-003` ("أسعار للمتغيرات
 *     والتخصيصات والباقات" / prices for variants, customisations and bundles) is a separate decision
 *     id with no recovered detail. What "flexible" means, and how a bundle is priced, are owner
 *     decisions.
 */
class BundleProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;
    use \Tests\Support\StripsSourceComments;

    /**
     * Load the module the way `DigitalProductOwnershipTest::enableModule()` does.
     *
     * An `add_on_managers` row plus `loadAddons()` plus the module's own migrations. Without all
     * three the routes do not exist and every row assertion below is satisfied by a 404 — trap 2.
     */
    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'BundleProduct',
            'name' => 'Bundle Product',
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
        $this->assertNotNull(\Illuminate\Support\Facades\Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/bundleproduct/1', 'DELETE')
        ), 'premise: the module routes are registered');
    }

    /** `PlanModuleCheck` refuses a non-operator whose plan lacks the module, before any handler. */
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

    /** A bundle row of `$storeId`, naming `$member` as its single member. */
    private function bundle(int $storeId, int $createdBy, int $parent, int $member): int
    {
        return (int) DB::table('bundle_products')->insertGetId([
            'product_id' => $parent,
            'bundle_products' => json_encode([(string) $member]),
            'created_by' => $createdBy,
            'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Read raw: `BundleProduct` is `Cachable`, so an Eloquent read can serve a pre-write row. */
    private function stored(int $id): ?object
    {
        return DB::table('bundle_products')->where('id', $id)->first();
    }

    /**
     * The victim's bundle, and an attacker from another tenant holding every permission and the
     * entitlement.
     *
     * @return array{attacker:User,victimBundle:int,victimStore:int,attackerParent:int}
     */
    private function twoTenants(): array
    {
        /* Burn store id 1, so "the victim's store", "the attacker's store" and the operator's
           `getCurrentStore()` fallback of 1 are three different numbers. */
        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();

        foreach (['Manage Bundle Product', 'Create Bundle Product', 'Edit Bundle Product', 'Delete Bundle Product'] as $permission) {
            $this->grantPermission(User::find($attacker->id), $permission);
        }
        $this->entitle(User::find($attacker->id));

        $victimParent = $this->product($victimStore->id, $victim->id, 'Victim parent');
        $victimMember = $this->product($victimStore->id, $victim->id, 'Victim member');

        $this->assertNotSame((int) $victimStore->id, (int) $attackerStore->id,
            'fixture: both tenants resolved to one store, so nothing cross-tenant is being driven');

        return [
            'attacker' => User::find($attacker->id),
            'victimStore' => (int) $victimStore->id,
            'victimBundle' => $this->bundle((int) $victimStore->id, (int) $victim->id, $victimParent, $victimMember),
            'attackerParent' => $this->product((int) $attackerStore->id, (int) $attacker->id, 'Attacker parent'),
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE PREMISES

    /**
     * `BundleProduct` IS NOT SCOPED, measured against the booted container, both halves.
     *
     * `getGlobalScopes()` is not the same question as "does it filter" — CLAUDE.md records asking
     * the first one about `Product` and `Category` as how two real defects survived. Here the model
     * does not even carry the trait, so both answers are "no".
     */
    public function test_the_bundle_model_is_not_scoped_so_the_scope_cannot_be_the_check(): void
    {
        $this->enableModule();

        $class = \Workdo\BundleProduct\app\Models\BundleProduct::class;

        $this->assertNotContains($class, config('tenancy.scoped_models'),
            'BundleProduct has been added to scoped_models. If that is deliberate, re-measure '
            . 'before removing this guard — null_tenant => "open" still means no predicate when no '
            . 'tenant resolves.');

        $this->assertStringNotContainsString('store_id', $class::query()->toSql(),
            'BundleProduct::query() now carries a store_id predicate, so the premise of this file '
            . 'has changed');
    }

    /** The module's own listing DOES scope, so the three row handlers are the exception. */
    public function test_the_modules_own_listing_scopes_by_store(): void
    {
        $source = $this->sourceWithoutComments(
            'packages/workdo/BundleProduct/src/app/Http/Controllers/BundleProductController.php'
        );

        $this->assertStringContainsString("BundleProduct::where('store_id', getCurrentStore())", $source,
            'the module no longer scopes its own listing, so the argument that these rows are '
            . 'tenant-scoped by the module\'s own reckoning needs re-making');
        $this->assertSame(3, substr_count($source, 'BundleProduct::find($id)'),
            'the number of bare-id row lookups in the vendored controller has changed from 3; '
            . 're-read it before trusting this file\'s target list');
    }

    // ══════════════════════════════════════════════════════════════════════════════ THE DEFECT

    /** DELETE — another tenant's bundle is destroyed by guessing an integer. */
    public function test_a_merchant_cannot_delete_another_tenants_bundle(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])->delete('/bundleproduct/' . $f['victimBundle']);

        $this->assertNotSame(404, $response->status(), 'the route does not exist, so the row '
            . 'assertion would be satisfied by a 404');
        $this->assertNotNull($this->stored($f['victimBundle']),
            'ANOTHER TENANT\'S BUNDLE WAS DELETED. `BundleProductController::destroy()` is '
            . '`BundleProduct::find($id)->delete()` behind a permission every merchant holds, and '
            . 'the model is in no scoped_models list — PRD-005 surface, GAP-184.');
        $this->assertSame(403, $response->status(), 'the guard did not refuse the request');
    }

    /** PUT — another tenant's bundle is re-pointed at the attacker's own product. */
    public function test_a_merchant_cannot_rewrite_another_tenants_bundle(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();
        $before = $this->stored($f['victimBundle']);

        $response = $this->actingAs($f['attacker'])->put('/bundleproduct/' . $f['victimBundle'], [
            'product_id' => $f['attackerParent'],
            'bundle_products' => [(string) $f['attackerParent']],
        ]);

        $this->assertNotSame(404, $response->status());
        $this->assertSame(403, $response->status(), 'the guard did not refuse a cross-tenant '
            . 'bundle update');

        $after = $this->stored($f['victimBundle']);
        $this->assertSame((int) $before->product_id, (int) $after->product_id,
            'ANOTHER TENANT\'S BUNDLE WAS RE-POINTED at the caller\'s own product');
        $this->assertSame((string) $before->bundle_products, (string) $after->bundle_products,
            'another tenant\'s bundle membership was rewritten');
    }

    /** PATCH is a separate method+URI key from PUT, so it needs its own case. */
    public function test_a_merchant_cannot_patch_another_tenants_bundle(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();
        $before = $this->stored($f['victimBundle']);

        $response = $this->actingAs($f['attacker'])->patch('/bundleproduct/' . $f['victimBundle'], [
            'product_id' => $f['attackerParent'],
            'bundle_products' => [(string) $f['attackerParent']],
        ]);

        $this->assertSame(403, $response->status(), 'the guard did not refuse a cross-tenant PATCH');
        $this->assertSame((int) $before->product_id, (int) $this->stored($f['victimBundle'])->product_id);
    }

    /**
     * GET …/edit — A CROSS-TENANT READ WITH NO PERMISSION CHECK OF ANY KIND.
     *
     * `edit()` is the only one of the three handlers that checks nothing at all, so this is the one
     * case a staff member on the narrowest possible role can still drive. The edit screen prints the
     * victim's product names.
     */
    public function test_a_merchant_cannot_read_another_tenants_bundle_through_the_edit_form(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $response = $this->actingAs($f['attacker'])->get('/bundleproduct/' . $f['victimBundle'] . '/edit');

        $this->assertNotSame(404, $response->status(), 'the edit route does not exist');
        $this->assertSame(403, $response->status(),
            'ANOTHER TENANT\'S BUNDLE EDIT FORM WAS SERVED. `edit()` checks no permission and no '
            . 'owner — PRD-005 surface, GAP-184.');
        $this->assertStringNotContainsString('Victim parent', (string) $response->getContent(),
            'the victim\'s product name reached the response body');
    }

    /**
     * THE SECOND URI SPELLING. The module declares the resource TWICE, so guarding one spelling
     * leaves the whole defect reachable at the other URL.
     */
    public function test_the_hyphenated_resource_spelling_is_guarded_too(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $delete = $this->actingAs($f['attacker'])->delete('/bundle-product/' . $f['victimBundle']);
        $this->assertNotSame(404, $delete->status(), 'premise: /bundle-product/{id} is not routed, '
            . 'so this case proves nothing about the second spelling');
        $this->assertSame(403, $delete->status(), 'the hyphenated spelling of the resource is '
            . 'unguarded, so the defect is reachable at the other URL');
        $this->assertNotNull($this->stored($f['victimBundle']),
            'another tenant\'s bundle was deleted through the hyphenated route');

        $edit = $this->actingAs($f['attacker'])->get('/bundle-product/' . $f['victimBundle'] . '/edit');
        $this->assertSame(403, $edit->status(),
            'the hyphenated edit route served another tenant\'s bundle');
    }

    // ══════════════════════════════════════════════════════════════════ THE CONTROLS: NO LOCKOUT

    /** A merchant can still delete their OWN bundle — the lockout this family has produced before. */
    public function test_a_merchant_can_still_delete_their_own_bundle(): void
    {
        $this->enableModule();

        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$merchant, $store] = $this->makeTenant();
        foreach (['Manage Bundle Product', 'Edit Bundle Product', 'Delete Bundle Product'] as $permission) {
            $this->grantPermission(User::find($merchant->id), $permission);
        }
        $merchant = User::find($merchant->id);
        $this->entitle($merchant);

        $parent = $this->product((int) $store->id, (int) $merchant->id, 'Own parent');
        $member = $this->product((int) $store->id, (int) $merchant->id, 'Own member');
        $own = $this->bundle((int) $store->id, (int) $merchant->id, $parent, $member);

        $this->assertSame((int) $store->id, (int) getCurrentStore($merchant->id),
            'premise: the merchant is not acting in the store their bundle belongs to, so a '
            . 'refusal here would not mean what this case reads it as');

        $response = $this->actingAs($merchant)->delete('/bundleproduct/' . $own);

        $this->assertNotSame(403, $response->status(),
            'the guard refused a merchant their OWN bundle — a lockout, not a tightening');
        $this->assertNull($this->stored($own),
            'the merchant\'s own bundle was not deleted, so the guard is blocking the handler');
    }

    /** And can still open their own edit form, with the victim unreachable in the same run. */
    public function test_a_merchant_can_still_open_their_own_bundle_edit_form(): void
    {
        $this->enableModule();

        $decoy = $this->makeMerchant();
        $this->makeStore($decoy);

        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission(User::find($merchant->id), 'Edit Bundle Product');
        $merchant = User::find($merchant->id);
        $this->entitle($merchant);

        $parent = $this->product((int) $store->id, (int) $merchant->id, 'Own parent');
        $member = $this->product((int) $store->id, (int) $merchant->id, 'Own member');
        $own = $this->bundle((int) $store->id, (int) $merchant->id, $parent, $member);

        $response = $this->actingAs($merchant)->get('/bundleproduct/' . $own . '/edit');

        $this->assertNotSame(403, $response->status(),
            'the guard refused a merchant their own bundle edit form');
    }

    /** The platform operator is still admitted, as in every sibling guard. */
    public function test_the_platform_operator_can_still_reach_a_bundle(): void
    {
        $this->enableModule();
        $f = $this->twoTenants();

        $operator = $this->makePlatformAdmin();
        $this->satisfyUpdaterGate();

        $response = $this->actingAs($operator)->get('/bundleproduct/' . $f['victimBundle'] . '/edit');

        $this->assertNotSame(403, $response->status(),
            'the operator was refused. Laratrust has no super-admin bypass, so the operator limb '
            . 'has to come FIRST in the guard — reversing it is a lockout, not a tightening.');
    }
}
