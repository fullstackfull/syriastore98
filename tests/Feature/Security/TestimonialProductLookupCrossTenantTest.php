<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Testimonial;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B — `POST get-product` returns EVERY TENANT'S product names.
 * ================================================================================================
 *
 * `TestimonialController::get_product()` exists to fill the product dropdown on the testimonial
 * form once a category is chosen. It does this:
 *
 *     $id = $request->id;                                   // caller-supplied category_id
 *     $Product = Product::where('category_id', $id)->get();  // NO store predicate
 *     ... '<option value="' . $Category->id . '">' . $Category->name . '</option>'
 *
 * No permission check, no ownership check, no tenant predicate — and **`Product` is not in
 * `config/tenancy.php`'s `scoped_models`**, so `StoreScope::isActiveFor()` short-circuits and the
 * global scope adds no predicate for ANY principal. CLAUDE.md records this exact property, and
 * records that it already produced a live cross-tenant stock drain (S19) which a `customer_id`
 * sweep missed because it was looking at the wrong identifier.
 *
 * REACHABILITY IS THE WHOLE QUESTION, and it is settled: `routes/web.php:944` registers
 * `POST get-product` as `get.product`, inside the `['auth','xss','setlocate','verified']` group.
 * `Route::resource('testimonial')` would not have created it — it is a separate declaration, which
 * is exactly why a census keyed on resource-controller method names never looked at it.
 *
 * SO THE IMPACT IS CATALOGUE ENUMERATION. A competitor merchant iterates `category_id` and reads
 * back every other store's product ids and names. Product names are commercial data; the ids are
 * then usable against any other handler that takes a product id.
 *
 * HOW IT WAS FOUND, recorded because it says something about the instruments. Not by the
 * controller census — that filters to single-record method names (`show`, `edit`, `update`,
 * `destroy`, status toggles) and `get_product` is a LIST query under none of those names. It came
 * from a separate static discovery pass over the Package B surfaces, and survived two adversarial
 * verifiers. It was then confirmed here by a driven request rather than taken on trust.
 */
class TestimonialProductLookupCrossTenantTest extends SecurityTestCase
{
    use RefreshDatabase;

