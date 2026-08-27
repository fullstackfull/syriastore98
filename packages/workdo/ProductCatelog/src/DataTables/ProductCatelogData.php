<?php

namespace Workdo\ProductCatelog\DataTables;

use App\Models\Category;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\ProductCatelog\app\Models\ProductCatelog;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductCatelogData extends DataTable
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
            ->addColumn('action', function (ProductCatelog $catelog) {
                return view('product-catelog::action', compact('catelog'));
            })
            ->editColumn('store_id', function ($row) {
                $store = Store::find($row->store_id);
                return $store ? $store->name : '-';
            })
            ->editColumn('category_id', function ($row) {
                $category = Category::find($row->category_id);
                return $category ? $category->name : '';
            })
            ->rawColumns(['action','store_id','category_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductCatelog $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productcatelog-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'category_id' => ['title' => __('Category Name')],
            'email' => ['title' => __('Customer Email')],
            'store_id' => ['title' => __('Store Name')],
            'description' => ['title' => __('Description'), 'addClass' => 'description-wrp']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductCatelog_' . date('YmdHis');
    }
}
