<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-14 -- SIX ANONYMOUS ADMIN MUTATIONS IN TWO VENDORED MODULES.
 * =============================================================================================
 *
 * THE PREMISE TEST COMES FIRST AND IT IS NOT A FORMALITY. CLAUDE.md records that no WorkDo
 * module loads in any test run unless the test inserts an `add_on_managers` row, so the whole
 * existing suite -- 2880 testcases -- is structurally blind to these six routes: they are not in
 * its route table at all. `test_without_the_modules_none_of_the_six_routes_exist` measures that
 * blindness, so no later assertion here can be mistaken for something the suite already covered.
 *
 * Both modules are enabled through the PRODUCTION loader (an `add_on_managers` row, then
 * `PackageServiceProvider::loadAddons`), following OrderDocumentRouteResolutionTest, because
 * enabling them any other way would prove something about the test harness rather than about the
 * application.
 *
 * THE MODULE MIGRATIONS ARE RUN EXPLICITLY. RefreshDatabase runs database/migrations only, so
 * `auction_product_orders`, `auction_product_settings` and `purchase_tickets` are absent -- which
 * would send every auction request down the guard's fail-closed limb and make a "403" prove
 * nothing about ownership. `migrateModuleTables()` creates them so the ownership limb is the
 * thing being measured; `test_the_guard_refuses_when_the_target_table_is_absent` deliberately
 * does NOT call it, so the fail-closed limb is covered on its own terms.
 *
 * WHAT A POSITIVE CASE ASSERTS, AND WHY NOT THE STATUS CODE. The two lottery handlers reach
 * notification code after their write (`getStoreById`, `User::find`, a mail/webhook try/catch),
 * so a permitted request can still answer 500 for reasons that have nothing to do with this
 * guard. Every positive case therefore asserts the HANDLER'S OWN WRITE landed -- the row moved --
 * and only then that the response was not a 403. A status-only positive would have been a test
 * of the notification stack.
 *
 * THE REMOVAL CHECKS, AS MEASURED (scripted, each control deleted alone, suite re-run, file
 * restored byte-for-byte). Re-run in full after the adversarial review's fixes:
 *
 *   R1  the bootstrap/app.php registration ........ 20 of 29 fail
 *   R2  the anonymous limb ........................  7 fail (one per route, + the customer case)
 *   R3  the store comparison ......................  8 fail
 *   R4  the permission limb .......................  2 fail (host-order + auction)
 *   R5  operator-first ordering ...................  1 fail (the policy ratchet)
 *   R6  body-first id resolution ..................  5 fail
 *   R6b the present-key-decides rule ..............  1 fail
 *   R7  the fail-closed try/catch .................  1 fail
 *   R8  the uniform refusal body ..................  1 fail
 *   R9  the inertness early return ................  1 fail -- ONLY AFTER the test was fixed;
 *                                                     see that test for what it missed first
 *   R11 the auth('web') principal lookup ..........  1 fail
 *   R13 the permission-row existence check ........  1 fail
 *   R10 the missing-row refusal ................... GREEN -- SUBSUMED, not isolated
 *   R12 getCurrentStore($user->id) ................ GREEN here -- covered by a SOURCE RATCHET
 *
 * TWO CONTROLS ARE NOT ISOLATED BY ANY TEST IN THIS FILE, AND BOTH SAY SO RATHER THAN BEING
 * QUIETLY COUNTED:
 *
 * R10 is SUBSUMED. `empty($row->store_id)` in the guard is a language construct that suppresses
 * the null-property warning and short-circuits before the cast, so a null row already denies
 * through the store comparison. The explicit check is kept as defence in depth and the guard says
 * so at that line. Writing a test for it would be writing a test for `empty()`.
 *
 * R12 is UNREACHABLE FROM HERE. Passing the user id to `getCurrentStore()` only matters when the
 * DEFAULT guard holds a different principal from `auth('web')` -- and in that case the guard has
 * already denied at its null check, before this line. So no behavioural test can isolate it; it
 * is defensive against a future reordering of the very limbs that make it unreachable. It is
 * pinned instead by a source assertion in
 * tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest, alongside the sibling guard's -- VERIFIED
 * to bite: removing the argument reddens that test. A control covered by a ratchet rather than by
 * behaviour is worth having and is worth labelling correctly.
 *
 * ALL ROW ASSERTIONS GO THROUGH `DB::table()`. `App\Models\Order` is Cachable, and an Eloquent
 * read in a test can serve a mutated in-memory instance -- that produced a measured FALSE
 * REPRODUCTION during P1-INV-04, where a model read reported a hijacked row while the raw row was
 * untouched and the response was a 500.
 */
class ModuleOrderMutationGuardTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** uri => the request key its handler reads, taken from the handler's own source line. */
    private const ROUTES = [
        'lottery-order-status-change/{id}' => 'id',
        'lottery-order-return' => 'order_id',
        'auction-order-status-change/{id}' => 'id',
        'auction-order-return' => 'order_id',
        'auction-order-assign' => 'order_id',
        'update-auction-status' => 'auctionId',
    ];

    /** Exactly what production does: the row, then the provider's own loader. */
    private function enableModules(): void
    {
        foreach ([
            ['LotteryPrizes', 'Lottery Prizes', 'lottery-prizes'],
            ['AuctionProduct', 'Auction Product', 'auction-product'],
        ] as [$module, $name, $package]) {
            AddOnManager::create([
                'module' => $module,
                'name' => $name,
                'monthly_price' => 0,
                'yearly_price' => 0,
                'is_enable' => 1,
                'package_name' => $package,
            ]);
        }

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['LotteryPrizes', 'AuctionProduct']);
    }

    /**
     * The module tables, which RefreshDatabase does not create.
     *
     * `Artisan::call('migrate', ['--path' => ...])` DOES NOT WORK HERE, and the reason is worth
     * recording because it is silent: SecurityTestCase::satisfyUpdaterGate() inserts the name of
     * EVERY on-disk migration -- the module directories included -- into the `migrations` table,
     * so that XSS's updater gate does not redirect platform-admin requests away from the
     * controller under test. The migrator then reads those names as already run and creates
     * nothing, with exit code 0. A fixture that reports success and builds no table is exactly
     * the shape of a false reading, so the migration objects are invoked directly instead.
     *
     * Each module `up()` is already wrapped in `if (! Schema::hasTable(...))` upstream, so this
     * is idempotent, and it uses the module's OWN schema -- a column rename upstream shows up
     * here as a failing fixture rather than as a silently different table.
     */
    private function migrateModuleTables(): void
    {
        foreach ([
            'packages/workdo/AuctionProduct/src/database/migrations',
            'packages/workdo/LotteryPrizes/src/database/migrations',
        ] as $path) {
            $files = glob(base_path($path . '/*.php')) ?: [];

            $this->assertNotEmpty($files,
                "No migration files under {$path}; the module layout changed and this fixture "
                . 'would build nothing while reporting success.');

            foreach ($files as $file) {
                $migration = require $file;
                $migration->up();
            }
        }

        foreach (['auction_product_orders', 'auction_product_settings'] as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "The module migration for `{$table}` did not run, so every auction assertion in "
                . 'this file would answer from the guard\'s fail-closed limb instead of its '
                . 'ownership limb.'
            );
            $this->assertTrue(
                Schema::hasColumn($table, 'store_id'),
                "`{$table}` has no store_id column -- the guard's whole predicate rests on it."
            );
        }
    }

    private function auctionOrder(Store $store, array $attributes = []): int
    {
        return (int) DB::table('auction_product_orders')->insertGetId(array_merge([
            'store_id' => $store->id,
            'product_json' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function auctionSetting(Store $store, int $isActive = 1): int
    {
        return (int) DB::table('auction_product_settings')->insertGetId([
            'name' => 'Auction ' . uniqid(),
            'store_id' => $store->id,
            'is_active' => $isActive,
            // Both NOT NULL in the module's own migration (:17, :25).
            'created_by' => (int) $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function pendingOrder(Store $store)
    {
        return $this->makeOrder($store, [
            'delivered_status' => 0,
            'payment_status' => 'Paid',
            'product_json' => json_encode([]),
        ]);
    }

    /** @return array<string,mixed> */
    private function row(string $table, int $id): array
    {
        $row = DB::table($table)->where('id', $id)->first();
        $this->assertNotNull($row, "The `{$table}` row {$id} vanished.");

        return (array) $row;
    }

    // =========================================================================================
    //  PREMISE
    // =========================================================================================

    /**
     * THE SUITE'S BLINDNESS, MEASURED. Without the two `add_on_managers` rows none of the six
     * uris is in the route table, so nothing the existing suite does could have exercised them.
     * This is the reason the defect survived 2880 testcases.
     */
    public function test_without_the_modules_none_of_the_six_routes_exist(): void
    {
        $posts = Route::getRoutes()->getRoutesByMethod()['POST'] ?? [];

        foreach (array_keys(self::ROUTES) as $uri) {
            $this->assertArrayNotHasKey(
                $uri,
                $posts,
                "POST {$uri} is registered with no module enabled. That changes the premise of "
                . 'this whole file -- re-read the guard docblock.'
            );
        }
    }

    /**
     * WITH THE MODULES ENABLED: all six exist, NONE carries `Authenticate`, and ALL carry the
     * guard.
     *
     * The middle assertion is the defect and the third is the fix, measured in the same pass so
     * they cannot drift. If an upstream release ever gates those groups, the `Authenticate`
     * assertion reddens -- and that is wanted: it means the exposure is gone upstream and the
     * guard's reason for covering that uri should be re-read rather than assumed.
     */
    public function test_with_the_modules_enabled_all_six_routes_are_anonymous_and_guarded(): void
    {
        $this->enableModules();

        $posts = Route::getRoutes()->getRoutesByMethod()['POST'] ?? [];

        foreach (array_keys(self::ROUTES) as $uri) {
            $this->assertArrayHasKey($uri, $posts, "POST {$uri} did not register.");

            $middleware = app('router')->gatherRouteMiddleware($posts[$uri]);

            $this->assertNotContains(
                \Illuminate\Auth\Middleware\Authenticate::class,
                $middleware,
                "{$uri} is now authenticated upstream. Good news, but it changes the premise of "
                . 'P1-INV-14 -- re-read the guard docblock before trusting it.'
            );

            $this->assertContains(
                \App\Http\Middleware\ModuleOrderMutationGuard::class,
                $middleware,
                "{$uri} does not carry ModuleOrderMutationGuard. Stack: ["
                . implode(', ', $middleware) . ']'
            );
        }
    }

    /**
     * THE GUARD'S TARGET LIST AND THE ROUTE TABLE AGREE, IN BOTH DIRECTIONS.
     *
     * A target whose uri no longer exists is dead configuration that looks like a control --
     * the failure mode OrderDocumentRouteResolutionTest's dead `$exempt` entries recorded. A
     * registered uri that is not a target is the exposure returning.
     */
    public function test_every_guard_target_is_a_real_registered_route(): void
    {
        $this->enableModules();

        $targets = array_keys(
            (new \ReflectionClass(\App\Http\Middleware\ModuleOrderMutationGuard::class))
                ->getConstant('TARGETS')
        );

        $this->assertSame(
            array_keys(self::ROUTES),
            $targets,
            'The guard\'s TARGETS no longer match the six uris this file drives.'
        );

        $posts = Route::getRoutes()->getRoutesByMethod()['POST'] ?? [];

        foreach ($targets as $uri) {
            $this->assertArrayHasKey(
                $uri,
                $posts,
                "The guard targets `{$uri}`, which is registered by nothing. Either the module "
                . 'renamed it -- in which case the guard is now inert on a live route -- or the '
                . 'entry should go.'
            );
        }
    }

    // =========================================================================================
    //  ANONYMOUS -- the headline, one case per route
    // =========================================================================================

    /**
     * AN ANONYMOUS CALLER CANNOT CANCEL AN ORDER. The worst of the six: `delivered_status` moves
     * to 2 and `payment_status` to 'Unpaid', and that second column is exactly what
     * `OrderObserver::updated` reacts to by calling `OrderFinancialService::syncFromOrder`. So
     * pre-fix this walked back a store's finance record with no principal at all.
     *
     * The finance half is not asserted here and the omission is deliberate rather than glossed:
     * `store_order_financials` is a GATE F table created by no migration, so the observer's own
     * try/catch swallows the query failure in any test database. These assertions pin the ORDER
     * columns, which are the trigger for it.
     */
    public function test_an_anonymous_caller_cannot_cancel_an_order_through_the_lottery_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $victim = $this->pendingOrder($storeB);
        $before = $this->row('orders', $victim->id);

        $response = $this->postJson('/lottery-order-status-change/' . $victim->id, [
            'delivered' => 'cancel',
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('orders', $victim->id),
            'An anonymous caller cancelled a store\'s order. payment_status moving to Unpaid is '
            . 'what drives the finance sync.');
    }

    /** AN ANONYMOUS CALLER CANNOT RETURN STOCK. */
    public function test_an_anonymous_caller_cannot_return_stock_through_the_lottery_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $victim = $this->pendingOrder($storeB);
        $before = $this->row('orders', $victim->id);

        $response = $this->postJson('/lottery-order-return', [
            'order_id' => $victim->id,
            'product_id' => 1,
            'variant_id' => 0,
        ]);

        /*
         * THE STATUS IS WHAT BITES HERE; THE ROW ASSERTION DOES NOT ISOLATE ANYTHING, and the
         * review was right to say so. The fixture's order is not delivered, so `product_return`
         * refuses on its own (app/Models/Order.php's delivered_status precondition) and the row
         * would be unchanged with or without the guard. The row check is kept as a belt, not
         * presented as the control.
         */
        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('orders', $victim->id));
    }

    /** AN ANONYMOUS CALLER CANNOT CHANGE AN AUCTION ORDER'S STATUS. */
    public function test_an_anonymous_caller_cannot_change_an_auction_order_status(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $id = $this->auctionOrder($storeB, ['delivered_status' => 0]);
        $before = $this->row('auction_product_orders', $id);

        $response = $this->postJson('/auction-order-status-change/' . $id, ['delivered' => 'cancel']);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('auction_product_orders', $id));
    }

    /** AN ANONYMOUS CALLER CANNOT RETURN AN AUCTION ORDER. */
    public function test_an_anonymous_caller_cannot_return_an_auction_order(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $id = $this->auctionOrder($storeB);
        $before = $this->row('auction_product_orders', $id);

        $response = $this->postJson('/auction-order-return', [
            'order_id' => $id,
            'product_id' => 1,
            'variant_id' => 0,
        ]);

        // As above: the STATUS is the control. The fixture is not delivered, so
        // AuctionProductOrder::product_return would refuse on its own.
        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('auction_product_orders', $id));
    }

    /**
     * AN ANONYMOUS CALLER CANNOT ASSIGN A DRIVER. `order_assign` writes `delivery_id` from
     * `$request->delivery_boy` with no validation of either value, so pre-fix this handed any
     * store's auction order to an arbitrary id.
     */
    public function test_an_anonymous_caller_cannot_assign_a_driver_to_an_auction_order(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $id = $this->auctionOrder($storeB);
        $before = $this->row('auction_product_orders', $id);

        $response = $this->postJson('/auction-order-assign', [
            'order_id' => $id,
            'delivery_boy' => 4242,
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('auction_product_orders', $id),
            'An anonymous caller assigned a delivery boy to another store\'s auction order.');
    }

    /**
     * AN ANONYMOUS CALLER CANNOT TOGGLE AN AUCTION SETTING. The least valuable write of the six
     * and still an anonymous settings mutation on any tenant, which is why it is a target rather
     * than an exemption.
     */
    public function test_an_anonymous_caller_cannot_toggle_an_auction_setting(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $id = $this->auctionSetting($storeB, 1);

        $response = $this->postJson('/update-auction-status', [
            'auctionId' => $id,
            'isActivated' => 'false',
        ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(1, (int) $this->row('auction_product_settings', $id)['is_active'],
            'An anonymous caller deactivated another store\'s auction setting.');
    }

    // =========================================================================================
    //  AUTHENTICATED BUT NOT ENTITLED
    // =========================================================================================

    /**
     * A MERCHANT HOLDING 'Manage Order' CANNOT CANCEL ANOTHER STORE'S ORDER.
     *
     * The permission is granted deliberately so that the permission limb cannot be what refuses
     * this -- the refusal has to come from the store comparison, which is the thing under test.
     */
    public function test_a_merchant_cannot_cancel_another_stores_order_through_the_lottery_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $victim = $this->pendingOrder($storeB);
        $before = $this->row('orders', $victim->id);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/lottery-order-status-change/' . $victim->id, ['delivered' => 'cancel']);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('orders', $victim->id));
    }

    /** A MERCHANT CANNOT TOGGLE ANOTHER STORE'S AUCTION SETTING. */
    public function test_a_merchant_cannot_toggle_another_stores_auction_setting(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        // GRANTED deliberately, so the permission limb cannot be what refuses this -- the
        // refusal has to come from the store comparison.
        $this->grantPermission($merchantA, 'Edit Auction Setting');

        $id = $this->auctionSetting($storeB, 1);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/update-auction-status', ['auctionId' => $id, 'isActivated' => 'false']);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(1, (int) $this->row('auction_product_settings', $id)['is_active']);
    }

    /**
     * A MERCHANT HOLDING NO ORDER PERMISSION CANNOT USE THE LOTTERY ROUTE ON THEIR OWN ORDER.
     *
     * Deliberately the caller's OWN order, so ownership cannot be the refuser: this isolates the
     * permission limb. It is the case that distinguishes authenticated from authorised, and it is
     * the ONLY test in this file that the store comparison alone cannot satisfy.
     */
    public function test_a_merchant_without_manage_order_cannot_use_the_lottery_status_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        /*
         * THE ROW MUST EXIST FOR THE LIMB TO FIRE, and that caught this test out when the limb
         * became existence-conditional: with no `Manage Order` row the guard correctly degrades
         * to ownership and the caller reaches their own order, so the test went red. In
         * PRODUCTION the row is always there -- `Utility::addNewData()` seeds it
         * (app/Models/Utility.php:2755) -- so the fixture, not the behaviour, was the artificial
         * part. Created here WITHOUT being granted, which is the state under test.
         */
        \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Order'],
            ['display_name' => 'Manage Order']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.

        $order = $this->pendingOrder($storeA);
        $before = $this->row('orders', $order->id);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/lottery-order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('orders', $order->id),
            'A merchant holding no order permission cancelled an order through the module route.');
    }

    // =========================================================================================
    //  THE LOAD-BEARING CASE: THE GUARD READS THE KEY THE HANDLER READS
    // =========================================================================================

    /**
     * A POSTED `id` CANNOT REACH AN ORDER THE URL DOES NOT NAME.
     *
     * `Request::__get` reads `all()` before the route parameters, so `$request->id` in the
     * handler resolves to the BODY when both are present. A guard that authorised the route
     * segment would therefore wave through a request that mutates a different row -- and that is
     * not hypothetical: P1-ORD-04 shipped exactly that bug for one commit, pinning the id for
     * the authorization while leaving two later sinks on the posted value.
     *
     * The URL names an order the caller genuinely owns and is genuinely entitled to cancel, so
     * every limb of the guard would pass on the segment. Only reading the body refuses this.
     */
    public function test_a_posted_id_cannot_reach_an_order_the_url_does_not_name(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $own = $this->pendingOrder($storeA);
        $victim = $this->pendingOrder($storeB);

        $ownBefore = $this->row('orders', $own->id);
        $victimBefore = $this->row('orders', $victim->id);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/lottery-order-status-change/' . $own->id, [
                'id' => $victim->id,
                'delivered' => 'cancel',
            ]);

        $this->assertSame(403, $response->status(),
            'The guard authorised the route segment while the handler would have acted on the '
            . 'posted id. Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($victimBefore, $this->row('orders', $victim->id),
            'The other store\'s order was cancelled via a posted id under a URL naming the '
            . 'caller\'s own order.');
        $this->assertSame($ownBefore, $this->row('orders', $own->id),
            'The refusal must be a refusal, not a redirection onto the caller\'s own order.');
    }

    /** The same precedence on the auction status route, which declares `{id}` too. */
    public function test_a_posted_id_cannot_reach_an_auction_order_the_url_does_not_name(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $own = $this->auctionOrder($storeA, ['delivered_status' => 0]);
        $victim = $this->auctionOrder($storeB, ['delivered_status' => 0]);
        $victimBefore = $this->row('auction_product_orders', $victim);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-status-change/' . $own, [
                'id' => $victim,
                'delivered' => 'cancel',
            ]);

        $this->assertSame(403, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($victimBefore, $this->row('auction_product_orders', $victim));
    }

    // =========================================================================================
    //  POSITIVE -- the guard must not break the feature
    // =========================================================================================

    /**
     * THE OWNER OF THE ORDER, HOLDING 'Manage Order', STILL CANCELS IT.
     *
     * Asserted on the HANDLER'S OWN WRITE rather than on the status code: `Order::order_status_change`
     * runs before the lottery handler's notification code, so the row moving is proof the guard
     * passed the request through even if the notification stack answers 500 for its own reasons.
     */
    public function test_a_merchant_can_cancel_their_own_order_through_the_lottery_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $order = $this->pendingOrder($storeA);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/lottery-order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $after = $this->row('orders', $order->id);

        $this->assertSame(2, (int) $after['delivered_status'],
            'The guard refused the order\'s own store. Status ' . $response->status()
            . ', body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame('Unpaid', (string) $after['payment_status']);
        $this->assertNotSame(403, $response->status());
    }

    /** THE PLATFORM OPERATOR IS NOT REFUSED, even though no role holds 'Manage Order'. */
    public function test_the_platform_operator_is_not_refused(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        $operator = $this->makePlatformAdmin();
        [, $storeB] = $this->makeTenant();
        $order = $this->pendingOrder($storeB);

        $response = $this->actingAs($operator->fresh())
            ->postJson('/lottery-order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $this->assertSame(2, (int) $this->row('orders', $order->id)['delivered_status'],
            'The platform operator was refused. On a database built from source NO role holds '
            . '\'Manage Order\', so checking the permission before the operator limb locks the '
            . 'operator out of the support workflow -- the lockout FinancePermission records. '
            . 'Status ' . $response->status() . ', body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertNotSame(403, $response->status());
    }

    /** A MERCHANT STILL TOGGLES THEIR OWN AUCTION SETTING -- the no-permission-limb route. */
    public function test_a_merchant_can_toggle_their_own_auction_setting(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Auction Setting');
        $id = $this->auctionSetting($storeA, 1);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/update-auction-status', ['auctionId' => $id, 'isActivated' => 'false']);

        $this->assertSame(0, (int) $this->row('auction_product_settings', $id)['is_active'],
            'The merchant was refused their own auction setting while holding the permission the '
            . 'guard requires. Status ' . $response->status() . ', body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertNotSame(403, $response->status());
    }

    // =========================================================================================
    //  FAIL-CLOSED AND INERTNESS
    // =========================================================================================

    /**
     * A MISSING TARGET TABLE DENIES; IT DOES NOT RAISE INTO THE HANDLER.
     *
     * This test deliberately does NOT call migrateModuleTables(), so `auction_product_orders`
     * does not exist -- the real state of a module whose routes are registered before its
     * migrations have run. Without the try/catch the `DB::table()` read raises a QueryException
     * into the exception handler; whether that reaches the unguarded handler depends on the
     * handler chain, and "depends" is not a control.
     */
    public function test_the_guard_refuses_when_the_target_table_is_absent(): void
    {
        $this->enableModules();

        $this->assertFalse(Schema::hasTable('auction_product_orders'),
            'The module table now exists without migrateModuleTables(), so this test no longer '
            . 'exercises the fail-closed limb.');

        [$merchantA] = $this->makeTenant();

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-assign', ['order_id' => 1, 'delivery_boy' => 7]);

        $this->assertSame(403, $response->status(),
            'An unreadable target table must deny. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /** A ROW THAT DOES NOT EXIST DENIES, and the response does not say which case it was. */
    public function test_an_unknown_row_is_refused_without_disclosing_why(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $foreign = $this->auctionOrder($storeB);

        $missing = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-assign', ['order_id' => 999999, 'delivery_boy' => 7]);
        $otherStore = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-assign', ['order_id' => $foreign, 'delivery_boy' => 7]);

        $this->assertSame(403, $missing->status());
        $this->assertSame(403, $otherStore->status());
        $this->assertSame(
            (string) $missing->getContent(),
            (string) $otherStore->getContent(),
            'The refusal body differs between "no such row" and "another store\'s row", which '
            . 'turns the guard into an existence oracle over every tenant\'s id space.'
        );
    }

    /**
     * THE GUARD IS INERT EVERYWHERE ELSE.
     *
     * It is appended to the whole `web` group, so a mistake in its first statements would refuse
     * the entire admin surface. The full Feature run is the real evidence for that; this pins two
     * explicit cases so a regression names itself here rather than as hundreds of unrelated
     * failures.
     *
     * THE SECOND CASE IS THE ONE THAT ISOLATES THE CONTROL, and the first version of this test
     * did not have it. A removal check measured that: with the early return replaced by a default
     * target, the suite stayed GREEN 23/23, because the only non-target route being driven was
     * `/order-status-change/{id}` -- the same table, the same permission, the same store and a
     * numeric id in the same key, so a non-inert guard allowed it for the same reasons the real
     * one ignores it. An id-less route is what a non-inert guard cannot help refusing: it reaches
     * `resolveId()`, finds nothing, and denies. `/dashboard` is that route.
     */
    public function test_the_guard_is_inert_on_a_non_target_route(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        // CASE 1: a non-target route that DOES look like a target -- the host order route the
        // module's declaration shadows. It must keep working.
        $order = $this->pendingOrder($storeA);

        $response = $this->actingAs($merchantA->fresh())
            ->post('/order-status-change/' . $order->id, ['delivered' => 'cancel']);

        $this->assertNotSame(403, $response->status(),
            'The guard refused a route it does not target. Its first statements must return '
            . 'early for any uri absent from TARGETS.');
        $this->assertSame(2, (int) $this->row('orders', $order->id)['delivered_status'],
            'The host route stopped working.');

        // CASE 2: a route carrying NO id at all, which only an inert guard can allow.
        $dashboard = $this->actingAs($merchantA->fresh())->get('/dashboard');

        $this->assertSame(200, $dashboard->status(),
            'The guard is refusing an ordinary admin page that names no row. It is appended to '
            . 'the whole `web` group, so it MUST return early for any uri absent from TARGETS -- '
            . 'otherwise every id-less request in the application is denied. Status '
            . $dashboard->status() . '.');
    }

    // =========================================================================================
    //  EQUIVALENCE WITH OrderPolicy, FOR THE TWO HOST-ORDER ROUTES
    // =========================================================================================

    /**
     * THE GUARD'S VERDICT MATCHES `OrderPolicy::updateStatus` ON THE HOST-ORDER ROUTES.
     *
     * Two mechanisms authorising the same table is the drift this ratchet exists to prevent: a
     * future tightening of the policy that is not mirrored here would leave the module route the
     * looser of the two, which is exactly the shape of the original defect.
     *
     * ONE DELIBERATE DIFFERENCE, stated rather than hidden: the guard normalises
     * `strtolower(trim($user->type))` before comparing to 'super admin', as CLAUDE.md prescribes,
     * while `OrderPolicy::isPlatformAdmin` compares the raw string. A user whose type were
     * 'Super Admin' would pass the guard and fail the policy. No such row is written anywhere in
     * the application, and loosening the policy to match is a separate change this package does
     * not make -- so the matrix below uses the canonical spelling and the difference is recorded
     * here rather than asserted away.
     */
    public function test_the_guard_verdict_matches_order_policy_for_the_host_order_routes(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        [$bare] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $storeA->id]);

        $own = $this->pendingOrder($storeA);
        $foreign = $this->pendingOrder($storeB);

        $cases = [
            'entitled owner, own order' => [$merchantA, $own],
            'entitled owner, foreign order' => [$merchantA, $foreign],
            'unentitled owner, own order' => [$bare, $own],
            'operator, foreign order' => [$operator, $foreign],
        ];

        foreach ($cases as $label => [$user, $order]) {
            $fresh = User::find($user->id);

            // The policy's verdict, taken through the application's own Gate so that any
            // registration or ordering change is included.
            $this->actingAs($fresh);
            $policy = \Illuminate\Support\Facades\Gate::forUser($fresh)
                ->allows('updateStatus', \App\Models\Order::find($order->id));

            /*
             * The guard's verdict, read off the response of the route it protects.
             *
             * PINNED TO 403 IN BOTH DIRECTIONS, because `!== 403` alone would silently invert
             * this ratchet: a refusal that ever arrived as 419 (CSRF) or 401 would register as
             * ALLOW and the two mechanisms could then diverge while the test stayed green. The
             * review raised exactly that. A status that is neither 403 nor a pass-through is a
             * failure here, not an "allow".
             */
            $status = $this->actingAs($fresh)
                ->postJson('/lottery-order-status-change/' . $order->id, ['delivered' => 'confirmed'])
                ->status();

            $this->assertNotContains($status, [401, 419],
                "{$label}: the response was {$status}, so something ahead of the guard refused "
                . 'this request and the comparison below would be meaningless.');

            $guard = $status !== 403;

            $this->assertSame($policy, $guard,
                "Guard and OrderPolicy disagree for: {$label}. Policy says "
                . ($policy ? 'ALLOW' : 'DENY') . ', guard says ' . ($guard ? 'ALLOW' : 'DENY')
                . '. Two mechanisms authorising `orders` must not drift.');
        }
    }

    // =========================================================================================
    //  CENSUS RATCHET
    // =========================================================================================

    /**
     * EVERY MODULE-DECLARED POST ON AN ORDER-MUTATION HANDLER IS A TARGET OR A REASONED EXEMPTION.
     *
     * "Two routes" was this task's first answer and it was wrong -- there are six -- because the
     * first pass followed the one route that led here from P1-INV-02's caller trace instead of
     * reading both route files. There are 70 vendored modules and any upstream release may add
     * another, so the completeness claim cannot be a one-time reading.
     *
     * KEYED ON THE HANDLER METHOD, not the uri, for the reason OrderDocumentAccessGuard's audit
     * recorded: a census keyed on how a uri is SPELLED cannot see the route whose spelling nobody
     * thought of, and that is the only kind that matters.
     *
     * WHAT IT DOES NOT COVER, NARROWED AFTER REVIEW. The first version of this docblock claimed
     * that "no route can be added to an EXISTING mutation handler without someone answering for
     * it here". That was too broad, and the review proved it by finding three live cross-tenant
     * writes the scan could not produce: `Route::resource` registers `update` and `destroy`
     * routes with no `Route::post(...)` line to match, and `$methods` named neither. Those three
     * are now raised as P1-INV-17 (#63) -- `AuctionProductSettingController::update` is the worst
     * of them, because it writes `store_id = getCurrentStore()` and so MOVES another store's row
     * into the caller's tenant.
     *
     * The scan below now covers `Route::resource` and both methods, so the three appear and carry
     * reasoned entries pointing at that issue. The honest statement of what remains uncovered:
     *   - a NEW handler method name is invisible, exactly as a new uri word is invisible to a uri
     *     census (which is why the sibling guard keeps both kinds);
     *   - a route declared across several lines, or through a variable, is invisible;
     *   - this says nothing about whether a route's EXISTING gating is sufficient -- only that
     *     somebody has written down an answer for it.
     */
    public function test_every_module_declared_order_mutation_post_is_guarded_or_exempt(): void
    {
        $targets = array_keys(
            (new \ReflectionClass(\App\Http\Middleware\ModuleOrderMutationGuard::class))
                ->getConstant('TARGETS')
        );

        // Handler methods that mutate an order, a shipment assignment, or a module setting.
        //
        // `update` and `destroy` are DELIBERATELY NOT HERE, and that was measured rather than
        // assumed: adding them made this scan match generic CRUD across seventeen other modules
        // -- 19 extra uris, every one of which would need an exemption saying nothing. A
        // platform-wide resource-route tenancy sweep is a real piece of work and it is #63's
        // acceptance criterion, not this ratchet's. Resource mutations are covered below instead,
        // scoped to the two modules this guard owns.
        $methods = [
            'order_status_change', 'order_return', 'order_assign', 'updateStatus',
            'order_payment_status', 'orderStatusChange', 'orderReturn', 'orderAssign',
        ];

        // Reasoned exemptions. Each says WHY, and each is verified below to still be produced by
        // the scan -- an exemption the scan cannot reach is documentation pretending to be a
        // control, which is the failure the sibling test's dead entries recorded.
        $exempt = [
            // `updateStatus` is a common method name, so the scan produces three routes in other
            // modules that have nothing to do with orders. Each enclosing group was READ, not
            // inferred from the uri:
            //
            // CountDownTimer/src/routes/web.php:17 opens
            // Route::middleware(['auth','web','PlanModuleCheck:CountDownTimer']) -- a principal
            // and a plan entitlement are both required.
            'update-count-down-status' => 'inside the module auth + PlanModuleCheck:CountDownTimer group',
            // Helpdesk/src/routes/web.php:19 opens
            // Route::middleware(['auth','web','PlanModuleCheck:Helpdesk']). It is also a helpdesk
            // STATUS LABEL, not an order.
            'update-status/{id}' => 'inside the module auth + PlanModuleCheck:Helpdesk group; a ticket status label, not an order',
            // LynomiaChat is first-party and declares its own permission on the route itself:
            // ->middleware(Laratrust\Middleware\Permission::class . ':Assign Lynomia Chat').
            'conversations/{conversation}/status' => 'carries Laratrust Permission:Assign Lynomia Chat on the route',

            /*
             * THE SIX Route::resource DECLARATIONS IN THESE TWO MODULES. Each update() and
             * destroy() body was READ; the reason below says which it is, and three of them say
             * OPEN rather than "already protected" -- because they are. An exemption that records
             * an open defect with an issue number is honest; silence would not be.
             *
             * They are NOT added to TARGETS because the vehicle differs: their id comes only from
             * the route parameter, `AuctionProductSettingController::update` needs its
             * `store_id = getCurrentStore()` write stopped rather than just its lookup scoped,
             * and the same sweep is owed to the other 68 modules.
             */
            'auctionproduct/{auctionproduct}' => 'OPEN -- update():861 and destroy():1509 reach the HOST Product by bare id, gated only on Edit/Delete Auction Product which the module seeder grants the merchant admin role; tracked at #63',
            'auctionsetting/{auctionsetting}' => 'OPEN -- update():115 has NO permission check and writes store_id = getCurrentStore(), so it MOVES another store\'s row into the caller\'s tenant; destroy():140 likewise; tracked at #63',
            'auctionorder/{auctionorder}' => 'OPEN -- destroy():105 deletes any store\'s auction order by bare id with no permission check (update():95 is a `//` stub); tracked at #63',
            /*
             * THESE THREE ENTRIES WERE MISLEADING AND ARE CORRECTED. Each said only that
             * `update()` and `destroy()` are `//` stubs -- TRUE of those two methods, and FALSELY
             * REASSURING ABOUT THE RESOURCE, because the dangerous method on `lotteryresult` is
             * `store()`, which no entry mentioned. A completeness review found it there:
             * LotteryResultController::store():66-193 takes `selected_codes` from request input,
             * resolves `PurchaseTicket::whereIn('lottery_code', ...)` with NO store predicate,
             * writes forged PAID host `orders` rows carrying another tenant's product id, and
             * `LotteryCustomer::insert()`s rows INTO the victim's tenant -- bypassing every global
             * scope. Raised as P1-INV-18 (#64).
             *
             * An exemption that is true about what it says and silent about what matters reads as
             * coverage. The new failure mode for this file's censuses, alongside "keyed on uri
             * words" and "keyed on Route::post lines": AN EXEMPTION SCOPED TO THE METHODS SOMEBODY
             * HAPPENED TO LOOK AT. Each entry now states which methods were read and which were
             * NOT.
             */
            'lotteryproduct/{lotteryproduct}' => 'update():165 and destroy():175 are `//` stubs. store() READ: LotteryPrizesController::store():107-124 IS store-scoped. index/show not audited.',
            'lotteryresult/{lotteryresult}' => 'update():221 and destroy():231 are `//` stubs. store():66-193 WAS a live cross-tenant host-order write (#64) and is now guarded by App\\Http\\Middleware\\LotteryDrawOwnershipGuard -- a different vehicle from this one, because its predicate is a SET of ticket codes rather than a row id. This entry said "OPEN" and "it is NOT protected" until that landed; a stale exemption reason is the same defect class this comment block exists to stop.',
            'lotteryorder/{lotteryorder}' => 'update():86 and destroy():96 are `//` stubs. index():33 is store-scoped but its permission check is COMMENTED OUT at :31,:36-38 -- not this guard\'s surface; noted so it is not mistaken for audited.',
        ];

        // THE EXEMPTIONS MUST BE REACHABLE. Three entries in the sibling test were uris its
        // regex could never produce -- they looked like controls and were not. Every key above
        // must still be produced by the scan, or it is documentation pretending to be a control.
        $exemptMustAppear = array_keys($exempt);

        $files = array_merge(
            glob(base_path('packages/workdo/*/src/routes/*.php')) ?: [],
            glob(base_path('packages/workdo/*/src/Routes/*.php')) ?: []
        );

        $this->assertGreaterThan(60, count($files),
            'The route-file scan found too few files to be meaningful.');

        $found = [];

        foreach ($files as $file) {
            $relative = str_replace(base_path() . '/', '', $file);

            foreach (preg_split('/\R/', (string) file_get_contents($file)) as $lineNo => $line) {
                // Whole-line comments are skipped. This is not hygiene: a CI ratchet in this
                // repository was made satisfiable by PROSE when a docblock happened to quote the
                // string it greps for, and the control was then deletable with the suite still
                // green. Any scan of source text in this repo strips comment lines.
                if (preg_match('/^\s*(\/\/|\*|#|\/\*)/', $line) === 1) {
                    continue;
                }

                // An explicit mutation route: Route::post('uri', [Controller::class, 'method']).
                if (preg_match(
                    '/Route::(?:post|any|match|put|patch)\s*\(\s*[\'"]([^\'"]*)[\'"]\s*,\s*\[[^\]]*?,\s*[\'"]('
                        . implode('|', array_map('preg_quote', $methods)) . ')[\'"]/',
                    $line,
                    $m
                ) === 1) {
                    $found[ltrim($m[1], '/')] = $relative . ':' . ($lineNo + 1) . ' -> ' . $m[2];
                    continue;
                }

                /*
                 * A Route::resource, which registers update() and destroy() with no line for the
                 * regex above to match. This is the gap that hid #63, and it is scoped to the two
                 * modules THIS GUARD covers -- widening it platform-wide is #63's sweep, not
                 * this test's (see the note on $methods above). The uris are synthesised the way
                 * Laravel registers them, `<uri>/{<singular>}`, so the entries below read as the
                 * real route table does.
                 */
                if (! preg_match('#packages/workdo/(AuctionProduct|LotteryPrizes)/#', $relative)) {
                    continue;
                }

                if (preg_match('/Route::resource\s*\(\s*[\'"]([^\'"]+)[\'"]\s*,\s*([A-Za-z0-9_]+)::class/', $line, $r) !== 1) {
                    continue;
                }

                $base = ltrim($r[1], '/');
                $found[$base . '/{' . str_replace('-', '_', $base) . '}'] =
                    $relative . ':' . ($lineNo + 1) . ' -> ' . $r[2] . '::update|destroy';
            }
        }

        $this->assertNotEmpty($found,
            'The scanner found no module order-mutation POSTs at all, so it would pass vacuously.');

        // A FLOOR, so a pattern edit that quietly stops matching cannot pass. Six uris match
        // today and all six are targets; the scan must never see fewer than six.
        $this->assertGreaterThanOrEqual(count($targets), count($found),
            'The scan matched fewer uris than there are guard targets, so the pattern has stopped '
            . "seeing routes it used to see:\n  " . implode("\n  ", array_keys($found)));

        $unguarded = [];

        foreach ($found as $uri => $where) {
            // The scan reads the DECLARED uri; the route table keys the uri with its parameters,
            // so a `{id}` suffix is compared both ways.
            if (in_array($uri, $targets, true) || in_array($uri . '/{id}', $targets, true)) {
                continue;
            }

            if (array_key_exists($uri, $exempt)) {
                continue;
            }

            $unguarded[$uri] = $where;
        }

        $this->assertSame([], $unguarded,
            "A module route file declares a POST onto an order-mutation handler that "
            . "App\\Http\\Middleware\\ModuleOrderMutationGuard does not target and that carries no "
            . "reasoned exemption:\n"
            . implode("\n", array_map(
                fn ($where, $uri) => "  {$uri}  ({$where})",
                $unguarded,
                array_keys($unguarded)
            ))
            . "\n\nAdd it to the guard's TARGETS -- with the request key its handler actually "
            . 'reads -- or to this test\'s $exempt list with the reason it is already protected.');

        foreach ($exemptMustAppear as $uri) {
            $this->assertArrayHasKey($uri, $found,
                "The exemption for '{$uri}' is dead: the scan no longer produces that uri.");
        }
    }

    /**
     * THE AUCTION PERMISSIONS ARE SEEDED BY THE MODULE, AND THE GUARD'S LIMB MATCHES WHAT THE
     * MODULE'S OWN CONTROLLER CHECKS.
     *
     * THIS TEST REPLACES ONE THAT COULD NOT FAIL. The first version asserted the opposite -- that
     * `Manage Auction Product Order` and `Manage Auction Product` are NOT seeded -- and scanned
     * only `app/Models/Utility.php`, on the premise that `Utility::addNewData()` is the only
     * mechanism creating Permission rows. The adversarial review refuted that premise and the
     * refutation was measured: the MODULE ships its own seeder, and the module enable path runs
     * it. So the old test was green while the condition it existed to detect was already true,
     * and the guard was leaving a within-tenant privilege gap on the strength of it.
     *
     * The lesson is the one about scans generally: a scan of ONE file answers a question about
     * that file, never about the application. This version reads the module's seeder and then
     * asserts the end state in the database.
     */
    public function test_the_auction_permissions_are_seeded_by_the_module_and_the_guard_requires_them(): void
    {
        $seeder = base_path('packages/workdo/AuctionProduct/src/database/seeders/PermissionTableSeeder.php');

        $this->assertFileExists($seeder,
            'The module permission seeder is gone. If upstream removed it, the guard\'s '
            . 'existence-conditional permission limb silently stops enforcing -- re-read the '
            . 'docblock.');

        $source = (string) file_get_contents($seeder);

        foreach (['Manage Auction Product Order', 'Edit Auction Setting'] as $permission) {
            $this->assertStringContainsString("'{$permission}'", $source,
                "The module seeder no longer creates `{$permission}`, which ModuleOrderMutationGuard "
                . 'requires on an auction route. With no row of that name the limb stops enforcing '
                . 'and only ownership is left.');
        }

        // AND THE END STATE, because the file containing a string is not the same question as a
        // row existing. The seeder grants to the global `admin` role, so that has to exist first.
        $this->makeGlobalAdminRole();
        $this->enableModules();

        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'Workdo\\AuctionProduct\\database\\seeders\\AuctionProductDatabaseSeeder',
            '--force' => true,
        ]);

        foreach (['Manage Auction Product Order', 'Edit Auction Setting'] as $permission) {
            $this->assertTrue(
                DB::table('permissions')->where('name', $permission)->exists(),
                "Running the module's own seeder did not create `{$permission}`."
            );
        }

        // The names the guard actually requires are the ones just asserted to exist.
        $targets = (new \ReflectionClass(\App\Http\Middleware\ModuleOrderMutationGuard::class))
            ->getConstant('TARGETS');

        $this->assertSame('Manage Auction Product Order',
            $targets['auction-order-status-change/{id}']['permission']);
        $this->assertSame('Edit Auction Setting', $targets['update-auction-status']['permission']);
    }

    /**
     * A MERCHANT HOLDING NO AUCTION PERMISSION CANNOT TOUCH THEIR OWN AUCTION ORDER.
     *
     * The within-tenant gap the review found, pinned. Deliberately the caller's OWN store, so
     * ownership cannot be the refuser. Requires the Permission ROW to exist, because the limb is
     * existence-conditional -- which is itself the thing the next test covers.
     */
    public function test_a_merchant_without_the_auction_permission_cannot_change_their_own_auction_order(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Manage Auction Product Order'],
            ['display_name' => 'Manage Auction Product Order']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.

        $id = $this->auctionOrder($storeA, ['delivered_status' => 0]);
        $before = $this->row('auction_product_orders', $id);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-status-change/' . $id, ['delivered' => 'cancel']);

        $this->assertSame(403, $response->status(),
            'An authenticated merchant holding no auction permission reached their own store\'s '
            . 'auction order. Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($before, $this->row('auction_product_orders', $id));
    }

    /** With the permission GRANTED, the same merchant gets through. The positive half. */
    public function test_a_merchant_holding_the_auction_permission_can_change_their_own_auction_order(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Auction Product Order');

        $id = $this->auctionOrder($storeA, ['delivered_status' => 0]);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-status-change/' . $id, ['delivered' => 'cancel']);

        $this->assertNotSame(403, $response->status(),
            'The guard refused a merchant who holds the permission and owns the row. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * WITH NO PERMISSION ROW AT ALL THE LIMB STOPS ENFORCING -- AND OWNERSHIP STILL REFUSES.
     *
     * This is the lockout guard, and it is the one branch that could be mistaken for a bypass, so
     * it is asserted in both directions: a merchant holding nothing reaches their OWN row (no
     * permanent 403, which is the lockout FinancePermission records), and the SAME merchant is
     * still refused another store's row (the degraded state is "authenticated and same-store",
     * never "open").
     */
    public function test_with_no_permission_row_the_limb_degrades_to_ownership_and_not_to_open(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        $this->assertFalse(
            DB::table('permissions')->where('name', 'Manage Auction Product Order')->exists(),
            'The permission row exists in this fixture, so this test is no longer exercising the '
            . 'no-row branch.'
        );

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $own = $this->auctionOrder($storeA, ['delivered_status' => 0]);
        $foreign = $this->auctionOrder($storeB, ['delivered_status' => 0]);
        $foreignBefore = $this->row('auction_product_orders', $foreign);

        $allowed = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-status-change/' . $own, ['delivered' => 'cancel']);
        $refused = $this->actingAs($merchantA->fresh())
            ->postJson('/auction-order-status-change/' . $foreign, ['delivered' => 'cancel']);

        $this->assertNotSame(403, $allowed->status(),
            'With no permission row the limb must not fire, or an install whose module was '
            . 'enabled before its seeder ran is locked out of its own auction orders forever. '
            . 'Body: ' . substr((string) $allowed->getContent(), 0, 300));
        $this->assertSame(403, $refused->status(),
            'The no-row branch skipped the OWNERSHIP limb too. That would be a bypass, not a '
            . 'lockout guard.');
        $this->assertSame($foreignBefore, $this->row('auction_product_orders', $foreign));
    }

    // =========================================================================================
    //  THE PRINCIPAL IS READ FROM THE `web` GUARD, NOT THE MUTABLE DEFAULT
    // =========================================================================================

    /**
     * A CUSTOMER PRINCIPAL IS REFUSED -- 403, NOT 500 AND NOT 200.
     *
     * The guard's most load-bearing stylistic choice is `auth('web')->user()` rather than
     * `auth()->user()`, and nothing in this file asserted it until the review pointed that out:
     * someone "simplifying" it would have had a green suite. The failure mode it prevents is
     * specific. With the default guard pointed at `customers`, `auth()->user()` returns an
     * `App\Models\Customer`, and on the auction routes -- where the permission limb may not fire
     * -- the request would reach `getCurrentStore($customer->id)`, which resolves the `User` whose
     * id happens to equal the customer id. A cross-principal id collision deciding a tenant
     * predicate.
     *
     * A 500 fails this test as loudly as a 200, because a crash is not a refusal.
     */
    public function test_a_customer_session_principal_is_refused_not_crashed(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [, $storeB] = $this->makeTenant();
        $setting = $this->auctionSetting($storeB, 1);

        $customer = \App\Models\Customer::create([
            'name' => 'Shopper',
            'email' => 'shopper' . uniqid() . '@example.test',
            'password' => bcrypt('secret-' . uniqid()),
            'store_id' => $storeB->id,
        ]);

        $response = $this->actingAs($customer, 'customers')
            ->postJson('/update-auction-status', ['auctionId' => $setting, 'isActivated' => 'false']);

        $this->assertSame(403, $response->status(),
            'A customer principal must be refused by the `web`-guard lookup. A 500 here means '
            . 'the guard read the mutable default guard and called a User method on a Customer. '
            . 'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(1, (int) $this->row('auction_product_settings', $setting)['is_active']);
    }

    // =========================================================================================
    //  THE ID SOURCES
    // =========================================================================================

    /**
     * THE QUERY STRING IS AN ID SOURCE TOO.
     *
     * `resolveId()` reads `$request->input()`, which includes the query bag -- and so does
     * `Request::__get` in the handler. A future narrowing to `$request->post()` would silently
     * drop this and leave the handler reading a value the guard never saw.
     */
    public function test_a_query_string_id_cannot_reach_another_stores_order(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $own = $this->pendingOrder($storeA);
        $victim = $this->pendingOrder($storeB);
        $victimBefore = $this->row('orders', $victim->id);

        $response = $this->actingAs($merchantA->fresh())
            ->postJson('/lottery-order-status-change/' . $own->id . '?id=' . $victim->id,
                ['delivered' => 'cancel']);

        $this->assertSame(403, $response->status(),
            'A query-string id reached past the guard. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame($victimBefore, $this->row('orders', $victim->id));
    }

    /**
     * A PRESENT-BUT-UNUSABLE ID DENIES; IT DOES NOT FALL THROUGH TO THE ROUTE SEGMENT.
     *
     * THE HOLE THIS CLOSES WAS LIVE, AND THIS TEST CANNOT PROVE IT CLOSED. `resolveId()`
     * originally required `is_numeric()` in both loops, so `id=5abc` was skipped by the body loop
     * and the route-parameter loop authorised the caller's own order -- while the handler passed
     * the raw string to `Order::find('5abc')`, which on MySQL coerces to 5 and returns ANOTHER
     * STORE'S ORDER. Found by the adversarial review of this guard.
     *
     * Under SQLite, `'5abc'` is not converted, so the victim row never matches and the pre-fix
     * code no-ops -- this test would therefore PASS against the hole if it asserted only that the
     * victim is unchanged. It asserts the STATUS, which is 403 only with the fix. The engine half
     * is proven where it can be:
     * tests/Support/Security/bin/verify-module-order-mutation-id-coercion-on-mysql.php, measured
     * on MySQL 8.0.46 -- `'5abc'` resolves to row 5 while the old predicate authorised 42.
     */
    public function test_a_present_but_unusable_id_denies_rather_than_falling_back(): void
    {
        $this->enableModules();
        $this->migrateModuleTables();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $own = $this->pendingOrder($storeA);
        $ownBefore = $this->row('orders', $own->id);

        foreach (['5abc', '0x5', 'abc', '5.9', '-1', '0', ['5']] as $value) {
            $response = $this->actingAs($merchantA->fresh())
                ->postJson('/lottery-order-status-change/' . $own->id, [
                    'id' => $value,
                    'delivered' => 'cancel',
                ]);

            $this->assertSame(403, $response->status(),
                'A present-but-unusable id fell through to the route segment for '
                . json_encode($value) . '. On MySQL the handler would coerce it to a row the '
                . 'guard never authorised. Body: '
                . substr((string) $response->getContent(), 0, 200));
        }

        $this->assertSame($ownBefore, $this->row('orders', $own->id),
            'The refusal must be a refusal, not a redirection onto the caller\'s own order.');
    }

}
