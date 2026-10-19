<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-02 / S37-2 — the order-return path that restocks any store's inventory.
 * =============================================================================================
 *
 * `OrderController::order_return` (`:651`) passes three request fields straight into
 * `Order::product_return()` with **no permission check and no ownership predicate** — it sits in
 * the `auth` group and nothing else. Its own sibling eleven lines below,
 * `order_return_request` (`:669`), already carries `$this->authorize('updateStatus', $order)`
 * plus an idempotency guard, added by GATE C. The two ends of the same workflow diverged.
 *
 * `Order::product_return()` (`app/Models/Order.php:637`) then:
 *   - `Order::find($order_id)` — `Order` is NOT in `config/tenancy.php`'s `scoped_models`, so
 *     this reaches every tenant;
 *   - `$Order->delivered_status` on the result with no null check — a 500 on an absent id;
 *   - `ProductVariant::where('product_id', $product_id)->where('id', $variant_id)` (`:664`) —
 *     unscoped, and `$product_id`/`$variant_id` come from REQUEST INPUT rather than from the
 *     order's own `product_json`;
 *   - `$ProductStock->stock += $product['qty']` (`:666`) and `$Order->save()` (`:674`).
 *
 * A PRIOR SESSION MEASURED THIS: a store-A merchant posting store B's `order_id` moved B's
 * variant stock 50 -> 59 and marked B's order returned, then 500'd AFTER the writes landed. This
 * file is the regression test for that, and it asserts through raw queries — `Product` is
 * `Cachable`, and an Eloquent read in a sibling package reported a hijack that had never reached
 * the database (see `WoocomProductOwnershipTest`).
 *
 * A SECOND DEFECT, FOUND READING FOR THIS FIX AND PINNED BELOW. At `:658` the loop variable
 * `$product` — the order LINE, an array — is REASSIGNED to the result of
 * `Product::find($product_id)`. The very next line reads `$product['qty']`, which on an Eloquent
 * model resolves the `qty` ATTRIBUTE, and `products` has no such column. So the NON-VARIANT
 * branch adds `null` and restocks nothing, while the variant branch (`:663-669`, where
 * `$product` is not shadowed) restocks for real. That is why the measured reproduction moved a
 * VARIANT's stock. The shadowing is a live bug in its own right: a legitimate non-variant return
 * silently fails to restock.
 */
class OrderReturnOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function productIn($store, string $name, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 1,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** A NON-variant product, for the branch the variant tests never reach. */
    private function simpleProductIn($store, string $name, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    private function productStock(int $productId): int
    {
        return (int) DB::table('products')->where('id', $productId)->value('product_stock');
    }

    private function variantFor(Product $product, int $stock): int
    {
        return DB::table('product_variants')->insertGetId([
            'product_id' => $product->id, 'variant' => 'red', 'sku' => 'v-' . uniqid(),
            'price' => 10, 'stock' => $stock, 'store_id' => $product->store_id,
            'variation_option' => 'enabled,manage_stock', 'stock_status' => 'in_stock',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A delivered order carrying one returnable line.
     *
     * `delivered_status = 1` is required or `product_return()` refuses before anything else, and
     * a test that skipped it would pass against unfixed code because the delivery check stopped
     * it — not ownership. `'return' => 0` is present on the line because `:684` reads
     * `$product['return']` unconditionally in its second pass.
     */
    private function deliveredOrder($store, Product $product, int $variantId, int $qty)
    {
        return $this->makeOrder($store, [
            'delivered_status' => 1,
            'product_json' => json_encode([[
                'product_id' => $product->id,
                'variant_id' => $variantId,
                'qty' => $qty,
                'final_price' => 90,
                'return' => 0,
            ]]),
        ]);
    }

    private function variantStock(int $variantId): int
    {
        return (int) DB::table('product_variants')->where('id', $variantId)->value('stock');
    }

    private function orderJson(int $orderId): string
    {
        return (string) DB::table('orders')->where('id', $orderId)->value('product_json');
    }

    /**
     * A MERCHANT CANNOT RESTOCK ANOTHER STORE'S INVENTORY BY POSTING THEIR ORDER ID.
     *
     * The principal is legitimate: merchant A, signed in, holding `Manage Order` on their own
     * store. Only the posted `order_id` belongs to someone else.
     */
    public function test_a_merchant_cannot_return_another_stores_order(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $victimProduct = $this->productIn($storeB, 'victim-returnable', 50);
        $victimVariant = $this->variantFor($victimProduct, 50);
        $victimOrder = $this->deliveredOrder($storeB, $victimProduct, $victimVariant, 9);

        $stockBefore = $this->variantStock($victimVariant);
        $jsonBefore = $this->orderJson($victimOrder->id);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $victimOrder->id,
            'product_id' => $victimProduct->id,
            'variant_id' => $victimVariant,
        ]);

        $this->assertSame($stockBefore, $this->variantStock($victimVariant),
            'Another store\'s variant stock moved from ' . $stockBefore . ' to '
            . $this->variantStock($victimVariant) . '.');
        $this->assertSame($jsonBefore, $this->orderJson($victimOrder->id),
            'Another store\'s order was marked returned.');
        $this->assertNotSame(500, $response->status(),
            'The refusal must not be a crash. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * THE POSITIVE CONTROL: an own-store return still restocks.
     *
     * Without this every assertion above is satisfied by a handler that refuses everyone, and
     * "merchant returns keep working" is the task's stated compatibility requirement.
     */
    public function test_a_merchant_can_still_return_their_own_order(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $product = $this->productIn($storeA, 'own-returnable', 50);
        $variant = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeA, $product, $variant, 9);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant,
        ]);

        $this->assertSame(200, $response->status(),
            'The owner can no longer return their own order, so the refusal test above proves '
            . 'nothing. Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(59, $this->variantStock($variant),
            'The owner\'s own return did not restock, so the destructive path did not run at all '
            . 'and the negative test measures nothing.');
        $this->assertStringContainsString('"return":1', $this->orderJson($order->id),
            'The owner\'s own order was not marked returned.');
    }

    /**
     * A MERCHANT CANNOT RESTOCK A FOREIGN PRODUCT THROUGH THEIR OWN ORDER.
     *
     * This isolates the second acceptance criterion — pairing the Product/ProductVariant lookups
     * to the order — from the first. The order is the caller's, so the ownership check on the
     * ORDER passes; only a predicate tying the restocked variant to that order can refuse it.
     *
     * Reachable because `product_id` and `variant_id` come from request input, not from the
     * order's own `product_json`: the handler trusts the caller to name a line that matches, and
     * `product_json` is compared on those same two caller-supplied values (`:654`).
     */
    public function test_a_merchant_cannot_restock_a_foreign_variant_through_their_own_order(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $foreignProduct = $this->productIn($storeB, 'foreign-target', 50);
        $foreignVariant = $this->variantFor($foreignProduct, 50);

        // The caller's OWN order, but its line names store B's product and variant.
        $order = $this->deliveredOrder($storeA, $foreignProduct, $foreignVariant, 9);

        $stockBefore = $this->variantStock($foreignVariant);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $foreignProduct->id,
            'variant_id' => $foreignVariant,
        ]);

        $this->assertSame($stockBefore, $this->variantStock($foreignVariant),
            'A return on the caller\'s OWN order restocked another store\'s variant: '
            . $stockBefore . ' -> ' . $this->variantStock($foreignVariant)
            . '. The ownership check on the ORDER cannot see this -- only a predicate pairing '
            . 'the restocked row to the order can.');
        $this->assertNotSame(500, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * AN ANONYMOUS CALLER IS REFUSED, and the route's `auth` group is what does it.
     *
     * Pinned because the route carries only `themelanguage` of its own
     * (`routes/web.php:1263`) and inherits `auth` from the group opened at `:400`. A later
     * re-declaration of the same method+URI would silently drop that — CLAUDE.md records later
     * declarations replacing earlier ones — and this is what would notice.
     */
    public function test_an_anonymous_caller_cannot_drive_a_return(): void
    {
        [, $storeB] = $this->makeTenant();
        $product = $this->productIn($storeB, 'anon-target', 50);
        $variant = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeB, $product, $variant, 9);

        $stockBefore = $this->variantStock($variant);

        $this->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant,
        ]);

        $this->assertSame($stockBefore, $this->variantStock($variant),
            'An anonymous caller restocked a store\'s inventory.');
    }

    /**
     * A MERCHANT WITHOUT `Manage Order` IS REFUSED — the third acceptance criterion.
     *
     * The route had no permission check at all, only the auth group, so any authenticated user
     * could drive it. This is the test that distinguishes "authenticated" from "authorised".
     */
    public function test_a_merchant_without_manage_order_is_refused(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        // Deliberately NO grantPermission call.

        $product = $this->productIn($storeA, 'unpermitted-target', 50);
        $variant = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeA, $product, $variant, 9);

        $stockBefore = $this->variantStock($variant);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant,
        ]);

        $this->assertSame($stockBefore, $this->variantStock($variant),
            'A merchant holding no order permission restocked their own inventory. '
            . 'Authenticated is not authorised.');
        $this->assertNotSame(500, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * AN ABSENT ORDER ID IS A REFUSAL, NOT A CRASH.
     *
     * `product_return()` read `$Order->delivered_status` on the result of `Order::find()` with no
     * null check, so a nonexistent id was a 500. Pinned so the fix cannot regress into one.
     */
    public function test_an_absent_order_id_does_not_crash(): void
    {
        [$merchantA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => 999999,
            'product_id' => 1,
            'variant_id' => 1,
        ]);

        $this->assertNotSame(500, $response->status(),
            'An absent order id still crashes. Body: '
            . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * C1 — THE DECISION THIS WHOLE TASK TURNS ON, WHICH NOTHING ELSE PINNED.
     * ==========================================================================================
     *
     * Raised by adversarial review and it was right: in every other test the authorised caller's
     * `current_store` IS the order's store, so replacing `$Order->store_id` with
     * `getCurrentStore()` in `product_return()` left ALL SIX tests passing — while silently
     * restocking nothing for the platform operator, who is the one principal for whom the two
     * differ. `OrderPolicy::allow` short-circuits on `isPlatformAdmin` BEFORE `sameStore`
     * (`OrderPolicy.php:56`), so an operator may legitimately return an order in a store that is
     * not their `current_store` — the same argument already written for `restoreReturnedStock`
     * at `OrderController.php:810-820`.
     *
     * So this is the test that distinguishes "the order's store" from "the caller's store", and
     * it is the second time in this session a central design decision turned out to have no
     * coverage at all (see P1-SEC-02's owner-bounding branch).
     */
    public function test_the_operator_returning_a_merchants_order_still_restocks_that_merchant(): void
    {
        $operator = $this->makePlatformAdmin();
        // Store 1 must not be the operator's resolved store, or the two values coincide and this
        // measures nothing -- getCurrentStore() returns a hardcoded 1 for an operator with no
        // current_store (helper.php:403-404). Same trap as the P1-INV-08 operator tests.
        $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        $product = $this->productIn($storeB, 'operator-returnable', 50);
        $variant = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeB, $product, $variant, 9);

        $this->assertNotSame((int) $storeB->id, (int) getCurrentStore($operator->id),
            'The order store and the operator\'s resolved store coincide, so this test cannot '
            . 'distinguish the two readings.');

        $response = $this->actingAs($operator->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant,
        ]);

        $this->assertSame(200, $response->status(),
            'The operator cannot return a merchant\'s order at all. Body: '
            . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(59, $this->variantStock($variant),
            'The operator\'s return restocked NOTHING: the predicate is keyed on the CALLER\'s '
            . 'store rather than the ORDER\'s, so the authorised support workflow silently '
            . 'does nothing.');
    }

    /**
     * C2 — THE SHADOWING FIX, WHICH NO TEST REACHED.
     *
     * Raised by review: every other request in this file posts a non-zero `variant_id`, so the
     * `elseif ($variant_id == 0 || empty($variant_id))` branch never executed and reverting
     * `$line['qty']` to the shadowed `$product['qty']` failed nothing.
     *
     * The original wrote `$product = Product::find($product_id)` and then read `$product['qty']`
     * — by which point `$product` was the MODEL, so `qty` resolved as an attribute and `products`
     * has no such column. The branch added null and restocked NOTHING, which is why the
     * cross-tenant reproduction moved a VARIANT's stock rather than a product's.
     */
    public function test_a_non_variant_return_restocks_the_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $product = $this->simpleProductIn($storeA, 'non-variant-returnable', 50);
        $order = $this->deliveredOrder($storeA, $product, 0, 9);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => 0,
        ]);

        $this->assertSame(200, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
        $this->assertSame(59, $this->productStock($product->id),
            'A non-variant return restocked nothing. That is the shadowing bug: $product was '
            . 'reassigned to the Product model and $product[\'qty\'] then resolved a nonexistent '
            . 'attribute.');
    }

    /**
     * A NON-VARIANT RETURN CANNOT REACH ANOTHER STORE'S PRODUCT EITHER.
     *
     * The variant branch has its own cross-store case; this gives the branch fixed above the
     * same coverage, so the two are not asymmetric.
     */
    public function test_a_non_variant_return_cannot_restock_a_foreign_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $foreign = $this->simpleProductIn($storeB, 'foreign-non-variant', 50);
        $order = $this->deliveredOrder($storeA, $foreign, 0, 9);

        $response = $this->actingAs($merchantA->fresh())->post('/order-return', [
            'order_id' => $order->id,
            'product_id' => $foreign->id,
            'variant_id' => 0,
        ]);

        $this->assertSame(50, $this->productStock($foreign->id),
            'A return on the caller\'s own order restocked another store\'s product.');
        $this->assertNotSame(500, $response->status(),
            'Body: ' . substr((string) $response->getContent(), 0, 300));
    }

    /**
     * REPLAYING A RETURN CREDITS THE STOCK ONCE.
     *
     * Raised by review as PRE-EXISTING AND LIVE: nothing checked whether the line was already
     * returned, so each repeat call credited the stock again and subtracted `return_price` from
     * `final_price` again — unbounded inflation, and reachable by a CUSTOMER on their own
     * delivered order through `routes/api.php:110`. The sibling endpoint closed exactly this
     * primitive with `$previousReturnStatus`; the two ends are now consistent on replay as well
     * as on authorization.
     */
    public function test_replaying_a_return_does_not_credit_the_stock_twice(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Manage Order');

        $product = $this->productIn($storeA, 'replay-target', 50);
        $variant = $this->variantFor($product, 50);
        $order = $this->deliveredOrder($storeA, $product, $variant, 9);

        $payload = [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'variant_id' => $variant,
        ];

        $this->actingAs($merchantA->fresh())->post('/order-return', $payload);
        $this->assertSame(59, $this->variantStock($variant),
            'The first return did not restock, so the replay assertion below measures nothing.');

        $firstFinalPrice = (float) DB::table('orders')->where('id', $order->id)->value('final_price');

        $this->actingAs($merchantA->fresh())->post('/order-return', $payload);

        $this->assertSame(59, $this->variantStock($variant),
            'Replaying the return credited the stock a second time: 59 -> '
            . $this->variantStock($variant) . '. Unbounded inflation.');
        $this->assertSame($firstFinalPrice,
            (float) DB::table('orders')->where('id', $order->id)->value('final_price'),
            'Replaying the return reduced final_price again, driving the order total down '
            . 'without limit.');
    }
}
