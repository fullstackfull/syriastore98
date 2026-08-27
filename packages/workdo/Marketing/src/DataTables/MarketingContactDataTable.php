<?php

namespace Workdo\Marketing\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Workdo\Marketing\app\Models\MarketingContact;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Services\DataTable;

class MarketingContactDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $rowColumn = ['status'];
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->editColumn('status', function (MarketingContact $contactsDetails) {
                if ($contactsDetails->status == "pending") {
                    $html = '<span class="badge fix_badge bg-warning bg-info p-2 px-3 rounded">' . ucFirst($contactsDetails->status) . '</span>';
                } else {

                    $html = '<span class="badge fix_badge bg-primary p-2 px-3 rounded">' . ucFirst($contactsDetails->status) . '</span>';
                }
                return $html;
            });
            $dataTable->addColumn('action', function (MarketingContact $contactsDetails) {
                return view('marketing::contacts.action', compact('contactsDetails'));
            });
            $rowColumn[] = 'action';
        return $dataTable->rawColumns($rowColumn);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(MarketingContact $model): QueryBuilder
    {
        $user = auth()->user();
        $contactsDetail = $model->newQuery()
            ->where('created_by', auth()->user()->creatorId())
            ->where('store_id', $user->current_store);
        return $contactsDetail;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('marketing-contact-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'email' => ['title' => __('Email')],
            'phone' => ['title' => __('Phone')],
            'message' => ['title' => __('Message')],
            'status' => ['title' => __('Status')]
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
