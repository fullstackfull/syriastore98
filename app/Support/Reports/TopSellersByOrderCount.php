<?php

namespace App\Support\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * ANA-002 / GAP-221 — "TOP CATEGORY / TOP BRAND BY ORDERS", COMPUTED WITHOUT `SUBSTRING_INDEX`.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * WHY THIS EXISTS: THE SUITE COULD NOT EXECUTE THE SCREEN AT ALL
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `ReportController::top_product()` built the two rankings by joining the comma-joined
 * `orders.product_id` column with a generated series:
 *
 *     ->join('orders', fn ($join) => $join
 *         ->on('products.id', '=', DB::raw("SUBSTRING_INDEX(SUBSTRING_INDEX(orders.product_id, ',', numbers.n), ',', -1)"))
 *         ->crossJoin(DB::raw("(SELECT 1 + a.N + b.N * 10 AS n FROM … ) AS numbers")))
 *
 * SQLite has no `SUBSTRING_INDEX`, so driving `top-all-reports` under the Feature suite answered
 * `SQLSTATE[HY000]: General error: 1 no such function: SUBSTRING_INDEX` — measured, and recorded
 * on GAP-221 as the reason the fifth of its five screens could not be given a period with real
 * test evidence. The blindness was not confined to the two charts: the ranking, the limit, the tie
 * break and the three store predicates were all unexecutable too, on a screen that reads every
 * order the store has ever taken.
 *
 * GAP-221's own disposition names this change — *"replace their `selectRaw` … so the suite can
 * reach them — the second is the larger change and the one that removes the blindness
 * permanently"* — and it is NOT a new pattern here. `App\Support\TopSellersByRevenue` already
 * replaced the identical cross join on the dashboard's Top Category / Top Brand cards, and its
 * docblock measures why that join can never be *summed* correctly.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE METRIC IS UNCHANGED, AND IT IS THE ONE THE VIEW ALREADY DOCUMENTS
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 *   top_5_reports.blade.php:  "Categories by the number of distinct orders containing one of
 *                              their products."
 *
 * That is `COUNT(DISTINCT orders.id)` grouped by the dimension's NAME, which is exactly what the
 * SQL computed — so this is a change of mechanism and not of definition. No inclusion rule is
 * added or removed: the screen still counts an order that was later cancelled or returned, which
 * the card discloses in its own sentence and which D1/D2 reserve to the owner.
 *
 * `COUNT(DISTINCT)` is also why the cross join's non-uniform fan-out never corrupted these two
 * figures the way it corrupted the revenue cards: past the end of the list,
 * `SUBSTRING_INDEX(list, ',', n)` returns the whole list and the `-1` takes its LAST element, so
 * the last product matched `101 - k` times — and `DISTINCT` absorbed every repeat.
 *
 * **ONE BEHAVIOUR DIFFERENCE, AND IT IS A WIDENING, STATED RATHER THAN DISCOVERED LATER.** The
 * generated series ran `n = 1 … 100`, so only the first hundred positions of `orders.product_id`
 * were ever matched: in an order listing more than a hundred products, the category of the 101st
 * was silently absent from a figure defined as *"orders containing one of their products"*. This
 * reads the whole list, so such an order now counts. The direction is towards the documented
 * definition, and `TopSalesReportSuiteReachabilityTest` pins it.
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 * THE ORDER SET IS THE CALLER'S, AND THAT IS THE POINT
 * ══════════════════════════════════════════════════════════════════════════════════════════════
 *
 * `compute()` takes the SAME Eloquent builder the handler uses for its other two charts rather
 * than building its own. The screen carries four rankings; if each derived its own order set, a
 * period applied to one and not another would put a February chart beside an all-time one under a
 * single caption. That is the exact confusion `showCountryOrderReport()` was fixed for twice, and
 * it is designed out here rather than guarded against.
 *
 * THE CATALOGUE PREDICATES ARE WRITTEN, NOT INHERITED. `Product`, `Category` and `ProductBrand`
 * all register `StoreScope` and NONE of them is in `config/tenancy.php`'s `scoped_models`, so
 * `StoreScope::isActiveFor()` short-circuits and adds NO predicate for any principal — the S19
 * shape. The old query spelled out `products.store_id`, `categories.store_id` and
 * `orders.store_id`; all three survive, because `orders.product_id` is free text and an order of
 * this store may name another tenant's product id.
 *
 * `DB::table()` AND NOT ELOQUENT, for two measured reasons. Most models in `app/Models`
 * are `Cachable`, so an Eloquent read can serve a pre-write row after a raw write — three
 * measured false readings in this repository — and these three models carry no `SoftDeletingScope`
 * and no active `StoreScope`, so there is no scope to lose. `TopSellersByRevenue` reads the same
 * two tables the same way.
 */
