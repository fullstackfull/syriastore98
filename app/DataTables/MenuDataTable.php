<?php

namespace App\DataTables;

use App\Models\Menu;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class MenuDataTable extends DataTable
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
                ->editColumn('created_at', function (Menu $menu) {
                    return isset($menu->created_at) ? $menu->created_at->format('Y-m-d H:i:s') : '-';
                })
                ->addColumn('action', function (Menu $menu) {
                    return view('menu.action', compact('menu'));
                })
                ->rawColumns(['topic','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Menu $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('menu-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'created_at' => ['title' => __('Created At')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Menu_' . date('YmdHis');
    }
}
