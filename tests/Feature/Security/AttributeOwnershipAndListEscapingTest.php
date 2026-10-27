<?php

namespace Tests\Feature\Security;

use App\DataTables\CategoryDataTable;
use App\Models\Category;
use App\Models\ProductAttribute;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2-05-4 follow-up — the two defects the independent review of the slice found on the surface
 * the slice converts, plus the test gap it found inside the slice itself.
 *
 * 1. **A CROSS-TENANT RENAME AND DELETE.** `ProductAttributeController::update` and `::destroy`
 *    took a route-model-bound `ProductAttribute` and applied no ownership predicate, so a
 *    merchant holding the ordinary `Edit Attributes` / `Delete Attributes` permissions could
 *    rewrite or destroy ANOTHER STORE'S attribute by posting its id. Both permissions are granted
 *    to every tenant owner's role by default (`Utility.php:3093-3096`), so the attacker is any
 *    merchant. `::edit` had neither a permission gate nor a predicate.
 *
 *    Nothing else was stopping it, and each was checked rather than assumed: `ProductAttribute`
 *    registers no global scope and is not in `config/tenancy.php`'s `scoped_models`, so
 *    `StoreScope::isActiveFor()` short-circuits; the route group carries no tenancy middleware;
 *    and `ModuleResourceOwnershipGuard`'s TARGETS map has no attribute entry. The CHILD
 *    (`ProductAttributeOptionController::ownedAttribute()`) and the NEIGHBOUR
 *    (`CategoryController::categoryIsOutsideCurrentStore()`) were both already fixed — the parent
 *    was missed.
 *
 * 2. **TWO MORE RAW COLUMNS.** `CategoryDataTable`'s `image_path` and `icon_path` interpolate a
 *    merchant-supplied filename into `<img src="...">` by concatenation inside `rawColumns()` —
 *    the same shape as the `parent_id` defect the slice fixed, eleven lines above it in the same
 *    method. The filename reaches the database unsanitised
 *    (`CategoryController:89` uses `getClientOriginalName()`, which strips only path separators).
 *
 * 3. **THE SLICE SHIPPED THOSE `e()` CALLS WITH NO TEST.** Reverting the `parent_id` escape left
 *    the whole suite green. The existing ratchet cannot see it either:
 *    `CustomerListOutputEscapingTest` flags raw columns with NO closure, and all of these have
 *    closures. So the escaping is asserted here against the rendered cell.
 */
class AttributeOwnershipAndListEscapingTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function attributeIn(int $storeId, string $name): ProductAttribute
    {
        return ProductAttribute::forceCreate([
            'name' => $name,
            'slug' => 'a-' . uniqid(),
            'store_id' => $storeId,
        ]);
    }

    private function merchantOf(int $storeId): \App\Models\User
    {
        $user = $this->makeMerchant(['current_store' => $storeId]);
        $this->grantPermission($user, 'Manage Attributes');
        $this->grantPermission($user, 'Edit Attributes');
        $this->grantPermission($user, 'Delete Attributes');

        return $user;
    }

    // ---------------------------------------------------------------- the cross-tenant writes

    public function test_a_merchant_cannot_rename_another_stores_attribute(): void
    {
        $victim = $this->attributeIn(2, 'Victim Size');
        $attacker = $this->merchantOf(1);

        $this->actingAs($attacker)->put('/product-attributes/' . $victim->id, ['name' => 'Pwned']);

        $this->assertSame('Victim Size',
            ProductAttribute::withoutGlobalScopes()->find($victim->id)->name,
            'store 2\'s attribute was renamed by a merchant of store 1. The permission check alone '
            . 'is not an ownership check, and ProductAttribute carries no global scope.');
    }

    public function test_a_merchant_cannot_delete_another_stores_attribute(): void
    {
        $victim = $this->attributeIn(2, 'Victim Colour');
        $attacker = $this->merchantOf(1);

        $this->actingAs($attacker)->delete('/product-attributes/' . $victim->id);

        $this->assertNotNull(ProductAttribute::withoutGlobalScopes()->find($victim->id),
            'store 2\'s attribute was deleted by a merchant of store 1. Asserted through '
            . 'withoutGlobalScopes() so the row is read from the table rather than through '
            . 'whichever tenant happens to be resolved.');
    }

    public function test_a_merchant_cannot_read_another_stores_attribute_form(): void
    {
        $victim = $this->attributeIn(2, 'Victim Material');
        $attacker = $this->merchantOf(1);

        $response = $this->actingAs($attacker)->get('/product-attributes/' . $victim->id . '/edit');

        $response->assertDontSee('Victim Material', false);
    }

    /** The positive control: the guard must not break a merchant's own edit. */
    public function test_a_merchant_can_still_rename_their_own_attribute(): void
    {
        $own = $this->attributeIn(1, 'Size');
        $merchant = $this->merchantOf(1);

        $this->actingAs($merchant)->put('/product-attributes/' . $own->id, ['name' => 'Sizes']);

        $this->assertSame('Sizes', ProductAttribute::withoutGlobalScopes()->find($own->id)->name);
    }

    public function test_a_merchant_can_still_delete_their_own_attribute(): void
    {
        $own = $this->attributeIn(1, 'Colour');
        $merchant = $this->merchantOf(1);

        $this->actingAs($merchant)->delete('/product-attributes/' . $own->id);

        $this->assertNull(ProductAttribute::withoutGlobalScopes()->find($own->id));
    }

    /**
     * The permission gate must still refuse, so the ownership fix has not replaced it with
     * something weaker for a same-store caller who lacks the permission.
     */
    public function test_a_merchant_without_the_permission_still_cannot_delete_their_own(): void
    {
        $own = $this->attributeIn(1, 'Weight');
        $user = $this->makeMerchant(['current_store' => 1]);
        $this->grantPermission($user, 'Manage Attributes');

        $this->actingAs($user)->delete('/product-attributes/' . $own->id);

        $this->assertNotNull(ProductAttribute::withoutGlobalScopes()->find($own->id));
    }

    // ------------------------------------------------------------- the raw-column escaping

    /**
     * Rendered through the DataTable, not read off the source: the point is what reaches the
     * merchant's page, and these cells are built by concatenation where nothing else escapes.
     */
    private function renderedCategoryTable(): string
    {
        $this->actingAs($this->merchantOf(1));

        request()->merge(['draw' => 1, 'start' => 0, 'length' => 10]);

        return (string) (new CategoryDataTable())->dataTable(
            Category::query()->where('store_id', 1)
        )->toJson()->getContent();
    }

    public function test_a_parent_category_name_containing_markup_is_escaped_in_the_list(): void
    {
        $parent = Category::forceCreate([
            'name' => '"><img src=x onerror=alert(1)>',
            'slug' => 'p-' . uniqid(), 'parent_id' => 0,
            'trending' => 0, 'status' => 1, 'store_id' => 1,
        ]);
        Category::forceCreate([
            'name' => 'Child', 'slug' => 'c-' . uniqid(), 'parent_id' => $parent->id,
            'trending' => 0, 'status' => 1, 'store_id' => 1,
        ]);

        $json = $this->renderedCategoryTable();

        $this->assertStringNotContainsString('<img src=x', $json,
            'the parent-category cell is in rawColumns(), so an unescaped name is live markup in '
            . 'the merchant\'s list.');
        $this->assertStringContainsString('img', $json, 'premise: the parent name reached the cell');
    }

    public function test_an_image_filename_containing_markup_cannot_break_out_of_the_img_tag(): void
    {
        Category::forceCreate([
            'name' => 'Has an image',
            'slug' => 'i-' . uniqid(), 'parent_id' => 0,
            'trending' => 0, 'status' => 1, 'store_id' => 1,
            'image_path' => 'uploads/1/a" onerror=alert(1) z=".png',
            'icon_path' => 'uploads/1/b" onerror=alert(2) z=".png',
        ]);

        $json = $this->renderedCategoryTable();

        /*
         * THE PROPERTY IS THE QUOTE, NOT THE WORD. `e()` leaves the text `onerror=alert(1)` in
         * place -- harmlessly, inside a now-properly-quoted attribute value -- so asserting the
         * word's absence tests the wrong thing and fails against the CORRECT fix. What must not
         * survive is the unescaped `"` that ends the `src` attribute and starts a new one. The
         * first version of this test got that wrong, which is worth keeping: an assertion that
         * fails on a working fix is as much a defect as one that passes on a broken one.
         */
        $this->assertStringNotContainsString('" onerror=', $json,
            'an unescaped double quote in the uploaded filename closes the src attribute and '
            . 'starts a new one, so the rest of the name becomes live markup. The filename '
            . 'reaches this cell unsanitised — CategoryController uses getClientOriginalName(), '
            . 'which strips only path separators — and the cell is built by concatenation inside '
            . 'a raw column, where nothing else escapes it.');

        $this->assertStringContainsString('&quot; onerror=alert(1)', $json,
            'premise: the hostile filename really did reach the cell, and it is the ESCAPING '
            . 'that neutralised it rather than the value never arriving. Without this, the '
            . 'assertion above would pass just as well on an empty table.');

        $this->assertStringNotContainsString('" onerror=alert(2)', $json, 'the icon column too');
    }
}
