<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\FlashSale;
use App\Models\Newsletter;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ShippingMethod;
use App\Models\ShippingZone;
use App\Models\Testimonial;
use App\Models\Webhook;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B / F-SEC-07 -- the empty-body existence oracles, DRIVEN.
 * ================================================================================================
 *
 * `Tests\Unit\Security\OwnedResourceMethodsAreGuardedTest` proves these bodies are no longer
 * EMPTY. That is a structural property and deliberately nothing more: it cannot tell a correct
 * guard from a guard that refuses the owner too, or from one whose predicate compares the
 * caller's own two values. This file is the behavioural half -- it drives a real HTTP request as
 * a real second tenant and asserts the response.
 *
 * WHY AN EMPTY BODY WAS EVER A FINDING. Route-model binding resolves the row BEFORE the action
 * runs. None of these seven models is in `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and the global scope adds no predicate for any
 * principal. The method then returned nothing -- but the STATUS differed: 200 for a row in any
 * store, 404 only for an id that exists nowhere. That is a working enumeration oracle over every
 * tenant's primary keys, which is why it survived every sweep that looked for leaked DATA.
 *
 * MEASURED FROM THE ROUTE TABLE, not assumed: every route below carries exactly
 * `web, Authenticate, XSS, SetLocate, EmailIsVerified` -- there is NO permission middleware on
 * any of them, and the bodies held no `isAbleTo()` call because they held nothing at all. So the
 * reachable principal was any authenticated merchant, holding zero permissions.
 *
 * WHAT THIS FIX DOES AND DOES NOT DO, stated plainly rather than implied: it closes the
 * CROSS-TENANT oracle. It does not add a permission check, because these are unimplemented
 * resource stubs that return no data -- there is nothing for a permission to gate. The owner's
 * response is unchanged (an empty 200), which is what the positive controls here assert.
 *
 * EVERY CASE CARRIES A POSITIVE CONTROL and it is load-bearing: a guard that returned 404 to
 * everyone would satisfy every cross-tenant assertion in this file. In F-SEC-03 a fixture that
 * omitted a NOT NULL column made the owner's own request fail, and the control is what caught it.
 *
 * FIXTURE FACTS MEASURED FROM THE LIVE SCHEMA (`Schema::getForeignKeys`), not assumed -- SQLite
 * runs with `PRAGMA foreign_keys = 1` here, so a wrong default is an error, not a silent pass:
 *   shipping_methods.zone_id     NOT NULL, FK -> shipping_zones(id)
 *   testimonials.category_id     DEFAULT 0 but FK -> categories(id), so 0 is NOT a valid value
 *   wishlists.product_id         DEFAULT 0, FK -> products(id); same trap
 *   flash_sales.created_by       NOT NULL
 */
class EmptyBodyExistenceOracleTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ---------------------------------------------------------------------------------------
    // Fixtures
    // ---------------------------------------------------------------------------------------

    private function makeCategory(int $storeId): Category
    {
        $name = 'Oracle Cat ' . uniqid();

        return Category::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    private function makeFlashSale(int $storeId, int $userId): FlashSale
    {
        return FlashSale::forceCreate([
            'name' => 'Oracle Sale ' . uniqid(),
            'discount_type' => 'percentage', 'discount_amount' => '10',
            'is_active' => 1, 'created_by' => $userId, 'store_id' => $storeId,
        ]);
    }

    private function makeShippingMethod(int $storeId): ShippingMethod
    {
        $zone = ShippingZone::forceCreate([
            'zone_name' => 'Oracle Zone ' . uniqid(),
            'shipping_method' => 'flat_rate', 'store_id' => $storeId,
        ]);

        return ShippingMethod::forceCreate([
            'method_name' => 'Oracle Method ' . uniqid(),
            'zone_id' => $zone->id, 'cost' => '5', 'store_id' => $storeId,
        ]);
    }

    private function makeTestimonial(int $storeId, int $userId): Testimonial
    {
        return Testimonial::forceCreate([
            'title' => 'Oracle Testimonial ' . uniqid(),
            'description' => 'seeded', 'rating_no' => 5, 'username' => 'Probe',
            'status' => 1, 'category_id' => $this->makeCategory($storeId)->id,
            'store_id' => $storeId, 'user_id' => $userId,
        ]);
    }

    private function makeProductAttribute(int $storeId): ProductAttribute
    {
        $name = 'Oracle Attr ' . uniqid();

        return ProductAttribute::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'store_id' => $storeId,
        ]);
    }

    private function makeWebhook(int $storeId): Webhook
    {
        return Webhook::forceCreate([
            'module' => 'New Order', 'url' => 'https://example.test/' . uniqid(),
            'method' => 'POST', 'store_id' => $storeId,
        ]);
    }

    private function makeWishlist(int $storeId, int $userId): Wishlist
    {
        $product = Product::forceCreate([
            'name' => 'Oracle Product ' . uniqid(),
            'slug' => 'op-' . uniqid(), 'description' => 'x', 'price' => 10,
            'track_stock' => 0, 'trending' => 0, 'stock_status' => 'in_stock',
            'product_stock' => 5, 'product_type' => null, 'status' => 1,
            'variant_product' => 0, 'category_id' => $this->makeCategory($storeId)->id,
            'store_id' => $storeId, 'created_by' => $userId,
        ]);

        return Wishlist::forceCreate([
            'customer_id' => 1, 'product_id' => $product->id, 'variant_id' => 0,
            'status' => 1, 'store_id' => $storeId,
        ]);
    }

    private function makeNewsletter(int $storeId): Newsletter
    {
        return Newsletter::forceCreate([
            'email' => 'oracle-' . uniqid() . '@example.test', 'store_id' => $storeId,
        ]);
    }

    // ---------------------------------------------------------------------------------------
    // The oracle, one case per guarded endpoint
    // ---------------------------------------------------------------------------------------

    /**
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: \App\Models\User}
     */
    private function twoTenants(): array
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();

        return [$victim, $victimStore, $attacker];
    }

    public function test_flash_sale_show_is_not_an_existence_oracle_across_tenants(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeFlashSale($victimStore->id, $victim->id);

        $this->actingAs($attacker)->get('/flash-sale/' . $foreign->id)->assertStatus(404);
    }

    public function test_the_flash_sale_owner_still_reaches_show(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeFlashSale($store->id, $owner->id);

        $this->actingAs($owner)->get('/flash-sale/' . $own->id)->assertStatus(200);
    }

    public function test_shipping_method_show_and_destroy_are_not_existence_oracles(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeShippingMethod($victimStore->id);

        $this->actingAs($attacker)->get('/shipping-method/' . $foreign->id)->assertStatus(404);
        $this->actingAs($attacker)->delete('/shipping-method/' . $foreign->id)->assertStatus(404);

        // NOT EVIDENCE, and labelled so rather than left to read as evidence. `destroy()` is a
        // stub that deletes nothing under the fix AND under reversion, so this assertion passes
        // either way -- it is future-proofing for the day the body is implemented, and the status
        // assertion above is what actually carries this test. Raised by the tenant-authz review,
        // which named it as the "asserting the row did not change when the endpoint does nothing
        // for anyone" shape.
        $this->assertNotNull(
            \DB::table('shipping_methods')->where('id', $foreign->id)->first(),
            "A merchant's DELETE removed another tenant's shipping method."
        );
    }

    public function test_the_shipping_method_owner_still_reaches_show_and_destroy(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeShippingMethod($store->id);

        $this->actingAs($owner)->get('/shipping-method/' . $own->id)->assertStatus(200);
        $this->actingAs($owner)->delete('/shipping-method/' . $own->id)->assertStatus(200);
    }

    public function test_testimonial_show_is_not_an_existence_oracle_across_tenants(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeTestimonial($victimStore->id, $victim->id);

        $this->actingAs($attacker)->get('/testimonial/' . $foreign->id)->assertStatus(404);
    }

    public function test_the_testimonial_owner_still_reaches_show(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeTestimonial($store->id, $owner->id);

        $this->actingAs($owner)->get('/testimonial/' . $own->id)->assertStatus(200);
    }

    public function test_product_attribute_show_is_not_an_existence_oracle_across_tenants(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeProductAttribute($victimStore->id);

        $this->actingAs($attacker)->get('/product-attributes/' . $foreign->id)->assertStatus(404);
    }

    public function test_the_product_attribute_owner_still_reaches_show(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeProductAttribute($store->id);

        $this->actingAs($owner)->get('/product-attributes/' . $own->id)->assertStatus(200);
    }

    public function test_webhook_show_is_not_an_existence_oracle_across_tenants(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeWebhook($victimStore->id);

        $this->actingAs($attacker)->get('/webhook/' . $foreign->id)->assertStatus(404);
    }

    public function test_the_webhook_owner_still_reaches_show(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeWebhook($store->id);

        $this->actingAs($owner)->get('/webhook/' . $own->id)->assertStatus(200);
    }

    public function test_wishlist_edit_and_update_are_not_existence_oracles(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeWishlist($victimStore->id, $victim->id);

        $this->actingAs($attacker)->get('/wishlist/' . $foreign->id . '/edit')->assertStatus(404);
        $this->actingAs($attacker)->put('/wishlist/' . $foreign->id, [])->assertStatus(404);
    }

    public function test_the_wishlist_owner_still_reaches_edit_and_update(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeWishlist($store->id, $owner->id);

        $this->actingAs($owner)->get('/wishlist/' . $own->id . '/edit')->assertStatus(200);
        $this->actingAs($owner)->put('/wishlist/' . $own->id, [])->assertStatus(200);
    }

    public function test_newsletter_update_is_not_an_existence_oracle_across_tenants(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();
        $foreign = $this->makeNewsletter($victimStore->id);

        $this->actingAs($attacker)->put('/newsletter/' . $foreign->id, [])->assertStatus(404);
    }

    public function test_the_newsletter_owner_still_reaches_update(): void
    {
        [$owner, $store] = $this->makeTenant();
        $own = $this->makeNewsletter($store->id);

        $this->actingAs($owner)->put('/newsletter/' . $own->id, [])->assertStatus(200);
    }

    // =============================================================================================
    // THE THREE BRANCHES OF `getCurrentStore()` THAT NOTHING ELSE DRIVES.
    //
    // Raised by the tenant-authz review of this change: `makeTenant()` always sets
    // `current_store`, so every test above exercises exactly ONE of the helper's branches. The
    // other three are where its known traps live (`app/Helper/helper.php:767-781`), and a guard
    // built on it is only as good as those branches. Measured here rather than reasoned about.
    // =============================================================================================

    /**
     * STAFF OF THE OWNING TENANT MUST STILL BE ADMITTED -- the failure mode these guards could
     * plausibly have introduced. `getCurrentStore()` falls back to `Store::where('created_by',
     * $user->id)` -- the caller's OWN id, not `creatorId()` -- which is null for a staff member.
     * `makeStaff()` copies the owner's `current_store`, so the fallback is not reached and the
     * guard matches; this test is what proves that rather than assuming it.
     */
    public function test_staff_of_the_owning_tenant_still_reach_the_guarded_endpoints(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner);

        $webhook = $this->makeWebhook($store->id);
        $attribute = $this->makeProductAttribute($store->id);

        $this->actingAs($staff)->get('/webhook/' . $webhook->id)->assertStatus(200);
        $this->actingAs($staff)->get('/product-attributes/' . $attribute->id)->assertStatus(200);
    }

    /**
     * A STAFF MEMBER WITH NO `current_store` IS REFUSED, not admitted. This is the fallback branch
     * above: `Store::where('created_by', $staff->id)` finds nothing, `getCurrentStore()` answers
     * empty, and every guard's `empty($storeId)` limb fires. Recorded as an AVAILABILITY edge --
     * such a staff row sees 404 on their own tenant's data -- and it is the direction we want,
     * because the alternative is a predicate that admits when no tenant resolves.
     */
    public function test_a_staff_member_with_no_current_store_is_refused_rather_than_admitted(): void
    {
        [$owner, $store] = $this->makeTenant();
        $staff = $this->makeStaff($owner, ['current_store' => null]);

        $webhook = $this->makeWebhook($store->id);

        $this->actingAs($staff)->get('/webhook/' . $webhook->id)->assertStatus(404);
    }

    /**
     * THE OPERATOR WITH AN EMPTY `current_store` IS SCOPED TO THE LITERAL STORE 1, not given a
     * bypass. `getCurrentStore()` returns `1` for a `super admin` whose `current_store` is empty
     * (`helper.php:774-775`), so the operator reaches store 1's rows and is REFUSED everywhere
     * else. There is no super-admin bypass in this codebase and these guards add none.
     *
     * Asserted on a store that is deliberately NOT store 1, which is why the fixture store id is
     * checked first: on a fresh migration the first store created would BE 1 and the test would
     * pass for the wrong reason.
     */
    public function test_the_operator_with_no_current_store_is_scoped_to_store_one_not_bypassed(): void
    {
        // TWO tenants, and the SECOND one's store: on a fresh migration the first store created is
        // store 1, which the premise assertion below caught on the first run of this test. Left in
        // place rather than silently worked around -- it is the only thing standing between this
        // test and passing for the wrong reason if fixture ordering ever changes again.
        $this->makeTenant();
        [$owner, $store] = $this->makeTenant();

        $this->assertNotSame(
            1,
            (int) $store->id,
            'The fixture store is store 1, so this test cannot distinguish "refused" from '
            .'"allowed because getCurrentStore() returned 1". Fixture ordering changed.'
        );

        $operator = $this->makePlatformAdmin(['current_store' => null]);
        $webhook = $this->makeWebhook($store->id);

        $this->actingAs($operator)->get('/webhook/' . $webhook->id)->assertStatus(404);
    }

    /**
     * A ROW WITH A NULL `store_id` MATCHES NO STORE PREDICATE ANYWHERE IN THE APPLICATION and must
     * not become visible here. Both guard shapes check `!empty()` on the row side for exactly
     * this: without it, the comparison form would compare `(int) null === (int) $storeId` and the
     * query form would compile `where('store_id', null)` to `IS NULL` and MATCH the orphan.
     */
    public function test_a_row_with_a_null_store_id_is_refused_to_its_apparent_owner(): void
    {
        [$owner, $store] = $this->makeTenant();

        $orphan = $this->makeWebhook($store->id);
        \DB::table('webhooks')->where('id', $orphan->id)->update(['store_id' => null]);

        // Raw write, so the Cachable model cache would still serve the pre-write row: flush it.
        (new Webhook)->flushCache();

        $this->assertNull(
            \DB::table('webhooks')->where('id', $orphan->id)->first()->store_id,
            'The fixture did not actually null the column, so this test proves nothing.'
        );

        $this->actingAs($owner)->get('/webhook/' . $orphan->id)->assertStatus(404);
    }

    /**
     * A row that exists NOWHERE must be indistinguishable from a foreign one. This is the whole
     * point of choosing 404 over 403: a 403 for a foreign id and a 404 for an absent id would
     * leave the oracle intact while looking like a fix.
     */
    public function test_a_foreign_row_and_an_absent_row_answer_identically(): void
    {
        [$victim, $victimStore, $attacker] = $this->twoTenants();

        $pairs = [
            '/flash-sale/'        => $this->makeFlashSale($victimStore->id, $victim->id)->id,
            '/shipping-method/'   => $this->makeShippingMethod($victimStore->id)->id,
            '/testimonial/'       => $this->makeTestimonial($victimStore->id, $victim->id)->id,
            '/product-attributes/' => $this->makeProductAttribute($victimStore->id)->id,
            '/webhook/'           => $this->makeWebhook($victimStore->id)->id,
        ];

        foreach ($pairs as $prefix => $foreignId) {
            $foreign = $this->actingAs($attacker)->get($prefix . $foreignId)->status();
            $absent = $this->actingAs($attacker)->get($prefix . '999999')->status();

            $this->assertSame(
                $absent,
                $foreign,
                "{$prefix}: a foreign row answered {$foreign} and an absent one {$absent}, so the "
                .'status still distinguishes "exists in another store" from "exists nowhere".'
            );
        }
    }
}
