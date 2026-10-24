<?php

namespace Tests\Feature\Security;

use App\Models\AddOnManager;
use App\Models\Plan;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * P1-PLT-04 / #36, SECOND CRITERION -- "module_is_active() answers from one source of truth".
 *
 * IT DOES NOT, AND THIS SUITE MEASURES EXACTLY WHERE. The three sources, read from the code:
 *
 *   A. `plans.modules` of the store's owner  -- `module_is_active()`'s SLUG branch only
 *                                               (helper.php, the `request()->route('storeSlug')` limb)
 *   B. `user_active_modules` of the owner    -- `ActivatedModule()` -> `activated_modules_for_owner()`,
 *                                               intersected with `Module::getByStatus(1)`
 *   C. `users.type === 'super admin'`        -- both functions, unconditional true
 *
 * `PlanModuleCheck` -- the real gate on every merchant route -- requires A' AND B: it calls
 * `module_is_active($m)` (which on an authenticated request is B, not A) and then separately
 * intersects with `ActivatedModule()`. So the ADMIN side is already the intersection.
 *
 * THE STOREFRONT IS THE ONE THAT DIVERGES. On a `{storeSlug}` route nobody is authenticated, the
 * slug branch runs, and it consults A ALONE. A merchant whose plan grants a module they have never
 * activated gets that module on their storefront and a 403 on their own admin screen for it; a
 * merchant who activated a module their plan does not list gets the reverse.
 *
 * ===========================================================================================
 *  WHY THIS SUITE PINS THE DISAGREEMENT RATHER THAN CLOSING IT.
 * ===========================================================================================
 *
 * The one-source rule that would be CORRECT is the one the admin gate already enforces: the plan
 * allows it AND the owner activated it. Applying that to the slug branch is a one-line change and
 * it is strictly NARROWER than today -- which means it REMOVES a working storefront feature from
 * every merchant who has a plan entitlement with no `user_active_modules` row. That is a live
 * business change on the shopper-facing side, with a blast radius this repository cannot bound: it
 * depends on how many such rows exist in the production table, which is not knowable here.
 *
 * So the disagreement is MEASURED and PINNED here, today's behaviour is recorded as the baseline,
 * and the change itself waits on the one read-only query that bounds it:
 *
 *     SELECT COUNT(*) FROM users u
 *       JOIN plans p ON p.id = u.plan_id
 *      WHERE u.type = 'admin'
 *        AND p.modules IS NOT NULL AND p.modules <> ''
 *        AND NOT EXISTS (SELECT 1 FROM user_active_modules m WHERE m.user_id = u.id);
 *
 * A zero answer makes the change a no-op and it ships. A non-zero answer is the number of
 * merchants whose storefront would lose a feature, and that is an owner's call, not an
 * engineer's. Recorded in the ledger as the precise blocker on criterion 2.
 *
 * IF THE SLUG BRANCH IS EVER UNIFIED, these tests are what must change, and the direction is
 * stated in each one, so the next reader does not have to re-derive which way is tighter.
 */
class ModuleEntitlementSourcesTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * NOT LandingPage. It is the sole entry of User::$superadmin_activated_module, which
     * ActivatedModule() merges in unconditionally at the end, so it answers true for every
     * principal and would have made the disagreement below invisible -- measured: the first
     * version of this suite used LandingPage and its disagreement test passed for that reason,
     * not because the two sources agreed.
     */
    private const MODULE = 'BoostSales';

    /** @return array{0: User, 1: Store} a merchant whose plan lists the module and who has NOT activated it */
    private function merchantWithPlanOnly(): array
    {
        [$owner, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'Plan ' . uniqid(), 'price' => 0, 'duration' => 'Unlimited', 'themes' => '', 'storage_limit' => 0,
            'modules' => self::MODULE, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('users')->where('id', $owner->id)->update(['plan_id' => $planId]);
        (new User)->flushCache();          // User is Cachable: a raw write leaves Eloquent serving the old row
        Cache::forget("user_{$owner->id}");
        Cache::forget("user_by_store_{$store->created_by}");

        return [User::find($owner->id), $store];
    }

    private function activate(User $owner, string $module): void
    {
        DB::table('user_active_modules')->insert([
            'user_id' => $owner->id, 'module' => $module, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // activated_modules_for_owner() memoises per owner in a scoped container binding.
        $this->app->forgetScopedInstances();
    }

    private function enableAddOnRow(string $module): void
    {
        AddOnManager::firstOrCreate(['module' => $module], [
            'name' => $module, 'monthly_price' => 0, 'yearly_price' => 0, 'is_enable' => 1,
            'package_name' => strtolower($module),
        ]);

        Cache::flush();
    }

    /** module_is_active() reads the CURRENT request's route, so the question has to be asked inside one. */
    private function moduleIsActiveOnStorefront(Store $store, string $module): bool
    {
        $answer = null;

        \Illuminate\Support\Facades\Route::get('/{storeSlug}/p1-plt-04-probe', function () use ($module, &$answer) {
            $answer = module_is_active($module);

            return 'ok';
        })->middleware('web');

        $this->get('/' . $store->slug . '/p1-plt-04-probe');

        $this->assertNotNull($answer, 'The probe route did not run, so nothing was measured.');

        return (bool) $answer;
    }

    // =====================================================================================
    //  PREMISE
    // =====================================================================================

    public function test_the_premise_the_three_sources_are_distinct_tables(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('plans', 'modules'),
            'Source A is plans.modules.');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('user_active_modules'),
            'Source B is user_active_modules.');
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('users', 'type'),
            'Source C is users.type.');
    }

    // =====================================================================================
    //  THE MEASUREMENT
    // =====================================================================================

    /**
     * SOURCE A ALONE DECIDES THE STOREFRONT. The owner has the plan entitlement and NO
     * user_active_modules row, and the storefront says the module is active.
     *
     * IF UNIFIED this becomes FALSE, and that is the regression the owner query above bounds.
     */
    public function test_today_the_storefront_answers_from_the_plan_alone(): void
    {
        $this->enableAddOnRow(self::MODULE);
        [$owner, $store] = $this->merchantWithPlanOnly();

        $this->assertSame([], DB::table('user_active_modules')->where('user_id', $owner->id)->pluck('module')->all(),
            'Fixture premise: the owner must have activated NOTHING, or this measures the wrong thing.');

        $this->assertTrue($this->moduleIsActiveOnStorefront($store, self::MODULE),
            'BASELINE, not an endorsement: the storefront grants a module the owner never activated, '
            . 'because the slug branch reads plans.modules alone.');
    }

    /**
     * SOURCE B ALONE DECIDES THE ADMIN SIDE. The same owner, the same plan, no activation -- and
     * ActivatedModule() does NOT list the module. So the two sides already disagree about one
     * merchant, which is the whole of criterion 2.
     */
    public function test_the_admin_side_answers_from_user_active_modules_and_therefore_disagrees(): void
    {
        $this->enableAddOnRow(self::MODULE);
        [$owner] = $this->merchantWithPlanOnly();

        $this->assertNotContains(self::MODULE, ActivatedModule($owner->id),
            'THE DISAGREEMENT, measured: the storefront grants this module (test above) and the admin side does not.');
    }

    /** And with the activation row present the two agree, which is what "one source" would mean everywhere. */
    public function test_with_both_sources_satisfied_the_two_sides_agree(): void
    {
        $this->enableAddOnRow(self::MODULE);
        [$owner, $store] = $this->merchantWithPlanOnly();
        $this->activate($owner, self::MODULE);

        $this->assertContains(self::MODULE, ActivatedModule($owner->id));
        $this->assertTrue($this->moduleIsActiveOnStorefront($store, self::MODULE));
    }

    /**
     * SOURCE C, the operator, is unconditional in BOTH functions, and that is correct rather than a
     * third disagreement: this codebase has no super-admin bypass in Laratrust, so a platform
     * operator holds no role granting any module and an entitlement check without this limb would
     * lock them out of every module screen permanently -- the lockout
     * App\Http\Middleware\FinancePermission records having been caused once already.
     */
    public function test_the_operator_limb_is_unconditional_in_both_functions_and_must_stay(): void
    {
        $this->enableAddOnRow(self::MODULE);

        $operator = $this->makeMerchant();
        DB::table('users')->where('id', $operator->id)->update(['type' => 'super admin', 'plan_id' => null]);
        (new User)->flushCache();
        Cache::forget("user_{$operator->id}");

        $this->assertContains(self::MODULE, ActivatedModule($operator->id),
            'The operator lost a module they hold no plan and no activation row for. That is the lockout direction.');
    }

    /**
     * THE RULE A UNIFICATION WOULD APPLY, asserted as arithmetic so it is unambiguous when someone
     * comes to make the change: the admin gate is the INTERSECTION, so the storefront must be too.
     */
    public function test_the_admin_gate_is_already_the_intersection_so_that_is_the_rule_to_adopt(): void
    {
        $source = file_get_contents(app_path('Http/Middleware/PlanModuleCheck.php'));

        $stripped = implode('', array_map(
            fn ($t) => is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($t) ? $t[1] : $t),
            token_get_all($source)
        ));

        $this->assertStringContainsString('module_is_active(', $stripped);
        $this->assertStringContainsString('ActivatedModule(', $stripped);
        $this->assertStringContainsString('array_intersect(', $stripped,
            'PlanModuleCheck no longer intersects the two sources, so the rule this suite recommends for the '
            . 'storefront is no longer the one the admin side enforces. Re-derive before changing module_is_active().');
    }
}
