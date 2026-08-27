<?php

namespace App\DataTables;

use App\Models\ShippingZone;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ShippingZoneDataTable extends DataTable
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
            ->addColumn('action', function (ShippingZone $shippingZone) {
                return view('shippingzone.action', compact('shippingZone'));
            })
            ->editColumn('country_id', function (ShippingZone $shippingZone) {
                return $shippingZone->getCountryNameAttribute()->name ?? '-';
            })
            ->editColumn('state_id', function (ShippingZone $shippingZone) {
                return $shippingZone->getStateNameAttribute()->name ?? '-';
            })
            ->filterColumn('country_id', function ($query, $keyword) {
                // Add filtering logic for country name
                $query->whereHas('country', function ($q) use ($keyword) {
                    $q->where('name', 'LIKE', "%{$keyword}%");
                });
            })
            ->filterColumn('state_id', function ($query, $keyword) {
                // Add filtering logic for state name
                $query->whereHas('state', function ($q) use ($keyword) {
                    $q->where('name', 'LIKE', "%{$keyword}%");
                });
            })
            ->filterColumn('city_id', function ($query, $keyword) {
                // Add filtering logic for city name
                $query->whereHas('city', function ($q) use ($keyword) {
                    $q->where('name', 'LIKE', "%{$keyword}%");
                });
            })
            ->rawColumns(['action', 'country_id', 'state_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ShippingZone $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('shippingzone-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'No' => ['title' => __('#'), 'data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
            'zone_name' => ['title' => __('Name')],
            'country_id' => ['title' => __('Country')],
            'state_id' => ['title' => __('State')],
            'shipping_method' => ['title' => __('Shipping Method')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ShippingZone_' . date('YmdHis');
    }
}
