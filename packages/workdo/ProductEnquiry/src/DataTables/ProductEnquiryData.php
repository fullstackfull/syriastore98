<?php

namespace Workdo\ProductEnquiry\DataTables;

use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;
use Workdo\ProductEnquiry\app\Models\ProductEnquiry;

class ProductEnquiryData extends DataTable
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
            ->addColumn('action', function (ProductEnquiry $question) {
                return view('product-enquiry::product-enquiry.action', compact('question'));
            })
            ->editColumn('first_name', function ($question) {
                return $question->first_name . ' ' . $question->last_name;
            })
            ->editColumn('product_id', function ($question) {
                $product = \App\Models\Product::find($question->product_id);
                return $product ? $product->name : '';
            })
            ->editColumn('answers', function ($question) {
                if (!empty($question->answers)) {
                    return '<span class="badges fix_badges bg-success p-2 px-3">' . __('Answered') . '</span>';
                } else {
                    return '<span class="badges fix_badges bg-danger p-2 px-3">' . __('Pending') . '</span>';
                }
            })
            ->rawColumns(['answers']);

        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductEnquiry $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productenquiry-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'first_name' => ['title' => __('Customer Name')],
            'email' => ['title' => __('Email')],
            'product_id' => ['title' => __('Product Name')],
            'comment' => ['title' => __('Queries')],
            'answers' => ['title' => __('Status')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductEnquiry_' . date('YmdHis');
    }
}
