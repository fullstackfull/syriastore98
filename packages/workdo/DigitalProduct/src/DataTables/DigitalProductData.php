<?php

namespace Workdo\DigitalProduct\DataTables;

use App\Models\Product;
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

class DigitalProductData extends DataTable
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
            ->addColumn('action', function (Product $product) {
                return view('digital-product::product.action', compact('product'));
            })
            ->editColumn('category_id', function ($product) {
                return !empty($product->ProductData) ? $product->ProductData->name : '';
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                $query->whereHas('ProductData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('brand_id', function ($product) {
                return !empty($product->brand) ? $product->brand->name : '-';
            })
            ->filterColumn('brand_id', function ($query, $keyword) {
                $query->whereHas('brand', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('label_id', function ($product) {
                return !empty($product->label) ? $product->label->name : '-';
            })
            ->filterColumn('label_id', function ($query, $keyword) {
                $query->whereHas('label', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->addColumn('image', function ($product) {
                $image = \Workdo\DigitalProduct\app\Models\DigitalProductImage::where('product_id', $product->id)->first();
                if ($image) {
                    return '<a download="" href="' . get_file($image->image_path) . '" class="btn btn-sm btn-primary d-inline-flex align-items-center mx-2" data-bs-toggle="tooltip" title="' . __('Download') . '">
                                <i class="ti ti-arrow-bar-to-down me-2"></i>
                                ' . __('Attachment.png') . '
                            </a>';
                } else {
                    return '-';
                }
            })
            ->editColumn('price', function ($product) {
                return currency_format_with_sym($product->price, getCurrentStore()) ?? SetNumberFormat($product->price);
            })
            ->rawColumns(['image']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Product $model): QueryBuilder
    {
        return $model->where('product_type','digital')->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('product-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'category_id' => ['title' => __('Category')],
            'brand_id' => ['title' => __('Brand')],
            'label_id' => ['title' => __('Label')],
            'image' => ['title' => __('Image')],
            'price' => ['title' => __('Price')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'DigitalProduct_' . date('YmdHis');
    }
}
