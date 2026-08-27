<?php

namespace App\DataTables;

use App\Models\Product;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $admin = getAdminAllSetting();
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (Product $product) {
                return view('product.action', compact('product'));
            })
            ->editColumn('category_id', function (Product $product) {
                return !empty($product->ProductData) ? $product->ProductData->name : '-';
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                $query->whereHas('ProductData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('brand_id', function (Product $product) {
                return !empty($product->brand) ? $product->brand->name : '-';
            })
            ->filterColumn('brand_id', function ($query, $keyword) {
                $query->whereHas('brand', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('label_id', function (Product $product) {
                return !empty($product->label) ? $product->label->name : '-';
            })
            ->filterColumn('label_id', function ($query, $keyword) {
                $query->whereHas('label', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('variant_product', function (Product $product) {
                return $product->variant_product == 1 ? 'has variant' : 'no variant';
            })
            ->editColumn('average_rating', function (Product $product) {
                return '<i class="ti ti-star text-warning"></i> ' . $product->average_rating;
            })
            ->editColumn('price', function (Product $product) {
                if ($product->variant_product == 0) {
                    return currency_format_with_sym($product->price, getCurrentStore()) ?? SetNumberFormat($product->price);
                } else {
                    return __('In Variant');
                }
            })
            ->addColumn('stock_status', function ($product) use ($admin) {
                if ($product->variant_product == 1) {
                    return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-warning">' . __('In Variant') . '</span>';
                } else {
                    if ($product->track_stock == 0) {
                        if ($product->stock_status == 'out_of_stock') {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-danger">' . __('Out of stock') . '</span>';
                        } elseif ($product->stock_status == 'on_backorder') {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-warning">' . __('On Backorder') . '</span>';
                        } else {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-primary">' . __('In stock') . '</span>';
                        }
                    } else {
                        if ($product->product_stock <= (isset($admin['out_of_stock_threshold']) ? $admin['out_of_stock_threshold'] : 0)) {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-danger">' . __('Out of stock') . '</span>';
                        } else {
                            return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-primary">' . __('In stock') . '</span>';
                        }
                    }
                }
            })
            ->addColumn('product_stock', function ($product) {
                if ($product->variant_product == 1) {
                    return '-';
                } else {
                    return $product->product_stock > 0 ? $product->product_stock : '-';
                }
            })
            ->editColumn('cover_image_path', function (Product $product) {
                if (isset($product->cover_image_path) && !empty($product->cover_image_path)) {
                    return '<img src="' . get_file($product->cover_image_path) . '" alt="" width="100" class="cover_img' . $product->id . '">';
                }
                return '';
            })
            ->rawColumns(['action', 'category_id', 'brand_id', 'label_id', 'variant_product', 'average_rating', 'price', 'stock_status', 'product_stock', 'cover_image_path']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Product $model): QueryBuilder
    {
        return $model->where('product_type', null)->where('store_id', getCurrentStore());
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
            'cover_image_path' => ['title' => __('Cover Image')],
            'variant_product' => ['title' => __('Variant')],
            'average_rating' => ['title' => __('Review')],
            'price' => ['title' => __('Price')],
            'stock_status' => ['title' => __('Stock Status'), 'addClass' => 'text-capitalize'],
            'product_stock' => ['title' => __('Stock Quantity')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Product_' . date('YmdHis');
    }
}
