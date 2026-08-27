<?php

namespace Workdo\Donation\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\Donation\app\Models\CustomerDonation;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class CustomerDonationData extends DataTable
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
            ->addColumn('action', function (CustomerDonation $donation) {
                return view('donation::admin.donation-history.action', compact('donation'));
            })
            ->editColumn('donation_id', function ($donation) {
                $donation = \Workdo\Donation\app\Models\Donation::find($donation->donation_id);
                return $donation->title ?? '';
            })
            ->editColumn('amount', function ($donation) {
                return currency_format_with_sym($donation->amount, $donation->store_id);
            })
            ->editColumn('payment_type', function ($donation) {
                return '<span class="badge p-2 rounded f-w-600 bg-light-success">' . $donation->payment_type . '</span>';
            })
            ->rawColumns(['payment_type']);

        return $dataTable;
    }


    /**
     * Get the query source of dataTable.
     */
    public function query(CustomerDonation $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('customerdonation-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'donation_id' => ['title' => __('Donation')],
            'amount' => ['title' => __('Amount')],
            'payment_type' => ['title' => __('Payment Type')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Customerdonation_' . date('YmdHis');
    }
}
