<?php

namespace App\DataTables;

use App\Models\OrderRefund;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class OrderRefundDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (OrderRefund $refund_request) {
                return view('order.refund-action', compact('refund_request'));
            })
            ->addColumn('order_id', function ($refund_request) {
                $order_id_encrypted = \Illuminate\Support\Facades\Crypt::encrypt($refund_request->order_id);
                $order_id_display = $refund_request->order ? '#' . $refund_request->order->product_order_id : '#N/A';
                
                $html = '<div class="d-flex align-items-center">
                            <a href="' . route('refund-request.show', $order_id_encrypted) . '"
                               class="btn btn-primary btn-sm text-sm"
                               data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">' . $order_id_display . '</span>
                            </a>
                        </div>';

                return $html;
            })
            ->addColumn('created_at', function ($refund_request) {
                return \Carbon\Carbon::parse($refund_request['created_at'])->format('Y-m-d');
            })
            ->addColumn('refund_status', function ($refund_request) {
                $badge_class = 'bg-light-success';
                if ($refund_request->refund_status == 'Cancel') {
                    $badge_class = 'bg-light-danger';
                } elseif ($refund_request->refund_status == 'Processing') {
                    $badge_class = 'bg-light-info';
                } elseif ($refund_request->refund_status == 'Refunded') {
                    $badge_class = 'bg-light-warning';
                }
                return '<span class="badge badge-80 rounded p-2 f-w-600 ' . $badge_class . '">'
                       . $refund_request['refund_status'] . '</span>';
            })
            ->filterColumn('order_id', function ($query, $keyword) {
                $query->whereHas('order', function ($subQuery) use ($keyword) {
                    $subQuery->where('product_order_id', 'like', "%$keyword%");
                });
            })
            ->rawColumns(['action', 'order_id', 'created_at', 'refund_status']);
        return $dataTable;
    }


    /**
     * Get the query source of dataTable.
     */
    public function query(OrderRefund $model): QueryBuilder
    {
        return $model->newQuery()->with('order')->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('order-refunds-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'order_id' => ['title' => __('Order Id')],
            'created_at' => ['title' => __('Refund Request Date')],
            'refund_status' => ['title' => __('Refund Request Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'OrderRefund_' . date('YmdHis');
    }
}
