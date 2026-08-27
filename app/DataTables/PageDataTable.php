<?php

namespace App\DataTables;

use App\Models\Page;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class PageDataTable extends DataTable
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
            ->editColumn('page_status', function (Page $page) {
                $switch = '';
                if ($page->page_status == true || !empty($page->page_status)) {
                    $switch = '<div class="form-check form-switch"> <input type="checkbox" class="form-check-input page-toggle" data-page-id="'.$page->id.'"  checked /> </div>';
                } else {
                    $switch = '<div class="form-check form-switch"> <input type="checkbox" class="form-check-input page-toggle" data-page-id="'.$page->id.'" /> </div>';
                }
                return $switch;
            })
            ->addColumn('action', function (Page $page) {
                return view('page.action', compact('page'));
            })
            ->rawColumns(['page_name','page_slug','page_status','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Page $model): QueryBuilder
    {
        return $model->newQuery()->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('page-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'page_name' => ['title' => __('Page Name')],
            'page_slug' => ['title' => __('Page Slug')],
            'page_status' => ['title' => __('Page Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Page_' . date('YmdHis');
    }
}
