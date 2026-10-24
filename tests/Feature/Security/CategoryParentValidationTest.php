<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-11 -- `parent_id` OF A CATEGORY WAS WRITTEN UNVALIDATED: A NAME LEAK ACROSS STORES AND A CYCLE THAT FATALS.
 * ==================================================================================================================
 *
 * CategoryController::store() and ::update() wrote `parent_id` straight from request input. The value is rendered back through an unscoped
 * relation (`Category::parent_category()` is a plain belongsTo; CategoryDataTable prints `optional($category->parent_category)->name`), so a
 * merchant who PUT their own category with `parent_id=N` and opened /category was shown store B's category name in the Parent column -
 * incrementing N walks the whole catalogue taxonomy of the platform. The create/edit dropdown is store-scoped so the UI never offers a foreign
 * id, but that is obscurity: the POST is hand-craftable. The same missing rule admits `parent_id = own id` (or any loop), which sends
 * `buildCategoryTree()` into unbounded recursion - a fatal on the caller's own create and edit pages.
 *
 * The rule: `parent_id` is 0/absent, or the id of a category in the caller's own store (the platform operator is exempt, as in P1-INV-08's
 * predicate), and it must not be the category itself or one of its descendants. A refusal is the controller's existing redirect with an error.
 */
class CategoryParentValidationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function categoryIn($store, string $name, int $parentId = 0): Category
    {
        return Category::forceCreate([
            'name' => $name, 'slug' => 'collections/' . $name . '-' . uniqid(), 'parent_id' => $parentId,
            'trending' => 0, 'status' => 1, 'image_path' => null, 'store_id' => $store->id,
        ]);
    }

    private function merchant(): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Edit Product Category');
        $this->grantPermission($merchant, 'Create Product Category');
        $this->grantPermission($merchant, 'Manage Product Category');

        return [$merchant->fresh(), $store];
    }

    private function parentOf(int $id): int
    {
        return (int) DB::table('categories')->where('id', $id)->value('parent_id');
    }

    public function test_a_foreign_stores_category_cannot_become_a_parent_on_update(): void
    {
        [$merchant, $store] = $this->merchant();
        [, $victimStore] = $this->makeTenant();
        $own = $this->categoryIn($store, 'OwnCategory');
        $foreign = $this->categoryIn($victimStore, 'VICTIM-FIXTURE-CATEGORY-NAME');

        $response = $this->actingAs($merchant)->put('/category/' . $own->id, ['name' => 'OwnCategory', 'parent_id' => $foreign->id, 'status' => 1, 'trending' => 0]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->parentOf($own->id), "A foreign store's category became the parent of the caller's own.");
    }

    public function test_a_foreign_stores_category_cannot_become_a_parent_on_create(): void
    {
        [$merchant, $store] = $this->merchant();
        [, $victimStore] = $this->makeTenant();
        $foreign = $this->categoryIn($victimStore, 'VICTIM-FIXTURE-CATEGORY-NAME');

        $response = $this->actingAs($merchant)->post('/category', ['name' => 'NewOne', 'parent_id' => $foreign->id, 'status' => 1, 'trending' => 0]);

        $response->assertSessionHas('error');
        $this->assertSame(0, DB::table('categories')->where('store_id', $store->id)->where('name', 'NewOne')->count(), 'A category with a foreign parent was created.');
    }

    public function test_a_stored_foreign_parent_does_not_leak_its_name_in_the_listing(): void
    {
        [$merchant, $store] = $this->merchant();
        [, $victimStore] = $this->makeTenant();
        $foreign = $this->categoryIn($victimStore, 'VICTIM-FIXTURE-CATEGORY-NAME');
        // a row written before the rule existed (or by any other writer)
        $this->categoryIn($store, 'OwnChild', $foreign->id);

        $response = $this->actingAs($merchant)->getJson('/category', ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertStringNotContainsString('VICTIM-FIXTURE-CATEGORY-NAME', (string) $response->getContent(), "The listing printed another store's category name.");
    }

    public function test_a_category_cannot_be_its_own_parent_and_the_edit_page_still_renders(): void
    {
        [$merchant, $store] = $this->merchant();
        $own = $this->categoryIn($store, 'Loop');

        $response = $this->actingAs($merchant)->put('/category/' . $own->id, ['name' => 'Loop', 'parent_id' => $own->id, 'status' => 1, 'trending' => 0]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->parentOf($own->id));
        $this->actingAs($merchant)->get('/category/' . $own->id . '/edit')->assertOk();
    }

    public function test_a_descendant_cannot_become_the_parent_of_its_ancestor(): void
    {
        [$merchant, $store] = $this->merchant();
        $top = $this->categoryIn($store, 'Top');
        $mid = $this->categoryIn($store, 'Mid', $top->id);
        $leaf = $this->categoryIn($store, 'Leaf', $mid->id);

        $response = $this->actingAs($merchant)->put('/category/' . $top->id, ['name' => 'Top', 'parent_id' => $leaf->id, 'status' => 1, 'trending' => 0]);

        $response->assertSessionHas('error');
        $this->assertSame(0, $this->parentOf($top->id), 'A cycle was stored.');
    }

    public function test_the_tree_builder_survives_a_cycle_that_is_already_stored(): void
    {
        [, $store] = $this->merchant();
        $a = $this->categoryIn($store, 'A');
        $b = $this->categoryIn($store, 'B', $a->id);
        DB::table('categories')->where('id', $a->id)->update(['parent_id' => $b->id]);   // a loop written before the rule existed

        $tree = buildCategoryTree(Category::where('store_id', $store->id)->get(), $a->id);

        $this->assertIsArray($tree);   // reaching this line IS the assertion: the old builder recursed until it fatalled
        $this->assertLessThanOrEqual(2, count($tree));
    }

    public function test_an_own_store_parent_is_still_accepted_and_shown(): void
    {
        [$merchant, $store] = $this->merchant();
        $parent = $this->categoryIn($store, 'OWN-PARENT-FIXTURE');
        $child = $this->categoryIn($store, 'Child');

        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'parent_id' => $parent->id, 'status' => 1, 'trending' => 0])->assertSessionHas('success');

        $this->assertSame($parent->id, $this->parentOf($child->id));
        $listing = $this->actingAs($merchant)->getJson('/category', ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertStringContainsString('OWN-PARENT-FIXTURE', (string) $listing->getContent(), 'Premise: an own-store parent is still shown in the Parent column.');
    }

    public function test_zero_and_absent_parent_still_mean_top_level(): void
    {
        [$merchant, $store] = $this->merchant();
        $child = $this->categoryIn($store, 'Child');

        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'status' => 1, 'trending' => 0])->assertSessionHas('success');
        $this->actingAs($merchant)->post('/category', ['name' => 'Fresh', 'parent_id' => 0, 'status' => 1, 'trending' => 0])->assertSessionHas('success');

        $this->assertSame(0, $this->parentOf($child->id));
        $this->assertSame(1, DB::table('categories')->where('store_id', $store->id)->where('name', 'Fresh')->count());
    }

    public function test_a_non_numeric_parent_is_refused_not_coerced(): void
    {
        [$merchant, $store] = $this->merchant();
        $parent = $this->categoryIn($store, 'RealOwnParent');
        $child = $this->categoryIn($store, 'Child');

        // MySQL reads '<id>abc' as <id> in a comparison, so a value that is a REAL own-store id plus junk would be accepted by a lenient parse
        // (and SQLite's INTEGER affinity hides that): the rule refuses anything that is not a plain positive integer
        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'parent_id' => $parent->id . 'abc', 'status' => 1, 'trending' => 0])->assertSessionHas('error');
        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'parent_id' => ' ' . $parent->id, 'status' => 1, 'trending' => 0])->assertSessionHas('success');   // TrimStrings: a padded integer IS the integer
        $this->assertSame($parent->id, $this->parentOf($child->id));
        DB::table('categories')->where('id', $child->id)->update(['parent_id' => 0]);
        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'parent_id' => [(string) $parent->id], 'status' => 1, 'trending' => 0])->assertSessionHas('error');
        $this->actingAs($merchant)->put('/category/' . $child->id, ['name' => 'Child', 'parent_id' => $parent->id . '.5', 'status' => 1, 'trending' => 0])->assertSessionHas('error');

        $this->assertSame(0, $this->parentOf($child->id));
    }

    public function test_the_platform_operator_is_not_locked_out_of_a_parent_in_the_store_it_edits(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin(['current_store' => $store->id]);
        $this->grantPermission($operator, 'Edit Product Category');
        $parent = $this->categoryIn($store, 'OP-PARENT');
        $child = $this->categoryIn($store, 'OP-CHILD');

        $this->actingAs($operator->fresh())->put('/category/' . $child->id, ['name' => 'OP-CHILD', 'parent_id' => $parent->id, 'status' => 1, 'trending' => 0])->assertSessionHas('success');

        $this->assertSame($parent->id, $this->parentOf($child->id));
    }
}
