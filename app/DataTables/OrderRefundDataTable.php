<?php

namespace App\DataTables;

use App\Http\Controllers\OrderRefundController;
use App\Models\OrderRefund;
use App\Support\DsBadge;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class OrderRefundDataTable extends DataTable
{
    /**
     * Tone and label per refund state, keyed on OrderRefundController's own constants
     * (OrderRefundController.php:145-148) the way OrderStatusTone keys off HomeController's.
     *
     * A METHOD AND NOT A CONST, for two reasons: `__()` cannot run in a const initialiser, and
     * the locale is only known per request -- `ThemeLanguage::handle()` calls
     * `App::setLocale($user->language)` on the AJAX leg too (routes/web.php:1545).
     *
     * WHY THE MARKUP CHANGED AT ALL -- MEASURED, NOT ASSUMED. The column used to emit
     * `badge badge-80 rounded p-2 f-w-600 bg-light-*`, so the winning declarations were
     * `.badge.bg-light-{success,info,warning,danger}` (public/assets/css/style.css:19579/19585/
     * 19591/19597, byte-identical in style-dark.css, so both themes read alike). Composited over
     * the DataTables card surface `--ss-dt-card-bg` (#ffffff light / #292a33 dark,
     * store-syria-ds.css:3316-3318) with the WCAG 2.x formula:
     *
     *     success  #0bb755 on rgba(11,183,85,.22)   2.13:1 light    3.80:1 dark
     *     info     #3ec9d6 on #d8f4f7               1.73:1 both
     *     warning  #ffa21d on #ffecd2               1.74:1 both
     *     danger   #ff3a6e on #ffd8e2               2.66:1 both
     *
     * Eight readings, all eight under the 4.5:1 minimum of WCAG 1.4.3. The design system's own
     * tones are 7.10/8.83/8.09/8.28/8.12:1 light and 8.79/9.81/9.06/8.25/8.98:1 dark
     * (success/warning/danger/info/neutral), taken from tokens at store-syria-ds.css:287-297 and
     * :423-427, so no stylesheet is touched here and dark mode is carried by the theme block
     * rather than by PHP. The numbers are recomputed in RefundStatusBadgeTest.
     *
     * THE HUES MOVE, AND THAT IS A DECLARED CHANGE, NOT A BY-PRODUCT OF THE CONTRAST FIX.
     * Before: Processing=info, Accept=success -- and success was the DEFAULT, so every
     * out-of-vocabulary value claimed success too -- Refunded=warning, Cancel=danger.
     * After: Processing=warning, Accept=info, Refunded=success, Cancel=danger: the assignment
     * the shipped order list already uses for Pending/Confirmed/Delivered
     * (OrderStatusTone::TONES), because 'Refunded' is the settled terminal state
     * (RefundIntegrityService.php:71) while 'Accept' is approved-not-settled (:80), and
     * amber-for-done contradicted Delivered=success one screen away. The contrast defect alone
     * did not require this; it is recorded as a Phase 2.5 decision.
     */
    private static function states(): array
    {
        return [
            OrderRefundController::STATE_PROCESSING => ['warning', __('Processing')],
            OrderRefundController::STATE_ACCEPT     => ['info',    __('Accept')],
            OrderRefundController::STATE_REFUNDED   => ['success', __('Refunded')],
            OrderRefundController::STATE_CANCEL     => ['danger',  __('Cancel')],
        ];
    }

    /**
     * Build the DataTable class.
     *
     * @param QueryBuilder $query Results from query() method.
     */
    public function dataTable(QueryBuilder $query): EloquentDataTable
    {
        $dataTable = (new EloquentDataTable($query))
            ->addIndexColumn()
            ->addColumn('action', function (OrderRefund $refund_request) {
                return view('order.refund-action', compact('refund_request'));
            })
            ->addColumn('order_id', function ($refund_request) {
                $order_id_encrypted = \Illuminate\Support\Facades\Crypt::encrypt($refund_request->order_id);
                $order_id_display = $refund_request->order ? '#' . $refund_request->order->product_order_id : '#N/A';
                
                $html = '<div class="d-flex align-items-center">
                            <a href="' . route('refund-request.show', $order_id_encrypted) . '"
                               class="btn btn-primary btn-sm text-sm"
                               data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">' . $order_id_display . '</span>
                            </a>
                        </div>';

                return $html;
            })
            ->addColumn('created_at', function ($refund_request) {
                return \Carbon\Carbon::parse($refund_request['created_at'])->format('Y-m-d');
            })
            ->addColumn('refund_status', function ($refund_request) {
                $status = (string) ($refund_request->refund_status ?? '');
                [$tone, $label] = self::states()[$status]
                    ?? ['neutral', $status === '' ? __('Status not set') : $status];

                // DsBadge::render() escapes the label, which is what makes the fallback safe:
                // refund_status is a rawColumns column, so nothing downstream would.
                return DsBadge::render($tone, $label);
            })
            ->filterColumn('order_id', function ($query, $keyword) {
                $query->whereHas('order', function ($subQuery) use ($keyword) {
                    $subQuery->where('product_order_id', 'like', "%$keyword%");
                });
            })
            ->filterColumn('refund_status', function ($query, $keyword) {
                /*
                 * THE COLUMN NOW DISPLAYS A TRANSLATION, SO SEARCH HAS TO ACCEPT ONE.
                 * refund_status is a string holding the English literal (migration
                 * 2024_02_07_071800:17), and without this yajra compiles
                 * `refund_status like %kw%` (QueryDataTable::compileQuerySearch):
                 * an Arabic merchant typing the label they can see ('المعالجة')
                 * matches nothing, where today -- with the English literal on screen -- typing
                 * it works. Same reason ProductDataTable carries a filterColumn for every column
                 * whose editColumn moved the display off the raw value.
                 *
                 * THE RAW `like` IS THE FIRST LIMB ON PURPOSE: an English merchant, an
                 * English-literal search, a partial word and any saved search keep working.
                 *
                 * ISOLATION IS UNAFFECTED. applyFilterColumn runs this on a fresh OrderRefund
                 * query and merges its wheres as a nested group with boolean 'or' INSIDE the
                 * closure globalSearch already wrapped. `where('store_id', getCurrentStore())`
                 * in query() stays ANDed outside it. OrderRefund IS in scoped_models
                 * (config/tenancy.php:216), so that fresh query also carries the StoreScope
                 * predicate into the nested group -- which narrows this limb, never widens it.
                 */
                $keyword = trim((string) $keyword);
                $matched = [];

                foreach (self::states() as $state => [, $label]) {
                    if ($keyword !== '' && mb_stripos($label, $keyword) !== false) {
                        $matched[] = $state;
                    }
                }

                $query->where(function ($q) use ($keyword, $matched) {
                    $q->where('refund_status', 'like', '%' . $keyword . '%');

                    if ($matched !== []) {
                        $q->orWhereIn('refund_status', $matched);
                    }
                });
            })
            ->rawColumns(['action', 'order_id', 'created_at', 'refund_status']);
        return $dataTable;
    }


    /**
     * Get the query source of dataTable.
     */
    public function query(OrderRefund $model): QueryBuilder
    {
        return $model->newQuery()->with('order')->where('store_id', getCurrentStore());
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('order-refunds-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
        public function getColumns(): array
    {
        return buildDataTableColumn([
            'order_id' => ['title' => __('Order Id')],
            'created_at' => ['title' => __('Refund Request Date')],
            'refund_status' => ['title' => __('Refund Request Status'), 'addClass' => 'text-capitalize']
        ]);
    }

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'OrderRefund_' . date('YmdHis');
    }
}
