<?php

namespace App\DataTables;

use App\Models\ShippingMethod;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ShippingMethodDataTable extends DataTable
{
    protected $zone_id;
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function setZoneId($id)
    {
        $this->zone_id = $id;
    }

    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (ShippingMethod $shippingMethod) {
                return view('shipping_method.action', compact('shippingMethod'));
            })
            ->rawColumns(['action']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ShippingMethod $model): QueryBuilder
    {
        return $model->newQuery()
                     ->where('zone_id', $this->zone_id)
                     ->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('shipping-taxmethod-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'No' => ['title' => __('#'), 'data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
            'method_name' => ['title' => __('Shipping Method')],
            'cost' => ['title' => __('Shipping Cost')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ShippingMethod_' . date('YmdHis');
    }
}
