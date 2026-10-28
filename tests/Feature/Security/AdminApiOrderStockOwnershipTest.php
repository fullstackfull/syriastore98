<?php

namespace Tests\Feature\Security;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * P01-COMMERCE-SEC-03 FOLLOW-UP — the merchant API's own order writer.
 * =============================================================================================
 *
 * `Api\DashboardController::createOrderViaApi` is routed at `POST api/admin/createOrderViaApi`
 * (routes/api.php:240, middleware `custom.auth`) — the prefix the shipped admin Flutter app
 * calls. It was found by a completeness sweep AFTER the storefront cart → order chain was
 * closed, and it is the same defect one surface over:
 *
 *   - `resolveOwnedStore($storeId)` proves the caller owns the STORE.
 *   - `products.*.product_id => exists:products,id` proves the product EXISTS.
 *   - NOTHING tied the two together: `Product::find()` and `ProductVariant::find()` are scoped
 *     by nothing at all, so a merchant could name another merchant's product and the loop
 *     decremented that merchant's inventory while writing the order under their own store.
 *
 * Both tests drive the real route with a real Sanctum token for a real merchant.
 */
class AdminApiOrderStockOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function variantProductIn($store, int $productStock = 50, int $variantStock = 50): array
    {
        $product = Product::forceCreate([
            'name' => 'aoso-' . uniqid(), 'slug' => 'aoso-' . uniqid(),
            'product_stock' => $productStock, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 10, 'sale_price' => 10, 'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);

        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'aoso-' . uniqid(),
            'price' => 10, 'stock' => $variantStock, 'store_id' => $store->id,
            'variation_option' => 'manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$product, $variantId];
    }

    private function customerIn($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Aoso', 'last_name' => 'Shopper',
            'email' => 'aoso-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'),
            'store_id' => $store->id, 'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function createOrder($merchant, $store, Customer $customer, int $productId, $variantId = null)
    {
        // P2-04-10 CONSEQUENCE, recorded rather than silently absorbed.
        //
        // `createOrderViaApi` gained a permission gate on 2026-10-28: it previously had NO
        // permission check at all (328 lines, two order-creating calls, zero checks), so an
        // employee with no order permission created real orders in their employer's store. This
        // suite's subject is the STOCK contract, not permissions, and a fixture merchant holds no
        // Laratrust role -- so without this grant it would start failing for an orthogonal reason
        // and stop isolating what it was written for. A REAL merchant is unaffected: the shared
        // global `admin` role carries `Manage Order` and every merchant is given it for every
        // store they create.
        $this->grantPermission(\App\Models\User::find($merchant->id), 'Manage Order');
        $merchant = \App\Models\User::find($merchant->id);

        return $this->withHeader('Authorization', 'Bearer ' . $merchant->createToken('auth_token')->plainTextToken)
            ->postJson('/api/admin/createOrderViaApi?store_id=' . $store->id, [
                'customer_id' => $customer->id,
                'products' => [array_filter([
                    'product_id' => $productId,
                    'variant_id' => $variantId,
                    'quantity' => 3,
                    'price' => 10.00,
                ], fn ($v) => $v !== null)],
                'payment_type' => 'cod',
                'payment_status' => 'Unpaid',
                'first_name' => 'Aoso', 'last_name' => 'Buyer',
                'email' => 'buyer@example.test', 'telephone' => '555000111',
                'address' => 'Street 1',
            ]);
    }

    /**
     * A MERCHANT CANNOT DRAIN ANOTHER MERCHANT'S VARIANT STOCK THROUGH THEIR OWN STORE.
     *
     * The principal is entirely legitimate — merchant A, their own token, their own `store_id`.
     * Only the `product_id` belongs to someone else.
     */
    public function test_a_merchant_cannot_decrement_another_merchants_variant_stock(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $customer = $this->customerIn($storeA);
        [$foreign, $foreignVariantId] = $this->variantProductIn($storeB, 50, 50);

        $ordersBefore = (int) DB::table('orders')->count();
        $response = $this->createOrder($merchantA, $storeA, $customer, $foreign->id, $foreignVariantId);

        $this->assertSame(50, (int) DB::table('product_variants')->where('id', $foreignVariantId)->value('stock'),
            'Merchant A decremented merchant B\'s variant stock through the admin order API.');
        $this->assertSame(50, (int) Product::withoutGlobalScopes()->find($foreign->id)->product_stock,
            'Merchant A decremented merchant B\'s product stock through the admin order API.');
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'An order was written naming another merchant\'s product. Response: '
            . $response->getContent());
    }

    /**
     * AND THE VARIANT MUST BELONG TO THE PRODUCT, within one store.
     *
     * Scoping the product does not scope the variant: both rows here belong to merchant A, so
     * only the pairing is wrong. Without the `product_id` predicate the decrement landed on an
     * unrelated variant of an unrelated product.
     */
    public function test_a_variant_from_another_product_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $customer = $this->customerIn($storeA);
        [$product] = $this->variantProductIn($storeA, 50, 50);
        [, $unrelatedVariantId] = $this->variantProductIn($storeA, 50, 50);

        $ordersBefore = (int) DB::table('orders')->count();
        $response = $this->createOrder($merchantA, $storeA, $customer, $product->id, $unrelatedVariantId);

        $this->assertSame(50, (int) DB::table('product_variants')->where('id', $unrelatedVariantId)->value('stock'),
            'An unrelated variant was decremented. Response: ' . $response->getContent());
        $this->assertSame($ordersBefore, (int) DB::table('orders')->count(),
            'An order was written pairing a product with another product\'s variant.');
    }

    /**
     * THE POSITIVE CONTROL. Without this, both tests above would be satisfied by an endpoint
     * that refuses everything — including the 403 it returns for a store the caller does not
     * own, which is how a version of this test could pass while proving nothing.
     */
    public function test_a_merchant_can_still_write_an_order_for_their_own_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $customer = $this->customerIn($storeA);
        [$own, $ownVariantId] = $this->variantProductIn($storeA, 50, 50);

        $response = $this->createOrder($merchantA, $storeA, $customer, $own->id, $ownVariantId);

        $this->assertSame(47, (int) DB::table('product_variants')->where('id', $ownVariantId)->value('stock'),
            'A merchant\'s own legitimate order no longer decrements their own variant stock. '
            . 'Response: ' . $response->getContent());
        $this->assertSame(1, (int) DB::table('orders')->count(),
            'A merchant\'s own legitimate order was refused. Response: ' . $response->getContent());
    }
}
