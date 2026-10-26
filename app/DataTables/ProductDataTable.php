<?php

namespace App\DataTables;

use App\Models\Product;
use App\Support\DsBadge;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        // P1-SEC-26: same store as query() below (`where('store_id', getCurrentStore())`), so the out_of_stock_threshold that labels a
        // row comes from the store whose rows are being listed. A no-argument call resolves it from the caller, which for a staff member
        // is their owner's current_store.
        $admin = getAdminAllSetting(null, getCurrentStore());
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (Product $product) {
                return view('product.action', compact('product'));
            })
            ->editColumn('category_id', function (Product $product) {
                return !empty($product->ProductData) ? $product->ProductData->name : '-';
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                $query->whereHas('ProductData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('brand_id', function (Product $product) {
                return !empty($product->brand) ? $product->brand->name : '-';
            })
            ->filterColumn('brand_id', function ($query, $keyword) {
                $query->whereHas('brand', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('label_id', function (Product $product) {
                return !empty($product->label) ? $product->label->name : '-';
            })
            ->filterColumn('label_id', function ($query, $keyword) {
                $query->whereHas('label', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('variant_product', function (Product $product) {
                // P2-03: these two strings never went through `__()`, so the variant column
                // read ENGLISH on every Arabic product list. `no variant` already had an
                // ar.json entry that nothing was reaching; `has variant` had none and was
                // added with this change.
                return $product->variant_product == 1 ? __('has variant') : __('no variant');
            })
            ->editColumn('average_rating', function (Product $product) {
                return '<i class="ti ti-star text-warning"></i> ' . $product->average_rating;
            })
            ->editColumn('price', function (Product $product) {
                if ($product->variant_product == 0) {
                    return currency_format_with_sym($product->price, getCurrentStore()) ?? SetNumberFormat($product->price);
                } else {
                    return __('In Variant');
                }
            })
            ->addColumn('stock_status', function ($product) use ($admin) {
                /*
                 * THE DESIGN SYSTEM'S BADGE. The vendored `badge bg-light-*` classes this
                 * column used measured 2.59:1 for "In stock" (white on the merchant's brand
                 * green) and 2.66:1 for "Out of stock" -- both text, both needing 4.5:1 -- in
                 * every one of LTR/RTL x light/dark. Same family of defect as the order list's
                 * status buttons (P2-04-4), found the same way, on the screen beside it.
                 *
                 * `In Variant` MOVES FROM WARNING TO NEUTRAL, deliberately. It is not a stock
                 * level and not a problem: it says the stock lives on this product's variants,
                 * so the question does not apply at this row. An amber pill in a stock column
                 * reads as "something is wrong with this product", which is a claim the data
                 * does not support.
                 */
                if ($product->variant_product == 1) {
                    return DsBadge::render('neutral', __('In Variant'));
                }

                if ($product->track_stock == 0) {
                    /* UNTRACKED: the level is not a fact, so the merchant's own `stock_status` is
                       the only thing to report. This half already agreed with the filter, which
                       excludes untracked products from all three states for the same reason. */
                    if ($product->stock_status == 'out_of_stock') {
                        return DsBadge::render('danger', __('Out of stock'),
                            __('Stock is not tracked for this product. You marked it out of stock.'));
                    }

                    if ($product->stock_status == 'on_backorder') {
                        return DsBadge::render('warning', __('On Backorder'),
                            __('Stock is not tracked for this product. You marked it on backorder.'));
                    }

                    return DsBadge::render('success', __('In stock'),
                        __('Stock is not tracked for this product.'));
                }

                /* TRACKED: one rule, shared with the filter. See trackedStockState(). */
                $fallback = self::stockFallbackThreshold($admin);
                $state = self::trackedStockState(
                    (int) $product->product_stock,
                    $product->low_stock_threshold,
                    $fallback
                );
                $effectiveLow = (int) ($product->low_stock_threshold ?: 0) ?: $fallback;

                if ($state === 'out') {
                    return DsBadge::render('danger', __('Out of stock'),
                        __('None left. :count in stock.', ['count' => (int) $product->product_stock]));
                }

                if ($state === 'low') {
                    return DsBadge::render('warning', __('Low stock'),
                        __(':count left, at or below the low-stock level of :threshold.', [
                            'count' => (int) $product->product_stock,
                            'threshold' => $effectiveLow,
                        ]));
                }

                return DsBadge::render('success', __('In stock'),
                    __(':count left, above the low-stock level of :threshold.', [
                        'count' => (int) $product->product_stock,
                        'threshold' => $effectiveLow,
                    ]));
            })
            ->addColumn('product_stock', function ($product) {
                if ($product->variant_product == 1) {
                    return '-';
                } else {
                    return $product->product_stock > 0 ? $product->product_stock : '-';
                }
            })
            ->editColumn('cover_image_path', function (Product $product) {
                if (isset($product->cover_image_path) && !empty($product->cover_image_path)) {
                    return '<img src="' . get_file($product->cover_image_path) . '" alt="" width="100" class="cover_img' . $product->id . '">';
                }
                return '';
            })
            ->rawColumns(['action', 'category_id', 'brand_id', 'label_id', 'variant_product', 'average_rating', 'price', 'stock_status', 'product_stock', 'cover_image_path']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    /**
     * P2-03-2. The caller's own catalogue, optionally NARROWED.
     *
     * THE STORE PREDICATE COMES FIRST AND IS NOT NEGOTIABLE. `Product` is not in
     * `config/tenancy.php`'s `scoped_models` and registers no effective `StoreScope`, so this
     * `where('store_id', ...)` is the ONLY thing keeping one merchant's catalogue out of
     * another's list -- the fact behind the S19 cross-tenant stock drain. Filters are applied
     * AFTER it and can only add predicates; `applyFilters()` never touches the builder's
     * existing wheres and never calls `orWhere`.
     */
    public function query(Product $model): QueryBuilder
    {
        $query = $model->where('product_type', null)->where('store_id', getCurrentStore());

        return $this->applyFilters($query);
    }

    /**
     * P2-03-2 -- the list filters.
     *
     * READ FROM `ss_`-PREFIXED QUERY PARAMETERS, and the prefix earns its place: the AJAX leg's
     * url IS the page url (yajra's `minifiedAjax()` sets it that way), so DataTables' own
     * `draw`, `columns`, `search`, `order`, `start` and `length` travel in the same query
     * string. A filter called `status` would be one library rename away from a collision.
     *
     * NO JAVASCRIPT ANYWHERE. The filter bar is a GET form that reloads the page; both legs then
     * see the same parameters, because the AJAX url inherits them. Measured in a real browser
     * before the feature was designed around it.
     *
     * AN UNRECOGNISED VALUE IS IGNORED, not refused. A merchant editing the url, or a bookmark
     * from a build that spelled a value differently, gets the list -- not a 500 and not an empty
     * screen that reads as data loss. Every branch below is an explicit match on a known value,
     * so an unknown one simply adds no predicate: a filter can only narrow, never widen, and
     * never replace what is already on the builder.
     */
    private function applyFilters(QueryBuilder $query): QueryBuilder
    {
        $request = request();

        /*
         * `is_string` on every read, and it is REDUNDANT TODAY -- stated rather than implied,
         * because the first version of this comment claimed it was preventing a 500 and the
         * bite test refuted that. `?ss_stock[]=out` does make these arrays, but strict
         * `in_array` returns false for an array and `filter_var` returns false too, so every
         * branch below already declines without it: removing all four guards left the suite
         * green.
         *
         * Kept anyway, for one reason worth a line: the next filter added here may compare
         * directly (`$v === 'x'`, `strtolower($v)`, a `LIKE`), and THAT is where an array
         * becomes an "Array to string conversion" and a 500 from a query string. One guard per
         * read makes the safe shape the default rather than something the next person has to
         * remember.
         */
        $status = $request->query('ss_status');

        if (is_string($status) && in_array($status, ['0', '1'], true)) {
            $query->where('status', (int) $status);
        }

        $variant = $request->query('ss_variant');

        if (is_string($variant) && in_array($variant, ['0', '1'], true)) {
            $query->where('variant_product', (int) $variant);
        }

        $category = $request->query('ss_category');

        if (is_string($category) && $category !== '') {
            $categoryId = filter_var($category, FILTER_VALIDATE_INT);

            /*
             * A category id from request input needs no ownership check, and that is worth
             * saying rather than leaving to inference: this predicate NARROWS a set already
             * confined to the caller's own store, so a foreign category id returns zero rows
             * rather than somebody else's. It is not a tenant predicate and is not standing in
             * for one.
             */
            if ($categoryId !== false && $categoryId > 0) {
                $query->where('category_id', $categoryId);
            }
        }

        $stock = $request->query('ss_stock');

        if (is_string($stock) && in_array($stock, ['in', 'low', 'out'], true)) {
            $this->applyStockFilter($query, $stock);
        }

        return $query;
    }

    /**
     * The three stock states, which PARTITION the tracked products: nothing is in two of them
     * and nothing tracked is in none. That property is what makes the counts trustworthy, and a
     * boundary mistake breaks it silently -- so `ProductListFilterTest` asserts the partition
     * directly, on values sitting exactly on the boundaries.
     *
     * `track_stock = 1` ON ALL THREE. A product the merchant told the system not to count has
     * no stock state at all: its `product_stock` of 0 is the absence of a fact, not "none
     * left". Listing it under "out of stock" would invent a problem, which is the same honesty
     * the preview panel applies to the same column.
     *
     * THE THRESHOLD IS PER PRODUCT, falling back to the STORE's setting.
     * `COALESCE(NULLIF(low_stock_threshold, 0), ?)` reads the same on MySQL and on SQLite --
     * both functions are standard in both -- and `NULLIF` is what makes a stored 0 mean
     * "unset" rather than "a threshold of zero", which is how the column is used everywhere
     * else in this application.
     */
    /**
     * THE ONE STOCK RULE, used by BOTH the badge and the filter (P2-03-9).
     * =============================================================================================
     *
     * P2-03-9's acceptance asks that the indicator "agree with the product list's own predicate".
     * It did not, in two ways, and both were reachable:
     *
     *   1. THE BOUNDARY DISAGREED. The badge said out-of-stock at
     *      `product_stock <= out_of_stock_threshold` (the global setting, which the merchant edits
     *      at `setting/stock_setting.blade.php:19`), while the filter says out at
     *      `product_stock <= 0`. With the threshold set to 5, a product at stock 3 showed the badge
     *      "Out of stock" and `?ss_stock=out` did NOT return it, while `?ss_stock=low` did. The row
     *      and the filter contradicted each other on screen.
     *
     *   2. THERE WAS NO "LOW STOCK" BADGE AT ALL. The filter has offered a Low stock option since
     *      P2-03-2 (`product/partials/filters.blade.php:41`), so a merchant could filter to it and
     *      every row would read "In stock".
     *
     * THE FILTER'S SEMANTICS WIN, and not merely because the acceptance names it: they are the
     * better-reasoned pair. `ProductListFilterTest` asserts that the three states PARTITION the
     * tracked products -- nothing in two, nothing tracked in none -- and that an untracked product
     * is in none of them, because `track_stock = 0` means its zero is "not counted" rather than
     * "none left". The badge had no such property.
     *
     * SO THEY NOW SHARE THIS METHOD. Agreement by construction, not by two copies of an
     * expression that drift -- which is exactly how they came to disagree.
     *
     * WHAT THIS DOES NOT TOUCH, deliberately: the CART's rule. `Cart.php:135` refuses a purchase
     * only when `product_stock <= out_of_stock_threshold` AND `stock_order_status == 'not_allow'`,
     * so a product at zero with any other order status is still purchasable -- and the fixture's
     * (and production's) `''` is "any other". That divergence between what the merchant list says
     * and what a customer can actually do is recorded as F79; changing it would be a change to
     * purchasing behaviour, which is reserved.
     *
     * @return 'out'|'low'|'in' the state a TRACKED product is in
     */
    public static function trackedStockState(int $stock, $lowThreshold, int $fallback): string
    {
        if ($stock <= 0) {
            return 'out';
        }

        // `NULLIF(low_stock_threshold, 0)` in SQL: a per-product 0 means "unset", not "zero".
        $low = (int) ($lowThreshold ?: 0);
        if ($low === 0) {
            $low = $fallback;
        }

        return $stock <= $low ? 'low' : 'in';
    }

    /** The global fallback the badge and the filter both start from. */
    public static function stockFallbackThreshold($admin = null): int
    {
        $settings = $admin ?? getAdminAllSetting();

        return (int) ($settings['out_of_stock_threshold'] ?? 0);
    }

    private function applyStockFilter(QueryBuilder $query, string $stock): void
    {
        /* ONE SOURCE FOR THE FALLBACK, shared with the badge (P2-03-9). The SQL below cannot call
           `trackedStockState()` -- it has to stay a predicate the database evaluates -- so the
           shared PHP method and this SQL are kept in agreement by
           `ProductStockIndicatorTest::test_the_badge_and_the_filter_agree_on_every_boundary`,
           which drives both and compares them row by row rather than trusting that two
           expressions written to match still do. */
        $fallback = self::stockFallbackThreshold();

        $query->where('track_stock', 1);

        if ($stock === 'out') {
            $query->where('product_stock', '<=', 0);

            return;
        }

        $query->where('product_stock', '>', 0);

        if ($stock === 'low') {
            $query->whereRaw('product_stock <= COALESCE(NULLIF(low_stock_threshold, 0), ?)', [$fallback]);

            return;
        }

        $query->whereRaw('product_stock > COALESCE(NULLIF(low_stock_threshold, 0), ?)', [$fallback]);
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('product-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'category_id' => ['title' => __('Category')],
            'brand_id' => ['title' => __('Brand')],
            'label_id' => ['title' => __('Label')],
            'cover_image_path' => ['title' => __('Cover Image')],
            'variant_product' => ['title' => __('Variant')],
            'average_rating' => ['title' => __('Review')],
            'price' => ['title' => __('Price')],
            'stock_status' => ['title' => __('Stock Status'), 'addClass' => 'text-capitalize'],
            'product_stock' => ['title' => __('Stock Quantity')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Product_' . date('YmdHis');
    }
}
