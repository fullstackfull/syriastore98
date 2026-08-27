<?php

namespace App\DataTables;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class StoreDataTable extends DataTable
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
            ->editColumn('avatar', function (User $user) {
                $avatarUrl = check_file($user->profile_image) ? get_file($user->profile_image) : get_file('storage/uploads/avatar.png');
                return '<a><img src="' . $avatarUrl . '" class="img-fluid rounded-circle card-avatar" width="35" id="blah3"></a>';
            })
            ->editColumn('type', function (User $user) {
                return  '<span class="badge bg-primary p-2 px-3 rounded rounded">' . $user->type . '</span>';
            })
            ->addColumn('action', function (User $user) {
                return view('store.action', compact('user'));
            })
            ->rawColumns(['avatar','name','type','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(User $model): QueryBuilder
    {
        return $model->newQuery()->select(
            [
                'users.*',
            ]
        )->join('stores', 'stores.created_by', '=', 'users.id')->where('users.created_by', \Auth::user()->creatorId())->where('users.type', '=', 'admin')->groupBy('users.id');
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('store-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'avatar' => ['title' => __('Avatar'), 'orderable' => false, 'sortable' => false, 'searchable' => false, 'exportable' => false],
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'type' => ['title' => __('Role')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Store_' . date('YmdHis');
    }
}
