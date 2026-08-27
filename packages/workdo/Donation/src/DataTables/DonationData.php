<?php

namespace Workdo\Donation\DataTables;

use App\Models\Store;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\Donation\app\Models\Donation;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class DonationData extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $user = auth()->user();
        $store = Store::find($user->current_store);
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (Donation $donation) {
                return view('donation::admin.donation.action', compact('donation'));
            })
            ->editColumn('description', function ($donation) {
                return strip_tags($donation->description) ?? '-';
            })
            ->editColumn('amount', function ($donation) use ($store) {
                $amounts = json_decode($donation->amount, true);
                $amountsHtml = '';

                foreach ($amounts as $amountData) {
                    $formattedAmount = currency_format_with_sym($amountData['amount'], $store->id) ?? SetNumberFormat($amountData['amount']);
                    $amountsHtml .= '<span class="badge rounded f-w-600 bg-light-success">' . $formattedAmount . '</span> ';
                }
                return $amountsHtml;
            })
            ->rawColumns(['action','amount']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Donation $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('donation-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'title' => ['title' => __('Title')],
            'note' => ['title' => __('Note'), 'addClass' => 'description-wrp'],
            'description' => ['title' => __('Description'), 'addClass' => 'description-wrp'],
            'amount' => ['title' => __('Amount')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Donation_' . date('YmdHis');
    }
}
