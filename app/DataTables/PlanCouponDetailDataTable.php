<?php

namespace App\DataTables;

use App\Models\PlanUserCoupon;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PlanCouponDetailDataTable extends DataTable
{
    protected $planCouponId;

    /**
     * Set the PlanCoupon ID for filtering.
     *
     * @param int $planCouponId
     */
    public function setPlanCouponId(int $planCouponId)
    {
        $this->planCouponId = $planCouponId;
    }

    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {

        return datatables()
        ->eloquent($query)
        ->addColumn('title', function ($row) {
            return $row->coupon_detail->name ?? null;
        })
        ->addColumn('order_id', function ($row) {
            return '<div class="btn btn-primary btn-sm text-sm" data-toggle="tooltip" ">
                            <span class="btn-inner--icon"></span>
                            <span class="btn-inner--text">#'. $row->order . '</span>
                        </div>';
        })
        ->addColumn('amount', function ($row) {
            if (isset($row->order_detail->price)) {
                return $row->order_detail->price;
            } elseif (module_is_active('Campaigns')) {
                $campaignsData = \Workdo\Campaigns\app\Models\Campaigns::find($row->order);
                if ($campaignsData) {
                    return $campaignsData->total_cost;
                }
            }
            return $row->order_detail->price ?? '-';
        })
        ->editColumn('created_at', function ($row) {
            return isset($row->created_at) ? $row->created_at->format('Y-m-d H:i:s') : '-';
        })
        ->filterColumn('title', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('coupon_detail', function ($subQuery) use ($keyword) {
                $subQuery->where('name', 'like', "%$keyword%");
            });
        })
        ->filterColumn('amount', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->whereHas('order_detail', function ($subQuery) use ($keyword) {
                $subQuery->where('price', 'like', "%$keyword%");
            });
        })
        ->filterColumn('order_id', function ($query, $keyword) {
            // Assuming `permissions` is a relationship, adjust as needed
            $query->where('order', 'like', "%$keyword%");
        })
        ->rawColumns(['order_id']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(PlanUserCoupon $model): QueryBuilder
    {
    return $model->newQuery()->where('coupon_id', $this->planCouponId);
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('plan-coupon-detail-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'title' => ['title' => __('Title')],
            'order_id' => ['title' => __('Order ID')],
            'amount' => ['title' => __('Amount')],
            'created_at' => ['title' => __('Date')]
        ], false);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Coupon_detail_' . date('YmdHis');
    }
}
