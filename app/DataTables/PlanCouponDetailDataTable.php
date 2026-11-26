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
        /*
         * GAP-179 / P06-06 slice 3 -- A `btn-primary` FILL BUILT IN PHP, WHICH NO BLADE CENSUS SEES.
         *
         * This cell used to be `<div class="btn btn-primary btn-sm text-sm">` wrapping the order
         * reference. Measured as the operator on `/plan-coupon/{id}`, APP_DEBUG off:
         * `span.btn-inner--text` at **2.58:1 in dark**, rgb(4,34,42) on rgb(51,93,168) -- the
         * host's primary fill, which `style-dark.css` paints
         * `--bs-btn-color: var(--theme-color)` on `--bs-btn-bg: var(--third-color)`. The same
         * defect slice 1 existed to close, in a place no view carries: a raw-HTML string inside a
         * DataTable closure. `GAP-296`'s census of 425 occurrences reads Blade files, so this was
         * outside its population too.
         *
         * AND IT WAS INVISIBLE FOR A SECOND REASON: `plan_user_coupons` was empty on the dev
         * fixture, so the column rendered no cell at all. An empty grid hides a cell defect
         * exactly as an absent surface hides a screen -- the same lesson as this slice's two
         * unmeasurable surfaces, one level down. `seed-p2dev.php` now seeds one redemption row.
         *
         * `.ss-code` IS THE REUSE, AND THE SEMANTICS ARE THE REAL POINT. The element was never a
         * button: it has no handler, no href and no role, so a filled pill told the operator it
         * was actionable. An order reference is a machine identifier, which is what `.ss-code`
         * is for -- slice 1 added it for `/platform/resources`' storage paths. It takes `--ss-fg`
         * on `--ss-surface-sunken` (#1a1d23 on #eeeff1 light), so it re-themes by construction,
         * and its `unicode-bidi: isolate` keeps an ASCII reference from reordering inside the
         * Arabic page -- a correctness gain the fill never had. No new CSS.
         *
         * `e()` stays. The column is in `rawColumns`, so this string reaches the browser
         * unescaped and the order reference is stored text.
         */
        ->addColumn('order_id', function ($row) {
            return '<span class="ss-code">#' . e($row->order) . '</span>';
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
