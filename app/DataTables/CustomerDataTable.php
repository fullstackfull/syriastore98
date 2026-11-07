<?php

namespace App\DataTables;

use App\Models\Customer;
use App\Models\ActivityLog;
use App\Support\Customers\CustomerListFilter;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;
use Illuminate\Http\Request;

class CustomerDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $activitylog = ActivityLog::groupBy('customer_id')->where('store_id', getCurrentStore())->get();
        return (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('first_name', function (Customer $customer) {
                /*
                 * F98. ESCAPED. `first_name` is in this table's `rawColumns()` list, so yajra does
                 * NOT escape it, and this cell is built by string concatenation -- so all three
                 * values below used to reach the merchant's admin page as markup.
                 *
                 * ALL THREE ARE WRITTEN BY A SHOPPER. `CustomerLoginController::register`
                 * validates `first_name` as exactly `['required','string','max:255']` and nothing
                 * more, so a shopper of this merchant's own store chooses the string this cell
                 * prints.
                 *
                 * THE XSS MIDDLEWARE DOES NOT COVER IT, and reading it is the point:
                 * `app/Http/Middleware/XSS.php` runs `htmlspecialchars_decode()` and THEN strips
                 * only the four strings `&lt;`, `&gt;`, `javascript`, `alert`. The decode turns an
                 * encoded `&lt;` into a raw `<` before the strip looks for `&lt;`, and a raw `<`
                 * was never a target -- so `<img src=x onerror=...>` passes through whole as long
                 * as it avoids two words. A denylist of four strings is not an escaping layer and
                 * nothing downstream supplies one.
                 *
                 * `e()` is applied to EACH VALUE, not to the finished string: escaping the whole
                 * cell would double-escape this file's own `<a>` and `<br>` and render visible tag
                 * soup. `CustomerListOutputEscapingTest` pins both halves.
                 *
                 * Identical in shape and remedy to `OrderDataTable:63-90`, which fixed the same
                 * defect for the same two shopper-written fields. This was the surface that sweep
                 * did not reach.
                 *
                 * THE `if ($activityLogEntry)` BRANCHES WERE BYTE-IDENTICAL and are collapsed.
                 * `$activitylog` is no longer captured because nothing in here reads it. That is
                 * not tidying for its own sake: two identical branches are where a later fix lands
                 * in one copy and not the other, which is exactly how an escaping bug survives.
                 */
                if (! $customer) {
                    return null;
                }

                return '<a class="text-primary" href="' . route('customer.timeline', $customer->id) . '">
                        <span class="btn-inner--icon"></span>
                        <span class="btn-inner--text text-capitalize">'
                    . e((string) $customer->first_name) . ' ' . e((string) $customer->last_name)
                    . '</span><br></a>' . e((string) $customer->mobile);
            })
            ->editColumn('last_active', function (Customer $customer) {
                if ($customer && $customer->last_active) { // Ensure $customer is not null
                    $active = \Carbon\Carbon::parse($customer->last_active);
                    return $active->format('F d, Y');
                }
                return null;
            })
            ->editColumn('regiester_date', function (Customer $customer) {
                if ($customer && $customer->regiester_date) { // Ensure $customer is not null
                    $carbonDate = \Carbon\Carbon::parse($customer->regiester_date);
                    return $carbonDate->format('F d, Y');
                }
                return null;
            })
            ->editColumn('orders', function (Customer $customer) {
                if ($customer) { // Ensure $customer is not null
                    return '<a href="' . route('customer.show', $customer->id) . '">' . $customer->Ordercount() . '</a>';
                }
                return null;
            })
            ->editColumn('total_spend', function (Customer $customer) {
                return $customer ? ($customer->total_spend() ?? 0) : 0; // Ensure $customer is not null
            })
            ->editColumn('aov', function (Customer $customer) {
                if ($customer && $customer->total_spend() && $customer->Ordercount()) {
                    return number_format($customer->total_spend() / $customer->Ordercount(), 2);
                }
                return 0;
            })
            ->addColumn('action', function (Customer $customer) use ($activitylog) {
                if ($customer) {
                    $activityLogEntry = $activitylog->where('customer_id', $customer->id)->first();
                    return view('customer.action', compact('customer', 'activityLogEntry'));
                } else {
                    return null;
                }
            })
            ->addColumn('status', function (Customer $customer) {
                if ($customer) {
                    return view('customer.status', compact('customer'));
                } else {
                    return null;
                }
            })
            ->filterColumn('first_name', function ($query, $keyword) {
                $query->whereRaw("CONCAT(first_name, ' ', last_name) like ?", ["%{$keyword}%"])->orWhere('mobile', 'Like', "%{$keyword}%");
            })
            /*
             * F98. `'email'` IS REMOVED from this list rather than escaped.
             *
             * It had no `editColumn` at all, so yajra emitted the model value verbatim -- a
             * shopper-chosen string straight into the merchant's page. Nothing in that cell is
             * ever markup, so the right answer is not to escape it here but to stop declaring it
             * raw: off this list, yajra escapes it itself, and no closure is needed.
             *
             * The rest stay. `first_name`, `orders`, `action` and `status` are real markup this
             * file or a view produces; `last_active`, `regiester_date`, `total_spend` and `aov`
             * are server-computed dates and numbers whose closures cannot emit shopper text.
             * `CustomerListOutputEscapingTest::test_every_raw_column_has_a_closure_that_decides_its_escaping`
             * fails if a future entry is added here without a closure to own its escaping.
             */
            ->rawColumns(['first_name', 'last_active', 'regiester_date', 'orders', 'total_spend', 'aov', 'action', 'status']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Customer $model, Request $request): QueryBuilder
    {
        $query = $model->newQuery()->where('store_id', getCurrentStore());

        /*
         * P2.5-R-06 -- ONE SHARED FILTER, because this method and
         * CustomerController::CustomFilterData() each carried their own ~100-line copy and the two
         * had drifted apart. All three numeric faults existed in BOTH: the AOV `'Equal' && $AOV =
         * $text_field_float` ASSIGNMENT (truthy for any non-zero input, so it matched every
         * customer with a computable average), a duplicated `'Less Than'` branch whose second limb
         * tested `>` (so "Less Than 3" returned everything except exactly 3), and NO `'More Than'`
         * branch at all for `No. of Orders` or `Total Spend` (so that operator answered with an
         * empty screen).
         *
         * The extraction is the repair, not housekeeping: fault 3 survived P2.5-R-02 precisely
         * because a reader who fixed one copy had not fixed the screen.
         *
         * THE TENANT PREDICATE STAYS HERE, on the line above. CustomerListFilter::apply() takes an
         * already-scoped Builder, has no $storeId parameter and never reads the request, so it can
         * only ever narrow what it is handed. A tenant predicate built from caller-supplied input
         * is not a tenant check -- CLAUDE.md records four separate defects from that -- and none of
         * the three values passed below can name a store.
         *
         * THE REQUEST KEYS ARE UNCHANGED. This screen has always sent `field_name`; the grid sends
         * `customer_field`. Both spellings are live in bookmarks and in shipped markup, so each
         * caller reads its own key and passes the resolved value in.
         */
        $requestData = $request->all();

        $query = CustomerListFilter::apply(
            $query,
            $requestData['field_name'] ?? null,
            $requestData['selected_name'] ?? null,
            $requestData['text_field'] ?? null
        );

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('customer-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'first_name' => ['title' => __('Customer Info'), 'addClass' => 'ss-dt-ident'],
            'email' => ['title' => __('Email')],
            'last_active' => ['title' => __('Last Active')],
            'regiester_date' => ['title' => __('Date Registered')],
            'orders' => ['title' => __('Orders'), 'orderable' => false, 'searchable' => false],
            'total_spend' => ['title' => __('Total Spend'), 'orderable' => false, 'searchable' => false, 'addClass' => 'ss-dt-num'],
            'aov' => ['title' => __('AOV'), 'orderable' => false, 'searchable' => false, 'addClass' => 'ss-dt-num'],
            'status' => ['title' => __('Status'), 'exportable' => false, 'printable' => false, 'width' => 60],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Customer_' . date('YmdHis');
    }
}