    /**
     * `products.category_id` carries a FOREIGN KEY to `categories(id)` -- measured, after an
     * invented id 4242 failed with SQLSTATE[23000]. That matters for more than the fixture: it
     * means a category id is a small, per-store integer an attacker simply enumerates.
     */
    private function makeCategory(int $storeId, string $name): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $storeId,
        ]);
    }

    private function makeProduct(int $storeId, int $createdBy, string $name, int $categoryId): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x',
            'price' => 10,
            'track_stock' => 0,
            'trending' => 0,
            'stock_status' => 'in_stock',
            'product_stock' => 5,
            'product_type' => null,
            'status' => 1,
            'variant_product' => 0,
            'category_id' => $categoryId,
            'store_id' => $storeId,
            'created_by' => $createdBy,
        ]);
    }

    public function test_the_product_lookup_does_not_return_another_tenants_products(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker, $attackerStore] = $this->makeTenant();

        // The attacker posts the VICTIM'S category id. Categories are per-store rows with small
        // sequential ids, so this is enumeration rather than guesswork, and the handler never
        // looks at the category's own store_id -- only at products carrying that id.
        $victimCategory = $this->makeCategory($victimStore->id, 'Victim Category');
        $attackerCategory = $this->makeCategory($attackerStore->id, 'Attacker Category');

        $foreign = $this->makeProduct($victimStore->id, $victim->id, 'VictimSecretProduct', $victimCategory->id);
        $this->makeProduct($attackerStore->id, $attacker->id, 'AttackerOwnProduct', $attackerCategory->id);

        // Premise: the two categories really are distinct rows, or this proves nothing.
        $this->assertNotSame($victimCategory->id, $attackerCategory->id);

        $response = $this->actingAs($attacker)
            ->post('/get-product', ['id' => $victimCategory->id]);

        $response->assertStatus(200);
        $html = (string) ($response->json('html') ?? '');

        $this->assertStringNotContainsString(
            'VictimSecretProduct',
            $html,
            "POST get-product returned another tenant's product name. "
            . 'TestimonialController::get_product() filters on `category_id` alone, and Product is '
            . 'absent from config/tenancy.php scoped_models, so the global scope adds no predicate.'
        );

        $this->assertStringNotContainsString(
            'value="' . $foreign->id . '"',
            $html,
            "POST get-product returned another tenant's product ID, which is then usable against "
            . 'any handler that takes a product id.'
        );
    }

    public function test_the_lookup_still_answers_for_a_category_the_caller_owns(): void
    {
        // The second positive control: the dropdown must still fill, or the testimonial form is
        // broken by the fix. Driven separately from the cross-tenant case so a single refusal
        // cannot satisfy both.
        [$owner, $store] = $this->makeTenant();

        $category = $this->makeCategory($store->id, 'Own Category');
        $this->makeProduct($store->id, $owner->id, 'FirstOwnProduct', $category->id);
        $this->makeProduct($store->id, $owner->id, 'SecondOwnProduct', $category->id);

        $html = (string) ($this->actingAs($owner)
            ->post('/get-product', ['id' => $category->id])
            ->assertStatus(200)
            ->json('html') ?? '');

        $this->assertStringContainsString('FirstOwnProduct', $html);
        $this->assertStringContainsString('SecondOwnProduct', $html);
    }

    public function test_a_category_with_no_products_of_the_callers_own_returns_an_empty_dropdown(): void
    {
        // The shape that would otherwise hide a leak: when the caller owns NOTHING in the
        // category, the correct answer is the bare placeholder -- not the other tenant's rows.
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        $victimCategory = $this->makeCategory($victimStore->id, 'Victim Only Category');
        $this->makeProduct($victimStore->id, $victim->id, 'VictimOnlyProduct', $victimCategory->id);

        $html = (string) ($this->actingAs($attacker)
            ->post('/get-product', ['id' => $victimCategory->id])
            ->assertStatus(200)
            ->json('html') ?? '');

        $this->assertStringNotContainsString('VictimOnlyProduct', $html,
            "A caller who owns no product in that category still saw the victim's.");
    }

    // =============================================================================================
    // THE SHORT-CIRCUIT BRANCH, which was UNTESTABLE until this case existed.
    //
    // Raised by the tenant-authz review: reverting `empty($currentStore) ? collect() : ...` alone,
    // leaving the `->where('store_id', $currentStore)` in place, left all three tests above green.
    // So half the fix could not fail. `products.store_id` is nullable
    // (2023_12_26_092822_create_products_table.php:49) and `where('store_id', null)` compiles to
    // `store_id is null`, so without the short-circuit a principal with no current store is served
    // every ORPHAN product in the platform.
    // =============================================================================================

    public function test_a_principal_with_no_current_store_is_served_no_orphan_products(): void
    {
        $stray = $this->makeMerchant();          // no store, no current_store
        $stray->current_store = null;
        $stray->save();
        $stray->flushCache();

        [$victim, $victimStore] = $this->makeTenant();
        $category = $this->makeCategory($victimStore->id, 'Orphan Host Category');

        // An ORPHAN row: store_id NULL. This is what `where('store_id', null)` matches.
        Product::forceCreate([
            'name' => 'OrphanProductName',
            'slug' => 'orphan-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id,
            'store_id' => null,
            'created_by' => $victim->id,
        ]);

        $html = (string) ($this->actingAs($stray)
            ->post('/get-product', ['id' => $category->id])
            ->assertStatus(200)
            ->json('html') ?? '');

        $this->assertStringNotContainsString('OrphanProductName', $html,
            'A principal with no current store was served an orphan product. Without the '
            . 'empty($currentStore) short-circuit, `where(\'store_id\', null)` matches every row '
            . 'whose store_id IS NULL.');
    }

    // =============================================================================================
    // THE SAME FILE'S OTHER THREE HANDLERS -- a cross-tenant WRITE, which is worse than the read
    // this test class was opened for. Found by the tenant-authz review of that read's fix.
    //
    // `Testimonial` uses only HasFactory + Cachable and is deliberately absent from scoped_models,
    // so `Route::resource('testimonial')`'s implicit binding resolves any row by bare primary key.
    // The index DataTable IS store-filtered, so an attacker is never SHOWN a foreign id -- they are
    // small sequential integers, the same enumeration argument as category_id above.
    // =============================================================================================

    /**
     * `testimonials.category_id` DEFAULTS TO 0 and carries a FOREIGN KEY to `categories(id)`
     * (2023_12_26_095726_create_testimonials_table.php:16,27). Measured, after omitting it failed
     * with SQLSTATE[23000]: the column is nullable, but leaving it out takes the default 0, and no
     * category has id 0. So the row needs a real category of its own store.
     */
    private function makeTestimonial(int $storeId, int $userId, string $title): Testimonial
    {
        $category = $this->makeCategory($storeId, 'Testimonial Host ' . uniqid());

        return Testimonial::forceCreate([
            'title' => $title,
            'description' => 'seeded',
            'rating_no' => 5,
            'username' => 'Probe Customer',
            'status' => 1,
            'category_id' => $category->id,
            'store_id' => $storeId,
            'user_id' => $userId,
        ]);
    }

    private function rawTestimonial(int $id): ?object
    {
        return \DB::table('testimonials')->where('id', $id)->first();
    }

    public function test_a_merchant_cannot_open_another_tenants_testimonial(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Testimonial');

        $foreign = $this->makeTestimonial($victimStore->id, $victim->id, 'VictimTestimonialTitle');

        $this->actingAs($attacker)->get('/testimonial/' . $foreign->id . '/edit')
            ->assertStatus(404);
    }

    public function test_a_merchant_cannot_rewrite_another_tenants_testimonial(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Edit Testimonial');

        $foreign = $this->makeTestimonial($victimStore->id, $victim->id, 'VictimTestimonialTitle');

        $this->actingAs($attacker)->put('/testimonial/' . $foreign->id, [
            'title' => 'RewrittenByAttacker',
            'description' => 'rewritten',
            'rating_no' => 1,
            'username' => 'attacker',
            // `testimonials.status` is `integer default 1` with NO ->nullable()
            // (2023_12_26_095726_create_testimonials_table.php:23), and update() assigns
            // `$request->status` unconditionally -- so a payload omitting it writes NULL and the
            // row is rejected by the column, not by the guard. Measured: without this key the
            // OWNER's own rename failed too, which would have made the three cross-tenant passes
            // above meaningless. A real form always posts it.
            'status' => 1,
        ]);

        $this->assertSame('VictimTestimonialTitle', $this->rawTestimonial($foreign->id)->title,
            "A merchant rewrote another tenant's testimonial.");
    }

    public function test_a_merchant_cannot_delete_another_tenants_testimonial(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Delete Testimonial');

        $foreign = $this->makeTestimonial($victimStore->id, $victim->id, 'VictimTestimonialTitle');

        $this->actingAs($attacker)->delete('/testimonial/' . $foreign->id);

        $this->assertNotNull($this->rawTestimonial($foreign->id),
            "A merchant deleted another tenant's testimonial -- and destroy() debits the CALLER'S "
            . 'own storage quota for the victim\'s avatar before deleting.');
    }

    public function test_the_testimonial_owner_can_still_do_all_of_it(): void
    {
        // The positive control for the three guards above: a controller that refused everyone
        // would satisfy every assertion in them.
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Edit Testimonial');
        $this->grantPermission($owner, 'Delete Testimonial');

        $own = $this->makeTestimonial($store->id, $owner->id, 'OwnTestimonialTitle');

        $this->actingAs($owner)->get('/testimonial/' . $own->id . '/edit')->assertStatus(200);

        $this->actingAs($owner)->put('/testimonial/' . $own->id, [
            'title' => 'RenamedByOwner',
            'description' => 'renamed',
            'rating_no' => 4,
            'username' => 'owner',
            'status' => 1,
        ]);
        $this->assertSame('RenamedByOwner', $this->rawTestimonial($own->id)->title,
            'The owner can no longer rename their own testimonial.');

        $this->actingAs($owner)->delete('/testimonial/' . $own->id);
        $this->assertNull($this->rawTestimonial($own->id),
            'The owner can no longer delete their own testimonial.');
    }
}
