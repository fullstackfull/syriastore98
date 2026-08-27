<?php

namespace App\DataTables;

use App\Models\Newsletter;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class NewsletterDataTable extends DataTable
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
        ->editColumn('created_at', function (Newsletter $newsletter) {
            return isset($newsletter->created_at) ? $newsletter->created_at->format('Y-m-d H:i:s') : '-';
        })
        ->addColumn('action', function (Newsletter $newsletter) {
            return view('newsletter.action', compact('newsletter'));
        })
        ->rawColumns(['email','created_at','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Newsletter $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('newsletter-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'email' => ['title' => __('Email')],
            'created_at' => ['title' => __('Created At')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Newsletter_' . date('YmdHis');
    }
}
