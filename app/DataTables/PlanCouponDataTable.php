<?php

namespace App\DataTables;

use App\Models\PlanCoupon;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PlanCouponDataTable extends DataTable
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
            ->editColumn('discount',function(PlanCoupon $coupon){
                return  $coupon->discount .( $coupon->type == 'percentage' ? ' (%)' : '');
            })
            ->addColumn('action', function (PlanCoupon $coupon) {
                return view('plan-coupon.action', compact('coupon'));
            })
            ->rawColumns(['discount','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(PlanCoupon $model): QueryBuilder
    {
        return $model->newQuery();
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('plan-coupon-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'code' => ['title' => __('Code')],
            'discount' => ['title' => __('Discount')],
            'limit' => ['title' => __('Limit')]
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
