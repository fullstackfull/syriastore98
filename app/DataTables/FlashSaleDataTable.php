<?php

namespace App\DataTables;

use App\Services\TableFilterMemory;
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
        // P1-SEC-80. The name is plain text a staff member types, and the discount type and the dates are plain text too: yajra escapes them. `status` is a view and `action` is a view.
        ->rawColumns(['status','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(FlashSale $model): QueryBuilder
    {
        return $this->applyFilters($model->newQuery()->where('store_id', getCurrentStore()));
    }

    /**
     * MKT-002's flash-sale filters. `is_active` is the merchant's switch; `ss_window` is what the
     * DATES say, which is a different question -- a sale can be active and not yet started.
     */
    private function applyFilters(QueryBuilder $query): QueryBuilder
    {
        $request = request();

        /*
         * `is_string` on every read, the shape `ProductDataTable::applyFilters()` established and
         * states the reason for: `?ss_x[]=v` makes these arrays, and the next filter added here may
         * compare directly, which is where an array becomes an "Array to string conversion" and a
         * 500 from a query string. One guard per read makes the safe shape the default.
         *
         * AN UNRECOGNISED VALUE DECLINES RATHER THAN NARROWING TO NOTHING. A merchant whose url has
         * a typo sees the unfiltered list, not an empty one -- and the receipt stays silent for the
         * same value, so the screen and its wording agree.
         */

        $status = $request->query('ss_status');

        if (is_string($status) && in_array($status, ['0', '1'], true)) {
            $query->where('flash_sales.is_active', (int) $status);
        }

        $type = $request->query('ss_type');

        /* `flash_sales.discount_type` is the narrower pair: there is no product-level type on a
           flash sale, so the coupon screen's third value is deliberately absent. */
        if (is_string($type) && in_array($type, ['percentage', 'flat'], true)) {
            $query->where('flash_sales.discount_type', $type);
        }

        $window = $request->query('ss_window');

        if (is_string($window) && in_array($window, ['running', 'upcoming', 'ended'], true)) {
            $today = \Carbon\Carbon::now()->toDateString();

            /*
             * BOTH BOUNDS ARE INCLUSIVE, and that is the boundary this has to get right: a sale
             * that starts AND ends today is RUNNING. A `<` on either side would drop a one-day sale
             * out of all three windows, which reads as a broken filter rather than an empty result.
             * `start_date` and `end_date` are DATE columns, so whereDate compares exactly.
             */
            if ($window === 'running') {
                $query->whereDate('flash_sales.start_date', '<=', $today)
                    ->whereDate('flash_sales.end_date', '>=', $today);
            } elseif ($window === 'upcoming') {
                $query->whereDate('flash_sales.start_date', '>', $today);
            } else {
                $query->whereDate('flash_sales.end_date', '<', $today);
            }
        }

        $from = TableFilterMemory::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('flash_sales.created_at', '>=', $from->copy()->startOfDay());
        }

        $to = TableFilterMemory::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /* `endOfDay()`, because `created_at` is a DATETIME: a bare `<= '2026-03-17'` compares
               against midnight and drops everything recorded on the day the merchant typed. */
            $query->where('flash_sales.created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
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
