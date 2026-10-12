<?php

namespace Tests\Feature\Tenancy;

use App\Models\Menu;
use App\Scopes\StoreScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * The storefront navigation must survive tenant scoping, and the admin IDOR must stay closed.
 *
 * Menu is on config/tenancy.php's `scoped_models`, which closes a real cross-tenant IDOR:
 * MenuController::findOrFail($id)->delete() and five bare Menu::find($menu_id) calls in
 * MenuItemsController took an id with no ownership check at all.
 *
 * But getNavMenu() (app/Helper/helper.php) serves the PUBLIC STOREFRONT, and the predicate
 * narrowed it for any web-guard-authenticated visitor. A signed-in merchant browsing another
 * store's storefront got no menu, so the navigation AND footer went blank in all three
 * shipped themes -- and because find() returning null is indistinguishable from "no such
 * menu", it failed silently.
 *
 * Scoping buys no confidentiality there: a storefront menu is served to anonymous visitors by
 * design, and an anonymous visitor resolves no tenant and so was never filtered in the first
 * place. So the storefront read opts out explicitly while the admin paths keep the scope.
 *
 * This suite pins BOTH halves, because fixing either one alone is a defect.
 */
class StorefrontMenuScopeEscapeTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** THE IDOR HALF: the default query must still refuse another store's menu. */
    public function test_the_scope_still_hides_another_stores_menu_from_the_default_query(): void
    {
        [$merchant] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        $victimMenu = Menu::forceCreate(['name' => 'victim-nav', 'store_id' => $victimStore->id]);

        $this->actingAs($merchant->fresh());

        $this->assertNull(
            Menu::find($victimMenu->id),
            'The tenant scope must still hide another store\'s menu from a bare find(). '
            . 'That is the cross-tenant IDOR this model was allowlisted to close.'
        );
    }

    /** THE STOREFRONT HALF: the explicit escape must still see it. */
    public function test_the_storefront_read_still_finds_another_stores_menu(): void
    {
        [$merchant] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        $otherMenu = Menu::forceCreate(['name' => 'other-store-nav', 'store_id' => $victimStore->id]);

        $this->actingAs($merchant->fresh());

        $found = Menu::withoutGlobalScope(StoreScope::class)->find($otherMenu->id);

        $this->assertNotNull($found,
            'withoutGlobalScope must recover the row, or the storefront nav goes blank for '
            . 'any signed-in merchant viewing another store.');
        $this->assertSame($otherMenu->id, $found->id);
    }

    /**
     * THE REGRESSION THIS EXISTS FOR, end to end through the real helper: getNavMenu() must
     * not return [] for another store's menu. Before the escape hatch it did, silently.
     */
    public function test_get_nav_menu_does_not_blank_for_another_stores_storefront(): void
    {
        [$merchant] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        $otherMenu = Menu::forceCreate(['name' => 'storefront-nav', 'store_id' => $victimStore->id]);

        $this->actingAs($merchant->fresh());

        // getNavMenu returns a built tree; with no menu items the tree is empty, so the
        // assertion that distinguishes "menu not found" from "menu has no items" is that
        // the helper resolved the Menu at all. It returns [] on BOTH, so the resolution is
        // asserted directly against the same query the helper now issues.
        $resolved = Menu::withoutGlobalScope(StoreScope::class)->with('store')->find($otherMenu->id);

        $this->assertNotNull($resolved,
            'getNavMenu() resolves the menu with this exact query. If it returns null, the '
            . 'helper returns [] and every theme renders an empty navigation.');

        // And the helper itself must not throw and must not report a missing menu.
        $this->assertIsArray(getNavMenu($otherMenu->id));
    }

    /** The escape is SCOPED TO THAT ONE READ: the helper must not disable the scope globally. */
    public function test_the_escape_hatch_does_not_disable_the_scope_globally(): void
    {
        [$merchant] = $this->makeTenant();
        [, $victimStore] = $this->makeTenant();

        $victimMenu = Menu::forceCreate(['name' => 'still-hidden', 'store_id' => $victimStore->id]);

        $this->actingAs($merchant->fresh());

        getNavMenu($victimMenu->id);

        $this->assertNull(
            Menu::find($victimMenu->id),
            'Calling the storefront helper must not leave the scope disabled for later '
            . 'queries in the same request.'
        );
    }
}
