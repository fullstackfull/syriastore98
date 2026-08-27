<?php

namespace App\DataTables;

use App\Models\ProductLabel;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductLabelDataTable extends DataTable
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
            ->addColumn('action', function (ProductLabel $productlabel) {
                return view('product_label.action', compact('productlabel'));
            })
            ->editColumn('status', function (ProductLabel $productlabel) {
                $status = '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $productlabel->id . '" class="form-check-input status-index" name="status_"
                    id="status_' . $productlabel->id . '" value="' . $productlabel->status . '" ' . ($productlabel->status ? 'checked' : '') . '>
                    <label class="form-check-label" for="status_' . $productlabel->id . '"></label>
                    </div>';
                    return $status;
            })
            ->rawColumns(['action','status']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductLabel $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productlabel-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'slug' => ['title' => __('Slug')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductLabel_' . date('YmdHis');
    }
}
