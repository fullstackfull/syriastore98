<?php

namespace App\DataTables;

use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class SalesDownloadableProductDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
        ->addColumn('order_id', function ($order) {
            return '<a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '"><span class="btn-inner--text">#' . e($order->product_order_id) . '</span></a>';
        })
        ->editColumn('product_name', function ($order) {
            /*
             * `orders.product_json` IS NULLABLE, and `json_decode(null, true)` is null, so this
             * `foreach` was handed null and threw. yajra formats INSIDE the draw and catches at
             * the top, so the blast radius was the WHOLE TABLE: every row replaced by
             * `{"recordsFiltered":0,"data":[],"error":"foreach() argument must be of type
             * array|object, null given"}`. One malformed order hid all the healthy ones behind a
             * raw PHP message. The neighbouring case was already anticipated -- the
             * `if (! is_array($product)) continue;` below guards a malformed ELEMENT -- what was
             * missing is the guard on the array itself. An order with nothing to list formats as
             * empty rather than being dropped: it exists and the merchant should see it.
             */
            $products = json_decode($order->product_json, true);
            $products = is_array($products) ? $products : [];
            $productNames = [];

            foreach ($products as $product) { 
                if (!is_array($product)) continue;
                $productModel = Product::find($product['product_id']);
                $variantModel = ProductVariant::find($product['variant_id']);

                if ($productModel) {
                    $productNames[] = e($productModel->name) .  ($variantModel ? (' (' . e($variantModel->variant) . ')') : '') ;
                }
            }

            return implode('<br>', $productNames);
        })
        ->editColumn('created_at', function ($order) {
            return $order->created_at->toDateTimeString();
        })
        ->addColumn('customer', function ($order) {
            if ($order->is_guest == 1) {
                return __('Guest');
            } elseif ($order->customer_id != 0) {
                return !empty($order->CustomerData) ? ($order->CustomerData->first_name .' '. $order->CustomerData->last_name) : '';
            } else {
                return __('Walk-in-customer');
            }
        })
        ->addColumn('attachment', function ($order) {
            $output = '';
            // The same null `foreach` as `product_name` above, and the same whole-table blast
            // radius; see that formatter for why the guard belongs on the array, not the element.
            $products = json_decode($order->product_json, true);
            $products = is_array($products) ? $products : [];
            foreach ($products as $product) { 
                if (!is_array($product)) continue;
                $variant = ProductVariant::where('id', $product['variant_id'])->first();
                $d_product = Product::where('id', $product['product_id'])->first();
                if (!empty($variant->downloadable_product)) {
                    $output .= '<img src="' . e(get_file($variant->downloadable_product)) . '" >';
                }
                if (!empty($d_product->downloadable_product)) {
                    $output .= '<img src="' . e(get_file($d_product->downloadable_product)) . '" >';
                }
            }
            return $output;
        })
        ->filterColumn('order_id', function ($query, $keyword) {
            $query->where('product_order_id', 'like', "%$keyword%");
        })
        ->filterColumn('customer', function ($query, $keyword) {
            if (stripos('Guest', $keyword) !== false) {
                // Filter for guest customers
                $query->where('is_guest', 1);
            } elseif (stripos('Walk-in-customer', $keyword) !== false) {
                // Filter for walk-in customers
                $query->where('customer_id', 0);
            } else {
                // Filter for registered customers (searching by name)
                $query->whereHas('CustomerData', function ($subQuery) use ($keyword) {
                    $subQuery->where('first_name', 'like', "%$keyword%")
                             ->orWhere('last_name', 'like', "%$keyword%");
                });
            }
        })
        ->filterColumn('product_name', function ($query, $keyword) {
            $query->whereRaw('
                EXISTS (
                    SELECT 1
                    FROM JSON_TABLE(product_json, "$[*]" COLUMNS (name VARCHAR(255) PATH "$.product_name")) AS jt
                    WHERE jt.name LIKE ?
                )
            ', ["%$keyword%"]);
        })
        // P1-SEC-80. The SHOPPER's name (`customer`, registration validates it as `required|string|max:255` and nothing more) and the timestamp are plain text: yajra escapes them. The three
        // cells that are markup (the order link, the product names joined with <br>, the attachment images) escape every value they interpolate.
        ->rawColumns(['order_id','product_name','attachment']);
    }

    /**
     * Get the query source of dataTable.
     */
    /**
     * ANA-002 / GAP-221. The period the merchant chose, or null for the unfiltered report.
     *
     * Set by `ReportController::OrderDownlodableReport()` on BOTH legs -- the page render and
     * yajra's AJAX draw go through the same route and the same handler, so the controller sets
     * this twice and the two legs cannot disagree about the window.
     */
    private ?\App\Support\Analytics\AnalyticsPeriod $period = null;

    public function forPeriod(?\App\Support\Analytics\AnalyticsPeriod $period): self
    {
        $this->period = $period;

        return $this;
    }

    public function query(Order $model): QueryBuilder
    {
        return $model->newQuery()
        ->select('orders.*', 'products.name as product_name')
        ->leftJoin('products', 'orders.product_id', '=', 'products.id')
        ->where('orders.store_id', getCurrentStore())
        /*
         * THE WINDOW JOINS THE STORE PREDICATE, it does not replace it: `Order` is not in
         * `config/tenancy.php`'s `scoped_models`, so nothing else would confine this.
         *
         * IT WINDOWS `order_date`, NOT THE `created_at` THIS TABLE DISPLAYS. Every other screen
         * in the reports centre windows `order_date`, and the merchant's own order list shows it
         * under the title "Date" -- a period meaning a different thing on one screen of one
         * centre is the defect this workstream exists to remove. The two coincide in practice
         * (every write site sets `order_date` to `now()` in the request that inserts the row, and
         * nothing edits it afterwards), so no row changes membership today; that the column on
         * display is still `created_at` is recorded on GAP-221 rather than changed here.
         */
        ->when($this->period !== null, fn ($query) => $query->whereBetween(
            'orders.order_date',
            [$this->period->from(), $this->period->to()]
        ));

    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('sales-downloadable-product-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'order_id' => ['title' => __('Order Id')],
            'product_name' => ['title' => __('Product Name'), 'name' => 'product_name', 'addClass' => 'text-capitalize'],
            'customer' => ['title' => __('Customer'), 'addClass' => 'text-capitalize'],
            'attachment' => ['title' => __('Attachment')],
            'created_at' => ['title' => __('Timestamp')]
        ], false);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'SalesDownloadableProduct_' . date('YmdHis');
    }
}
