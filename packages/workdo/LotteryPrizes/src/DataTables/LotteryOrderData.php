<?php

namespace Workdo\LotteryPrizes\DataTables;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\LotteryPrizes\app\Models\PurchaseTicket;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class LotteryOrderData extends DataTable
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
            ->addColumn('action', function (Order $order) {
                return view('lottery-prizes::admin.lottery-order.action', compact('order'));
            })
            ->editColumn('product_order_id', function ($item) {
                $btnClass = 'bg-info';
                if ($item->delivered_status == 2 || $item->delivered_status == 3) {
                    $btnClass = 'bg-danger';
                } elseif ($item->delivered_status == 1) {
                    $btnClass = 'bg-success';
                } elseif ($item->delivered_status == 4) {
                    $btnClass = 'btn-warning';
                } elseif ($item->delivered_status == 5) {
                    $btnClass = 'btn-secondary';
                } elseif ($item->delivered_status == 6) {
                    $btnClass = 'btn-dark';
                }

                return '<a href="' . route('lottery.order.view', \Illuminate\Support\Facades\Crypt::encrypt($item->id)) . '"
                    class="btn ' . $btnClass . ' btn-sm text-sm" data-toggle="tooltip" title="' . __('Invoice ID') . '">
                    <span class="btn-inner--text">#' . $item->product_order_id . '</span>
                </a>';
            })
            ->editColumn('order_date', function ($item) {
                $date = \App\Models\Utility::dateFormat($item->order_date);
                return $date;
            })
            ->editColumn('customer_id', function ($item) {
                $lottery = PurchaseTicket::where('lottery_code',$item->lottery_code)->first();
                return !empty($lottery->firstname) ? $lottery->firstname . '<br>' . $lottery->number : '';
            })
            ->editColumn('sub_total', function ($item) {
                return currency_format_with_sym($item->final_price ?? 0, getCurrentStore()) ?? SetNumberFormat($item->final_price);
            })
            ->editColumn('lottery_code', function ($item) {
                return !empty($item->lottery_code) ? $item->lottery_code : '';
            })
            ->editColumn('delivered_status', function ($item) {
                $buttonHtml = '';
                switch ($item->delivered_status) {
                    case 0:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-info btn-icon rounded-pill badge-same">
                            <span class="btn-inner--icon"><i class="fas fa-check soft-info"></i></span>
                            <span class="btn-inner--text">' . __('Pending') . ' : ' . \App\Models\Utility::dateFormat($item->order_date) . '</span>
                        </button>';
                        break;
                    case 1:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-success btn-icon bg-success rounded-pill badge-same">
                            <span class="btn-inner--text">' . __('Delivered') . ' : ' . \App\Models\Utility::dateFormat($item->delivery_date) . '</span>
                        </button>';
                        break;
                    case 2:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-danger btn-icon bg-danger rounded-pill badge-same">
                            <span class="btn-inner--text">' . __('Cancel') . ' : ' . \App\Models\Utility::dateFormat($item->cancel_date) . '</span>
                        </button>';
                        break;
                    case 3:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-danger btn-icon bg-danger rounded-pill badge-same">
                            <span class="btn-inner--text">' . __('Return') . ' : ' . \App\Models\Utility::dateFormat($item->return_date) . '</span>
                        </button>';
                        break;
                    case 4:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-warning btn-icon bg-warning rounded-pill badge-same">
                            <span class="btn-inner--text">' . __('Confirmed') . ' : ' . \App\Models\Utility::dateFormat($item->confirmed_date) . '</span>
                        </button>';
                        break;
                    case 5:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-secondary btn-icon rounded-pill badge-same">
                            <span class="btn-inner--icon"><i class="fas fa-check soft-secondary"></i></span>
                            <span class="btn-inner--text">' . __('Picked Up') . ' : ' . \App\Models\Utility::dateFormat($item->picked_date) . '</span>
                        </button>';
                        break;
                    case 6:
                        $buttonHtml = '<button type="button" class="btn btn-sm btn-soft-dark btn-icon bg-dark rounded-pill badge-same">
                            <span class="btn-inner--text">' . __('Shipped') . ' : ' . \App\Models\Utility::dateFormat($item->shipped_date) . '</span>
                        </button>';
                        break;
                }
                return $buttonHtml;
            })

            ->rawColumns(['action','product_order_id','order_date', 'customer_id', 'sub_total','delivered_status']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Order $model): QueryBuilder
    {
        return $model->where('product_order_status',
        'lottery_order')->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('auctionproductorder-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'product_order_id' => ['title' => __('Order Id')],
            'order_date' => ['title' => __('Date')],
            'customer_id' => ['title' => __('Customer Name')],
            'payment_status' => ['title' => __('Payment Status')],
            'sub_total' => ['title' => __('Price')],
            'lottery_code' => ['title' => __('Lottery Code')],
            'delivered_status' => ['title' => __('Order Status')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Orders_' . date('YmdHis');
    }
}
