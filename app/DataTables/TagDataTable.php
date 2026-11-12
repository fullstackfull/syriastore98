<?php

namespace App\DataTables;

use App\Models\Tag;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class TagDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))->addIndexColumn();

        // Resolved ONCE, outside the per-row closure: a permission lookup per row is a lookup
        // per row. The same shape as ProductLabelDataTable::mayToggle().
        if ($this->mayAct()) {
            $dataTable->addColumn('action', function (Tag $tag) {
                return view('tag.action', compact('tag'));
            });
        }

        return $dataTable->rawColumns(['name','action']);
    }

    /**
     * MAY THIS USER ACT ON A TAG ROW AT ALL?
     *
     * The Action column used to be declared unconditionally while its CELLS were gated:
     * `tag/action.blade.php` wraps Edit in `@permission('Edit Tag')` and Delete in
     * `@permission('Delete Tag')`. A merchant holding neither therefore got a column headed
     * "Action" containing nothing at all on every row -- which is what the owner's capture of
     * 2026-11-06 shows, taken before the dev fixture was granted those two permissions.
     *
     * THE PREDICATE IS DELIBERATELY THE DIRECTIVE'S OWN CALL, not an equivalent-looking one.
     * `@permission(X)` compiles to `app('laratrust')->hasPermission(X)`
     * (LaratrustServiceProvider:102), and `hasPermission()` takes an array with
     * `$requireAll = false`, so this asks exactly "holds at least one of the two" through
     * exactly the mechanism the cells use. A header gated on a DIFFERENT predicate -- say
     * `isAbleTo()` plus an operator bypass, as the status toggles use -- could disagree with
     * its own cells and reintroduce the empty column for whichever principal the two answer
     * differently about. CLAUDE.md records that there is no operator bypass in `isAbleTo()`,
     * so that divergence is real and not hypothetical.
     *
     * This HIDES A COLUMN. It never renders a control: the cells keep their own `@permission`
     * guards, so nothing here can show an action a user is not entitled to use.
     */
    private function mayAct(): bool
    {
        return app('laratrust')->hasPermission(['Edit Tag', 'Delete Tag']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Tag $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('tag-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        // The second argument is `$action` -- helper.php:2938 appends the Action column when it
        // is true. Passing the same predicate the cells use is what stops an empty column being
        // declared for a user who can do nothing in it.
        return buildDataTableColumn([
            'name' => ['title' => __('Name'), 'addClass' => 'ss-dt-ident']
        ], $this->mayAct());
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Tag_' . date('YmdHis');
    }
}
