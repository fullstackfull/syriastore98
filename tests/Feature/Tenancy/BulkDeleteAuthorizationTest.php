<?php

namespace Tests\Feature\Tenancy;

use App\Http\Middleware\BulkDeleteTenantGuard;
use App\Models\AddOnManager;
use App\Models\Store;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Security\SecurityTestCase;

/**
 * NEW-BULKDELETE-01 -- the first HTTP test of /bulk-delete that has ever existed in this project.
 *
 * Everything written about this endpoint until now was read out of the source. These tests POST
 * to it, with real sessions, through the real middleware stack, and read the damage back out
 * through raw `DB::table()` so that a global scope cannot hide a delete it failed to prevent.
 *
 * TWO RULES THESE TESTS FOLLOW DELIBERATELY, both earned here:
 *
 *  1. A STATUS CODE IS NEVER THE ASSERTION FOR A CROSS-TENANT DELETE. The endpoint answers
 *     `{"status":true}` + 200 for "deleted 2 of the 4 ids you sent", so asserting 200 or even
 *     asserting the same status for two actors proves nothing at all. Every tenancy assertion
 *     below is about WHICH ROWS STILL EXIST, read with raw SQL. The status codes are asserted
 *     separately, as their own property (403 vs 401), because repo B's network.dart turns a 401
 *     into a forced logout.
 *
 *  2. THE ADDON HAS TO BE TURNED ON BY HAND. app/Providers/PackageServiceProvider.php:20-22
 *     registers addon packages from `$this->app->booted()` by reading `add_on_managers`, and
 *     RefreshDatabase has not migrated anything at that point -- so under test the BulkDelete
 *     routes DO NOT EXIST unless the row is inserted and the loader re-run. `enableBulkDeleteAddon()`
 *     does exactly what production does, via the provider's own loader, rather than faking a
 *     route. test_the_bulk_delete_endpoint_exists... proves the endpoint is genuinely there
 *     afterwards, so none of the tests below can pass by 404.
 */
class BulkDeleteAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * Exactly what production does: an `add_on_managers` row, then
     * PackageServiceProvider::loadAddons(), which registers the `Workdo\BulkDelete\` psr-4 prefix
     * (it is NOT in composer.json) and the package's service providers, whose RouteServiceProvider
     * then declares POST /bulk-delete.
     */
    private function enableBulkDeleteAddon(): void
    {
        AddOnManager::create([
            'module' => 'BulkDelete',
            'name' => 'Bulk Delete',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'bulk-delete',
        ]);

        // App\Classes\Module::find() memoises through Cache::rememberForever and AddOnManager
        // carries the model-caching trait, so a warmed negative would make the module look absent.
        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['BulkDelete']);
    }

    /**
     * PlanModuleCheck:BulkDelete (app/Http/Middleware/PlanModuleCheck.php:34-67) refuses any
     * non-super-admin whose activated modules do not include BulkDelete. Every merchant in this
     * file gets the row, so a 403 in these tests is always THE GUARD's and never the plan gate's.
     *
     * (ActivatedModule() memoises into a `static $active_module` keyed on nothing
     * -- app/Helper/helper.php:1371 -- so the first principal's list is reused for the rest of the
     * process. Giving every merchant the same activation makes that irrelevant here rather than
     * load-bearing.)
     */
    private function activateModuleFor(User $user): void
    {
        userActiveModule::create(['user_id' => $user->id, 'module' => 'BulkDelete']);
    }

    /**
     * @return array{0: User, 1: Store}
     */
    private function merchantWithAddon(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->activateModuleFor($merchant);

        return [$merchant, $store];
    }

    private function insertProduct(Store $store, User $owner, string $name): int
    {
        return (int) DB::table('products')->insertGetId([
            'name' => $name,
            'slug' => $name . '-' . uniqid(),
            'track_stock' => 0,
            'created_by' => $owner->id,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertCategory(Store $store, string $name): int
    {
        return (int) DB::table('categories')->insertGetId([
            'name' => $name,
            'slug' => $name . '-' . uniqid(),
            'parent_id' => 0,
            'status' => 1,
            'store_id' => $store->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int, int> $ids */
    private function survivingIds(string $table, array $ids): array
    {
        $alive = DB::table($table)->whereIn('id', $ids)->pluck('id')->map(fn ($v) => (int) $v)->all();
        sort($alive);

        return $alive;
    }

    // =========================================================================================
    //  A. THE ENDPOINT IS REAL AND THE GUARD IS ON IT
    // =========================================================================================

    /**
     * THE REACHABILITY PROOF for choosing a `web`-group middleware over editing packages/.
     *
     * If this fails, every other test in this file is meaningless, and the fix has to move.
     */
    public function test_the_bulk_delete_endpoint_exists_and_this_guard_is_in_its_resolved_middleware_stack(): void
    {
        $this->enableBulkDeleteAddon();

        $route = Route::getRoutes()->getByName('bulk-delete');

        $this->assertNotNull($route, 'POST /bulk-delete is not registered; the addon loader changed.');
        $this->assertSame('bulk-delete', $route->uri());
        $this->assertContains('POST', $route->methods());
        $this->assertSame(
            'Workdo\BulkDelete\app\Http\Controllers\BulkDeleteController@bulkDelete',
            $route->getActionName()
        );

        $stack = app('router')->gatherRouteMiddleware($route);

        $this->assertContains(
            BulkDeleteTenantGuard::class,
            $stack,
            'The guard is not in the resolved stack of the route it exists to protect. It is '
            . "appended to the `web` group in bootstrap/app.php; the resolved stack was:\n  "
            . implode("\n  ", array_map(fn ($m) => is_string($m) ? $m : gettype($m), $stack))
        );

        // POSITION MATTERS, and it is asserted rather than assumed: the guard reads
        // auth()->user(), so it must come after Authenticate. (It deliberately defers to
        // Authenticate for a null user, but a stack where it ran first would make the
        // platform-operator bypass unreachable.)
        $positions = array_values(array_filter(
            array_keys($stack),
            fn ($i) => in_array($stack[$i], [
                \Illuminate\Auth\Middleware\Authenticate::class,
                BulkDeleteTenantGuard::class,
            ], true)
        ));

        $this->assertCount(2, $positions);
        $this->assertSame(
            \Illuminate\Auth\Middleware\Authenticate::class,
            $stack[$positions[0]],
            'Authenticate must resolve before the bulk-delete guard.'
        );
    }

    /**
     * RATCHET. The guard mirrors a map that lives as a LOCAL VARIABLE inside
     * BulkDeleteController::bulkDelete(), so no runtime mechanism can read it. This parses the
     * controller's source every run and fails by name the moment upstream adds, removes or
     * repoints a type.
     *
     * An unmirrored type is refused (403) rather than passed through, so a stale mirror degrades
     * to a denial -- but a denial is still a broken screen, which is why this is a test and not a
     * comment.
     */
    public function test_this_guard_mirrors_every_type_and_class_the_controller_maps(): void
    {
        $path = base_path('packages/workdo/BulkDelete/src/app/Http/Controllers/BulkDeleteController.php');
        $this->assertFileExists($path);

        $source = (string) file_get_contents($path);

        $imports = [];
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source, $useMatches);
        foreach ($useMatches[1] as $fqcn) {
            $imports[substr($fqcn, strrpos($fqcn, '\\') + 1)] = $fqcn;
        }

        $controllerMap = [];
        preg_match_all("/'([^']+)'\s*=>\s*([A-Za-z_\\\\]+)::class/", $source, $classConst, PREG_SET_ORDER);
        foreach ($classConst as $entry) {
            $short = ltrim($entry[2], '\\');
            $controllerMap[$entry[1]] = ltrim($imports[$short] ?? $short, '\\');
        }
        preg_match_all("/'([^']+)'\s*=>\s*'(Workdo\\\\[^']+)'/", $source, $strings, PREG_SET_ORDER);
        foreach ($strings as $entry) {
            $controllerMap[$entry[1]] = $entry[2];
        }

        // 65 keys over 61 classes, counted from the source -- the same figures
        // ResidualTenancyExposureTest measures. Asserted so a map that silently halves is noticed.
        $this->assertCount(65, $controllerMap);

        $guardMap = [];
        foreach (BulkDeleteTenantGuard::MODEL_MAP as $type => $class) {
            $guardMap[$type] = ltrim($class, '\\');
        }

        ksort($controllerMap);
        ksort($guardMap);

        $this->assertSame(
            $controllerMap,
            $guardMap,
            'BulkDeleteTenantGuard::MODEL_MAP has drifted from the controller it mirrors. '
            . 'Missing: ' . implode(', ', array_keys(array_diff_key($controllerMap, $guardMap)))
            . '; extra: ' . implode(', ', array_keys(array_diff_key($guardMap, $controllerMap)))
        );

        // Every type the guard classifies must be a type that actually exists in the map -- a
        // typo in PLATFORM_GLOBAL or PERMISSIONS would otherwise be a silently dead control.
        foreach (BulkDeleteTenantGuard::PLATFORM_GLOBAL as $type) {
            $this->assertArrayHasKey($type, $guardMap, "PLATFORM_GLOBAL names an unknown type: {$type}");
        }
        foreach (array_keys(BulkDeleteTenantGuard::PERMISSIONS) as $type) {
            $this->assertArrayHasKey($type, $guardMap, "PERMISSIONS names an unknown type: {$type}");
        }
        foreach (array_keys(BulkDeleteTenantGuard::DERIVED_OWNERSHIP) as $type) {
            $this->assertArrayHasKey($type, $guardMap, "DERIVED_OWNERSHIP names an unknown type: {$type}");
        }
        foreach (BulkDeleteTenantGuard::CUSTOMER_KEYED as $type) {
            $this->assertArrayHasKey($type, $guardMap, "CUSTOMER_KEYED names an unknown type: {$type}");
            $this->assertStringContainsString(
                "'{$type}'",
                $source,
                "The controller no longer treats {$type} as a customer_id delete."
            );
        }
    }

    /**
     * Gating on a permission name no seeder creates 403s everybody -- that exact mistake locked
     * out the finance surface in an earlier phase. Every name the guard requires is checked
     * against Utility::addNewData(), AND against the single-record controller it was copied from,
     * so the bulk path can never require more than the per-record screen already does.
     */
    /**
     * `CATEGORY_TYPE` is the key the two D13 blocks switch on, so it has to name a real type in
     * the map and that type has to be the product-category model. Raised by review: an upstream
     * rename is already caught by the whole-map comparison above and by the behavioural tests
     * posting the literal 'main-category', but neither failure says "D13 is unhooked".
     */
    public function test_the_d13_category_type_names_the_product_category_model(): void
    {
        $this->assertArrayHasKey(
            BulkDeleteTenantGuard::CATEGORY_TYPE,
            BulkDeleteTenantGuard::MODEL_MAP,
            'CATEGORY_TYPE is not a type this guard maps, so both D13 blocks are dead code'
        );

        $this->assertSame(
            \App\Models\Category::class,
            BulkDeleteTenantGuard::MODEL_MAP[BulkDeleteTenantGuard::CATEGORY_TYPE],
            'CATEGORY_TYPE no longer names the product-category model, so the D13 refusal is '
                . 'guarding the wrong table'
        );
    }

    public function test_every_permission_name_this_guard_requires_is_created_by_the_seeder(): void
    {
        $utility = file_get_contents(base_path('app/Models/Utility.php'));

        foreach (array_unique(array_values(BulkDeleteTenantGuard::PERMISSIONS)) as $permission) {
            $this->assertGreaterThan(
                0,
                substr_count($utility, '"' . $permission . '"') + substr_count($utility, "'" . $permission . "'"),
                "'{$permission}' is required by BulkDeleteTenantGuard but is NOT created by "
                . 'Utility::addNewData(). A permission that no seeder creates denies everyone.'
            );
        }

        // The other direction: each name is the one the equivalent per-record destroy() uses.
        $sources = [
            'Delete Blog' => 'BlogController',
            'Delete Blog Category' => 'BlogCategoryController',
            'Delete Product Brand' => 'ProductBrandController',
            'Delete Contact Us' => 'ContactController',
            'Delete Coupon' => 'CouponController',
            'Delete Faqs' => 'FaqController',
            'Delete Flash Sale' => 'FlashSaleController',
            'Delete Product Category' => 'CategoryController',
            'Delete Menu' => 'MenuController',
            'Delete Newsletter' => 'NewsletterController',
            'Delete Page' => 'PageController',
            'Delete Attributes' => 'ProductAttributeController',
            'Delete Products' => 'ProductController',
            'Delete Product Label' => 'ProductLabelController',
            'Delete Product Question' => 'ProductQuestionController',
            'Delete Shipping Class' => 'ShippingController',
            'Delete Shipping Zone' => 'ShippingZoneController',
            'Delete Support Ticket' => 'SupportTicketController',
            'Delete Tag' => 'TagController',
            'Delete Tax' => 'TaxController',
            'Delete Tax Method' => 'TaxMethodController',
            'Delete Testimonial' => 'TestimonialController',
            'Delete Wishlist' => 'WishlistController',
        ];

        $this->assertSame(
            array_values(array_unique(array_values(BulkDeleteTenantGuard::PERMISSIONS))),
            array_keys($sources),
            'The guard requires a permission that is not traced to a per-record destroy().'
        );

        foreach ($sources as $permission => $controller) {
            $file = base_path("app/Http/Controllers/{$controller}.php");
            $this->assertFileExists($file);
            $body = (string) file_get_contents($file);
            $destroy = substr($body, (int) strpos($body, 'function destroy'));
            $window = substr($destroy, 0, 1200);
            $needle = "isAbleTo('{$permission}')";

            $this->assertStringContainsString(
                $needle,
                $window,
                "{$controller}::destroy no longer requires '{$permission}', so the bulk path would "
                . 'be demanding a permission the single-record path does not.'
            );

            /*
             * A COMMENT MUST NOT BE ABLE TO SATISFY THE ASSERTION ABOVE. P1-INV-08.
             *
             * MEASURED, not hypothesised: a P1-INV-08 docblock in CategoryController::destroy
             * quoted `$needle` verbatim inside this window, and with the controller's REAL
             * permission check deleted this file still passed 23/23 -- the ratchet had become
             * satisfiable by prose. Exactly the "a ratchet matching a comment that quotes the
             * defect it scans for" failure CLAUDE.md names.
             *
             * Two independent conditions, because either alone can be evaded: the needle must
             * appear exactly ONCE in the window, and the line carrying it must be code rather
             * than a comment. A controller that legitimately needs the string twice should hoist
             * its check instead of relaxing this.
             */
            $occurrences = substr_count($window, $needle);
            $this->assertSame(1, $occurrences,
                "{$controller}::destroy contains the literal \"{$needle}\" {$occurrences} times "
                . 'in the first 1200 bytes. A second occurrence -- in a docblock quoting the '
                . 'permission, typically -- would keep this test green after the real check was '
                . 'deleted. Reword the comment to name the permission without its call syntax.');

            $codeLines = array_values(array_filter(
                explode("\n", $window),
                static function (string $line) use ($needle): bool {
                    if (!str_contains($line, $needle)) {
                        return false;
                    }
                    $trimmed = ltrim($line);

                    return !str_starts_with($trimmed, '*')
                        && !str_starts_with($trimmed, '//')
                        && !str_starts_with($trimmed, '/*');
                }
            ));
            $this->assertNotEmpty($codeLines,
                "{$controller}::destroy mentions '{$permission}' only inside a comment, so this "
                . 'guard is measuring prose rather than an authorization check.');
        }
    }

    // =========================================================================================
    //  B. THE CROSS-TENANT DESTRUCTIVE VULNERABILITY
    // =========================================================================================

    /**
     * THE PRIMARY TEST. A mixed id list: the caller's own rows must go, the other merchant's must
     * survive, read back with raw SQL.
     *
     * `products` is the subject because Product is NOT on tenancy.scoped_models, so nothing but
     * this guard stands between the request and the delete. Before the guard, all four ids were
     * deleted.
     */
    public function test_a_mixed_id_list_deletes_only_the_rows_the_caller_owns(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [$victim, $victimStore] = $this->merchantWithAddon();

        $this->grantPermission($attacker, 'Delete Products');

        $mine = [
            $this->insertProduct($attackerStore, $attacker, 'mine-a'),
            $this->insertProduct($attackerStore, $attacker, 'mine-b'),
        ];
        $theirs = [
            $this->insertProduct($victimStore, $victim, 'theirs-a'),
            $this->insertProduct($victimStore, $victim, 'theirs-b'),
        ];

        $response = $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'Product',
            'ids' => array_merge($mine, $theirs),
        ]);

        // The endpoint reports success either way -- see the class docblock. The DATABASE is the
        // assertion.
        $response->assertStatus(200);

        $this->assertSame([], $this->survivingIds('products', $mine),
            'The caller\'s own products were not deleted, so the legitimate half of the request broke.');

        sort($theirs);
        $this->assertSame($theirs, $this->survivingIds('products', $theirs),
            'CROSS-TENANT DELETE: another merchant\'s products were destroyed by id.');
    }

    /**
     * An all-foreign list. Nothing of the victim's may move, and the attacker learns nothing from
     * the status code either way.
     */
    public function test_a_merchant_cannot_bulk_delete_another_stores_blogs(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker] = $this->merchantWithAddon();
        [, $victimStore] = $this->merchantWithAddon();

        $this->grantPermission($attacker, 'Delete Blog');

        $theirs = [];
        foreach (['v1', 'v2', 'v3'] as $title) {
            $theirs[] = (int) DB::table('blogs')->insertGetId([
                'title' => $title,
                'slug' => $title . '-' . uniqid(),
                'store_id' => $victimStore->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'blog',
            'ids' => $theirs,
        ]);

        sort($theirs);
        $this->assertSame($theirs, $this->survivingIds('blogs', $theirs),
            'CROSS-TENANT DELETE: another store\'s blog posts were destroyed.');
    }

    /**
     * `abandoncart` and `wishlist` are the two types the controller deletes by `customer_id`
     * (BulkDeleteController.php:113-114) -- so the ids are not even row ids, and one customer id
     * can name rows in more than one store.
     *
     * Cart IS on tenancy.scoped_models, so this is belt and braces for Cart; Wishlist is NOT, and
     * the next test is the one that has no other protection.
     */
    public function test_abandoncart_rows_of_a_foreign_customer_survive(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [, $victimStore] = $this->merchantWithAddon();

        $mineCustomerId = 9001;
        $theirCustomerId = 9002;

        $mine = (int) DB::table('carts')->insertGetId([
            'customer_id' => $mineCustomerId, 'product_id' => 1, 'qty' => 1, 'price' => 10,
            'store_id' => $attackerStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirs = (int) DB::table('carts')->insertGetId([
            'customer_id' => $theirCustomerId, 'product_id' => 1, 'qty' => 1, 'price' => 10,
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'abandoncart',
            'ids' => [$mineCustomerId, $theirCustomerId],
        ]);

        $this->assertSame([], $this->survivingIds('carts', [$mine]),
            'The caller\'s own abandoned cart was not deleted.');
        $this->assertSame([$theirs], $this->survivingIds('carts', [$theirs]),
            'CROSS-TENANT DELETE: another store\'s abandoned cart was destroyed by customer id.');
    }

    public function test_a_foreign_customers_wishlist_rows_survive(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [$victim, $victimStore] = $this->merchantWithAddon();

        $this->grantPermission($attacker, 'Delete Wishlist');

        $mineCustomerId = 7001;
        $theirCustomerId = 7002;

        // wishlists.product_id is a real foreign key onto products
        // (2024_01_22_040531_create_wishlists_table.php:24), so the rows have to hang off real
        // products rather than a literal id.
        $myProduct = $this->insertProduct($attackerStore, $attacker, 'wl-mine');
        $theirProduct = $this->insertProduct($victimStore, $victim, 'wl-theirs');

        $mine = (int) DB::table('wishlists')->insertGetId([
            'customer_id' => $mineCustomerId, 'product_id' => $myProduct,
            'store_id' => $attackerStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirs = (int) DB::table('wishlists')->insertGetId([
            'customer_id' => $theirCustomerId, 'product_id' => $theirProduct,
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'wishlist',
            'ids' => [$mineCustomerId, $theirCustomerId],
        ]);

        $this->assertSame([], $this->survivingIds('wishlists', [$mine]));
        $this->assertSame([$theirs], $this->survivingIds('wishlists', [$theirs]),
            'CROSS-TENANT DELETE: another store\'s wishlist rows were destroyed by customer id. '
            . 'Wishlist is NOT on tenancy.scoped_models, so this guard is its only protection.');
    }

    /**
     * A customer id whose rows span two stores must be refused outright rather than filtered to
     * "at least one of mine", because the controller deletes by that id and would take the
     * foreign row with it.
     */
    public function test_a_customer_id_with_rows_in_two_stores_is_dropped_entirely(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [$victim, $victimStore] = $this->merchantWithAddon();

        $this->grantPermission($attacker, 'Delete Wishlist');

        $shared = 7777;

        $here = (int) DB::table('wishlists')->insertGetId([
            'customer_id' => $shared,
            'product_id' => $this->insertProduct($attackerStore, $attacker, 'shared-here'),
            'store_id' => $attackerStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $there = (int) DB::table('wishlists')->insertGetId([
            'customer_id' => $shared,
            'product_id' => $this->insertProduct($victimStore, $victim, 'shared-there'),
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'wishlist',
            'ids' => [$shared],
        ]);

        $this->assertSame([$here, $there], $this->survivingIds('wishlists', [$here, $there]),
            'A customer id naming rows in two stores must be dropped whole: the controller '
            . 'deletes by customer_id, so admitting it would destroy the foreign row too.');
    }

    /**
     * `user_coupons` has NO store_id (verified: id, user_id, coupon_id, amount, order_id,
     * date_used). It is still merchant data -- it records a redemption of a STORE coupon -- so it
     * is not refused like the platform tables; its tenant is reached through `coupons.store_id`.
     */
    public function test_user_coupon_ownership_is_derived_from_the_coupons_store(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [, $victimStore] = $this->merchantWithAddon();

        $myCoupon = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'mine', 'coupon_code' => 'MINE' . uniqid(),
            'store_id' => $attackerStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirCoupon = (int) DB::table('coupons')->insertGetId([
            'coupon_name' => 'theirs', 'coupon_code' => 'THEIRS' . uniqid(),
            'store_id' => $victimStore->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // user_coupons.user_id is a real foreign key, and on a non-MySQL build
        // 2026_10_12_000104_reconcile_user_coupons_user_id_on_non_mysql.php has repointed it at
        // `customers` and made it nullable -- so neither a users id nor a literal 1 is usable
        // here. The column is irrelevant to the control under test; null keeps the fixture
        // honest instead of inventing a customer.
        $mine = (int) DB::table('user_coupons')->insertGetId([
            'user_id' => null, 'coupon_id' => $myCoupon, 'amount' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $theirs = (int) DB::table('user_coupons')->insertGetId([
            'user_id' => null, 'coupon_id' => $theirCoupon, 'amount' => 5,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // NOT TESTED, AND WHY -- the guard's subquery also drops a row whose coupon cannot be
        // resolved, but that state is UNREACHABLE on this schema and both attempts to build it
        // were run and failed rather than assumed:
        //   - a row pointing at a DELETED coupon: the FK is ON DELETE CASCADE
        //     (2023_12_29_100550_create_user_coupons_table.php:24), so deleting the coupon takes
        //     the row with it. `PRAGMA foreign_keys = OFF` does not help: SQLite ignores that
        //     pragma inside a transaction and RefreshDatabase wraps every test in one.
        //   - a row with coupon_id NULL: refused with
        //     "NOT NULL constraint failed: user_coupons.coupon_id".
        // So that branch is defence in depth with no reachable test, and is reported as such
        // rather than claimed as covered.

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'user-coupon',
            'ids' => [$mine, $theirs],
        ]);

        $this->assertSame([], $this->survivingIds('user_coupons', [$mine]),
            'A redemption of the caller\'s own coupon should still be deletable.');
        $this->assertSame([$theirs], $this->survivingIds('user_coupons', [$theirs]),
            'CROSS-TENANT DELETE: a redemption of another store\'s coupon was destroyed.');

    }

    // =========================================================================================
    //  C. PLATFORM-GLOBAL REFERENCE AND SaaS BILLING DATA
    // =========================================================================================

    /**
     * The worst case in the brief: `countries` has no store_id at all, so NO global scope can
     * ever reach it, and the merchant-visible page already renders the bulk-delete checkbox over
     * it (resources/views/country/index.blade.php:187). CountryController::destroy:128 allows
     * only a super admin; this makes the bulk path agree.
     */
    public function test_a_merchant_cannot_bulk_delete_platform_countries(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant] = $this->merchantWithAddon();

        $ids = [];
        foreach (['Kuwait', 'Syria', 'Jordan'] as $name) {
            $ids[] = (int) DB::table('countries')->insertGetId([
                'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'country',
            'ids' => $ids,
        ]);

        // THE DATABASE FIRST, THE STATUS SECOND. Asserting the status first lets a status
        // assertion mask the thing that actually matters -- when this control was removed as a
        // teeth proof, the status assertion failed and the row assertion was never reached, so
        // the test could not say whether the rows had gone.
        sort($ids);
        $this->assertSame($ids, $this->survivingIds('countries', $ids),
            'PLATFORM-WIDE DELETE: a merchant wiped the shared country reference table.');

        // NOTE ON WHAT THIS DOES AND DOES NOT ISOLATE: for `country` TWO controls deny, and
        // removing either one alone leaves the test green -- the PLATFORM_GLOBAL list, and the
        // fail-closed branch for a table with no store_id and no stated derivation. That is
        // defence in depth, and it is stated rather than implied. PLATFORM_GLOBAL is isolated by
        // test_a_merchant_cannot_bulk_delete_plan_orders_even_their_own, where plan_orders DOES
        // carry store_id and the row IS the caller's own, so the list is the only thing denying.
        $response->assertStatus(403);
    }

    public function test_a_merchant_cannot_bulk_delete_states_cities_or_currencies(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant] = $this->merchantWithAddon();

        $country = (int) DB::table('countries')->insertGetId(['name' => 'Kuwait', 'created_at' => now(), 'updated_at' => now()]);
        $state = (int) DB::table('states')->insertGetId(['name' => 'Capital', 'country_id' => $country, 'created_at' => now(), 'updated_at' => now()]);
        $city = (int) DB::table('cities')->insertGetId(['name' => 'Kuwait City', 'country_id' => $country, 'state_id' => $state, 'created_at' => now(), 'updated_at' => now()]);
        $currency = (int) DB::table('currency')->insertGetId(['name' => 'Kuwaiti Dinar', 'code' => 'KWD', 'symbol' => 'KD', 'created_at' => now(), 'updated_at' => now()]);

        foreach ([['state', 'states', $state], ['city', 'cities', $city], ['currency', 'currency', $currency]] as [$type, $table, $id]) {
            $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
                'type' => $type,
                'ids' => [$id],
            ]);

            $this->assertSame([$id], $this->survivingIds($table, [$id]),
                "PLATFORM-WIDE DELETE: a merchant deleted from `{$table}`.");
            $response->assertStatus(403);
        }
    }

    /**
     * plan_orders DOES have a store_id, so a tenant filter alone would have let the payer erase
     * the record of their own subscription payment. It is the platform's billing record, not
     * merchant content -- so the type is refused outright, and the test proves the merchant's OWN
     * row is the one that survives.
     */
    public function test_a_merchant_cannot_bulk_delete_plan_orders_even_their_own(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();

        $own = (int) DB::table('plan_orders')->insertGetId([
            'order_id' => 'PO-' . uniqid(), 'plan_name' => 'Gold', 'plan_id' => 1,
            'price' => 100, 'price_currency' => 'USD', 'txn_id' => 'TXN-' . uniqid(),
            'payment_status' => 'succeeded', 'user_id' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'plan-order',
            'ids' => [$own],
        ]);

        $this->assertSame([$own], $this->survivingIds('plan_orders', [$own]),
            'A merchant erased the record of their own subscription payment.');
        $response->assertStatus(403);
    }

    /**
     * NO PLATFORM-ADMIN REGRESSION. The platform operator still has the capability the per-record
     * controllers give them, and it is exercised rather than asserted in prose: the guard must
     * pass a super admin through untouched.
     */
    public function test_a_platform_admin_can_still_bulk_delete_countries(): void
    {
        $this->enableBulkDeleteAddon();

        $admin = $this->makePlatformAdmin();

        $ids = [];
        foreach (['Atlantis', 'Lemuria'] as $name) {
            $ids[] = (int) DB::table('countries')->insertGetId([
                'name' => $name, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $response = $this->actingAs($admin)->post(route('bulk-delete'), [
            'type' => 'country',
            'ids' => $ids,
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $this->survivingIds('countries', $ids),
            'The guard broke platform administration: a super admin can no longer manage the '
            . 'country reference table, which CountryController::destroy:128 permits.');
    }

    // =========================================================================================
    //  D. PERMISSION, AND THE LEGITIMATE WORKFLOW
    // =========================================================================================

    /**
     * THE UNAUTHORIZED REQUEST. Same tenant, same store, own rows -- but no 'Delete Products'.
     * ProductController::destroy has required that name all along, so the bulk path was a
     * permission bypass for every merchant staff member.
     */
    public function test_a_merchant_without_the_delete_permission_cannot_bulk_delete_their_own_products(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();

        $ids = [
            $this->insertProduct($store, $merchant, 'p1'),
            $this->insertProduct($store, $merchant, 'p2'),
        ];

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'Product',
            'ids' => $ids,
        ]);

        sort($ids);
        $this->assertSame($ids, $this->survivingIds('products', $ids),
            'A caller with no delete permission still destroyed rows.');
        $response->assertStatus(403);
    }

    /**
     * THE POSITIVE PROOF that the fix did not break the feature. A merchant with the permission,
     * deleting their own store's products, in bulk -- the whole point of the addon.
     */
    public function test_a_merchant_with_the_permission_can_bulk_delete_their_own_products(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();
        $this->grantPermission($merchant, 'Delete Products');

        $ids = [
            $this->insertProduct($store, $merchant, 'own-1'),
            $this->insertProduct($store, $merchant, 'own-2'),
            $this->insertProduct($store, $merchant, 'own-3'),
        ];

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'Product',
            'ids' => $ids,
        ]);

        $response->assertStatus(200);
        $response->assertJson(['status' => true]);
        $this->assertSame([], $this->survivingIds('products', $ids),
            'Legitimate bulk deletion no longer works, which is a regression, not a fix.');
    }

    /**
     * A merchant's STAFF member with the permission, deleting the employer's rows. creatorId()
     * resolves through created_by, and the staff member's current_store is the employer's -- so
     * this proves the tenant filter follows the tenant and not the individual user row.
     */
    public function test_a_staff_member_with_the_permission_can_bulk_delete_the_tenants_products(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();
        $staff = $this->makeStaff($merchant);
        $this->activateModuleFor($staff);
        $this->grantPermission($staff, 'Delete Products');

        $ids = [$this->insertProduct($store, $merchant, 'staff-1')];

        $this->actingAs($staff)->post(route('bulk-delete'), [
            'type' => 'Product',
            'ids' => $ids,
        ]);

        $this->assertSame([], $this->survivingIds('products', $ids),
            'A permitted staff member cannot delete their own tenant\'s products.');
    }

    // =========================================================================================
    //  E. roles -- THE NULLABLE DISCRIMINATOR
    // =========================================================================================

    /**
     * `roles.store_id` is NULLABLE and the shared global `admin` role is created with NULL
     * (laratrust_setup_tables.php:25) and is used by EVERY self-registered merchant. Deleting it
     * takes Laratrust down platform-wide. `whereIn('store_id', ...)` cannot match NULL, and
     * `created_by` is required too, mirroring RolePolicy::ownsRole().
     */
    public function test_the_shared_global_admin_role_and_another_stores_role_both_survive(): void
    {
        $this->enableBulkDeleteAddon();

        [$attacker, $attackerStore] = $this->merchantWithAddon();
        [$victim, $victimStore] = $this->merchantWithAddon();

        $global = $this->makeGlobalAdminRole();
        $mine = $this->makeRole($attacker, $attackerStore, 'attacker-role');
        $theirs = $this->makeRole($victim, $victimStore, 'victim-role');

        $this->actingAs($attacker)->post(route('bulk-delete'), [
            'type' => 'roles',
            'ids' => [$global->id, $mine->id, $theirs->id],
        ]);

        $this->assertSame([$global->id], $this->survivingIds('roles', [$global->id]),
            'PLATFORM-WIDE: the shared global `admin` role (store_id NULL) was deleted by a merchant.');
        $this->assertSame([$theirs->id], $this->survivingIds('roles', [$theirs->id]),
            'CROSS-TENANT DELETE: another store\'s role was deleted.');
        $this->assertSame([], $this->survivingIds('roles', [$mine->id]),
            'The merchant\'s own role should still be deletable.');
    }

    // =========================================================================================
    //  F. FAIL-CLOSED, AND THE STATUS CODES
    // =========================================================================================

    /**
     * FAIL-CLOSED. A type the guard does not know is refused, not forwarded. That is what makes a
     * stale MODEL_MAP safe: the worst outcome of drift is a 403, never an unfiltered delete.
     */
    public function test_an_unknown_type_is_refused_rather_than_handed_to_the_controller(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant] = $this->merchantWithAddon();

        $this->actingAs($merchant)
            ->post(route('bulk-delete'), ['type' => 'not-a-real-type', 'ids' => [1, 2]])
            ->assertStatus(403);
    }

    /**
     * EVERY denial here is 403. repo B's lib/network/network.dart maps ANY 401 into a forced
     * logout that clears stored preferences, and handles 403 as "not allowed" without logging
     * out -- so a 401 on this endpoint would sign the operator out of the mobile app.
     */
    public function test_every_denial_on_this_endpoint_is_403_and_never_401(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();
        $id = $this->insertProduct($store, $merchant, 'status-probe');

        $cases = [
            'platform-global' => ['type' => 'country', 'ids' => [1]],
            'unknown type' => ['type' => 'nope', 'ids' => [1]],
            'missing permission' => ['type' => 'Product', 'ids' => [$id]],
            'saas billing' => ['type' => 'plan-order', 'ids' => [1]],
        ];

        foreach ($cases as $label => $payload) {
            $response = $this->actingAs($merchant)->post(route('bulk-delete'), $payload);
            $this->assertSame(403, $response->getStatusCode(), "{$label} did not answer 403.");
        }
    }

    /**
     * The guard must not convert an anonymous request into a 403 and so swallow the sign-in
     * redirect. It defers to Authenticate for a null user.
     */
    public function test_an_unauthenticated_request_still_gets_the_auth_redirect(): void
    {
        $this->enableBulkDeleteAddon();

        $response = $this->post(route('bulk-delete'), ['type' => 'Product', 'ids' => [1]]);

        $this->assertNotSame(403, $response->getStatusCode(),
            'An anonymous request was answered by the guard instead of by Authenticate.');
        $this->assertContains($response->getStatusCode(), [302, 401, 419]);
    }

    /**
     * `array_key_exists($request->type, $modelMap)` at BulkDeleteController.php:111 is a
     * TypeError when `type` is an array, and `whereIn('id', null)` at :116 is a different error
     * when `ids` is absent. Both were 500s reachable by any authenticated merchant; the guard
     * answers 422 first.
     */
    public function test_a_malformed_payload_is_rejected_instead_of_crashing(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant] = $this->merchantWithAddon();

        $this->actingAs($merchant)
            ->post(route('bulk-delete'), ['type' => ['Product'], 'ids' => [1]])
            ->assertStatus(422);

        $this->actingAs($merchant)
            ->post(route('bulk-delete'), ['type' => 'Product'])
            ->assertStatus(422);

        $this->actingAs($merchant)
            ->post(route('bulk-delete'), ['type' => 'Product', 'ids' => [['nested']]])
            ->assertStatus(422);
    }

    /**
     * INERTNESS. The guard is appended to the WHOLE `web` group, so "it does nothing anywhere
     * else" is a property that has to be proven, not assumed.
     *
     * Driven directly rather than through a page request on purpose: every Blade layout in this
     * app reads `owner_menu_settings`, a table NO migration creates
     * (NEW-ADDON-SCHEMA-02, the path-casing bug in LandingPageServiceProvider), so an HTTP smoke
     * over a rendered route 500s at the baseline for a reason that has nothing to do with this
     * guard -- and a test that red for that reason would teach nothing. This asserts the two
     * things that actually matter: $next ran, and the payload was NOT rewritten.
     *
     * The payload used is one the guard WOULD refuse on its own route (`type=country`), so a
     * guard that mis-identified the route would fail here loudly instead of silently.
     */
    public function test_the_guard_is_inert_on_a_route_it_does_not_own(): void
    {
        [$merchant] = $this->makeTenant();
        $this->actingAs($merchant);

        $foreign = new \Illuminate\Routing\Route(
            ['POST'],
            'product/destroy',
            ['uses' => 'App\Http\Controllers\ProductController@destroy']
        );
        $foreign->name('product.destroy');

        $request = \Illuminate\Http\Request::create('/product/destroy', 'POST', [
            'type' => 'country',
            'ids' => [1, 2, 3],
        ]);
        $request->setRouteResolver(fn () => $foreign);

        $reached = false;

        $response = (new BulkDeleteTenantGuard())->handle($request, function () use (&$reached) {
            $reached = true;

            return new \Illuminate\Http\Response('downstream', 200);
        });

        $this->assertTrue($reached, 'The guard answered a request on a route it does not own.');
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['1', '2', '3'], array_map('strval', (array) $request->input('ids')),
            'The guard rewrote the payload of a route it does not own.');
    }

    /**
     * The other half of inertness: on its OWN route the same payload is refused. Without this,
     * the test above would also pass for a guard that never does anything at all.
     */
    public function test_the_same_payload_is_refused_on_the_route_the_guard_does_own(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant] = $this->merchantWithAddon();

        $this->actingAs($merchant)
            ->post(route('bulk-delete'), ['type' => 'country', 'ids' => [1, 2, 3]])
            ->assertStatus(403);
    }
    // =========================================================================================
    //  G. OWNER DECISION D13 -- A CATEGORY WITH PRODUCTS IN IT IS NOT DELETABLE HERE EITHER
    // =========================================================================================

    /**
     * WHY THIS ROUTE NEEDED ITS OWN GUARD, AND WHY THE CONTROLLER'S WAS NOT ENOUGH.
     *
     * `CategoryController::destroy` carries the D13 refusal, but this endpoint never calls it:
     * BulkDeleteController:116 is `$model::whereIn('id', $request->ids)->delete()` straight onto
     * `App\Models\Category`. `products.category_id` is `ON DELETE CASCADE` against
     * `categories.id` (database/migrations/2023_12_26_092822_create_products_table.php:55), so a
     * single bulk tick removed every product in the category IN THE DATABASE -- no model event, no
     * log line, and nothing the application can undo.
     *
     * Verified on the real engine rather than inferred from SQLite's own FK handling:
     * tests/Support/Security/bin/verify-category-product-cascade-on-mysql.php, 3 checks, 0 failures
     * on MySQL 8.0.46, including `information_schema.REFERENTIAL_CONSTRAINTS` reporting
     * DELETE_RULE = CASCADE.
     */
    public function test_a_merchant_cannot_bulk_delete_a_category_that_still_has_products(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();
        $this->grantPermission($merchant, 'Delete Product Category');

        $populated = $this->insertCategory($store, 'bulk-populated');
        $productId = $this->insertProduct($store, $merchant, 'bulk-kept-product');
        DB::table('products')->where('id', $productId)->update(['category_id' => $populated]);

        $this->assertSame(
            1,
            DB::table('products')->where('category_id', $populated)->count(),
            'premise: the category really does hold a product'
        );

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'main-category',
            'ids' => [$populated],
        ]);

        $this->assertSame(409, $response->getStatusCode(),
            'A populated category was accepted for bulk deletion. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame([$populated], $this->survivingIds('categories', [$populated]),
            'The category was bulk-deleted although it still held a product (owner decision D13).');
        $this->assertSame([$productId], $this->survivingIds('products', [$productId]),
            'The FK cascade took the product with the category.');
    }

    /**
     * THE POSITIVE CONTROL. Without it the test above is satisfied by a guard that refuses every
     * category bulk delete, or by an endpoint that never worked for this type at all.
     */
    public function test_a_merchant_can_still_bulk_delete_their_own_empty_categories(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $store] = $this->merchantWithAddon();
        $this->grantPermission($merchant, 'Delete Product Category');

        $ids = [
            $this->insertCategory($store, 'bulk-empty-one'),
            $this->insertCategory($store, 'bulk-empty-two'),
        ];

        $this->assertSame(
            0,
            DB::table('products')->whereIn('category_id', $ids)->count(),
            'premise: both categories are empty'
        );

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'main-category',
            'ids' => $ids,
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $this->survivingIds('categories', $ids),
            'An EMPTY category can no longer be bulk-deleted either, so the refusal above proves '
            . 'nothing. Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * THE REFUSAL PRECEDES THE OPERATOR BYPASS.
     *
     * Every other check in this guard is downstream of `if (super admin) return $next()`, and that
     * is correct for them: narrowing the operator is what breaks platform administration. D13 is
     * not a permission rule. An operator cannot un-cascade a merchant's catalogue any more than the
     * merchant can, so this is the one check placed above the bypass -- and this test is what pins
     * it there. Moving the D13 block below the bypass leaves every other test in this file green.
     */
    public function test_the_operator_cannot_bulk_delete_a_populated_category_either(): void
    {
        $this->enableBulkDeleteAddon();

        $admin = $this->makePlatformAdmin();
        [$merchant, $store] = $this->merchantWithAddon();

        $populated = $this->insertCategory($store, 'operator-bulk-populated');
        $productId = $this->insertProduct($store, $merchant, 'operator-bulk-kept');
        DB::table('products')->where('id', $productId)->update(['category_id' => $populated]);

        $response = $this->actingAs($admin)->post(route('bulk-delete'), [
            'type' => 'main-category',
            'ids' => [$populated],
        ]);

        $this->assertSame(409, $response->getStatusCode(),
            'The operator bypass carried a populated-category bulk delete straight through to the '
            . 'cascade. Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame([$populated], $this->survivingIds('categories', [$populated]),
            'The operator bulk-deleted a category that still held a merchant\'s product.');
        $this->assertSame([$productId], $this->survivingIds('products', [$productId]),
            'A merchant\'s product was cascade-deleted by an operator bulk category delete.');
    }

    /**
     * THE TENANT CHECK IS ASKED OF THE NARROWED LIST, NOT OF RAW INPUT.
     *
     * A merchant ticking their own empty category alongside another store's populated one must get
     * their own delete, not a refusal that tells them something about store B's catalogue. Section
     * D strips the foreign id first and only what survives is asked the D13 question.
     */
    public function test_a_foreign_populated_category_does_not_block_the_callers_own_empty_one(): void
    {
        $this->enableBulkDeleteAddon();

        [$merchant, $storeA] = $this->merchantWithAddon();
        $this->grantPermission($merchant, 'Delete Product Category');

        [$other, $storeB] = $this->merchantWithAddon();

        $mineEmpty = $this->insertCategory($storeA, 'mine-empty');
        $theirsPopulated = $this->insertCategory($storeB, 'theirs-populated');
        $theirProduct = $this->insertProduct($storeB, $other, 'their-product');
        DB::table('products')->where('id', $theirProduct)->update(['category_id' => $theirsPopulated]);

        $response = $this->actingAs($merchant)->post(route('bulk-delete'), [
            'type' => 'main-category',
            'ids' => [$mineEmpty, $theirsPopulated],
        ]);

        $response->assertStatus(200);
        $this->assertSame([], $this->survivingIds('categories', [$mineEmpty]),
            'The caller\'s own empty category was withheld because of another store\'s data.');
        $this->assertSame([$theirsPopulated], $this->survivingIds('categories', [$theirsPopulated]),
            'Another store\'s category was bulk-deleted.');
        $this->assertSame([$theirProduct], $this->survivingIds('products', [$theirProduct]),
            'Another store\'s product was cascade-deleted.');
    }
}
