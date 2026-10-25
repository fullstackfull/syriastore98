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
        // Resolved ONCE, outside the per-row closures: `isAbleTo()` is a permission lookup, and
        // asking it per row per column is two extra lookups on every row of every page.
        $mayToggle = $this->mayToggle();

        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (ProductBrand $brand) {
                return view('product_brand.action', compact('brand'));
            })
            ->editColumn('status', function (ProductBrand $brand) use ($mayToggle) {
                $disabled = $mayToggle ? '' : ' disabled aria-disabled="true" title="'
                    . e(__('You do not have permission to change this.')) . '"';

                return '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $brand->id . '" class="form-check-input status-index" name="status"
                    id="status_' . $brand->id . '" value="' . $brand->status . '" ' . ($brand->status ? 'checked' : '') . $disabled . '>
                    <label class="form-check-label" for="status_' . $brand->id . '">
                        <span class="ss-visually-hidden">' . e(__('Enabled')) . '</span>
                    </label>
                    </div>';
            })
            ->editColumn('is_popular', function (ProductBrand $brand) use ($mayToggle) {
                $disabled = $mayToggle ? '' : ' disabled aria-disabled="true" title="'
                    . e(__('You do not have permission to change this.')) . '"';

                return '<div class="form-check form-switch">
                    <input type="checkbox" data-id="' . $brand->id . '" class="form-check-input popular-index" name="is_popular"
                    id="is_popular_' . $brand->id . '" value="' . $brand->is_popular . '" ' . ($brand->is_popular ? 'checked' : '') . $disabled . '>
                    <label class="form-check-label" for="is_popular_' . $brand->id . '">
                        <span class="ss-visually-hidden">' . e(__('Popular')) . '</span>
                    </label>
                    </div>';
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

    /**
     * Whether this caller may actually USE the toggles this table renders. P2-05-1.
     *
     * The switches used to render for everybody and the handler checked nothing, so they always
     * worked. Now the handler requires `Edit Product Brand`, and a switch that answers 403 is
     * the F27 lesson from the notification feed repeated: do not offer a control the request
     * behind it will refuse.
     *
     * DISABLED RATHER THAN HIDDEN. The column's VALUE is information a caller holding
     * `Manage Product Brand` is allowed to read -- hiding it would take away something they may
     * see in order to prevent something they may not do. The switch stays, shows the real state,
     * and does not move.
     *
     * Operator first: Laratrust has no super-admin bypass, so `isAbleTo()` alone refuses the
     * platform operator.
     */
    private function mayToggle(): bool
    {
        $user = auth()->user();

        if ($user === null) {
            return false;
        }

        return \App\Policies\UserPolicy::isOperator($user)
            || $user->isAbleTo('Edit Product Brand');
    }
}
