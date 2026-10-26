<?php

namespace Tests\Feature\Merchant;

use App\DataTables\ProductDataTable;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Security\SecurityTestCase;

/**
 * P2-05-3 — the stock reports must answer "is this low stock" the same way the product list does.
 * =============================================================================================
 *
 * The slice's acceptance is that stock figures carry THEIR RULE, and the rule is the product
 * list's: `ProductDataTable::trackedStockState()`, shared with the list's own filter so the badge
 * and the filter cannot disagree (P2-02-3, P2-03-9). It reads
 *
 *     $low = (int) ($lowThreshold ?: 0);
 *     if ($low === 0) { $low = $fallback; }      // the store's out_of_stock_threshold
 *     return $stock <= 0 ? 'out' : ($stock <= $low ? 'low' : 'in');
 *
 * so a per-product `low_stock_threshold` of 0 means UNSET and falls back to the store setting, and
 * a product is `out` only at zero.
 *
 * THE REPORTS DID NOT. Measured:
 *
 *   ReportController::getLowStockProducts   `$product->product_stock <= $product->low_stock_threshold`
 *                                           -- no fallback, so an unset threshold can never be low
 *   ReportController::getOutOfStockProducts `$product->product_stock <= $settings['out_of_stock_threshold']`
 *                                           -- the store setting used as the OUT threshold
 *
 * Two divergences, in OPPOSITE directions, which is why one product exhibits all three at once.
 * With `low_stock_threshold = 0`, `product_stock = 3` and a store `out_of_stock_threshold` of 5:
 *
 *   the product list     effective low = 5, stock 3 > 0 -> LOW STOCK
 *   the Low Stock report 3 <= 0 is false                -> ABSENT
 *   the Out of Stock     3 <= 5 is true                 -> PRESENT, as out of stock
 *
 * So the merchant reads "Low stock" on the catalogue, finds the product missing from the low-stock
 * report, and finds it listed as out of stock in the next tab — for one row of one table.
 *
 * WHAT THIS FILE ASSERTS, AND WHY IT IS BUILT THIS WAY. It drives the real report endpoints and
 * compares their membership against `ProductDataTable::trackedStockState()` CALLED DIRECTLY, rather
 * than against a second copy of the rule written here. A test that restated the predicate would
 * pass while both sides were wrong together, which is the failure this whole slice is about.
 */
class StockReportPredicateTest extends SecurityTestCase
{
    use RefreshDatabase;

    private const AJAX = [
        'X-Requested-With' => 'XMLHttpRequest',
        'Accept' => 'application/json',
    ];

