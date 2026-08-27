<?php

namespace App\DataTables;

use App\Models\Contact;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ContactDataTable extends DataTable
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
            ->addColumn('action', function (Contact $contact) {
                return view('contact.action', compact('contact'));
            })
            ->filterColumn('description', function ($query, $keyword) {
                $query->whereRaw('LOWER(contacts.description) LIKE ?', ["%{$keyword}%"]);
            })
            ->filterColumn('deion', function ($query, $keyword) {
                $query->whereRaw('LOWER(contacts.description) LIKE ?', ["%{$keyword}%"]);
            })
            ->orderColumn('description', function ($query, $direction) {
                $query->orderby('description', $direction);
            })
            ->orderColumn('deion', function ($query, $direction) {
                $query->orderby('description', $direction);
            })
            ->rawColumns(['action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Contact $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('contact-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'first_name' => ['title' => __('First Name')],
            'last_name' => ['title' => __('Last Name')],
            'email' => ['title' => __('Email')],
            'contact' => ['title' => __('Contact')],
            'subject' => ['title' => __('Subject')],
            'description' => ['title' => __('Description')],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Contact_' . date('YmdHis');
    }
}
