<?php

namespace Tests\Feature\Security;

use App\Services\MerchantPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\StripsSourceComments;

/**
 * THE STORE-ANALYTICS SCREEN WAS GATED BY THE SIDEBAR MENU AND NOTHING ELSE.
 * =================================================================================================
 *
 * Measured 2026-11-21 while sizing ANA-004. `CompanyMenuListener:50-60` publishes
 * `themean-alytic` as "Store Analytics" with `'permission' => 'Manage Store Analytics'`, and
 * `Menu::permits()` honours it — so the menu entry is correctly hidden from a staff member who does
 * not hold that permission. The ROUTE, however, carried only
 * `web, Authenticate, XSS, SetLocate, EmailIsVerified`, and the controller's only check was
 * `$user->type != 'super admin'`, which is a BRANCH choosing a data path rather than a refusal.
 *
 * So any verified user read their store's traffic analytics — page views by URL, and the device,
 * browser and platform breakdowns — by typing the URL. Store-scoped, so this is VERTICAL within one
 * tenant rather than cross-tenant, and it is the same class `P1-SEC-09` closed for
 * `ReportController`'s 29 methods, whose docblock uses the same sentence: the permissions "were
 * enforced ONLY by the sidebar menu".
 *
 * THE RULE APPLIED IS NOT NEW. `Api\DashboardController` already refuses the SAME screen's data
 * without `['Manage Store Analytics', 'Manage Dashboard']` (:1278, :3966) through the same
 * `MerchantPermissions::allowsAny()` helper. The web screen never got it, so the API and the web
 * disagreed about who may read one figure. This test pins them together.
 */
final class StoreAnalyticsPermissionTest extends SecurityTestCase
{
    use RefreshDatabase;
    use StripsSourceComments;

    private const URI = '/themean-alytic';

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.debug' => false]);
    }

    public function test_premise_the_permission_this_screen_names_is_one_the_application_seeds(): void
    {
        $seeded = (string) file_get_contents(base_path('app/Models/Utility.php'));

        $this->assertStringContainsString('"Manage Store Analytics"', $seeded,
            'CLAUDE.md: a permission name absent from Utility::addNewData() cannot exist as a row '
            . 'and evaluates false for EVERYBODY including the tenant owner. If this name is not '
            . 'seeded, the gate below is not a gate, it is a wall.');
    }

    /**
     * THE DEFECT, from the principal it exposed the screen to.
     *
     * A staff member of this store who holds no analytics or dashboard permission. The menu hides
     * the entry from them; before the fix the URL did not.
     */
    public function test_a_staff_member_without_the_permission_is_refused(): void
    {
        [$merchant] = $this->makeTenant();
        $staff = $this->makeStaff($merchant);

        $response = $this->actingAs($staff->fresh())->get(self::URI);

        $this->assertNotSame(200, $response->status(), sprintf(
            'The store-analytics screen answered 200 to a staff member holding neither '
            . '"Manage Store Analytics" nor "Manage Dashboard". The menu entry is hidden from them, '
            . 'so the permission was enforced by the sidebar alone — P1-SEC-09 all over again.'
        ));

        $response->assertRedirect();

        $this->assertNotSame(401, $response->status(),
            'A 401 force-logs-out the shipped Flutter app. The refusal must be a redirect for a '
            . 'web route, or a 403 for a JSON caller, never a 401.');
    }

    /** Either permission admits, exactly as the API pair does. */
    public function test_either_permission_the_api_accepts_also_admits_here(): void
    {
        foreach (['Manage Store Analytics', 'Manage Dashboard'] as $permission) {
            [$merchant] = $this->makeTenant();
            $staff = $this->makeStaff($merchant);
            $this->grantPermission($staff, $permission);

            $this->assertTrue(
                MerchantPermissions::allowsAny($staff->fresh(), ['Manage Store Analytics', 'Manage Dashboard'], $staff->fresh()->current_store),
                "Premise: the {$permission} grant did not take, so the case below proves nothing."
            );

            $response = $this->actingAs($staff->fresh())->get(self::URI);

            $this->assertNotSame(302, $response->status(), sprintf(
                'A staff member holding "%s" was redirected away from the store-analytics screen. '
                . 'Api\\DashboardController admits either of the two permissions for the same data '
                . '(:1278, :3966), so the web screen refusing one of them makes the API and the web '
                . 'disagree about who may read one figure.',
                $permission
            ));
        }
    }

    /**
     * THE OPERATOR, who holds no Laratrust role at all.
     *
     * `MerchantPermissions::names()` answers `[WILDCARD]` for them via `UserPolicy::isOperator`,
     * which is the whole reason this gate uses `allowsAny()` rather than `isAbleTo()`: Laratrust has
     * no super-admin bypass, so a bare permission check would refuse the one principal who reads
     * every store.
     */
    public function test_the_platform_operator_is_not_refused_by_the_new_gate(): void
    {
        $operator = $this->makePlatformAdmin();

        $this->assertTrue(
            MerchantPermissions::allowsAny($operator->fresh(), ['Manage Store Analytics', 'Manage Dashboard'], null),
            'The operator is refused by the predicate this screen now uses. Laratrust has no '
            . 'super-admin bypass, so allowsAny() answering WILDCARD for them is what keeps the '
            . 'operator out of a wall of their own making.'
        );
    }

    /**
     * THE GATE IS THE CONTROLLER'S, AND IT RUNS BEFORE THE WORK.
     *
     * P1-SEC-22 (f) found this ordering wrong in `CartController::abandon_carts_messsend`: the
     * lookup and relation load ran before `isAbleTo()`, so a staff member without the permission
     * still drove the queries and a missing row 500'd before the refusal. Asserted structurally
     * because the queries themselves are not reachable under SQLite — the handler's URL filter uses
     * `regexp`, which SQLite does not implement — so no test can prove the ordering by observing a
     * 200.
     */
    public function test_the_refusal_precedes_the_queries_it_protects(): void
    {
        /*
         * COMMENTS STRIPPED, and my own mutation run is why. The first version read the raw file,
         * so when the guard was removed to prove this test catches its absence, `strpos` still
         * found `MerchantPermissions::allowsAny` -- IN THE DOCBLOCK I had just written explaining
         * the guard. The test passed against the pre-fix code. `StripsSourceComments` exists
         * because that exact mistake had already been made six times in this repository; this was
         * the seventh, and the second one I made today.
         */
        $source = $this->sourceWithoutComments('app/Http/Controllers/ThemeAnalyticController.php');

        $guard = strpos($source, 'MerchantPermissions::allowsAny');
        $firstQuery = strpos($source, 'shetabit_visits');

        $this->assertNotFalse($guard, 'the permission gate is gone from the controller.');
        $this->assertNotFalse($firstQuery, 'the analytics queries are gone; this test has no subject.');

        $this->assertLessThan($firstQuery, $guard,
            'The permission check now runs AFTER the first analytics query. P1-SEC-22 (f) is the '
            . 'record of why that is wrong: authorization must gate the work, not follow it.');
    }
}
