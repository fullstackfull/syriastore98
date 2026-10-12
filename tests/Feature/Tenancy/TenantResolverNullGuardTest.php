<?php

namespace Tests\Feature\Tenancy;

use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Utility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE THREE NULL-GUARD DEFECTS ON THE `current_store` / ROLE RESOLUTION PATH.
 *
 * These are REAL LATENT PRODUCT DEFECTS, not test artefacts, and all three are the same shape: a
 * lookup that can legitimately return null, followed immediately by a property or method read on
 * it with no guard. Each one turns a whole page into a 500.
 *
 *   1. app/Helper/helper.php get_file() -- `User::where('type','super admin')->first()` then
 *      `$admin->current_store`. `get_file()` is called from the shared layout partials, so this
 *      500s EVERY page on a database with no super-admin row. Measured: 7 of the 13 Feature
 *      failures on p1b/integration @ 17cb2f91 were this single line.
 *   2. app/Helper/helper.php isEnabled() -- `User::find($enableSetting->created_by)` then
 *      `$admin->current_store`. Found while fixing (1); 500s domain/subdomain resolution for a
 *      setting whose creator was deleted.
 *   3. app/Models/Utility.php addNewData() -- `Role::where('name','LIKE','admin')->first()` then
 *      `->hasPermission()`, and the same for the `super admin` role. addNewData() runs from
 *      AuthenticatedSessionController::store():83, so this 500s LOGIN and REGISTRATION. Measured:
 *      failures 2 and 8 of the 13.
 *
 * WHY THEY ARE TESTED HERE. `users.type` is a plain string column and roles are ordinary rows, so
 * "there is no super admin" and "there is no role named admin" are states a real deployment
 * reaches: a renamed or soft-deleted super admin, a partially seeded install, a tenant-only
 * install. The guards therefore have to be asserted against a database that genuinely lacks those
 * rows, which is what each test below builds.
 *
 * WHAT A FIXED GUARD MUST DO -- and these are the assertions, because "does not throw" is not
 * enough on its own:
 *   - get_file() must return the LOCAL url, which is what an empty settings array already means
 *     everywhere else in that function. Not '', and not an exception.
 *   - isEnabled() must return FALSE. A custom domain whose owner no longer exists cannot be
 *     confirmed enabled, so this one fails CLOSED on purpose.
 *   - addNewData() must still create the permission ROWS and still return true. Skipping the
 *     grant to a role that does not exist is the only thing that can be done with it; abandoning
 *     the permission inserts as well would be a second defect.
 */
class TenantResolverNullGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Assert the database really is in the state the guard is about. Without this a passing test
     * might only mean the seeder happened to leave no super admin OR that it left one.
     */
    private function assertNoSuperAdminExists(): void
    {
        $this->assertSame(
            0,
            DB::table('users')->where('type', 'super admin')->count(),
            'This test is about the no-super-admin state; it must actually hold or the guard is '
            . 'never exercised.'
        );
    }

    // -------------------------------------------------------------------------------------
    //  1. get_file()
    // -------------------------------------------------------------------------------------

    public function test_get_file_returns_a_local_url_when_no_super_admin_exists(): void
    {
        DB::table('users')->where('type', 'super admin')->delete();
        $this->assertNoSuperAdminExists();

        // Before the guard this raised:
        //   ErrorException: Attempt to read property "current_store" on null
        //   in app/Helper/helper.php:145
        $this->assertSame(url('uploads/x.png'), get_file('uploads/x.png'));
    }

    public function test_get_file_still_short_circuits_an_empty_path_with_no_super_admin(): void
    {
        DB::table('users')->where('type', 'super admin')->delete();

        // The `empty($path)` early return sits AFTER the settings read, so it was unreachable
        // without the guard -- an empty path 500d too.
        $this->assertSame('', get_file(''));
    }

    public function test_get_file_behaviour_is_unchanged_when_a_super_admin_does_exist(): void
    {
        // The guard must not alter the working path. With no `storage_setting` row the function
        // has always fallen through to the local url, and it still must.
        $admin = new User();
        $admin->forceFill([
            'name' => 'Platform operator',
            'email' => 'operator-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'super admin',
            'current_store' => 1,
        ])->save();

        $this->assertSame(url('uploads/y.png'), get_file('uploads/y.png'));
    }

    public function test_get_file_honours_a_super_admins_settings_so_the_guard_did_not_break_the_feature(): void
    {
        // Proves the settings read is still LIVE rather than guarded away: a `storage_setting` of
        // anything other than wasabi/s3 must still take the local branch, and the row must be
        // found via the super admin's `current_store` exactly as before.
        $admin = new User();
        $admin->forceFill([
            'name' => 'Platform operator',
            'email' => 'operator-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'super admin',
            'current_store' => 7,
        ])->save();

        Setting::insert([
            ['name' => 'storage_setting', 'value' => 'local', 'store_id' => 7, 'created_by' => $admin->id],
        ]);

        $this->assertSame(url('uploads/z.png'), get_file('uploads/z.png'));
    }

    // -------------------------------------------------------------------------------------
    //  2. isEnabled()
    // -------------------------------------------------------------------------------------

    public function test_is_enabled_fails_closed_when_the_settings_creator_no_longer_exists(): void
    {
        // `created_by` points at a user id that is not present. Before the guard this raised
        // "Attempt to read property \"current_store\" on null".
        $setting = new Setting();
        $setting->forceFill([
            'name' => 'domains',
            'value' => 'on',
            'store_id' => 3,
            'created_by' => 987654,
        ]);

        $this->assertFalse(isEnabled($setting));
    }

    public function test_is_enabled_still_returns_true_for_a_live_owner_whose_store_matches(): void
    {
        $owner = new User();
        $owner->forceFill([
            'name' => 'Merchant',
            'email' => 'merchant-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'admin',
            'current_store' => 3,
        ])->save();

        $setting = new Setting();
        $setting->forceFill([
            'name' => 'domains',
            'value' => 'on',
            'store_id' => 3,
            'created_by' => $owner->id,
        ]);

        $this->assertTrue(isEnabled($setting), 'The guard must not change the working answer.');
    }

    public function test_is_enabled_still_returns_false_for_a_live_owner_whose_store_differs(): void
    {
        $owner = new User();
        $owner->forceFill([
            'name' => 'Merchant',
            'email' => 'merchant-' . uniqid() . '@example.test',
            'password' => bcrypt('not-a-real-credential'),
            'type' => 'admin',
            'current_store' => 99,
        ])->save();

        $setting = new Setting();
        $setting->forceFill([
            'name' => 'domains',
            'value' => 'on',
            'store_id' => 3,
            'created_by' => $owner->id,
        ]);

        $this->assertFalse(isEnabled($setting));
    }

    // -------------------------------------------------------------------------------------
    //  3. Utility::addNewData()
    // -------------------------------------------------------------------------------------

    public function test_add_new_data_survives_a_database_with_no_admin_or_super_admin_role(): void
    {
        DB::table('roles')->delete();

        $this->assertSame(0, DB::table('roles')->count(), 'This test is about the no-roles state.');

        // Before the guard this raised:
        //   Error: Call to a member function hasPermission() on null
        //   in app/Models/Utility.php:3098
        // reached from AuthenticatedSessionController::store():83 -- i.e. on LOGIN.
        $this->assertTrue(Utility::addNewData());

        // And the permission rows must still have been created. A guard that bailed out of the
        // whole loop would be a second defect wearing the first one's clothes.
        $this->assertGreaterThan(
            0,
            DB::table('permissions')->count(),
            'addNewData() must still create the permission rows; only the GRANT to a missing role '
            . 'is skipped.'
        );
    }

    public function test_add_new_data_still_grants_permissions_when_the_roles_do_exist(): void
    {
        // The positive half: the guard must not stop a real role from being granted.
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        $this->assertTrue(Utility::addNewData());

        $this->assertTrue(
            $role->fresh()->hasPermission('Manage Dashboard'),
            'With an `admin` role present, addNewData() must still grant it its permissions. If '
            . 'this fails the guard has disabled the feature instead of protecting it.'
        );
    }
}
