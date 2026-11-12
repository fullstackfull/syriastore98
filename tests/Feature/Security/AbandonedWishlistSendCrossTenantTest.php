<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * P2.5-PKG-C -- THE WISHLIST "NUDGE THE SHOPPER" ACTIONS ARE F-SEC-09 AGAIN, AND WORSE.
 * ================================================================================================
 *
 * FOUND BY ADDING AN ERROR HANDLER AND THEN LOOKING AT WHAT IT SAID. The four recovery actions on
 * these two screens had no `error` leg, so every failure was silent. Adding one and driving a
 * missing row put this on the merchant's screen, in a toast:
 *
 *     Error -- Attempt to read property "customer_id" on null
 *
 * A raw PHP error, verbatim, in the interface. The defect was always there; the silence was what
 * hid it. That is the whole argument for the error leg, and it is also a warning: surfacing errors
 * surfaces the ones you did not know about, so the leg must not relay a 5xx body (it now sends a
 * generic message for those, and the controller's own wording only for 4xx).
 *
 * WHY THIS IS WORSE THAN THE CART TWIN. `abandon_carts_emailsend` relies on `Cart`'s global scope,
 * which at least filters for a caller who resolves a tenant -- the hole there needed a STORELESS
 * principal. `Wishlist` is absent from `config/tenancy.php`'s `scoped_models` ENTIRELY: there is no
 * scope to fall back on for anybody. So an ordinary merchant with their own store, holding a
 * permission that `Utility::addNewData()` grants on every login, can name any wishlist id on the
 * platform and act on it. This is F-SEC-09 -- which I closed on `WishlistController::show` earlier
 * in this phase -- standing unrepaired in two more methods of the same file. The pattern of F-SEC-09
 * repeated exactly: the fix went to the method that was being reviewed, and the siblings that
 * return no HTML were not looked at.
 *
 * `abandonWishlistMsgSend` CARRIES FOUR DEFECTS IN FIVE LINES, and the ordering is the one worth
 * naming: `Wishlist::find()`, `$cart->customer_id` and `$cart->UserData` all run BEFORE
 * `isAbleTo()`. So a caller WITHOUT the permission still drove the lookup and the relation load,
 * and a missing row made it 500 before reaching the refusal -- authorization running after the work
 * it is supposed to gate. The cart twin had exactly this fixed under P1-SEC and the wishlist one
 * was left.
 *
 * NOT FIXED HERE, AND DELIBERATELY: `abandonWishlistMsgSend` gates on `Abandon Cart`, not
 * `Abandon Wishlist`. That is plainly wrong, but correcting it CHANGES WHO MAY DO WHAT -- a role
 * holding `Abandon Cart` without `Abandon Wishlist` can send these today and could not afterwards.
 * §8 of the directive reserves permission changes to the owner, so it is recorded rather than
 * changed, and this file asserts the permission that is actually checked rather than the one that
 * ought to be.
 */
class AbandonedWishlistSendCrossTenantTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function makeWishlistRow(int $storeId, int $createdBy, int $customerId, string $productName): Wishlist
    {
        $catName = 'Wish Cat ' . uniqid();
        $category = Category::forceCreate([
            'name' => $catName,
            'slug' => \Illuminate\Support\Str::slug($catName) . '-' . uniqid(),
            'parent_id' => 0, 'trending' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
        $product = Product::forceCreate([
            'name' => $productName,
            'slug' => \Illuminate\Support\Str::slug($productName) . '-' . uniqid(),
            'description' => 'x', 'price' => 10, 'track_stock' => 0, 'trending' => 0,
            'stock_status' => 'in_stock', 'product_stock' => 5, 'product_type' => null,
            'status' => 1, 'variant_product' => 0,
            'category_id' => $category->id, 'store_id' => $storeId, 'created_by' => $createdBy,
        ]);
        $customer = Customer::forceCreate([
            'first_name' => 'W', 'last_name' => 'Shopper', 'email' => uniqid() . '@example.test',
            'password' => bcrypt('x'), 'store_id' => $storeId,
        ]);

        return Wishlist::forceCreate([
            'customer_id' => $customerId > 0 ? $customerId : $customer->id,
            'product_id' => $product->id, 'variant_id' => 0, 'status' => 1, 'store_id' => $storeId,
        ]);
    }

    /** endpoint => the permission the handler ACTUALLY checks (see the class docblock) */
    public static function sendEndpoints(): array
    {
        return [
            'email' => ['/abandon-wish-emailsend', 'Abandon Wishlist'],
            'sms' => ['/abandon-wishlist-message-send', 'Abandon Cart'],
        ];
    }

    /**
     * @dataProvider sendEndpoints
     */
    public function test_a_merchant_cannot_nudge_another_tenants_shopper(string $endpoint, string $permission): void
    {
        [$victim, $victimStore] = $this->makeTenant();
        [$attacker] = $this->makeTenant();
        $this->grantPermission($attacker, $permission);

        $foreign = $this->makeWishlistRow($victimStore->id, $victim->id, 0, 'VictimWishedProduct');

        // The premise: `Wishlist` has NO global scope, so the attacker's own query sees the row.
        // If this ever stops being true the assertion below could pass for the wrong reason.
        $this->actingAs($attacker);
        $this->assertNotNull(
            Wishlist::find($foreign->id),
            'premise: Wishlist is absent from scoped_models, so nothing hides the foreign row '
            . 'and the handler\'s own predicate is the only guard under test'
        );

        $response = $this->actingAs($attacker)->postJson($endpoint, ['wish_id' => $foreign->id]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'an ordinary merchant reached another tenant\'s wishlist through ' . $endpoint
            . '. Status ' . $response->getStatusCode()
        );
        $response->assertDontSee('VictimWishedProduct');
    }

    /**
     * @dataProvider sendEndpoints
     */
    public function test_a_missing_row_refuses_instead_of_leaking_a_php_error(string $endpoint, string $permission): void
    {
        [$merchant] = $this->makeTenant();
        $this->grantPermission($merchant, $permission);

        $response = $this->actingAs($merchant)->postJson($endpoint, ['wish_id' => 99999999]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'a wishlist id that resolves to nothing must refuse, not 500. A 500 reaches the '
            . 'merchant as a toast reading `Attempt to read property "customer_id" on null`.'
        );
        $this->assertStringNotContainsString('Attempt to read property', (string) $response->getContent());
    }

    /**
     * AUTHORIZATION BEFORE THE WORK IT GATES. `abandonWishlistMsgSend` ran the lookup and the
     * relation load before `isAbleTo()`, so an unpermitted caller drove both -- and with a missing
     * row answered 500 rather than a refusal, which is also how a probe learns a row does not
     * exist without holding any permission at all.
     *
     * @dataProvider sendEndpoints
     */
    public function test_an_unpermitted_caller_is_refused_before_any_lookup(string $endpoint): void
    {
        [$merchant] = $this->makeTenant();   // no permission granted

        $response = $this->actingAs($merchant)->postJson($endpoint, ['wish_id' => 99999999]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'the permission check must run FIRST, so a missing row cannot 500 ahead of the refusal'
        );
    }

    /**
     * THE STORELESS CALLER -- the one case the rest of this file could not fail for.
     *
     * ADDED AFTER THE TENANT-AUTHZ REVIEW POINTED OUT that reverting BOTH `empty($storeId)`
     * refusals left all eight tests above green, so half the fix was unverified. Every other
     * principal here comes from `makeTenant()`, which sets `current_store`, so the branch was
     * never entered. The cart twin had this case from the start; the wishlist one did not, and I
     * did not notice because the suite was green.
     *
     * It is not a theoretical branch. `wishlists.store_id` is nullable with no default
     * (2024_01_22_040531), so without an explicit refusal the predicate compiles to
     * `store_id IS NULL` and refuses only by the accident of what the table happens to contain --
     * the same argument already recorded on the cart side, and the same mistake I made there in
     * prose before the review corrected it.
     *
     * `getCurrentStore()` is NULL for this principal because its fallback keys on
     * `Store::where('created_by', $user->id)` -- the caller's OWN id -- so it is also NULL for
     * any STAFF member with an unset `current_store`, who never created the store they work in.
     *
     * @dataProvider sendEndpoints
     */
    public function test_a_storeless_caller_cannot_nudge_another_tenants_shopper(string $endpoint, string $permission): void
    {
        [$victim, $victimStore] = $this->makeTenant();

        // makeMerchant() creates NO store, unlike makeTenant(), so the created_by fallback comes
        // back empty too and getCurrentStore() really is null.
        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, $permission);

        $foreign = $this->makeWishlistRow($victimStore->id, $victim->id, 0, 'StorelessReachableWish');

        $this->actingAs($storeless);
        $this->assertNotNull(
            Wishlist::find($foreign->id),
            'premise: nothing hides the foreign row from this caller -- Wishlist has no global '
            . 'scope at all, so the handler\'s own refusal is the only guard under test'
        );

        $response = $this->actingAs($storeless)->postJson($endpoint, ['wish_id' => $foreign->id]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'a caller with no store reached another tenant\'s wishlist through ' . $endpoint
            . '. Status ' . $response->getStatusCode()
        );
        $response->assertDontSee('StorelessReachableWish');
    }

    /**
     * THE ROW THAT MAKES THE GUARD LOAD-BEARING: a wishlist line with a NULL `store_id`.
     *
     * THE TEST ABOVE WAS NOT ENOUGH, and I only know that because I mutated it. Deleting both
     * `empty($storeId)` refusals left it green: without them the lookup compiles to
     * `store_id IS NULL`, and every row the fixture writes carries a real store, so the query
     * misses and the handler 404s anyway. The test measured the predicate, not the guard. That is
     * the second time in this package a cross-tenant assertion passed for a reason other than the
     * one it claimed.
     *
     * `wishlists.store_id` IS nullable with no default (2024_01_22_040531), so this row is not a
     * contrivance -- it is the legacy or imported line the tenant-authz review named, and
     * `config/tenancy.php` already records that precondition as the operator's. For a caller whose
     * `getCurrentStore()` is NULL, `store_id IS NULL` MATCHES it, so without the explicit refusal
     * the handler reads and acts on a row belonging to no tenant at all, for a caller belonging to
     * no tenant at all. With the refusal it never runs a query.
     *
     * Mutation-checked in both directions: delete either `empty($storeId)` guard and this test
     * turns red; restore it and it passes.
     *
     * @dataProvider sendEndpoints
     */
    public function test_a_storeless_caller_cannot_reach_a_null_store_wishlist_row(string $endpoint, string $permission): void
    {
        [$victim, $victimStore] = $this->makeTenant();

        $orphan = $this->makeWishlistRow($victimStore->id, $victim->id, 0, 'NullStoreOrphanWish');
        // Straight to the column, because `store_id` is not in the model's $fillable path for a
        // null and the point is a row this application would not write today.
        \DB::table('wishlists')->where('id', $orphan->id)->update(['store_id' => null]);

        $storeless = $this->makeMerchant(['current_store' => null]);
        $this->grantPermission($storeless, $permission);

        // The premise, asserted rather than assumed: the orphan row really has a NULL store, so
        // `where('store_id', null)` WOULD match it if the guard were absent.
        $this->assertNull(
            \DB::table('wishlists')->where('id', $orphan->id)->value('store_id'),
            'premise: the row under test must carry a NULL store_id'
        );

        $response = $this->actingAs($storeless)->postJson($endpoint, ['wish_id' => $orphan->id]);

        $this->assertSame(
            404,
            $response->getStatusCode(),
            'a storeless caller reached a NULL-store wishlist row through ' . $endpoint
            . '. Status ' . $response->getStatusCode() . '. `where(\'store_id\', null)` compiles '
            . 'to `store_id IS NULL`, which MATCHES this row -- only the explicit refusal stops it.'
        );
        $response->assertDontSee('NullStoreOrphanWish');
    }

    /**
     * @dataProvider sendEndpoints
     */
    public function test_the_owner_can_still_nudge_their_own_shopper(string $endpoint, string $permission): void
    {
        [$owner, $store] = $this->makeTenant();
        $this->grantPermission($owner, $permission);

        $own = $this->makeWishlistRow($store->id, $owner->id, 0, 'OwnWishedProduct');

        $response = $this->actingAs($owner)->postJson($endpoint, ['wish_id' => $own->id]);

        // Neither gateway is configured here, so both answer 200 with is_success false whatever
        // happens. What this asserts is that the row RESOLVED: 404 is the refusal, 403 the gate.
        $this->assertNotSame(404, $response->getStatusCode(), 'the owner was refused their own row');
        $this->assertNotSame(403, $response->getStatusCode(), 'the owner was refused by permission');
    }
}
