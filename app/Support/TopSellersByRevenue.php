<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * F242 — "TOP CATEGORY / TOP BRAND BY SALES", COMPUTED FROM THE REAL PER-LINE VALUES.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT WAS WRONG, and it was wrong in two independent ways
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `HomeController::top_brand_category_chart()` built twelve queries that CROSS JOINED a generated
 * series `numbers.n` of 1..100 and matched
 * `products.id = SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)`,
 * then took `SUM(orders.final_price)` and the view divided by 100.
 *
 * **1. THE FAN-OUT IS NOT UNIFORM, so no divisor can correct it.** Nothing bounds `n` to the number
 * of elements in the list. For `n` past the end, `SUBSTRING_INDEX(list, ',', n)` returns the WHOLE
 * list and the `-1` then takes its LAST element. So for an order of `k` products:
 *
 *     products 1 … k-1   match exactly 1 time each
 *     product  k         matches 101 - k times
 *
 * With `k = 1` that is 100 matches and `/100` is exact — which is why the divisor looked right.
 * With `k = 2` the first product's category is credited 1/100th of the order and the second 99/100.
 * With `k = 3`, 1/100, 1/100 and 98/100. The divisor is only ever correct for single-product orders,
 * and every multi-product order under-reports its earlier categories a hundredfold.
 *
 * **2. THE WHOLE ORDER TOTAL WAS ATTRIBUTED TO EVERY MATCHED CATEGORY.** Even with the fan-out
 * removed, `SUM(orders.final_price)` credits each category the ENTIRE order — shipping, tax and all
 * — so a two-category order would be counted twice over. "Top categories by sales" cannot be
 * computed from an order-level total at all; it needs the per-line values.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT THIS DOES INSTEAD
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `orders.product_json` is the authoritative per-line record — it is what the order detail page,
 * the invoice and the replacement engine all read. Each line carries its own product, quantity and
 * price. So revenue is summed per line, attributed to that line's product's category or brand, in
 * plain PHP:
 *
 *   - **No divisor**, arbitrary or otherwise.
 *   - **No cross join**, so no fan-out to compensate for.
 *   - **Correct for 1, 2, 3 and more products**, because each line is read once.
 *   - **Store isolation is explicit on every query.** `Product` registers no `StoreScope` and is
 *     absent from `config/tenancy.php`'s `scoped_models` (the S19 shape), so `whereIn('id', …)`
 *     alone would read every tenant's catalogue. The store predicate is written, not inherited.
 *   - **Date isolation is a half-open window** `[from, to)`, so an order at exactly midnight
 *     belongs to one period and not two.
 *   - **Runs on SQLite as well as MySQL**, which matters more than it sounds: the old query used
 *     `SUBSTRING_INDEX` and `CURDATE`, so the Feature suite could not execute it at all and the
 *     only coverage was a MariaDB script. These figures are now testable in the ordinary suite.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHAT A LINE'S REVENUE IS, and why the reader is defensive
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * Measured across `app/` and the vendored modules, the writers do not agree on key names:
 * `product_id` 97 sites, `price` 56, `final_price` 37, `variant_id` 36, **`quantity` 33 and `qty`
 * 23**. So a reader that knows only one spelling returns zero revenue for a third of the platform's
 * orders — silently, which is the failure mode this card already had.
 *
 * The rule: a line's own stated total (`final_price`, then `total_orignal_price`) wins when it is
 * present and numeric, because that is what the line says it was sold for. Otherwise quantity ×
 * unit price. Quantity reads `qty` then `quantity`; unit price reads `price` then `orignal_price`.
 *
 * **THIS IS PRODUCT REVENUE, NOT ORDER TOTAL, and the difference is deliberate.** Shipping, tax and
 * coupon discounts are order-level and belong to no category, so the sum across categories does not
 * equal the sum of `final_price` — it should not. The card's label says so.
 */
class TopSellersByRevenue
{
    public const CATEGORY = 'category';
    public const BRAND = 'brand';

    /**
     * @param  string      $dimension  self::CATEGORY | self::BRAND
     * @param  int|string  $storeId
     * @param  string|null $from       inclusive lower bound (Y-m-d H:i:s), or null for all time
     * @param  string|null $to         EXCLUSIVE upper bound, or null for all time
     * @return array<int, object{sale_name:string, sale_image_path:?string, total_sale:float}>
     */
    public static function compute(string $dimension, $storeId, ?string $from = null, ?string $to = null): array
    {
        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            /* No tenant, no figures. Returning every store's revenue because a store did not
               resolve is the shape S19 keeps producing, so it is refused rather than defaulted. */
            return [];
        }

