<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C -- ANY REGISTERED SHOPPER CAN DELETE ANY WISHLIST ROW ON THE PLATFORM.
 * ================================================================================================
 *
 * FOUND BY THE TENANT-AUTHZ REVIEW OF THE WISHLIST SEND HANDLERS, as the third member of a family
 * I had repaired two of. The whole handler is one line:
 *
 *     public function delete_wishlist(Request $request, $slug)
 *     {
 *         Wishlist::where('id', $request->id)->delete();
 *     }
 *
 * No customer predicate, no store predicate, and `Wishlist` is absent from
 * `config/tenancy.php`'s `scoped_models`, so there is no global scope behind it for anybody. The
 * caller supplies the id and the row is gone.
 *
 * IT IS A DESTRUCTIVE WRITE ON A **GET** ROUTE (`routes/customer.php:338`), so it needs no CSRF
 * token: an `<img src="https://store/x/delete-wishlist?id=123">` on any page a signed-in shopper
 * loads fires it. `StateChangingGetInventoryTest` already carries this route as a declared
 * state-changing GET needing "its own caller sweep", so the VERB is tracked and is deliberately
 * not changed here -- converting it would change the storefront's own callers. What was missing is
 * the ownership predicate, and that is what this test pins.
 *
 * THE HISTORY IS THE INSTRUCTIVE PART. `routes/customer.php:303-306` records that an earlier pass
 * looked at this exact line and chose "NOT GATED HERE, reported instead", on the reasoning that
 * `delete.wishlist` "proves guests cannot own wishlist rows at all". That reasoning is about
 * GUESTS, and it is sound as far as it goes -- the route was then put behind customer
 * authentication. But authenticating a handler that trusts a caller-supplied id only narrows the
 * attacker from "anyone on the internet" to "anyone with an account", which for a public
 * storefront is not much of a narrowing. The predicate was never added.
 *
 * TWO ASSERTIONS, because they fail for different reasons and a single one could not tell me which
 * guard I had added: a foreign row in ANOTHER store, and a foreign row belonging to another
 * customer of the SAME store. The second is the one a store predicate alone would miss.
 */
class StorefrontWishlistDeleteOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeCustomerIn(int $storeId): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'S', 'last_name' => 'Hopper',
            'email' => uniqid() . '@example.test', 'password' => bcrypt('x'),
            'store_id' => $storeId,
        ]);
    }

    private function makeWishlistRow(int $storeId, int $createdBy, int $customerId): Wishlist
    {
        $name = 'Wished ' . uniqid();
        $category = Category::forceCreate([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
        $product = Product::forceCreate([
            'name' => $name, 'slug' => \Illuminate\Support\Str::slug($name) . '-p-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id, 'store_id' => $storeId, 'created_by' => $createdBy,
        ]);

        return Wishlist::forceCreate([
            'customer_id' => $customerId, 'product_id' => $product->id,
            'variant_id' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    /** Asserted through `withoutGlobalScopes()` -- a plain find answers against whichever tenant resolved. */
    private function rowStillExists(int $id): bool
    {
        return Wishlist::withoutGlobalScopes()->where('id', $id)->exists();
    }

    public function test_a_shopper_cannot_delete_another_stores_wishlist_row(): void
    {
        [$victimMerchant, $victimStore] = $this->makeTenant();
        [$attackerMerchant, $attackerStore] = $this->makeTenant();

        $victimCustomer = $this->makeCustomerIn($victimStore->id);
        $foreign = $this->makeWishlistRow($victimStore->id, $victimMerchant->id, $victimCustomer->id);

        $attacker = $this->makeCustomerIn($attackerStore->id);
        $this->actingAs($attacker, 'customers');

        $this->get('/' . $attackerStore->slug . '/delete-wishlist?id=' . $foreign->id);

        $this->assertTrue(
            $this->rowStillExists($foreign->id),
            'a shopper of one store DELETED a wishlist row belonging to another store. The handler '
            . 'deletes by caller-supplied id with no ownership predicate, and Wishlist has no '
            . 'global scope behind it.'
        );
    }

    public function test_a_shopper_cannot_delete_another_shoppers_row_in_their_own_store(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $victim = $this->makeCustomerIn($store->id);
        $theirRow = $this->makeWishlistRow($store->id, $merchant->id, $victim->id);

        $attacker = $this->makeCustomerIn($store->id);
        $this->actingAs($attacker, 'customers');

        $this->get('/' . $store->slug . '/delete-wishlist?id=' . $theirRow->id);

        $this->assertTrue(
            $this->rowStillExists($theirRow->id),
            'a shopper deleted ANOTHER shopper\'s wishlist row in the same store. A store '
            . 'predicate alone does not stop this -- the customer predicate is what does.'
        );
    }

    /**
     * THE COLLIDING-ID SHAPE, which is the only case the `store_id` predicate is load-bearing for.
     *
     * MUTATION FOUND THIS GAP. With the two tests above, dropping `store_id` left them green,
     * because both used a victim whose customer id DIFFERED from the attacker's, so `customer_id`
     * alone refused. The store predicate was being credited for the customer predicate's work --
     * the fourth time in this package a guard looked tested and was not.
     *
     * The realistic shape is a wishlist row in ANOTHER store that happens to carry the attacker's
     * own customer id. Nothing prevents that: `wishlists.customer_id` is a bare integer, each
     * store's customers are separate rows, and `customers` is store-scoped precisely BECAUSE the
     * id space is reused across tenants. So `where('customer_id', $mine)` matches a foreign
     * store's row, and only `store_id` stops the delete.
     */
    public function test_a_shopper_cannot_delete_a_foreign_stores_row_that_shares_their_customer_id(): void
    {
        [$victimMerchant, $victimStore] = $this->makeTenant();
        [, $attackerStore] = $this->makeTenant();

        $attacker = $this->makeCustomerIn($attackerStore->id);
        $this->actingAs($attacker, 'customers');

        // Store B's row, carrying the ATTACKER's own customer id -- the id-reuse shape.
        $foreign = $this->makeWishlistRow($victimStore->id, $victimMerchant->id, $attacker->id);

        $this->assertSame(
            $attacker->id,
            (int) Wishlist::withoutGlobalScopes()->where('id', $foreign->id)->value('customer_id'),
            'premise: the foreign row must carry the attacker\'s own customer id, or the '
            . 'customer predicate alone would refuse and this test would not reach the store one'
        );

        $this->get('/' . $attackerStore->slug . '/delete-wishlist?id=' . $foreign->id);

        $this->assertTrue(
            $this->rowStillExists($foreign->id),
            'a shopper deleted a row in ANOTHER store that happened to share their customer id. '
            . 'The customer predicate matches it; only the store predicate refuses.'
        );
    }

    /**
     * THE POSITIVE CONTROL. Without it a handler that deleted nothing at all would satisfy both
     * assertions above, and "the feature is broken" would read as "the feature is secure".
     */
    public function test_a_shopper_can_still_delete_their_own_wishlist_row(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $shopper = $this->makeCustomerIn($store->id);
        $own = $this->makeWishlistRow($store->id, $merchant->id, $shopper->id);

        $this->actingAs($shopper, 'customers');
        $this->get('/' . $store->slug . '/delete-wishlist?id=' . $own->id);

        $this->assertFalse(
            $this->rowStillExists($own->id),
            'the shopper could no longer remove their OWN wishlist row -- the guard is too broad'
        );
    }
}
