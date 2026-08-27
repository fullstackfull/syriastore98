<?php

namespace App\DataTables;

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class BlogCategoryDataTable extends DataTable
{
    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        return (new EloquentDataTable($query))
                ->addIndexColumn()
                ->addColumn('action', function (BlogCategory $category) {
                    return view('blog-category.action', compact('category'));
                })
                ->rawColumns(['name','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(BlogCategory $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('blog-category-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')]
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'BlogCategory_' . date('YmdHis');
    }
}