    private function makeCategory(int $storeId): int
    {
        return (int) \DB::table('categories')->insertGetId([
            'name' => 'Textiles', 'slug' => 'textiles-' . uniqid(),
            'trending' => 0, 'status' => 1, 'store_id' => $storeId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0:\App\Models\User,1:\App\Models\Store}
     */
    private function tenantWithThreshold(int $storeThreshold): array
    {
        [$merchant, $store] = $this->makeTenant();
        $this->grantPermission($merchant, 'Manage Stock Reports');
        $this->grantPermission($merchant, 'Manage Reports');

        \DB::table('settings')->insert([
            'name' => 'out_of_stock_threshold', 'value' => (string) $storeThreshold,
            'created_by' => $merchant->id, 'store_id' => $store->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        \Cache::forget('admin_details');

        return [$merchant, $store];
    }

    private function product(int $storeId, int $createdBy, int $stock, int $ownThreshold): Product
    {
        return Product::forceCreate([
            'name' => 'Scarf-' . uniqid(), 'slug' => 'scarf-' . uniqid(),
            'description' => 'x', 'price' => 10,
            'track_stock' => 1, 'trending' => 0, 'stock_status' => 'in_stock',
            'product_stock' => $stock, 'low_stock_threshold' => $ownThreshold,
            'product_type' => null, 'status' => 1, 'variant_product' => 0,
            'category_id' => $this->makeCategory($storeId),
            'store_id' => $storeId, 'created_by' => $createdBy,
        ]);
    }

    /** @return list<string> the product names the endpoint returned */
    private function reportNames(\App\Models\User $merchant, string $uri): array
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson($uri . '?draw=1&start=0&length=50');

        $response->assertOk();
        $payload = $response->json();

        // PREMISE: the AJAX leg really answered. Without `draw` this is a view or a redirect, and
        // an empty membership list would then "agree" with anything.
        $this->assertArrayHasKey('draw', $payload,
            'the report did not serve its datatables leg: ' . substr(json_encode($payload), 0, 200));

        return array_map(
            static fn (array $row): string => (string) ($row['product_name'] ?? ''),
            $payload['data'] ?? []
        );
    }

    /**
     * The authority, called rather than restated.
     */
    private function listVerdict(Product $product, int $storeThreshold): string
    {
        return ProductDataTable::trackedStockState(
            (int) $product->product_stock,
            $product->low_stock_threshold,
            $storeThreshold
        );
    }

    /**
     * The three-way contradiction, as one case, because it is one product.
     */
    public function test_a_product_the_list_calls_low_is_in_the_low_report_and_not_the_out_report(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: 3, ownThreshold: 0);

        // PREMISE, stated against the authority: this fixture really is a LOW product by the
        // product list's own rule. If this is not 'low', the case is measuring nothing.
        $this->assertSame('low', $this->listVerdict($product, 5),
            'premise: the product list must call this product low, or this case proves nothing');

        $low = $this->reportNames($merchant, '/stock-report/low-stock');
        $out = $this->reportNames($merchant, '/stock-report/out-stock');

        $this->assertContains($product->name, $low,
            'The product list calls this product LOW STOCK and the low-stock report omits it. Its '
            . 'per-product low_stock_threshold is 0, which ProductDataTable::trackedStockState() '
            . 'treats as UNSET and falls back to the store threshold; the report compares against '
            . 'the 0 directly, so an unset threshold can never be low there.');

        $this->assertNotContains($product->name, $out,
            'The product list calls this product LOW STOCK and the out-of-stock report lists it as '
            . 'OUT. The report uses the store out_of_stock_threshold as the out boundary, while the '
            . 'list is out only at zero. The merchant gets two different answers for one row.');
    }

    /**
     * And a product that really is out must appear in the out report and not the low one, so the
     * fix cannot be "put everything in both".
     */
    public function test_a_product_the_list_calls_out_is_in_the_out_report_only(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: 0, ownThreshold: 0);

        $this->assertSame('out', $this->listVerdict($product, 5), 'premise: this must be out');

        $low = $this->reportNames($merchant, '/stock-report/low-stock');
        $out = $this->reportNames($merchant, '/stock-report/out-stock');

        $this->assertContains($product->name, $out, 'a product at zero is missing from the out report');
        $this->assertNotContains($product->name, $low, 'a product at zero is listed as merely low');
    }

    /**
     * A healthy product must be in neither, which is the assertion that stops a fix that simply
     * widens both reports.
     */
    public function test_a_product_the_list_calls_in_stock_is_in_neither_report(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: 40, ownThreshold: 0);

        $this->assertSame('in', $this->listVerdict($product, 5), 'premise: this must be in stock');

        $this->assertNotContains($product->name, $this->reportNames($merchant, '/stock-report/low-stock'),
            'a healthy product is reported as low');
        $this->assertNotContains($product->name, $this->reportNames($merchant, '/stock-report/out-stock'),
            'a healthy product is reported as out');
    }

    /**
     * A per-product threshold that IS set must win over the store fallback, in the report exactly
     * as in the list. This is the case that would still pass if the fix hard-coded the fallback.
     */
    public function test_a_products_own_threshold_wins_over_the_store_fallback(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(2);
        // Own threshold 10 is ABOVE the store's 2, so 6 is low by the product's own rule and would
        // be 'in' if the report ignored it.
        $product = $this->product($store->id, $merchant->id, stock: 6, ownThreshold: 10);

        $this->assertSame('low', $this->listVerdict($product, 2),
            'premise: the per-product threshold must make this low');

        $this->assertContains($product->name, $this->reportNames($merchant, '/stock-report/low-stock'),
            'the report ignored the product\'s own low_stock_threshold in favour of the store default');
    }

    /**
     * An UNTRACKED product makes no stock claim. The product list says so explicitly -- it falls
     * back to the merchant's own stock_status and the list filter excludes untracked products from
     * all three states -- so neither report may invent a level for one.
     */
    public function test_an_untracked_product_appears_in_neither_report(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: 1, ownThreshold: 0);
        $product->track_stock = 0;
        $product->save();

        $this->assertNotContains($product->name, $this->reportNames($merchant, '/stock-report/low-stock'),
            'an untracked product was given a low-stock level it does not have');
        $this->assertNotContains($product->name, $this->reportNames($merchant, '/stock-report/out-stock'),
            'an untracked product was reported out of stock on a level it does not have');
    }
}
