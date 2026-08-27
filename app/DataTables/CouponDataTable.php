<?php

namespace App\DataTables;

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
            return $coupon->discount_amount . '<i class="'.$icon.'"></i> '. __('Discount');
        })
        ->editColumn('coupon_expiry_date', function (Coupon $coupon) {
            return isset($coupon->coupon_expiry_date) ? Utility::dateFormat($coupon->coupon_expiry_date) : '';
        })
        ->addColumn('action', function (Coupon $coupon) {
            return view('coupon.action', compact('coupon'));
        })
        ->rawColumns(['coupon_name','coupon_code','discount_amount','coupon_limit', 'coupon_expiry_date','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Coupon $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id', getCurrentStore());
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
            'coupon_name' => ['title' => __('Name')],
            'coupon_code' => ['title' => __('Code')],
            'discount_amount' => ['title' => __('Discount')],
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
