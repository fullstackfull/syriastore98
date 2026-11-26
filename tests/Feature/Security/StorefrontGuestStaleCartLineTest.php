<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * THE GUEST TWIN OF GAP-241 / GAP-245 — `Cart::cart_list_cookie()` 500s ON A STALE BASKET LINE
 * EXACTLY AS `ApiController::cart_list()` DID.
 * =============================================================================================
 *
 * FOUND, NOT INHERITED. GAP-241 and GAP-245 both name the SIGNED-IN basket read; neither mentions
 * the cookie basket. This was raised while closing them, from the code, and the row it needs does
 * not exist yet. Four unguarded dereferences in the NON-MODULE arm — the one that runs when no
 * WorkDo module is loaded, which is every test run and any install without them:
 *
 *     Cart.php:541   $cart_product_data->id          deleted PRODUCT   (plain line)
 *     Cart.php:548   $ProductVariant->variant        deleted VARIANT
 *     Cart.php:551   $ProductVariant->discount_price deleted VARIANT
 *     Cart.php:564   $cart_product_data->product_type deleted PRODUCT  (BOTH branches)
 *
 * REACHABLE BY THE SAME MECHANISM, measured: `scopeForGuestCookie` keys on
 * `carts.cookie_session_id`, so a guest's basket is REAL `carts` ROWS, not cookie-encoded data.
 * `carts` carries no foreign key onto `products` or `product_variants` and neither model uses
 * `SoftDeletes`, so a merchant's ordinary delete strands a guest's line precisely as it strands a
 * signed-in shopper's.
 *
 * ENTRY POINT DRIVEN HERE: `POST {storeSlug}/cart-list-sidebar`
 * (`routes/customer.php:196`, one declaration, checked for duplicates; inside the
 * `ActiveTheme`/`themelanguage` group at :51 and NOT inside the `auth:customers` group at :239,
 * so a guest reaches it). `CartController::cart_list_sidebar:92-93` takes the
 * `auth('customers')->guest()` arm straight into `Cart::cart_list_cookie()`. The same method is
 * also reached by `apply_coupon`, `payment_list`, `PaymentService` and the gateway return
 * handlers; this is the shopper-facing one.
 *
 * ---------------------------------------------------------------------------------------------
 * THE DECISION IS THE SAME ONE, DELIBERATELY. Drop the line, name it, recompute — identical to
 * the signed-in arm, because a shopper must not get a different answer to the same problem
 * depending on whether they are signed in, and a divergence between the two arms is the hazard
 * GAP-245 names. Nothing measured here argued for a third behaviour.
 *
 * WHAT THE TENANT AND IDENTITY PREDICATE IS ON THIS ARM, stated because it is NOT `customer_id`
 * and the global scope is NOT doing it. `Cart` IS in `config/tenancy.php`'s `scoped_models`, but a
 * guest resolves NO tenant and `null_tenant => 'open'` means the scope then adds NO predicate at
 * all — so the explicit `Cart::forGuestCookie($cookie)->where('store_id', $store_id)` at
 * `Cart.php:259` is the whole of it, and it is pre-existing. Two cases below drive that boundary
 * rather than assuming my change left it alone.
 */
class StorefrontGuestStaleCartLineTest extends SecurityTestCase
{
    use RefreshDatabase;

    // ──────────────────────────────────────────────────────────────────────────────── fixtures

    private function tenant(): array
    {
        [$merchant, $store] = $this->makeTenant();

        foreach (['stock_management' => 'on', 'out_of_stock_threshold' => '0', 'notification' => '[]'] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        /* Several handlers read the platform admin through Cache::remember('admin_details', ...). */
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function productIn($store, string $name, bool $variantProduct = false): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'guest241-' . uniqid(),
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

    private function variantFor(Product $product, $store): ProductVariant
    {
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'red/large',
            'sku' => 'SKU-' . uniqid(),
            'price' => 100.00,
            'stock' => 50,
            'stock_status' => 'in_stock',
            'low_stock_threshold' => 0,
            'store_id' => $store->id,
        ]);
    }

