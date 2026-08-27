<?php

namespace Workdo\AdditionalCustomFields\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Support\Facades\Auth;
use Workdo\AdditionalCustomFields\app\Models\AdditionalCustomFields;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class AdditionalCustomFieldData extends DataTable
{
   
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('label', function ($field) {
                return $field->label;
            })
            ->addColumn('options', function ($field) {
                if (in_array($field->type, ['Radio', 'Select', 'Checkbox'])) {
                    if (!empty($field->options)) {
                        $options = json_decode($field->options, true);
                        
                        // Check if the decoding was successful and if options are an array
                        if (is_array($options) && count($options) > 0) {
                            return implode(', ', $options);  // Return the options as a comma-separated string
                        }
                    }
                    
                    // If options are empty or decoding failed, return a default value
                    return '-';
                }
                
                return '-';
            })
            ->addColumn('type', function ($field) {
                return ucfirst($field->type);
            })
            ->addColumn('action', function ($field) {
                return view('additional-custom-fields::partials.actions', compact('field'));
            })
            ->rawColumns(['action']);
    }

    /**
     * Get the query source of dataTable.
     *
     * @param AdditionalCustomFields $model
     * @return QueryBuilder
     */
    public function query(AdditionalCustomFields $model): QueryBuilder
    {
        $store_id = getcurrentstore();
        return $model->newQuery()
            ->where('store_id', $store_id);
    }

    /**
     * Optional method if you want to use the html builder.
     *
     * @return HtmlBuilder
     */
    public function html(): HtmlBuilder
    {
        return $this->builder()
            ->setTableId('additional-custom-fields-table')
            ->columns($this->getColumns())
            ->minifiedAjax()
            ->orderBy(0)
            ->language([
                "paginate" => [
                    "next" => '<i class="ti ti-chevron-right"></i>',
                    "previous" => '<i class="ti ti-chevron-left"></i>',
                ],
                'lengthMenu' => "_MENU_" . __('Entries Per Page'),
                "searchPlaceholder" => __('Search...'),
                "search" => "",
                "info" => __("Showing")." _START_ ".__("to")." _END_ ".__("of")." _TOTAL_ ".__("entries"),
            ]);
    }

    /**
     * Get the columns definition for the table.
     *
     * @return array
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'No' => ['title' => __('#'), 'data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
            'label' => ['title' => __('Label')],
            'options' => ['title' => __('Options')],
            'type' => ['title' => __('Type')]
        ]);
    }

    /**
     * Get the filename for export.
     *
     * @return string
     */
    protected function filename(): string
    {
        return 'AdditionalCustomFields_' . date('YmdHis');
    }
}
