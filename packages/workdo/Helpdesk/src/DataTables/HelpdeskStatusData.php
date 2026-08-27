<?php

namespace Workdo\Helpdesk\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Helpdesk\app\Models\HelpdeskStatus;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class HelpdeskStatusData extends DataTable
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
            ->addColumn('action', function (HelpdeskStatus $status) use (&$counter) {
                $counter++;
                if ($counter > 4) {
                    return view('helpdesk::status.action', compact('status'));
                } else {
                    return '';
                }
            })
            ->editColumn('status_color', function ($status) {
                return '<div class="btn-badge" style="background-color: ' . $status->status_color . '; width: 20px; height: 20px;"></div>';
            })
            ->rawColumns(['status_color','action']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(HelpdeskStatus $model): QueryBuilder
    {
       return $model->newQuery();
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('helpdeskticket-status-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'No' => ['title' => __('#'), 'data' => 'DT_RowIndex', 'name' => 'DT_RowIndex', 'searchable' => false, 'orderable' => false],
            'status_name' => ['title' => __('Name')],
            'status_color' => ['title' => __('Color')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Users_' . date('YmdHis');
    }
}
