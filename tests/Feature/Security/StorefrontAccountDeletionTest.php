<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryAddress;
use App\Models\DeliveryBoy;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11, AUTHZ-2 — POST api/{slug}/user-delete, the most destructive row in the package.
 *
 * The handler was:
 *
 *     $user = User::find($request->user_id);            // the ADMIN model, bare id
 *     DeliveryAddress::where('customer_id', $request->user_id)->delete();
 *     Order::where('customer_id', $request->user_id)->delete();
 *     Wishlist::where('customer_id', $request->user_id)->delete();
 *     Testimonial::where('user_id', $request->user_id)->delete();
 *     $user->delete();
 *
 * Four failures, each sufficient alone:
 *
 *   1. `user_id` came from the request and was never compared to the caller. The route carries
 *      ['custom.auth','APILog'] and custom.auth admits a Customer, a User or a DeliveryBoy token.
 *   2. It deleted from the ADMIN model — a merchant or the platform operator — on a storefront
 *      endpoint whose only plausible caller is a shopper.
 *   3. None of User, Order, Wishlist, DeliveryAddress or Testimonial is tenant-scoped, so nothing
 *      narrowed any of the five deletes.
 *   4. IT CROSSED TWO ID SPACES. The cascade filtered `customer_id` by a value taken from
 *      `users.id`. Those are independent sequences, so naming a merchant also destroyed the entire
 *      order history, addresses and wishlist of whichever CUSTOMER shared that integer. The test
 *      below constructs exactly that collision, because it is the part that is easy to miss by
 *      reading and impossible to miss by running.
 */
class StorefrontAccountDeletionTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'del-' . $label . '-' . uniqid() . '@example.test',
            'password' => bcrypt('original-password-not-real'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function callUserDelete($store, $userId, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)
            ->postJson('/api/' . $store->slug . '/user-delete', ['user_id' => $userId]);
    }

    private function productIn($store): Product
    {
        return Product::forceCreate([
            'name' => 'del-product-' . uniqid(),
            'slug' => 'del-product-' . uniqid(),
            'product_stock' => 1,
            // products.track_stock is NOT NULL with no default.
            'track_stock' => 0,
            'variant_product' => 0,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    /** Orders, addresses and a wishlist row, so a cascade delete is visible. */
    private function belongingsFor($store, Customer $customer): array
    {
        $order = $this->makeOrder($store, ['customer_id' => $customer->id, 'is_guest' => 0]);

        // delivery_addresses has `full_name` and `title`, not `name`
        // (2023_12_18_062620_create_delivery_addresses_table.php:16-27).
        $address = DeliveryAddress::forceCreate([
            'customer_id' => $customer->id,
            'full_name' => 'Home Recipient',
            'title' => 'Home',
            'address' => '1 Test Street',
            'default_address' => 1,
            'store_id' => $store->id,
        ]);

        // wishlists.product_id carries a real FK to products
        // (2024_01_22_040531_create_wishlists_table.php:24), so a literal id will not do.
        $wishlist = Wishlist::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $this->productIn($store)->id,
            'variant_id' => 0,
            'store_id' => $store->id,
        ]);

        return [$order, $address, $wishlist];
    }

    /**
     * Assertions bypass the tenant scope deliberately.
     *
     * `Customer` carries BOTH StoreScope and SoftDeletingScope (verified by enumerating
     * getGlobalScopes()). So a plain `Customer::find()` in an assertion answers null whenever the
     * resolved tenant differs from the row's store -- which is precisely what the cross-store
     * tests here construct -- and the soft-delete scope hides a trashed row too. An
     * "it is gone" assertion written that way passes whether or not the fix works.
     *
     * withoutGlobalScopes() drops both, so these read the table. `customerStillExists()` then
     * states soft-deletion explicitly rather than inheriting it from a scope.
     */
    private function customerStillExists($customer): bool
    {
        $row = Customer::withoutGlobalScopes()->find(
            $customer instanceof Customer ? $customer->getKey() : $customer
        );

        return $row !== null && $row->deleted_at === null;
    }

    private function assertBelongingsIntact(array $belongings, string $because): void
    {
        [$order, $address, $wishlist] = $belongings;

        $this->assertNotNull(Order::find($order->id), $because . ' — the order was deleted.');
        $this->assertNotNull(DeliveryAddress::find($address->id), $because . ' — the address was deleted.');
        $this->assertNotNull(Wishlist::find($wishlist->id), $because . ' — the wishlist row was deleted.');
    }

    // ===================================================================================
    // THE ADMIN MODEL IS OUT OF REACH
    // ===================================================================================

    public function test_a_customer_cannot_delete_the_platform_operator(): void
    {
        [, $store] = $this->makeTenant();
        $operator = $this->makePlatformAdmin();
        $attacker = $this->customerIn($store, 'a1');

        $this->callUserDelete($store, $operator->id, $attacker->createToken('t')->plainTextToken);

        $this->assertNotNull(User::find($operator->id),
            'A shopper deleted the platform operator by naming their id.');
    }

    public function test_a_customer_cannot_delete_a_merchant(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $attacker = $this->customerIn($store, 'a2');

        $this->callUserDelete($store, $merchant->id, $attacker->createToken('t')->plainTextToken);

        $this->assertNotNull(User::find($merchant->id),
            'A shopper deleted a merchant by naming their id.');
    }

    public function test_a_merchant_token_cannot_delete_anything_here(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a3');
        $belongings = $this->belongingsFor($store, $victim);

        $this->callUserDelete($store, $victim->id, $merchant->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($victim),
            "A merchant's token deleted a customer through the storefront endpoint.");
        $this->assertBelongingsIntact($belongings, "A merchant's token ran the cascade");
    }

    public function test_a_driver_token_cannot_delete_anything_here(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a4');
        $driver = $this->driverFor($merchant, $store);
        $belongings = $this->belongingsFor($store, $victim);

        $this->callUserDelete($store, $victim->id, $driver->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($victim),
            "A delivery driver's token deleted a customer.");
        $this->assertBelongingsIntact($belongings, "A driver's token ran the cascade");
    }

    // ===================================================================================
    // THE ID-SPACE COLLISION — the failure that reading alone misses
    // ===================================================================================

    /**
     * `users` and `customers` have independent sequences. The cascade filtered `customer_id` by a
     * value taken from `users.id`, so naming a merchant destroyed an unrelated shopper's data.
     *
     * This test forces the collision explicitly rather than hoping the sequences line up, so it
     * measures the defect instead of depending on fixture luck.
     */
    public function test_naming_a_user_id_cannot_destroy_the_customer_who_shares_that_integer(): void
    {
        [$merchant, $store] = $this->makeTenant();

        $bystander = $this->customerIn($store, 'b1-bystander');

        // Force a `users` row whose id equals the bystander customer's id.
        $collidingUserId = (int) $bystander->id;

        if (User::find($collidingUserId) === null) {
            DB::table('users')->insert([
                'id' => $collidingUserId,
                'name' => 'Colliding Merchant',
                'email' => 'collide-' . uniqid() . '@example.test',
                'password' => bcrypt('x'),
                'type' => 'admin',
                'created_by' => $merchant->id,
                'lang' => 'en',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->assertNotNull(User::find($collidingUserId),
            'Could not construct the id collision, so this test would prove nothing.');

        $belongings = $this->belongingsFor($store, $bystander);
        $attacker = $this->customerIn($store, 'b1-attacker');

        $this->callUserDelete($store, $collidingUserId, $attacker->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($bystander),
            'Naming a users.id deleted the CUSTOMER sharing that integer.');
        $this->assertBelongingsIntact(
            $belongings,
            'Naming a users.id ran the cascade against an unrelated customer'
        );
        $this->assertNotNull(User::find($collidingUserId),
            'The users row was deleted too.');
    }

    // ===================================================================================
    // ONE CUSTOMER CANNOT DELETE ANOTHER
    // ===================================================================================

    public function test_one_customer_cannot_delete_another_in_the_same_store(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c1-victim');
        $attacker = $this->customerIn($store, 'c1-attacker');
        $belongings = $this->belongingsFor($store, $victim);

        $this->callUserDelete($store, $victim->id, $attacker->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($victim),
            'One shopper deleted another by naming their id.');
        $this->assertBelongingsIntact($belongings, 'One shopper ran the cascade on another');
    }

    public function test_a_customer_cannot_delete_one_in_another_store(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'c2-victim');
        $attacker = $this->customerIn($storeB, 'c2-attacker');
        $belongings = $this->belongingsFor($storeA, $victim);

        $this->callUserDelete($storeB, $victim->id, $attacker->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($victim),
            'A shopper of store B deleted a customer of store A.');
        $this->assertBelongingsIntact($belongings, 'A cross-tenant cascade ran');
    }

    /**
     * Isolates the store predicate specifically.
     *
     * Once the target is session-derived, a wrong slug can no longer redirect the deletion to
     * another account -- so the remaining question this answers is whether one tenant's endpoint
     * may act on another tenant's customer at all. It may not: the account survives. Removing the
     * `$customer->store_id !== (int) $store->id` comparison is what makes this test fail, which is
     * the only reason the comparison is in the handler.
     */
    public function test_a_customer_cannot_delete_their_own_account_through_another_stores_slug(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $customer = $this->customerIn($storeA, 'c4');
        $belongings = $this->belongingsFor($storeA, $customer);

        $this->callUserDelete($storeB, $customer->id, $customer->createToken('t')->plainTextToken);

        $this->assertTrue($this->customerStillExists($customer),
            "Store B's endpoint deleted a customer of store A.");
        $this->assertBelongingsIntact($belongings, "Store B's endpoint ran the cascade on store A");
    }

    /**
     * A premise test, not a bite on this handler's own control: `custom.auth` refuses a missing
     * bearer token at app/Http/Middleware/CustomAuth.php before the handler runs, so this passes
     * against the unfixed body too. It is here to record that the middleware really is on the
     * route, because every denial below assumes it.
     */
    public function test_an_anonymous_caller_is_refused(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'c3');

        $this->callUserDelete($store, $victim->id, null);

        $this->assertTrue($this->customerStillExists($victim),
            'An anonymous caller deleted a customer.');
    }

    // ===================================================================================
    // AND THE LEGITIMATE JOURNEY STILL WORKS
    // ===================================================================================

    public function test_a_shopper_can_still_delete_their_own_account(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd1');
        $belongings = $this->belongingsFor($store, $customer);
        $token = $customer->createToken('auth_token');

        $this->callUserDelete($store, $customer->id, $token->plainTextToken)
            ->assertStatus(200)
            ->assertJsonPath('status', 1);

        $this->assertFalse($this->customerStillExists($customer),
            'A shopper can no longer delete their own account; that is the journey this endpoint '
            . 'exists for.');

        [$order, $address, $wishlist] = $belongings;

        /*
         * REVERSED BY OWNER DECISION D14 (P1-ORD-03, #22): order and accounting records are
         * PRESERVED when a customer is deleted.
         *
         * This assertion used to be `assertNull(Order::find(...))` -- it pinned the hard delete as
         * correct behaviour. It is not: `Order` has no SoftDeletes, so a shopper closing their
         * account irreversibly destroyed the merchant's sales history, while `Customer` itself
         * soft-deletes and survives. The address and the wishlist ARE the customer's own content
         * and are still removed, which is what keeps this test honest about the difference.
         */
        $this->assertNotNull(Order::find($order->id),
            'The order was destroyed with the account. Owner decision D14: order and accounting '
            . 'records are preserved.');
        $this->assertNull(DeliveryAddress::find($address->id), 'Their own address survived.');
        $this->assertNull(Wishlist::find($wishlist->id), 'Their own wishlist row survived.');

        $this->assertNull(
            \Laravel\Sanctum\PersonalAccessToken::find($token->accessToken->id),
            'The deleted account\'s API token still exists, so it remains usable.'
        );
    }

    public function test_a_shopper_deleting_themselves_does_not_touch_another_customer(): void
    {
        [, $store] = $this->makeTenant();
        $leaving = $this->customerIn($store, 'd2-leaving');
        $staying = $this->customerIn($store, 'd2-staying');
        $stayingBelongings = $this->belongingsFor($store, $staying);

        $this->callUserDelete($store, $leaving->id, $leaving->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertTrue($this->customerStillExists($staying),
            'Deleting one account removed another.');
        $this->assertBelongingsIntact($stayingBelongings, 'The cascade reached another customer');
    }

    public function test_the_posted_user_id_is_ignored_entirely(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'd3');
        $other = $this->customerIn($store, 'd3-other');

        // A tampered id must only ever affect the session's own account.
        $this->callUserDelete($store, $other->id, $customer->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertFalse($this->customerStillExists($customer),
            "The session's own account was not the one deleted.");
        $this->assertTrue($this->customerStillExists($other),
            'The tampered user_id reached another account.');
    }
}
