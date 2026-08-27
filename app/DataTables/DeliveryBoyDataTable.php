<?php

namespace App\DataTables;

use App\Models\DeliveryBoy;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class DeliveryBoyDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('avatar', function (DeliveryBoy $deliveryboy) {
                $avatarUrl = check_file($deliveryboy->profile_image) ? get_file($deliveryboy->profile_image) : get_file('storage/uploads/avatar.png');
                return '<a><img src="' . $avatarUrl . '" class="img-fluid rounded-circle card-avatar" width="35" id="blah3"></a>';
            })
            ->addColumn('action', function (DeliveryBoy $deliveryboy) {
                return view('deliveryboy.action', compact('deliveryboy'));
            })
            ->rawColumns(['avatar','name','email','contact','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(DeliveryBoy $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('deliveryboy-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'avatar' => ['title' => __('Avatar'), 'searchable' => false, 'orderable' => false, 'exportable' => false],
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'contact' => ['title' => __('Contact')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'DeliveryBoy_' . date('YmdHis');
    }
}
