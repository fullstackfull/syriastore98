<?php

namespace Tests\Feature\Security;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P2-SEC-F4 -- `getSideMenu()` DRIVEN, not approximated.
 * =============================================================================================
 *
 * WHY THIS FILE EXISTS, AND IT IS NOT A NICETY. The independent review of the first commit found
 * that every assertion in CustomSidebarAuthorizationTest drives the helpers
 * (`sideMenuRowIsPermitted`, `generateSideMenu`) directly, so the ONE LINE that applies the gate
 * in production -- the `continue` inside `getSideMenu()`'s row loop -- could be deleted and all
 * nineteen stayed green. MEASURED: deleting it changed nothing any of them could see. A control
 * with no test on its call site is a control that can be removed by accident.
 *
 * THE FOUR PRECONDITIONS, each arranged explicitly and each asserted. `getSideMenu()` reaches the
 * database branch only when all four hold, and the reason the earlier file did not drive it is
 * that one of them looks impossible:
 *
 *   1. `module_is_active('SidebarCustomization')`. Resolved at REQUEST time, not boot time:
 *      `Module::has()` reads the package directory (present in the repository) and
 *      `Module::find($name)->isEnabled()` reads `add_on_managers`. So an inserted row is enough,
 *      and this is the step BackupRestoreModuleGatingTest's docblock is right about for ROUTES
 *      but which does not bind a plain function call.
 *   2. `ActivatedModule()` must contain the module, or `module_is_active` returns false for a
 *      merchant. It reads `AddOnManager::where('is_enable', 1)` plus the owner's
 *      `user_active_modules`.
 *   3. `Schema::hasTable('side_menus_option')`. The module's migration never runs here, because
 *      its provider is not registered, so the test creates the table from the migration's own
 *      column list. That is the ONLY schema this test invents, and it is copied, not guessed.
 *   4. The `SideMenuOption` model class must be loadable. `app/Helper/helper.php:34` imports it
 *      from the module's namespace, which `loadAddons()` would have registered with the Composer
 *      loader at boot. Rather than touch the process-global ClassLoader -- which CLAUDE.md names
 *      as a measured pollution source -- the model file is required directly. It declares its own
 *      namespace, so requiring it defines exactly the class the import names and nothing else.
 *
 * If any precondition silently stopped holding, this file would skip rather than pass: each is an
 * assertion or a documented skip, never an assumption.
 */
class CustomSidebarRenderPathTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** Column-for-column from the module's own migration, 2024_11_26_050003_*. */
    private function createSideMenuTables(): void
    {
        Schema::create('side_menus_option', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('menu_type')->nullable();
            $table->string('type')->nullable();
            $table->string('name')->nullable();
            $table->string('icon')->nullable();
            $table->string('link_type')->nullable();
            $table->string('link')->nullable();
            $table->integer('position')->nullable()->default(0);
            $table->string('parent_id')->nullable();
            $table->integer('order')->default(0)->index();
            $table->string('window_type')->nullable();
            $table->string('is_enable')->default('off');
            $table->unsignedBigInteger('store_id')->nullable()->index();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });

        // NOTE THE ABSENCE. Neither `module` nor `permission` is here, because neither is in the
        // migration -- which is the whole premise of P2-SEC-F4. If a future migration adds them,
        // this table definition must gain them and `sideMenuRowIsPermitted()`'s first limb stops
        // being inert.
        // `sidemenu_order`, SINGULAR AND UNPREFIXED -- not the `side_menu_orders` Eloquent
        // would infer. Read from 2024_12_09_045412_create_sidemenu_order_table.php, which is why
        // the first version of this file failed with "no such table: sidemenu_order": the model
        // sets `$table` explicitly and the convention does not apply.
        Schema::create('sidemenu_order', function ($table) {
            $table->id();
            $table->unsignedBigInteger('menu_id')->nullable();
            $table->string('position')->nullable();
            $table->longText('order')->nullable();
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
        });
    }

    private function loadSideMenuModels(): void
    {
        $base = base_path('packages/workdo/SidebarCustomization/src/app/Models/');

        foreach (['SideMenuOption.php', 'SideMenuOrder.php'] as $file) {
            if (! file_exists($base . $file)) {
                $this->markTestSkipped("the SidebarCustomization module is not vendored here ({$file})");
            }
        }

        if (! class_exists(\Workdo\SidebarCustomization\app\Models\SideMenuOption::class, false)) {
            require_once $base . 'SideMenuOption.php';
        }

        if (! class_exists(\Workdo\SidebarCustomization\app\Models\SideMenuOrder::class, false)) {
            require_once $base . 'SideMenuOrder.php';
        }
    }

    private function activateModule(): void
    {
        DB::table('add_on_managers')->insert([
            'name' => 'Sidebar Customization',
            'module' => 'SidebarCustomization',
            'is_enable' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * PRECONDITION 1b, AND IT COST A REAL FALSE READING. `Module::find($name)` wraps its
         * lookup in `Cache::rememberForever($name, ...)` and caches the whole Module OBJECT,
         * `$addon` property included. Something earlier in the request -- SecurityTestCase's
         * updater-gate fixture reaches module code -- had already resolved
         * 'SidebarCustomization' while no `add_on_managers` row existed, so the cache held a
         * Module whose `$addon` was null and `isEnabled()` answered FALSE against a row that was
         * plainly there. MEASURED: the row reads `is_enable: 1` straight from the table while
         * `module_is_active()` returns false; one `Cache::flush()` and it returns true.
         *
         * This is exactly the trap CLAUDE.md records for `Cache::remember('admin_details', ...)`
         * -- a fixture written after a cached read is invisible to it -- and under
         * `CACHE_STORE=array` the stale entry lives for the rest of the test.
         */
        Cache::flush();
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'title' => 'A Row',
            'menu_type' => 'mainmenu',
            'type' => 'admin',
            'name' => 'a-row',
            'icon' => 'home',
            'link_type' => 'route',
            'link' => '',
            'position' => 1,
            'parent_id' => null,
            'order' => 1,
            'window_type' => null,
            'is_enable' => 'on',
            'store_id' => null,
            'created_by' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    /**
     * THE FINDING, DRIVEN THROUGH THE PRODUCTION FUNCTION.
     *
     * A platform-authored row (`created_by = 0`, `store_id = null`) naming a menu entry this
     * merchant's role is refused must not appear in what `getSideMenu()` returns -- and a row
     * naming something the listeners never offered must still appear, so the gate is shown to be
     * selective rather than simply empty.
     */
    public function test_getSideMenu_drops_a_platform_row_the_caller_may_not_see(): void
    {
        $this->loadSideMenuModels();
        $this->createSideMenuTables();
        $this->activateModule();

        [$merchant] = $this->makeTenant();

        // Precondition 2: the module must be in the caller's activated set.
        // Columns read from the live schema, not assumed: user_active_modules is
        // (id, user_id, module, created_at, updated_at) -- there is NO store_id, and passing
        // one failed the insert outright.
        DB::table('user_active_modules')->insert([
            'module' => 'SidebarCustomization',
            'user_id' => $merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($merchant);

        // Preconditions 1 and 3, asserted rather than assumed. Without these the function
        // returns the LISTENER-fed menu and every assertion below would be measuring the wrong
        // code path entirely -- passing or failing for reasons unrelated to the gate.
        $this->assertTrue(Schema::hasTable('side_menus_option'), 'precondition 3');
        $this->assertTrue(
            module_is_active('SidebarCustomization'),
            'precondition 1: without this getSideMenu() takes the listener branch and this test '
            . 'measures nothing about the database rows'
        );

        DB::table('side_menus_option')->insert([
            // 'product' IS a CompanyMenuListener name, permission 'Manage Products', which this
            // merchant does not hold -- so the gate refuses it and the row must be dropped.
            $this->row(['title' => 'Products Row', 'name' => 'product']),
            // A name no listener offers: nothing on record to refuse it with, so it stays. This
            // is the documented limit of the approach, pinned as a decision.
            $this->row(['title' => 'Merchant Own Row', 'name' => 'a-merchants-own-link', 'order' => 2]),
        ]);

        $html = getSideMenu();

        $this->assertStringNotContainsString(
            'Products Row',
            $html,
            'a platform-authored row reached a merchant who may not see that menu entry -- the '
            . 'gate in getSideMenu() is not being applied'
        );

        $this->assertStringContainsString(
            'Merchant Own Row',
            $html,
            'the gate dropped a row it has no permission on record for; it is refusing '
            . 'everything rather than refusing the right thing'
        );
    }

    /**
     * AND THE SAME ROW IS KEPT ONCE THE CALLER HOLDS THE PERMISSION. Without this, the case
     * above would pass against a `getSideMenu()` that dropped every listener-known row
     * unconditionally.
     */
    public function test_getSideMenu_keeps_the_row_once_the_caller_holds_the_permission(): void
    {
        $this->loadSideMenuModels();
        $this->createSideMenuTables();
        $this->activateModule();

        [$merchant] = $this->makeTenant();

        // Columns read from the live schema, not assumed: user_active_modules is
        // (id, user_id, module, created_at, updated_at) -- there is NO store_id, and passing
        // one failed the insert outright.
        DB::table('user_active_modules')->insert([
            'module' => 'SidebarCustomization',
            'user_id' => $merchant->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->grantPermission($merchant, 'Manage Products');
        $this->actingAs($merchant);

        $this->assertTrue(module_is_active('SidebarCustomization'), 'precondition 1');

        DB::table('side_menus_option')->insert([
            $this->row(['title' => 'Products Row', 'name' => 'product']),
        ]);

        $this->assertStringContainsString('Products Row', getSideMenu());
    }

    /**
     * THE OPERATOR IS ADMITTED UNCONDITIONALLY, which is the deliberate zero-regression choice
     * recorded in `sideMenuRowIsPermitted()`'s docblock: the operator configures this sidebar,
     * holds no merchant role, and a permission with no row can be held by nobody, so enforcing
     * against them is a lockout rather than a tightening.
     *
     * Pinned here because it is a DECISION. If it is ever revisited, this is the test that says
     * what the current behaviour is and why.
     */
    public function test_getSideMenu_does_not_gate_the_platform_operator(): void
    {
        $this->loadSideMenuModels();
        $this->createSideMenuTables();
        $this->activateModule();

        $operator = $this->makePlatformAdmin();
        $this->actingAs($operator);

        $this->assertTrue(module_is_active('SidebarCustomization'), 'precondition 1');

        DB::table('side_menus_option')->insert([
            // A merchant permission the operator's role does not hold.
            $this->row(['title' => 'Operator Row', 'name' => 'product', 'type' => 'super admin']),
        ]);

        $this->assertStringContainsString(
            'Operator Row',
            getSideMenu(),
            'the operator lost a row from their own custom sidebar; see the zero-regression '
            . 'argument in sideMenuRowIsPermitted()'
        );
    }
}
