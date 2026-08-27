<?php

namespace Workdo\Helpdesk\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Helpdesk\app\Models\HelpdeskCategory;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class HelpdeskCategoryData extends DataTable
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
            ->addColumn('action', function (HelpdeskCategory $category) {
                return view('helpdesk::category.action', compact('category'));
            })
            ->editColumn('color', function ($category) {
                return '<div class="btn-badge" style="background-color: ' . $category->color . '; width: 20px; height: 20px;"></div>';
            })
            ->rawColumns(['color']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(HelpdeskCategory $model): QueryBuilder
    {
        return $model->newQuery();
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('helpdeskticketcategory-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'No' => ['title' => __('#'), 'data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
            'name' => ['title' => __('Name')],
            'color' => ['title' => __('Color')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Helpdeskcategory_' . date('YmdHis');
    }
}
