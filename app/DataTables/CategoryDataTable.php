<?php

namespace App\DataTables;

use App\Models\Category;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class CategoryDataTable extends DataTable
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
            ->addColumn('action', function (Category $category) {
                return view('category.action', compact('category'));
            })
            ->editColumn('trending', function (Category $category) {
                $trending = $category->trending == 1 ? __('Yes') : __('No');
                    return $trending;
            })
            ->editColumn('status', function ($category) {
                if (!empty($category->status)) {
                    return '<span class="badges fix_badges bg-success p-2 px-3 badge">' . __('Active') . '</span>';
                } else {
                    return '<span class="badges fix_badges bg-danger p-2 px-3 badge">' . __('In-Active') . '</span>';
                }
            })
            ->editColumn('image_path', function (Category $category) {
                if (!empty($category->image_path)) {
                    $imagePath = get_file($category->image_path);
                    $html = '<img src="' . $imagePath . '" alt="" class="category_Image">';
                } else {
                    $html = '-';
                }
                return $html;
            })
            ->editColumn('icon_path', function (Category $category) {
                if (!empty($category->icon_path)) {
                    $iconPath = get_file($category->icon_path);
                    $iconPath = '<img src="' . $iconPath . '" alt="" class="category_Image">';
                } else {
                    $iconPath = '-';
                }
                return $iconPath;
            })
            ->editColumn('parent_id', function (Category $category) {
                return optional($category->parent_category)->name ?? '-';
            })
            ->filterColumn('parent_id', function ($query, $keyword) {
                $query->whereHas('parent_category', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->rawColumns(['action','image_path','trending','status','icon_path','parent_id']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Category $model): QueryBuilder
    {
        return $model->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('category-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'name' => ['title' => __('Name')],
            'image_path' => ['title' => __('Image')],
            'icon_path' => ['title' => __('Icon')],
            'parent_id' => ['title' => __('Parent Category')],
            'trending' => ['title' => __('Trending')],
            'status' => ['title' => __('Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Category_' . date('YmdHis');
    }
}