        $storeId = (int) $storeId;
        $revenueByProduct = self::revenueByProduct($storeId, $from, $to);

        if ($revenueByProduct === []) {
            return [];
        }

        [$column, $table, $nameColumn, $imageColumn] = $dimension === self::BRAND
            ? ['brand_id', 'product_brands', 'name', 'logo']
            : ['category_id', 'categories', 'name', 'image_path'];

        /* THE STORE PREDICATE IS THE POINT OF THIS QUERY, not an optimisation. */
        $products = DB::table('products')
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($revenueByProduct))
            ->whereNotNull($column)
            ->pluck($column, 'id');

        $revenueByDimension = [];

        foreach ($products as $productId => $dimensionId) {
            if (! is_numeric($dimensionId) || (int) $dimensionId <= 0) {
                continue;
            }

            $revenueByDimension[(int) $dimensionId] =
                ($revenueByDimension[(int) $dimensionId] ?? 0.0) + $revenueByProduct[$productId];
        }

        if ($revenueByDimension === []) {
            return [];
        }

        $labels = DB::table($table)
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($revenueByDimension))
            ->get(['id', $nameColumn . ' as sale_name', $imageColumn . ' as sale_image_path'])
            ->keyBy('id');

        $rows = [];

        foreach ($revenueByDimension as $dimensionId => $revenue) {
            if (! isset($labels[$dimensionId])) {
                /* A category or brand belonging to another store, or deleted. Dropped rather than
                   rendered nameless — a row with revenue and no label is worse than no row. */
                continue;
            }

            $rows[] = (object) [
                'sale_name' => (string) $labels[$dimensionId]->sale_name,
                'sale_image_path' => $labels[$dimensionId]->sale_image_path,
                'total_sale' => round($revenue, 2),
            ];
        }

        usort($rows, fn ($a, $b) => $b->total_sale <=> $a->total_sale);

        return $rows;
    }

    /**
     * Sum every order line's revenue per product id, for one store and one window.
     *
     * @return array<int|string, float>
     */
    private static function revenueByProduct(int $storeId, ?string $from, ?string $to): array
    {
        $query = DB::table('orders')->where('store_id', $storeId);

        /* HALF-OPEN `[from, to)`. A closed upper bound counts a midnight order in two periods, and
           `whereDate` would additionally force a per-row function call that no index can serve. */
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<', $to);
        }

        $revenue = [];

        /* Chunked, because a busy store's year has more orders than one array should hold, and the
           old query's cost was the database's problem rather than the request's. */
        $query->orderBy('id')->select('id', 'product_json')->chunk(500, function ($orders) use (&$revenue) {
            foreach ($orders as $order) {
                $lines = json_decode((string) $order->product_json, true);

                if (! is_array($lines)) {
                    continue;
                }

                foreach ($lines as $line) {
                    if (! is_array($line)) {
                        continue;
                    }

                    $productId = $line['product_id'] ?? null;

                    if (! is_numeric($productId) || (int) $productId <= 0) {
                        continue;
                    }

                    $revenue[(int) $productId] = ($revenue[(int) $productId] ?? 0.0) + self::lineRevenue($line);
                }
            }
        });

        return array_filter($revenue, fn ($amount) => $amount > 0);
    }

    /**
     * One line's revenue, reading every spelling the platform's writers actually use.
     *
     * Public so a test can drive the key precedence directly rather than inferring it from a total.
     */
    public static function lineRevenue(array $line): float
    {
        foreach (['final_price', 'total_orignal_price'] as $statedTotal) {
            if (isset($line[$statedTotal]) && is_numeric($line[$statedTotal]) && (float) $line[$statedTotal] > 0) {
                return (float) $line[$statedTotal];
            }
        }

        $quantity = 0;

        foreach (['qty', 'quantity'] as $key) {
            if (isset($line[$key]) && is_numeric($line[$key])) {
                $quantity = (int) $line[$key];
                break;
            }
        }

        $unitPrice = 0.0;

        foreach (['price', 'orignal_price'] as $key) {
            if (isset($line[$key]) && is_numeric($line[$key])) {
                $unitPrice = (float) $line[$key];
                break;
            }
        }

        return max(0, $quantity) * max(0.0, $unitPrice);
    }
}
