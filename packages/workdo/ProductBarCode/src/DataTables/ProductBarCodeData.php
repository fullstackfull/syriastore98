<?php

namespace Workdo\ProductBarCode\DataTables;

use App\Models\Setting;
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

class ProductBarCodeData extends DataTable
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
            ->addColumn('barcode', function ($product) {
                $settings = Setting::where('store_id', getCurrentStore())
                    ->pluck('value', 'name')
                    ->toArray();
                $barcode_type = isset($settings['barcode_type']) ? $settings['barcode_type'] : 'PHARMA';
                $barcode_color = isset($settings['barcode_color']) ? $settings['barcode_color'] : 'black';
                return \DNS1D::getBarcodeHTML($product->product_code, $barcode_type, 2, 50, $barcode_color);
            })
            ->rawColumns(['barcode']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Product $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore())->whereNotNull('product_code')->orderBy('id', 'desc');
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
            'product_code' => ['title' => __('Product Code')],
            'barcode' => ['title' => __('Bar Code')]
        ], false);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Barcode_' . date('YmdHis');
    }
}
