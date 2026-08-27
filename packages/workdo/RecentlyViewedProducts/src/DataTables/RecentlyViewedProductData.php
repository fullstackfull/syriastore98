<?php

namespace Workdo\RecentlyViewedProducts\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\RecentlyViewedProducts\app\Models\RecentlyViewedProduct;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class RecentlyViewedProductData extends DataTable
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
            ->addColumn('action', function (RecentlyViewedProduct $product) {
                return view('recently-viewed-products::recently-viewed.action', compact('product'));
            })
            ->editColumn('product_id', function (RecentlyViewedProduct $product) {
                return !empty($product->product) ? $product->product->name : '';
            })
            ->filterColumn('product_id', function($query, $keyword) {
                $query->whereHas('product', function($query) use ($keyword) {
                    $query->where('name', 'like', "%{$keyword}%");
                });
            })
            ->rawColumns(['action','product_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(RecentlyViewedProduct $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('recentlyviewedproduct-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'product_id' => ['title' => __('Name')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Recentlyviewproduct_' . date('YmdHis');
    }
}
