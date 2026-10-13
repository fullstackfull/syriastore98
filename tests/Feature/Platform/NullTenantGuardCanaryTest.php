<?php

namespace Tests\Feature\Platform;

use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * NULL-GUARD CANARIES. THESE TWO TESTS ARE EXPECTED TO FAIL TODAY, AND THE FAILURE IS THE
 * FINDING. They are the integration signal for two null-dereference defects in app/, which
 * is owned by another agent this phase.
 *
 * WHY THEY EXIST AS THEIR OWN TESTS. Nine of the suite's thirteen failures are caused by
 * these two lines, but every one of them reaches the defect INDIRECTLY, through a page
 * render or a login POST, and reports it as "expected 200, got 500". That is weak signal:
 * it says a page is broken without saying why, and it cannot distinguish "the guard
 * landed" from "the page broke for a new reason". These two call the defective code
 * directly, with nothing else in the way, so when they go green the guard provably landed
 * and when they stay red the exact line is named.
 *
 * MEASURED, by calling each site directly on a fresh RefreshDatabase database:
 *
 *   get_file('uploads/x.png')
 *     ErrorException: Attempt to read property "current_store" on null
 *     at app/Helper/helper.php:145
 *
 *   Utility::addNewData()
 *     Error: Call to a member function hasPermission() on null
 *     at app/Models/Utility.php:3098
 *
 * THE TWO DEFECTS.
 *
 *   1. app/Helper/helper.php:143-145
 *          $admin    = User::where('type', 'super admin')->first();
 *          $settings = Setting::where('store_id', $admin->current_store)->...
 *      $admin is dereferenced with no null check. There is no super-admin row in a fresh
 *      database, and get_file() is called from the shared layouts, so EVERY page render
 *      500s. This is the single largest cause of red in the suite: seven of thirteen.
 *      It is not only a test-environment problem -- any deployment whose super-admin row
 *      is missing, renamed or soft-deleted takes the whole storefront and admin UI down.
 *
 *   2. app/Models/Utility.php:2899 + :3098
 *          $companyRole = Role::where('name', 'LIKE', 'admin')->first();   // :2899
 *          ...
 *          if (! $companyRole->hasPermission($op)) {                        // :3098
 *      $companyRole is dereferenced with no null check inside addNewData(), which runs on
 *      the login path -- so a database with no role named `admin` makes login itself 500.
 *      That is AuthenticationTest::test_users_can_authenticate_using_the_login_screen.
 *
 * WHAT A CORRECT FIX LOOKS LIKE, and what these tests do NOT demand: they do not require a
 * particular return value, only that the call does not throw. Guarding the null and
 * returning early (or falling back to an empty settings array) satisfies them. They must
 * NOT be satisfied by seeding a super admin or an admin role into the test fixture -- that
 * would hide a production-reachable crash behind test data, which is the opposite of the
 * point. See the assertion messages.
 *
 * CROSS-AGENT DEPENDENCY: app/Helper/helper.php and app/Models/Utility.php are owned by
 * another agent in this phase. tests/Feature/Platform/ is owned here. These are recorded
 * as OPEN against app/, and classified FIXED BUT NOT FULLY VERIFIED only once the guards
 * land on the integration branch and these two tests are re-run green.
 */
class NullTenantGuardCanaryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * RED PENDING app/Helper/helper.php:143-145.
     */
    public function test_get_file_survives_a_database_with_no_super_admin_row(): void
    {
        $this->assertSame(
            0,
            \App\Models\User::where('type', 'super admin')->count(),
            'This canary is only meaningful when no super-admin row exists. If the fixture '
            . 'now seeds one, the guard at helper.php:145 is no longer being exercised and '
            . 'this test has become vacuous -- do not "fix" the defect by seeding.'
        );

        try {
            $result = get_file('uploads/x.png');
        } catch (\Throwable $e) {
            $this->fail(
                'get_file() threw instead of degrading gracefully: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine()
                . "\napp/Helper/helper.php:143-145 does "
                . 'User::where(\'type\', \'super admin\')->first() and then reads '
                . '->current_store off the result with no null check. get_file() is called '
                . 'from the shared Blade layouts, so while this throws, every page in the '
                . 'product returns 500 whenever that row is absent. Guard the null; do not '
                . 'seed a super admin to make this pass.'
            );
        }

        // The contract asserted is only "does not throw". get_file() returns the path it
        // was given when it cannot resolve storage settings, which is the graceful
        // degradation this canary wants.
        $this->assertNotNull($result);
    }

    /**
     * RED PENDING app/Models/Utility.php:2899 + :3098.
     */
    public function test_add_new_data_survives_a_database_with_no_admin_role(): void
    {
        $this->assertSame(
            0,
            \App\Models\Role::where('name', 'LIKE', 'admin')->count(),
            'This canary is only meaningful when no role named `admin` exists. If the '
            . 'fixture now seeds one, Utility.php:3098 is no longer being exercised and '
            . 'this test has become vacuous -- do not "fix" the defect by seeding.'
        );

        try {
            Utility::addNewData();
        } catch (\Throwable $e) {
            $this->fail(
                'Utility::addNewData() threw instead of degrading gracefully: '
                . get_class($e) . ': ' . $e->getMessage()
                . ' at ' . $e->getFile() . ':' . $e->getLine()
                . "\napp/Models/Utility.php:2899 does "
                . 'Role::where(\'name\', \'LIKE\', \'admin\')->first() and :3098 calls '
                . '->hasPermission() on the result with no null check. addNewData() runs on '
                . 'the login path, so while this throws, login itself returns 500 on any '
                . 'database with no admin role. Guard the null; do not seed a role to make '
                . 'this pass.'
            );
        }

        $this->assertTrue(true);
    }
}
