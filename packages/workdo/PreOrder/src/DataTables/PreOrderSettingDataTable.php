<?php

namespace Workdo\PreOrder\DataTables;

use App\Models\ProductVariant;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Workdo\PreOrder\app\Models\PreOrderSetting;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PreOrderSettingDataTable extends DataTable
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
            ->addColumn('action', function (PreOrderSetting $preorder_setting) {
                return view('pre-order::setting.action', compact('preorder_setting'));
            })
            ->editColumn('product_id', function ($item) {
                $variant = ProductVariant::find($item->variant_id);
                if($variant){
                    return isset($item->productData) && !empty($item->productData->name) ? $item->productData->name . ' ('. $variant->variant .')' : '-';
                }else{
                    return isset($item->productData) && !empty($item->productData->name) ? $item->productData->name : '-';
                }
            })
            ->filterColumn('product_id', function ($query, $keyword) {
                $query->whereHas('productData', function ($subQuery) use ($keyword) {
                    $subQuery->where('name', 'like', "%$keyword%");
                });
            })
            ->editColumn('available_date', function ($item) {
                return \App\Models\Utility::dateFormat($item->available_date);
            })
            ->addColumn('status', function ($item){
                if ($item->status == 0) {
                    return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-danger">' . __('Disable') . '</span>';
                } else {
                    return '<span class="badge badge-80 rounded p-2 f-w-600 bg-light-primary">' . __('Enable') . '</span>';
                }
            })
            ->filterColumn('status', function ($query, $keyword) {
                if (stripos('Enable', $keyword) !== false) {
                    $query->where('status', 1);
                }
                elseif (stripos('Disable', $keyword) !== false) {
                    $query->orWhere('status', 0);
                }
            })
            ->rawColumns(['action', 'product_id', 'available_date', 'status']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(PreOrderSetting $model): QueryBuilder
    {
        return $model->with('productData');
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('pre-order-setting-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'product_id' => ['title' => __('Product Name')],
            'qty' => ['title' => __('Quantity')],
            'available_date' => ['title' => __('Available Date')],
            'status' => ['title' => __('Status')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'PreOrderSetting_' . date('YmdHis');
    }
}
