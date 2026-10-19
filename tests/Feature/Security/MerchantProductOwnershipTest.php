<?php

namespace Tests\Feature\Security;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * P1-INV-01 / S37-1 — the merchant catalogue surface.
 * =============================================================================================
 *
 * `ProductController::update` takes a route-model-bound `Product $product` and the only
 * authorization in the method is `isAbleTo('Edit Products')` — which answers *may this user edit
 * products* and never *may this user edit THIS product*. `App\Models\Product` is not in
 * `config/tenancy.php`'s `scoped_models` and registers no effective global scope (CLAUDE.md), so
 * the binding resolves across every tenant.
 *
 * Its sibling `destroy()` already carries the ownership check this adds, with the same shape:
 * `isAbleTo` first, platform operator exempt via `UserPolicy::PLATFORM_ADMIN`, then
 * `getCurrentStore()` against `$product->store_id`. `update()`, `edit()` and `show()` did not.
 *
 * WHY THE PAYLOAD CARRIES THE FIELDS IT DOES. Omit any one of them and this file stops measuring
 * ownership, so the set is load-bearing and is listed in full:
 *
 *   - `name`, `category_id`, `status`, `variant_product`, `price`, `cost_price` are required by
 *     the validator (`ProductController.php:1051-1060`). Omit one and `update()` returns its
 *     `$msg` error array with NO write, so a cross-store test passes because validation refused,
 *     not because ownership did. `cost_price` is the trap of the group: it is **required and
 *     never written** — `update()` has no `cost_price` assignment at all, and
 *     `products.cost_price` has no migration (a known GATE F gap, P1-MIG-03).
 *   - `stock_status` (:1146), `trending` (:1207) and `track_stock` (:1211/:1215) are written
 *     STRAIGHT THROUGH from request input into **NOT NULL** columns
 *     (`create_products_table.php:39`, `:26`, `:29` — and `track_stock` has no default at all).
 *     Omit one and the UPDATE sets NULL, which SQLite REFUSES with a constraint violation: the
 *     save fails, the victim is unchanged, and a refusal test passes because the write died.
 *
 * MySQL with `'strict' => false` (`config/database.php:55` — the production setting) COERCES those
 * NULLs instead, so the write lands there. **Production is more exposed than this test database**,
 * which is the reverse of the direction this project is usually bitten in. Measured both ways in
 * `tests/Support/Security/bin/verify-product-ownership-on-mysql.php`, which also shows the
 * cross-store write completing on MySQL with the two NOT NULL fields OMITTED — the payload that
 * cannot complete on SQLite at all.
 *
 * The third field set was named by an adversarial review of this file: the first version of this
 * docblock said "these three" and listed two.
 */
class MerchantProductOwnershipTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** A category in the given store, because `category_id` is a required field. */
    private function categoryIn($store): int
    {
        return DB::table('categories')->insertGetId([
            'name' => 'cat-' . uniqid(), 'slug' => 'cat-' . uniqid(),
            'status' => 1, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function productIn($store, string $name, int $stock = 50): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => 'p-' . uniqid(),
            'product_stock' => $stock, 'track_stock' => 1, 'variant_product' => 0,
            'price' => 100.00, 'sale_price' => 90.00, 'status' => 1, 'trending' => 0,
            'stock_status' => 'in_stock',
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }

    /** The payload the product edit form posts for a non-variant product. */
    private function payload(int $categoryId, string $name, int $stock): array
    {
        return [
            'name' => $name,
            'category_id' => $categoryId,
            'status' => 1,
            'variant_product' => 0,
            'price' => 100.00,
            'sale_price' => 90.00,
            'cost_price' => 40.00,
            'product_stock' => $stock,
            'track_stock' => 1,
            'trending' => 0,
            // `update()` writes these three straight through to NOT NULL columns
            // (:1066 stock_status, :1127 trending). Omitting them makes the SAVE FAIL on SQLite
            // with a constraint violation -- which would make the refusal test above pass because
            // the write died, not because it was refused. MySQL with `strict => false` coerces the
            // NULLs, so production is MORE exposed than this test database. Measured, both ways.
            'stock_status' => 'in_stock',
        ];
    }

    private static function fresh(Product $p): Product
    {
        return Product::withoutGlobalScopes()->findOrFail($p->id);
    }

    /**
     * A MERCHANT CANNOT REWRITE OR RESTOCK ANOTHER STORE'S PRODUCT.
     *
     * The principal is entirely legitimate: merchant A, signed in, with `Edit Products` on their
     * own store. Only the bound id belongs to someone else.
     */
    public function test_a_merchant_cannot_update_another_stores_product(): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-original', 50);
        $categoryB = $this->categoryIn($storeB);

        $this->actingAs($merchantA)
            ->put('/product/' . $victim->id, $this->payload($categoryB, 'HIJACKED', 7));

        $after = self::fresh($victim);

        $this->assertSame('victim-original', $after->name,
            'Merchant A rewrote another store\'s product name.');
        $this->assertSame(50, (int) $after->product_stock,
            'Merchant A restocked another store\'s product.');
        $this->assertSame((int) $storeB->id, (int) $after->store_id,
            'The product changed hands.');
    }

    /**
     * THE POSITIVE CONTROL. Without it, the test above is satisfied by a handler that refuses
     * everything — or, worse, by one whose write silently fails on this database engine.
     */
    public function test_a_merchant_can_still_update_their_own_product(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $own = $this->productIn($storeA, 'own-original', 50);
        $categoryA = $this->categoryIn($storeA);

        $response = $this->actingAs($merchantA)
            ->put('/product/' . $own->id, $this->payload($categoryA, 'own-renamed', 12));

        $after = self::fresh($own);

        $this->assertSame('own-renamed', $after->name,
            'A merchant can no longer rename their OWN product, so the refusal test above proves '
            . 'nothing. Response: ' . $response->getStatusCode() . ' ' . substr($response->getContent(), 0, 300));
        $this->assertSame(12, (int) $after->product_stock,
            'A merchant can no longer restock their own product.');
    }
    /**
     * AND THE SAME BINDING IS NOT A READ LEAK EITHER.
     *
     * `edit()` and `show()` had no ownership check at all, so another store's product rendered
     * into this merchant's form — name, prices, cost, stock and variant rows. A read rather than
     * a write, through the identical binding.
     *
     * @dataProvider readRouteProvider
     */
    public function test_a_merchant_cannot_read_another_stores_product_form(string $suffix): void
    {
        [$merchantA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');

        $victim = $this->productIn($storeB, 'victim-secret-name', 50);

        $response = $this->actingAs($merchantA)->get('/product/' . $victim->id . $suffix);

        $this->assertSame(302, $response->status(),
            'The refusal must be the handler\'s own redirect. A 500 would also be "not 200", and '
            . 'a 500 for every caller would satisfy this assertion AND the positive control below '
            . '-- the exact shape CLAUDE.md records as a vacuous test. Asserting 302 excludes it.');
        $this->assertStringNotContainsString('victim-secret-name', (string) $response->getContent(),
            'Another store\'s product name leaked into the response body.');
    }

    public static function readRouteProvider(): array
    {
        return ['show' => [''], 'edit' => ['/edit']];
    }

    /**
     * THE POSITIVE CONTROL FOR THE READ ROUTES. Without it, the test above is satisfied by a
     * handler that refuses its owner too.
     */
    public function test_a_merchant_can_still_open_their_own_product_form(): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        $this->grantPermission($merchantA, 'Edit Products');
        $own = $this->productIn($storeA, 'own-visible-name', 50);

        $response = $this->actingAs($merchantA)->get('/product/' . $own->id . '/edit');

        $this->assertSame(200, $response->status(),
            'A merchant can no longer open their OWN product form, so the read-refusal test above '
            . 'proves nothing. Asserting 200 rather than "not 302" is what excludes a 500 for '
            . 'everyone. Session error: ' . json_encode(session('error')));
        $this->assertStringContainsString('own-visible-name', (string) $response->getContent(),
            'The form rendered without the product in it, so the 200 does not prove the owner can '
            . 'actually read their own product.');
    }
}
