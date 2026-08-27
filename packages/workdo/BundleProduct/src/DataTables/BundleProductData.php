<?php

namespace Workdo\BundleProduct\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\BundleProduct\app\Models\BundleProduct;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class BundleProductData extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (BundleProduct $product) {
                return view('bundle-product::action', compact('product'));
            })
            ->editColumn('product_id', function ($product) {
                $pro = \App\Models\Product::find($product->product_id);
                return $pro ? $pro->name : '';
            })
            ->editColumn('bundle_products', function ($product) {
                $html = '';
                if ($product->bundle_products) {
                    $bundleProducts = json_decode($product->bundle_products);
                    foreach ($bundleProducts as $productId) {
                        $bundleProduct = \App\Models\Product::find($productId);
                        $productName = $bundleProduct ? $bundleProduct->name : '';
                        $html .= '<span class="badge rounded f-w-600 bg-light-success">' . htmlspecialchars($productName) . '</span><br>';
                    }
                }
                return $html;
            })
            ->filterColumn('product_id', function ($query, $keyword) {
                $query->whereHas('product', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->rawColumns(['action','product_id','bundle_products']);
        return $dataTable;

    }

    /**
     * Get the query source of dataTable.
     */
    public function query(BundleProduct $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('bundleproduct-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'product_id' => ['title' => __('Product Name')],
            'bundle_products' => ['title' => __('Bundle Product')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Users_' . date('YmdHis');
    }
}
