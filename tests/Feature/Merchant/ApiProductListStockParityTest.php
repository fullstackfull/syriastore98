<?php

namespace Tests\Feature\Merchant;

use App\Models\Product;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-07-10 / WS K9 — the app can only agree with the web if the API tells it the threshold.
 * =================================================================================================
 *
 * THE WEB'S RULE, which the app now applies: `products.stock_status` is read ONLY when
 * `track_stock == 0`; for a tracked product the badge comes from
 * `$product->product_stock <= $settings['out_of_stock_threshold']`
 * (`resources/views/product/list.blade.php:115-129`).
 *
 * WHY THAT MATTERS: `products.stock_status` is written only from merchant input at create and
 * update and is **never recomputed when stock drains through an order**, so for a tracked product
 * it is stale by the first shipment. The app read it unconditionally and therefore called sold-out
 * products "In stock" while the web called them "Out of stock".
 *
 * `out_of_stock_threshold` IS A PER-STORE SETTING, not a constant — its default is `'0'`
 * (`Utility.php:113`) but a store may set any value, and the web honours whatever is set. So the
 * client cannot hardcode it, and this is the additive field that lets it match. The parity rule
 * itself is tested on the client side (`test/ui/stock_state_parity_test.dart`, 10 cases); this
 * pins the one thing only the server can provide.
 */
class ApiProductListStockParityTest extends SecurityTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // `productlist` reads `currency_rates`, which GATE F leaves unmanaged.
        $this->requireCurrencyRatesStandIn();
    }

    /** @return array{0: User, 1: Store} */
    private function merchantWithStore(): array
    {
        $merchant = $this->makeMerchant();
        $store = $this->makeStore($merchant, ['default_language' => 'en']);
        $merchant->current_store = $store->id;
        $merchant->save();

        $this->grantPermission(User::find($merchant->id), 'Manage Products');

        return [User::find($merchant->id), $store];
    }

    private function productList(User $user, Store $store): array
    {
        $response = $this->withHeader(
            'Authorization',
            'Bearer ' . $user->createToken('auth_token')->plainTextToken
        )->getJson('/api/admin/productlist?store_id=' . $store->id);

        $this->assertSame(200, $response->getStatusCode(),
            'premise: productlist must have answered. Body: '
            . substr((string) $response->getContent(), 0, 300));

        return $response->json();
    }

    /** The field exists, so the client has something to apply. */
    public function test_the_product_list_reports_the_out_of_stock_threshold(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->productIn($store, 'tracked');

        $body = $this->productList($merchant, $store);

        $this->assertArrayHasKey('out_of_stock_threshold', $body['data'] ?? [],
            'the app cannot match the web\'s out-of-stock rule without the per-store threshold, '
            . 'and hardcoding 0 would be wrong for any store that changed it');
        $this->assertIsNumeric($body['data']['out_of_stock_threshold']);
    }

    /**
     * AND IT IS THE STORE'S OWN VALUE, not the default. This is the assertion that would fail if
     * someone replaced the setting read with a constant, which is exactly the shortcut the field
     * exists to avoid.
     */
    public function test_the_threshold_is_the_stores_configured_value(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->productIn($store, 'tracked');

        \Illuminate\Support\Facades\DB::table('settings')->insert([
            'name' => 'out_of_stock_threshold',
            'value' => '7',
            'store_id' => $store->id,
            'created_by' => $store->created_by,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Cache::flush();

        $body = $this->productList(User::find($merchant->id), $store);

        $this->assertSame(7, (int) $body['data']['out_of_stock_threshold'],
            'the threshold was reported as the default rather than as this store\'s setting');
    }

    /** PREMISE: the fields the client's rule needs are all present on each row. */
    public function test_each_row_carries_what_the_rule_needs(): void
    {
        [$merchant, $store] = $this->merchantWithStore();
        $this->productIn($store, 'tracked');

        $row = $this->productList($merchant, $store)['data']['data'][0] ?? [];

        foreach (['product_stock', 'track_stock', 'variant_product', 'stock_status'] as $field) {
            $this->assertArrayHasKey($field, $row,
                'the client rule reads `' . $field . '` and the row does not carry it');
        }
    }

    private function productIn(Store $store, string $name): Product
    {
        return Product::forceCreate([
            'name' => $name, 'slug' => $name . '-' . uniqid(),
            'price' => 10, 'sale_price' => 10, 'cost_price' => 4,
            // The stale pair the defect turns on: no stock left, column still says in_stock.
            'product_stock' => 0, 'track_stock' => 1, 'variant_product' => 0,
            'stock_status' => 'in_stock', 'status' => 1,
            'store_id' => $store->id, 'created_by' => $store->created_by,
        ]);
    }
}
