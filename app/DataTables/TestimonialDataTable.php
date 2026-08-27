<?php

namespace App\DataTables;

use App\Models\Testimonial;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class TestimonialDataTable extends DataTable
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
            ->editColumn('avatar', function (Testimonial $testimonial) {
                $avatar = '<div class="d-flex align-items-center">
                        <a href="'.get_file($testimonial->avatar).'" class="table-image me-3" target="__blank">
                            <img src="'.get_file($testimonial->avatar).'" class="rounded h-100 w-100" loading="lazy" alt="avatar avatar"></a>
                        </div>';

                return $avatar;
            })
            ->addColumn('action', function (Testimonial $testimonial) {
                return view('testimonial.action', compact('testimonial'));
            })
            ->editColumn('category_id', function (Testimonial $testimonial) {
                $maincategory = !empty($testimonial->MainCategoryData) ? $testimonial->MainCategoryData->name : '';
                return $maincategory;
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                $query->whereHas('MainCategoryData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('product_id', function (Testimonial $testimonial) {
                $product = !empty($testimonial->ProductData) ? $testimonial->ProductData->name : '';
                return $product;
            })
            ->filterColumn('product_id', function ($query, $keyword) {
                $query->whereHas('ProductData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('rating_no', function ($testimonial) {
                $ratingHtml = '';

                for ($i = 0; $i < 5; $i++) {
                    $starClass = $i < $testimonial->rating_no ? 'text-warning' : '';
                    $ratingHtml .= '<i class="ti ti-star ' . $starClass . '"></i>';
                }

                return $ratingHtml;
            })
            ->filterColumn('description', function ($query, $keyword) {
                $query->whereRaw('LOWER(description) LIKE ?', ["%{$keyword}%"]);
            })
            ->orderColumn('description', function ($query, $direction) {
                $query->orderby('description', $direction);
            })
            ->rawColumns(['action', 'avatar', 'username', 'category_id', 'product_id', 'rating_no']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Testimonial $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('testimonial-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'avatar' => ['title' => __('Avatar')],
            'username' => ['title' => __('User Name')],
            'category_id' => ['title' => __('Category')],
            'product_id' => ['title' => __('Product')],
            'rating_no' => ['title' => __('Rating')],
            'description' => ['title' => __('Description'), 'orderable' => false, 'addClass' => 'description-wrp']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Testimonial_' . date('YmdHis');
    }
}
