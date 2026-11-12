<?php

namespace Tests\Feature\Security;

use App\Models\ProductBrand;
use App\Models\ProductLabel;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B — can one merchant read, change or TAKE another merchant's brand?
 * ================================================================================================
 *
 * WHY THIS TEST EXISTS, and why reading the controller was not enough. `ProductBrandController`
 * was read method by method while measuring the catalogue screens, and four of its nine methods
 * carry no ownership check at all:
 *
 *     index()         isAbleTo('Manage Product Brand')          + DataTable
 *     create()        NO permission, NO ownership
 *     store()         isAbleTo('Create Product Brand')          stamps store_id = getCurrentStore()
 *     show()          NO permission, NO ownership   (its body is a bare `//`)
 *     edit()          NO permission, NO ownership
 *     update()        isAbleTo('Edit Product Brand')            NO ownership check
 *     destroy()       isAbleTo('Delete Product Brand')          NO ownership check visible
 *     changeStatus()  resolveOwnedBrand()  -- a REAL check, store_id compared
 *     changePopular() resolveOwnedBrand()  -- the same
 *
 * TWO FACTS MAKE THAT MATTER RATHER THAN BEING THEORETICAL.
 *
 * `ProductBrand` has NO global scope and is NOT in `config/tenancy.php`'s `scoped_models`, so
 * route model binding resolves ANY id in the table -- the bound instance is not filtered by tenant,
 * exactly as CLAUDE.md records for `Product`. And `update()` line 158 does
 *
 *     $productBrand->store_id = getCurrentStore();
 *
 * which is not an ownership CHECK but an ownership REASSIGNMENT. If a foreign brand reaches that
 * line, the update does not merely edit another tenant's row: it MOVES the row into the caller's
 * store.
 *
 * THE EXISTING COVERAGE DOES NOT REACH THIS. `BrandLabelStatusAuthorizationTest` has fifteen tests
 * and every one drives `changeStatus` or `changePopular` -- the two methods that ARE hardened.
 * Nothing in the suite drives edit, show, update or destroy across a tenant boundary.
 *
 * SO THIS TEST ASKS THE QUESTION DIRECTLY RATHER THAN INFERRING THE ANSWER. Whatever it reports is
 * a measurement: if the application already refuses, the reading above is an over-reading of the
 * code and these tests pin the behaviour that protects it; if it does not refuse, they are the
 * reproduction.
 */
class BrandCrossTenantReadWriteTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeBrand(int $storeId, int $createdBy, string $name): ProductBrand
    {
        return ProductBrand::forceCreate([
            'name' => $name,
            'slug' => $name . '-' . $storeId,
            'status' => 1,
            'is_popular' => 0,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    /** Raw, because ProductBrand is Cachable and an Eloquent read can serve a pre-write row. */
    private function rawBrand(int $id): ?object
    {
        return \DB::table('product_brands')->where('id', $id)->first();
    }

    public function test_a_merchant_cannot_open_another_tenants_brand_edit_form(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Brand');

        $foreign = $this->makeBrand($victimStore->id, $victim->id, 'VictimBrand');

        $response = $this->actingAs($attacker)->get('/product-brand/' . $foreign->id . '/edit');

        $this->assertNotSame(
            200,
            $response->status(),
            'A merchant opened another tenant\'s brand edit form. ProductBrandController::edit() '
            . 'has no permission check and no ownership check, and ProductBrand has no global '
            . 'scope, so route model binding resolved a foreign row.'
        );
        $response->assertDontSee('VictimBrand', false);
    }

    public function test_a_merchant_cannot_update_another_tenants_brand(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Brand');

        $foreign = $this->makeBrand($victimStore->id, $victim->id, 'VictimBrand');

        $this->actingAs($attacker)
            ->put('/product-brand/' . $foreign->id, ['name' => 'TakenOver']);

        $after = $this->rawBrand($foreign->id);

        $this->assertSame(
            'VictimBrand',
            $after->name,
            "A merchant renamed another tenant's brand. update() checks the PERMISSION but never "
            . 'the owner.'
        );
    }

    public function test_an_update_cannot_move_another_tenants_brand_into_the_callers_store(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Brand');

        $foreign = $this->makeBrand($victimStore->id, $victim->id, 'VictimBrand');

        $this->actingAs($attacker)
            ->put('/product-brand/' . $foreign->id, ['name' => 'TakenOver']);

        $after = $this->rawBrand($foreign->id);

        // THE SHARPEST EDGE OF THIS. Line 158 ASSIGNS store_id rather than checking it, so a
        // successful foreign update does not just change the row -- it transfers ownership.
        $this->assertSame(
            (int) $victimStore->id,
            (int) $after->store_id,
            'A brand changed hands. update() assigns `store_id = getCurrentStore()`, so a foreign '
            . "row that reaches that line is moved into the caller's store."
        );
        $this->assertNotSame((int) $attackerStore->id, (int) $after->store_id);
    }

    public function test_a_merchant_cannot_delete_another_tenants_brand(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Product Brand');

        $foreign = $this->makeBrand($victimStore->id, $victim->id, 'VictimBrand');

        $this->actingAs($attacker)->delete('/product-brand/' . $foreign->id);

        $this->assertNotNull(
            $this->rawBrand($foreign->id),
            "A merchant deleted another tenant's brand. destroy() checks the permission but not "
            . 'the owner.'
        );
    }

    // =============================================================================================
    // PRODUCT LABEL -- the same controller shape, measured rather than assumed to match.
    //
    // `ProductLabelController` opens edit() with a bare `ProductLabel::find($id)` (no permission,
    // no ownership), update() checks `Edit Product Label` and then re-finds unscoped, and -- the
    // part that makes it a takeover rather than an edit -- assigns
    // `$productLabel->store_id = getCurrentStore();`. `ProductLabel` carries no global scope and
    // is absent from `scoped_models`, exactly like ProductBrand.
    // =============================================================================================

    private function makeLabel(int $storeId, int $createdBy, string $name): ProductLabel
    {
        // THE COLUMNS THE TABLE REALLY HAS. The first version of this helper invented a `color`
        // column; `product_labels` has none, so every label test died inside the fixture before
        // reaching a route -- and three of them "failed" in a way that looked exactly like the
        // cross-tenant hole they were written to prove. A test that fails for the wrong reason
        // proves nothing. Shape taken from BrandLabelStatusAuthorizationTest::makeLabel().
        return ProductLabel::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'status' => 1,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    private function rawLabel(int $id): ?object
    {
        return \DB::table('product_labels')->where('id', $id)->first();
    }

    public function test_a_merchant_cannot_open_another_tenants_label_edit_form(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Label');

        $foreign = $this->makeLabel($victimStore->id, $victim->id, 'VictimLabel');

        $response = $this->actingAs($attacker)->get('/product-label/' . $foreign->id . '/edit');

        $this->assertNotSame(200, $response->status(),
            "A merchant opened another tenant's label edit form.");
        $response->assertDontSee('VictimLabel', false);
    }

    public function test_a_merchant_cannot_update_or_take_another_tenants_label(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Product Label');

        $foreign = $this->makeLabel($victimStore->id, $victim->id, 'VictimLabel');

        $this->actingAs($attacker)
            ->put('/product-label/' . $foreign->id, ['name' => 'TakenOver']);

        $after = $this->rawLabel($foreign->id);
        $this->assertSame('VictimLabel', $after->name, "A merchant renamed another tenant's label.");
        $this->assertSame((int) $victimStore->id, (int) $after->store_id,
            'A label changed hands: update() assigns store_id rather than checking it.');
        $this->assertNotSame((int) $attackerStore->id, (int) $after->store_id);
    }

    public function test_a_merchant_cannot_delete_another_tenants_label(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Product Label');

        $foreign = $this->makeLabel($victimStore->id, $victim->id, 'VictimLabel');

        $this->actingAs($attacker)->delete('/product-label/' . $foreign->id);

        $this->assertNotNull($this->rawLabel($foreign->id),
            "A merchant deleted another tenant's label.");
    }

    public function test_the_label_owner_can_still_do_all_of_it(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Product Label');
        $this->grantPermission($owner, 'Delete Product Label');

        $own = $this->makeLabel($store->id, $owner->id, 'OwnLabel');

        $this->actingAs($owner)->get('/product-label/' . $own->id . '/edit')->assertStatus(200);
        $this->actingAs($owner)->put('/product-label/' . $own->id, ['name' => 'Renamed']);
        $this->assertSame('Renamed', $this->rawLabel($own->id)->name,
            'The owner can no longer rename their own label.');
        $this->assertSame((int) $store->id, (int) $this->rawLabel($own->id)->store_id);

        $this->actingAs($owner)->delete('/product-label/' . $own->id);
        $this->assertNull($this->rawLabel($own->id),
            'The owner can no longer delete their own label.');
    }

    public function test_the_owner_can_still_do_all_of_it(): void
    {
        // THE POSITIVE CONTROL, and it is the half that keeps the others honest: a controller that
        // refused everything would satisfy every assertion above.
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Product Brand');

        $own = $this->makeBrand($store->id, $owner->id, 'OwnBrand');

        $this->actingAs($owner)->get('/product-brand/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/product-brand/' . $own->id, ['name' => 'Renamed']);
        $this->assertSame('Renamed', $this->rawBrand($own->id)->name,
            'The owner can no longer rename their own brand.');
        $this->assertSame((int) $store->id, (int) $this->rawBrand($own->id)->store_id,
            "The owner's own brand left their store.");

        // DESTROY NEEDS ITS OWN POSITIVE CONTROL, raised by the authz review: the cross-tenant
        // delete test asserts only that the row SURVIVES, so a destroy() that 500'd for everyone
        // would keep it green. destroy() is the likeliest method to break on its own -- it calls
        // \File::exists(base_path(...)) and Utility::changeStorageLimit() before deleting.
        $this->grantPermission($owner, 'Delete Product Brand');
        $this->actingAs($owner)->delete('/product-brand/' . $own->id);
        $this->assertNull($this->rawBrand($own->id),
            'The owner can no longer delete their own brand.');
    }
}
