<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P1-INV-17 / #63 -- FOUR CROSS-TENANT WRITES BEHIND AuctionProduct's `Route::resource` ROUTES.
 * =============================================================================================
 *
 * REPRODUCED BY EXECUTION BEFORE ANYTHING WAS BUILT. Four of the five cases below wrote across
 * tenants on the first run against unfixed code: another store's product DELETED, another store's
 * auction setting MOVED INTO THE CALLER'S STORE, and another store's auction setting and auction
 * order both deleted.
 *
 * TWO THINGS MADE THE FIRST VERSION OF THIS FILE PROVE LESS THAN IT LOOKED LIKE IT DID, and both
 * are recorded because each is a way a security test passes for the wrong reason:
 *
 *   1. NO PLAN ENTITLEMENT. `PlanModuleCheck` refuses any non-operator whose
 *      `user_active_modules` rows do not include the module, so three of five cases "passed"
 *      without the request ever reaching a handler. See entitle().
 *   2. A VALIDATOR MISTAKEN FOR A CONTROL. The product-update case posted only `name`, and the
 *      handler answered `{"flag":"error","msg":"The category id field is required."}`. A
 *      validator is not an ownership check; all four required fields are attacker-supplied.
 *
 * THE PRODUCT UPDATE CANNOT BE REPRODUCED IN THIS SUITE AT ALL, AND THAT IS STATED RATHER THAN
 * GLOSSED. With the required fields supplied, `AuctionProductController::update` writes NULL into
 * `products.trending` and `products.track_stock` from unposted request fields. SQLite REFUSES the
 * write; production MySQL has `strict => false` (`config/database.php:55`) and COERCES, so the
 * cross-tenant write LANDS in production and cannot land here. This is the same engine-hiding
 * that CLAUDE.md records for P1-INV-01 / S37-1, in the same direction: **production is more
 * exposed than the test database.** So that case asserts the REFUSAL (403, which only the guard
 * produces) rather than the row, and the write itself is proven by
 * tests/Support/Security/bin/verify-module-resource-ownership-on-mysql.php.
 *
 * THE REMOVAL CHECKS, AS MEASURED (scripted, each control deleted alone, suite re-run, file
 * restored byte-for-byte). FOUR OF THESE TESTS EXIST BECAUSE A REMOVAL CHECK FOUND THEM MISSING:
 *
 *   R1  the bootstrap/app.php registration ........ 10 of 22 fail
 *   R2  the store comparison (trait) ..............  7 fail
 *   R3  the null-store fail-closed (trait) ........  1 fail -- only after
 *                                                    test_a_storeless_caller... was ADDED
 *   R4  the permission limb .......................  1 fail
 *   R5  operator-first ordering ...................  1 fail -- only after the operator test was
 *                                                    given a Permission ROW to check against
 *   R6  the route-parameter id source .............  1 fail
 *   R8  the fail-closed try/catch (trait) .........  1 fail -- only after
 *                                                    test_the_guard_refuses_when_the_target_table_is_absent
 *   R9  the uniform refusal body ..................  1 fail
 *   R10 method+uri keying .........................  1 fail -- only after
 *                                                    test_edit_permission_does_not_confer_delete
 *   R11 the inertness early return ................  1 fail
 *   R12 the permission-row existence check ........  1 fail
 *   R7  strict id parsing (trait) ................. GREEN -- not isolatable HERE
 *   R13 getCurrentStore($user->id) (trait) ........ GREEN -- covered by a SOURCE RATCHET
 *
 * TWO CONTROLS ARE NOT ISOLATED BY ANY TEST IN THIS FILE, AND BOTH SAY SO:
 *
 * R7 cannot be isolated here, and the reason is structural rather than a missing test. Strict id
 * parsing matters when the guard and the handler could disagree about which row a value denotes
 * -- which is what `'5abc'` did on the sibling guard, where the id comes from the request BODY and
 * the handler re-reads it. Here the id is the ROUTE PARAMETER and the handler receives the
 * identical string as its `$id` argument, so the two cannot diverge: anything the guard
 * mis-parses, the handler mis-parses the same way. The control is inherited from the shared trait,
 * where it IS isolated, and it is defence against a future entry that takes a body key.
 *
 * R13 is unreachable for the same shape of reason as on the sibling guard: passing the user id to
 * `getCurrentStore()` only matters when the DEFAULT guard holds a different principal from
 * `auth('web')`, and in that case the guard has already denied at its null check. It is pinned
 * instead by a source assertion in tests/Feature/Tenancy/OwnedPathRawQueryInventoryTest --
 * VERIFIED to bite.
 *
 * ALL ROW ASSERTIONS READ THE RAW ROW VIA `DB::table()`. `App\Models\Product` is Cachable and an
 * Eloquent read in a test can serve a mutated in-memory instance -- that produced a measured
 * false reproduction during P1-INV-04.
 */
class ModuleResourceOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function enableModules(): void
    {
        AddOnManager::create([
            'module' => 'AuctionProduct',
            'name' => 'Auction Product',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'auction-product',
        ]);

        Cache::flush();

        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AuctionProduct']);

        foreach (glob(base_path('packages/workdo/AuctionProduct/src/database/migrations/*.php')) ?: [] as $file) {
            (require $file)->up();
        }

        $this->assertTrue(Schema::hasTable('auction_product_settings'));
    }

    /**
     * THE PLAN ENTITLEMENT, WITHOUT WHICH THESE ROUTES REFUSE EVERYONE -- and the first version
     * of this file did not have it, so three of the five cases "passed" against unfixed code.
     *
     * `PlanModuleCheck` (app/Http/Middleware/PlanModuleCheck.php:34-80) refuses any principal
     * whose `type` is not 'super admin' unless `ActivatedModule()` contains the module, and that
     * resolves to a `user_active_modules` row keyed on the OWNER's id
     * (`activated_modules_for_owner`, app/Helper/helper.php:1360-1384). So the defects below are
     * reachable by a merchant WHOSE PLAN CARRIES AuctionProduct -- which is the realistic
     * condition, and the precondition this issue's severity rests on. A merchant without it is
     * redirected to the dashboard.
     *
     * The memo is a container `scoped()` binding, so it has to be seeded BEFORE the request that
     * reads it; inserting the row inside the acting call would be answered from an already
     * resolved memo.
     */
    private function entitle(\App\Models\User $owner): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id,
            'module' => 'AuctionProduct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertContains('AuctionProduct', ActivatedModule($owner->id),
            'The entitlement fixture did not take, so PlanModuleCheck would refuse every request '
            . 'below and each assertion would pass without reaching the handler.');
    }

    /** A product owned by a store, inserted raw so no model hook or cache is involved. */
    private function product(Store $store, array $attributes = []): int
    {
        return (int) DB::table('products')->insertGetId(array_merge([
            'name' => 'Victim product ' . uniqid(),
            'slug' => 'victim-' . uniqid(),
            'store_id' => $store->id,
            // NOT NULL with no default in the products migration (:29, :50).
            'track_stock' => 0,
            'created_by' => (int) $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));
    }

    private function setting(Store $store): int
    {
        return (int) DB::table('auction_product_settings')->insertGetId([
            'name' => 'Victim setting ' . uniqid(),
            'store_id' => $store->id,
            'is_active' => 1,
            'created_by' => (int) $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function auctionOrder(Store $store): int
    {
        return (int) DB::table('auction_product_orders')->insertGetId([
            'store_id' => $store->id,
            'product_json' => '[]',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** DEFECT 1a -- cross-tenant product EDIT through the auction resource. */
    public function test_a_merchant_cannot_edit_another_stores_product_through_the_auction_resource(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Auction Product');
        $this->entitle($merchantA);

        $victim = $this->product($storeB);
        $before = (array) DB::table('products')->where('id', $victim)->first();

        /*
         * THE VALIDATOR'S REQUIRED FIELDS ARE SUPPLIED, because a validator is not an ownership
         * control. The first version of this test posted only `name` and the handler answered
         * `{"flag":"error","msg":"The category id field is required."}` -- so the test PASSED
         * against unfixed code and proved nothing. `AuctionProductController::update:868-874`
         * requires name, category_id, status and variant_product; all four are attacker-supplied.
         */
        $response = $this->actingAs($merchantA->fresh())->put('/auctionproduct/' . $victim, [
            'name' => 'HIJACKED-BY-AUCTION',
            'category_id' => 1,
            'status' => 1,
            'variant_product' => 0,
        ]);

        $after = (array) DB::table('products')->where('id', $victim)->first();

        /*
         * THE STATUS IS THE CONTROL HERE, AND THE FIRST VERSION OF THIS TEST DID NOT ASSERT IT --
         * so it passed with the guard deleted, which a removal check measured. Three ways the row
         * assertions fail to bite, all of them live:
         *
         *   1. the handler writes NULL into products.trending and products.track_stock from
         *      unposted fields, and SQLite REFUSES that UPDATE, so `name` is unchanged whether or
         *      not the guard ran (on production MySQL, strict => false, it is NOT unchanged --
         *      see verify-module-resource-ownership-on-mysql.php);
         *   2. the handler never writes store_id at all, so that assertion could not move;
         *   3. the whole body is wrapped in `catch (\Exception $e) { return $msg; }`
         *      (AuctionProductController.php:1494-1497), and QueryException is an Exception, so
         *      the failure is swallowed and the response is HTTP 200 -- `assertNotSame(500, ...)`
         *      passed too.
         *
         * 403 is produced by nothing in this chain but the guard: the caller holds the permission
         * (so the Laratrust limb passes), is entitled (so PlanModuleCheck passes), and the row is
         * another store's (so the guard reaches deny('other store') -> abort(403)). The row
         * assertions are kept as corroboration, not as the control.
         */
        $this->assertSame(403, $response->status(),
            'The guard did not refuse a cross-store product update. The row assertions below '
            . 'CANNOT detect this -- SQLite refuses the handler\'s NULL write and its catch block '
            . 'turns the failure into a 200. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 300));
        $this->assertSame($before['name'], $after['name'],
            "Another store's product was renamed through the auction resource.");
        $this->assertSame((int) $before['store_id'], (int) $after['store_id'],
            "Another store's product changed store_id.");
    }

    /** DEFECT 1b -- cross-tenant product DELETE. The host catalogue, by bare id. */
    public function test_a_merchant_cannot_delete_another_stores_product_through_the_auction_resource(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Product');
        $this->entitle($merchantA);

        $victim = $this->product($storeB);

        $this->actingAs($merchantA->fresh())->delete('/auctionproduct/' . $victim);

        $this->assertNotNull(DB::table('products')->where('id', $victim)->first(),
            "Another store's product was DELETED through the auction resource.");
    }

    /** DEFECT 2 -- the worst shape: update() MOVES the row into the caller's store. */
    public function test_a_merchant_cannot_move_another_stores_auction_setting_into_their_own_store(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->entitle($merchantA);

        $victim = $this->setting($storeB);
        $before = (array) DB::table('auction_product_settings')->where('id', $victim)->first();

        $this->actingAs($merchantA->fresh())
            ->put('/auctionsetting/' . $victim, ['name' => 'STOLEN', 'start_date' => '2026-01-01']);

        $after = (array) DB::table('auction_product_settings')->where('id', $victim)->first();

        $this->assertSame((int) $storeB->id, (int) $after['store_id'],
            "Another store's auction setting was MOVED into the caller's store (" . $storeA->id
            . '). The victim can no longer see their own row and the attacker can.');
        $this->assertSame($before['name'], $after['name'],
            "Another store's auction setting was renamed.");
    }

    /** DEFECT 3 -- cross-tenant settings delete. */
    public function test_a_merchant_cannot_delete_another_stores_auction_setting(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->entitle($merchantA);
        $victim = $this->setting($storeB);

        $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $victim);

        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $victim)->first(),
            "Another store's auction setting was deleted.");
    }

    /** DEFECT 4 -- cross-tenant auction-order delete. */
    public function test_a_merchant_cannot_delete_another_stores_auction_order(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $this->entitle($merchantA);
        $victim = $this->auctionOrder($storeB);

        $this->actingAs($merchantA->fresh())->delete('/auctionorder/' . $victim);

        $this->assertNotNull(DB::table('auction_product_orders')->where('id', $victim)->first(),
            "Another store's auction order was deleted.");
    }

    // =========================================================================================
    //  THE LOAD-BEARING CASE: THE ID COMES FROM THE ROUTE PARAMETER, NOT THE BODY
    // =========================================================================================

    /**
     * A POSTED BODY KEY CANNOT MAKE THE GUARD AUTHORISE A ROW THE HANDLER WILL NOT TOUCH.
     *
     * This is the sibling guard's rule in MIRROR IMAGE, and getting it backwards here would be
     * the P1-ORD-04 bug again. `ModuleOrderMutationGuard` reads the BODY first because its
     * handlers read `$request->id` and `Request::__get` reads `all()` before route parameters.
     * These handlers take `$id` as a METHOD ARGUMENT -- `update(Request $request, $id)`,
     * `destroy($id)` -- so the route parameter is what they act on, and a guard that read a
     * posted `auctionsetting` key would authorise the caller's OWN row while the handler deleted
     * another store's.
     *
     * The body names a row the caller legitimately owns, so every limb of the guard would pass on
     * it. Only reading the route parameter refuses this.
     */
    public function test_a_posted_body_key_cannot_redirect_the_guard_off_the_route_parameter(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        $own = $this->setting($storeA);
        $victim = $this->setting($storeB);

        $response = $this->actingAs($merchantA->fresh())
            ->delete('/auctionsetting/' . $victim, ['auctionsetting' => $own]);

        $this->assertSame(403, $response->status(),
            'The guard authorised a posted body key while the handler acts on the route '
            . 'parameter. Status ' . $response->status() . '.');
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $victim)->first(),
            "Another store's auction setting was deleted via a body key.");
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $own)->first(),
            'The refusal must be a refusal, not a redirection onto the caller\'s own row.');
    }

    // =========================================================================================
    //  POSITIVES -- the guard must not break the feature
    // =========================================================================================

    /** The owner still deletes their OWN auction setting. */
    public function test_a_merchant_can_delete_their_own_auction_setting(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        $own = $this->setting($storeA);

        $response = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $own);

        $this->assertNull(DB::table('auction_product_settings')->where('id', $own)->first(),
            'The guard refused the owner their own auction setting. Status '
            . $response->status() . '.');
        $this->assertNotSame(403, $response->status());
    }

    /** The owner still renames their OWN auction setting, and it keeps its store. */
    public function test_a_merchant_can_update_their_own_auction_setting(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Auction Setting');
        $this->entitle($merchantA);

        $own = $this->setting($storeA);

        $response = $this->actingAs($merchantA->fresh())
            ->put('/auctionsetting/' . $own, ['name' => 'RENAMED-BY-OWNER']);

        $after = (array) DB::table('auction_product_settings')->where('id', $own)->first();

        $this->assertSame('RENAMED-BY-OWNER', $after['name'],
            'The guard refused the owner their own auction setting. Status '
            . $response->status() . '.');
        $this->assertSame((int) $storeA->id, (int) $after['store_id']);
        $this->assertNotSame(403, $response->status());
    }

    /** The owner still deletes their OWN auction order. */
    public function test_a_merchant_can_delete_their_own_auction_order(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Product Order');
        $this->entitle($merchantA);

        $own = $this->auctionOrder($storeA);

        $response = $this->actingAs($merchantA->fresh())->delete('/auctionorder/' . $own);

        $this->assertNull(DB::table('auction_product_orders')->where('id', $own)->first(),
            'The guard refused the owner their own auction order. Status '
            . $response->status() . '.');
        $this->assertNotSame(403, $response->status());
    }

    /** The owner still deletes their OWN product through the auction resource. */
    public function test_a_merchant_can_delete_their_own_product_through_the_auction_resource(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Product');
        $this->entitle($merchantA);

        $own = $this->product($storeA);

        $response = $this->actingAs($merchantA->fresh())->delete('/auctionproduct/' . $own);

        $this->assertNotSame(403, $response->status(),
            'The guard refused the owner their own product. Body: '
            . substr(strip_tags((string) $response->getContent()), 0, 300));
    }

    /** THE PLATFORM OPERATOR is not refused, even where no role holds the permission. */
    public function test_the_platform_operator_is_not_refused(): void
    {
        $this->enableModules();

        /*
         * THE PERMISSION ROW MUST EXIST FOR THE ORDERING TO BE LOAD-BEARING, and a removal check
         * caught this test out: with no row the limb is skipped anyway, so moving the operator
         * check after it changed nothing and the suite stayed green. Created here and granted to
         * nobody, which is the production state -- the module seeds it on enable and the
         * operator holds no merchant role.
         */
        \App\Models\Permission::firstOrCreate(
            ['name' => 'Delete Auction Setting'],
            ['display_name' => 'Delete Auction Setting']
        );

        $operator = $this->makePlatformAdmin();
        [, $storeB] = $this->makeTenant();
        $victim = $this->setting($storeB);

        // getCurrentStore() returns a hardcoded 1 for a super admin with no current_store
        // (app/Helper/helper.php:403-404), so the operator is pointed at the victim's store to
        // isolate the OPERATOR limb from the store comparison.
        $operator->current_store = $storeB->id;
        $operator->save();

        $response = $this->actingAs($operator->fresh())->delete('/auctionsetting/' . $victim);

        $this->assertNotSame(403, $response->status(),
            'The platform operator was refused. On a database built from source no role holds '
            . 'these permissions, so checking the permission before the operator limb locks the '
            . 'operator out of the support workflow -- the lockout FinancePermission records.');
    }

    // =========================================================================================
    //  PERMISSION LIMB
    // =========================================================================================

    /**
     * A MERCHANT HOLDING NO AUCTION-SETTING PERMISSION CANNOT DELETE THEIR OWN SETTING.
     *
     * Deliberately the caller's OWN row, so ownership cannot be the refuser. This is also the
     * case where the guard is STRICTER than the handler: `AuctionProductSettingController` checks
     * no permission anywhere, so before this guard an entitled merchant's staff could delete
     * settings with no permission at all. A guard that dropped the check because the handler had
     * none would be a regression.
     */
    public function test_a_merchant_without_the_setting_permission_cannot_delete_their_own_setting(): void
    {
        $this->enableModules();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Delete Auction Setting'],
            ['display_name' => 'Delete Auction Setting']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.
        $this->entitle($merchantA);

        $own = $this->setting($storeA);

        $response = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $own);

        $this->assertSame(403, $response->status());
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $own)->first(),
            'A merchant holding no permission deleted an auction setting.');
    }

    /**
     * WITH NO PERMISSION ROW AT ALL THE LIMB STOPS ENFORCING -- AND OWNERSHIP STILL REFUSES.
     *
     * The lockout guard, asserted in both directions, as on the sibling guard: a merchant holding
     * nothing reaches their OWN row (so an install whose module was enabled before its seeder ran
     * is not locked out forever), and the same merchant is still refused another store's row (so
     * the degraded state is "authenticated and same-store", never "open").
     */
    public function test_with_no_permission_row_the_limb_degrades_to_ownership_and_not_to_open(): void
    {
        $this->enableModules();

        $this->assertFalse(
            DB::table('permissions')->where('name', 'Delete Auction Setting')->exists(),
            'The permission row exists in this fixture, so this test no longer exercises the '
            . 'no-row branch.'
        );

        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->entitle($merchantA);

        $own = $this->setting($storeA);
        $foreign = $this->setting($storeB);

        $allowed = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $own);
        $refused = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $foreign);

        $this->assertNull(DB::table('auction_product_settings')->where('id', $own)->first(),
            'With no permission row the limb must not fire. Status ' . $allowed->status() . '.');
        $this->assertSame(403, $refused->status(),
            'The no-row branch skipped the OWNERSHIP limb too. That would be a bypass, not a '
            . 'lockout guard.');
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $foreign)->first());
    }

    // =========================================================================================
    //  FAIL-CLOSED, INERTNESS, AND THE ROUTE-TABLE RATCHET
    // =========================================================================================

    /** A row that does not exist denies, with the same body as another store's row. */
    public function test_an_unknown_row_is_refused_without_disclosing_why(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        $foreign = $this->setting($storeB);

        $missing = $this->actingAs($merchantA->fresh())
            ->deleteJson('/auctionsetting/999999');
        $otherStore = $this->actingAs($merchantA->fresh())
            ->deleteJson('/auctionsetting/' . $foreign);

        $this->assertSame(403, $missing->status());
        $this->assertSame(403, $otherStore->status());
        $this->assertSame(
            (string) $missing->getContent(),
            (string) $otherStore->getContent(),
            'The refusal body differs between "no such row" and "another store\'s row", which '
            . 'turns the guard into an existence oracle over every tenant\'s id space.'
        );
    }

    /** A non-numeric id denies rather than reaching a handler that would coerce it. */
    public function test_an_unusable_id_denies(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        foreach (['5abc', '0x5', '-1', '0'] as $value) {
            $response = $this->actingAs($merchantA->fresh())
                ->deleteJson('/auctionsetting/' . $value);

            $this->assertSame(403, $response->status(),
                'An unusable id reached the handler for ' . var_export($value, true)
                . '. On MySQL `WHERE id = \'5abc\'` resolves to row 5 -- see '
                . 'verify-module-order-mutation-id-coercion-on-mysql.php.');
        }
    }

    /**
     * THE GUARD IS INERT EVERYWHERE ELSE, including on the GET (show) route of the very same uri.
     *
     * It is appended to the whole `web` group and keyed on METHOD+uri, so this is the assertion
     * that the method keying actually works: a GET to `auctionsetting/{id}` must not be refused
     * by a guard that only claims the write methods. The id-less `/dashboard` case covers the
     * other direction, as it does for the sibling guard -- a non-inert guard cannot help refusing
     * a route that names no row.
     */
    public function test_the_guard_is_inert_on_a_non_target_method_and_route(): void
    {
        $this->enableModules();

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Auction Setting');
        $this->entitle($merchantA);

        $own = $this->setting($storeA);

        $show = $this->actingAs($merchantA->fresh())->get('/auctionsetting/' . $own);
        $this->assertNotSame(403, $show->status(),
            'The guard refused a GET on a uri whose write methods it targets. TARGETS is keyed on '
            . 'METHOD+uri precisely so that reads are untouched.');

        $dashboard = $this->actingAs($merchantA->fresh())->get('/dashboard');
        $this->assertSame(200, $dashboard->status(),
            'The guard is refusing an ordinary admin page that names no row. It is appended to '
            . 'the whole `web` group, so it MUST return early for any method+uri absent from '
            . 'TARGETS.');
    }

    /**
     * EVERY GUARD TARGET IS A REAL REGISTERED ROUTE, AND EVERY GUARDED RESOURCE MUTATION IS A
     * TARGET.
     *
     * Both directions. A target whose method+uri no longer exists is dead configuration that
     * looks like a control -- the failure mode OrderDocumentRouteResolutionTest's dead exemptions
     * recorded. A registered resource mutation that is not a target is the exposure returning,
     * which is how #63 came to exist in the first place.
     */
    public function test_the_guard_targets_and_the_route_table_agree(): void
    {
        $this->enableModules();

        $targets = array_keys(
            (new \ReflectionClass(\App\Http\Middleware\ModuleResourceOwnershipGuard::class))
                ->getConstant('TARGETS')
        );

        $this->assertCount(9, $targets);

        $registered = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            foreach ($route->methods() as $method) {
                $registered[$method . ' ' . $route->uri()] = app('router')
                    ->gatherRouteMiddleware($route);
            }
        }

        foreach ($targets as $key) {
            $this->assertArrayHasKey($key, $registered,
                "The guard targets `{$key}`, which is registered by nothing. Either the module "
                . 'renamed the resource -- in which case the guard is now inert on a live route '
                . '-- or the entry should go.');
            $this->assertContains(
                \App\Http\Middleware\ModuleResourceOwnershipGuard::class,
                $registered[$key],
                "`{$key}` does not carry the guard."
            );
        }

        /*
         * THE OTHER DIRECTION, KEYED ON THE CONTROLLER ACTION RATHER THAN THE URI.
         *
         * The first version matched `^(PUT|PATCH|DELETE) (auctionproduct|auctionsetting|...)/` --
         * anchored at the start of the uri, so any future declaration of the same actions under a
         * PREFIX was invisible to it. That is not hypothetical for this module: its own
         * RouteServiceProvider already loads a second route file under `prefix('api')`, and a
         * `Route::prefix('admin')->resource('auctionproduct', ...)` would register
         * `PUT admin/auctionproduct/{auctionproduct}` and sail past the check. It is also exactly
         * the class OrderDocumentAccessGuard's `order/{order}` entry records -- a SECOND URI ON
         * THE SAME HANDLER.
         *
         * Keyed on the action, a prefix, a rename and a second uri are all caught.
         */
        $guardedActions = [
            'AuctionProductController@update', 'AuctionProductController@destroy',
            'AuctionProductSettingController@update', 'AuctionProductSettingController@destroy',
            'AuctionOrdersController@update', 'AuctionOrdersController@destroy',
        ];

        $unguarded = [];
        $actionsSeen = [];

        foreach (\Illuminate\Support\Facades\Route::getRoutes() as $route) {
            $action = $route->getActionName();

            foreach ($guardedActions as $guarded) {
                if (! str_ends_with($action, $guarded)) {
                    continue;
                }

                $actionsSeen[$guarded] = true;

                foreach ($route->methods() as $method) {
                    if (! in_array($method, ['PUT', 'PATCH', 'DELETE'], true)) {
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
            "A route reaches an auction mutation handler that this guard does not target. A "
            . "second uri, a prefix or a rename will land here:\n  "
            . implode("\n  ", $unguarded));

        // THE SCAN MUST BE REACHABLE. Six action names are listed; if a controller is renamed
        // upstream the loop above silently matches nothing and passes vacuously.
        $this->assertSame(
            array_values($guardedActions),
            array_keys($actionsSeen),
            'The action scan no longer produces every guarded handler, so it would pass without '
            . 'checking anything. Found: [' . implode(', ', array_keys($actionsSeen)) . ']'
        );

        /*
         * AND THE PREMISE OF THE ANONYMOUS LIMB. The guard refuses a null principal, and its
         * docblock keeps that limb because "the group has auth today" is a property of a vendored
         * file upstream is free to change -- which is exactly how the sibling guard's six routes
         * came to be anonymous. Nothing asserted that premise, so this does: if these routes ever
         * LOSE Authenticate, this reddens and the limb stops being belt-and-braces.
         */
        foreach ($targets as $key) {
            $this->assertContains(
                \Illuminate\Auth\Middleware\Authenticate::class,
                $registered[$key],
                "`{$key}` no longer carries Authenticate. The guard's own anonymous limb now "
                . 'carries the whole authentication requirement for it -- re-read the guard '
                . 'docblock, which currently describes that limb as belt-and-braces.'
            );
        }
    }

    /**
     * THE PERMISSION NAMES THIS GUARD REQUIRES ARE THE ONES THE MODULE SEEDS.
     *
     * The limb is existence-conditional, so a name the module does not seed would silently stop
     * enforcing rather than fail loudly. That is the shape of the false premise the sibling
     * guard shipped with -- a claim about seeding that no test could falsify -- so here the names
     * are checked against the seeder AND against the table after running it.
     */
    public function test_every_permission_the_guard_requires_is_seeded_by_the_module(): void
    {
        $required = array_values(array_unique(array_filter(array_map(
            fn ($t) => $t['permission'],
            (new \ReflectionClass(\App\Http\Middleware\ModuleResourceOwnershipGuard::class))
                ->getConstant('TARGETS')
        ))));

        sort($required);
        $this->assertSame([
            'Delete Auction Product',
            'Delete Auction Product Order',
            'Delete Auction Setting',
            'Edit Auction Product',
            'Edit Auction Setting',
            'Manage Auction Product Order',
        ], $required);

        $this->makeGlobalAdminRole();
        $this->enableModules();

        \Illuminate\Support\Facades\Artisan::call('db:seed', [
            '--class' => 'Workdo\\AuctionProduct\\database\\seeders\\AuctionProductDatabaseSeeder',
            '--force' => true,
        ]);

        foreach ($required as $permission) {
            $this->assertTrue(
                DB::table('permissions')->where('name', $permission)->exists(),
                "The module's own seeder does not create `{$permission}`, which this guard "
                . 'requires. With no row of that name the limb stops enforcing and only ownership '
                . 'is left.'
            );
        }
    }

    /**
     * A ROW WITH A NULL store_id IS NOT MUTABLE BY ANYONE BUT THE OPERATOR.
     *
     * `auction_product_settings.store_id` is `nullable()` in the module's own migration (`:25`),
     * so this is a real row shape and not a hypothetical. Such a row is invisible to every
     * `where('store_id', ...)` predicate in the application -- its owning tenant cannot see it --
     * and it must not become reachable through this guard either.
     *
     * A removal check added this test: deleting the trait's `empty($rowStore)` limb left the
     * suite green at 18/18, because nothing here had a NULL-store row.
     */
    public function test_a_row_with_no_store_is_refused(): void
    {
        $this->enableModules();

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        $orphan = (int) DB::table('auction_product_settings')->insertGetId([
            'name' => 'Orphan setting',
            'store_id' => null,
            'is_active' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $orphan);

        $this->assertSame(403, $response->status(),
            'A row with a NULL store_id was reachable. It is invisible to every store_id '
            . 'predicate in the application, so the guard must fail closed on it rather than let '
            . 'whichever tenant asks first claim it.');
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $orphan)->first());
    }

    /**
     * A STORELESS CALLER CANNOT CLAIM A STORELESS ROW. 0 === 0 IS NOT OWNERSHIP.
     *
     * This is the combination the `empty()` limb actually exists for, and a removal check is what
     * found that out. Deleting the limb left the test above green, because `(int) null === (int) 1`
     * is `0 === 1` and the cast comparison refuses anyway -- so that case is SUBSUMED. But when
     * BOTH sides are absent the comparison becomes `0 === 0` and ALLOWS. Both sides absent is a
     * real pair of states: `auction_product_settings.store_id` is nullable in the module's
     * migration, and `getCurrentStore()` returns null for a merchant with no `current_store` and
     * no store of their own (app/Helper/helper.php:396-406).
     *
     * So this test, not the one above, is what isolates the limb -- and a test that looked
     * sufficient was not.
     */
    public function test_a_storeless_caller_cannot_claim_a_storeless_row(): void
    {
        $this->enableModules();

        // A merchant with NO current_store and NO store of their own, so getCurrentStore() is null.
        $storeless = $this->makeMerchant();
        $this->grantPermission($storeless, 'Delete Auction Setting');

        DB::table('user_active_modules')->insert([
            'user_id' => $storeless->id,
            'module' => 'AuctionProduct',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertEmpty(getCurrentStore($storeless->id),
            'The fixture resolved a store, so this test no longer exercises the both-sides-absent '
            . 'case.');

        $orphan = (int) DB::table('auction_product_settings')->insertGetId([
            'name' => 'Orphan setting',
            'store_id' => null,
            'is_active' => 1,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($storeless->fresh())->delete('/auctionsetting/' . $orphan);

        $this->assertSame(403, $response->status(),
            'A caller with no store claimed a row with no store. Without the empty() limb the '
            . 'comparison is `0 === 0`, which is not ownership -- it is two absences matching.');
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $orphan)->first());
    }

    /**
     * A MISSING TARGET TABLE DENIES; IT DOES NOT RAISE INTO THE HANDLER.
     *
     * This test deliberately does NOT run the module migrations, which is the real state of a
     * module whose routes are registered before its migration step has run. Without the trait's
     * try/catch the `DB::table()` read raises a QueryException into the exception handler, and
     * whether that reaches the unguarded handler depends on the handler chain -- "depends" is not
     * a control.
     *
     * A removal check added this test too: the try/catch could be deleted with the suite green,
     * because every other case here migrates the tables first.
     */
    public function test_the_guard_refuses_when_the_target_table_is_absent(): void
    {
        // The module is enabled WITHOUT its migrations, so the routes exist and the table does not.
        AddOnManager::create([
            'module' => 'AuctionProduct',
            'name' => 'Auction Product',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'auction-product',
        ]);
        Cache::flush();
        $provider = new \App\Providers\PackageServiceProvider($this->app);
        $loadAddons = new \ReflectionMethod($provider, 'loadAddons');
        $loadAddons->setAccessible(true);
        $loadAddons->invoke($provider, ['AuctionProduct']);

        $this->assertFalse(Schema::hasTable('auction_product_settings'),
            'The module table exists, so this test no longer exercises the fail-closed limb.');

        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Delete Auction Setting');
        $this->entitle($merchantA);

        $response = $this->actingAs($merchantA->fresh())->deleteJson('/auctionsetting/1');

        $this->assertSame(403, $response->status(),
            'An unreadable target table must deny. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * THE PERMISSION IS PER METHOD, NOT PER URI, AND THIS IS THE CASE THAT PROVES IT.
     *
     * `Route::resource` puts PUT, PATCH, DELETE and GET on one uri, and `TARGETS` is keyed on
     * METHOD+uri so each can require its own permission -- `Edit Auction Setting` for the writes
     * that modify, `Delete Auction Setting` for the one that destroys. A guard keyed on uri alone
     * would hand all of them whichever permission happened to be first.
     *
     * The caller holds EDIT and owns the row, so ownership and entitlement both pass; only the
     * per-method permission refuses the delete. A removal check added this test as well.
     */
    public function test_edit_permission_does_not_confer_delete_on_the_same_uri(): void
    {
        $this->enableModules();

        \App\Models\Permission::firstOrCreate(
            ['name' => 'Delete Auction Setting'],
            ['display_name' => 'Delete Auction Setting']
        );

        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Auction Setting');
        $this->entitle($merchantA);

        $own = $this->setting($storeA);

        // The write they ARE entitled to still works.
        $update = $this->actingAs($merchantA->fresh())
            ->put('/auctionsetting/' . $own, ['name' => 'RENAMED']);
        $this->assertNotSame(403, $update->status(),
            'The holder of Edit Auction Setting was refused an update.');

        // The one they are NOT entitled to does not.
        $delete = $this->actingAs($merchantA->fresh())->delete('/auctionsetting/' . $own);

        $this->assertSame(403, $delete->status(),
            'Holding Edit Auction Setting conferred DELETE on the same uri. TARGETS is keyed on '
            . 'METHOD+uri precisely so that it does not.');
        $this->assertNotNull(DB::table('auction_product_settings')->where('id', $own)->first());
    }
}
