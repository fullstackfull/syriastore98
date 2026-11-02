<?php

namespace App\DataTables;

use App\Models\ProductAttribute;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductAttributeDataTable extends DataTable
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
            ->addColumn('action', function (ProductAttribute $attribute) {
                return view('attributes.action', compact('attribute'));
            })
            ->addColumn('terms', function (ProductAttribute $attribute) {
                /*
                 * P2-05-4. The option pills become `ss-chip ss-chip--static` -- theme-aware
                 * tokens rather than the vendored `bg-light-primary` on a `border-primary`,
                 * which is the pale-on-pale family P2-04-4 measured below AA. The existing
                 * htmlspecialchars() stays: this cell is built by concatenation, not by Blade.
                 *
                 * The link gains an aria-label naming the ATTRIBUTE. "Configure terms" repeats
                 * once per row, so a screen-reader user listing the links on this page heard the
                 * same phrase N times with nothing to tell them apart.
                 */
                $termsHtml = '';
                foreach ($attribute->attributeOptions as $option) {
                    $termsHtml .= '<span class="ss-chip ss-chip--static">'
                        . htmlspecialchars($option->terms, ENT_QUOTES, 'UTF-8') .
                        '</span>';
                }

                $termsHtml .= '<a class="ss-btn ss-btn--ghost ss-btn--sm" href="'
                    . e(route('product-attribute-option.show', [$attribute->id])) . '" aria-label="'
                    . e(__('Configure terms of :name', ['name' => $attribute->name])) . '">'
                    . e(__('Configure terms')) . '</a>';

                return $termsHtml;
            })
            ->rawColumns(['action','terms']);
        return $dataTable;
    }
    /**
     * Get the query source of dataTable.
     */
    public function query(ProductAttribute $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productattribute-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'slug' => ['title' => __('Slug')],
            'terms' => ['title' => __('Terms')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductAttribute_' . date('YmdHis');
    }
}
