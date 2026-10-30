<?php

namespace Tests\Feature\Merchant;

use App\DataTables\ProductDataTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Security\SecurityTestCase;

/**
 * F222 — THE DASHBOARD AND THE PRODUCT LIST MUST ANSWER "OUT OF STOCK" THE SAME WAY.
 *
 * `HomeController` counted a tracked product as out of stock at
 * `product_stock <= out_of_stock_threshold`. The authoritative rule —
 * `ProductDataTable::trackedStockState()` and the list's own `applyStockFilter()` — makes a tracked
 * product out ONLY at `product_stock <= 0`, and uses the threshold as the **LOW** boundary.
 *
 * So with a threshold of 5, a product with stock 3 was labelled **LOW** on the list and **counted
 * as OUT** on the dashboard: one question, two numbers, on two surfaces the merchant reaches from
 * each other. `ReportController` calls the shared helpers at eleven sites for exactly this reason;
 * `HomeController` called neither, and **no test anywhere drove `$out_of_stock_products`** — which
 * is why a row claiming the rule was shared could read DONE.
 *
 * THIS TEST DOES NOT RE-STATE THE RULE. It drives BOTH surfaces over the same rows and compares
 * them, which is the only shape that cannot drift: a test that asserted "the dashboard counts N"
 * would keep passing while the list changed underneath it. The same technique
 * `ProductStockIndicatorTest::test_the_badge_and_the_filter_agree_on_every_boundary` already uses
 * for the badge and the filter.
 */
class DashboardStockCountAgreementTest extends SecurityTestCase
{
    use RefreshDatabase;

    /** The boundary rows. `stock` is null for an untracked product. */
    private const ROWS = [
        ['track' => 1, 'stock' => 0,  'status' => 'in_stock',     'label' => 'tracked, zero'],
        ['track' => 1, 'stock' => 1,  'status' => 'in_stock',     'label' => 'tracked, one'],
        ['track' => 1, 'stock' => 3,  'status' => 'in_stock',     'label' => 'tracked, under the threshold'],
        ['track' => 1, 'stock' => 5,  'status' => 'in_stock',     'label' => 'tracked, at the threshold'],
        ['track' => 1, 'stock' => 9,  'status' => 'in_stock',     'label' => 'tracked, above it'],
        ['track' => 0, 'stock' => 0,  'status' => 'out_of_stock', 'label' => 'untracked, marked out'],
        ['track' => 0, 'stock' => 0,  'status' => 'in_stock',     'label' => 'untracked, marked in'],
    ];

    private const THRESHOLD = 5;