    /** A guest basket line. `customer_id = 0` and `cookie_session_id` is the identity. */
    private function guestLine(string $sid, $store, Product $product, int $variantId = 0, int $qty = 1): Cart
    {
        return Cart::forceCreate([
            'customer_id' => 0,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => 100.00,
            'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);
    }

    /**
     * Delete a row underneath a live basket, the way a merchant's own delete does.
     *
     * `Product` and `ProductVariant` are both `Cachable`, and under CACHE_STORE=array a raw write
     * leaves Eloquent reads — the handler's own — serving the pre-delete row for the rest of the
     * test. CLAUDE.md records three measured false readings from exactly that.
     */
    private function deleteRow(string $table, int $id, string $model): void
    {
        DB::table($table)->where('id', $id)->delete();
        (new $model())->flushCache();

        $this->assertNull(DB::table($table)->where('id', $id)->first(),
            'premise: the row was not actually deleted, so this case is vacuous');
    }

    /**
     * THE SHOPPER-FACING ROUTE. Proves the defect is reachable and user-visible, and carries the
     * rendered cart views.
     *
     * `withCookie` and NOT `withUnencryptedCookie` -- MEASURED, because the choice is invisible
     * and silently empties the basket: through `withCookie` this route answered
     * `cart_total_product 1` / `sub_total 200.00`, and through `withUnencryptedCookie` it answered
     * `0` / `0.00`. Every guest assertion in this file would have been vacuous on the wrong one.
     */
    private function sidebar($store, string $sid)
    {
        return $this->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/cart-list-sidebar', ['slug' => $store->slug]);
    }

    /**
     * THE STRUCTURED PAYLOAD, FROM THE METHOD UNDER TEST ITSELF.
     *
     * `CartController::cart_list_sidebar` does NOT pass the basket through: it builds its own
     * `$return` of `status`, `sub_total`, `cart_total_product` and three blocks of rendered HTML,
     * so `product_list` and the new keys never appear in that response. Measured -- the first
     * draft of this file asserted against `data.product_list` of the route and every case was
     * reading a key that does not exist, which is exactly the "test that cannot fail" shape.
     *
     * So the route above proves REACHABILITY and the rendering, and this reads the return value of
     * `Cart::cart_list_cookie()` directly, which is what GAP-241's remedy names. `Cookie::get()`
     * resolves from the current request, so the cookie is set there -- verified by measurement, not
     * assumed: through this helper a healthy one-line basket answers `product_list` of 1 and
     * `total_final_price` 200.00.
     */
    private function basketFor($store, string $sid): array
    {
        request()->cookies->set('cart', $sid);

        $payload = json_decode(json_encode(Cart::cart_list_cookie(['slug' => $store->slug], $store->id)), true);

        return is_array($payload) ? $payload : [];
    }

    // ───────────────────────────────────────────────────────────────── the crash, both flavours

    public function test_the_guest_cart_does_not_crash_when_the_product_is_deleted(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $product = $this->productIn($store, 'GUEST241-GONE-PLAIN');
        $this->guestLine($sid, $store, $product);
        $this->deleteRow('products', (int) $product->id, Product::class);

        $response = $this->sidebar($store, $sid);

        $this->assertNotSame(500, $response->status(),
            'Cart::cart_list_cookie() dereferenced ->id and ->product_type on a Product::find() '
            . 'that returned null, so a GUEST shopper whose basket holds a delisted product gets '
            . 'HTTP 500 on the cart screen — the signed-in twin of this was GAP-245');
    }

    public function test_the_guest_cart_does_not_crash_when_the_variant_is_deleted(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $product = $this->productIn($store, 'GUEST241-GONE-VARIANT', true);
        $variant = $this->variantFor($product, $store);
        $this->guestLine($sid, $store, $product, (int) $variant->id);
        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->sidebar($store, $sid);

        $this->assertNotSame(500, $response->status(),
            'Cart::cart_list_cookie() read ->variant and multiplied ->discount_price off a '
            . 'ProductVariant::find() that returned null');
    }

    // ────────────────────────────────────────────────────────── the decision, same as signed-in

    public function test_the_guest_cart_drops_the_stale_line_prices_the_healthy_one_and_names_it(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $healthy = $this->productIn($store, 'GUEST241-HEALTHY');
        $this->guestLine($sid, $store, $healthy);

        $gone = $this->productIn($store, 'GUEST241-DELISTED');
        $goneLine = $this->guestLine($sid, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->sidebar($store, $sid);
        $body = $this->basketFor($store, $sid);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) ($body['status'] ?? 0),
            'the whole guest basket was refused, which is the alternative NOT chosen');

        $list = $body['data']['product_list'] ?? null;
        $this->assertIsArray($list);
        $this->assertCount(1, $list,
            'the priced guest basket still holds the delisted line');
        $this->assertSame((int) $healthy->id, (int) $list[0]['product_id']);

        $this->assertSame(100.00, (float) ($body['data']['total_final_price'] ?? -1),
            'the guest total did not recompute over the surviving line alone');
        $this->assertSame(1, (int) ($body['data']['cart_total_qty'] ?? -1));
        $this->assertSame(1, (int) ($body['data']['cart_total_product'] ?? -1),
            'cart_total_product still counts the raw cart rows, so the sidebar prints a count '
            . 'that disagrees with the lines it was given');

        $dropped = $body['data']['unavailable_products'] ?? null;
        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped, 'the guest is not told that anything was dropped');
        $this->assertSame((int) $gone->id, (int) $dropped[0]['product_id']);
        $this->assertSame(0, (int) $dropped[0]['variant_id']);

        /*
         * `unavailable_products.cart_id` IS A REAL `carts.id` ON BOTH ARMS, and that is a
         * deliberate asymmetry with `product_list.cart_id`, which on THIS arm is the loop index
         * (`Cart.php:559`) — a known trap recorded at PaymentController.php:2101-2129, where
         * reading it as a row id released an idempotency claim for every guest. The new structure
         * is identical in meaning on both arms; the old key is left exactly as it was, because
         * changing IT is a storefront contract change that comment defers.
         */
        $this->assertSame((int) $goneLine->id, (int) $dropped[0]['cart_id'],
            'unavailable_products.cart_id is not the real carts row, so a consumer cannot act on it');
        $this->assertNotSame('', (string) ($body['data']['unavailable_message'] ?? ''));
    }

