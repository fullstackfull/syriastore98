<?php

namespace Workdo\BackupRestore\DataTables;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\BackupRestore\app\Models\BackupRestoreLog;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class BackupRestoreData extends DataTable
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
            ->addColumn('action', function (BackupRestoreLog $backup) {
                return view('backup-restore::backup-restore.action', compact('backup'))->render();
            })
            ->editColumn('file_name', function ($backup) {
                return str_replace(['.zip', '.sql'], '', $backup->file_name);
            })
            ->editColumn('type', function ($backup) {
                return ucfirst($backup->type);
            })
            ->editColumn('created_at', function ($backup) {
                return Carbon::parse($backup->created_at)->format('d-m-Y');
            })
            ->rawColumns(['action'])
            ->setRowId('id');
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(BackupRestoreLog $model): QueryBuilder
    {
        $user = auth()->user();
        $query = $model->newQuery()->where('created_by', $user->id);
        
        // Add store_id filter if user has a current_store
        if (isset($user->current_store) && $user->current_store !== null) {
            $query->where(function($q) use ($user) {
                $q->where('store_id', $user->current_store)
                  ->orWhereNull('store_id');
            });
        }
        
        return $query->orderBy('created_at', 'desc');
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('backuprestorelog-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'file_name' => ['title' => __('File Name')],
            'type' => ['title' => __('Type')],
            'created_at' => ['title' => __('Created Date')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Backup&Restore_' . date('YmdHis');
    }
}
