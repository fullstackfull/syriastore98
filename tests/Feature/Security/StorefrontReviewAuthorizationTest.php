<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\Testimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the two storefront handlers that write `testimonials`: product_rating and
 * add_review. Grouped because they write the same table by different routes with different rules,
 * and separated from the rest of the package because the defect class is different: forged
 * ATTRIBUTION and self-publishing content, not a leaked or overwritten account.
 *
 *   product_rating  took `user_id` from the request, so a review could be attributed to anyone.
 *                   The dedupe keyed on the same posted value, so an attacker could also exhaust
 *                   another account's one-review-per-product allowance. `Product::find($request->id)`
 *                   had no store predicate, and Testimonial::AvregeRating() then WRITES
 *                   `average_rating` back onto that product -- so this was a cross-tenant WRITE,
 *                   not only a cross-tenant read.
 *
 *   add_review      read no principal of any kind. `user_id` was never set and took its column
 *                   default of 1, so every review claimed to be from user id 1; `product_id` was
 *                   checked for neither existence nor store membership; and `status` came from the
 *                   caller, so the review was self-publishing.
 *
 * Product carries StoreScope, but on these routes no tenant resolves and `null_tenant => 'open'`
 * adds no predicate, so an unscoped find() genuinely reached another tenant's catalogue. That is
 * asserted below rather than assumed.
 */
class StorefrontReviewAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'rev-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function driverFor(User $merchant, $store): DeliveryBoy
    {
        return DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'drv-' . uniqid() . '@example.test',
            'password' => bcrypt('driver-password-not-real'),
            'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);
    }

    private function categoryIn($store): int
    {
        $id = \Illuminate\Support\Facades\DB::table('categories')
            ->where('store_id', $store->id)->value('id');

        if ($id === null) {
            $id = \Illuminate\Support\Facades\DB::table('categories')->insertGetId([
                // `categories` has no created_by column.
                'name' => 'rev-cat-' . uniqid(),
                'slug' => 'rev-cat-' . uniqid(),
                'status' => 1,
                'store_id' => $store->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return (int) $id;
    }

    /**
     * The product carries a real category, because `testimonials.category_id` has a foreign key
     * to `categories` (2023_12_26_095726_create_testimonials_table.php:27). Without one, every
     * write here dies on a constraint violation and returns 500 -- and a denial test asserting
     * "no review was written" then passes against the UNFIXED handler. Three of the add_review
     * tests below were doing exactly that on their first run.
     */
    private function productIn($store): Product
    {
        $categoryId = $this->categoryIn($store);

        return Product::forceCreate([
            'name' => 'rev-product-' . uniqid(),
            'slug' => 'rev-product-' . uniqid(),
            'product_stock' => 5, 'track_stock' => 0, 'variant_product' => 0,
            'average_rating' => 0,
            'category_id' => $categoryId,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function callApi($store, string $path, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /** Testimonial and Product lookups bypass scopes so these read the table, not the tenant. */
    private static function reviews()
    {
        return Testimonial::withoutGlobalScopes();
    }

    private static function productRow($id): ?Product
    {
        return Product::withoutGlobalScopes()->find($id);
    }

    // ===================================================================================
    // product_rating — forged attribution and a cross-tenant product write
    // ===================================================================================

    public function test_a_review_cannot_be_attributed_to_another_account(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'p1-victim');
        $attacker = $this->customerIn($store, 'p1-attacker');
        $product = $this->productIn($store);

        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => $victim->id,
            'rating_no' => 1, 'title' => 'FORGED', 'description' => 'FORGED',
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->where('user_id', $victim->id)->count(),
            "A review was written in another shopper's name.");
        $this->assertSame(1, self::reviews()->where('user_id', $attacker->id)->count(),
            "The review was not attributed to the caller.");
    }

    /**
     * The dedupe keyed on the posted id too, so naming a victim consumed THEIR allowance for
     * that product and they could never review it themselves.
     */
    public function test_a_caller_cannot_consume_another_accounts_review_allowance(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'p2-victim');
        $attacker = $this->customerIn($store, 'p2-attacker');
        $product = $this->productIn($store);

        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => $victim->id,
            'rating_no' => 1, 'title' => 'FORGED', 'description' => 'FORGED',
        ], $attacker->createToken('t')->plainTextToken);

        // The victim must still be able to review that product.
        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => $victim->id,
            'rating_no' => 5, 'title' => 'GENUINE', 'description' => 'GENUINE',
        ], $victim->createToken('t')->plainTextToken)->assertJsonPath('status', 1);

        $this->assertSame(1, self::reviews()->where('user_id', $victim->id)->count(),
            "The victim's own review was blocked by one planted in their name.");
    }

    public function test_a_merchant_token_cannot_write_a_product_rating(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store);

        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => $merchant->id,
            'rating_no' => 5, 'title' => 'SELF-PROMOTION', 'description' => 'SELF-PROMOTION',
        ], $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->count(),
            "A merchant's token wrote a review of their own product.");
    }

    public function test_a_driver_token_cannot_write_a_product_rating(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $driver = $this->driverFor($merchant, $store);
        $product = $this->productIn($store);

        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => 1,
            'rating_no' => 5, 'title' => 'DRIVER', 'description' => 'DRIVER',
        ], $driver->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->count(),
            "A driver's token wrote a review.");
    }

    /**
     * The cross-tenant WRITE. AvregeRating() loads the product and saves `average_rating` onto
     * it, so rating another tenant's product through your own store's slug modified their row.
     */
    public function test_a_rating_cannot_reach_another_tenants_product(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victimProduct = $this->productIn($storeB);
        $attacker = $this->customerIn($storeA, 'p5');

        $this->callApi($storeA, 'product-rating', [
            'id' => $victimProduct->id, 'user_id' => $attacker->id,
            'rating_no' => 1, 'title' => 'SABOTAGE', 'description' => 'SABOTAGE',
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->where('product_id', $victimProduct->id)->count(),
            "Store A's endpoint attached a review to store B's product.");
        $this->assertSame(0, (int) self::productRow($victimProduct->id)->average_rating,
            "Store B's product row was WRITTEN -- AvregeRating() saved a new average_rating onto "
            . 'another tenant\'s catalogue.');
    }

    public function test_a_shopper_can_still_rate_a_product_in_their_store(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'p6');
        $product = $this->productIn($store);

        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => $customer->id,
            'rating_no' => 4, 'title' => 'GOOD', 'description' => 'GOOD',
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $review = self::reviews()->where('product_id', $product->id)->first();
        $this->assertNotNull($review, 'A shopper can no longer rate a product.');
        $this->assertSame((int) $customer->id, (int) $review->user_id);
        $this->assertSame(4, (int) self::productRow($product->id)->average_rating,
            'The product average stopped being recalculated.');
    }

    public function test_the_one_rating_per_product_rule_still_applies_to_the_caller(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'p7');
        $product = $this->productIn($store);
        $token = $customer->createToken('t')->plainTextToken;

        $payload = [
            'id' => $product->id, 'user_id' => $customer->id,
            'rating_no' => 4, 'title' => 'FIRST', 'description' => 'FIRST',
        ];

        $this->callApi($store, 'product-rating', $payload, $token)->assertJsonPath('status', 1);
        $this->callApi($store, 'product-rating', $payload, $token)->assertJsonPath('status', 0);

        $this->assertSame(1, self::reviews()->where('product_id', $product->id)->count(),
            'A shopper can now rate the same product twice.');
    }

    // ===================================================================================
    // add_review — no principal, no product check, caller-chosen status
    // ===================================================================================

    public function test_add_review_refuses_a_merchant_token(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $product = $this->productIn($store);

        // A VALID category: with a nonexistent one the insert dies on the testimonials ->
        // categories foreign key, and this denial would pass against the unfixed handler because
        // it crashed rather than because it refused.
        $this->callApi($store, 'add-review', [
            'category_id' => $this->categoryIn($store), 'product_id' => $product->id,
            'rating_no' => 5, 'title' => 'SELF-PROMOTION', 'description' => 'SELF-PROMOTION',
            'status' => 1,
        ], $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->count(),
            "A merchant's token published a review of their own product.");
    }

    public function test_add_review_cannot_reach_another_tenants_product(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victimProduct = $this->productIn($storeB);
        $attacker = $this->customerIn($storeA, 'r2');

        $this->callApi($storeA, 'add-review', [
            'category_id' => $this->categoryIn($storeA), 'product_id' => $victimProduct->id,
            'rating_no' => 1, 'title' => 'SABOTAGE', 'description' => 'SABOTAGE', 'status' => 1,
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->where('product_id', $victimProduct->id)->count(),
            "Store A's endpoint attached a review to store B's product.");
    }

    public function test_add_review_cannot_be_attached_to_a_nonexistent_product(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'r3');

        $this->callApi($store, 'add-review', [
            'category_id' => $this->categoryIn($store), 'product_id' => 999999, 'rating_no' => 1,
            'title' => 'ORPHAN', 'description' => 'ORPHAN', 'status' => 1,
        ], $customer->createToken('t')->plainTextToken);

        $this->assertSame(0, self::reviews()->count(),
            'A review was attached to a product that does not exist.');
    }

    /**
     * `user_id` was never set, so every review here claimed to be from user id 1 -- the column
     * default (2024_03_19_104643_add_user_id_column_in_testimonials_table.php).
     *
     * The author MUST NOT be customer id 1, or the assertion cannot tell a derived id from the
     * column default. On a fresh test database the first customer created IS id 1, so the first
     * run of this test passed with the attribution fix reverted. A discard row is created first
     * to push the real author's id past 1, and the id is asserted to differ from 1 so the
     * precondition cannot silently regress.
     */
    public function test_add_review_is_attributed_to_the_caller_not_user_one(): void
    {
        [, $store] = $this->makeTenant();
        $this->customerIn($store, 'r4-discard');
        $customer = $this->customerIn($store, 'r4');
        $this->assertNotSame(1, (int) $customer->id,
            'The author is customer id 1, so this test cannot distinguish a derived attribution '
            . 'from the column default.');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-review', [
            'category_id' => 999999, 'product_id' => $product->id, 'rating_no' => 5,
            'title' => 'MINE', 'description' => 'MINE', 'status' => 1,
        ], $customer->createToken('t')->plainTextToken)->assertJsonPath('status', 1);

        $review = self::reviews()->where('product_id', $product->id)->first();
        $this->assertNotNull($review, 'A shopper can no longer add a review.');
        $this->assertSame((int) $customer->id, (int) $review->user_id,
            'The review was attributed to the column default rather than to its author.');
    }

    /** `status` came from the caller, so the review published itself. */
    public function test_add_review_status_is_not_taken_from_the_caller(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'r5');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-review', [
            'category_id' => 999999, 'product_id' => $product->id, 'rating_no' => 5,
            'title' => 'MINE', 'description' => 'MINE', 'status' => 99,
        ], $customer->createToken('t')->plainTextToken)->assertJsonPath('status', 1);

        $review = self::reviews()->where('product_id', $product->id)->first();
        $this->assertNotNull($review);
        $this->assertSame(1, (int) $review->status,
            'The caller still chose the review status, so the review is self-publishing.');
    }

    /**
     * `category_id` was a fourth caller-supplied identifier, and it carries a real foreign key to
     * `categories`. So a review could be filed under ANOTHER tenant's category, and a nonexistent
     * one produced a 500 rather than a validation error. It is derived from the product now and
     * the posted value is inert -- asserted with a value that does not exist, which would have
     * been a constraint violation before.
     */
    public function test_add_review_ignores_the_posted_category(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'r6');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-review', [
            'category_id' => 999999, 'product_id' => $product->id, 'rating_no' => 5,
            'title' => 'MINE', 'description' => 'MINE', 'status' => 1,
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $review = self::reviews()->where('product_id', $product->id)->first();
        $this->assertNotNull($review, 'A nonexistent posted category still broke the write.');
        $this->assertSame((int) $product->category_id, (int) $review->category_id,
            "The review's category did not come from the product.");
    }

    public function test_an_anonymous_caller_is_refused_on_both_review_endpoints(): void
    {
        [, $store] = $this->makeTenant();
        $product = $this->productIn($store);

        // A premise check on custom.auth, which refuses a missing bearer before either handler
        // runs. Recorded because the denials above assume it.
        $this->callApi($store, 'product-rating', [
            'id' => $product->id, 'user_id' => 1, 'rating_no' => 5,
            'title' => 'A', 'description' => 'A',
        ], null);
        $this->callApi($store, 'add-review', [
            'category_id' => $this->categoryIn($store), 'product_id' => $product->id,
            'rating_no' => 5, 'title' => 'A', 'description' => 'A', 'status' => 1,
        ], null);

        $this->assertSame(0, self::reviews()->count(), 'An anonymous caller wrote a review.');
    }
}
