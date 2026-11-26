<?php

namespace Tests\Feature\Security;

use App\Models\Cart;
use App\Models\Customer;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-280 (B) — THE REFUSAL MUST NAME THE LINE.
 * =============================================================================================
 *
 * THE DECISION THIS ENACTS, AND THE ONE IT DOES NOT. GAP-280's measurement
 * (`tests/Support/Security/bin/measure-foreign-product-cart-read.php`) offered two remedies.
 * (A) scope the cart readers so the foreign line is DROPPED — which works, unifies the defect with
 * GAP-241, and was measured to turn a refused mixed basket into a COMPLETED order with the
 * shopper's own stock moving 50 -> 48, because the readers feed `basketHasForeignProduct()` its
 * input. That is a payments behaviour change and it went to the owner. (B), enacted here, is the
 * half that is not a decision: KEEP THE REFUSAL EXACTLY AS IT IS AND MAKE IT SAY WHICH LINE.
 *
 * SO NOTHING ABOUT WHAT IS REFUSED MOVES, and several cases below exist only to prove that. The
 * basket is still refused, no order is written, no stock moves, and the DISPLAYED TOTAL IS LEFT
 * ALONE — deliberately, on the owner's instruction: the basket cannot be paid either way so the
 * total is not a charge, and silently re-pricing a line the shopper can still see would make the
 * screen disagree with itself.
 *
 * ---------------------------------------------------------------------------------------------
 * WHAT WAS WRONG WITH THE THREE REFUSALS, measured. CITED BY METHOD, NOT BY LINE: the three line
 * numbers these sites held went stale FOUR TIMES while this task was being done, every time
 * because of an edit made higher in the same 10,000-line file -- twice by the change that was
 * citing them. CLAUDE.md §3 names the hazard; a method name is simply immune to it:
 *
 *     place_order()    error(['message' => __('Product not found .')])
 *     payment_sheet()  error(['message' => __('Your cart contains an item this store does not sell.')])
 *     ordersave()      error(['message' => 'Product not found .'])
 *
 *   * TWO OF THE THREE ARE ACTIVELY MISLEADING. "Product not found ." is what this codebase says
 *     when a product does not exist. Here it exists and is simply not this store's, so the shopper
 *     is told something false about a basket they can see, and a merchant reading a support ticket
 *     is sent looking for a deleted product.
 *   * NONE OF THE THREE NAMES A LINE, which is the dead end: the shopper is shown a total they
 *     cannot pay and given no way to find the line at fault.
 *   * `ordersave`'s IS NOT WRAPPED IN `__()` at all — a bare English literal on a bilingual
 *     storefront. Pinned below by driving the Arabic locale.
 *
 * THE NAMES COST NOTHING AND DISCLOSE NOTHING NEW. `$products` here IS
 * `$cartlist['product_list']`, which the same handler produced moments earlier and which already
 * carries each line's `name` — so naming them is echoing back a field from the caller's own
 * payload, with no extra query and no new channel. Measured separately: those names are already
 * served to the same token holder through the store's own slug on `product-detail`.
 *
 * THE SUBSTRING `item this store does not sell` IS LOAD-BEARING AND IS KEPT. Eight assertions in
 * `ForeignProductCartChainTest` match on it, including the gateway arm this workstream must not
 * edit. A premise case below pins it at all three sites, so the wording can grow but not drift
 * away from the suite that depends on it.
 */
class ForeignBasketRefusalNamesTheLineTest extends SecurityTestCase
{
    use RefreshDatabase;

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

        $planId = DB::table('plans')->insertGetId([
            'name' => 'g280b-plan-' . uniqid(), 'price' => 0, 'themes' => '[]',
            'storage_limit' => '100', 'shipping_method' => 'off',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $merchant->plan_id = $planId;
        $merchant->save();
        \Illuminate\Support\Facades\Cache::forget('admin_details');

        $shippingId = (int) DB::table('shippings')->insertGetId([
            'name' => 'standard', 'slug' => 'standard-' . uniqid(),
            'store_id' => $store->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$merchant->fresh(), $store, $shippingId];
    }

    private function productIn($store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'g280b-' . uniqid(), 'description' => 'x',
            'product_stock' => 50, 'track_stock' => 0, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1, 'price' => '10.00', 'sale_price' => '10.00',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function shopperOf($store): Customer
    {
        return Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'g280b-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('not-a-real-password'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);
    }

    private function line($store, Product $product, Customer $customer): Cart
    {
        return Cart::forceCreate([
            'customer_id' => $customer->id, 'product_id' => $product->id, 'variant_id' => 0,
            'qty' => 1, 'price' => 0, 'store_id' => $store->id,
        ]);
    }

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

    /** A store-A basket holding one of store A's products and one of store B's. */
    private function mixedBasket(): array
    {
        [, $storeA, $shippingId] = $this->tenant();
        [, $storeB] = $this->tenant();

        $shopper = $this->shopperOf($storeA);
        $own = $this->productIn($storeA, 'G280B-OWN-ITEM');
        $foreign = $this->productIn($storeB, 'G280B-FOREIGN-ITEM');

        $this->line($storeA, $own, $shopper);
        $foreignLine = $this->line($storeA, $foreign, $shopper);

        return [$storeA, $shopper, $own, $foreign, $foreignLine, $shippingId];
    }

    private function hit($store, string $path, Customer $shopper, array $extra = [])
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer ' . $shopper->createToken('t' . uniqid())->plainTextToken,
        ])->postJson('/api/' . $store->slug . '/' . $path, $extra + [
            'customer_id' => $shopper->id,
            'billing_info' => $this->billing(),
            'payment_type' => 'cod',
        ]);
    }

