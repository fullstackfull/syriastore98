<?php

namespace Tests\Feature\Security;

use App\Models\Menu;
use App\Models\MenuItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Security\MinimalRows;

/**
 * P1-SEC-80, third round -- `MenuController::updateOrder` wrote any tenant's menu items by id.
 * ============================================================================================================================================
 *
 * The handler resolves the menu it was asked about (`Menu::find`, which is store-scoped) and then, for every item in the posted `menu_structure` and every child of it, runs
 * `MenuItem::where('id', <posted id>)->update([parent_id, order, target, icon_type, icon])`. `MenuItem` has no `store_id` and is not in `scoped_models`, and the posted ids are never compared
 * with the menu: a merchant who holds `Edit Menu` rewrites ANOTHER store's items -- their order, their nesting, their link target and their `icon`, which the editor's preview writes into a
 * modal with `innerHTML`. `deleteItem` and `deleteCustomLink` were closed in P2.5 by asking the owning store one hop away (`menus`); this is the third door of the same family.
 *
 * Each case drives the real route as the merchant and asks the TABLE, not the Eloquent model, whether the foreign row changed (`MenuItem` is `Cachable`).
 */
class MenuItemOrderUpdateTenancyTest extends SecurityTestCase
{
    use MinimalRows;
    use RefreshDatabase;

    private const ICON = '"><img src=x onerror=XSS-6>';

    /** @return array{0:\App\Models\User,1:\App\Models\Store,2:Menu,3:MenuItem} */
    private function merchantWithMenu(string $label): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Menu');
        $menu = $this->minimalRow(Menu::class, ['store_id' => $store->id, 'name' => $label . ' menu']);
        $item = $this->minimalRow(MenuItem::class, [
            'menu_id' => $menu->id, 'menu_itemable_type' => 'App\Models\CustomLink', 'menu_itemable_id' => 1, 'parent_id' => null, 'order' => 1, 'target' => '_self', 'icon_type' => null, 'icon' => null,
        ]);

        return [$merchant, $store, $menu, $item];
    }

    private function row(int $id): object
    {
        return DB::table('menu_items')->where('id', $id)->first();
    }

    private function submit($as, Menu $menu, array $structure)
    {
        return $this->actingAs($as)->postJson('/menu/update-order', ['menuId' => $menu->id, 'menuName' => $menu->name, 'menu_structure' => $structure]);
    }

    private function entry(MenuItem $item, array $overrides = []): array
    {
        return $overrides + ['id' => $item->id, 'order' => 7, 'target' => '_blank', 'icon_type' => 'class', 'icon' => 'fa fa-star', 'children' => []];
    }

    public function test_a_merchant_reorders_the_items_of_their_own_menu(): void
    {
        [$merchant, , $menu, $item] = $this->merchantWithMenu('A');

        $response = $this->submit($merchant, $menu, [$this->entry($item)]);

        $response->assertOk();
        $this->assertTrue((bool) $response->json('status'), 'premise: the handler accepts a structure for the merchant\'s own menu');
        $this->assertSame(7, (int) $this->row($item->id)->order, 'premise: the own item was written');
    }

    public function test_a_merchant_cannot_rewrite_another_stores_menu_item_through_their_own_menu(): void
    {
        [$merchantA, , $menuA] = $this->merchantWithMenu('A');
        [, , , $foreign] = $this->merchantWithMenu('B');
        $before = (array) $this->row($foreign->id);

        $this->submit($merchantA, $menuA, [$this->entry($foreign, ['icon' => self::ICON])]);

        $after = (array) $this->row($foreign->id);
        $this->assertSame($before['order'], $after['order'], 'the foreign item was re-ordered');
        $this->assertSame($before['target'], $after['target'], 'the foreign item\'s target was rewritten');
        $this->assertSame($before['icon'], $after['icon'], 'the foreign item\'s icon was rewritten');
    }

    public function test_a_merchant_cannot_rewrite_another_stores_menu_item_as_a_child_either(): void
    {
        [$merchantA, , $menuA, $own] = $this->merchantWithMenu('A');
        [, , , $foreign] = $this->merchantWithMenu('B');
        $before = (array) $this->row($foreign->id);

        $this->submit($merchantA, $menuA, [$this->entry($own, ['children' => [$this->entry($foreign, ['icon' => self::ICON])]])]);

        $after = (array) $this->row($foreign->id);
        $this->assertSame($before['parent_id'], $after['parent_id'], 'the foreign item was re-parented under the merchant\'s item');
        $this->assertSame($before['icon'], $after['icon'], 'the foreign item\'s icon was rewritten through the children');
    }

    public function test_an_item_of_another_menu_of_the_same_store_is_not_rewritten_through_this_menu_either(): void
    {
        [$merchant, $store, $menu] = $this->merchantWithMenu('A');
        $otherMenu = $this->minimalRow(Menu::class, ['store_id' => $store->id, 'name' => 'second menu']);
        $sibling = $this->minimalRow(MenuItem::class, [
            'menu_id' => $otherMenu->id, 'menu_itemable_type' => 'App\Models\CustomLink', 'menu_itemable_id' => 2, 'parent_id' => null, 'order' => 1, 'target' => '_self', 'icon_type' => null, 'icon' => null,
        ]);

        $this->submit($merchant, $menu, [$this->entry($sibling)]);

        $this->assertSame(1, (int) $this->row($sibling->id)->order, 'an item of the store\'s OTHER menu was written through this menu\'s structure');
    }
}
