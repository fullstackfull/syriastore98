<?php

namespace Workdo\SpamPrevent\DataTables;

use App\Models\Customer;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\SpamPrevent\app\Models\ProductComment;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class SpamPreventData extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = new EloquentDataTable($query);

        return $dataTable->addIndexColumn()
            ->editColumn('customer_id', function (ProductComment $data) {
                return $data->customer ? $data->customer->first_name : __('No Customer Found.');
            })
            ->editColumn('product_id', function (ProductComment $data) {
                return '<span class="badge rounded f-w-600 bg-light-success">' . htmlspecialchars($data->product->name ?? __('No Product Found.'), ENT_QUOTES, 'UTF-8') . '</span>';
            })
            ->addColumn('action', function (ProductComment $data) {
                return view('spam-prevent::admin.action', compact('data'));
            })
            ->filterColumn('customer_id', function ($query, $keyword) {
                $query->whereHas('customer', function ($q) use ($keyword) {
                    $q->where('first_name', 'like', "%{$keyword}%");
                });
            })
            ->rawColumns(['product_id']);
    }


    /**
     * Get the query source of dataTable.
     */
    public function query(ProductComment $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore())->groupBy('customer_id');
    }


    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productcomment-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('Customer Name')],
            'product_id' => ['title' => __('Commented Products')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Productcomment_' . date('YmdHis');
    }
}