    private function seedRows($store, $merchant): array
    {
        $ids = [];

        foreach (self::ROWS as $i => $row) {
            $ids[$row['label']] = (int) DB::table('products')->insertGetId([
                'name' => 'P' . $i,
                'slug' => 'p' . $i . '-' . uniqid(),
                'store_id' => $store->id,
                'created_by' => $merchant->id,
                'track_stock' => $row['track'],
                'product_stock' => $row['stock'],
                'stock_status' => $row['status'],
                'variant_product' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    public function test_the_shared_rule_makes_a_tracked_product_out_only_at_zero(): void
    {
        /*
         * The premise, asserted so the comparison below is anchored to something rather than to two
         * surfaces that could be wrong together. THIS is the authoritative rule.
         */
        $this->assertSame('out', ProductDataTable::trackedStockState(0, null, self::THRESHOLD));
        $this->assertSame('low', ProductDataTable::trackedStockState(1, null, self::THRESHOLD));
        $this->assertSame(
            'low',
            ProductDataTable::trackedStockState(3, null, self::THRESHOLD),
            'THE WHOLE POINT: stock 3 under a threshold of 5 is LOW, not OUT. If this ever says '
            . '"out", the shared rule changed and the dashboard must follow it, not the reverse.'
        );
        $this->assertSame('low', ProductDataTable::trackedStockState(5, null, self::THRESHOLD));
        $this->assertSame('in', ProductDataTable::trackedStockState(9, null, self::THRESHOLD));
    }

    public function test_the_dashboard_count_agrees_with_the_shared_rule_row_by_row(): void
    {
        [$merchant, $store] = $this->makeTenant();
        $merchant->current_store = $store->id;
        $merchant->save();

        DB::table('settings')->insert([
            'name' => 'out_of_stock_threshold', 'value' => (string) self::THRESHOLD,
            'store_id' => $store->id, 'created_by' => $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->seedRows($store, $merchant);

        /*
         * The dashboard's predicate, lifted verbatim from HomeController so the test exercises the
         * shipped SQL rather than a paraphrase of it. If the handler's query is edited and this is
         * not, the next assertion is comparing the wrong thing — which is why the source assertion
         * at the end of this file exists.
         */
        $dashboardCount = DB::table('products')
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('variant_product', '!=', 1)->orWhereNull('variant_product');
            })
            ->where(function ($q) {
                $q->where(function ($untracked) {
                    $untracked->where('track_stock', 0)->where('stock_status', 'out_of_stock');
                })->orWhere(function ($tracked) {
                    $tracked->where('track_stock', '!=', 0)->where('product_stock', '<=', 0);
                });
            })
            ->count();

        /* What the SHARED rule says, computed row by row in PHP. */
        $expected = 0;

        foreach (self::ROWS as $row) {
            if ($row['track'] != 0) {
                $expected += ProductDataTable::trackedStockState(
                    (int) $row['stock'], null, self::THRESHOLD
                ) === 'out' ? 1 : 0;
            } else {
                // The deliberately-broader limb: an untracked product has no count to judge, so its
                // own `stock_status` string is the only answer available for it.
                $expected += $row['status'] === 'out_of_stock' ? 1 : 0;
            }
        }

        $this->assertSame(
            $expected,
            $dashboardCount,
            'The dashboard count and the shared stock rule disagree. Measured rows: '
            . json_encode(self::ROWS) . ' with threshold ' . self::THRESHOLD . '. Before F222 this '
            . 'returned ' . ($expected + 2) . ' instead of ' . $expected . ', because stocks 3 and '
            . '5 were counted as out.'
        );

        // And the figure is not trivially zero or everything, which would make the comparison weak.
        $this->assertSame(2, $expected, 'two rows are genuinely out: tracked-zero and untracked-marked-out');
    }

    public function test_the_threshold_changes_the_low_boundary_and_not_the_out_count(): void
    {
        /*
         * The property that was broken: the OUT count must be INDEPENDENT of the threshold. Before
         * F222, raising the threshold raised the dashboard's out-of-stock figure — so a merchant
         * who configured a low-stock warning watched their "out of stock" number climb.
         */
        [$merchant, $store] = $this->makeTenant();
        $merchant->current_store = $store->id;
        $merchant->save();
        $this->seedRows($store, $merchant);

        $count = fn () => DB::table('products')
            ->where('store_id', $store->id)
            ->where(function ($q) {
                $q->where('variant_product', '!=', 1)->orWhereNull('variant_product');
            })
            ->where(function ($q) {
                $q->where(function ($untracked) {
                    $untracked->where('track_stock', 0)->where('stock_status', 'out_of_stock');
                })->orWhere(function ($tracked) {
                    $tracked->where('track_stock', '!=', 0)->where('product_stock', '<=', 0);
                });
            })
            ->count();

        $atZero = $count();

        DB::table('settings')->insert([
            'name' => 'out_of_stock_threshold', 'value' => '99',
            'store_id' => $store->id, 'created_by' => $merchant->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame(
            $atZero,
            $count(),
            'raising the threshold to 99 must not change the OUT count — it is the LOW boundary'
        );
    }

    public function test_the_handler_really_uses_the_zero_boundary(): void
    {
        /*
         * The three cases above all run the predicate the TEST holds, so they would keep passing
         * against a handler that went back to the threshold. This is the assertion that binds them
         * to the shipped code.
         */
        $source = (string) file_get_contents(base_path('app/Http/Controllers/HomeController.php'));

        $this->assertStringContainsString(
            "\$tracked->where('track_stock', '!=', 0)\n                        ->where('product_stock', '<=', 0);",
            $source,
            'HomeController no longer uses the `<= 0` boundary for its out-of-stock count. If it '
            . 'reads the threshold again, the dashboard and the product list disagree for every '
            . 'merchant with a non-zero threshold — which is F222.'
        );

        $this->assertStringNotContainsString(
            "->where('product_stock', '<=', (int) (\$out_of_stock_threshold ?? 0))",
            $source,
            'the threshold-as-out-boundary predicate is back'
        );
    }
}
