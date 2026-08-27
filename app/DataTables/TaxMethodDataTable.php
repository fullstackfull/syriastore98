<?php

namespace App\DataTables;

use App\Models\TaxMethod;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class TaxMethodDataTable extends DataTable
{
    protected $tax_id;
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function setTaxId($id)
    {
        $this->tax_id = $id;
    }

    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (TaxMethod $tax_method) {
                return view('taxes.method-action', compact('tax_method'));
            })
            ->editColumn('country_id', function (TaxMethod $tax_method) {
                $country = $tax_method->getCountryNameAttribute();
                return $country ? $country->name : '*';
            })
            ->editColumn('state_id', function (TaxMethod $tax_method) {
                $state = $tax_method->getStateNameAttribute();
                return $state ? $state->name : '*';
            })
            ->editColumn('city_id', function (TaxMethod $tax_method) {
                $city = $tax_method->getCityNameAttribute();
                return $city ? $city->name : '*';
            })
            ->rawColumns(['action','country_id','state_id','city_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(TaxMethod $model): QueryBuilder
    {
        return $model->newQuery()
                     ->where('tax_id', $this->tax_id)
                     ->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('taxmethod-table', $this->builder(), $this->getColumns());
    }
    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'tax_rate' => ['title' => __('Tax Rate')],
            'country_id' => ['title' => __('Country'), 'addClass' => 'text-capitalize'],
            'state_id' => ['title' => __('State'), 'addClass' => 'text-capitalize'],
            'city_id' => ['title' => __('City'), 'addClass' => 'text-capitalize'],
            'priority' => ['title' => __('Priority')]
        ]);
    }
    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'TaxMethod_' . date('YmdHis');
    }
}
