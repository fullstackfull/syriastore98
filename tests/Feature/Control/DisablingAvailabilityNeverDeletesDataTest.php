<?php

namespace Tests\Feature\Control;

use App\Classes\Module;
use App\Models\AddOnManager;
use App\Models\Category;
use App\Models\Plan;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;

/**
 * `CTRL-004` — "عدم حذف بيانات ميزة بمجرد إيقاف إتاحتها".
 * =================================================================================================
 *
 * *Do NOT delete a feature's data merely because its availability was disabled.*
 * `docs/development/master-plan/DECISION-ID-REGISTER.md:252`, Phase 06 §06-H.
 *
 * MEASURED STATE BEFORE THIS FILE: the guarantee HELD and **nothing asserted it**. So this is a
 * missing-verification row, not a missing-functionality one, and that distinction is the whole
 * reason the file is written this way — a test for an invariant that is already true has to be
 * built so that breaking the invariant breaks the test, because there is no red run to start from.
 *
 * -------------------------------------------------------------------------------------------------
 * THE FOUR PATHS THAT CHANGE AVAILABILITY, and which ones are in scope.
 *
 * | path | what it writes | in scope |
 * |---|---|---|
 * | `Module::disable()` via `POST /modules-enable` | `add_on_managers.is_enable` 1 → 0 | **yes** |
 * | `User::assignPlan()` | `user_active_modules` rows; `users.is_active`, `stores.is_active` | **yes** |
 * | `PlanController::update()` with fewer modules | `user_active_modules` rows for every holder | **yes** |
 * | `ModuleController::remove()` | permissions, the add-on row, the package directory | **no** |
 *
 * `remove()` IS AN UNINSTALL, NOT A DISABLE, and conflating the two would make this test assert
 * something the decision does not say. `CTRL-004` is about *إيقاف إتاحتها* — switching availability
 * off. Deleting the application is a separate, deliberate operator act with its own guard
 * (`App\Support\Modules\RemovableModule`, and `docs/development/phase-6/PHASE-06-MODULE-REMOVAL-FINDINGS.md`).
 * Recorded here so that a later reader does not "complete" this file by driving it.
 *
 * -------------------------------------------------------------------------------------------------
 * WHY THE ASSERTION IS "NO TABLE SHRANK" AND NOT A LIST OF TABLES TO CHECK.
 *
 * CLAUDE.md: *"WHEN A CENSUS HUNTS FOR SOMETHING DANGEROUS, ENUMERATE THE SAFE SET INSTEAD. A list
 * of dangerous spellings is always short by the one nobody thought of."* A test naming the tables a
 * disable must not touch is exactly such a list: it is blind to the 71st table, and this schema has
 * hundreds. So the direction is inverted. Every table in the database is snapshotted, the
 * availability change is driven through the real path, and **no table may hold fewer rows
 * afterwards** except the ones named in `AVAILABILITY_TABLES` — which is the safe set, is three
 * entries long, and is the thing a reviewer can actually check.
 *
 * GROWTH IS NOT A VIOLATION. A disable may legitimately write an audit row, a session, a log. Only
 * a *decrease* is the thing `CTRL-004` forbids, so only a decrease fails, and the test needs no
 * exclusion list for sessions, caches or jobs. That is a deliberate simplification and it is also
 * what makes the assertion stable across whatever else the request happens to write.
 *
 * THE PREMISE IS ASSERTED SEPARATELY, AND IT IS THE HALF THAT ROTS. "No table shrank" passes
 * perfectly when the handler did nothing at all — a 403, a redirect from a gate, a `Module::find()`
 * that cached a stale row. CLAUDE.md records that a merchant with an empty `add_on_managers` is
 * redirected to the dashboard with **HTTP 200**, "which a status-code assertion photographs as the
 * screen". So every case below asserts, first, that the availability really changed, and only then
 * that nothing was deleted. A mutation that turns the disable into a no-op fails on the premise.
 *
 * AND THE FIXTURE HAS TO CARRY ROWS FOR THE INVARIANT TO HAVE TEETH. A deletion of products cannot
 * be detected in a database with no products. `assertFixtureIsPopulated()` requires a floor on the
 * number of populated tables, so emptying the fixture fails the test rather than silently
 * weakening it.
 *
 * `Module::find()` IS `Cache::rememberForever` — CLAUDE.md's own warning, and it bites here
 * directly: a row inserted after the first `find()` call changes nothing anything reads. Every
 * fixture below inserts the `add_on_managers` row and then forgets the module's cache key, which is
 * the module NAME because that is the key `Module::find()` uses.
 */
class DisablingAvailabilityNeverDeletesDataTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * THE SAFE SET: the tables whose rows ARE availability, so losing one is the disable working.
     *
     *  - `add_on_managers` — one global row per application; `is_enable` is the operator's switch.
     *    Listed because `ModuleController::remove()` deletes from it, not because a *disable* does;
     *    `Module::disable()` only flips the flag, and `test_disabling_an_application_only_flips_the_flag`
     *    asserts the row survives, which is the stricter claim.
     *  - `user_active_modules` — per-OWNER entitlement. `PHASE-06-SAAS-LIFECYCLE-FINDINGS.md` §2
     *    states the distinction this decision turns on: *"`user_active_modules` is availability, not
     *    data"*. A plan change rewrites it by design.
     *  - `permission_role` — `userActiveModule` churn does not touch it, but a role's grants are
     *    availability rather than merchant data and the plan paths below may re-seed them. Kept so
     *    that a legitimate re-seed is not reported as data loss.
     *
     * Nothing else may shrink. If a future change needs a fourth entry, that is a `CTRL-004`
     * conversation and not a one-line edit.
     */
    private const AVAILABILITY_TABLES = [
        'add_on_managers',
        'user_active_modules',
        'permission_role',
    ];

    /**
     * How many tables must already hold rows before an availability change is driven.
     *
     * NOT A ROUND NUMBER AND NOT A GUESS: `makeTenant()` plus the catalogue, settings and plan rows
     * below populate more than this, measured on a fresh `RefreshDatabase`. The floor exists so
     * that deleting half the fixture fails the test instead of making it vacuous, which is the
     * failure mode CLAUDE.md records as "a test that passes for the wrong reason".
     */
    private const POPULATED_TABLE_FLOOR = 6;

    /**
     * A REAL module directory, because `ModuleController::enable()` gates on `Module::has()`, which
     * reads the on-disk package directories rather than any table. An invented name is refused
     * before the handler reaches anything this test is about.
     */
    private const REAL_MODULE = 'ProductReview';

    // ---------------------------------------------------------------------------------------------
    // the instrument
    // ---------------------------------------------------------------------------------------------

    /**
     * Row counts for every table that currently holds at least one row.
     *
     * RAW `DB::table()` COUNTS, NOT ELOQUENT. 79 of the 95 models in `app/Models/` are `Cachable`
     * and the suite runs `CACHE_STORE=array`, so an Eloquent count taken before the act can be
     * served from the memo afterwards — CLAUDE.md records three measured false readings from
     * exactly that. A raw count asks the table.
     *
     * Tables holding nothing are omitted: a table that was empty cannot shrink, and listing them
     * would only make the diff noisy.
     *
     * @return array<string, int>
     */
    private function populatedTableCounts(): array
    {
        $counts = [];

        foreach (Schema::getTables() as $table) {
            $name = $table['name'] ?? null;

            if (! is_string($name) || $name === '' || str_starts_with($name, 'sqlite_')) {
                continue;
            }

            $rows = (int) DB::table($name)->count();

            if ($rows > 0) {
                $counts[$name] = $rows;
            }
        }

        ksort($counts);

        return $counts;
    }

    private function assertFixtureIsPopulated(array $before): void
    {
        $this->assertGreaterThanOrEqual(
            self::POPULATED_TABLE_FLOOR,
            count($before),
            'The fixture populated only ' . count($before) . ' tables, below the floor of '
            . self::POPULATED_TABLE_FLOOR . '. CTRL-004 is asserted by watching for rows that '
            . 'disappear, so a thin fixture makes this whole file vacuous: a deletion of products '
            . 'cannot be detected in a database with no products. Restore the fixture rather than '
            . 'lowering the floor.'
        );
    }

    /**
     * THE `CTRL-004` ASSERTION. No table holds fewer rows than it did, except the safe set.
     */
    private function assertNothingButAvailabilityWasDeleted(array $before, string $what): void
    {
        $after = $this->populatedTableCounts();
        $lost = [];

        foreach ($before as $table => $rows) {
            if (in_array($table, self::AVAILABILITY_TABLES, true)) {
                continue;
            }

            $now = $after[$table] ?? 0;

            if ($now < $rows) {
                $lost[] = "{$table}: {$rows} -> {$now}";
            }
        }

        $this->assertSame(
            [],
            $lost,
            "CTRL-004: {$what} deleted rows that are not availability.\n"
            . "The approved decision is \"do not delete a feature's data merely because its "
            . "availability was disabled\" (DECISION-ID-REGISTER.md:252).\n"
            . 'Tables that lost rows: ' . implode(', ', $lost)
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the fixture
    // ---------------------------------------------------------------------------------------------

    /**
     * A merchant with a store, a catalogue and settings — i.e. a feature's DATA, in the tables a
     * careless disable would reach for.
     *
     * @return array{0: User, 1: \App\Models\Store}
     */
    private function tenantWithContent(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $category = Category::forceCreate([
            'name' => 'Reviewed goods',
            'slug' => 'reviewed-goods-' . $this->uniqueFixtureRef(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
        ]);

        foreach (['First reviewed item', 'Second reviewed item'] as $name) {
            Product::forceCreate([
                'name' => $name,
                'slug' => \Illuminate\Support\Str::slug($name) . '-' . $this->uniqueFixtureRef(),
                'description' => 'x',
                'price' => 10,
                'track_stock' => 0,
                'trending' => 0,
                'stock_status' => 'in_stock',
                'product_stock' => 5,
                'product_type' => null,
                'status' => 1,
                'variant_product' => 0,
                'category_id' => $category->id,
                'store_id' => $store->id,
                'created_by' => $merchant->id,
            ]);
        }

        /*
         * A STORE-LEVEL FEATURE SWITCH AND THE SETTING BESIDE IT. `enable_product_enquiry` is one of
         * `AppSettingController::PANEL_OWNED_FEATURE_FLAGS`, so this row is literally a feature's
         * availability expressed as merchant data — and the row next to it is the kind of
         * configuration that must survive the application being switched off, because the merchant
         * will want it back when it is switched on again.
         */
        Setting::forceCreate([
            'name' => 'enable_product_enquiry',
            'value' => 'on',
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);

        Setting::forceCreate([
            'name' => 'product_review_auto_publish',
            'value' => 'off',
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);

        return [$merchant->fresh(), $store];
    }

    private function plan(string $name, string $modules, array $attributes = []): Plan
    {
        return Plan::create(array_merge([
            'name' => $name,
            'price' => 5,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 100,
            'max_users' => 5,
            'modules' => $modules,
            // The three NOT NULL columns with no default, read off PRAGMA table_info(plans).
            'themes' => 'stylique',
            'storage_limit' => '1024',
        ], $attributes));
    }

    /**
     * An installed, ENABLED application, with `Module::find()`'s memo cleared so the row is visible.
     */
    private function installEnabled(string $module): AddOnManager
    {
        $addon = AddOnManager::create([
            'module' => $module,
            'name' => 'Product Review',
            'is_enable' => 1,
        ]);

        /*
         * THE KEY IS THE MODULE NAME -- `Module::find()` is `Cache::rememberForever($name, ...)`.
         *
         * AND THE MEMO IS ALREADY WARM BY THE TIME THIS RUNS, which is why the line is not
         * defensive. `SecurityTestCase::setUp()` calls `satisfyUpdaterGate()`, which calls
         * `allModules()`, which calls `find()` for all 70 directories -- against an EMPTY
         * `add_on_managers`. So every module is memoised with `addon = null`, and without this
         * forget the row inserted above is invisible to `isEnabled()` and `disable()`.
         * CLAUDE.md records exactly this trap. `Module::moduleCacheForget($module)` is the
         * application's own spelling of it and does nothing more than this one call.
         */
        Cache::forget($module);

        return $addon;
    }

    // ---------------------------------------------------------------------------------------------
    // path 1 -- the operator switches an application off, platform-wide
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_disabling_an_application_only_flips_the_flag(): void
    {
        $operator = $this->makePlatformAdmin();
        $this->tenantWithContent();
        $addon = $this->installEnabled(self::REAL_MODULE);

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        $this->actingAs($operator);
        $this->post(route('module.enable'), ['name' => self::REAL_MODULE]);

        /*
         * THE PREMISE, AND IT IS NOT THE RESPONSE STATUS. `enable()` redirects back on every
         * outcome, success and refusal alike, so the status says nothing. The stored flag does.
         */
        $this->assertSame(
            0,
            (int) DB::table('add_on_managers')->where('id', $addon->id)->value('is_enable'),
            'The application was not switched off, so this test proves nothing about CTRL-004. '
            . 'Check the gate: `enable()` requires `Manage Module` OR type `super admin`, and '
            . '`Module::has()` requires a real directory under packages/workdo.'
        );

        /*
         * THE STRICTER HALF: a disable must not even remove the add-on row, because the row is how
         * the application is switched back ON. `add_on_managers` is in the safe set for the
         * uninstall path, so the generic assertion below would let this through.
         */
        $this->assertSame(
            1,
            (int) DB::table('add_on_managers')->where('id', $addon->id)->count(),
            'Switching an application off deleted its add-on row. Re-enabling it is then '
            . 'impossible from the UI, and the price, alias and package name the operator '
            . 'configured are gone.'
        );

        $this->assertNothingButAvailabilityWasDeleted(
            $before,
            'switching an application off through POST /modules-enable'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // path 2 -- a downgrade takes entitlements away
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_a_plan_downgrade_withdraws_entitlement_without_deleting_content(): void
    {
        $this->makePlatformAdmin();
        [$merchant] = $this->tenantWithContent();

        $big = $this->plan('Three applications', 'ProductReview,Marketing,Zoom');
        $small = $this->plan('One application', 'Marketing');

        $this->actingAs($merchant);
        $merchant->assignPlan($big->id);

        $this->assertSame(
            3,
            (int) DB::table('user_active_modules')->where('user_id', $merchant->id)->count(),
            'The upgrade did not grant three entitlements, so the downgrade below cannot withdraw '
            . 'any and the test would pass vacuously.'
        );

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        $merchant->assignPlan($small->id);

        // THE PREMISE: the downgrade really withdrew availability.
        $remaining = DB::table('user_active_modules')
            ->where('user_id', $merchant->id)
            ->pluck('module')
            ->all();

        $this->assertSame(
            ['Marketing'],
            $remaining,
            'A downgrade from a three-application plan to a one-application plan must leave exactly '
            . 'the one the new plan grants. If this still reads three the downgrade did not '
            . 'downgrade and the CTRL-004 assertion below is vacuous.'
        );

        $this->assertNothingButAvailabilityWasDeleted(
            $before,
            'a plan downgrade through User::assignPlan()'
        );
    }

    /**
     * THE OTHER HALF OF `assignPlan()`: it DEACTIVATES over-limit stores and staff. It must not
     * delete them, and the distinction is the one `CTRL-004` is made of.
     *
     * `PHASE-06-SAAS-LIFECYCLE-FINDINGS.md` §2 states this as measured behaviour. Nothing asserted
     * it, so a change from `is_active = 0` to `->delete()` — one of the most natural "tidy-ups" a
     * later reader could make — was invisible.
     */
    #[Test]
    public function test_a_downgrade_past_the_store_limit_deactivates_rather_than_deletes(): void
    {
        $this->makePlatformAdmin();
        [$merchant] = $this->tenantWithContent();

        $second = $this->makeStore($merchant);
        $third = $this->makeStore($merchant);

        $small = $this->plan('Single store', 'Marketing', ['max_stores' => 1]);

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        $this->actingAs($merchant);
        $merchant->assignPlan($small->id);

        // THE PREMISE: the limit really bit.
        $this->assertSame(
            0,
            (int) DB::table('stores')->where('id', $third->id)->value('is_active'),
            'A one-store plan left a third store active, so the cascade did not run and the '
            . 'assertion below is vacuous.'
        );

        foreach ([$second->id, $third->id] as $storeId) {
            $this->assertSame(
                1,
                (int) DB::table('stores')->where('id', $storeId)->count(),
                'A plan downgrade DELETED a store row. CTRL-004 forbids it: the merchant upgrades '
                . 'again and the store must come back, with its products, orders and settings.'
            );
        }

        $this->assertNothingButAvailabilityWasDeleted(
            $before,
            'a downgrade past the store limit'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // path 3 -- the operator edits a plan and takes an application out of it
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function test_editing_an_application_out_of_a_plan_does_not_touch_its_holders_content(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant] = $this->tenantWithContent();

        $plan = $this->plan('Edited plan', 'ProductReview,Marketing');

        $this->actingAs($merchant);
        $merchant->assignPlan($plan->id);

        $this->assertSame(
            2,
            (int) DB::table('user_active_modules')->where('user_id', $merchant->id)->count(),
            'The holder does not hold two entitlements, so removing one below proves nothing.'
        );

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        /*
         * `isAbleTo('Edit Plan')` HAS NO OPERATOR LIMB in this handler, and CLAUDE.md records that
         * `isAbleTo()` alone REFUSES the platform operator. So the permission is granted through a
         * real Laratrust role and asserted as a premise -- without it the handler answers
         * "Permission denied", the plan is untouched, and every assertion below passes for the
         * wrong reason. That exact mistake is recorded in PHASE-06-MODULE-REMOVAL-FINDINGS.md §3.
         */
        $this->grantPermission($operator, 'Edit Plan');
        $this->assertTrue(
            $operator->fresh()->isAbleTo('Edit Plan'),
            'The operator fixture does not hold `Edit Plan`, so PlanController::update refuses and '
            . 'this test asserts nothing.'
        );

        $this->actingAs($operator->fresh());
        $this->put(route('plan.update', $plan->id), [
            'name' => 'Edited plan',
            'price' => 5,
            'duration' => 'Month',
            'max_stores' => 5,
            'max_products' => 100,
            'max_users' => 5,
            'storage_limit' => 1024,
            // ProductReview is withdrawn; Marketing stays.
            'modules' => ['Marketing'],
            'themes' => ['stylique'],
        ]);

        // THE PREMISE: the edit landed.
        $this->assertSame(
            'Marketing',
            (string) DB::table('plans')->where('id', $plan->id)->value('modules'),
            'The plan still grants ProductReview, so the operator edit did not land -- check the '
            . 'validator and the `Edit Plan` grant rather than trusting the assertions below.'
        );

        $this->assertSame(
            ['Marketing'],
            DB::table('user_active_modules')->where('user_id', $merchant->id)->pluck('module')->all(),
            'Editing an application out of a plan must withdraw it from the plan\'s holders. If '
            . 'this still reads two the availability change did not happen.'
        );

        $this->assertNothingButAvailabilityWasDeleted(
            $before,
            'editing an application out of a plan through PUT /plan/{plan}'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the settings a disabled feature leaves behind
    // ---------------------------------------------------------------------------------------------

    /**
     * THE NARROWEST STATEMENT OF `CTRL-004`, AND THE ONE A GENERIC ROW COUNT CANNOT MAKE.
     *
     * "No table shrank" is satisfied by a handler that deletes one row and writes another. This
     * asserts the identified rows themselves: after an application is switched off platform-wide,
     * the merchant's own configuration FOR that application is still there, by name and by value,
     * so switching it back on restores what they had rather than a default.
     */
    #[Test]
    public function test_a_disabled_applications_merchant_settings_survive_by_name_and_value(): void
    {
        $operator = $this->makePlatformAdmin();
        [$merchant, $store] = $this->tenantWithContent();
        $this->installEnabled(self::REAL_MODULE);

        $this->actingAs($operator);
        $this->post(route('module.enable'), ['name' => self::REAL_MODULE]);

        $this->assertSame(
            0,
            (int) DB::table('add_on_managers')->where('module', self::REAL_MODULE)->value('is_enable'),
            'The application was not switched off, so nothing about CTRL-004 is being tested.'
        );

        foreach (['enable_product_enquiry' => 'on', 'product_review_auto_publish' => 'off'] as $name => $value) {
            $this->assertSame(
                $value,
                (string) DB::table('settings')
                    ->where('name', $name)
                    ->where('store_id', $store->id)
                    ->value('value'),
                "The merchant's `{$name}` setting did not survive the application being switched "
                . 'off. CTRL-004 requires that disabling availability leaves the feature\'s data '
                . 'alone, and a merchant\'s configuration is the first thing a cleanup reaches for.'
            );
        }

        $this->assertSame(
            2,
            (int) DB::table('products')->where('store_id', $store->id)->count(),
            'Switching the application off removed the merchant\'s catalogue.'
        );

        $this->assertSame(
            $merchant->id,
            (int) DB::table('users')->where('id', $merchant->id)->value('id'),
            'Switching an application off removed a merchant account.'
        );
    }

    // ---------------------------------------------------------------------------------------------
    // the premise this whole file rests on
    // ---------------------------------------------------------------------------------------------

    /**
     * `Module::disable()` IS A FLAG FLIP AND NOTHING ELSE — asserted directly, not through a route.
     *
     * The route tests above can only see the outcome of the whole request. This pins the method, so
     * a later edit that adds a cleanup inside it is caught even if the route grows a guard that
     * hides the call.
     */
    #[Test]
    public function test_the_disable_primitive_writes_one_column_and_deletes_nothing(): void
    {
        $this->tenantWithContent();
        $addon = $this->installEnabled(self::REAL_MODULE);

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        $module = (new Module())->find(self::REAL_MODULE);

        $this->assertTrue(
            $module->isEnabled(),
            '`Module::find()` resolved an application whose add-on row it cannot see -- the '
            . 'rememberForever memo was not cleared, so `disable()` below is a no-op on a '
            . 'detached object and the test would pass for the wrong reason.'
        );

        $module->disable();

        $this->assertSame(
            0,
            (int) DB::table('add_on_managers')->where('id', $addon->id)->value('is_enable'),
            '`Module::disable()` did not switch the application off.'
        );

        $this->assertNothingButAvailabilityWasDeleted($before, 'Module::disable()');
    }

    /**
     * AND THE INSTRUMENT ITSELF IS TESTED, because an instrument that cannot see a deletion would
     * make every assertion above green for ever.
     *
     * This is the mutation the whole file exists to catch, performed in the open: delete a
     * merchant's products and prove the comparator reports it. Without this, a refactor that broke
     * `populatedTableCounts()` — a wrong driver branch, an exclusion that swallowed everything —
     * would be silent.
     */
    #[Test]
    public function test_the_comparator_reports_a_deletion_it_is_shown(): void
    {
        [, $store] = $this->tenantWithContent();

        $before = $this->populatedTableCounts();
        $this->assertFixtureIsPopulated($before);

        DB::table('products')->where('store_id', $store->id)->delete();

        try {
            $this->assertNothingButAvailabilityWasDeleted($before, 'a deliberate deletion');
        } catch (\PHPUnit\Framework\AssertionFailedError $failure) {
            $this->assertStringContainsString(
                'products: 2 -> 0',
                $failure->getMessage(),
                'The comparator failed, but not with the table and counts it was shown.'
            );

            return;
        }

        $this->fail(
            'populatedTableCounts()/assertNothingButAvailabilityWasDeleted() did not notice two '
            . 'deleted products. Every CTRL-004 assertion in this file is therefore vacuous.'
        );
    }

    /**
     * THE SAFE SET IS SMALL ON PURPOSE, SO ITS SIZE IS PINNED.
     *
     * `AVAILABILITY_TABLES` is the one place this file permits a row to disappear. Adding a fourth
     * entry widens what `CTRL-004` tolerates, and the decision says nothing about such a widening —
     * so it must be a visible, deliberate change rather than a line added while chasing a red run.
     */
    #[Test]
    public function test_the_set_of_tables_allowed_to_lose_rows_is_the_declared_three(): void
    {
        $this->assertSame(
            ['add_on_managers', 'permission_role', 'user_active_modules'],
            collect(self::AVAILABILITY_TABLES)->sort()->values()->all(),
            'A table was added to or removed from the set this file lets a disable empty. That '
            . 'widens or narrows what CTRL-004 tolerates. Read the constant\'s docblock, decide '
            . 'deliberately, and update this assertion in the same change.'
        );
    }

    /**
     * `userActiveModule` IS AVAILABILITY, AND THAT CLASSIFICATION IS LOAD-BEARING.
     *
     * The whole file turns on it: if those rows were a feature's DATA, path 2 and path 3 would be
     * `CTRL-004` violations rather than the decision working. The claim is grounded in what the
     * table can hold — a user id and a module name, and nothing a merchant typed — so it is
     * asserted against the schema instead of being left as prose.
     */
    #[Test]
    public function test_the_entitlement_table_holds_no_merchant_content(): void
    {
        $columns = Schema::getColumnListing('user_active_modules');
        sort($columns);

        $this->assertSame(
            ['created_at', 'id', 'module', 'updated_at', 'user_id'],
            $columns,
            '`user_active_modules` has grown a column. It is treated as pure availability by this '
            . 'file and by PHASE-06-SAAS-LIFECYCLE-FINDINGS.md §2 BECAUSE it carries nothing but a '
            . 'user id and a module name. A column holding anything a merchant supplied makes '
            . 'rewriting the table on a plan change a CTRL-004 question.'
        );
    }
}
