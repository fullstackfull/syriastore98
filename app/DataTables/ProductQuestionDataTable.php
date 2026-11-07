<?php

namespace App\DataTables;

use App\Models\ProductQuestion;
use App\Support\DsBadge;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class ProductQuestionDataTable extends DataTable
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
            ->addColumn('action', function (ProductQuestion $question) {
                return view('product-question.action', compact('question'));
            })
            ->editColumn('customer_id', function (ProductQuestion $question) {
                $user = $question->users->name ?? '';
                    return $user;
            })
            /*
             * P2.5 product-question-status-off-theme-badge. The cell wore the vendored theme's own
             * colour classes and took its contrast from them, measured with the WCAG 2.x formula
             * over the card background:
             *
             *   - `bg-danger` (public/assets/css/style.css:9514-9518, byte-identical at
             *     style-dark.css:9518-9522) is `rgba(255,174,189,0.41) !important` with
             *     `color:#d80027 !important`. The fill is TRANSLUCENT, so it composites with the
             *     card: 4.26:1 over the light `--bs-card-bg:#ffffff` and 1.03:1 over the dark
             *     `#292A33` (style-dark.css:5006) -- below the 4.5:1 AA minimum in BOTH themes,
             *     and effectively invisible in dark.
             *
             *   - `bg-success` (style.css:9488-9492) hard-wires `color:var(--bs-white) !important`
             *     over `background-color:var(--theme-color) !important` -- white on the MERCHANT'S
             *     OWN brand colour. 6.42:1 on today's platform default #335da8, but 2.59:1 on the
             *     still-selectable theme-3 preset #10B77F; custom.css:4874-4887 records six of the
             *     ten presets below 4.5:1 with white text. No single label colour can be right for
             *     a fill the merchant picks, so the badge must stop reading `--theme-color`.
             *
             * `ss-badge` (public/css/store-syria-ds.css:1169-1173) paints its OWN opaque background
             * from the design system's theme-aware tokens, so the card colour cannot enter the
             * calculation: 7.10:1 / 8.79:1 success light/dark and 8.09:1 / 9.06:1 danger
             * light/dark. No stylesheet changes, so no rtl-custom.css twin is owed -- `.ss-badge`
             * is laid out with `padding-inline` and `gap`.
             *
             * Through `DsBadge` rather than a hand-built span: the helper is what guarantees the
             * `ss-badge__dot`, and a hand-built span omitting it is how the first CategoryDataTable
             * attempt failed in all four configurations.
             *
             * `danger` for "Pending" is INHERITED from `bg-danger`, not reasoned -- an unanswered
             * question is a to-do rather than a failure, and the design system has a `warning`
             * tone. Re-colouring a status is a design decision beyond this contrast defect, and
             * CategoryDataTable:49-50 sets the danger-for-negative-state precedent, so it is kept
             * and recorded for a status-semantics pass.
             */
            ->editColumn('answers', function (ProductQuestion $question) {
                if (!empty($question->answers)) {
                    return DsBadge::render('success', __('Answered'));
                } else {
                    return DsBadge::render('danger', __('Pending'));
                }
            })
            ->rawColumns(['action','customer_id','answers']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(ProductQuestion $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('productquestion-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'customer_id' => ['title' => __('User')],
            'question' => ['title' => __('Question')],
            'answers' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'ProductQuestion_' . date('YmdHis');
    }
}