final class TopSellersByOrderCount
{
    public const CATEGORY = 'category';
    public const BRAND = 'brand';

    /** The screen is headed "Top Sales Report" and the card says "the five are the top five". */
    public const LIMIT = 5;

    /**
     * @param  string  $dimension  self::CATEGORY | self::BRAND
     * @param  \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder  $orderQuery
     *                            the handler's own order set, already scoped to the store and to
     *                            the chosen period
     * @param  int|string  $storeId
     * @return Collection<int, object{sale_name: string, sale_image_path: string|null, total_sale: int}>
     */
    public static function compute(string $dimension, $orderQuery, $storeId, int $limit = self::LIMIT): Collection
    {
        if (! is_numeric($storeId) || (int) $storeId <= 0) {
            /* No tenant, no figures. Ranking every store's catalogue because a store did not
               resolve is the shape S19 keeps producing, so it is refused rather than defaulted. */
            return collect();
        }

        $storeId = (int) $storeId;

        [$column, $table, $imageColumn] = $dimension === self::BRAND
            ? ['brand_id', 'product_brands', 'logo']
            : ['category_id', 'categories', 'image_path'];

        /*
         * productId => [orderId => true]. A SET, so an order listing the same product three times
         * -- the shape two of the three writers of this column produce, neither de-duplicating --
         * contributes one order and not three.
         *
         * THAT PROPERTY IS HELD TWICE OVER, and a mutation run says so rather than this comment
         * claiming a single guard. `productIds()` already returns each id once per order, so
         * turning this set into a positional array leaves every figure unchanged and the
         * duplicate-listing test green (recorded as S8, SURVIVED, in the slice's mutation run).
         * The set that IS load-bearing is the one over DIMENSIONS below: removing it makes the
         * figure count product matches instead of orders, which S5 catches.
         */
        $ordersByProduct = [];

        foreach ((clone $orderQuery)->pluck('product_id', 'id') as $orderId => $list) {
            foreach (self::productIds((string) $list) as $productId) {
                $ordersByProduct[$productId][(int) $orderId] = true;
            }
        }

        if ($ordersByProduct === []) {
            return collect();
        }

        $dimensionByProduct = DB::table('products')
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($ordersByProduct))
            ->whereNotNull($column)
            ->pluck($column, 'id');

        /* dimensionId => [orderId => true]. Unioned rather than added: one order containing two
           products of the SAME category is one order for that category. */
        $ordersByDimension = [];

        foreach ($dimensionByProduct as $productId => $dimensionId) {
            if (! is_numeric($dimensionId) || (int) $dimensionId <= 0) {
                continue;
            }

            $dimensionId = (int) $dimensionId;

            foreach ($ordersByProduct[(int) $productId] ?? [] as $orderId => $_) {
                $ordersByDimension[$dimensionId][$orderId] = true;
            }
        }

        if ($ordersByDimension === []) {
            return collect();
        }

        $labels = DB::table($table)
            ->where('store_id', $storeId)
            ->whereIn('id', array_keys($ordersByDimension))
            ->orderBy('id')
            ->get(['id', 'name as sale_name', $imageColumn . ' as sale_image_path'])
            ->keyBy('id');

