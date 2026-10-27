<?php

namespace App\DataTables;

use App\Models\Category;
use App\Support\DsBadge;
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
            /*
             * P2-05-4. `bg-success` / `bg-danger` are the vendored theme's, and P2-04-4 measured
             * that family on the order list: below AA in 44 of 56 readings across LTR/RTL x
             * light/dark, two of them effectively unreadable. `ss-badge` takes theme-aware
             * tokens instead, which is the same substitution that took the order list's worst
             * reading to 6.82:1.
             *
             * Through `DsBadge` rather than a hand-built span, and that was not a style choice:
             * the first version wrote the markup inline and the live check failed in all four
             * configurations on the missing `ss-badge__dot`. The dot is the second channel that
             * keeps the badge from carrying its meaning in colour alone (WCAG 1.4.1), and the
             * helper is where that is guaranteed. `DsBadge::render()` escapes the label itself.
             */
            ->editColumn('status', function ($category) {
                return $category->status
                    ? DsBadge::render('success', __('Active'))
                    : DsBadge::render('danger', __('In-Active'));
            })
            /*
             * P2-05-4: `e()`, for the same reason as `parent_id` eleven lines below -- and found
             * by the independent review of that fix, which pointed out the identical shape in the
             * same method.
             *
             * THE FILENAME IS ATTACKER-CONTROLLED END TO END. `CategoryController:89` builds it
             * as `rand() . '_' . time() . '_' . $request->image->getClientOriginalName()`, and
             * `getClientOriginalName()` is the raw multipart filename: Symfony strips path
             * separators and nothing else, so a double quote survives. `Utility.php:402` does not
             * sanitise it, `get_file()` returns `url($path)` and Laravel's UrlGenerator
             * rawurlencodes only its `$extra` argument, not the path. `XSS` middleware does not
             * help either -- it only filters STRING request values, so an UploadedFile is skipped.
             *
             * So a real PNG named `a" onerror=... z=".png` passes the `mimes:` rule and lands in
             * this cell as live markup, because the column is in `rawColumns()` and the cell is
             * built by concatenation rather than by Blade. Same blast radius as the category-name
             * defect (F125): same store, any user who opens the list.
             */
            ->editColumn('image_path', function (Category $category) {
                if (empty($category->image_path)) {
                    return '-';
                }

                return '<img src="' . e(get_file($category->image_path)) . '" alt="" class="category_Image">';
            })
            ->editColumn('icon_path', function (Category $category) {
                if (empty($category->icon_path)) {
                    return '-';
                }

                return '<img src="' . e(get_file($category->icon_path)) . '" alt="" class="category_Image">';
            })
            ->editColumn('parent_id', function (Category $category) {
                // P1-INV-11: the relation is unscoped, so a parent_id written before the controller validated it could name another store's
                // category. Only a parent of the SAME store is printed.
                $parent = $category->parent_category;

                /*
                 * P2-05-4: `e()`. `parent_id` is in rawColumns(), so this string is printed as
                 * MARKUP -- and `$parent->name` is merchant input. Same shape as F98 on
                 * CustomerDataTable, on the screen this slice converts. Blade is not involved on
                 * this path: the DataTable builds the cell itself.
                 */
                return ($parent && (int) $parent->store_id === (int) $category->store_id) ? e($parent->name) : '-';
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
