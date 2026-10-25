<?php

namespace Tests\Feature\Security;

use App\Classes\Menu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

/**
 * P2-SEC-F4 / P2-SEC-F5 -- the customised sidebar's missing gate, and its unescaped titles.
 * =============================================================================================
 *
 * WHAT WAS WRONG. With `SidebarCustomization` active, `getSideMenu()` stops using
 * `App\Classes\Menu::add()` -- the ONLY module-entitlement and permission check the navigation
 * has -- and pushes `side_menus_option` rows straight onto `$menu->menu`. It builds each row
 * with `'module' => $item->module ?? null` and `'permission' => $item->permission ?? null`, and
 * BOTH ARE STRUCTURALLY ALWAYS NULL: the table has neither column, in the module's own migration
 * or in the restored production dump, and the seeder that knows the values drops them on insert.
 * So the platform-authored rows (`created_by = 0`, `store_id = null`) rendered for every
 * merchant and every staff member regardless of plan or role, and `generateSideMenu()` then
 * interpolated the row's `title`, `icon` and `href` into HTML without escaping.
 *
 * WHY THE GATE IS TESTED AT THIS SEAM AND NOT OVER HTTP. `getSideMenu()` only reaches the
 * database branch when `module_is_active('SidebarCustomization')` is true AND
 * `side_menus_option` exists -- and that table's migration is contributed by the module's own
 * provider, which `PackageServiceProvider::loadDynamicAddons()` registers from
 * `$this->app->booted()`. The `add_on_managers` row therefore has to exist BEFORE the
 * application boots, which no in-process test can arrange after `RefreshDatabase` has run. A
 * test asserting the HTTP shape would be one of the vacuous kinds the project has already been
 * bitten by (BackupRestoreModuleGatingTest's docblock records the same constraint and answers it
 * with subprocess scripts).
 *
 * So the gate is driven where it actually decides, with REAL principals and REAL Laratrust
 * roles: the listeners are fired against a `Menu`, which records what it refused, and the
 * refusal is then asked of a database-shaped row. That is the whole mechanism, and every
 * assertion below fails if the production change is reverted.
 */
class CustomSidebarAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A `side_menus_option` row as `getSideMenu()` builds it -- both gate columns null, as in production. */
    private function databaseRow(string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'category' => null,
            'title' => 'Orders',
            'icon' => 'home',
            'name' => $name,
            'parent' => null,
            'order' => 1,
            'link_type' => 'route',
            'route' => '',
            'module' => null,
            'window_type' => null,
            'is_enable' => 'on',
            'permission' => null,
        ], $overrides);
    }

    // =========================================================================================
    // F4 -- the permission gate
    // =========================================================================================

    /**
     * THE FINDING ITSELF: a principal the listeners' gate refuses must not get the same menu
     * entry back through a database row.
     *
     * The premise is asserted first, because this test is worthless if the gate happened to
     * permit the name anyway -- then "the row was refused" would be measuring nothing.
     */
    public function test_a_row_naming_a_menu_entry_the_caller_may_not_see_is_refused(): void
    {
        $owner = $this->makeMerchant();

        $this->actingAs($owner);

        $gate = new Menu($owner);
        event(new \App\Events\CompanyMenuEvent($gate));

        // PREMISE. 'product' is CompanyMenuListener's Products entry, permission
        // 'Manage Products', which this bare user does not hold. If the listeners did not
        // refuse it there is nothing here to honour.
        $this->assertArrayHasKey(
            'product',
            $gate->denied,
            'premise: the listener gate must have refused "product" for a user holding no permissions'
        );

        $this->assertFalse(
            sideMenuRowIsPermitted($this->databaseRow('product'), $gate),
            'a database row may not reintroduce a menu entry the one gate refused'
        );
    }

    /**
     * THE OTHER DIRECTION, so the gate is not simply refusing everything -- which would pass the
     * test above while breaking every sidebar in the application.
     */
    public function test_a_row_naming_an_entry_the_caller_may_see_is_permitted(): void
    {
        $owner = $this->makeMerchant();
        $this->grantPermission($owner, 'Manage Products');

        $this->actingAs($owner);

        $gate = new Menu($owner);
        event(new \App\Events\CompanyMenuEvent($gate));

        $this->assertArrayNotHasKey(
            'product',
            $gate->denied,
            'premise: holding Manage Products must get the entry past the listener gate'
        );

        $this->assertTrue(sideMenuRowIsPermitted($this->databaseRow('product'), $gate));
    }

    /**
     * A ROW THE LISTENERS NEVER OFFERED IS NOT REFUSED, and that is a deliberate limit rather
     * than an oversight: a merchant's own custom link has no permission on record, and inventing
     * one would delete their link. Pinned so the limit is a decision somebody made, not a
     * surprise -- the module half of that case is covered by the route-existence check below.
     */
    public function test_a_custom_row_the_listeners_do_not_know_is_left_alone(): void
    {
        $owner = $this->makeMerchant();
        $this->actingAs($owner);

        $gate = new Menu($owner);
        event(new \App\Events\CompanyMenuEvent($gate));

        $this->assertArrayNotHasKey('a-merchants-own-link', $gate->denied);
        $this->assertTrue(sideMenuRowIsPermitted($this->databaseRow('a-merchants-own-link'), $gate));
    }

    /**
     * FORWARD COMPATIBILITY, limb 1 of `sideMenuRowIsPermitted()`. The columns do not exist
     * today; if a deployment adds them, the row's own declaration must be honoured. Asserted so
     * that limb is not dead code nobody ever ran.
     */
    public function test_a_rows_own_permission_column_is_honoured_when_a_deployment_has_one(): void
    {
        $owner = $this->makeMerchant();
        $this->actingAs($owner);

        $gate = new Menu($owner);

        $this->assertFalse(
            sideMenuRowIsPermitted(
                $this->databaseRow('x', ['module' => 'Base', 'permission' => 'Manage Products']),
                $gate
            ),
            'a row declaring a permission the caller lacks must be refused'
        );

        $this->grantPermission($owner, 'Manage Products');
        $gateAfter = new Menu($owner);

        $this->assertTrue(
            sideMenuRowIsPermitted(
                $this->databaseRow('x', ['module' => 'Base', 'permission' => 'Manage Products']),
                $gateAfter
            )
        );
    }

    /**
     * MODULE ENTITLEMENT, limb 1. `ActivatedModule()` returns the active modules plus 'Base', so
     * a row naming a module that is not active is refused.
     */
    public function test_a_rows_own_module_column_is_honoured_when_a_deployment_has_one(): void
    {
        $owner = $this->makeMerchant();
        $this->actingAs($owner);

        $gate = new Menu($owner);

        $this->assertTrue(
            sideMenuRowIsPermitted($this->databaseRow('x', ['module' => 'Base']), $gate),
            'premise: Base is always in the activated list'
        );

        $this->assertFalse(
            sideMenuRowIsPermitted($this->databaseRow('x', ['module' => 'AModuleNobodyBought']), $gate),
            'a row naming an unentitled module must be refused'
        );
    }

    // =========================================================================================
    // F4 -- module entitlement via route existence, which is also an availability fix
    // =========================================================================================

    /**
     * A ROW POINTING AT AN UNREGISTERED ROUTE IS DROPPED, NOT RENDERED AND NOT THROWN FROM.
     *
     * An add-on's routes exist only while `add_on_managers` holds its row, so `Route::has()` is
     * a true reading of module activation for a table with no `module` column. Before this
     * change the same row reached `route($name)`, which THROWS -- from inside the sidebar that
     * every admin page renders. The production dump holds rows naming `preorders.index` and
     * `additionalcustomfields.index`, so turning either module off served HTTP 500 everywhere.
     */
    public function test_a_row_naming_an_unregistered_route_is_dropped_instead_of_throwing(): void
    {
        $this->assertFalse(
            Route::has('a.route.no.module.registered'),
            'premise: the route must genuinely not exist'
        );

        $html = generateSideMenu([
            $this->databaseRow('gone', ['route' => 'a.route.no.module.registered']),
        ], null);

        $this->assertSame('', $html, 'the row must be dropped');
    }

    public function test_a_row_naming_a_registered_route_still_renders(): void
    {
        Route::get('/p2-sidebar-probe', fn () => '')->name('p2.sidebar.probe');

        // `RouteCollection` caches its name lookup table, and a route added after the
        // collection was built is NOT in it -- so `Route::has()` answers false and this test
        // would measure the drop path while claiming to measure the keep path. Refreshing it is
        // what makes the premise below true; without the premise this test was green for the
        // wrong reason in its first run.
        Route::getRoutes()->refreshNameLookups();

        $this->assertTrue(Route::has('p2.sidebar.probe'), 'premise: the route must be registered');

        $html = generateSideMenu([
            $this->databaseRow('here', ['route' => 'p2.sidebar.probe']),
        ], null);

        $this->assertStringContainsString('p2-sidebar-probe', $html);
        $this->assertStringContainsString('Orders', $html);
    }

    /**
     * The empty-route row is a PARENT/heading, not a module row, and must keep rendering with
     * `#`. Without this the gate above would delete every zone heading in the custom sidebar.
     */
    public function test_a_row_with_no_route_at_all_still_renders_as_a_heading(): void
    {
        $html = generateSideMenu([$this->databaseRow('heading', ['route' => ''])], null);

        $this->assertStringContainsString('href="#"', $html);
    }

    // =========================================================================================
    // F5 -- output escaping
    // =========================================================================================

    public function test_a_database_sourced_title_is_escaped(): void
    {
        $html = generateSideMenu([
            $this->databaseRow('x', [
                'route' => '',
                'title' => '</span></a><script>alert(1)</script>',
            ]),
        ], null);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    /**
     * `icon` is interpolated INSIDE a class attribute, so the payload does not need a `<`: a
     * quote is enough to break out and add an event handler.
     */
    public function test_a_database_sourced_icon_cannot_break_out_of_its_attribute(): void
    {
        $html = generateSideMenu([
            $this->databaseRow('x', [
                'route' => '',
                'icon' => 'home" onmouseover="alert(1)',
            ]),
        ], null);

        /*
         * NOT `assertStringNotContainsString('onmouseover')`. That is what this assertion said
         * first, and it FAILED against correct output: the allowlist strips the quotes, the
         * space and the `=`, leaving the letters `homeonmouseoveralert1` as an inert class name.
         * The letters are not the vulnerability -- the attribute boundary is. So the assertion
         * is that no quote survived inside the class attribute and no event handler was formed.
         */
        $this->assertStringContainsString('class="ti ti-homeonmouseoveralert1"', $html);
        $this->assertStringNotContainsString('onmouseover=', $html);
        $this->assertStringNotContainsString('ti-home"', $html);
    }

    /**
     * ESCAPING IS NOT ENOUGH FOR AN HREF. `javascript:alert(1)` survives htmlspecialchars
     * unchanged and still executes on click, so the scheme is allowlisted.
     */
    public function test_an_external_row_cannot_carry_a_javascript_url(): void
    {
        $this->assertSame('#', sideMenuSafeExternalUrl('javascript:alert(1)'));
        $this->assertSame('#', sideMenuSafeExternalUrl('JaVaScRiPt:alert(1)'));
        $this->assertSame('#', sideMenuSafeExternalUrl(' javascript:alert(1)'));
        $this->assertSame('#', sideMenuSafeExternalUrl('data:text/html,<script>alert(1)</script>'));
        $this->assertSame('#', sideMenuSafeExternalUrl('//evil.example.com'));

        $this->assertSame('https://example.com/x', sideMenuSafeExternalUrl('https://example.com/x'));
        $this->assertSame('http://example.com/x', sideMenuSafeExternalUrl('http://example.com/x'));
    }

    /**
     * THE SAME RULE THROUGH THE RENDERER, not only through the function.
     *
     * The assertion above drives `sideMenuSafeExternalUrl()` directly, so it stays green even if
     * `sideMenuHref()` stops calling it -- measured during the bite test, where reverting the
     * call left that case passing. This one fails in that revert, which is what makes the
     * allowlist's reachability asserted rather than assumed.
     */
    public function test_a_javascript_url_does_not_reach_the_rendered_href(): void
    {
        $html = generateSideMenu([
            $this->databaseRow('x', [
                'link_type' => 'external',
                'route' => json_encode(['href' => ['javascript:alert(1)'], 'type' => ['']]),
            ]),
        ], null);

        $this->assertStringContainsString('href="#"', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_an_external_row_renders_its_url_escaped(): void
    {
        $html = generateSideMenu([
            $this->databaseRow('x', [
                'link_type' => 'external',
                'route' => json_encode(['href' => ['https://example.com/?a=1&b=2'], 'type' => ['']]),
            ]),
        ], null);

        $this->assertStringContainsString('a=1&amp;b=2', $html);
    }

    public function test_sideMenuIconSlug_keeps_every_icon_the_shipped_data_uses(): void
    {
        foreach (['home', 'layout-2', 'message-circle', 'user', 'settings'] as $icon) {
            $this->assertSame($icon, sideMenuIconSlug($icon));
        }
    }
}
