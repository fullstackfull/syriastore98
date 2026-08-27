<?php

namespace Workdo\ProductReview\DataTables;

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
use Workdo\ProductReview\app\Models\ProductReview;


class ProductReviewDataTable extends DataTable
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
            ->editColumn('category_id', function (ProductReview $review) {
                return !empty($review->MainCategoryData) ? $review->MainCategoryData->name : '';
            })
            ->editColumn('product_id', function (ProductReview $review) {

                return !empty($review->ProductData) ? $review->ProductData->name : '';
            })
            ->editColumn('rating_no', function (ProductReview $review) {

                return view('product-review::product-review.rating', compact('review'));
            })
            ->addColumn('action', function (ProductReview $review) {
                return view('product-review::product-review.action', compact('review'));
            })
            ->filterColumn('rating_no', function ($query, $keyword) {
                // Assuming `permissions` is a relationship, adjust as needed
                $query->where('rating_no', 'like', "%$keyword%");
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                // Assuming `permissions` is a relationship, adjust as needed
                $query->whereHas('MainCategoryData', function ($subQuery) use ($keyword) {
                    $subQuery->where('name', 'like', "%$keyword%");
                });
            })
            ->filterColumn('product_id', function ($query, $keyword) {
                // Assuming `permissions` is a relationship, adjust as needed
                $query->whereHas('ProductData', function ($subQuery) use ($keyword) {
                    $subQuery->where('name', 'like', "%$keyword%");
                });
            })
            ->rawColumns(['main_category','product','rating','description','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductReview $model): QueryBuilder
    {
       return $model->newQuery()->with(['MainCategoryData','ProductData'])->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('product-review-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'category_id' => ['title' => __('Category')],
            'product_id' => ['title' => __('Product')],
            'rating_no' => ['title' => __('Rating')],
            'description' => ['title' => __('Review'), 'addClass' => 'description-wrp']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Product_review_' . date('YmdHis');
    }
}
