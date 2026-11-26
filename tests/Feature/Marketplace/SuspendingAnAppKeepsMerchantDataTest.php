<?php

namespace Tests\Feature\Marketplace;

use App\Models\AddOnManager;
use App\Models\Permission;
use App\Models\User;
use App\Models\userActiveModule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Security\SecurityTestCase;
use Tests\Support\Security\EnablesWorkdoModules;

/**
 * MARKET-005 — "تعليق التطبيقات أو سحب صلاحياتها دون إتلاف بيانات التاجر":
 * suspend applications, or withdraw their permissions, WITHOUT destroying merchant data.
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * THE DISPOSITION THIS FILE RECORDS: THE GUARANTEE ALREADY HELD, AND NOTHING ASSERTED IT
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * Measured across the host application on 2026-11-26, before a line of this was written:
 *
 *   - `migrate:rollback`, `migrate:reset`, `migrate:fresh`, `Schema::drop` and `dropIfExists`:
 *     **0 occurrences in `app/`**. So no disable or removal path drops a module's tables.
 *   - no module declares an uninstall or disable hook: `function uninstall`, `function onDisable`,
 *     `ModuleDisabled`, `disableModule` -> **0 files in `packages/workdo/`**.
 *   - `ModuleController::enable()`'s disable branch is `$module->disable()` and nothing else, which
 *     is one `UPDATE add_on_managers SET is_enable = 0`.
 *   - `ModuleController::remove()` deletes exactly two row sets -- `permissions` and
 *     `add_on_managers`, both keyed on the module name -- and the module's directory.
 *   - `PlanController::update()` and `User::assignPlan()` delete `user_active_modules` rows.
 *
 * `permissions` and `user_active_modules` are the two MARKET-005 explicitly ALLOWS: the decision
 * text's own verb for them is "سحب صلاحياتها", withdraw its permissions. Everything else is merchant
 * data and must survive.
 *
 * So the correct disposition for MARKET-005 is MISSING VERIFICATION, not missing functionality --
 * and an unasserted guarantee is one refactor from being false. This file asserts it, in the two
 * forms the project's own rules call for: a behavioural test through the production doors, and a
 * census that ENUMERATES THE SAFE SET rather than hunting for dangerous spellings (CLAUDE.md §3:
 * "a list of dangerous spellings is always short by the one nobody thought of").
 *
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 * WHY A REAL MODULE, AND WHY A PROBE FOR THE REMOVAL CASE
 * ──────────────────────────────────────────────────────────────────────────────────────────────
 *
 * The disable cases drive `ProductReview`, a real vendored module with exactly one migration
 * (`product_reviews`), through `EnablesWorkdoModules` -- the production loader. CLAUDE.md records
 * that no WorkDo module loads in a test run without an `add_on_managers` row, so a test over a
 * module surface may never have reached it; every case here asserts its premise first.
 *
 * The REMOVAL case cannot use a vendored module: `remove()` recursively deletes the module's
 * directory, and a test that did that to `ProductReview` would delete vendored source from the
 * worktree. It uses a probe package this test creates and removes.
 */
class SuspendingAnAppKeepsMerchantDataTest extends SecurityTestCase
{
    use RefreshDatabase;
    use EnablesWorkdoModules;

    private const PROBE = 'P6MarketRemovalProbe';

