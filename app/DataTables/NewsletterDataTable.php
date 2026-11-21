<?php

namespace App\DataTables;

use App\Services\TableFilterMemory;
use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class NewsletterDataTable extends DataTable
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
        ->editColumn('created_at', function (Newsletter $newsletter) {
            return isset($newsletter->created_at) ? $newsletter->created_at->format('Y-m-d H:i:s') : '-';
        })
        ->addColumn('action', function (Newsletter $newsletter) {
            return view('newsletter.action', compact('newsletter'));
        })
        // P1-SEC-80. `email` and `created_at` are NOT raw: `email` is a VISITOR's string (the storefront footer form stores whatever is posted, and Laravel's `email` rule would
        // accept a quoted local part holding markup anyway), so yajra escapes it; `created_at` is a plain date and needs nothing. Only the action partial is markup.
        ->rawColumns(['action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Newsletter $model): QueryBuilder
    {
        return $this->applyFilters($model->newQuery()->where('store_id', getCurrentStore()));
    }

    /**
     * MKT-002's newsletter filter, and there is only one it can honestly offer: `newsletters` holds
     * `customer_id`, `email` and `store_id` and nothing else, so "is this a registered customer or
     * just an address" is the single distinction the table can make. A status or a type filter here
     * would be a column that does not exist.
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

        $source = $request->query('ss_source');

        if (is_string($source) && in_array($source, ['customer', 'guest'], true)) {
            /*
             * NULL **AND** 0 BOTH MEAN "NO CUSTOMER", and treating only NULL as a guest is the
             * defect this branch exists to avoid: the column is an `unsignedBigInteger` with no
             * default, and a subscribe path that has no customer in hand writes 0 as readily as it
             * writes null. A `whereNull` alone would file every one of those under "registered
             * customer" and show the merchant a customer id of 0.
             */
            if ($source === 'customer') {
                $query->whereNotNull('newsletters.customer_id')->where('newsletters.customer_id', '>', 0);
            } else {
                $query->where(static function ($inner): void {
                    $inner->whereNull('newsletters.customer_id')
                        ->orWhere('newsletters.customer_id', '<=', 0);
                });
            }
        }

        $from = TableFilterMemory::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('newsletters.created_at', '>=', $from->copy()->startOfDay());
        }

        $to = TableFilterMemory::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /* `endOfDay()`, because `created_at` is a DATETIME: a bare `<= '2026-03-17'` compares
               against midnight and drops everything recorded on the day the merchant typed. */
            $query->where('newsletters.created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('newsletter-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'email' => ['title' => __('Email')],
            'created_at' => ['title' => __('Created At')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Newsletter_' . date('YmdHis');
    }
}