    /** The name is carried when it can be: a deleted VARIANT leaves its product row behind. */
    public function test_a_dropped_guest_variant_line_is_named(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $product = $this->productIn($store, 'GUEST241-NAMEABLE', true);
        $variant = $this->variantFor($product, $store);
        $this->guestLine($sid, $store, $product, (int) $variant->id);
        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $body = $this->basketFor($store, $sid);
        $dropped = $body['data']['unavailable_products'] ?? null;

        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped);
        $this->assertSame('GUEST241-NAMEABLE', (string) $dropped[0]['name']);
        $this->assertSame((int) $variant->id, (int) $dropped[0]['variant_id']);
    }

    /**
     * AND THE NAME MUST NOT BECOME A CROSS-TENANT DISCLOSURE — the same hole I opened and closed
     * on the signed-in arm, which is why it is driven here BEFORE writing the guest version.
     *
     * `Product::find()` is unscoped (`Product` carries `StoreScope` via `BelongsToStore` but is
     * NOT in `scoped_models`, so the scope is inert for every principal), and a guest `carts` row
     * can hold a foreign `product_id` the same way a signed-in one can. The gate is a PROVABLE
     * MISMATCH and not equality, because `products.store_id` is nullable
     * (2023_12_26_092822_create_products_table.php:49) and `productOfCartLineStore()` already
     * accepts NULL and refuses only a provable mismatch (GAP-237).
     */
    public function test_a_dropped_guest_line_does_not_name_another_stores_product(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $foreign = $this->productIn($storeB, 'GUEST241-STORE-B-SECRET-NAME', true);
        $foreignVariant = $this->variantFor($foreign, $storeB);

        /* The row a vendored module writer can create: store A's basket, store B's product. */
        $this->guestLine($sid, $storeA, $foreign, (int) $foreignVariant->id);
        $this->deleteRow('product_variants', (int) $foreignVariant->id, ProductVariant::class);

        $response = $this->sidebar($storeA, $sid);
        $body = $this->basketFor($storeA, $sid);

        $this->assertNotSame(500, $response->status());

        $dropped = $body['data']['unavailable_products'] ?? null;
        $this->assertIsArray($dropped);
        $this->assertCount(1, $dropped,
            'premise: the foreign line was not dropped, so this case never reaches the name');
        $this->assertSame('', (string) $dropped[0]['name'],
            "the guest response named another store's product");
        $this->assertStringNotContainsString('GUEST241-STORE-B-SECRET-NAME', $response->getContent(),
            "store B's product name reached a store-A guest somewhere in the payload");
    }

    // ────────────────────────────────────────────────────────────────── the shape of the payload

    /**
     * `product_list` MUST STILL SERIALISE AS A JSON ARRAY, and `cart_id` MUST STILL EQUAL ITS
     * POSITION.
     *
     * The key is the cart collection's index, so a bare `continue` leaves a gap and `json_encode`
     * emits an OBJECT. And `Cart.php:559` sets `['cart_id'] = $key` — so on this arm `cart_id` IS
     * the position, today, and four storefront views render it into `data-id`. Dropping a line
     * without renumbering would break that invariant silently. Measured first, so this asserts
     * the behaviour that exists rather than one I invented: on the GUEST branch
     * `CartController::change_cart:188-200` ignores the posted `cart_id` entirely and keys on
     * `product_id`/`variant_id`, so renumbering cannot break the quantity or remove path.
     */
    public function test_guest_product_list_is_a_json_array_whose_cart_ids_match_their_positions(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        /* The stale line is inserted FIRST so it is not the last key, which is where a gap shows. */
        $gone = $this->productIn($store, 'GUEST241-ORDER-GONE');
        $this->guestLine($sid, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $kept = $this->productIn($store, 'GUEST241-ORDER-KEPT');
        $this->guestLine($sid, $store, $kept);

        $body = $this->basketFor($store, $sid);
        $list = $body['data']['product_list'] ?? null;

        $this->assertIsArray($list);
        $this->assertTrue(array_is_list($list),
            'product_list came back with a gap in its keys, so it serialises as a JSON object '
            . 'rather than an array: ' . json_encode(array_keys((array) $list)));

        foreach ($list as $position => $line) {
            $this->assertSame($position, (int) $line['cart_id'],
                'cart_id no longer equals its position, so the data-id four storefront views '
                . 'render has drifted from the row it labels');
        }
    }

    /**
     * A STALE LINE TOGETHER WITH A FLAT COUPON — THE ONE PIECE OF ARITHMETIC A DROP CAN MOVE.
     *
     * Raised by the independent review of the signed-in change, which found the broad claim
     * "dropping a line cannot change the remaining lines' arithmetic" to be FALSE even though the
     * narrow one ("no total that works today moves") is true. The mechanism, verified in the code:
     * `CouponBasketAllocator::forBasket()` takes `basketSubtotal = $cart_final_price`, which now
     * excludes dropped lines; `discountForLine()` apportions a flat coupon as
     * `flatDiscount * lineTotal / basketSubtotal`; and `spendIsOutOfRange()` (`:105`) reads the
     * same figure, so a drop can also push a basket under `minimum_spend` and make a valid coupon
     * inert.
     *
     * SO THIS ASSERTS THE RECOMPUTED FIGURES, NOT MERELY THAT NOTHING CRASHED. A two-line 200.00
     * basket with a flat 50.00 coupon, one line then delisted, must price as the SURVIVING 100.00
     * line with the WHOLE 50.00 coupon against it — not half of it, which is what apportioning
     * across a basket that still counted the dead line would give.
     */
    public function test_a_flat_coupon_is_reapportioned_over_the_surviving_guest_lines(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $kept = $this->productIn($store, 'GUEST241-COUPON-KEPT');
        $this->guestLine($sid, $store, $kept);

        $gone = $this->productIn($store, 'GUEST241-COUPON-GONE');
        $this->guestLine($sid, $store, $gone);

        DB::table('coupons')->insert([
            'coupon_name' => 'GUESTFLAT', 'coupon_code' => 'GUESTFLAT',
            'coupon_type' => 'flat', 'discount_amount' => 50,
            'coupon_limit' => 1000, 'coupon_limit_user' => 1000,
            'coupon_expiry_date' => now()->addYear()->toDateString(),
            'free_shipping_coupon' => 0, 'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        /* PREMISE: with BOTH lines live, the 200.00 basket takes the whole 50.00. */
        request()->cookies->set('cart', $sid);
        $before = json_decode(json_encode(Cart::cart_list_cookie(
            ['slug' => $store->slug, 'coupon_code' => 'GUESTFLAT'],
            $store->id
        )), true);

        $this->assertSame(200.00, (float) ($before['data']['total_final_price'] ?? -1),
            'premise: the two-line guest basket did not price at 200.00, so the comparison below '
            . 'is meaningless');
        $this->assertSame(50.00, (float) ($before['data']['total_coupon_price'] ?? -1),
            'premise: the flat coupon did not apply at all before the drop');

        $this->deleteRow('products', (int) $gone->id, Product::class);

        request()->cookies->set('cart', $sid);
        $after = json_decode(json_encode(Cart::cart_list_cookie(
            ['slug' => $store->slug, 'coupon_code' => 'GUESTFLAT'],
            $store->id
        )), true);

        $this->assertCount(1, $after['data']['product_list'] ?? []);
        $this->assertSame(100.00, (float) ($after['data']['total_final_price'] ?? -1),
            'the subtotal did not recompute over the surviving line alone');
        $this->assertSame(50.00, (float) ($after['data']['total_coupon_price'] ?? -1),
            'the flat coupon was still apportioned across the dead line, so the shopper lost part '
            . 'of a discount they are entitled to');
        $this->assertSame(50.00, (float) ($after['data']['total_sub_price'] ?? -1),
            'the amount owed is not the surviving line minus the whole coupon');
        $this->assertCount(1, $after['data']['unavailable_products'] ?? []);
    }

    /** CONTROL: a guest basket with nothing stale in it is priced exactly as before. */
    public function test_a_healthy_guest_basket_is_unchanged_and_reports_nothing_dropped(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $product = $this->productIn($store, 'GUEST241-CONTROL');
        $this->guestLine($sid, $store, $product, 0, 2);

        $body = $this->basketFor($store, $sid);

        $this->assertSame(1, (int) ($body['status'] ?? 0));
        $this->assertCount(1, $body['data']['product_list'] ?? []);
        $this->assertSame(200.00, (float) ($body['data']['total_final_price'] ?? -1),
            'the control guest basket no longer prices the way it did, so the change is not '
            . 'contained to stale lines');
        $this->assertSame([], $body['data']['unavailable_products'] ?? null);
        $this->assertSame('', (string) ($body['data']['unavailable_message'] ?? 'x'));
    }

    // ──────────────────────────────────────────────────── the identity boundary on this arm

    /**
     * ONE PRINCIPAL PER TEST METHOD, AND ON THIS ROUTE IT MATTERS FOR A SECOND REASON.
     *
     * The arm is chosen by `auth('customers')->guest()`. Laravel's test client keeps the session
     * across requests inside one method, so a signed-in request followed by a guest request in
     * the same method would leave the `customers` guard populated and the "guest" request would
     * silently take the AUTHENTICATED arm — the same class of false reading that reported a
     * merchant holding a shopper's basket on the signed-in twin, measured there.
     */
    public function test_another_guests_cookie_does_not_reach_this_guests_basket(): void
    {
        [, $store] = $this->tenant();
        $mine = 'guest241-mine-' . uniqid();
        $theirs = 'guest241-theirs-' . uniqid();

        $product = $this->productIn($store, 'GUEST241-NEIGHBOUR');
        $this->guestLine($mine, $store, $product);

        $gone = $this->productIn($store, 'GUEST241-NEIGHBOUR-GONE');
        $this->guestLine($mine, $store, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $body = $this->basketFor($store, $theirs);

        $this->assertSame([], $body['data']['product_list'] ?? null,
            "another guest's cookie was served this guest's basket");
        $this->assertSame([], $body['data']['unavailable_products'] ?? null,
            "another guest's cookie was told which of this guest's products had been delisted — "
            . 'a disclosure introduced by this fix');
    }

    /** And a cookie naming a basket in ANOTHER store sees nothing here. */
    public function test_a_cookie_whose_basket_is_in_another_store_sees_nothing(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $inB = $this->productIn($storeB, 'GUEST241-IN-STORE-B');
        $this->guestLine($sid, $storeB, $inB);

        $gone = $this->productIn($storeB, 'GUEST241-IN-STORE-B-GONE');
        $this->guestLine($sid, $storeB, $gone);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $body = $this->basketFor($storeA, $sid);

        $this->assertSame([], $body['data']['product_list'] ?? null,
            "store A served a basket whose rows carry store B's store_id");
        $this->assertSame([], $body['data']['unavailable_products'] ?? null,
            "store A disclosed store B's delisted product to this caller");
    }

    /** A SIGNED-IN shopper on the same route takes the OTHER arm and must not crash either. */
    public function test_a_signed_in_shopper_on_this_route_takes_the_authenticated_arm(): void
    {
        [, $store] = $this->tenant();
        $sid = 'guest241-' . uniqid();

        $shopper = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'guest241-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        /* Their OWN (signed-in) basket holds the stale line; the cookie basket is a decoy. */
        $gone = $this->productIn($store, 'GUEST241-SIGNED-IN-GONE');
        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $gone->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 100.00, 'store_id' => $store->id,
        ]);
        $decoy = $this->productIn($store, 'GUEST241-COOKIE-DECOY');
        $this->guestLine($sid, $store, $decoy);
        $this->deleteRow('products', (int) $gone->id, Product::class);

        $response = $this->actingAs($shopper, 'customers')
            ->withCookie('cart', $sid)
            ->post('/' . $store->slug . '/cart-list-sidebar', ['slug' => $store->slug]);

        $this->assertNotSame(500, $response->status(),
            'the signed-in arm of this route crashed on a stale line');
        $this->assertStringNotContainsString('GUEST241-COOKIE-DECOY', $response->getContent(),
            'a signed-in shopper was served the COOKIE basket, so the arm selection is wrong and '
            . 'every guest-arm case in this file may be measuring the wrong code');
    }
}
