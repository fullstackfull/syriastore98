<?php

namespace App\DataTables;

use App\Models\Product;
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
                if ($product->variant_product == 1) {
                    return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-warning">' . __('In Variant') . '</span>';
                } else {
                    if ($product->track_stock == 0) {
                        if ($product->stock_status == 'out_of_stock') {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-danger">' . __('Out of stock') . '</span>';
                        } elseif ($product->stock_status == 'on_backorder') {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-warning">' . __('On Backorder') . '</span>';
                        } else {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-primary">' . __('In stock') . '</span>';
                        }
                    } else {
                        if ($product->product_stock <= (isset($admin['out_of_stock_threshold']) ? $admin['out_of_stock_threshold'] : 0)) {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-danger">' . __('Out of stock') . '</span>';
                        } else {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-primary">' . __('In stock') . '</span>';
                        }
                    }
                }
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
    private function applyStockFilter(QueryBuilder $query, string $stock): void
    {
        $fallback = (int) (getAdminAllSetting()['out_of_stock_threshold'] ?? 0);

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
