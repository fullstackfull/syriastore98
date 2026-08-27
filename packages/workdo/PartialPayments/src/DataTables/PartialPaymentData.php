<?php

namespace Workdo\PartialPayments\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\PartialPayments\app\Models\OrderPartialPayments;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PartialPaymentData extends DataTable
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
            ->addColumn('action', function (OrderPartialPayments $payment) {
                return view('partial-payments::admin.action', compact('payment'));
            })
            ->addColumn('payment_info', function ($payment) {
                $html = '<a href="' . route('partialpayments.edit', \Illuminate\Support\Facades\Crypt::encrypt($payment->id)) . '"
                            class="btn bg-primary btn-sm text-sm" data-bs-toggle="tooltip" title="'. __('Invoice ID') .'">
                            <span class="btn-inner--icon"></span>
                            <span class="btn-inner--text">#' . $payment->order_id . ' - ' . $payment->id . '</span>
                        </a>';

                return $html;
            })
            ->editColumn('payment_status', function ($payment) {
                $btn_class = 'bg-light-info';
                if ($payment->payment_status == 'Cancelled') {
                    $btn_class = 'bg-light-danger';
                } elseif ($payment->payment_status == 'Completed') {
                    $btn_class = 'bg-light-success';
                } elseif ($payment->payment_status == 'Processing') {
                    $btn_class = 'bg-light-warning';
                } elseif ($payment->payment_status == 'On Hold') {
                    $btn_class = 'bg-light-info';
                } elseif ($payment->payment_status == 'Pending payment') {
                    $btn_class = 'bg-light-primary';
                }
                return '<span class="badge p-2 rounded status_badge f-w-600 ' . $btn_class . '">' . $payment->payment_status . '</span>';
            })
            ->editColumn('created_at', function ($payment) {
                return $payment->created_at->format('Y-m-d');
            })
            ->editColumn('payment_type', function ($payment) {
                return ucfirst($payment->payment_type);
            })
            ->editColumn('payment_amount', function ($payment) {
                return currency_format_with_sym($payment->payment_amount ?? 0, getCurrentStore()) ?? SetNumberFormat($payment->payment_amount);
            })
            ->rawColumns(['payment_info', 'payment_status']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(OrderPartialPayments $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('orderpartialpayment-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'payment_info' => ['title' => __('Partial Payment')],
            'created_at' => ['title' => __('Date')],
            'payment_type' => ['title' => __('Payment Type')],
            'payment_status' => ['title' => __('Status')],
            'payment_amount' => ['title' => __('Amount')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'PartialPayments_' . date('YmdHis');
    }
}
