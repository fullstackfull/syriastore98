<?php

namespace Tests\Feature\Security;

use App\Models\Category;
use App\Models\Customer;
use App\Models\DeliveryBoy;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * GAP-235 (a) — `releted-product` RESOLVES ANOTHER TENANT'S PRODUCT.
 * =============================================================================================
 *
 *     $product = Product::find($product_id);           // ApiController, releted_product
 *
 * `Product` is NOT in `config/tenancy.php`'s `scoped_models`, so the `StoreScope` it carries
 * through `BelongsToStore` is INERT and adds no predicate for ANY principal. The handler resolves
 * `$store` from the slug and uses it only for the related-products query underneath.
 *
 * WHAT IS ACTUALLY DISCLOSED, STATED PRECISELY, because overstating it would be the same error as
 * ignoring it. The list the handler returns IS already store-scoped
 * (`Product::where('store_id', $store->id)`), so no foreign ROW comes back. What comes back is:
 *
 *   1. AN EXISTENCE ORACLE over every tenant's product ids. A product id that belongs to another
 *      store answers `status 1` / "Related products get successfully.", and an id that belongs to
 *      nobody answers `status 0` / "Related products not found." -- so the endpoint enumerates
 *      which product ids exist on the platform. That is what this test measures, by requiring the
 *      two answers to be INDISTINGUISHABLE, which is the precedent `order_detail` and
 *      `delete_address` set in this same controller.
 *
 *   2. THE FOREIGN ROW'S `category_id` STEERING THE CALLER'S OWN LIST: the query takes
 *      `where('category_id', $product->category_id)` from the foreign row, and when that is NULL
 *      Illuminate turns it into `whereNull`, so store A's uncategorised catalogue is returned for a
 *      product store A does not sell. Within-tenant content, but the wrong answer, and it is what
 *      makes the oracle loud rather than subtle (a populated list rather than an empty one).
 *
 * Both routes (`releted-product` and `releted-product-guest`, routes/api.php:155-156) point at the
 * SAME method, so one predicate closes both -- asserted here rather than assumed, because a later
 * re-declaration of either route could split them.
 */
class StorefrontRelatedProductTenancyTest extends SecurityTestCase
{
    use RefreshDatabase;

    private function productIn($store, string $name, $categoryId = null): Product
    {
        return Product::forceCreate([
            'name' => $name,
            'slug' => 'gap235a-' . uniqid(),
            'description' => 'x',
            'detail' => $name . '-DETAIL',
            'category_id' => $categoryId,
            'product_stock' => 3,
            'track_stock' => 1,
            'variant_product' => 0,
            'stock_status' => 'in_stock',
            'trending' => 0,
            'status' => 1,
            'price' => 10,
            'sale_price' => 10,
            'store_id' => $store->id,
            'created_by' => $store->created_by,
        ]);
    }

    private function categoryIn($store, string $name): Category
    {
        return Category::forceCreate([
            'name' => $name,
            'slug' => 'gap235a-cat-' . uniqid(),
            'parent_id' => 0,
            'trending' => 0,
            'status' => 1,
            'store_id' => $store->id,
        ]);
    }

    /** @return array<string,string> label => bearer token, all three tokenable classes */
    private function threeTokensFor($merchant, $store): array
    {
        $customer = Customer::forceCreate([
            'first_name' => 'Shopper', 'last_name' => 'A',
            'email' => 'gap235a-shopper-' . uniqid() . '@example.test',
            'mobile' => '555' . random_int(100000, 999999),
            'password' => bcrypt('x'), 'store_id' => $store->id,
            'status' => 1, 'register_type' => 'email',
        ]);

        $driver = DeliveryBoy::forceCreate([
            'name' => 'Driver', 'email' => 'gap235a-drv-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_by' => $merchant->id, 'store_id' => $store->id,
        ]);

        return [
            'customer' => $customer->createToken('t')->plainTextToken,
            'merchant' => $merchant->createToken('t')->plainTextToken,
            'driver' => $driver->createToken('t')->plainTextToken,
        ];
    }

