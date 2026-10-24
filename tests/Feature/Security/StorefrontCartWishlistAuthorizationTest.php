<?php

namespace Tests\Feature\Security;

use App\Models\ActivityLog;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\User;
use App\Models\Wishlist;
use Illuminate\Http\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P01-SEC-11 — the storefront CART and WISHLIST handlers, plus update_user_image: cart_list,
 * addtocart, cart_qty, cart_check, wishlist, delivery_charge, variant_list, update_user_image.
 *
 * Six carry the guard-class trap and two carry the bare liveness check, but the thing that makes
 * this group worth one commit is that `cart_list` is called internally by ordersave(),
 * place_order(), cart_check() and payment_list() — so the cart read four other handlers depend on
 * was taking its subject from the request body.
 *
 * The sharper edges, each asserted below:
 *
 *   cart_qty          its 'remove' branch re-queried the cart WITHOUT any store_id filter and
 *                     deleted that row, so a wrong-class token removed a named shopper's cart row
 *                     in ANY store.
 *   wishlist          wrote an ActivityLog row attributed to the victim alongside the wishlist
 *                     change, so the audit trail recorded the victim doing it.
 *   update_user_image three effects, not one: the victim's existing avatar was DELETED from disk,
 *                     the replacement was billed against the VICTIM's store owner's storage quota
 *                     (the store was re-derived from the target's store_id, not the slug), and
 *                     customers.profile_image was rewritten.
 *   delivery_charge   the request-controlled branch set `$cart = []`, so this instance of the
 *                     pattern leaked nothing. Corrected for consistency and recorded here as
 *                     harmless rather than left for the next reader to re-derive.
 */
class StorefrontCartWishlistAuthorizationTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** @var array<int, string> directory listing of uploads/ before this test ran */
    private array $uploadsBefore = [];

    /**
     * update_user_image's authorized journey posts a real fake upload, and upload_theme_image()
     * writes it into the repository's `uploads/` directory -- which, unlike `public/uploads/`, is
     * NOT in .gitignore. The first run of this file left 27 PNGs behind and a `git add -A` swept
     * them into a commit.
     *
     * Snapshot before, delete the difference after. Done here rather than at the three call sites
     * so a fourth cannot be added without the cleanup.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $dir = base_path('uploads');
        $this->uploadsBefore = is_dir($dir) ? (scandir($dir) ?: []) : [];
    }

    protected function tearDown(): void
    {
        $dir = base_path('uploads');
        if (is_dir($dir)) {
            foreach (array_diff(scandir($dir) ?: [], $this->uploadsBefore) as $name) {
                $path = $dir . DIRECTORY_SEPARATOR . $name;
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }

        parent::tearDown();
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => $label,
            'email' => 'cart-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
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

    private function productIn($store, string $name = 'cart-product'): Product
    {
        return Product::forceCreate([
            'name' => $name . '-' . uniqid(),
            'slug' => $name . '-' . uniqid(),
            'product_stock' => 50,
            'track_stock' => 0,
            'variant_product' => 0,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function cartRowFor($store, Customer $customer, Product $product, int $qty = 3): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'qty' => $qty,
            'price' => 10 * $qty,
            'store_id' => $store->id,
        ]);
    }

    private function callApi($store, string $path, array $payload, ?string $bearer)
    {
        $headers = $bearer === null ? [] : ['Authorization' => 'Bearer ' . $bearer];

        return $this->withHeaders($headers)->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /**
     * Assertions must bypass the tenant scope, or they measure the scope instead of the handler.
     *
     * `Cart` and `Customer` ARE in config/tenancy.php's scoped_models. A test that asserts
     * "the victim's row is gone" with a plain `Cart::find()` passes whenever the resolved tenant
     * happens to differ from the row's store -- which is exactly the situation a cross-store test
     * constructs. The first run of this file reported the store-B cart row deleted when the raw
     * table still held it; the fix had worked and the assertion was blind.
     *
     * Every count and lookup below therefore goes through withoutGlobalScopes().
     */
    private function cartRows(Customer $customer)
    {
        return Cart::withoutGlobalScopes()->where('customer_id', $customer->id);
    }

    // ===================================================================================
    // cart_list — the read four other handlers depend on
    // ===================================================================================

    public function test_a_merchant_token_cannot_read_a_shoppers_cart(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'l1');
        $product = $this->productIn($store, 'VICTIMCART');
        $this->cartRowFor($store, $victim, $product);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $victim->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIMCART', $response->getContent(),
            "A merchant's token read a shopper's cart.");
        $this->assertSame(0, $response->json('status'));
    }

    public function test_a_driver_token_cannot_read_a_shoppers_cart(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'l2');
        $driver = $this->driverFor($merchant, $store);
        $product = $this->productIn($store, 'VICTIMCART');
        $this->cartRowFor($store, $victim, $product);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $victim->id],
            $driver->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIMCART', $response->getContent(),
            "A driver's token read a shopper's cart.");
    }

    public function test_a_shopper_still_reads_their_own_cart(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'l3');
        $product = $this->productIn($store, 'MYOWNCART');
        $this->cartRowFor($store, $customer, $product);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $customer->id],
            $customer->createToken('t')->plainTextToken);

        $response->assertStatus(200);
        $this->assertStringContainsString('MYOWNCART', $response->getContent(),
            'A shopper can no longer read their own cart.');
    }

    /**
     * cart_check forwards to cart_list, so it was a second door onto the same read.
     *
     * DEFENCE IN DEPTH, and labelled as such: reverting cart_check's own check alone does NOT
     * fail this test, because the fixed cart_list it calls refuses on its own. Both have to be
     * reverted together for it to fail. The check stays because cart_check does its own
     * Customer::find() and its own stock reads, and a later change to cart_list must not silently
     * reopen this door.
     */
    public function test_a_merchant_token_cannot_read_a_shoppers_cart_through_cart_check(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'k1');
        $product = $this->productIn($store, 'VICTIMCART');
        $this->cartRowFor($store, $victim, $product);

        $response = $this->callApi($store, 'cart-check', ['customer_id' => $victim->id],
            $merchant->createToken('t')->plainTextToken);

        $this->assertStringNotContainsString('VICTIMCART', $response->getContent(),
            "A merchant's token read a shopper's cart through cart-check.");
        $this->assertSame(0, $response->json('status'));
    }

    // ===================================================================================
    // addtocart — the trap, writing
    // ===================================================================================

    public function test_a_merchant_token_cannot_add_to_a_shoppers_cart(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a1');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-cart',
            ['customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->cartRows($victim)->count(),
            "A merchant's token added a product to a shopper's cart.");
    }

    public function test_a_driver_token_cannot_add_to_a_shoppers_cart(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'a2');
        $driver = $this->driverFor($merchant, $store);
        $product = $this->productIn($store);

        $this->callApi($store, 'add-cart',
            ['customer_id' => $victim->id, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
            $driver->createToken('t')->plainTextToken);

        $this->assertSame(0, $this->cartRows($victim)->count(),
            "A driver's token added a product to a shopper's cart.");
    }

    public function test_a_shopper_can_still_add_to_their_own_cart(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a3');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-cart',
            ['product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
            $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(1, $this->cartRows($customer)->count(),
            'A shopper can no longer add to their own cart.');
    }

    public function test_adding_to_cart_ignores_a_posted_customer_id(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'a4');
        $other = $this->customerIn($store, 'a4-other');
        $product = $this->productIn($store);

        $this->callApi($store, 'add-cart',
            ['customer_id' => $other->id, 'product_id' => $product->id, 'variant_id' => 0, 'qty' => 2],
            $customer->createToken('t')->plainTextToken)
            ->assertJsonPath('status', 1);

        $this->assertSame(1, $this->cartRows($customer)->count(),
            "The product did not land in the caller's own cart.");
        $this->assertSame(0, $this->cartRows($other)->count(),
            'The posted customer_id still steered the write.');
    }

    // ===================================================================================
    // cart_qty — alter and delete, including the unscoped 'remove' re-query
    // ===================================================================================

    public function test_a_merchant_token_cannot_change_a_shoppers_cart_quantity(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'q1');
        $product = $this->productIn($store);
        $row = $this->cartRowFor($store, $victim, $product, 3);

        $this->callApi($store, 'cart-qty', [
            'customer_id' => $victim->id, 'product_id' => $product->id,
            'variant_id' => 0, 'quantity_type' => 'increase',
        ], $merchant->createToken('t')->plainTextToken);

        $this->assertSame(3, (int) $row->refresh()->qty,
            "A merchant's token changed a shopper's cart quantity.");
    }

    public function test_one_shopper_cannot_change_anothers_cart_quantity(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'q2-victim');
        $attacker = $this->customerIn($store, 'q2-attacker');
        $product = $this->productIn($store);
        $row = $this->cartRowFor($store, $victim, $product, 3);

        $this->callApi($store, 'cart-qty', [
            'customer_id' => $victim->id, 'product_id' => $product->id,
            'variant_id' => 0, 'quantity_type' => 'increase',
        ], $attacker->createToken('t')->plainTextToken);

        $this->assertSame(3, (int) $row->refresh()->qty,
            "One shopper changed another's cart quantity.");
    }

    /**
     * The 'remove' branch specifically, and it takes a deliberate fixture to reach.
     *
     * The else arm re-queried the cart on product_id + customer_id + variant_id with NO store_id
     * filter and deleted `->first()`. To get there at all, the earlier store-scoped lookup must
     * find a row -- otherwise the handler answers 'Your cart is empty.' and the branch never runs.
     * So the victim needs a row in the CALLED store and another row elsewhere, and the elsewhere
     * row must have the lower id for `first()` to select it. Built in that order below.
     *
     * A naive version of this test -- one row, in the other store only -- passes against the
     * unfixed handler, because it returns early.
     *
     * Measured with a DeliveryBoy token, which is the principal that reaches it: StoreScope
     * resolves to MODE_UNRESOLVED for a driver and `null_tenant => 'open'` adds no predicate, so
     * the re-query really is platform-wide. For a MERCHANT token the scope resolves to their own
     * stores and narrows it, which is why the merchant case below asserts only that nothing of
     * the victim's was touched.
     *
     *   revert the principal check alone -> the driver deletes the store-A row
     *   revert the principal check AND the branch -> the driver deletes the store-B row
     *
     * so the two are separate controls, and both assertions below are load-bearing.
     */
    public function test_a_merchant_token_cannot_delete_a_shoppers_cart_row_in_another_store(): void
    {
        [$merchant, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'q3');
        $product = $this->productIn($storeB);

        // Lower id first, so the unscoped `->first()` selects the store-B row.
        $rowInB = $this->cartRowFor($storeB, $victim, $product, 1);
        $rowInA = $this->cartRowFor($storeA, $victim, $product, 1);
        $this->assertLessThan((int) $rowInA->id, (int) $rowInB->id, 'fixture ordering is wrong');

        $driver = $this->driverFor($merchant, $storeA);

        $this->callApi($storeA, 'cart-qty', [
            'customer_id' => $victim->id, 'product_id' => $product->id,
            'variant_id' => 0, 'quantity_type' => 'remove',
        ], $driver->createToken('t')->plainTextToken);

        $this->assertNotNull(Cart::withoutGlobalScopes()->find($rowInB->id),
            "Store A's endpoint deleted a cart row belonging to store B -- the remove branch "
            . 're-queried without any store_id filter.');
        $this->assertNotNull(Cart::withoutGlobalScopes()->find($rowInA->id),
            "A driver's token deleted a shopper's cart row.");
    }

    public function test_a_shopper_can_still_remove_from_their_own_cart(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'q4');
        $product = $this->productIn($store);
        $row = $this->cartRowFor($store, $customer, $product, 1);

        $this->callApi($store, 'cart-qty', [
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'quantity_type' => 'remove',
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertNull(Cart::withoutGlobalScopes()->find($row->id),
            'A shopper can no longer remove a product from their own cart.');
    }

    public function test_a_shopper_can_still_increase_their_own_cart_quantity(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'q5');
        $product = $this->productIn($store);
        $row = $this->cartRowFor($store, $customer, $product, 1);

        $this->callApi($store, 'cart-qty', [
            'customer_id' => $customer->id, 'product_id' => $product->id,
            'variant_id' => 0, 'quantity_type' => 'increase',
        ], $customer->createToken('t')->plainTextToken)
            ->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(2, (int) $row->refresh()->qty,
            'A shopper can no longer change their own cart quantity.');
    }

    // ===================================================================================
    // wishlist — the write, and the forged ActivityLog attribution
    // ===================================================================================

    public function test_a_merchant_token_cannot_add_to_a_shoppers_wishlist(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'w1');
        $product = $this->productIn($store);

        $this->callApi($store, 'wishlist',
            ['customer_id' => $victim->id, 'product_id' => $product->id, 'wishlist_type' => 'add'],
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, Wishlist::withoutGlobalScopes()->where('customer_id', $victim->id)->count(),
            "A merchant's token added a product to a shopper's wishlist.");
        $this->assertSame(0, ActivityLog::withoutGlobalScopes()->where('customer_id', $victim->id)->count(),
            "An activity-log row was written attributing the action to the VICTIM.");
    }

    public function test_one_shopper_cannot_clear_anothers_wishlist(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'w2-victim');
        $attacker = $this->customerIn($store, 'w2-attacker');
        $product = $this->productIn($store);
        Wishlist::forceCreate([
            'customer_id' => $victim->id, 'product_id' => $product->id,
            'variant_id' => 0, 'store_id' => $store->id,
        ]);

        $this->callApi($store, 'wishlist',
            ['customer_id' => $victim->id, 'product_id' => $product->id, 'wishlist_type' => 'remove'],
            $attacker->createToken('t')->plainTextToken);

        $this->assertSame(1, Wishlist::withoutGlobalScopes()->where('customer_id', $victim->id)->count(),
            "One shopper removed a product from another's wishlist.");
    }

    public function test_a_shopper_can_still_manage_their_own_wishlist(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'w3');
        $product = $this->productIn($store);
        $token = $customer->createToken('t')->plainTextToken;

        $this->callApi($store, 'wishlist',
            ['customer_id' => $customer->id, 'product_id' => $product->id, 'wishlist_type' => 'add'],
            $token)->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(1, Wishlist::withoutGlobalScopes()->where('customer_id', $customer->id)->count(),
            'A shopper can no longer add to their own wishlist.');
        $this->assertSame(1, ActivityLog::withoutGlobalScopes()->where('customer_id', $customer->id)->count(),
            'The activity log stopped recording the shopper\'s own action.');

        $this->callApi($store, 'wishlist',
            ['customer_id' => $customer->id, 'product_id' => $product->id, 'wishlist_type' => 'remove'],
            $token)->assertStatus(200)->assertJsonPath('status', 1);

        $this->assertSame(0, Wishlist::withoutGlobalScopes()->where('customer_id', $customer->id)->count(),
            'A shopper can no longer remove from their own wishlist.');
    }

    // ===================================================================================
    // update_user_image — avatar overwrite, file deletion, and a quota billed to the victim
    // ===================================================================================

    /**
     * These post a REAL fake upload. With `'image' => 'not-a-file'` the handler dies inside
     * upload_theme_image() (helper.php calls ->getSize() on it) and returns 500 before writing
     * anything, so the avatar survives and the test passes against the UNFIXED handler. That is a
     * crash being measured, not a control.
     */
    public function test_a_merchant_token_cannot_replace_a_shoppers_avatar(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'i1');
        $victim->profile_image = 'uploads/victim-original-avatar.png';
        $victim->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $merchant->createToken('t')->plainTextToken])
            ->post('/api/' . $store->slug . '/update-user-image', [
                'customer_id' => $victim->id,
                'image' => UploadedFile::fake()->image('attacker-avatar.png'),
            ]);

        $this->assertSame('uploads/victim-original-avatar.png',
            Customer::withoutGlobalScopes()->find($victim->id)->getRawOriginal('profile_image'),
            "A merchant's token replaced a shopper's avatar.");
    }

    public function test_one_shopper_cannot_replace_anothers_avatar(): void
    {
        [, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'i2-victim');
        $attacker = $this->customerIn($store, 'i2-attacker');
        $victim->profile_image = 'uploads/victim-original-avatar.png';
        $victim->save();

        $this->withHeaders(['Authorization' => 'Bearer ' . $attacker->createToken('t')->plainTextToken])
            ->post('/api/' . $store->slug . '/update-user-image', [
                'customer_id' => $victim->id,
                'image' => UploadedFile::fake()->image('attacker-avatar.png'),
            ]);

        $this->assertSame('uploads/victim-original-avatar.png',
            Customer::withoutGlobalScopes()->find($victim->id)->getRawOriginal('profile_image'),
            "One shopper replaced another's avatar.");
    }

    public function test_a_shopper_can_still_replace_their_own_avatar(): void
    {
        [, $store] = $this->makeTenant();
        $customer = $this->customerIn($store, 'i3');
        $customer->profile_image = 'uploads/my-old-avatar.png';
        $customer->save();

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken])
            ->post('/api/' . $store->slug . '/update-user-image', [
                'customer_id' => $customer->id,
                'image' => UploadedFile::fake()->image('my-new-avatar.png'),
            ]);

        $response->assertStatus(200)->assertJsonPath('status', 1);
        $this->assertNotSame('uploads/my-old-avatar.png',
            Customer::withoutGlobalScopes()->find($customer->id)->getRawOriginal('profile_image'),
            'A shopper can no longer replace their own avatar.');
    }

    // ===================================================================================
    // variant_list and delivery_charge
    // ===================================================================================

    /**
     * NOT A BITE. Reverting variant_list's fix does not fail this: the handler goes on to parse
     * `variant` and finds no matching ProductVariant, so it answers status 0 either way. Its
     * request-controlled branch used the id only to gate, so there was nothing to leak -- like
     * delivery_charge below, the fix is consistency with its siblings rather than a closed hole.
     * The assertion is kept to pin the refusal.
     */
    public function test_a_merchant_token_is_refused_on_variant_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'v1');
        $product = $this->productIn($store);

        $response = $this->callApi($store, 'variant-list', [
            'customer_id' => $victim->id, 'product_id' => $product->id,
            'variant' => 'Color:Red', 'quantity' => 1,
        ], $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'),
            'variant-list served a merchant token through its request-controlled branch.');
    }

    /**
     * NOT A BITE, and recorded as one of the handlers whose fix has no measurable security
     * effect. delivery_charge's request-controlled branch set `$cart = []`, so it computed a
     * charge over an empty cart and leaked nothing. This assertion passes against the unfixed
     * handler too -- reverting the fix changes nothing here. The handler was corrected for
     * consistency with its eight siblings, so the next reader does not have to re-derive that
     * this one instance of the pattern happened to be harmless; this test just pins the refusal
     * so a future change cannot quietly start serving a wrong-class principal real cart data.
     */
    public function test_a_merchant_token_is_refused_on_delivery_charge(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $victim = $this->customerIn($store, 'v2');

        $response = $this->callApi($store, 'delivery-charge',
            ['customer_id' => $victim->id, 'method_id' => 1],
            $merchant->createToken('t')->plainTextToken);

        $this->assertSame(0, $response->json('status'),
            'delivery-charge served a merchant token through its request-controlled branch.');
    }

    // ===================================================================================
    // CROSS-STORE
    // ===================================================================================

    public function test_a_shopper_of_another_store_is_refused_across_the_group(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $victim = $this->customerIn($storeA, 'x1-victim');
        $attacker = $this->customerIn($storeB, 'x1-attacker');
        $product = $this->productIn($storeA, 'VICTIMCART');
        $this->cartRowFor($storeA, $victim, $product);
        $token = $attacker->createToken('t')->plainTextToken;

        foreach (['cart-list', 'cart-check'] as $path) {
            $response = $this->callApi($storeA, $path, ['customer_id' => $victim->id], $token);
            $this->assertStringNotContainsString('VICTIMCART', $response->getContent(),
                "A shopper of store B read store A's cart through $path.");
            $this->assertSame(0, $response->json('status'), "$path served a cross-store caller.");
        }

        $this->callApi($storeA, 'wishlist',
            ['customer_id' => $victim->id, 'product_id' => $product->id, 'wishlist_type' => 'add'], $token);
        $this->assertSame(0, Wishlist::withoutGlobalScopes()->where('customer_id', $victim->id)->count(),
            "A shopper of store B wrote to store A's wishlist.");
    }
}
