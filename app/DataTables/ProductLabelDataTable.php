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
        // Resolved ONCE, outside the per-row closure -- `isAbleTo()` is a permission lookup and
        // asking it per row is an extra lookup on every row of every page.
        $mayToggle = $this->mayToggle();

        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (ProductLabel $productlabel) {
                return view('product_label.action', compact('productlabel'));
            })
            ->editColumn('status', function (ProductLabel $productlabel) use ($mayToggle) {
                $disabled = $mayToggle ? '' : ' disabled aria-disabled="true" title="'
                    . e(__('You do not have permission to change this.')) . '"';

                return '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $productlabel->id . '" class="form-check-input status-index" name="status_"
                    id="status_' . $productlabel->id . '" value="' . $productlabel->status . '" ' . ($productlabel->status ? 'checked' : '') . $disabled . '>
                    <label class="form-check-label" for="status_' . $productlabel->id . '">
                        <span class="ss-visually-hidden">' . e(__('Enabled')) . '</span>
                    </label>
                    </div>';
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

    /**
     * Whether this caller may actually USE the toggle this table renders. P2-05-1.
     *
     * Same reasoning as `ProductBrandDataTable::mayToggle()`: the handler now requires
     * `Edit Product Label`, and a switch that answers 403 is a control offered and then
     * refused. DISABLED rather than hidden, because the VALUE is information a caller holding
     * `Manage Product Label` may read.
     *
     * Operator first -- Laratrust has no super-admin bypass.
     */
    private function mayToggle(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return \App\Policies\UserPolicy::isOperator($user)
            || $user->isAbleTo('Edit Product Label');
    }
}
