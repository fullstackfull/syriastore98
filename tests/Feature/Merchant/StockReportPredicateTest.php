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

    /** @return list<array<string,mixed>> the raw rows the endpoint returned */
    private function reportRows(\App\Models\User $merchant, string $uri): array
    {
        $response = $this->actingAs($merchant)
            ->withHeaders(self::AJAX)
            ->getJson($uri . '?draw=1&start=0&length=50');

        $response->assertOk();
        $payload = $response->json();

        $this->assertArrayHasKey('draw', $payload,
            'the report did not serve its datatables leg: ' . substr(json_encode($payload), 0, 200));

        return $payload['data'] ?? [];
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
    /**
     * THE STATUS COLUMN MUST STATE THE STATE THE TAB IS ABOUT, in the same words and tone the
     * product list uses.
     *
     * Measured before this existed: the low-stock tab's status column was EMPTY for all five rows,
     * under a header reading "Stock status", and the most-stocked tab's was empty for all ten. The
     * partials gated on `stock_order_status` -- the merchant's BACKORDER POLICY, not a level -- so
     * a product whose policy read `allow` would have shown a badge saying **"In stock"** on the
     * LOW-STOCK report.
     *
     * BOTH HALVES ARE ASSERTED. The right label must be present AND the two wrong ones absent,
     * because a view that rendered every tone would satisfy a presence-only check.
     *
     * @dataProvider badgeExpectations
     */
    public function test_each_report_renders_the_badge_for_its_own_state(
        string $uri,
        int $stock,
        string $expectTone,
        string $expectLabel,
        array $forbidLabels
    ): void {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: $stock, ownThreshold: 0);

        $rows = $this->reportRows($merchant, $uri);
        $mine = array_values(array_filter($rows,
            static fn (array $r): bool => ($r['product_name'] ?? '') === $product->name));

        // PREMISE: the row is in this report at all. Without it every absence below passes free.
        $this->assertCount(1, $mine,
            'premise: the product is not in this report, so its badge cannot be measured');

        $html = (string) ($mine[0]['stock_status'] ?? '');

        $this->assertStringContainsString('ss-badge--' . $expectTone, $html,
            "the badge is not the design system's {$expectTone} tone. Raw Bootstrap pills are not "
            . 'theme-aware, which is why the order list recorded one at 1.09:1 in dark mode. Got: '
            . substr($html, 0, 160));
        $this->assertStringContainsString($expectLabel, $html,
            "the badge does not say \"{$expectLabel}\". An empty status column under a "
            . '"Stock status" header is what this replaced. Got: ' . substr($html, 0, 160));

        foreach ($forbidLabels as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $html,
                "the badge says \"{$forbidden}\" on a report about something else -- the exact "
                . 'defect this fixed, where the low-stock tab could read "In stock".');
        }
    }

    /**
     * @return array<string,array{0:string,1:int,2:string,3:string,4:list<string>}>
     */
    public static function badgeExpectations(): array
    {
        return [
            // uri, stock, tone, label it must carry, labels it must not
            'low report says Low stock' => [
                '/stock-report/low-stock', 3, 'warning', 'Low stock', ['In stock', 'Out of stock'],
            ],
            'out report says Out of stock' => [
                '/stock-report/out-stock', 0, 'danger', 'Out of stock', ['In stock', 'Low stock'],
            ],
            'most-stocked says In stock' => [
                '/stock-report/most-stocked', 40, 'success', 'In stock', ['Low stock', 'Out of stock'],
            ],
        ];
    }

    /**
     * The row action must have an accessible name that states the PRODUCT.
     *
     * Measured before this: five icon-only links, accessible name empty on all five, because the
     * `title` was on the inner `<i>` -- where it can never name the parent link -- and Bootstrap
     * then moved it into `data-bs-original-title` and blanked it. The same shape as F101 on the
     * customer list, with the extra twist of being on the wrong element to begin with.
     */
    public function test_the_row_action_names_the_product(): void
    {
        [$merchant, $store] = $this->tenantWithThreshold(5);
        $product = $this->product($store->id, $merchant->id, stock: 3, ownThreshold: 0);

        $rows = $this->reportRows($merchant, '/stock-report/low-stock');
        $mine = array_values(array_filter($rows,
            static fn (array $r): bool => ($r['product_name'] ?? '') === $product->name));
        $this->assertCount(1, $mine, 'premise: the product is not in the report');

        $action = (string) ($mine[0]['action'] ?? '');

        $this->assertStringContainsString('aria-label', $action,
            'the row action has no accessible name. A `title` on the inner <i> does not name the '
            . 'link, and Bootstrap blanks it on init.');
        $this->assertStringContainsString($product->name, $action,
            'the accessible name does not state which product the action applies to');
        $this->assertStringContainsString('ss-btn', $action,
            'the action is not on the design system; `btn-info` measured 1.09:1 in dark mode on '
            . 'the order list');
        $this->assertStringContainsString('aria-hidden="true"', $action,
            'the icon is not hidden from assistive technology, so the name is announced twice');
    }

    /**
     * F107. THE ACTION BEING NAMED IS NOT THE SAME CLAIM AS THE ACTION BEING REACHABLE, and on
     * this screen the second was false while the first was true.
     *
     * The three stock tables are 905px wide against an 803px wrapper at 1440 and a 320px one at
     * 390, and the action column is 99px -- so at the starting scroll position it sat entirely
     * outside the visible box. Measured by a real click in all eight configurations
     * (LTR/RTL x light/dark x 1440/390): the Edit anchor was outside the wrapper and
     * `elementFromPoint` at its centre returned something else, so a merchant could not reach it
     * without discovering a horizontal scroll. This is the same defect P2-03-1 measured and
     * fixed on the product list, and it is fixed the same way rather than a second way.
     *
     * THE CLASS IS WHAT THE CSS KEYS ON, so asserting it is asserting the fix: the rules live at
     * `public/css/store-syria-ds.css` under `.ss-dt-card-body table.dataTable ... td.ss-col-pinned`,
     * which is why the card body needs its class too -- without it the selector does not match
     * and `--ss-dt-card-bg` is undefined, so the sticky cell would scroll content under a
     * transparent background. Both halves are asserted, on all three tabs, because two of the
     * three were a copy of the first and a fix applied to one is easy to miss on the others.
     */
    public function test_every_stock_tab_pins_its_action_column_to_the_visible_edge(): void
    {
        $view = file_get_contents(resource_path('views/reports/stock_report.blade.php'));
        $this->assertNotFalse($view, 'premise: the view is readable');

        $this->assertSame(3, substr_count($view, "data: 'action', name: 'action'"),
            'premise: three tables, one per tab -- a fourth would need the same treatment');
        $this->assertSame(3, substr_count($view, 'ss-col-pinned'),
            'every tab\'s action column must carry `ss-col-pinned`, or its actions sit outside '
            . 'the wrapper at scroll 0 and cannot be clicked');
        $this->assertSame(3, substr_count($view, 'ss-dt-card-body'),
            'and every table\'s card body must carry `ss-dt-card-body`, which is what scopes the '
            . 'pin rules and defines --ss-dt-card-bg; without it the sticky cell is transparent');

        $css = file_get_contents(public_path('css/store-syria-ds.css'));
        $this->assertNotFalse($css);
        $this->assertStringContainsString(
            '.ss-dt-card-body table.dataTable > tbody > tr > td.ss-col-pinned',
            $css,
            'the rule the class depends on has been renamed or removed, so the class is inert');
    }

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
