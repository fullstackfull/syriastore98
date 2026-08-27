<?php

namespace Workdo\AdditionalFieldInCheckout\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\AdditionalFieldInCheckout\app\Models\AdditionalField;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class AdditionalFieldInCheckoutData extends DataTable
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
            ->addColumn('action', function (AdditionalField $field) {
                return view('additional-field-in-checkout::admin.action', compact('field'));
            })
            ->editColumn('options', function ($field) {
                $options = json_decode($field->options, true);
                if (is_array($options) && in_array('view_order_page', $options)) {
                    return '<span class="badge p-2 bg-success me-1 text-capitalize">Yes</span>';
                } else {
                    return '<span class="badge p-2 bg-danger me-1 text-capitalize">No</span>';
                }
            })
            ->rawColumns(['action','options']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(AdditionalField $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('additionalfield-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name'), 'addClass' => 'text-capitalize'],
            'type' => ['title' => __('Type'), 'addClass' => 'text-capitalize'],
            'label' => ['title' => __('Label')],
            'placeholder' => ['title' => __('Placeholder')],
            'options' => ['title' => __('View on order page')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'AdditionalField_' . date('YmdHis');
    }
}
