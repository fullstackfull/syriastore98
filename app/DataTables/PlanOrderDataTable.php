<?php

namespace App\DataTables;

use App\Models\PlanOrder;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class PlanOrderDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $userOrders = [];
        if (auth()->user() && auth()->user()->type == 'super admin') {
            $userOrders = PlanOrder::select('*')
            ->whereIn('id', function ($query) {
            $query->selectRaw('MAX(id)')
            ->from('plan_orders')
            ->groupBy('user_id');
            })
            ->orderBy('created_at', 'desc')
            ->get();

        }

        return datatables()
            ->eloquent($query)
            ->editColumn('order_id', function ($order) {
                return '#'. ($order->order_id ?? null);
            })
            ->editColumn('order_id', function ($order) {
                return '  <div " class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Order ID') . '">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">#'. $order->order_id . '</span>
                            </div>
                       ';
            })
            ->editColumn('created_at', function ($order) {
                return isset($order->created_at) ? $order->created_at->format('Y-m-d H:i:s') : '-';
            })
            ->editColumn('user_name', function ($order) {

                return $order->user_name ?? '-';
            })
            ->editColumn('plan_name', function ($order) {
                return $order->plan_name ?? '-';
            })
            ->editColumn('price', function ($order) {
                return GetCurrency() . $order->price;
            })
            ->editColumn('payment_status', function ($order) {
                return view('plans.payment_status', compact('order'));
            })
            ->editColumn('total_coupon_used', function ($order) {
                return isset($order->total_coupon_used->coupon_detail->code)
                    ? $order->total_coupon_used->coupon_detail->code
                    : '-';
            })
            ->editColumn('receipt', function ($order) {
                return view('plans.receipt', compact('order'));
            })
            ->addColumn('action', function ($order) use ($userOrders) {
                $user = User::find($order->user_id);
                return view('plans.action', compact('order','user','userOrders'));
            })
            ->rawColumns(['order_id','created_at','user_name','plan_name','price','payment_status','total_coupon_used','receipt','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(PlanOrder $model): QueryBuilder
    {
        $query = $model->newQuery()
        ->with(['total_coupon_used.coupon_detail']) // Eager-load the total_coupon_used relationship
        ->select(['plan_orders.*', 'users.name as user_name'])
        ->join('users', 'plan_orders.user_id', '=', 'users.id');

        if (auth()->user() && auth()->user()->type != 'super admin') {
            $query->where('users.id', '=', auth()->user()->id);
        }
        return $query;
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('plan-order-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        $columns = [
            'order_id' => ['title' => __('Order Id')],
            'created_at' => ['title' => __('Date')],
            'user_name' => ['title' => __('User Name'), 'name' => 'users.name'],
            'plan_name' => ['title' => __('Plan Name')],
            'price' => ['title' => __('Price')],
            'payment_type' => ['title' => __('Payment Type')],
            'payment_status' => ['title' => __('Status'), 'addClass' => 'text-capitalize'],
            'total_coupon_used' => [
                'title' => __('Coupon'),
                'name' => 'total_coupon_used.coupon_detail.code',
                'searchable' => true,
                'orderable' => true
            ],
            'receipt' => ['title' => __('Invoice')]
        ];

        if (auth()->user() && auth()->user()->type == 'super admin') {
            return buildDataTableColumn($columns, false);
        }
        return buildDataTableColumn($columns);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'PlanOrder_' . date('YmdHis');
    }
}
