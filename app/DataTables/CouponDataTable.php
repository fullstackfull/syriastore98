<?php

namespace App\DataTables;

use App\Services\TableFilterMemory;
use App\Models\Coupon;
use App\Models\Utility;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class CouponDataTable extends DataTable
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
        ->editColumn('discount_amount', function (Coupon $coupon) {
            if ($coupon->coupon_type == 'flat' || $coupon->coupon_type == 'fixed product discount') {
                $icon = 'ti ti-currency-dollar';
            } else {
                $icon = 'ti ti-percentage';
            }
            return e($coupon->discount_amount) . '<i class="'.$icon.'"></i> '. __('Discount');
        })
        ->editColumn('coupon_expiry_date', function (Coupon $coupon) {
            return isset($coupon->coupon_expiry_date) ? Utility::dateFormat($coupon->coupon_expiry_date) : '';
        })
        ->addColumn('action', function (Coupon $coupon) {
            return view('coupon.action', compact('coupon'));
        })
        // P1-SEC-80. The coupon's name and code are plain text a staff member types, and the limit and the expiry are plain text: yajra escapes them. `discount_amount` is built here around an icon
        // and escapes the number.
        ->rawColumns(['discount_amount','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Coupon $model): QueryBuilder
    {
        return $this->applyFilters($model->newQuery()->where('store_id', getCurrentStore()));
    }

    /**
     * MKT-002's coupon filters. Called AFTER the tenant predicate and only ever ADDS predicates, so
     * this narrows a set already confined to the caller's own store and is not standing in for a
     * tenant check. Every value is checked against the vocabulary `TableFilterMemory` words, so the
     * list and its receipt cannot disagree about what is in force.
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
            $query->where('coupons.status', (int) $status);
        }

        $type = $request->query('ss_type');

        /* The three literals `coupons.coupon_type` actually stores, read off CouponController's own
           comparisons (`:60`, `:71`, `:102`). Not a guess and not a new vocabulary. */
        if (is_string($type) && in_array($type, ['percentage', 'fixed product discount', 'flat'], true)) {
            $query->where('coupons.coupon_type', $type);
        }

        $expiry = $request->query('ss_expiry');

        if (is_string($expiry) && in_array($expiry, ['expired', 'current'], true)) {
            /* `coupon_expiry_date` is a DATE, so a plain date comparison is exact here -- no
               endOfDay() needed, and adding one would be cargo-culted from the datetime columns.
               `current` is `>=` today: a coupon expiring TODAY has not expired yet. */
            $query->whereDate(
                'coupons.coupon_expiry_date',
                $expiry === 'expired' ? '<' : '>=',
                \Carbon\Carbon::now()->toDateString()
            );
        }

        $from = TableFilterMemory::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('coupons.created_at', '>=', $from->copy()->startOfDay());
        }

        $to = TableFilterMemory::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /* `endOfDay()`, because `created_at` is a DATETIME: a bare `<= '2026-03-17'` compares
               against midnight and drops everything recorded on the day the merchant typed. */
            $query->where('coupons.created_at', '<=', $to->copy()->endOfDay());
        }

        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('coupon-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'coupon_name' => ['title' => __('Name'), 'addClass' => 'ss-dt-ident'],
            'coupon_code' => ['title' => __('Code')],
            'discount_amount' => ['title' => __('Discount'), 'addClass' => 'ss-dt-num'],
            'coupon_limit' => ['title' => __('Limit')],
            'coupon_expiry_date' => ['title' => __('Expiry Date')],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Coupon_' . date('YmdHis');
    }
}
