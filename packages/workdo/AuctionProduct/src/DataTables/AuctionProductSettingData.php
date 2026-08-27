<?php

namespace Workdo\AuctionProduct\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\AuctionProduct\app\Models\AuctionProductSetting;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class AuctionProductSettingData extends DataTable
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
            ->addColumn('action', function (AuctionProductSetting $auction) {
                return view('auction-product::auctionsetting.action', compact('auction'));
            })
            ->editColumn('is_active', function ($auction) {
                return '
                    <div class="form-check form-switch">
                        <input
                            type="checkbox"
                            class="form-check-input auction-toggle"
                            data-auction-id="' . $auction->id . '"
                            ' . ($auction->is_active ? 'checked' : '') . '
                        />
                    </div>';
            })
            ->rawColumns(['is_active']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(AuctionProductSetting $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('auctionproductsetting-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'start_date' => ['title' => __('Start Date')],
            'end_date' => ['title' => __('End Date')],
            'is_active' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'AuctionProductSetting_' . date('YmdHis');
    }
}
