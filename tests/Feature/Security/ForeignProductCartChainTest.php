<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P01-COMMERCE-SEC-03 — the cart → order chain that reaches another merchant's inventory.
 * =============================================================================================
 *
 * THE CHAIN, not a validation helper. Every test here drives the whole journey through real
 * routes, because that is the only shape that proves anything: each leg of this attack is
 * individually unremarkable, and the previous two packages' guards all sit on the LAST leg.
 *
 *     POST /api/{slugA}/addtocart     a store-A customer, their own token, store B's product
 *       -> a cart row persists naming the foreign product
 *     POST /api/{slugA}/order-save    an ordinary checkout
 *       -> merchant B's stock is decremented
 *
 * WHY THE EXISTING GUARDS DO NOT SEE IT. `addtocart` has resolved its product store-scoped since
 * S19 — `Product::where('id', …)->where('store_id', $store->id)` (`ApiController:960`) — and the
 * comment above that line says it "keeps a foreign product out of the cart in the first place".
 * **It does not.** The null it returns for a foreign id is never refused on the `attribute_id`
 * branch (`:1451`), which resolves the VARIANT from the caller's own two values
 * (`ProductVariant::where('id', $request->attribute_id)->where('product_id', $request->product_id)`),
 * never consults `$product`, prices from the foreign variant, and writes
 * `$cart->product_id = $request->product_id` verbatim.
 *
 * `ProductVariant` carries no `StoreScope` and is not in `config/tenancy.php`'s `scoped_models`,
 * so scoping the PRODUCT does not scope the VARIANT. That is the whole defect.
 *
 * AND THE ROW SURVIVES THE 500. `$cart->save()` happens before the handler dereferences the null
 * `$product`, and nothing wraps the two in a transaction — so the caller gets an error response
 * and the poisoned row stays. Partial persistence is part of the attack, not a side effect, which
 * is why these tests assert the database rather than the response.
 */
class ForeignProductCartChainTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The order writers mail the customer and the store owner on success.
        Mail::fake();
    }

    /**
     * A tenant that can take an order, with `stock_management` seeded to a chosen value.
     *
     * @param  string  $stockManagement  'on' or 'off' — both workflows must be protected.
     * @return array{0: \App\Models\User, 1: \App\Models\Store, 2: int}
     */
    private function tenant(string $stockManagement = 'on'): array
    {
        [$merchant, $store] = $this->makeTenant();

        $planId = DB::table('plans')->insertGetId([
            'name' => 'fpc-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        Cache::forget('admin_details');

        foreach ([
            'stock_management' => $stockManagement,
            'out_of_stock_threshold' => '0',
            'notification' => '[]',
        ] as $name => $value) {
            DB::table('settings')->insert([
                'name' => $name, 'value' => $value,
                'created_by' => $merchant->id, 'store_id' => $store->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('countries')->insertOrIgnore([
            'id' => 101, 'name' => 'Syria', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('states')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('cities')->insertOrIgnore([
            'id' => 1, 'name' => 'Damascus', 'country_id' => 101, 'state_id' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $shippingId = DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant, $store, $shippingId];
    }

    private function customerIn($store, string $label): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Fpc', 'last_name' => $label,
            'email' => 'fpc-' . $label . '-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    /** A variant product plus one variant row, both owned by $store. */
    private function variantProductIn($store, int $productStock = 50, int $variantStock = 50): array
    {
        $product = Product::forceCreate([
            'name' => 'fpc-' . uniqid(), 'slug' => 'fpc-' . uniqid(),
            'product_stock' => $productStock, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red',
            'sku' => 'fpc-' . uniqid(), 'price' => 10, 'stock' => $variantStock,
            'store_id' => $store->id, 'variation_option' => 'manage_stock',
            'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $variantId];
    }

    private function plainProductIn($store, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => 'fpc-' . uniqid(), 'slug' => 'fpc-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function token(Customer $customer): string
    {
        return $customer->createToken('t')->plainTextToken;
    }

    private function addToCart($store, Customer $customer, array $payload)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($customer)])
            ->postJson('/api/' . $store->slug . '/add-cart', $payload);
    }

    /** `billing_postecode` is misspelled in the handler; every delivery_* key is required too. */
    private function billing(): array
    {
        return [
            'firstname' => 'Fpc', 'lastname' => 'Shopper', 'email' => 'fpc@example.test',
            'billing_user_telephone' => '555111222',
            'billing_address' => 'Street', 'billing_postecode' => '11111',
            'billing_country' => 'Testland', 'billing_state' => 'Teststate',
            'billing_city' => 'Testcity', 'billing_company_name' => '',
            'delivery_address' => 'Street', 'delivery_postcode' => '11111',
            'delivery_country' => 'Testland', 'delivery_state' => 'Teststate',
            'delivery_city' => 'Testcity',
        ];
    }

    private function placeOrder($store, Customer $customer, int $shippingId, string $path = 'order-save')
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token($customer)])
            ->postJson('/api/' . $store->slug . '/' . $path, [
                'customer_id' => $customer->id,
                'billing_info' => $this->billing(),
                'payment_type' => 'cod',
                'delivery_id' => $shippingId,
                'method_id' => $shippingId,
            ]);
    }

    private static function cartRowsFor(Customer $customer)
    {
        return Cart::withoutGlobalScopes()->where('customer_id', $customer->id)->get();
    }

    private static function productStock(Product $product): int
    {
        return (int) Product::withoutGlobalScopes()->find($product->id)->product_stock;
    }

    private static function variantStock(int $variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }

    // =====================================================================================
    //  LEG 1 — the cart must refuse a foreign product
    // =====================================================================================

    /**
     * A CUSTOMER OF STORE A CANNOT PUT STORE B'S PRODUCT IN THEIR CART, via the variant branch.
     *
     * This is the leg that was open. The principal is entirely legitimate: a real store-A
     * customer with their own token, refused by nothing on the way in.
     */
    public function test_a_customer_cannot_add_another_stores_product_through_the_variant_branch(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $shopper = $this->customerIn($storeA, 'shopper');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB);

        $this->addToCart($storeA, $shopper, [
            'product_id' => $foreign->id,
            'attribute_id' => $foreignVariantId,
            'variant_id' => $foreignVariantId,
            'qty' => 1,
        ]);

        $this->assertCount(0, self::cartRowsFor($shopper),
            "A store-A customer's cart holds a row naming store B's product. Rows: "
            . self::cartRowsFor($shopper)->map->only(['product_id', 'variant_id', 'store_id'])->toJson());
    }

    /**
     * NOR THROUGH THE NON-VARIANT BRANCH, which already refused — pinned so the fix cannot be
     * read as having introduced the only protection on this route.
     */
    public function test_a_customer_cannot_add_another_stores_plain_product(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $shopper = $this->customerIn($storeA, 'shopper');
        $foreign = $this->plainProductIn($storeB);

        $this->addToCart($storeA, $shopper, [
            'product_id' => $foreign->id,
            'qty' => 1,
        ]);

        $this->assertCount(0, self::cartRowsFor($shopper),
            'A plain foreign product entered the cart.');
    }

    /**
     * A VARIANT OF ANOTHER PRODUCT, under a product this store DOES own.
     *
     * The mismatch case the brief names: `product_id` is legitimate, `attribute_id` is not its
     * variant. `ProductVariant::where('id', …)->where('product_id', …)` already refuses the
     * PAIR — so this asserts the pair predicate still holds, and that the handler does not fall
     * back to the posted `variant_id` and write it anyway.
     */
    public function test_a_variant_belonging_to_another_product_is_refused(): void
    {
        [, $storeA] = $this->tenant();
        $shopper = $this->customerIn($storeA, 'shopper');
        [$mine] = $this->variantProductIn($storeA);
        [, $otherVariantId] = $this->variantProductIn($storeA);

        $response = $this->addToCart($storeA, $shopper, [
            'product_id' => $mine->id,
            'attribute_id' => $otherVariantId,
            'variant_id' => $otherVariantId,
            'qty' => 1,
        ]);

        /*
         * THE RESPONSE IS ASSERTED FIRST, and the reason is a finding. The first version of this
         * test checked only that no cart row named the foreign variant — and it passed with the
         * emptiness guard removed, because without it the handler raises a null property read on
         * `$ProductVariant->variation_option` and answers 500, so no row is written either way.
         * That is the "passes only because of an unrelated HTTP 500" shape this package's brief
         * forbids, and a removal check is what exposed it: reverting that guard failed nothing.
         *
         * A refusal and a crash are not the same outcome. The guard's job is to turn the crash
         * into this handler's own "Product not found." answer.
         */
        $this->assertNotSame(500, $response->getStatusCode(),
            'A mismatched product/variant pair crashed instead of being refused. Body: '
            . substr((string) $response->getContent(), 0, 300));

        $rows = self::cartRowsFor($shopper);

        $this->assertTrue(
            $rows->isEmpty() || $rows->every(fn ($r) => (int) $r->variant_id !== $otherVariantId),
            "A cart row was written pairing this store's product with another product's variant. "
            . 'Rows: ' . $rows->map->only(['product_id', 'variant_id'])->toJson()
        );
    }

    /**
     * NOTHING IS PERSISTED WHEN THE REQUEST IS REFUSED — the partial-write half of the attack.
     *
     * The original handler wrote the cart row and only then dereferenced the null `$product`, with
     * no transaction, so the refusal and the persistence were independent: the caller saw an error
     * and the row was there anyway. This asserts the whole table, not just this customer's rows.
     */
    public function test_a_refused_add_to_cart_persists_nothing(): void
    {
        [, $storeA] = $this->tenant();
        [, $storeB] = $this->tenant();
        $shopper = $this->customerIn($storeA, 'shopper');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB);

        $before = (int) Cart::withoutGlobalScopes()->count();

        $this->addToCart($storeA, $shopper, [
            'product_id' => $foreign->id,
            'attribute_id' => $foreignVariantId,
            'variant_id' => $foreignVariantId,
            'qty' => 1,
        ]);

        $this->assertSame($before, (int) Cart::withoutGlobalScopes()->count(),
            'A refused add-to-cart left a row behind: the write happens before the refusal and '
            . 'nothing rolls it back.');
    }

    // =====================================================================================
    //  LEG 2 — the order writer must refuse a foreign cart row that is ALREADY stored
    // =====================================================================================

    /**
     * A FOREIGN CART ROW ALREADY IN THE DATABASE CANNOT MOVE ANOTHER MERCHANT'S STOCK.
     *
     * Defence in depth, and not hypothetical: the row can arrive from any writer that does not
     * go through `addtocart` — two vendored module routes write `carts.product_id` straight from
     * the request (`BoostSales`, `FrequentlyProduct`; S30/S32), and rows planted before this fix
     * are still in the table. Closing leg 1 does not clean those up.
     *
     * Both stock-management workflows, because they take different paths through the loop.
     *
     * @dataProvider stockManagementWorkflows
     */
    public function test_a_stored_foreign_cart_row_cannot_move_another_merchants_stock(string $stockManagement): void
    {
        [, $storeA, $shippingId] = $this->tenant($stockManagement);
        [, $storeB] = $this->tenant('on');
        $shopper = $this->customerIn($storeA, 'shopper');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        // Planted directly, as a module writer or a pre-fix request would have left it.
        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $foreign->id,
            'variant_id' => $foreignVariantId, 'qty' => 7, 'price' => 70.00,
            'store_id' => $storeA->id,
        ]);

        $ordersBefore = (int) DB::table('orders')->count();

        $this->placeOrder($storeA, $shopper, $shippingId);

        $this->assertSame(50, self::variantStock($foreignVariantId),
            "Another merchant's VARIANT stock moved from this store's checkout. stock_management: "
            . $stockManagement);

        $this->assertSame(50, self::productStock($foreign),
            "Another merchant's PRODUCT stock moved from this store's checkout. stock_management: "
            . $stockManagement);

        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'An order was written from a cart holding another merchant\'s product. '
            . 'stock_management: ' . $stockManagement);
    }

    public static function stockManagementWorkflows(): array
    {
        return ['stock management on' => ['on'], 'stock management off' => ['off']];
    }

    /** The same, through `place-order` rather than `order-save`. */
    public function test_place_order_also_refuses_a_stored_foreign_cart_row(): void
    {
        [, $storeA, $shippingId] = $this->tenant('on');
        [, $storeB] = $this->tenant('on');
        $shopper = $this->customerIn($storeA, 'shopper');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        Cart::forceCreate([
            'customer_id' => $shopper->id, 'product_id' => $foreign->id,
            'variant_id' => $foreignVariantId, 'qty' => 7, 'price' => 70.00,
            'store_id' => $storeA->id,
        ]);

        $ordersBefore = (int) DB::table('orders')->count();

        $this->placeOrder($storeA, $shopper, $shippingId, 'place-order');

        $this->assertSame(50, self::variantStock($foreignVariantId),
            "Another merchant's variant stock moved through place-order.");
        $this->assertSame(50, self::productStock($foreign),
            "Another merchant's product stock moved through place-order.");
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'place-order wrote an order from a foreign cart row.');
    }

    // =====================================================================================
    //  LEG 2b — the GATEWAY returns, which price a guest cart and have their own stock loops
    // =====================================================================================

    /** A guest cart row plus the cookie value that reaches it. */
    private function guestCartRow($store, int $productId, int $variantId, int $qty = 7): string
    {
        $sid = 'fpc-cookie-' . uniqid();

        Cart::forceCreate([
            'customer_id' => 0, 'product_id' => $productId, 'variant_id' => $variantId,
            'qty' => $qty, 'price' => 10.00 * $qty, 'store_id' => $store->id,
            'cookie_session_id' => $sid,
        ]);

        return $sid;
    }

    /** The shape PaymentService stores at initiation. */
    private function checkoutState($store): array
    {
        return [
            'slug' => $store->slug,
            'store_id' => $store->id,
            'customer_id' => '',
            'payment_type' => 'paiementpro',
            'shipping_final_price' => 0,
            'tax_price' => 0,
            'cartlist_final_price' => 70.00,
            'coupon_info' => null,
            'billing_info' => [
                'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
                'billing_user_telephone' => '123', 'billing_address' => 'x',
                'billing_postecode' => '1', 'billing_country' => '101',
                'billing_state' => '1', 'billing_city' => '1', 'billing_company_name' => '',
                'delivery_address' => 'x', 'delivery_postcode' => '1',
                'delivery_country' => '101', 'delivery_state' => '1', 'delivery_city' => '1',
            ],
        ];
    }

    /**
     * THE GATEWAY RETURN REFUSES A FOREIGN BASKET TOO, before its stock loop.
     *
     * This is the leg `basketHasForeignProduct()` was extended and wired for. The gateway returns
     * price a GUEST cart read through the cookie, so they never pass through `add-cart` at all —
     * closing the cart entry does nothing for them, and their own loops resolve
     * `Product::where('id', $product_id)` with no store predicate whatsoever.
     *
     * PaiementPro is the one whose order writer is reachable in this environment; see
     * GatewayReturnTrustBoundaryTest for why.
     */
    public function test_a_gateway_return_refuses_a_foreign_product_before_its_stock_loop(): void
    {
        [, $storeA] = $this->tenant('on');
        [, $storeB] = $this->tenant('on');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        $sid = $this->guestCartRow($storeA, $foreign->id, $foreignVariantId);
        $ordersBefore = (int) DB::table('orders')->count();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($storeA)])
            ->post('/' . $storeA->slug . '/get-payment-paiementpro', [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringContainsString('item this store does not sell', (string) session('error'),
            'The gateway return did not refuse with the foreign-product guard. Session error: '
            . json_encode(session('error')));

        $this->assertSame(50, self::variantStock($foreignVariantId),
            "Another merchant's variant stock moved through a gateway return.");
        $this->assertSame(50, self::productStock($foreign),
            "Another merchant's product stock moved through a gateway return.");
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'A gateway return wrote an order from a foreign basket.');
    }

    /**
     * AND A MISMATCHED VARIANT — this store's product paired with ANOTHER product's variant.
     *
     * THE HALF THAT SCOPING THE PRODUCT CANNOT CATCH, and the reason the helper grew a second
     * question. The product here is entirely legitimate and store-owned; only the variant is
     * foreign. Every stock loop on the platform resolves its variant as
     * `ProductVariant::where('id', $variant_id)->where('product_id', $product_id)` — and
     * `ProductVariant` is scoped by nothing — so before this guard the decrement landed on a
     * variant row belonging to a different product, in a different store.
     *
     * The victim variant belongs to store B, under store B's product, while the cart line names
     * store A's product id. Nothing of store B's may move, and store A's own stock must not move
     * either, because the basket was refused.
     */
    public function test_a_gateway_return_refuses_a_variant_belonging_to_another_product(): void
    {
        [, $storeA] = $this->tenant('on');
        [, $storeB] = $this->tenant('on');
        [$mine, $myVariantId] = $this->variantProductIn($storeA, 50, 50);
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        // A legitimate store-A product id, paired with store B's variant id.
        $sid = $this->guestCartRow($storeA, $mine->id, $foreignVariantId);
        $ordersBefore = (int) DB::table('orders')->count();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($storeA)])
            ->post('/' . $storeA->slug . '/get-payment-paiementpro', [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringContainsString('item this store does not sell', (string) session('error'),
            'A mismatched product/variant pair was not refused. Session error: '
            . json_encode(session('error')));

        $this->assertSame(50, self::variantStock($foreignVariantId),
            "Another product's variant stock moved: the variant half of the guard did not fire.");
        $this->assertSame(50, self::variantStock($myVariantId),
            "This store's own variant stock moved for a basket that was refused.");
        $this->assertSame(50, self::productStock($foreign),
            "Another merchant's product stock moved.");
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'An order was written from a mismatched product/variant pair.');
    }

    /**
     * AND A LEGITIMATE GUEST GATEWAY RETURN STILL WRITES ITS ORDER — the positive control for
     * this leg, without which both refusals above could be satisfied by a handler that refuses
     * every gateway return.
     */
    public function test_a_legitimate_gateway_return_still_writes_its_order(): void
    {
        [, $store] = $this->tenant('on');
        [$product, $variantId] = $this->variantProductIn($store, 50, 50);

        $sid = $this->guestCartRow($store, $product->id, $variantId, 7);
        $ordersBefore = (int) DB::table('orders')->count();

        session()->forget('error');
        $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($store)])
            ->post('/' . $store->slug . '/get-payment-paiementpro', [
                'responsecode' => 0, 'status' => 'success',
            ]);

        $this->assertStringNotContainsString('item this store does not sell', (string) session('error'),
            'A legitimate guest gateway return was refused by this package\'s guard.');

        $this->assertSame($ordersBefore + 1, (int) DB::table('orders')->count(),
            'A legitimate guest gateway return wrote no order, so both refusals above prove '
            . 'nothing. Session error: ' . json_encode(session('error')));

        $this->assertSame(43, self::variantStock($variantId),
            "A completed gateway return did not consume the variant's stock.");
    }

    /**
     * THE GENERIC STOREFRONT RETURN AND THE WHATSAPP WRITER, each with its own preflight.
     *
     * Added because the removal check reported that neither was covered by anything: reverting
     * `basketHasForeignProduct()` in `PaymentController::getProductStatus` or in `whatsapp`
     * failed no test. Both resolve `Product::where('id', $product_id)` with **no store predicate
     * at all** in their stock loops, so the preflight is the only place ownership is decided.
     *
     * The refusal text differs between them by design — `getProductStatus` redirects a browser,
     * `whatsapp` answers an AJAX caller with an `error()` envelope — so each asserts its own.
     *
     * @dataProvider paymentControllerWriters
     */
    public function test_the_payment_controller_writers_refuse_a_foreign_basket(
        string $uri,
        string $expectIn
    ): void {
        [, $storeA] = $this->tenant('on');
        [, $storeB] = $this->tenant('on');
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        $sid = $this->guestCartRow($storeA, $foreign->id, $foreignVariantId);
        $ordersBefore = (int) DB::table('orders')->count();

        session()->forget('error');
        $response = $this->withCookie('cart', $sid)
            ->withSession(['request_data' => $this->checkoutState($storeA)])
            ->post('/' . $storeA->slug . '/' . $uri, [
                'responsecode' => 0, 'status' => 'success', 'wts_number' => '123456789',
            ]);

        $haystack = $expectIn === 'session'
            ? (string) session('error')
            : (string) $response->getContent();

        $this->assertStringContainsString('item this store does not sell', $haystack,
            'The foreign-product preflight did not fire on ' . $uri . '. Observed: '
            . substr($haystack, 0, 300));

        $this->assertSame(50, self::variantStock($foreignVariantId),
            "Another merchant's variant stock moved through " . $uri);
        $this->assertSame(50, self::productStock($foreign),
            "Another merchant's product stock moved through " . $uri);
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'An order was written from a foreign basket on ' . $uri);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function paymentControllerWriters(): array
    {
        return [
            // The generic storefront return redirects, so its refusal lands in the session.
            'storefront return' => ['get-payment-status', 'session'],
            // whatsapp answers its AJAX caller, so the refusal is in the body.
            'whatsapp writer' => ['whatsapp', 'body'],
        ];
    }

    // =====================================================================================
    //  THE POSITIVE CONTROLS — without these, every refusal above could be satisfied by a
    //  handler that refuses everything.
    // =====================================================================================

    /**
     * A LEGITIMATE VARIANT PURCHASE STILL WORKS, end to end: add to cart, then order, then the
     * variant's own stock moves by the quantity ordered.
     */
    public function test_a_legitimate_variant_purchase_still_completes(): void
    {
        [, $store, $shippingId] = $this->tenant('on');
        $shopper = $this->customerIn($store, 'shopper');
        [$product, $variantId] = $this->variantProductIn($store, 50, 50);

        $add = $this->addToCart($store, $shopper, [
            'product_id' => $product->id,
            'attribute_id' => $variantId,
            'variant_id' => $variantId,
            'qty' => 2,
        ]);

        $rows = self::cartRowsFor($shopper);
        $this->assertCount(1, $rows,
            'A legitimate variant add-to-cart wrote no cart row, so every refusal in this file '
            . 'could be satisfied by a handler that refuses everything. Response: '
            . $add->getStatusCode() . ' ' . substr((string) $add->getContent(), 0, 300));
        $this->assertSame($variantId, (int) $rows->first()->variant_id,
            'The legitimate row did not record the chosen variant.');

        $ordersBefore = (int) DB::table('orders')->count();

        $this->placeOrder($store, $shopper, $shippingId);

        $this->assertSame($ordersBefore + 1, (int) DB::table('orders')->count(),
            'A legitimate variant checkout wrote no order.');
        $this->assertSame(48, self::variantStock($variantId),
            "A legitimate variant order did not consume the variant's stock.");
    }

    /** And a legitimate PLAIN product purchase, which takes the other branch entirely. */
    public function test_a_legitimate_plain_product_purchase_still_completes(): void
    {
        [, $store, $shippingId] = $this->tenant('on');
        $shopper = $this->customerIn($store, 'shopper');
        $product = $this->plainProductIn($store, 50);

        $this->addToCart($store, $shopper, ['product_id' => $product->id, 'qty' => 3]);

        $this->assertCount(1, self::cartRowsFor($shopper),
            'A legitimate plain add-to-cart wrote no cart row.');

        $ordersBefore = (int) DB::table('orders')->count();

        $this->placeOrder($store, $shopper, $shippingId);

        $this->assertSame($ordersBefore + 1, (int) DB::table('orders')->count(),
            'A legitimate plain checkout wrote no order.');
        $this->assertSame(47, self::productStock($product),
            'A legitimate plain order did not consume its stock.');
    }

    /**
     * AND AN UNMANAGED STORE STILL SELLS, without deducting — so the fix cannot be mistaken for
     * having re-coupled ownership to the stock-management setting (S34's subject).
     */
    public function test_an_unmanaged_store_still_completes_a_legitimate_order_without_deducting(): void
    {
        [, $store, $shippingId] = $this->tenant('off');
        $shopper = $this->customerIn($store, 'shopper');
        $product = $this->plainProductIn($store, 50);

        $this->addToCart($store, $shopper, ['product_id' => $product->id, 'qty' => 3]);
        $this->assertCount(1, self::cartRowsFor($shopper), 'The legitimate add-to-cart was refused.');

        $ordersBefore = (int) DB::table('orders')->count();

        $this->placeOrder($store, $shopper, $shippingId);

        $this->assertSame($ordersBefore + 1, (int) DB::table('orders')->count(),
            'An unmanaged store refused a legitimate order.');
        $this->assertSame(50, self::productStock($product),
            'An unmanaged store deducted stock.');
    }
}
