<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-B / F-SEC-09 -- `WishlistController::show` reads another tenant's wishlist, WITH DATA.
 * ================================================================================================
 *
 * FOUND BY THE TENANT-AUTHZ REVIEW OF F-SEC-08, AND THAT IS THE POINT WORTH RECORDING. The F-SEC-08
 * change guarded `WishlistController::edit` (:72) and `::update` (:95) -- the two methods either
 * side of `show` (:54) in the same file -- and left `show` open. My census was keyed on the
 * EMPTY-BODY shape, `show` has a body, so it was invisible to the census, invisible to the new
 * structural ratchet (which by design only flags empty bodies), and invisible to the F-SEC-08
 * feature test (which has no `show` case). Three separate instruments, all looking at this file,
 * all blind to the one method in it that returns bytes.
 *
 * TWO DEFECTS IN ELEVEN LINES:
 *
 *   1. THE BOUND ROW IS NOT OWNERSHIP-CHECKED. `Wishlist` is absent from `config/tenancy.php`'s
 *      `scoped_models`, so binding resolves any store's row. The permission that IS checked,
 *      `Show Wishlist`, is in the default grant list at `Utility.php:2959` that every `admin` role
 *      receives -- so this was live for every merchant on the platform, not a rare configuration.
 *
 *   2. THE INNER QUERY HAS NO STORE PREDICATE. `Wishlist::where('customer_id', $wish_id)->get()`
 *      is unscoped, so it collects every row carrying that customer id in ANY store. Guarding the
 *      bound row alone would NOT close this: ids collide across tenants in a shared-database
 *      design, and `customers` is store-scoped precisely because the same id space is reused.
 *
 * Both are asserted separately below, because a fix for one does not imply the other and a single
 * test could not tell me which I had actually repaired.
 *
 * WHY THE TESTS GRANT `Show Wishlist` EXPLICITLY. In production it arrives for free:
 * `Utility::addNewData()` runs on EVERY login and grants the shared global `admin` role every
 * name in `$companyNewPermission`, which includes `Show Wishlist` (`Utility.php:2959`) -- the role
 * every self-registered merchant holds. `makeMerchant()` does not replicate that, so the first
 * draft of this file answered 302 (permission refused) for the ATTACKER *and* for the OWNER, and
 * would have reported the cross-tenant case as closed while measuring nothing at all. The owner
 * positive control is the only reason I know that; it is the fourth time in this project that a
 * cross-tenant assertion passed for the wrong reason.
 *
 * The response is not an empty 200 as in F-SEC-08: `wishlist/show.blade.php:3` renders
 * `{{ $product->ProductData->name }}`, so the body carries another tenant's product names, and the
 * bound row discloses the victim customer's id.
 */
class WishlistShowCrossTenantTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCategory(int $storeId): Category
    {
        $name = 'Wishlist Cat ' . uniqid();

        return Category::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    private function makeProduct(int $storeId, int $createdBy, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $this->makeCategory($storeId)->id,
            'store_id' => $storeId, 'created_by' => $createdBy,
        ]);
    }

    /** A wishlist row in $storeId, for $customerId, holding a product named $productName. */
    private function makeWishlistRow(int $storeId, int $createdBy, int $customerId, string $productName): Wishlist
    {
        return Wishlist::forceCreate([
            'customer_id' => $customerId,
            'product_id' => $this->makeProduct($storeId, $createdBy, $productName)->id,
            'variant_id' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    private function makeCustomer(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'W', 'last_name' => 'Shopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    // =============================================================================================
    // DEFECT 1 -- the bound row
    // =============================================================================================

    public function test_a_merchant_cannot_read_another_tenants_wishlist(): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, 'Show Wishlist');

        $customer = $this->makeCustomer($victimStore->id);
        $foreign = $this->makeWishlistRow(
            $victimStore->id, $victim->id, $customer->id, 'VictimSecretProduct'
        );

        $response = $this->actingAs($attacker)->get('/wishlist/' . $foreign->id);

        $response->assertStatus(404);
        $response->assertDontSee('VictimSecretProduct');
    }

    public function test_the_wishlist_owner_can_still_read_their_own(): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Wishlist');

        $customer = $this->makeCustomer($store->id);
        $own = $this->makeWishlistRow($store->id, $owner->id, $customer->id, 'OwnVisibleProduct');

        $this->actingAs($owner)->get('/wishlist/' . $own->id)
            ->assertStatus(200)
            ->assertSee('OwnVisibleProduct');
    }

    // =============================================================================================
    // DEFECT 2 -- the inner `where('customer_id', ...)` query, asserted on its own
    //
    // Both rows carry the SAME customer id, one per store. That is the realistic shape in a shared
    // database: `customers` is store-scoped exactly because the id space is reused. A fix that only
    // guards the bound row leaves this test red, which is why it exists separately.
    // =============================================================================================

    public function test_the_owners_own_wishlist_page_does_not_list_another_stores_rows(): void
    {
        [$owner, $ownStore] = $this->makeTenant();
        [$other, $otherStore] = $this->makeTenant();
        $this->grantPermission($owner, 'Show Wishlist');

        $sharedCustomerId = 4242;

        $own = $this->makeWishlistRow(
            $ownStore->id, $owner->id, $sharedCustomerId, 'MineOnlyProduct'
        );
        $this->makeWishlistRow(
            $otherStore->id, $other->id, $sharedCustomerId, 'OtherStoreLeakedProduct'
        );

        $response = $this->actingAs($owner)->get('/wishlist/' . $own->id);

        $response->assertStatus(200);
        $response->assertSee('MineOnlyProduct');
        $response->assertDontSee(
            'OtherStoreLeakedProduct'
        );
    }
}
