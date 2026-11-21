<?php

namespace App\DataTables;

use App\Services\TableFilterMemory;
use App\Models\Cart;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class AbandonCartDataTable extends DataTable
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
                ->editColumn('customer_id', function (Cart $cart) {
                    return isset($cart->UserData)
                        ? e((string) $cart->UserData->first_name) . ' ' . e((string) $cart->UserData->last_name)
                        : null;
                })
                ->editColumn('email', function (Cart $cart) {
                    return isset($cart->UserData) ? e((string) $cart->UserData->email) : '';
                })
                ->addColumn('product', function (Cart $cart) {
                    $cart_count = Cart::where('customer_id',$cart->customer_id)->where('store_id', getCurrentStore())->count();
                    return view('cart.product', compact('cart','cart_count'));
                })
                ->addColumn('action', function (Cart $cart) {
                    return view('cart.action', compact('cart'));
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
    public function query(Cart $model): QueryBuilder
    {
        $query = $model->newQuery()
            ->selectRaw('
            MIN(carts.id) as id,
            carts.customer_id,
            MIN(carts.product_id) as product_id,
            MIN(carts.qty) as quantity,
            COUNT(*) as total_carts
        ')->with('UserData')->where('store_id', getCurrentStore())
            ->whereNotNull('carts.customer_id')
            ->groupBy('carts.customer_id');

        return $this->applyFilters($query);
    }

    /**
     * MKT-002's abandoned-cart filters, and THE ONE AGGREGATE PREDICATE IN THE SLICE.
     *
     * This table is not shaped like the other four. It selects `COUNT(*) as total_carts` and groups
     * by `carts.customer_id`, so one row out is one SHOPPER, not one basket line. That makes
     * `ss_items` a question about the group rather than the row, and the distinction is not
     * stylistic:
     *
     *   * `HAVING COUNT(*) = 1` is evaluated after the grouping and is the predicate meant here.
     *   * `WHERE total_carts = 1` references an alias no row carries. SQLite quietly resolves it to
     *     nothing and MySQL raises "Unknown column 'total_carts' in 'where clause'", so the same
     *     mistake is a wrong list on one engine and a 500 on the other. The suite runs on SQLite,
     *     which is the engine that would have hidden it.
     *   * `WHERE` on a date, by contrast, IS correct -- `created_at` is a per-row column, so
     *     `ss_from`/`ss_to` narrow the lines before they are grouped. Both kinds live here together
     *     on purpose, because that is what makes the difference legible.
     *
     * `havingRaw` CARRIES NO CALLER INPUT. The comparison is chosen from a two-value allowlist and
     * the SQL is a literal either way; the merchant's value never reaches the string. It is also not
     * a raw query ENTRY POINT -- `RawQueryTenancyInventoryTest::RAW_ENTRY_POINT_PATTERN` matches
     * `DB::table|select|insert|update|delete|statement|unprepared`, and this is a predicate on an
     * Eloquent builder that already carries `where('store_id', getCurrentStore())`.
     *
     * ONE / SEVERAL RATHER THAN A NUMBER, deliberately. "More than N items" is a marketing
     * threshold, and the standing instruction is not to invent one. These two are a fact about the
     * row count, not a policy about which baskets are worth chasing.
     */
    private function applyFilters(QueryBuilder $query): QueryBuilder
    {
        $request = request();

        /* `is_string` on every read -- see `ProductDataTable::applyFilters()` for why -- and an
           unrecognised value declines rather than narrowing to nothing, so a typo in a url shows the
           unfiltered list and the receipt stays silent for the same value. */
        $items = $request->query('ss_items');

        if (is_string($items) && in_array($items, ['one', 'several'], true)) {
            $query->havingRaw($items === 'one' ? 'COUNT(*) = 1' : 'COUNT(*) > 1');
        }

        $from = TableFilterMemory::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('carts.created_at', '>=', $from->copy()->startOfDay());
        }

        $to = TableFilterMemory::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /* `endOfDay()`, because `created_at` is a DATETIME: a bare `<= '2026-03-17'` compares
               against midnight and drops everything recorded on the day the merchant typed. */
            $query->where('carts.created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('abandoncart-table', $this->builder(), $this->getColumns());        
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('Customer'), 'addClass' => 'text-capitalize'],
            'email' => ['title' => __('Email')],
            'product' => ['title' => __('Products'), 'searchable' => false],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'AbandonCart_' . date('YmdHis');
    }
}
