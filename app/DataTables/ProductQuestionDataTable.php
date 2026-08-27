<?php

namespace App\DataTables;

use App\Models\ProductQuestion;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductQuestionDataTable extends DataTable
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
            ->addColumn('action', function (ProductQuestion $question) {
                return view('product-question.action', compact('question'));
            })
            ->editColumn('customer_id', function (ProductQuestion $question) {
                $user = $question->users->name ?? '';
                    return $user;
            })
            ->editColumn('answers', function (ProductQuestion $question) {
                if (!empty($question->answers)) {
                    return '<span class="badges fix_badges bg-success p-2 px-3">' . __('Answered') . '</span>';
                } else {
                    return '<span class="badges fix_badges bg-danger p-2 px-3">' . __('Pending') . '</span>';
                }
            })
            ->rawColumns(['action','customer_id','answers']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductQuestion $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productquestion-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('User')],
            'question' => ['title' => __('Question')],
            'answers' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductQuestion_' . date('YmdHis');
    }
}
