<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-241 + GAP-245 — A SHOPPER WHOSE BASKET HOLDS A DELETED PRODUCT OR VARIANT GETS HTTP 500,
 * ON THEIR OWN CART SCREEN AND AT CHECKOUT.
 * =============================================================================================
 *
 * ONE DEFECT, TWO REGISTERED ROWS, AND THE REGISTER SAYS THEY ARE ONE DECISION. The crash lives
 * in `Api\ApiController::cart_list()`:
 *
 *     :801   $value->product_data->id            the product row is gone
 *     :810   $ProductVariant->discount_price     the variant row is gone
 *
 * `carts` carries NO foreign key onto `products` or `product_variants`
 * (2024_01_18_103138_create_carts_table.php, measured), so a basket line whose product or variant
 * the merchant has since deleted is an ordinary, reachable state and not an edge case. Neither
 * model uses `SoftDeletes`, so the row is gone for good -- it cannot come back by a restore.
 *
 * `cart_list()` is the basket read that ~15 call sites depend on, `place_order()`, `ordersave()`
 * and `payment_list()` among them, and `cart-list` is ALSO a client-facing route
 * (`routes/api.php:107`). So the same two lines take down the cart screen (GAP-245) and checkout
 * (GAP-241).
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THE DECISION HAD TO COME FIRST, AND WHAT IT WAS.
 *
 * `cart-check` was closed earlier (GAP-235) with a pre-flight in FRONT of its forward to
 * `cart_list()`, deliberately leaving `cart_list()` alone, because there the crash currently
 * REFUSES an order -- and making the pricing pass merely TOLERATE a line whose product is gone
 * would turn that refusal into an order containing a zero-priced phantom line. That is "a fix
 * that removes an accidental safety", so the register recorded the commerce question rather than
 * guessing at it.
 *
 * The answer recorded in GAP-241 is engineering's recommendation and is what this file pins:
 * **DROP the stale line, tell the shopper which product went, and recompute** -- rather than
 * refuse the whole basket, because refusing a basket because one product was delisted loses a
 * sale that is otherwise fine. The alternative (refuse) is equally implementable and was NOT
 * taken.
 *
 * "Drop" means EXCLUDED FROM THE PRICED BASKET, not deleted from the table. The cart row is left
 * where it is: deleting rows inside a read that ~15 call sites reach -- payment callbacks among
 * them -- is a destructive live-data operation, and `Product` is `Cachable`, so a lookup that
 * ever answered null for a live row would destroy a real basket line irreversibly. The shopper
 * keeps `cart-qty remove` (pinned by StorefrontCartNullDereferenceTest) and now gets the
 * `product_id` / `variant_id` they need to call it.
 *
 * ---------------------------------------------------------------------------------------------
 * THE THREE THINGS A NAIVE "SKIP THE LINE" GETS WRONG, each pinned below by its own case:
 *
 *   1. THE ORDER MUST NOT CONTAIN THE PHANTOM. `place_order()` builds its lines from
 *      `$cartlist['product_list']` (`:3525`) and writes them to `orders.product_json` /
 *      `orders.product_id`, so excluding the line from the list is what keeps it out of the
 *      order -- and `final_price` must be the surviving line's alone.
 *
 *   2. `product_list` MUST STAY A JSON ARRAY. It is keyed by the cart collection's index, so a
 *      bare `continue` leaves a gap and `json_encode` emits an OBJECT instead of an array -- a
 *      silent contract break for the shipped Flutter client, which decodes it as a list. The
 *      basket is read `orderBy('id','desc')`, so the case below puts the STALE line at index 0,
 *      where the gap is unmissable.
 *
 *   3. THE READ AND THE WRITE MUST AGREE. GAP-245 warns against a second pre-flight in front of
 *      `cart-list`; the same reasoning retires `cart-check`'s, because a cart screen that drops
 *      the line while the checkout gate refuses the whole basket leaves the shopper looking at a
 *      basket that appears fine and a checkout that says "Product not found." about a line the
 *      screen no longer shows them -- a dead end with no affordance to clear it.
 *
 * ---------------------------------------------------------------------------------------------
 * TOKEN CLASSES. `custom.auth` logs a Sanctum token's owner into the guard matching its TOKENABLE
 * CLASS, so a merchant's and a driver's token both pass the middleware with `auth('customers')`
 * null. A customer-token-only probe of this family therefore proves nothing, and the last case
 * drives all three.
 */
class StorefrontStaleCartLineTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────────── fixtures

    /** A tenant whose merchant can actually take a COD order (plan, stock setting, shipping). */
    private function orderableTenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'gap241-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();

        /* Several handlers read the platform admin through Cache::remember('admin_details', ...),
           so without this they see the pre-fixture User. CLAUDE.md records it. */
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        DB::table('settings')->insert([
            'name' => 'stock_management', 'value' => 'on', 'created_by' => $merchant->id,
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $shippingId];
    }

    private function productIn($store, string $name, bool $variantProduct = false): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap241-' . uniqid(),
            'description' => 'x',
            'product_stock' => 50,
            'track_stock' => 0,
            'variant_product' => $variantProduct ? 1 : 0,
            'stock_status' => 'in_stock',
            'status' => 1,
            'price' => 100.00,
            'sale_price' => 100.00,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function variantFor(Product $product, $store, int $stock = 50): ProductVariant
    {
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'red/large',
            'sku' => 'SKU-' . uniqid(),
            'price' => 100.00,
            'stock' => $stock,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'store_id' => $store->id,
        ]);
    }

    private function shopperOf($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gap241-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function cartLine(Customer $customer, $store, Product $product, int $variantId = 0, int $qty = 1): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => 100.00,
            'store_id' => $store->id,
        ]);
    }

    /**
     * Delete a row underneath a live basket, the way a merchant's own delete does.
     *
     * `Product` and `ProductVariant` are both `Cachable`, and under CACHE_STORE=array a raw write
     * leaves Eloquent reads -- the handler's own -- serving the pre-delete row for the rest of the
     * test. CLAUDE.md records three measured false readings from exactly that.
     */
    private function deleteRow(string $table, int $id, string $model): void
    {
        DB::table($table)->where('id', $id)->delete();
        (new $model())->flushCache();

        $this->assertNull(DB::table($table)->where('id', $id)->first(),
            'premise: the row was not actually deleted, so this case is vacuous');
    }

    private function callApi($store, string $path, array $payload, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    /** `billing_postecode` is misspelled in the handler, and every delivery_* key is required. */
    private function billing(): array
    {
        return [
            'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'billing_address' => '1 St', 'billing_user_telephone' => '555',
            'billing_postecode' => '11111', 'billing_country' => 'X',
            'billing_state' => 'Y', 'billing_city' => 'Z',
            'delivery_address' => '1 St', 'delivery_postcode' => '11111',
            'delivery_country' => 'X', 'delivery_state' => 'Y', 'delivery_city' => 'Z',
        ];
    }

    // ───────────────────────────────────────────────────────── GAP-245: the client-facing read

    public function test_cart_list_does_not_crash_when_the_product_in_the_basket_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);
        $product = $this->productIn($store, 'GAP241-GONE-PLAIN');
        $this->cartLine($shopper, $store, $product);

        $this->deleteRow('products', (int) $product->id, Product::class);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status(),
            'cart_list dereferenced ->id on the product_data relation of a cart line whose product '
            . 'is gone, so the shopper\'s own cart screen answers 500 and they cannot see which '
            . 'line to clear');
    }

    public function test_cart_list_does_not_crash_when_the_variant_in_the_basket_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);
        $product = $this->productIn($store, 'GAP241-GONE-VARIANT', true);
        $variant = $this->variantFor($product, $store);
        $this->cartLine($shopper, $store, $product, (int) $variant->id);

        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status(),
            'cart_list multiplied ->discount_price off a ProductVariant::find() that returned null');
    }

    /**
     * THE RECOMMENDATION ITSELF: the stale line goes, the healthy one is priced, and the shopper
     * is told what went -- with the two identifiers `cart-qty remove` needs to clear it.
     */
    public function test_cart_list_drops_the_stale_line_prices_the_healthy_one_and_names_what_went(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $healthy = $this->productIn($store, 'GAP241-HEALTHY');
        $this->cartLine($shopper, $store, $healthy);

        $gone = $this->productIn($store, 'GAP241-DELISTED');
        $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'the basket was refused outright, which is the alternative the register did NOT choose');

        $list = $response->json('data.product_list');
        $this->assertIsArray($list);
        $this->assertCount(1, $list,
            'the priced basket still holds the delisted line, so a phantom reached the response');
        $this->assertSame((int) $healthy->id, (int) $list[0]['product_id']);

        $this->assertSame(100.00, (float) $response->json('data.total_final_price'),
            'the total did not recompute over the surviving line alone');
        $this->assertSame(1, (int) $response->json('data.cart_total_qty'),
            'the dropped line still contributes quantity');
        $this->assertSame(1, (int) $response->json('data.cart_total_product'),
            'cart_total_product still counts the raw cart rows, so the client would print a count '
            . 'that disagrees with the lines it was given');

        $dropped = $response->json('data.unavailable_products');
        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped, 'the shopper is not told that anything was dropped');
        $this->assertSame((int) $gone->id, (int) $dropped[0]['product_id'],
            'the shopper cannot tell which product went, so they cannot clear the line');
        $this->assertSame(0, (int) $dropped[0]['variant_id']);
        $this->assertNotSame('', (string) $response->json('data.unavailable_message'));
    }

    /**
     * THE NAME IS CARRIED WHEN IT CAN BE. `carts` holds no product name (measured: the migration
     * has customer_id, product_id, variant_id, qty, price, store_id and later size_data /
     * cookie_session_id and nothing else), so a DELETED PRODUCT cannot be named -- but a deleted
     * VARIANT of a live product can be, and must be.
     */
    public function test_a_dropped_variant_line_is_named_because_its_product_row_still_exists(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP241-NAMEABLE', true);
        $variant = $this->variantFor($product, $store);
        $this->cartLine($shopper, $store, $product, (int) $variant->id);
        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $dropped = $response->json('data.unavailable_products');
        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped);
        $this->assertSame('GAP241-NAMEABLE', (string) $dropped[0]['name'],
            'the product row is still there, so there is no reason not to name it');
        $this->assertSame((int) $variant->id, (int) $dropped[0]['variant_id']);
    }

    /**
     * AND THE NAME MUST NOT BECOME A CROSS-TENANT DISCLOSURE — a defect found by reviewing this
     * file's own fix against the checklist, not by reviewing the original handler.
     *
     * The name above is read with `Product::find()`, and `Product` is NOT in
     * `config/tenancy.php`'s `scoped_models`, so that lookup reaches every tenant's catalogue. A
     * `carts` row can hold a foreign `product_id` -- the API writer refuses one (pinned by
     * `ForeignProductShippingQuoteTest`) but vendored module writers do not, which is the whole
     * subject of `ForeignProductCartChainTest`. Such a line used to answer 500 when its variant
     * was deleted, so printing the name would have converted a crash into a disclosure of another
     * merchant's product name: a new hole opened BY the fix, which is the shape CLAUDE.md records
     * twice as "the defect was in the fix itself".
     *
     * THE GATE IS "PROVABLE MISMATCH", NOT EQUALITY, and that is this file's existing policy
     * rather than a new reading: `products.store_id` is NULLABLE
     * (2023_12_26_092822_create_products_table.php:49), and `productOfCartLineStore()` already
     * accepts a NULL and refuses only a provable mismatch (GAP-237). An equality gate would have
     * silently dropped the name for a legacy product with no `store_id`, which is a worse message
     * for no gain.
     */
    public function test_a_dropped_line_does_not_name_another_stores_product(): void
    {
        [, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $shopper = $this->shopperOf($storeA);

        $foreign = $this->productIn($storeB, 'GAP241-STORE-B-SECRET-NAME', true);
        $foreignVariant = $this->variantFor($foreign, $storeB);

        /* The row a vendored module writer can create: store A's cart, store B's product. */
        $this->cartLine($shopper, $storeA, $foreign, (int) $foreignVariant->id);
        $this->deleteRow('product_variants', (int) $foreignVariant->id, ProductVariant::class);

        $response = $this->callApi($storeA, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status());

        $dropped = $response->json('data.unavailable_products');
        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped,
            'premise: the foreign line was not dropped, so this case never reaches the name');
        $this->assertSame('', (string) $dropped[0]['name'],
            "the response named another store's product, so the 500 was traded for a disclosure");
        $this->assertStringNotContainsString('GAP241-STORE-B-SECRET-NAME', $response->getContent(),
            "store B's product name reached a store-A shopper somewhere in the payload");
    }

    /** CONTROL for the gate above: this store's OWN product is still named. */
    public function test_the_mismatch_gate_still_names_this_stores_own_product(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP241-OWN-NAME', true);
        $variant = $this->variantFor($product, $store);
        $this->cartLine($shopper, $store, $product, (int) $variant->id);
        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertSame('GAP241-OWN-NAME',
            (string) $response->json('data.unavailable_products.0.name'),
            'the mismatch gate also refuses this store\'s own product, so the shopper is never '
            . 'told what went and the gate has replaced the feature');
    }

    /**
     * `product_list` MUST STILL SERIALISE AS A JSON ARRAY.
     *
     * The basket is read `orderBy('id','desc')`, so the line inserted LAST sits at index 0. With
     * the stale line there, a bare `continue` leaves `[1 => …]` and `json_encode` emits
     * `{"1": …}` -- an object. The shipped Flutter client decodes this key as a list, so that is a
     * contract break that no status-code assertion would see.
     */
    public function test_product_list_is_still_a_json_array_when_the_dropped_line_was_first(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $healthy = $this->productIn($store, 'GAP241-ORDER-HEALTHY');
        $this->cartLine($shopper, $store, $healthy);

        $gone = $this->productIn($store, 'GAP241-ORDER-GONE');
        $goneLine = $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $this->assertGreaterThan(
            (int) DB::table('carts')->where('product_id', $healthy->id)->value('id'),
            (int) $goneLine->id,
            'premise: the stale line is not the newest row, so it is not at index 0 and this case '
            . 'cannot see the key gap it exists to see'
        );

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $decoded = json_decode($response->getContent(), true);
        $list = $decoded['data']['product_list'] ?? null;

        $this->assertIsArray($list);
        $this->assertTrue(array_is_list($list),
            'product_list came back with a gap in its keys, so it serialises as a JSON object '
            . 'rather than an array: ' . json_encode(array_keys($list)));
    }

    /** CONTROL: a basket with nothing stale in it is priced exactly as before. */
    public function test_a_healthy_basket_is_unchanged_and_reports_nothing_dropped(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP241-CONTROL');
        $this->cartLine($shopper, $store, $product, 0, 2);

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'));
        $this->assertCount(1, $response->json('data.product_list'));
        $this->assertSame(200.00, (float) $response->json('data.total_final_price'),
            'the control basket no longer prices the way it did, so the change is not contained '
            . 'to stale lines');
        $this->assertSame([], $response->json('data.unavailable_products'));
        $this->assertSame('', (string) $response->json('data.unavailable_message'));
    }

    // ───────────────────────────────────────────────────────────── GAP-241: checkout, the money

    /**
     * THE HEADLINE. A shopper with a stale line could not complete checkout at all, and the
     * reason the earlier slice refused to touch `cart_list()` was that tolerating the line would
     * write a zero-priced phantom into the order. So this case asserts BOTH halves: the order is
     * written, and it contains exactly one line at the surviving line's price.
     */
    public function test_place_order_completes_and_the_order_excludes_the_stale_line(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $shopper = $this->shopperOf($store);

        $healthy = $this->productIn($store, 'GAP241-ORDER-OK');
        $this->cartLine($shopper, $store, $healthy);

        $gone = $this->productIn($store, 'GAP241-ORDER-PHANTOM');
        $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->callApi($store, 'place-order', [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ], $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status(),
            'place_order still answers 500 for a basket holding a delisted product, so the '
            . 'shopper cannot check out at all');

        $order = DB::table('orders')->where('customer_id', $shopper->id)->first();
        $this->assertNotNull($order,
            'no order was written, so checkout is still blocked by the stale line');

        $this->assertSame(100.00, (float) $order->final_price,
            'the amount owed is not the surviving line\'s alone');
        $this->assertSame(100.00, (float) $order->product_price);

        $lines = json_decode($order->product_json, true);
        $this->assertIsArray($lines);
        $this->assertCount(1, $lines,
            'the order carries a zero-priced phantom line for the deleted product, which is the '
            . 'exact outcome the earlier slice refused to risk');
        $this->assertSame((int) $healthy->id, (int) $lines[0]['product_id']);

        $ids = json_decode((string) $order->product_id, true);
        $this->assertSame([(int) $healthy->id], array_map('intval', (array) $ids),
            'orders.product_id still names the deleted product');
    }

    /**
     * AND THE REFUSAL STAYS WHERE IT BELONGS. A basket with nothing orderable left in it is not
     * an order -- "drop the line" must not become "write an empty order".
     */
    public function test_place_order_still_refuses_a_basket_whose_every_line_is_stale(): void
    {
        [, $store, $shippingId] = $this->orderableTenant();
        $shopper = $this->shopperOf($store);

        $gone = $this->productIn($store, 'GAP241-ALL-GONE');
        $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->callApi($store, 'place-order', [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
            'delivery_id' => $shippingId,
            'method_id' => $shippingId,
        ], $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status());
        $this->assertSame(0, (int) $response->json('status'),
            'an order was reported for a basket with nothing orderable in it');
        $this->assertNull(DB::table('orders')->where('customer_id', $shopper->id)->first(),
            'an empty order was written');
    }

    // ──────────────────────────────────────────────── the read and the write must agree

    /**
     * `cart-check` IS THE CHECKOUT GATE THE CLIENT CALLS, and it must reach the same verdict as
     * the cart screen. GAP-235 closed its crash with a pre-flight that refuses the WHOLE basket;
     * once `cart_list()` drops the line, that pre-flight is the thing that disagrees -- the
     * shopper sees a basket that looks fine and a gate that says "Product not found." about a
     * line the screen no longer shows them.
     */
    public function test_cart_check_accepts_a_basket_whose_healthy_line_survives_a_stale_one(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $healthy = $this->productIn($store, 'GAP241-CHECK-OK');
        $this->cartLine($shopper, $store, $healthy);

        $gone = $this->productIn($store, 'GAP241-CHECK-GONE');
        $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->callApi($store, 'cart-check', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertNotSame(500, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'cart-check refused the whole basket while cart-list priced it, so the read and the '
            . 'write disagree about what a stale basket is');
        $this->assertSame('Cart is ready.', data_get($response->json(), 'data.message'));
    }

    // ─────────────────────────────────────────────────────────────────── the guard-class premise

    /**
     * A MERCHANT'S AND A DRIVER'S TOKEN MUST STILL BE REFUSED, AND MUST NOT CRASH.
     *
     * `custom.auth` logs a token's owner into the guard matching its TOKENABLE CLASS, so both of
     * these pass the middleware with `auth('customers')` null and `cart_list()` refuses them
     * through `resolveStoreCustomer()`. A customer-token-only probe of this family proves nothing,
     * and a new per-line lookup is exactly the kind of change that could reintroduce a
     * request-derived branch -- so every class is driven, with a stale basket in place.
     *
     * ONE PRINCIPAL PER TEST METHOD, AND THAT IS NOT STYLE. MEASURED while writing this file: a
     * single method that drove the customer first and the merchant second reported the merchant
     * receiving `status 1` and the shopper's basket. The cause is the TEST CLIENT, not the
     * handler -- `custom.auth` logs its principal into a SESSION guard, and Laravel's test client
     * keeps the session across requests inside one test method, so the `customers` session the
     * customer's request established was still there when the merchant's token arrived and
     * `auth('customers')->user()` answered with the shopper. Driven alone, from a fresh
     * application, the merchant and the driver each get HTTP 401 `{"status":0}`.
     *
     * So a loop over three tokens in one method is a FALSE READING on this family in both
     * directions: it can accuse a contained handler, and -- with the principals in the other
     * order -- it could clear an uncontained one. Each principal therefore gets its own method,
     * which is the only way each gets a fresh session.
     */
    public function test_a_merchant_token_gets_no_basket_and_no_crash_from_cart_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $shopper = $this->staleBasketFor($store, 'GAP241-GUARD-MERCHANT');

        $this->assertNonCustomerIsRefused($store, $shopper,
            $merchant->createToken('t')->plainTextToken, 'merchant');
    }

    public function test_a_driver_token_gets_no_basket_and_no_crash_from_cart_list(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $shopper = $this->staleBasketFor($store, 'GAP241-GUARD-DRIVER');

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver',
            'email' => 'gap241-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'),
            'created_by' => $merchant->id,
            'store_id' => $store->id,
        ]);

        $this->assertNonCustomerIsRefused($store, $shopper,
            $driver->createToken('t')->plainTextToken, 'driver');
    }

    /** CONTROL for the two above: the shopper's OWN token does get the basket, so they prove something. */
    public function test_the_shopper_themselves_still_gets_their_basket_from_cart_list(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->staleBasketFor($store, 'GAP241-GUARD-CONTROL');

        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id],
            $shopper->createToken('t')->plainTextToken);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'the shopper was refused their own basket, so the two refusal cases beside this one '
            . 'could be passing because the route is broken for everybody');
        $this->assertCount(1, $response->json('data.unavailable_products'));
    }

    /** A shopper of $store whose only basket line points at a product that has been deleted. */
    private function staleBasketFor($store, string $name): Customer
    {
        $shopper = $this->shopperOf($store);
        $gone = $this->productIn($store, $name);
        $this->cartLine($shopper, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        return $shopper;
    }

    private function assertNonCustomerIsRefused($store, Customer $shopper, string $bearer, string $principal): void
    {
        $response = $this->callApi($store, 'cart-list', ['customer_id' => $shopper->id], $bearer);

        $this->assertNotSame(500, $response->status(),
            "cart-list answered 500 for a {$principal} token on a basket holding a deleted product");
        $this->assertSame(401, $response->status(),
            "a {$principal} token was not refused by cart-list");
        $this->assertSame(0, (int) $response->json('status'),
            "a {$principal} token was handed a shopper's basket -- the guard-class trap");
        $this->assertNull($response->json('data.product_list'),
            "a {$principal} token received a product_list");
        $this->assertNull($response->json('data.unavailable_products'),
            "a {$principal} token was told which of a shopper's products had been delisted, which "
            . 'is a new disclosure introduced by this very fix');
    }
}