        /*
         * GROUPED BY NAME, CASE- AND SPACE-INSENSITIVELY, WHICH IS WHAT THE DATABASE ALREADY DID.
         *
         * The old query said `GROUP BY categories.name` and `ORDER BY categories.name`. MySQL's
         * collation here is `utf8mb4_unicode_ci` -- case-INSENSITIVE and PAD SPACE -- so 'Shoes',
         * 'shoes' and 'Shoes  ' were ONE bucket in production and would be THREE under PHP's
         * byte comparison. Folding on a trimmed, lower-cased key is therefore what PRESERVES the
         * shipped behaviour; not folding would be the change. This is the same conclusion the
         * payment-method chart on the same screen reached and recorded.
         *
         * THE BUCKET IS LABELLED WITH THE OLDEST ROW'S SPELLING. MySQL returned whichever row the
         * engine happened to reach first, which is arbitrary and could differ between loads; the
         * lowest id is deterministic and explainable. `$labels` is ordered by id for exactly this.
         */
        $buckets = [];

        foreach ($ordersByDimension as $dimensionId => $orderIds) {
            if (! isset($labels[$dimensionId])) {
                /* A category or brand of another store, or deleted. Dropped rather than rendered
                   nameless -- the old INNER JOIN dropped it too. */
                continue;
            }

            $name = (string) $labels[$dimensionId]->sale_name;
            $key = mb_strtolower(trim($name));

            if (! isset($buckets[$key])) {
                $buckets[$key] = [
                    'sale_name' => $name,
                    'sale_image_path' => $labels[$dimensionId]->sale_image_path,
                    'orders' => [],
                ];
            }

            $buckets[$key]['orders'] += $orderIds;
        }

        /*
         * RANKED, THEN LIMITED, with the SAME tie-break the SQL had: `orderByDesc(total_sale)`
         * then `orderBy(name)` ascending. Without the name the fifth place appears and disappears
         * between loads, which is the defect P5-3f already fixed once on this screen.
         *
         * The comparison is on the FOLDED key, because that is what the database's collation
         * compares. It can still differ from the server for a non-Latin name -- MySQL orders by
         * collation weights and PHP by code point -- and that residue is recorded rather than
         * claimed away, as the payment-method chart's own comment does.
         */
        $ranked = [];

        foreach ($buckets as $key => $bucket) {
            $ranked[] = [
                'key' => $key,
                'total_sale' => count($bucket['orders']),
                'sale_name' => $bucket['sale_name'],
                'sale_image_path' => $bucket['sale_image_path'],
            ];
        }

        usort($ranked, fn (array $a, array $b) => [$b['total_sale'], $a['key']] <=> [$a['total_sale'], $b['key']]);

        return collect(array_slice($ranked, 0, max(0, $limit)))
            ->map(fn (array $row) => (object) [
                'sale_name' => $row['sale_name'],
                'sale_image_path' => $row['sale_image_path'],
                'total_sale' => $row['total_sale'],
            ])
            ->values();
    }

    /**
     * The distinct product ids named by one `orders.product_id` value.
     *
     * The column is a comma-joined list of ids written by three paths, and it really carries empty
     * elements, whitespace and ids whose product has since been deleted. Each is skipped rather
     * than counted: the old `INNER JOIN` dropped them silently, and `' abc '` casts to 0, which
     * matches no product because ids start at 1.
     *
     * `(int) trim()` AND NOT `ctype_digit()`, deliberately, on both of the two grounds that
     * matter. It is what `ReportController::topSellingProducts()` -- the product chart on the SAME
     * screen -- already does, and two charts that disagree about which ids an order names
     * contradict each other under one caption. It is also what the engine did: the old join
     * compared the parsed string against an INT column, and MySQL casts a leading-numeric string,
     * so `'12abc'` matched product 12 there and matches product 12 here.
     *
     * @return list<int>
     */
    private static function productIds(string $list): array
    {
        $ids = [];

        foreach (explode(',', $list) as $raw) {
            $id = (int) trim($raw);

            if ($id > 0) {
                $ids[$id] = true;
            }
        }

        return array_keys($ids);
    }
}
