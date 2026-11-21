<?php

namespace App\DataTables;

use App\Services\TableFilterMemory;
use App\Models\Wishlist;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class WishlistDataTable extends DataTable
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
         * P2.5-PKG-I. ESCAPED. These two columns are in this table's `rawColumns()` list,
         * so yajra emits them WITHOUT escaping, and both carry shopper-written
         * registration data. A shopper whose first name is `<img src=x onerror=...>` got it
         * EXECUTED in the merchant's admin the moment this report was opened.
         *
         * `app/Http/Middleware/XSS.php` is not the control: it strips the literal words
         * `javascript` and `alert` from `web` input, which a payload simply avoids. That is
         * why AbandonedListOutputEscapingTest's needle contains neither.
         *
         * `e()` IS APPLIED TO EACH VALUE, never to the finished string -- escaping the
         * whole concatenation would also escape the markup these columns legitimately
         * render, and the merchant would meet visible tag soup. Same shape as the repair
         * already made on CustomerDataTable:69-70 and OrderDataTable.
         */
        ->editColumn('customer_id', function (Wishlist $wishlist) {
            return isset($wishlist->UserData)
                ? e((string) $wishlist->UserData->first_name) . ' ' . e((string) $wishlist->UserData->last_name)
                : null;
        })
        ->editColumn('email', function (Wishlist $wishlist) {
            return isset($wishlist->UserData) ? e((string) $wishlist->UserData->email) : '';
        })
        ->addColumn('product', function (Wishlist $wishlist) {
            $wishlist_count = Wishlist::where('customer_id',$wishlist->customer_id)->where('store_id', getCurrentStore())->count();
            return view('wishlist.product', compact('wishlist','wishlist_count'));
        })
        ->addColumn('action', function (Wishlist $wishlist) {
            return view('wishlist.action', compact('wishlist'));
        })
        ->filterColumn('customer_id', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('UserData', function ($subQuery) use ($keyword) {
                $subQuery->where('first_name', 'like', "%$keyword%")->orWhere('last_name', 'like', "%$keyword%");
            });
        })
        ->filterColumn('email', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('UserData', function ($subQuery) use ($keyword) {
                $subQuery->where('email', 'like', "%$keyword%");
            });
        })
        ->orderColumn('customer_id', function ($query, $direction) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('UserData', function ($subQuery) use ($direction) {
                $subQuery->orderBy('first_name', $direction)->orderBy('last_name', $direction);
            });
        })
        ->orderColumn('email', function ($query, $direction) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('UserData', function ($subQuery) use ($direction) {
                $subQuery->orderBy('email', $direction);
            });
        })
        ->rawColumns(['customer_id','email','product','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Wishlist $model): QueryBuilder
    {
        /* The filters are applied BEFORE `groupBy()` is appended, so every predicate below is a
           WHERE over the ungrouped rows. That is the correct half of the aggregate distinction:
           `status` is a per-ROW column, so a HAVING here would compare something no aggregate
           produced. The abandoned-cart table is the other half -- see its `ss_items`. */
        return $this->applyFilters(
            $model->newQuery()->with('UserData')->where('store_id', getCurrentStore())
        )->groupBy('customer_id');
    }

    /** MKT-002's wishlist filters, all per-row and therefore all WHERE. */
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
            $query->where('wishlists.status', (int) $status);
        }

        $from = TableFilterMemory::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('wishlists.created_at', '>=', $from->copy()->startOfDay());
        }

        $to = TableFilterMemory::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /* `endOfDay()`, because `created_at` is a DATETIME: a bare `<= '2026-03-17'` compares
               against midnight and drops everything recorded on the day the merchant typed. */
            $query->where('wishlists.created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('wishlist-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('Customer'), 'addClass' => 'text-capitalize'],
            'email' => ['title' => __('Email')],
            'product' => ['title' => __('Products'), 'searchable' => false]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Wishlist_' . date('YmdHis');
    }
}