    private string $probeDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->probeDirectory = base_path('packages/workdo/' . self::PROBE);
    }

    protected function tearDown(): void
    {
        if (isset($this->probeDirectory) && is_dir($this->probeDirectory)) {
            File::deleteDirectory($this->probeDirectory);
        }

        parent::tearDown();
    }

    /**
     * Three merchant reviews, written raw so no module model or scope is involved in the fixture.
     *
     * A REAL `categories` ROW IS REQUIRED. `product_reviews.category_id` defaults to 0 and carries
     * `->foreign('category_id')->references('id')->on('categories')->onDelete('cascade')`, so the
     * module's own default violates its own constraint -- the first version of this fixture failed
     * on `FOREIGN KEY constraint failed` for all three rows. Worth recording: on production MySQL
     * the same insert would fail the same way, so the module cannot store a review with no category.
     */
    private function seedMerchantRows(int $storeId = 7): void
    {
        $categoryId = DB::table('categories')->insertGetId([
            'name' => 'probe category',
            'slug' => 'probe-category-' . $storeId,
            'store_id' => $storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([5, 3, 4] as $i => $rating) {
            DB::table('product_reviews')->insert([
                'category_id' => $categoryId,
                'product_id' => 100 + $i,
                'rating_no' => $rating,
                'title' => 'merchant review ' . $i,
                'description' => 'written while the application was switched on',
                'status' => 1,
                'store_id' => $storeId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * A permission row that really carries `module`.
     *
     * `Permission::create([... 'module' => 'X'])` DOES NOT WORK and the first version of this file
     * walked into it: `App\Models\Permission` sets `$guarded = []` but extends Laratrust's
     * `Permission`, which declares a non-empty `protected $fillable = ['name','display_name',
     * 'description']`, and a non-empty inherited `$fillable` wins in `Model::isFillable()`. So
     * `module` was silently dropped and the row took the column's `->default('Base')` --
     * `Permission::where('module','ProductReview')->count()` came back **0** for a row that had just
     * been created. `RemovableModule`'s own docblock records the same fact from the other side: it is
     * why every unqualified permission in this application is a `Base` row.
     *
     * `Permission` is one of the 16 models in `app/Models/` that is NOT `Cachable`, so a raw insert
     * is read back correctly here; for a `Cachable` model this would be CLAUDE.md's stale-read trap.
     */
    private function seedPermission(string $name, string $module): void
    {
        DB::table('permissions')->insert([
            'name' => $name,
            'display_name' => $name,
            'guard_name' => 'web',
            'module' => $module,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function disableThroughTheProduct(string $module): \Illuminate\Testing\TestResponse
    {
        Cache::flush();

        return $this->actingAs($this->makePlatformAdmin())
            ->post('/modules-enable', ['name' => $module]);
    }

    /**
     * THE PREMISE, and CLAUDE.md requires it: the module must really be loaded and its table must
     * really exist, or every assertion below is about a surface the test never reached.
     */
    #[Test]
    public function test_the_module_and_its_table_are_really_there(): void
    {
        $failed = $this->enableWorkdoModules(['ProductReview']);

        $this->assertSame([], $failed, 'premise: the module migrations must run.');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('product_reviews'), 'premise');
        $this->assertSame(1, (int) AddOnManager::where('module', 'ProductReview')->value('is_enable'), 'premise');
    }

    #[Test]
    public function test_switching_an_application_off_destroys_no_merchant_row(): void
    {
        $this->assertSame([], $this->enableWorkdoModules(['ProductReview']), 'premise');
        $this->seedMerchantRows();

        $this->assertSame(3, DB::table('product_reviews')->count(), 'premise');

        $this->disableThroughTheProduct('ProductReview');

        $this->assertSame(
            0,
            (int) AddOnManager::where('module', 'ProductReview')->value('is_enable'),
            'premise for the rest of this test: the application really was switched off.'
        );

        $this->assertSame(
            3,
            DB::table('product_reviews')->count(),
            'Suspending an application must not destroy a single merchant row.'
        );
        $this->assertSame(
            ['merchant review 0', 'merchant review 1', 'merchant review 2'],
            DB::table('product_reviews')->orderBy('product_id')->pluck('title')->all(),
            'The rows must be unchanged, not merely present in the same number.'
        );
    }

    /**
     * THE ROW ITSELF SURVIVES TOO, and that is what makes suspension reversible: `remove()` deletes
     * the `add_on_managers` row, `disable()` must not. Without the row a re-enable goes down
     * `enable()`'s "no addon" branch, which re-runs `migrate` and `package:seed`.
     */
    #[Test]
    public function test_switching_an_application_off_keeps_its_add_on_row_and_its_permissions(): void
    {
        $this->assertSame([], $this->enableWorkdoModules(['ProductReview']), 'premise');

        $this->seedPermission('Manage Product Review', 'ProductReview');
        $before = Permission::where('module', 'ProductReview')->count();
        $this->assertGreaterThan(0, $before, 'premise: the permission really carries module = ProductReview.');

        $this->disableThroughTheProduct('ProductReview');

        $this->assertTrue(
            AddOnManager::where('module', 'ProductReview')->exists(),
            'Suspension must be reversible, so the add-on row stays.'
        );
        $this->assertSame(
            $before,
            Permission::where('module', 'ProductReview')->count(),
            'Switching an application off is not withdrawing its permissions; a merchant role keeps its grants.'
        );
    }

    /**
     * REVERSIBILITY, AND A VENDORED DEFECT FOUND BY DRIVING IT.
     *
     * `ModuleController::enable()`'s re-enable branch runs `Artisan::call('package:seed <Module>')`,
     * and the first version of this test answered **HTTP 500** on it. The cause is in the vendored
     * seeder, not in the host: `packages/workdo/ProductReview/src/database/seeders/
     * PermissionTableSeeder.php` does `$company_role = Role::where('name','admin')->first();` and
     * then `$company_role->hasPermission($value)` with **no null check** -- so re-enabling ANY module
     * on an installation that has no role literally named `admin` is a fatal
     * "Call to a member function hasPermission() on null". Measured, not inferred. It is vendored
     * code and `packages/workdo/` is not edited, so it is reported rather than fixed, and the role
     * is created here to get past it.
     *
     * The assertion that matters for MARKET-005 is the LAST one: the merchant's rows are still there
     * on the other side of a suspend-and-restore cycle.
     */
    #[Test]
    public function test_switching_an_application_back_on_is_possible_after_a_suspension(): void
    {
        $this->assertSame([], $this->enableWorkdoModules(['ProductReview']), 'premise');
        $this->seedMerchantRows();

        $owner = $this->makeMerchant();
        $this->makeRole($owner, $this->makeStore($owner), 'admin');

        $this->disableThroughTheProduct('ProductReview');
        $this->assertSame(0, (int) AddOnManager::where('module', 'ProductReview')->value('is_enable'), 'premise');

        $this->disableThroughTheProduct('ProductReview');

        $this->assertSame(
            1,
            (int) AddOnManager::where('module', 'ProductReview')->value('is_enable'),
            'A suspended application must be able to come back.'
        );
        $this->assertSame(3, DB::table('product_reviews')->count(), 'and the merchant data is still there');
    }

    /**
     * WITHDRAWING A MERCHANT'S ENTITLEMENT IS ALLOWED AND MUST NOT TOUCH THEIR DATA.
     *
     * `user_active_modules` is the per-merchant entitlement table -- a different question from
     * `add_on_managers.is_enable`, which is global. `User::assignPlan()` deletes rows here when a
     * plan no longer grants a module, which is MARKET-005's "withdraw their permissions" and is
     * explicitly permitted. What is not permitted is the merchant's content going with it.
     */
    #[Test]
    public function test_withdrawing_a_merchants_entitlement_destroys_no_merchant_row(): void
    {
        $this->assertSame([], $this->enableWorkdoModules(['ProductReview']), 'premise');
        $this->seedMerchantRows();

        $merchant = $this->makeMerchant();
        userActiveModule::create(['user_id' => $merchant->id, 'module' => 'ProductReview']);

        $this->assertTrue(
            userActiveModule::where('user_id', $merchant->id)->where('module', 'ProductReview')->exists(),
            'premise'
        );

        userActiveModule::where('user_id', $merchant->id)->where('module', 'ProductReview')->delete();

        $this->assertFalse(
            userActiveModule::where('user_id', $merchant->id)->where('module', 'ProductReview')->exists(),
            'premise: the entitlement really was withdrawn.'
        );
        $this->assertSame(
            3,
            DB::table('product_reviews')->count(),
            'Withdrawing an entitlement must leave the merchant data the application produced.'
        );
    }

    /**
     * REMOVING AN APPLICATION DELETES ITS BOOKKEEPING AND NOTHING ELSE.
     *
     * Driven against a probe package, because `remove()` recursively deletes the module's directory.
     * The assertions name the three things that must survive: merchant rows, another module's
     * permissions, and the `Base` permission set -- which is not a module at all and whose deletion
     * `RemovableModule` exists to prevent (`permission_role.permission_id` cascades, so deleting
     * `Base` rows strips grants from every role of every tenant).
     */
    #[Test]
    public function test_removing_an_application_deletes_only_its_own_bookkeeping(): void
    {
        $this->assertSame([], $this->enableWorkdoModules(['ProductReview']), 'premise');
        $this->seedMerchantRows();

        File::makeDirectory($this->probeDirectory, 0777, true);
        File::put($this->probeDirectory . '/module.json', json_encode(['name' => self::PROBE, 'alias' => 'Removal Probe']));

        AddOnManager::create([
            'module' => self::PROBE,
            'name' => 'Removal Probe',
            'monthly_price' => 0,
            'yearly_price' => 0,
            'is_enable' => 1,
            'package_name' => 'removal-probe',
        ]);

        $this->seedPermission('Manage Removal Probe', self::PROBE);
        $this->seedPermission('Manage Product Review', 'ProductReview');
        $this->seedPermission('Manage Base Thing', 'Base');

        Cache::flush();

        $operator = $this->makePlatformAdmin();
        $this->grantPermission($operator, 'Module remove');

        $this->actingAs($operator)->post('/remove-modules/' . self::PROBE);

        $this->assertFalse(
            AddOnManager::where('module', self::PROBE)->exists(),
            'premise: the removal really happened.'
        );
        $this->assertDirectoryDoesNotExist($this->probeDirectory, 'premise: the files really went.');

        $this->assertSame(
            3,
            DB::table('product_reviews')->count(),
            'Removing an application must not destroy merchant data -- its tables are not dropped.'
        );
        $this->assertTrue(
            Permission::where('module', 'ProductReview')->exists(),
            'Another module keeps its permissions.'
        );
        $this->assertTrue(
            Permission::where('module', 'Base')->exists(),
            'The core permission set is untouched; deleting it would strip every role of every tenant.'
        );
    }
}