    // ────────────────────────────────────────────────────────────────── it names the line

    public function test_place_order_names_the_line_it_refuses(): void
    {
        [$storeA, $shopper, , $foreign, $foreignLine, $shippingId] = $this->mixedBasket();

        $response = $this->hit($storeA, 'place-order', $shopper, [
            'delivery_id' => $shippingId, 'method_id' => $shippingId,
        ]);

        $message = (string) ($response->json('data.message') ?? '');

        $this->assertSame(0, (int) $response->json('status'),
            'the basket is no longer refused — (B) must not change WHAT is refused');
        $this->assertStringNotContainsString('Product not found', $message,
            'place-order still says "Product not found ." about a product that exists, which sends '
            . 'the shopper and the merchant looking for a deletion that never happened');
        $this->assertStringContainsString('G280B-FOREIGN-ITEM', $message,
            'the refusal does not name the line at fault, so the shopper has no way to find it');
        $this->assertStringNotContainsString('G280B-OWN-ITEM', $message,
            'the refusal names a line that is perfectly fine');

        $flagged = $response->json('data.unavailable_products');
        $this->assertIsArray($flagged, 'the refusal carries no structured data for a client to act on');
        $this->assertCount(1, $flagged);
        $this->assertSame((int) $foreign->id, (int) $flagged[0]['product_id']);
        $this->assertSame((int) $foreignLine->id, (int) $flagged[0]['cart_id']);

        /* AND NOTHING ABOUT THE REFUSAL ITSELF MOVED. */
        $this->assertNull(DB::table('orders')->where('customer_id', $shopper->id)->first(),
            'an order was written, so (B) changed what is refused');
        $this->assertSame(50, (int) DB::table('products')->where('id', $foreign->id)->value('product_stock'),
            "another merchant's stock moved");
    }

    public function test_order_save_names_the_line_and_is_translated(): void
    {
        [$storeA, $shopper, , , , $shippingId] = $this->mixedBasket();

        $response = $this->hit($storeA, 'order-save', $shopper, [
            'delivery_id' => $shippingId, 'method_id' => $shippingId,
        ]);

        $message = (string) ($response->json('data.message') ?? '');

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertStringContainsString('G280B-FOREIGN-ITEM', $message);
        $this->assertStringNotContainsString('Product not found', $message);
        $this->assertNull(DB::table('orders')->where('customer_id', $shopper->id)->first());

        /*
         * AND IT GOES THROUGH `__()`. `ordersave`'s refusal was a bare English literal with no
         * translation call at all, on a bilingual storefront. Driven by locale rather than by
         * reading the source, because a `__()` around an undeclared key returns the key and would
         * look identical.
         */
        app()->setLocale('ar');
        $arabic = __('Your cart contains an item this store does not sell: :products', ['products' => 'X']);
        app()->setLocale('en');

        $this->assertStringNotContainsString('Your cart contains', $arabic,
            'the refusal key is not declared in the Arabic catalogue, so an Arabic shopper reads '
            . 'English');
    }

    public function test_payment_sheet_names_the_line_it_refuses(): void
    {
        [$storeA, $shopper, , , , ] = $this->mixedBasket();

        $response = $this->hit($storeA, 'payment-sheet', $shopper);

        $message = (string) ($response->json('data.message') ?? '');

        $this->assertSame(0, (int) $response->json('status'));
        $this->assertStringContainsString('G280B-FOREIGN-ITEM', $message,
            'payment-sheet refuses without naming the line');
        $this->assertIsArray($response->json('data.unavailable_products'));
    }

    /**
     * THE PREMISE THE REST OF THE SUITE DEPENDS ON. Eight assertions in
     * `ForeignProductCartChainTest` match `item this store does not sell` as a SUBSTRING, including
     * on the gateway arm this workstream must not edit. The wording may grow; it may not drift away
     * from them.
     */
    public function test_all_three_refusals_keep_the_substring_the_suite_matches(): void
    {
        foreach (['place-order', 'order-save', 'payment-sheet'] as $path) {
            [$storeA, $shopper, , , , $shippingId] = $this->mixedBasket();

            $response = $this->hit($storeA, $path, $shopper, [
                'delivery_id' => $shippingId, 'method_id' => $shippingId,
            ]);

            $this->assertStringContainsString(
                'item this store does not sell',
                (string) ($response->json('data.message') ?? ''),
                $path . ' no longer contains the substring eight assertions in '
                . 'ForeignProductCartChainTest match on'
            );
        }
    }

    /** CONTROL: a basket of only this store's products still orders, so the refusal is targeted. */
    public function test_a_clean_basket_still_orders(): void
    {
        [, $storeA, $shippingId] = $this->tenant();
        $shopper = $this->shopperOf($storeA);
        $this->line($storeA, $this->productIn($storeA, 'G280B-CLEAN'), $shopper);

        $response = $this->hit($storeA, 'place-order', $shopper, [
            'delivery_id' => $shippingId, 'method_id' => $shippingId,
        ]);

        $this->assertSame(1, (int) $response->json('status'),
            'a clean basket is now refused, so the refusal has stopped being targeted');
        $this->assertNotNull(DB::table('orders')->where('customer_id', $shopper->id)->first());
    }
}
