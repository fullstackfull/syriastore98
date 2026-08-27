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
            return '<a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($order->id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '"><span class="btn-inner--text">#' . $order->product_order_id . '</span></a>';
        })
        ->editColumn('product_name', function ($order) {
            $products = json_decode($order->product_json, true);
            $productNames = [];

            foreach ($products as $product) { 
                if (!is_array($product)) continue;
                $productModel = Product::find($product['product_id']);
                $variantModel = ProductVariant::find($product['variant_id']);

                if ($productModel) {
                    $productNames[] = $productModel->name .  ($variantModel ? (' (' .$variantModel->variant. ')') : '') ;
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
            $products = json_decode($order->product_json, true);
            foreach ($products as $product) { 
                if (!is_array($product)) continue;
                $variant = ProductVariant::where('id', $product['variant_id'])->first();
                $d_product = Product::where('id', $product['product_id'])->first();
                if (!empty($variant->downloadable_product)) {
                    $output .= '<img src="' . get_file($variant->downloadable_product) . '" >';
                }
                if (!empty($d_product->downloadable_product)) {
                    $output .= '<img src="' . get_file($d_product->downloadable_product) . '" >';
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
        ->rawColumns(['order_id','product_name','customer','attachment','created_at']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Order $model): QueryBuilder
    {
        return $model->newQuery()
        ->select('orders.*', 'products.name as product_name')
        ->leftJoin('products', 'orders.product_id', '=', 'products.id')
        ->where('orders.store_id', getCurrentStore());

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
