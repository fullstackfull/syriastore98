<?php

namespace App\DataTables;

use App\Models\Role;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class RoleDataTable extends DataTable
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
                ->editColumn('permissions', function (Role $role){
                    return view('roles.permission', compact('role'));
                })
                ->addColumn('action', function (Role $role) {
                    return view('roles.action', compact('role'));
                })
                ->filterColumn('permissions', function ($query, $keyword) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('permissions', function ($subQuery) use ($keyword) {
                        $subQuery->where('name', 'like', "%$keyword%");
                    });
                })
                ->rawColumns(['name','permissions','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Role $model): QueryBuilder
    {
        return $model->newQuery()->with('permissions')->where('name', '!=', 'super admin')->where('store_id', getCurrentStore())->where('created_by', '=', auth()->user()->creatorId());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('roles-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Role')],
            'permissions' => ['title' => __('Permissions'), 'orderable' => false, 'addClass' => 'custom-yajra-label']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Role_' . date('YmdHis');
    }
}
