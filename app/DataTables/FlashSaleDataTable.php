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
        /*
         * DISPLAY ONLY. `flash_sales.discount_type` stores 'percentage' or 'flat' and MUST keep
         * storing exactly that -- FlashSaleController:163 and :418 branch on
         * `$request->discount_type == 'percentage'`, so a changed value would change pricing.
         * This maps the stored value to a translated label at render time and nothing else.
         *
         * The column previously had no editColumn at all: it printed the raw stored value, and
         * the `text-capitalize` class on it (see the column definition below) made 'flat' look
         * like a deliberate English label "Flat" on an Arabic page.
         *
         * An UNKNOWN value falls through to its own raw text rather than to an empty cell, so a
         * value added later is visible and obviously untranslated instead of silently missing.
         */
        ->editColumn('discount_type', function (FlashSale $flashsale) {
            return match ($flashsale->discount_type) {
                'percentage' => __('Percentage'),
                'flat'       => __('Flat'),
                default      => (string) $flashsale->discount_type,
            };
        })
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
            /*
             * `ss-flashsale-status-cell` IS A CAPTURE HOOK, and it is here rather than in the
             * partial because the partial renders INSIDE the cell, not as it.
             *
             * The inventory tracks "Flash sale status cell" as a component in its own right, so
             * its evidence has to be the CELL clipped to its own box -- a screenshot of the whole
             * list is evidence for the list. Nothing in this table distinguished that cell:
             * `text-center` is shared, and DataTables adds no per-column class of its own, so the
             * harness had no selector and the row stayed PENDING for want of one.
             *
             * `addClass` lands on every cell in the column (Column::addClass -> DataTables
             * `className`), which is what a per-row clip needs. It carries no styling: there is no
             * `.ss-flashsale-status-cell` rule in public/css/store-syria-ds.css, deliberately, so
             * adding it cannot change how the column looks in either theme or direction.
             */
            'status' => ['title' => __('Status'), 'addClass' => 'text-center ss-flashsale-status-cell', 'printable' => false, 'exportable' => false, 'orderable' => false, 'searchable' => false]
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
