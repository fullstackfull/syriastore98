<?php

namespace App\DataTables;

use App\Models\Shipping;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ShippingDataTable extends DataTable
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
        ->editColumn('description', function (Shipping $shipping) {
            return isset($shipping->description) ? $shipping->description : '-';
        })
        ->addColumn('action', function (Shipping $shipping) {
            return view('shipping.action', compact('shipping'));
        })
        ->filterColumn('description', function ($query, $keyword) {
            $query->whereRaw('LOWER(shippings.description) LIKE ?', ["%{$keyword}%"]);
        })
        ->filterColumn('deion', function ($query, $keyword) {
            $query->whereRaw('LOWER(shippings.description) LIKE ?', ["%{$keyword}%"]);
        })
        ->orderColumn('description', function ($query, $direction) {
            $query->orderby('shippings.description', $direction);
        })
        ->orderColumn('deion', function ($query, $direction) {
            $query->orderby('shippings.description', $direction);
        })
        ->rawColumns(['name','description','action']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Shipping $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('shipping-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'description' => ['title' => __('Description'), 'orderable' => false]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Shipping_' . date('YmdHis');
    }
}
