<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-235 (b) — A PRODUCT OR VARIANT DELETED WHILE IT SITS IN A LIVE BASKET TAKES THE ENDPOINT
 * DOWN WITH IT.
 * =============================================================================================
 *
 * Four unguarded dereferences, all on rows the handler has just looked up and not checked:
 *
 *     cart_check  $productStock_data->stock      the variant arm
 *                 $product_data->name            both arms
 *     cart_qty    $ProductVariant->final_price   before anything else in the method
 *
 * A merchant deleting a variant or a product is an ordinary act, and a shopper's cart row survives
 * it: `carts` carries no foreign key onto `products` or `product_variants`
 * (2023_12_28_create_carts_table). So the basket keeps pointing at a row that is gone, PHP 8
 * promotes the undefined-property warning to an `ErrorException`, and the shopper's checkout gate
 * answers HTTP 500 with no way out of it from the client.
 *
 * NOT A DISCLOSURE -- and that is exactly why it is in this file rather than filed as a UX bug. A
 * 500 is the shape that lets a regression test pass for the wrong reason: "the row did not change"
 * and "no foreign data came back" both hold when the handler never ran. CLAUDE.md records this
 * family producing that false reading more than once, and the sibling GAP-131 case for
 * `cart-check-guest` was written for the same reason.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT IS REACHABLE AND WHAT IS NOT, MEASURED RATHER THAN ASSUMED.
 *
 * `cart_check` does its own work only AFTER forwarding to `cart_list()`, which looks the same
 * products up and has unguarded dereferences of its own. So the two arms are not equally
 * reachable, and this file drives both and records which crash where. Claiming the
 * deleted-PRODUCT arm of `cart_check` as a fix for a reachable 500 without checking whether
 * `cart_list` crashes first would be the "a correction can be wrong in the way the thing it
 * corrects was" error.
 *
 * ---------------------------------------------------------------------------------------------
 * `remove` MUST KEEP WORKING, which is the one behavioural requirement here. A shopper whose cart
 * holds a deleted variant must be able to CLEAR it; a guard that refuses the whole method would
 * leave them stuck with an un-removable line and an un-completable basket -- a different bug with
 * the same cause. So `remove` is driven separately from `increase`.
 */
class StorefrontCartNullDereferenceTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function productIn($store, string $name, bool $variantProduct = false): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap235b-' . uniqid(),
            'description' => 'x',
            'product_stock' => 9,
            'track_stock' => 1,
            'variant_product' => $variantProduct ? 1 : 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function variantFor(Product $product, $store, int $stock = 5): ProductVariant
    {
        /* `price` and `stock` -- the columns product_variants actually has. */
        return ProductVariant::forceCreate([
            'product_id' => $product->id,
            'variant' => 'red/large',
            'sku' => 'SKU-' . uniqid(),
            'price' => 10,
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
            'email' => 'gap235b-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function cartLine(Customer $customer, $store, Product $product, int $variantId = 0, int $qty = 2): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'variant_id' => $variantId,
            'qty' => $qty,
            'price' => 20,
            'store_id' => $store->id,
        ]);
    }

    /** Delete a row underneath a live basket, the way a merchant's own delete does. */
    private function deleteRow(string $table, int $id, string $model): void
    {
        DB::table($table)->where('id', $id)->delete();

        /* `Product` and `ProductVariant` are both Cachable, and under CACHE_STORE=array a raw
           write leaves Eloquent reads -- the handler's own -- serving the pre-delete row for the
           rest of the test. CLAUDE.md records three measured false readings from exactly this. */
        (new $model())->flushCache();

        $this->assertNull(DB::table($table)->where('id', $id)->first(),
            'premise: the row was not actually deleted, so this case is vacuous');
    }

    private function hit($store, string $path, array $payload, Customer $customer)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $customer->createToken('t')->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, $payload);
    }

    // ═══════════════════════════════════════════════════════════════════════════════ cart_check

    public function test_cart_check_does_not_crash_when_a_variant_in_the_basket_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-VARIANT-PRODUCT', true);
        $variant = $this->variantFor($product, $store);
        $this->cartLine($shopper, $store, $product, (int) $variant->id);

        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->hit($store, 'cart-check', ['customer_id' => $shopper->id], $shopper);

        $this->assertNotSame(500, $response->status(),
            'cart_check read ->stock off a ProductVariant lookup that returned null, so a shopper '
            . 'whose basket holds a variant the merchant has deleted cannot check out and cannot '
            . 'be told why');
        $this->assertSame(200, $response->status());
        $this->assertSame(0, (int) $response->json('status'),
            'a basket holding a deleted variant was reported as ready for checkout');
    }

    public function test_cart_check_does_not_crash_when_a_product_in_the_basket_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-PLAIN-PRODUCT');
        $this->cartLine($shopper, $store, $product);

        $this->deleteRow('products', (int) $product->id, Product::class);

        $response = $this->hit($store, 'cart-check', ['customer_id' => $shopper->id], $shopper);

        $this->assertNotSame(500, $response->status(),
            'cart_check read ->name off a Product::find() that returned null');
        $this->assertSame(0, (int) $response->json('status'),
            'a basket holding a deleted product was reported as ready for checkout');
    }

    /** CONTROL: a basket whose rows all still exist is still reported ready. */
    public function test_cart_check_still_reports_a_healthy_basket_as_ready(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $plain = $this->productIn($store, 'GAP235B-HEALTHY-PLAIN');
        $this->cartLine($shopper, $store, $plain, 0, 2);

        $response = $this->hit($store, 'cart-check', ['customer_id' => $shopper->id], $shopper);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'cart_check refused a healthy same-store basket, which is a regression and not a fix');
        $this->assertSame('Cart is ready.', data_get($response->json(), 'data.message'));
    }

    /** CONTROL: and a real insufficient-stock basket is still refused BY NAME, not by crash. */
    public function test_cart_check_still_refuses_a_basket_that_outruns_the_stock(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $plain = $this->productIn($store, 'GAP235B-SHORT-STOCK');
        DB::table('products')->where('id', $plain->id)->update(['product_stock' => 1]);
        (new Product())->flushCache();
        $this->cartLine($shopper, $store, $plain, 0, 7);

        $response = $this->hit($store, 'cart-check', ['customer_id' => $shopper->id], $shopper);

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertStringContainsString('insufficient stock', (string) data_get($response->json(), 'data.message'),
            'the refusal no longer names the product that is short, so the guard has replaced the '
            . 'stock check rather than standing in front of it');
        $this->assertStringContainsString('GAP235B-SHORT-STOCK', (string) data_get($response->json(), 'data.message'));
    }

    // ═════════════════════════════════════════════════════════════════════════════════ cart_qty

    public function test_cart_qty_does_not_crash_when_the_variant_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-QTY-VARIANT-PRODUCT', true);
        $variant = $this->variantFor($product, $store);
        $this->cartLine($shopper, $store, $product, (int) $variant->id);

        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->hit($store, 'cart-qty', [
            'customer_id' => $shopper->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_type' => 'increase',
        ], $shopper);

        $this->assertNotSame(500, $response->status(),
            'cart_qty read ->final_price off a ProductVariant::find() that returned null, before '
            . 'it had looked at anything else');
        $this->assertSame(0, (int) $response->json('status'),
            'the quantity of a deleted variant was reported as increased');
    }

    /**
     * AND THE SHOPPER CAN STILL CLEAR THE STALE LINE. This is the case a blanket refusal fails:
     * without it, a guard that returns early for a missing variant leaves the line in the basket
     * for ever and `cart_check` then refuses the whole basket.
     */
    public function test_cart_qty_can_still_remove_a_line_whose_variant_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-QTY-REMOVE-PRODUCT', true);
        $variant = $this->variantFor($product, $store);
        $line = $this->cartLine($shopper, $store, $product, (int) $variant->id);

        $this->deleteRow('product_variants', (int) $variant->id, ProductVariant::class);

        $response = $this->hit($store, 'cart-qty', [
            'customer_id' => $shopper->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_type' => 'remove',
        ], $shopper);

        $this->assertNotSame(500, $response->status(), 'cart_qty crashed on a remove');

        /* Through DB::table(), not Cart::find(): `Cart` IS in config/tenancy.php's scoped_models,
           so an Eloquent read answers against whichever tenant happens to be resolved and an
           "it is gone" assertion can pass whether or not the row went. CLAUDE.md records that
           costing a real false reading on this very table. */
        $this->assertNull(DB::table('carts')->where('id', $line->id)->first(),
            'a shopper could not clear a cart line whose variant the merchant had deleted, so the '
            . 'basket is permanently un-checkoutable');
    }

    /** CONTROL: an ordinary increase on a live variant still increases the line. */
    public function test_cart_qty_still_increases_a_live_variant_line(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-QTY-LIVE-PRODUCT', true);
        $variant = $this->variantFor($product, $store, 50);
        $line = $this->cartLine($shopper, $store, $product, (int) $variant->id, 2);

        $response = $this->hit($store, 'cart-qty', [
            'customer_id' => $shopper->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'quantity_type' => 'increase',
        ], $shopper);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'cart_qty refused a legitimate increase on a live variant, which is a regression');
        $this->assertSame(3, (int) DB::table('carts')->where('id', $line->id)->value('qty'),
            'the quantity did not actually move, so the control proves nothing');
    }

    /** CONTROL: and a plain (non-variant) line still increases. */
    public function test_cart_qty_still_increases_a_live_plain_line(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-QTY-LIVE-PLAIN');
        $line = $this->cartLine($shopper, $store, $product, 0, 2);

        $response = $this->hit($store, 'cart-qty', [
            'customer_id' => $shopper->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity_type' => 'increase',
        ], $shopper);

        $this->assertSame(200, $response->status());
        $this->assertSame(1, (int) $response->json('status'),
            'cart_qty refused a legitimate increase on a live plain product');
        $this->assertSame(3, (int) DB::table('carts')->where('id', $line->id)->value('qty'));
    }

    public function test_cart_qty_does_not_crash_when_the_plain_product_is_deleted(): void
    {
        [, $store] = $this->makeTenant();
        $shopper = $this->shopperOf($store);

        $product = $this->productIn($store, 'GAP235B-QTY-DELETED-PLAIN');
        $this->cartLine($shopper, $store, $product, 0, 2);

        $this->deleteRow('products', (int) $product->id, Product::class);

        $response = $this->hit($store, 'cart-qty', [
            'customer_id' => $shopper->id,
            'product_id' => $product->id,
            'variant_id' => 0,
            'quantity_type' => 'increase',
        ], $shopper);

        $this->assertNotSame(500, $response->status(),
            'cart_qty read ->product_stock off a Product::find() that returned null');
        $this->assertSame(0, (int) $response->json('status'));
    }
}
