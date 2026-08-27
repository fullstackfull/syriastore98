<?php

namespace App\DataTables;

use App\Models\ProductBrand;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductBrandDataTable extends DataTable
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
            ->addColumn('action', function (ProductBrand $brand) {
                return view('product_brand.action', compact('brand'));
            })
            ->editColumn('status', function (ProductBrand $brand) {
                $trending = '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $brand->id . '" class="form-check-input status-index" name="status"
                    id="status_' . $brand->id . '" value="' . $brand->status . '" ' . ($brand->status ? 'checked' : '') . '>
                    <label class="form-check-label" for="status_' . $brand->id . '"></label>
                    </div>';
                return $trending;
            })
            ->editColumn('is_popular', function (ProductBrand $brand) {
                $status = '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $brand->id . '" class="form-check-input popular-index" name="is_popular"
                    id="is_popular_' . $brand->id . '" value="' . $brand->is_popular . '" ' . ($brand->is_popular ? 'checked' : '') . '>
                    <label class="form-check-label" for="is_popular_' . $brand->id . '"></label>
                    </div>';
                    return $status;
            })
            ->editColumn('logo', function (ProductBrand $brand) {
                if (!empty($brand->logo)) {
                    $imagePath = get_file($brand->logo);
                    $html = '<img src="' . $imagePath . '" alt="" class="category_Image">';
                } else {
                    $html = '-';
                }
                return $html;
            })
            ->rawColumns(['action','status','is_popular','logo']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductBrand $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('maincategory-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'slug' => ['title' => __('Slug')],
            'logo' => ['title' => __('Logo')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-capitalize'],
            'is_popular' => ['title' => __('Popular')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductBrand_' . date('YmdHis');
    }
}
