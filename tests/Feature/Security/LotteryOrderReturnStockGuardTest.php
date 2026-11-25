<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Product;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * GAP-260 -- THE STOCK AND MONEY HALF OF THE VENDORED LOTTERY RETURN DOOR.
 * =============================================================================================
 *
 * WHAT GAP-260 CLAIMED, AND WHAT IS ACTUALLY TRUE AT HEAD. The finding reported
 * `POST lottery-order-return` (packages/workdo/LotteryPrizes/src/routes/web.php:59) as an
 * unauthenticated cross-tenant stock and money write: the handler assigns `product_id`,
 * `variant_id` and `order_id` straight from request input and calls `Order::product_return()`,
 * with "no auth, no permission, no ownership check, anywhere on that path". The first two clauses
 * are true of the ROUTE FILE and of the HANDLER. The last clause is not true of the PATH: the
 * module declares the route inside `Route::middleware(['web'])`, and `bootstrap/app.php` appends
 * `App\Http\Middleware\ModuleOrderMutationGuard` to the `web` group, which has carried this exact
 * uri since P1-INV-14. The finding was derived by reading the vendored files and not the group.
 * `test_the_host_guard_is_on_the_resolved_middleware_stack` measures that, so this file cannot be
 * mistaken for a report that the door is open.
 *
 * SO WHY THIS FILE EXISTS. The guard's own suite
 * (`ModuleOrderMutationGuardTest::test_an_anonymous_caller_cannot_return_stock_through_the_lottery_route`)
 * drives this route and asserts a 403 -- and its own comment states that its row assertion
 * "does not isolate anything", because its fixture order is NOT delivered, so
 * `Order::product_return()` refuses on its own `delivered_status == 1` precondition whatever the
 * guard does. GAP-260's actual claim -- that the write LANDS on the victim's inventory and money
 * columns -- had therefore never been measured in either direction. Nothing in the suite built a
 * DELIVERED victim order with a real product and variant behind this route.
 *
 * This file supplies exactly that, in both directions:
 *
 *   - every refusal leg asserts the four things GAP-260 named -- `product_variants.stock`,
 *     `products.product_stock`, `orders.final_price` / `return_price` and the line's `return`
 *     flag -- are unmoved, RAW, through `DB::table()`;
 *   - every refusal leg is followed by a CONTROL on an identical fixture, driven by a principal
 *     the workflow is meant to admit, which proves those same four values DO move. Without the
 *     control, "unchanged" is also what a dead fixture, a missing route and a 404 produce --
 *     and here it is also what the delivery precondition produces, which is the specific way the
 *     existing test is vacuous.
 *
 * THE MODULE IS ENABLED THROUGH THE PRODUCTION LOADER -- an `add_on_managers` row, then
 * `PackageServiceProvider::loadAddons()`. CLAUDE.md records that no WorkDo module loads in any
 * test run otherwise, so `test_without_the_module_the_return_route_does_not_exist` pins the
 * suite's structural blindness first: without the row the route is absent, and a row assertion
 * against an absent route is satisfied by a 404.
 *
 * ALL ROW ASSERTIONS GO THROUGH `DB::table()`. `App\Models\Order` and `App\Models\Product` are
 * both `Cachable`, and under `CACHE_STORE=array` an Eloquent read can serve a mutated in-memory
 * instance for the rest of the test -- which produced a measured FALSE REPRODUCTION during
 * P1-INV-04, where a model read reported a hijacked row the database never held.
 *
 * WHY 403 AND NOT 401. No shipped client calls this route at all: the only caller of either
 * route in the module's unauthenticated group is the module's OWN merchant-panel view
 * (`admin/lottery-order/view.blade.php:374`, for the status route), there is no storefront or
 * Flutter caller, and `lottery-order-return` has no caller anywhere in either repository. A 401
 * would be the dangerous answer -- the shipped Flutter admin app force-logs-out on any 401 -- and
 * a JSON 403 is what the handler's own JSON shape makes readable. The customer-facing return
 * workflow is a DIFFERENT door (`api/{slug}/product-return` ->
 * `Api\ApiController::product_return`, and `OrderController::order_return` on the web), which
 * nothing here touches; `test_a_shopper_cannot_drive_the_merchant_panel_return_route` states that
 * boundary rather than leaving it implied.
 */
class LotteryOrderReturnStockGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const RETURN_URI = 'lottery-order-return';

    private const STATUS_URI = 'lottery-order-status-change/{id}';

    private const RETURN_ACTION =
        'Workdo\LotteryPrizes\app\Http\Controllers\LotteryOrderController@order_return';

    /** Exactly what production does: the row, then the provider's own loader. */
    private function enableModule(): void
    {
        AddOnManager::create([
            'module' => 'LotteryPrizes',
            'name' => 'Lottery Prizes',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'lottery-prizes',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes']);
    }

    /**
     * The module's own tables, which RefreshDatabase does not create.
     *
     * `Artisan::call('migrate')` does NOT work here: SecurityTestCase::satisfyUpdaterGate()
     * records every on-disk migration name as already run, so the migrator creates nothing and
     * returns 0. The migration objects are invoked directly instead. Needed because the SIBLING
     * route's handler reads `PurchaseTicket` after its write, so an absent `purchase_tickets`
     * would make a status-route case fail for a fixture reason rather than a guard reason.
     */
    private function migrateModuleTables(): void
    {
        $files = glob(base_path('packages/workdo/LotteryPrizes/src/database/migrations/*.php')) ?: [];

        $this->assertNotEmpty($files,
            'No migration files under the LotteryPrizes module; its layout changed and this '
            . 'fixture would build nothing while reporting success.');

        foreach ($files as $file) {
            $migration = require $file;
            $migration->up();
        }

        $this->assertTrue(Schema::hasTable('purchase_tickets'),
            'The module migration did not run, so a sibling-route case would answer for a '
            . 'missing table rather than for the guard.');
    }

    /**
     * A store whose id is neither 1 nor its owner's id.
     *
     * Skill §6.15: when "the route's store", "store 1" and "its owner's user id" are one number,
     * a wrong-store mutant survives because every wrong answer is also the right one. Burning a
     * store and a user before the one under test separates the three.
     *
     * @return array{0: \App\Models\User, 1: Store}
     */
    private function tenantWhoseIdsAreNobodyElses(): array
    {
        /*
         * A BARE USER FIRST, THEN A WHOLE TENANT. `makeTenant()` inserts one user AND one store,
         * so calling it repeatedly leaves the two sequences IN LOCKSTEP and every store's id
         * equals its owner's -- which is the collapse this helper exists to prevent, and which
         * the first version of it walked straight into. The bare merchant advances `users` past
         * `stores`; the burnt tenant moves the store under test off id 1.
         */
        $this->makeMerchant();
        $this->makeTenant();

        [$merchant, $store] = $this->makeTenant();

        $this->assertNotSame(1, (int) $store->id);
        $this->assertNotSame((int) $merchant->id, (int) $store->id,
            'The store id equals its owner\'s user id, so a guard reading the wrong one of the '
            . 'two would still answer correctly and a mutant would survive.');

        return [$merchant, $store];
    }

    private function productIn(Store $store, int $stock, bool $withVariant): Product
    {
        return Product::forceCreate([
            'name' => 'Lottery prize ' . uniqid(),
            'slug' => 'p-' . uniqid(),
            'product_stock' => $stock,
            'track_stock' => 1,
            'variant_product' => $withVariant ? 1 : 0,
            'price' => 100.00,
            'sale_price' => 90.00,
            'status' => 1,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function variantFor(Product $product, int $stock): int
    {
        return DB::table('product_variants')->insertGetId([
            'product_id' => $product->id,
            'variant' => 'red',
            'sku' => 'v-' . uniqid(),
            'price' => 10,
            'stock' => $stock,
            'store_id' => $product->store_id,
            'variation_option' => 'enabled,manage_stock',
            'stock_status' => 'in_stock',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * A DELIVERED order carrying one returnable line.
     *
     * `delivered_status = 1` is the whole point: without it `Order::product_return()` refuses on
     * its own precondition and every "nothing moved" assertion in this file would hold with the
     * guard deleted. `'return' => 0` is present because the method's second pass reads the key.
     */
    private function deliveredOrder(Store $store, Product $product, int $variantId, int $qty)
    {
        return $this->makeOrder($store, [
            'delivered_status' => 1,
            'payment_type' => 'cod',
            'final_price' => 100,
            'return_price' => 0,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'qty' => $qty,
                'final_price' => 90,
                'return' => 0,
            ]]),
        ]);
    }

    /** Every value GAP-260 named, read raw, in one shape so the legs compare like for like. */
    private function snapshot(int $orderId, int $productId, ?int $variantId): array
    {
        $order = DB::table('orders')->where('id', $orderId)->first();
        $this->assertNotNull($order, "The orders row {$orderId} vanished.");

        return [
            'product_stock' => (int) DB::table('products')->where('id', $productId)->value('product_stock'),
            'variant_stock' => $variantId === null
                ? null
                : (int) DB::table('product_variants')->where('id', $variantId)->value('stock'),
            'final_price' => (float) $order->final_price,
            'return_price' => (float) $order->return_price,
            'product_json' => (string) $order->product_json,
        ];
    }

    // =========================================================================================
    //  PREMISES. WITHOUT THESE EVERY ASSERTION BELOW CAN PASS FOR A REASON THAT IS NOT THE GUARD.
    // =========================================================================================

    /** THE SUITE'S BLINDNESS, MEASURED: no module row, no route, so a 404 satisfies any row check. */
    public function test_without_the_module_the_return_route_does_not_exist(): void
    {
        $uris = collect(Route::getRoutes())->map(fn ($route) => $route->uri())->all();

        $this->assertNotContains(self::RETURN_URI, $uris,
            'The route exists without an add_on_managers row, so this file\'s premise about how '
            . 'the module loads is wrong and its other cases may be measuring something else.');
        $this->assertNotContains(self::STATUS_URI, $uris);

        $this->assertSame(404, $this->postJson('/' . self::RETURN_URI, ['order_id' => 1])->status());
    }

    /** WITH THE MODULE ON, THE ROUTE RESOLVES TO THE VENDORED HANDLER THIS FILE IS ABOUT. */
    public function test_with_the_module_enabled_the_route_resolves_to_the_vendored_handler(): void
    {
        $this->enableModule();

        $route = collect(Route::getRoutes())
            ->first(fn ($r) => $r->uri() === self::RETURN_URI && in_array('POST', $r->methods(), true));

        $this->assertNotNull($route, 'The module loaded but did not register ' . self::RETURN_URI);
        $this->assertSame(
            ltrim(self::RETURN_ACTION, '\\'),
            ltrim((string) $route->getActionName(), '\\'),
            'The route no longer points at the handler GAP-260 is about.'
        );
    }

    /**
     * THE CONTROL THAT REFUTES GAP-260's "NO CHECK ANYWHERE ON THAT PATH".
     *
     * The route's DECLARED middleware is only `['web', 'themelanguage']`, which is what reading
     * the vendored route file shows and is why the finding concluded the path was open. What
     * actually runs is the EXPANDED `web` group, and the host guard is in it. Asserted against
     * the router's own expansion (`gatherRouteMiddleware`), not against `gatherMiddleware()`,
     * which leaves group names unresolved and would answer the wrong question -- the same class
     * of error as checking `getGlobalScopes()` to ask whether a model is scoped.
     */
    public function test_the_host_guard_is_on_the_resolved_middleware_stack(): void
    {
        $this->enableModule();

        $router = $this->app['router'];

        $gather = new \ReflectionMethod($router, 'gatherRouteMiddleware');
        $gather->setAccessible(true);

        foreach ([self::RETURN_URI, self::STATUS_URI] as $uri) {
            $route = collect(Route::getRoutes())
                ->first(fn ($r) => $r->uri() === $uri && in_array('POST', $r->methods(), true));

            $this->assertNotNull($route, "The module did not register {$uri}");

            $declared = $route->gatherMiddleware();
            $this->assertNotContains(\App\Http\Middleware\ModuleOrderMutationGuard::class, $declared,
                'The guard is now DECLARED on the route, which can only have happened by editing '
                . 'the vendored route file. Read packages/workdo/ before trusting this suite.');

            $expanded = $gather->invoke($router, $route);
            $this->assertContains(\App\Http\Middleware\ModuleOrderMutationGuard::class, $expanded,
                "{$uri} resolves without the host guard. GAP-260 is live: an anonymous POST "
                . 'reaches the vendored handler.');
        }
    }

    /**
     * BOTH ROUTES ARE DECLARED TO BOTH HOST CONTROLS, AND THE TWO ARE NOT THE SAME CONTROL.
     *
     * Measured, by removing each alone and re-running this file:
     *
     *   `ModuleOrderMutationGuard`'s TARGETS entry removed .. the ANONYMOUS legs still pass,
     *        because `ModuleAdministrativeRouteGuard` refuses an unauthenticated caller on the
     *        manifest's `auth` rule. Only the cross-tenant MERCHANT leg goes red.
     *   the manifest rule removed too ....................... the anonymous legs go red: that is
     *        GAP-260 reproduced.
     *
     * So authentication on this path is defended TWICE and OWNERSHIP exactly once. A behavioural
     * test can only see the entry's removal through the one leg whose fixture reaches it, so the
     * declarations are pinned here as well -- otherwise deleting the entry looks like a one-test
     * regression rather than the loss of the only tenant boundary this door has.
     */
    public function test_both_module_order_routes_are_declared_to_the_host_controls(): void
    {
        $targets = new \ReflectionClassConstant(
            \App\Http\Middleware\ModuleOrderMutationGuard::class, 'TARGETS'
        );

        $declared = $targets->getValue();

        foreach ([self::RETURN_URI, self::STATUS_URI] as $uri) {
            $this->assertArrayHasKey($uri, $declared,
                "ModuleOrderMutationGuard no longer names {$uri}, so nothing on that path "
                . 'compares the order\'s store with the caller\'s. GAP-260.');
            $this->assertSame('orders', $declared[$uri]['table']);
            $this->assertSame('Manage Order', $declared[$uri]['permission']);

            $this->assertSame('auth',
                \App\Support\ModuleRouteManifest::administrativeRule('POST', $uri),
                "The manifest no longer requires authentication on {$uri}, which is the second "
                . 'of the two layers that refuse an anonymous caller.');
        }

        // THE KEY EACH HANDLER READS, taken from its own source line. A guard that authorises one
        // row while the handler mutates another is not a guard.
        $this->assertSame(['order_id'], $declared[self::RETURN_URI]['keys']);
        $this->assertSame(['id'], $declared[self::STATUS_URI]['keys']);
    }

    // =========================================================================================
    //  GAP-260 ITSELF: THE DELIVERED ORDER, THE VICTIM'S STOCK, AND THE MONEY COLUMN.
    // =========================================================================================

    /** AN ANONYMOUS POST CANNOT CREDIT ANOTHER STORE'S VARIANT STOCK OR REWRITE ITS TOTAL. */
    public function test_an_anonymous_post_cannot_credit_another_stores_variant_stock(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $victim = $this->deliveredOrder($storeB, $product, $variantId, 9);

        $before = $this->snapshot($victim->id, $product->id, $variantId);
        $this->assertSame(50, $before['variant_stock']);
        $this->assertSame(100.0, $before['final_price']);

        $response = $this->postJson('/' . self::RETURN_URI, [
            'order_id' => $victim->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->snapshot($victim->id, $product->id, $variantId),
            'An anonymous POST moved another store\'s stock or money. GAP-260.');
    }

    /**
     * THE CONTROL FOR THE CASE ABOVE: the identical fixture, driven by the order's own merchant,
     * really does credit the variant and reduce the total. Without this the "nothing moved"
     * assertion is satisfied by a dead fixture, and the delivery precondition is the specific way
     * the existing suite's version of this case is vacuous.
     */
    public function test_the_victims_own_merchant_can_still_return_a_variant_line(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [$merchantB, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $this->grantPermission($merchantB, 'Manage Order');

        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeB, $product, $variantId, 9);

        $response = $this->actingAs($merchantB->fresh())->postJson('/' . self::RETURN_URI, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
        ]);

        $after = $this->snapshot($order->id, $product->id, $variantId);

        $this->assertSame(59, $after['variant_stock'],
            'The legitimate merchant return no longer credits the variant, so every refusal leg '
            . 'in this file proves nothing. Status ' . $response->status() . ', body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(90.0, $after['return_price']);
        $this->assertSame(10.0, $after['final_price']);
        $this->assertSame(1, (int) json_decode($after['product_json'], true)[0]['return']);
        $this->assertNotSame(403, $response->status());
    }

    /** AN ANONYMOUS POST CANNOT CREDIT ANOTHER STORE'S PRODUCT-LEVEL STOCK EITHER. */
    public function test_an_anonymous_post_cannot_credit_another_stores_product_stock(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $product = $this->productIn($storeB, 50, false);
        $victim = $this->deliveredOrder($storeB, $product, 0, 9);

        $before = $this->snapshot($victim->id, $product->id, null);
        $this->assertSame(50, $before['product_stock']);

        $response = $this->postJson('/' . self::RETURN_URI, [
            'order_id' => $victim->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->snapshot($victim->id, $product->id, null),
            'An anonymous POST moved another store\'s product stock or money. GAP-260.');
    }

    /** THE CONTROL FOR THE NON-VARIANT BRANCH. */
    public function test_the_victims_own_merchant_can_still_return_a_non_variant_line(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [$merchantB, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $this->grantPermission($merchantB, 'Manage Order');

        $product = $this->productIn($storeB, 50, false);
        $order = $this->deliveredOrder($storeB, $product, 0, 9);

        $response = $this->actingAs($merchantB->fresh())->postJson('/' . self::RETURN_URI, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $after = $this->snapshot($order->id, $product->id, null);

        $this->assertSame(59, $after['product_stock'],
            'The legitimate non-variant return no longer credits the product. Status '
            . $response->status() . ', body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(10.0, $after['final_price']);
        $this->assertNotSame(403, $response->status());
    }

    /**
     * AN AUTHENTICATED MERCHANT OF ANOTHER STORE CANNOT DO IT EITHER.
     *
     * `Manage Order` is granted deliberately, so the permission limb cannot be what refuses:
     * only the store comparison can. This is the leg that an authentication-only guard passes,
     * and it is why an anonymous probe alone cannot measure this door.
     */
    public function test_a_merchant_of_another_store_cannot_credit_the_victims_stock(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        [$merchantB, $storeB] = $this->tenantWhoseIdsAreNobodyElses();

        /*
         * THE VICTIM'S OWN MERCHANT HOLDS THE PERMISSION TOO, AND THAT IS NOT DECORATION.
         * ----------------------------------------------------------------------------------
         * It is the production state -- `Manage Order` is seeded by the host and granted to the
         * merchant `admin` role every merchant holds -- and WITHOUT it this leg could not see a
         * guard that read the wrong principal. Measured: mutating the guard's
         * `auth('web')->user()` to a user that is not the caller SURVIVED this file, because the
         * substituted principal was store B's owner, who had not been granted `Manage Order`, so
         * the PERMISSION limb refused and the store comparison -- the thing under test -- was
         * never reached. The mutant was masked by a limb that happens to agree. With both
         * merchants entitled, only the store comparison can refuse this request, which is the
         * question this leg asks.
         */
        $this->grantPermission($merchantB, 'Manage Order');

        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $victim = $this->deliveredOrder($storeB, $product, $variantId, 9);

        $before = $this->snapshot($victim->id, $product->id, $variantId);

        $response = $this->actingAs($merchantA->fresh())->postJson('/' . self::RETURN_URI, [
            'order_id' => $victim->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            /*
             * A `store_id` THE CALLER CHOOSES, NAMING THEIR OWN STORE. The handler does not read
             * this key and neither does the guard, and that is the point: CLAUDE.md records four
             * separate defects caused by a tenant predicate built from caller-supplied input,
             * which only ever compares the caller's own two values. Sending it here means a guard
             * that ever started honouring such a key is caught by this leg instead of shipping.
             */
            'store_id' => $merchantA->current_store,
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->snapshot($victim->id, $product->id, $variantId),
            'A store-A merchant holding Manage Order credited store B\'s stock. The guard is '
            . 'authenticating and not authorising.');
    }

    /**
     * A SHOPPER SIGNED IN ON THE `customers` GUARD CANNOT DRIVE THIS ROUTE.
     *
     * Not a regression in the shopper's own return workflow: that is a different door
     * (`api/{slug}/product-return`, and `OrderController::order_return` on the web), and nothing
     * in either repository posts to `lottery-order-return` from a storefront. This leg exists
     * because the principal is the trap -- `auth()->user()` on the mutable DEFAULT guard would
     * answer with the Customer, and `isAbleTo()` does not exist on that model, so a guard reading
     * the default guard rather than `auth('web')` either fatals or waves the request through.
     * The default guard is put back to `web` after signing the customer in, which is the
     * production shape (`PlatformUploaderDoorsTest::asAShopper`).
     */
    public function test_a_shopper_cannot_drive_the_merchant_panel_return_route(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $victim = $this->deliveredOrder($storeB, $product, $variantId, 9);

        $customer = \App\Models\Customer::create([
            'first_name' => 'Gap260',
            'last_name' => 'Shopper',
            'email' => 'shopper-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'mobile' => '555000261',
            'status' => 1,
            'store_id' => $storeB->id,
        ]);

        $this->actingAs($customer, 'customers');
        // PUT THE DEFAULT GUARD BACK. `actingAs(..., 'customers')` also calls
        // shouldUse('customers'), which is NOT the production shape and makes `auth()->user()`
        // answer with the Customer -- the exact condition this leg means to drive against a guard
        // that reads `auth('web')`.
        $this->app['auth']->shouldUse('web');
        $this->assertNull(auth()->user(),
            'The default guard still holds the customer, so this leg is not the production shape.');
        $this->assertNotNull(auth('customers')->user(), 'The shopper is not signed in at all.');

        $before = $this->snapshot($victim->id, $product->id, $variantId);

        $response = $this->postJson('/' . self::RETURN_URI, [
            'order_id' => $victim->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->snapshot($victim->id, $product->id, $variantId));
    }

    /** THE PLATFORM OPERATOR'S SUPPORT WORKFLOW IS PRESERVED. */
    public function test_the_platform_operator_can_still_return_through_the_module_route(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        $operator = $this->makePlatformAdmin();

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeB, $product, $variantId, 9);

        $response = $this->actingAs($operator->fresh())->postJson('/' . self::RETURN_URI, [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
        ]);

        $after = $this->snapshot($order->id, $product->id, $variantId);

        $this->assertSame(59, $after['variant_stock'],
            'The operator was refused. On a database built from source NO role holds '
            . '\'Manage Order\', so a guard checking the permission before the operator limb is '
            . 'a lockout, not a tightening. Status ' . $response->status() . ', body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertNotSame(403, $response->status());
    }

    // =========================================================================================
    //  THE SIBLING ROUTE, AND THE CHAIN THAT MAKES IT PART OF THIS FINDING.
    // =========================================================================================

    /**
     * AN ANONYMOUS POST CANNOT MARK ANOTHER STORE'S ORDER DELIVERED.
     *
     * The sibling route `lottery-order-status-change/{id}` is not merely a second defect of the
     * same shape: `delivered` is the PRECONDITION `Order::product_return()` requires
     * (`delivered_status == 1`). So an attacker who could drive the status route could manufacture
     * the precondition for GAP-260 on any order in any store, whatever state it was in. The
     * guard's existing suite drives only `cancel` on this route, so this transition had never
     * been measured.
     *
     * The order is left PENDING and the assertion is that it stays pending, with a control below
     * proving the transition is reachable at all.
     */
    public function test_an_anonymous_post_cannot_mark_another_stores_order_delivered(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $product = $this->productIn($storeB, 50, true);
        $variantId = $this->variantFor($product, 50);
        $victim = $this->makeOrder($storeB, [
            'delivered_status' => 0,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'qty' => 9,
                'final_price' => 90,
                'return' => 0,
            ]]),
        ]);

        $response = $this->postJson('/lottery-order-status-change/' . $victim->id, [
            'delivered' => 'delivered',
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(0,
            (int) DB::table('orders')->where('id', $victim->id)->value('delivered_status'),
            'An anonymous POST marked another store\'s order delivered, which manufactures the '
            . 'only precondition Order::product_return() requires.');
    }

    /** THE CONTROL: the `delivered` transition really is reachable for an entitled principal. */
    public function test_the_victims_own_merchant_can_mark_their_order_delivered(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [$merchantB, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $this->grantPermission($merchantB, 'Manage Order');

        $order = $this->makeOrder($storeB, ['delivered_status' => 0, 'product_json' => '[]']);

        /*
         * THE WRITE, NOT THE STATUS CODE. `LotteryOrderController::order_status_change` reaches
         * `PurchaseTicket`, `getStoreById` and a mail/SMS try/catch AFTER
         * `Order::order_status_change()` has already saved, so a permitted request can answer 500
         * for reasons that have nothing to do with this guard. The row moving is the proof the
         * request was passed through.
         */
        $this->actingAs($merchantB->fresh())
            ->postJson('/lottery-order-status-change/' . $order->id, ['delivered' => 'delivered']);

        $this->assertSame(1,
            (int) DB::table('orders')->where('id', $order->id)->value('delivered_status'),
            'The entitled merchant cannot reach the `delivered` transition, so the refusal leg '
            . 'above proves nothing.');
    }

    // =========================================================================================
    //  THE GUARD AND THE HANDLER MUST MEAN THE SAME ROW.
    // =========================================================================================

    /**
     * A MULTIPART FILE PART NAMED `order_id` CANNOT MOVE A ROW THE GUARD DID NOT AUTHORISE.
     *
     * Skill §6.12: `Request::all()` is `array_replace_recursive($this->input(), $this->allFiles())`,
     * so a FILE part named like a field REPLACES that field for every reader of
     * `$request->order_id` -- which is what the vendored handler reads -- while
     * `$request->input('order_id')`, which the guard reads, never sees files. The two therefore
     * read different values for the same key, and that divergence is the shape that has produced
     * a real defect here before.
     *
     * Measured outcome: not a cross-tenant write. The guard authorises the posted FIELD (the
     * caller's own order), and the handler then hands an `UploadedFile` to
     * `Order::find()`, which cannot be bound, so the request fails before
     * `performProductReturn()` reads anything -- and `product_return()` wraps its writes in a
     * transaction, so there is no half-applied state. This case pins that outcome in the only
     * terms that matter: neither store's stock or money moves. It is hardening, not a finding.
     */
    public function test_a_file_part_named_order_id_cannot_move_either_stores_stock(): void
    {
        $this->enableModule();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');
        $ownProduct = $this->productIn($storeA, 50, true);
        $ownVariant = $this->variantFor($ownProduct, 50);
        $ownOrder = $this->deliveredOrder($storeA, $ownProduct, $ownVariant, 9);

        [, $storeB] = $this->tenantWhoseIdsAreNobodyElses();
        $victimProduct = $this->productIn($storeB, 50, true);
        $victimVariant = $this->variantFor($victimProduct, 50);
        $victim = $this->deliveredOrder($storeB, $victimProduct, $victimVariant, 9);

        $beforeOwn = $this->snapshot($ownOrder->id, $ownProduct->id, $ownVariant);
        $beforeVictim = $this->snapshot($victim->id, $victimProduct->id, $victimVariant);

        /*
         * `call()` RATHER THAN `post()`, because the field and the file must carry the SAME key
         * and a single PHP array cannot hold a key twice. `post()` splits one array into inputs
         * and files by instance, so the collision this case is about is unreachable through it --
         * the guard reads `input('order_id')` (the field) and the handler reads
         * `$request->order_id`, i.e. `all()`, where the FILE wins.
         */
        $this->actingAs($merchantA->fresh())->call(
            'POST',
            '/' . self::RETURN_URI,
            [
                'order_id' => (string) $ownOrder->id,
                'product_id' => (string) $victimProduct->id,
                'variant_id' => (string) $victimVariant,
            ],
            [],
            ['order_id' => \Illuminate\Http\UploadedFile::fake()
                ->createWithContent('victim.txt', (string) $victim->id)]
        );

        $this->assertSame($beforeVictim, $this->snapshot($victim->id, $victimProduct->id, $victimVariant),
            'A file part named like the handler\'s key moved another store\'s stock or money.');
        $this->assertSame($beforeOwn, $this->snapshot($ownOrder->id, $ownProduct->id, $ownVariant),
            'The request neither refused nor no-opped: it half-applied to the caller\'s own order.');
    }
}
