<?php

namespace App\DataTables;

use App\Models\UserCoupon;
use App\Models\Coupon;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class UserCouponDataTable extends DataTable
{
    protected $couponId;

    /**
     * Set the PlanCoupon ID for filtering.
     *
     * @param int $couponId
     */
    public function setCouponId(int $couponId)
    {
        $this->couponId = $couponId;
    }
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
        ->addIndexColumn()
        ->editColumn('coupon_name', function (UserCoupon $coupon) {
            return $coupon->CouponData ? $coupon->CouponData->coupon_name : '';
        })
        ->editColumn('product_order_id', function (UserCoupon $coupon) {
            return $coupon->OrderData ? ('#'.$coupon->OrderData->product_order_id) : '';
        })
        ->editColumn('product_order_id', function (UserCoupon $coupon) {
            return '<div class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                        <span class="btn-inner--icon"></span>
                        <span class="btn-inner--text">#'. $coupon->OrderData->product_order_id . '</span>
                    </div>';
        })
        ->filterColumn('coupon_name', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('CouponData', function ($subQuery) use ($keyword) {
                $subQuery->where('coupon_name', 'like', "%$keyword%");
            });
        })
        ->filterColumn('product_order_id', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('OrderData', function ($subQuery) use ($keyword) {
                $subQuery->where('product_order_id', 'like', "%$keyword%");
            });
        })
        ->orderColumn('coupon_name', function ($query, $direction) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('CouponData', function ($subQuery) use ($direction) {
                $subQuery->orderBy('coupon_name', $direction);
            });
        })
        ->orderColumn('product_order_id', function ($query, $direction) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('OrderData', function ($subQuery) use ($direction) {
                $subQuery->orderBy('product_order_id', $direction);
            });
        })
        ->rawColumns(['coupon_name','product_order_id','amount', 'date_used']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(UserCoupon $model): QueryBuilder
    {
        return $model->newQuery()->where('coupon_id', $this->couponId);
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('usercoupon-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'coupon_name' => ['title' => __('Name')],
            'product_order_id' => ['title' => __('Order ID')],
            'amount' => ['title' => __('Amount')],
            'date_used' => ['title' => __('Date')]
        ], false);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'UserCoupon_' . date('YmdHis');
    }
}
