<?php

namespace App\DataTables;

use App\Models\FlashSale;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class FlashSaleDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
        ->addIndexColumn()
        ->addColumn('status', function (FlashSale $flashsale) {
            return view('flash-sale.status', compact('flashsale'));
        })
        ->addColumn('action', function (FlashSale $flashsale) {
            return view('flash-sale.action', compact('flashsale'));
        })
        ->rawColumns(['name','discount_type','start_date','end_date','status','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(FlashSale $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('flashsale-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'discount_type' => ['title' => __('Discount Type'), 'addClass' => 'text-capitalize'],
            'start_date' => ['title' => __('Start Date')],
            'end_date' => ['title' => __('End Date')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-center', 'printable' => false, 'exportable' => false, 'orderable' => false, 'searchable' => false]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'FlashSale_' . date('YmdHis');
    }
}
