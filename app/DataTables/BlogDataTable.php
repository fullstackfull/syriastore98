<?php

namespace App\DataTables;

use App\Models\Blog;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class BlogDataTable extends DataTable
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
                ->editColumn('cover_image_path', function (Blog $blog) {
                    // Ensure proper escaping of dynamic variables
                    $imageUrl = get_file($blog->cover_image_path);
                    return '<img src="'. $imageUrl .'" alt="" width="100" class="cover_img_'. $blog->id .'">';
                })
                ->editColumn('category_id', function (Blog $blog) {
                    return $blog->category->name ?? '-';
                })
                ->addColumn('action', function (Blog $blog) {
                    return view('blog.action', compact('blog'));
                })
                ->filterColumn('category_id', function ($query, $keyword) {
                    // Assuming `permissions` is a relationship, adjust as needed
                    $query->whereHas('category', function ($subQuery) use ($keyword) {
                        $subQuery->where('name', 'like', "%$keyword%");
                    });
                })
                ->filterColumn('description', function ($query, $keyword) {
                    $query->whereRaw('LOWER(blogs.description) LIKE ?', ["%{$keyword}%"]);
                })
                ->filterColumn('deion', function ($query, $keyword) {
                    $query->whereRaw('LOWER(blogs.description) LIKE ?', ["%{$keyword}%"]);
                })
                ->filterColumn('short_deion', function ($query, $keyword) {
                    $query->whereRaw('LOWER(blogs.short_description) LIKE ?', ["%{$keyword}%"]);
                })
                ->orderColumn('description', function ($query, $direction) {
                    $query->orderby('blogs.description', $direction);
                })
                ->orderColumn('deion', function ($query, $direction) {
                    $query->orderby('blogs.description', $direction);
                })
                ->orderColumn('short_deion', function ($query, $direction) {
                    $query->orderby('blogs.short_description', $direction);
                })
                ->rawColumns(['cover_image_path','category_id','title','slug','short_description','action']);
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Blog $model): QueryBuilder
    {
        return $model->newQuery()->with('category')->where('store_id',getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
    public function html(): HtmlBuilder
    {
        return buildDataTable('blogs-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
    public function getColumns(): array
    {
        return buildDataTableColumn([
            'cover_image_path' => ['title' => __('Cover Image')],
            'category_id' => ['title' => __('Category')],
            'title' => ['title' => __('Title')],
            'slug' => ['title' => __('Slug')],
            'short_description' => ['title' => __('Short Description'), 'addClass' => 'description-wrp'],
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Blog_' . date('YmdHis');
    }
}
