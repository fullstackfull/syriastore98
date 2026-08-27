<?php

namespace Workdo\ReviewReminder\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\ReviewReminder\app\Models\OrderReviews;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class ReviewOrdersDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->editColumn('product_order_id', function ($prequest) {
                $order = \App\Models\Order::find($prequest->order_id);
                $orderNumber = $order ? $order->product_order_id : $prequest->order_id;
                $html = '<a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($prequest->order_id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                <span class="btn-inner--icon"></span>
                <span class="btn-inner--text">#' . $orderNumber . '</span>
                    </a>';

                return $html;

            })
            ->editColumn('name', function ($prequest) {
                return $prequest->name;
            })
            ->editColumn('description', function ($prequest) {
                $description = !empty($prequest) ? $prequest->description : '-';
                $html = '<textarea class="form-control" placeholder="Enter Description" rows="2" readonly required>' . e($description) . '</textarea>';
                return $html;

            })
            ->editColumn('rating', function ($prequest) {
                return $prequest->rating;
            })
            ->editColumn('action', function ($prequest) {
                return view('review-reminder::action_btn', compact('prequest'));
            })
            ->addIndexColumn()
            ->rawColumns(['product_order_id', 'description', 'action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(OrderReviews $model): QueryBuilder
    {

        $quary = $model->where('store_id', getCurrentStore());
        return $quary;

    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('order-reviews-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */

        public function getColumns(): array
    {
        return buildDataTableColumn([
            'product_order_id' => ['title' => __('Order Id')],
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('E-Mail')],
            'rating' => ['title' => __('Rating')],
            'description' => ['title' => __('Review')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ReviewOrders_' . date('YmdHis');
    }
}