    private function related($store, string $path, $productId, string $bearer)
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $bearer])
            ->postJson('/api/' . $store->slug . '/' . $path, ['product_id' => $productId]);
    }

    /** @return array<string,array<int,string>> both routes, which point at the same method */
    public static function bothRoutes(): array
    {
        return [
            'plain' => ['releted-product'],
            'guest' => ['releted-product-guest'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_foreign_product_id_is_answered_exactly_like_one_that_does_not_exist(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();
        [, $storeB] = $this->makeTenant();

        /* Store A HAS an uncategorised product, so the foreign row's NULL category_id has
           something of store A's to select -- which is what makes the oracle a populated list
           rather than an empty one. Without this the two answers could coincide for the wrong
           reason and the case would be vacuous. */
        $ownUncategorised = $this->productIn($storeA, 'GAP235A-OWN-UNCATEGORISED');
        $foreign = $this->productIn($storeB, 'GAP235A-FOREIGN-PRODUCT');

        $row = DB::table('products')->where('id', $foreign->id)->first();
        $this->assertSame((int) $storeB->id, (int) $row->store_id, 'premise: the product is not store B\'s');
        $this->assertNull($row->category_id, 'premise: the foreign product is categorised, so the whereNull arm is not exercised');
        $this->assertSame((int) $storeA->id, (int) DB::table('products')->where('id', $ownUncategorised->id)->value('store_id'));

        $absentId = ((int) DB::table('products')->max('id')) + 5000;
        $this->assertNull(DB::table('products')->where('id', $absentId)->first(), 'premise: the "absent" id exists');

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $foreignAnswer = $this->related($storeA, $path, $foreign->id, $bearer);
            $absentAnswer = $this->related($storeA, $path, $absentId, $bearer);

            $this->assertSame(200, $foreignAnswer->status(), $path . ' did not answer for a ' . $label . ' token');
            $this->assertSame(200, $absentAnswer->status());

            $this->assertSame(
                [(int) $absentAnswer->json('status'), (string) $absentAnswer->json('message')],
                [(int) $foreignAnswer->json('status'), (string) $foreignAnswer->json('message')],
                sprintf(
                    '%s told a %s token of store A that store B\'s product id EXISTS: it answered '
                    . 'differently for a foreign id than for an id that belongs to nobody, which '
                    . 'enumerates every tenant\'s product ids. Product is NOT in '
                    . 'config/tenancy.php scoped_models, so Product::find() reaches every '
                    . 'catalogue and the handler\'s own predicate is the only control.',
                    $path, $label
                )
            );

            /* AND the foreign row's NULL category must not have steered store A's own list. */
            $this->assertStringNotContainsString('GAP235A-OWN-UNCATEGORISED', (string) $foreignAnswer->getContent(),
                $path . ' built a related-products list for a product store A does not sell, for a '
                . $label . ' token');
        }
    }

    /** CONTROL: a store's own product still gets its own related products, for all three tokens. */
    #[\PHPUnit\Framework\Attributes\DataProvider('bothRoutes')]
    public function test_a_stores_own_product_still_gets_its_related_products(string $path): void
    {
        [$merchantA, $storeA] = $this->makeTenant();

        $category = $this->categoryIn($storeA, 'GAP235A-OWN-CATEGORY');
        $asked = $this->productIn($storeA, 'GAP235A-OWN-ASKED', $category->id);
        $sibling = $this->productIn($storeA, 'GAP235A-OWN-SIBLING', $category->id);

        foreach ($this->threeTokensFor($merchantA, $storeA) as $label => $bearer) {
            $response = $this->related($storeA, $path, $asked->id, $bearer);

            $this->assertSame(200, $response->status());
            $this->assertSame(1, (int) $response->json('status'),
                $path . ' refused a legitimate same-store product for a ' . $label
                . ' token, which is a regression and not a fix');
            $this->assertStringContainsString('GAP235A-OWN-SIBLING', (string) $response->getContent(),
                $path . ' dropped the store\'s own related product for a ' . $label . ' token');
            $this->assertStringNotContainsString('GAP235A-OWN-ASKED', (string) $response->getContent(),
                $path . ' listed the asked-for product as its own related product');
            $this->assertSame((int) $sibling->id, (int) data_get($response->json(), 'data.data.0.id'));
        }
    }

    public function test_both_routes_still_refuse_a_caller_with_no_token(): void
    {
        [, $storeA] = $this->makeTenant();

        foreach (['releted-product', 'releted-product-guest'] as $path) {
            $response = $this->postJson('/api/' . $storeA->slug . '/' . $path, ['product_id' => 1]);

            $this->assertSame(401, $response->status(), $path . ' is anonymous');
        }
    }
}
