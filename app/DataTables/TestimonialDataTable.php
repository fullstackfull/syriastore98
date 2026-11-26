<?php

namespace App\DataTables;

use App\Models\Testimonial;
use App\Support\DsBadge;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class TestimonialDataTable extends DataTable
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
            ->editColumn('avatar', function (Testimonial $testimonial) {
                $avatar = '<div class="d-flex align-items-center">
                        <a href="'.e(get_file($testimonial->avatar)).'" class="table-image me-3" target="__blank">
                            <img src="'.e(get_file($testimonial->avatar)).'" class="rounded h-100 w-100" loading="lazy" alt="avatar avatar"></a>
                        </div>';

                return $avatar;
            })
            ->addColumn('action', function (Testimonial $testimonial) {
                return view('testimonial.action', compact('testimonial'));
            })
            ->editColumn('category_id', function (Testimonial $testimonial) {
                $maincategory = !empty($testimonial->MainCategoryData) ? $testimonial->MainCategoryData->name : '';
                return $maincategory;
            })
            ->filterColumn('category_id', function ($query, $keyword) {
                $query->whereHas('MainCategoryData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('product_id', function (Testimonial $testimonial) {
                $product = !empty($testimonial->ProductData) ? $testimonial->ProductData->name : '';
                return $product;
            })
            ->filterColumn('product_id', function ($query, $keyword) {
                $query->whereHas('ProductData', function ($q) use ($keyword) {
                    $q->where('name', 'like', "%{$keyword}%");
                });
            })
            ->editColumn('rating_no', function ($testimonial) {
                $ratingHtml = '';

                for ($i = 0; $i < 5; $i++) {
                    $starClass = $i < $testimonial->rating_no ? 'text-warning' : '';
                    $ratingHtml .= '<i class="ti ti-star ' . $starClass . '"></i>';
                }

                return $ratingHtml;
            })
            /*
             * `CUST-005` — «التقييمات الموثقة» / *verified reviews*. THE MERCHANT'S HALF OF IT.
             *
             * The register records this requirement as half-tracked, with verified reviews
             * appearing **zero** times in the traceability matrix, and `GAP-312` measured why: the
             * fact was unrecordable. It is recorded now, by
             * `App\Support\Commerce\VerifiedPurchase` at the two storefront doors, and this is the
             * only surface in the application where a human reads reviews one at a time — a
             * merchant deciding whether to leave one up. A badge they cannot see is not a feature.
             *
             * **THREE STATES, THREE RENDERINGS, AND THE THIRD IS THE ONE THAT MATTERS.** `null`
             * means NOBODY ASSESSED THIS — every review written before the column existed, and
             * every row written in this panel, where `testimonials.user_id` is a `users` id rather
             * than a `customers` one (the column holds ids from two different tables depending on
             * the writer; `GAP-335` turns on exactly that). Rendering `null` as "Not verified"
             * would assert a negative finding nobody made, which is the fabrication
             * `App\Models\Order::SOURCES`' docblock forbids in the same words. So it renders as a
             * dash with the reason in its `title`, exactly as `OrderDataTable`'s unrecorded
             * `order_source` does.
             *
             * `DsBadge` rather than hand-built markup, and that is not a style choice: P2-05-4
             * records a first version that wrote the span inline and failed the live contrast check
             * in all four LTR/RTL x light/dark configurations on the missing `ss-badge__dot`, which
             * is the second channel keeping the badge from carrying meaning in colour alone
             * (WCAG 1.4.1). `DsBadge::render()` escapes both the label and the title.
             */
            ->editColumn('verified_purchase', function (Testimonial $testimonial) {
                if ($testimonial->verified_purchase === null) {
                    return '<span data-bs-toggle="tooltip" title="'
                        . e(__('This review was not checked against a purchase.'))
                        . '">-</span>';
                }

                return ((int) $testimonial->verified_purchase) === 1
                    ? DsBadge::render('success', __('Verified purchase'), __('This reviewer bought this product in an order placed from their own account.'))
                    : DsBadge::render('warning', __('No purchase found'), __('No completed order from this customer\'s own account contains this product.'));
            })
            ->filterColumn('description', function ($query, $keyword) {
                $query->whereRaw('LOWER(description) LIKE ?', ["%{$keyword}%"]);
            })
            ->orderColumn('description', function ($query, $direction) {
                $query->orderby('description', $direction);
            })
            // P1-SEC-80. The author's name and the category and product names are plain text (the closures return plain text): yajra escapes them. The avatar and the stars are markup built here.
            ->rawColumns(['action', 'avatar', 'rating_no', 'verified_purchase']);
        return $dataTable;
    }

    /**
     * Get the query source of dataTable.
     */
    public function query(Testimonial $model): QueryBuilder
    {
        return $model->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('testimonial-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'avatar' => ['title' => __('Avatar')],
            'username' => ['title' => __('User Name')],
            'category_id' => ['title' => __('Category')],
            'product_id' => ['title' => __('Product')],
            'rating_no' => ['title' => __('Rating')],
            /* CUST-005. After the rating and before the prose, which is where a merchant scanning
               the list is already looking. 'searchable' => false for the reason at the column's
               own closure: the stored value is 0/1/NULL and a LIKE over it matches digits. */
            'verified_purchase' => ['title' => __('Verified'), 'searchable' => false],
            'description' => ['title' => __('Description'), 'orderable' => false, 'addClass' => 'description-wrp']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Testimonial_' . date('YmdHis');
    }
}
