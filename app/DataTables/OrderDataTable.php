<?php

namespace App\DataTables;

use App\Models\Order;
use App\Http\Controllers\HomeController;
use App\Support\OrderStatusTone;
use Illuminate\Database\Eloquent\Builder as QueryBuilder;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Html\Builder as HtmlBuilder;
use Yajra\DataTables\Html\Button;
use Yajra\DataTables\Html\Column;
use Yajra\DataTables\Html\Editor\Editor;
use Yajra\DataTables\Html\Editor\Fields;
use Yajra\DataTables\Services\DataTable;

class OrderDataTable extends DataTable
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
            ->addColumn('action', function (Order $order) {
                return view('order.action', compact('order'));
            })
            ->editColumn('product_order_id', function ($item) {
                /*
                 * ESCAPED, for the same reason as the customer cell below and with a longer
                 * path to it. `product_order_id` is a STRING column
                 * (`create_orders_table.php:16`) and `PaymentService.php:2689` builds it as
                 * `$request->customer_id . date('YmdHis')` for an authenticated shopper -- raw
                 * caller input, not the authenticated customer's id. The same request value is
                 * also written to `orders.customer_id`, which is `unsignedBigInteger`, so the
                 * obvious objection is that the insert would fail -- but `config/database.php`
                 * sets `'strict' => false` on the `mysql` connection, so MySQL COERCES that one
                 * to 0 and stores the string column whole.
                 *
                 * THE AUTHORIZATION HALF OF THAT IS NOT FIXED HERE and is recorded as ledger
                 * F46: reading `$request->customer_id` where `auth('customers')->id()` is
                 * available is the pattern CLAUDE.md names as this codebase's most productive
                 * defect class, and it sits on a payment path, which the owner's constraints
                 * reserve for escalation. Escaping the cell closes the rendering vector whatever
                 * is decided there.
                 */
                return '<div class="d-flex align-items-center">
                            <a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($item->id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">#' . e($item->product_order_id) . '</span>
                            </a>
                        </div>';
            })
            ->editColumn('order_date', function ($item) {
                // F19. `Utility::dateFormat(null)` is `date($fmt, strtotime(null))`, and
                // `strtotime(null)` is FALSE, so that call renders the Unix EPOCH -- a date the
                // order does not have, in the same type as the dates around it that are real.
                return self::dateOrDash($item->order_date);
            })
            ->editColumn('customer_id', function ($item) {
                /*
                 * ESCAPED. `customer_id` is in this table's `rawColumns()` list, so yajra does
                 * NOT escape it -- and both values here are written by a SHOPPER. Registration
                 * validates `first_name` as `'required','string','max:255'` and nothing more
                 * (`Customer\Auth\CustomerLoginController::register`), so a shopper of this
                 * merchant's own store chooses the string this cell prints into the merchant's
                 * admin origin. `Customer::getNameAttribute()` is `first_name . ' ' . last_name`.
                 *
                 * THE XSS MIDDLEWARE DOES NOT COVER IT, and reading it is the point:
                 * `app/Http/Middleware/XSS.php` runs `htmlspecialchars_decode()` and THEN strips
                 * only the four strings `&lt;`, `&gt;`, `javascript`, `alert`. The decode turns an
                 * encoded `&lt;` into a raw `<` before the strip looks for `&lt;`, and a raw `<`
                 * was never a target to begin with -- so `<img src=x onerror=...>` passes through
                 * whole, as long as it avoids two words. A denylist of four strings is not an
                 * escaping layer and nothing downstream supplies one.
                 *
                 * Found by the tenant/authz review of the status-column change, on the same
                 * `rawColumns()` line that change edits. `<br>` stays raw: it is this file's own
                 * markup, not the data.
                 */
                if ($item->is_guest == 1) {
                    return __('Guest');
                } elseif ($item->customer_id != 0) {
                    return e(!empty($item->CustomerData->name) ? $item->CustomerData->name : '') . '<br>' .
                        e(!empty($item->CustomerData->mobile) ? $item->CustomerData->mobile : '');
                } else {
                    return __('Walk-in-customer');
                }
            })
->editColumn('final_price', function ($item) {
    return order_currency_format_with_sym(($item->final_price ?? 0), $item->id) ?? SetNumberFormat($item->final_price);
})
->editColumn('payment_type', function ($item) {
                $paymentTypes = [
                    'cod' => __('Cash On Delivery'),
                    'paymera' => __('Paymera'),
                    'bank_transfer' => __('Bank Transfer'),
                    'stripe' => __('Stripe'),
                    'paystack' => __('Paystack'),
                    'mercado' => __('Mercado Pago'),
                    'skrill' => __('Skrill'),
                    'paymentwall' => __('PaymentWall'),
                    'Razorpay' => __('Razorpay'),
                    'paypal' => __('Paypal'),
                    'flutterwave' => __('Flutterwave'),
                    'mollie' => __('Mollie'),
                    'coingate' => __('Coingate'),
                    'paytm' => __('Paytm'),
                    'POS' => __('POS'),
                    'toyyibpay' => __('Toyyibpay'),
                    'sspay' => __('Sspay'),
                    'Paytabs' => __('Paytabs'),
                    'iyzipay' => __('IyziPay'),
                    'payfast' => __('PayFast'),
                    'benefit' => __('Benefit'),
                    'cashfree' => __('Cashfree'),
                    'aamarpay' => __('Aamarpay'),
                    'telegram' => __('Telegram'),
                    'whatsapp' => __('Whatsapp'),
                    'paytr' => __('PayTR'),
                    'yookassa' => __('Yookassa'),
                    'midtrans' => __('Midtrans'),
                    'Xendit' => __('Xendit'),
                    'Nepalste' => __('Nepalste'),
                    'khalti' => __('Khalti'),
                    'AuthorizeNet' => __('AuthorizeNet'),
                    'Tap' => __('Tap'),
                    'PhonePe' => __('PhonePe'),
                    'Paddle' => __('Paddle'),
                    'Paiementpro' => __('Paiement Pro'),
                    'FedPay' => __('FedPay'),
                    'CinetPay' => __('CinetPay'),
                    'SenagePay' => __('SenagePay'),
                    'CyberSource' => __('CyberSource'),
                    'Ozow' => __('Ozow'),
                    'MyFatoorah' => __('MyFatoorah'),
                    'easebuzz' => __('Easebuzz'),
                    'NMI' => __('NMI'),
                    'PayU' => __('PayU'),
                    'sofort' => __('Sofort'),
                    'esewa' => __('Esewa'),
                    'Paynow' => __('Paynow'),
                    'DPO' => __('DPO'),
                    'Braintree' => __('Braintree'),
                    'PowerTranz' => __('PowerTranz'),
                    'SSLCommerz' => __('SSLCommerz'),
                    'Lottery Product' => __('Lottery Product')
                ];
                return $paymentTypes[$item->payment_type] ?? '-';
            })
->addColumn('shipping_method', function (Order $item) {
    return $item->ShippingMethod?->method_name ?? '-';
})
            /*
             * P2-04-11 / WS E8 -- WHICH PATH CREATED THE ORDER.
             *
             * A LABEL FOR A RECORDED SOURCE, AND A DIFFERENT KIND OF THING FOR AN ABSENT ONE.
             * `order_source` is NULL on every order written before the column existed, so the
             * cell has to distinguish "a storefront order" from "nobody recorded where this came
             * from" -- and it must not do that with colour alone. A recorded source renders as a
             * badge; an unrecorded one renders as a dash with its reason in the tooltip, which is
             * a difference in KIND and survives greyscale, dark mode and a screen reader.
             * `OrderSourceAttributionTest` pins exactly that: a legacy order must not read as a
             * storefront order.
             *
             * The variants carry no judgement -- neutral for the storefront (the ordinary case),
             * `--info` for a typed order, `--brand` for the till -- and the DS's own rule applies:
             * "colour alone never carries the meaning, the text label beside it does".
             */
            ->editColumn('order_source', function ($item) {
                $label = Order::orderSourceLabel($item->order_source);

                if ($label === null) {
                    /*
                     * A BARE DASH, which is this table's own idiom for an absent value
                     * (`payment_type`, `shipping_method` and `dateOrDash` all render `'-'`), so
                     * the cell inherits the surrounding colour and needs no new class. The only
                     * addition is the tooltip, which says WHY it is empty -- the cell must not
                     * leave a merchant guessing whether the source is missing or the order is
                     * odd. `.ss-muted` was written here first and does not exist in
                     * `store-syria-ds.css`; it would have rendered as unstyled default text.
                     */
                    return '<span data-bs-toggle="tooltip" title="'
                        . e(__('This order was created before the source was recorded.'))
                        . '">-</span>';
                }

                $variant = match ($item->order_source) {
                    Order::SOURCE_POS => ' ss-badge--brand',
                    Order::SOURCE_MANUAL => ' ss-badge--info',
                    default => '',
                };

                return '<span class="ss-badge' . $variant . '">' . e($label) . '</span>';
            })
            ->editColumn('delivered_status', function ($item) {
                /*
                 * THE DESIGN SYSTEM'S BADGE, NOT THE VENDORED THEME'S BUTTONS.
                 *
                 * Two defects in one piece of markup, both measured in the running application.
                 *
                 * CONTRAST. The `btn btn-soft-*` classes this column used come from
                 * `public/assets/css/style.css` and are below AA in 44 of 56 readings across
                 * LTR/RTL x light/dark -- `btn-soft-dark` (Shipped, Partially Paid, Pre Order)
                 * at 1.12:1 in dark mode and `btn-soft-danger` (Cancel, Return) at 1.03:1 are
                 * not readable at all. Seen first in a screenshot: row 2 of the Arabic dark
                 * order list had a status control with no visible text in it.
                 *
                 * AND A BUTTON THAT IS NOT A BUTTON. Every one of these was
                 * `<button type="button">` with no handler, no `onclick` and no form -- a status
                 * label wearing a control's clothes. Assistive technology announces it as an
                 * actionable button and a keyboard user tabs to each one in turn for nothing.
                 * The status is a label, so it is now a `<span>`.
                 *
                 * The tone comes from `OrderStatusTone`, which the dashboard's "Latest orders"
                 * card reads too. The two surfaces previously disagreed about four of the nine
                 * statuses -- Shipped was `info` on the dashboard and `dark` here, Confirmed
                 * `info` there and `warning` here, Pending `warning` there and `info` here,
                 * Picked Up `info` there and `secondary` here -- so one order changed colour
                 * between two screens a merchant reads one after the other.
                 */
                $labels = [
                    HomeController::ORDER_STATUS_PENDING        => __('Pending') . self::statusDate($item->order_date),
                    HomeController::ORDER_STATUS_DELIVERED      => __('Delivered') . self::statusDate($item->delivery_date),
                    HomeController::ORDER_STATUS_CANCEL         => __('Cancel') . self::statusDate($item->cancel_date),
                    HomeController::ORDER_STATUS_RETURN         => __('Return') . self::statusDate($item->return_date),
                    HomeController::ORDER_STATUS_CONFIRMED      => __('Confirmed') . self::statusDate($item->confirmed_date),
                    HomeController::ORDER_STATUS_PICKED_UP      => __('Picked Up') . self::statusDate($item->picked_date),
                    HomeController::ORDER_STATUS_SHIPPED        => __('Shipped') . self::statusDate($item->shipped_date),
                    HomeController::ORDER_STATUS_PARTIALLY_PAID => __('Partially Paid') . self::statusDate($item->order_date),
                    HomeController::ORDER_STATUS_PRE_ORDER      => __('Pre Order') . self::statusDate($item->order_date),
                ];

                /*
                 * NULL IS NOT PENDING. `(int) null` is `0`, so casting first would print
                 * "Pending" for an order whose status was never written -- a confident claim
                 * about fulfilment made out of an absent value, which is the same class of
                 * defect as F19's epoch dates. It is reported as unset instead.
                 *
                 * An unrecognised NON-NULL code is SHOWN rather than swallowed: the previous
                 * array returned '' for anything outside 0-8, so a tenth status rendered an
                 * order with no status at all. `LynomiaCommerceDataTest` establishes that
                 * out-of-range values do occur in this column.
                 */
                if ($item->delivered_status === null || $item->delivered_status === '') {
                    return OrderStatusTone::badge(-1, __('Status not set'));
                }

                $status = (int) $item->delivered_status;
                $label = $labels[$status] ?? __('Status :code', ['code' => $status]);

                return OrderStatusTone::badge($status, $label);
            })
            ->rawColumns(['action', 'product_order_id', 'order_date', 'customer_id', 'final_price', 'payment_type', 'order_source', 'delivered_status']);
        return $dataTable;
    }

    /**
     * F19 -- a date this order does not have is not invented.
     * =========================================================================================
     *
     * `Utility::dateFormat()` is `date($settings, strtotime($date))`. `strtotime(null)` and
     * `strtotime('')` both return FALSE, and `date($fmt, false)` is `date($fmt, 0)` -- 1 January
     * 1970. So an order marked Delivered whose `delivery_date` was never written rendered
     * **"Delivered : Jan 1, 1970"**: a fabricated date presented exactly like the real ones in
     * the rows around it, with nothing to tell a merchant which is which. Seen in this phase's
     * live screenshots of the order list.
     *
     * WHY THIS IS HERE AND NOT IN `Utility::dateFormat()`. The null behaviour is wrong wherever
     * it happens, but that helper has 64 call sites and **28 of them are inside vendored
     * `packages/workdo/` modules**. Changing it alters output across seventy modules with no
     * regression evidence available for any of them -- a sweep with an owner decision behind it,
     * not a line in a list fix. The surface P2-04 owns is corrected here; the wider finding, and
     * the same epoch in `Order::getReturnDateAttribute()`, stay on the ledger.
     *
     * STATIC, because these are called from the `editColumn()` closures above, which yajra
     * invokes with its own binding.
     */
    private static function hasDate($value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        // A zero-ish STORED value is the epoch too: `'0000-00-00'` is what MySQL's old zero-date
        // default leaves behind, and `strtotime()` answers false for that as well. `> 0` rather
        // than `!== false` so a literal `'1970-01-01'` that somebody actually stored is also
        // treated as the placeholder it almost certainly is.
        $timestamp = strtotime((string) $value);

        return $timestamp !== false && $timestamp > 0;
    }

    /** The formatted date, or a dash -- never a date the row does not have. */
    private static function dateOrDash($value): string
    {
        return self::hasDate($value) ? \App\Models\Utility::dateFormat($value) : '-';
    }

    /**
     * The ` : <date>` suffix a status badge carries, or nothing at all.
     *
     * THE LABEL IS NOT THE DATE'S HOSTAGE: with no date the badge still reads "Delivered",
     * because the status is what the column exists for and a merchant needs it whether or not
     * the timestamp was recorded. Only the fabricated half goes. The single trailing space
     * preserves the badge's existing padding either way.
     */
    private static function statusDate($value): string
    {
        return self::hasDate($value)
            ? ' : ' . \App\Models\Utility::dateFormat($value)
            : '';
    }

    /**
     * Get the query source of dataTable.
     */
    /**
     * P2-04-8. The caller's own orders, optionally NARROWED.
     *
     * THE STORE PREDICATE COMES FIRST AND IS NOT NEGOTIABLE. `Order` is not in
     * `config/tenancy.php`'s `scoped_models` and registers no `StoreScope`, so this
     * `where('store_id', ...)` is the ONLY thing keeping one merchant's orders out of another's
     * list. Filters are applied after it and can only add predicates.
     */
    public function query(Order $model): QueryBuilder
    {
        $query = $model->newQuery()
            ->with('ShippingMethod')
            ->where('store_id', getCurrentStore())
            ->orderBy('created_at', 'desc');

        return $this->applyFilters($query);
    }

    /**
     * P2-04-8 -- the list filters. The SAME SHAPE as `ProductDataTable::applyFilters()`,
     * deliberately: two lists with two idioms for "read a filter from request input into a
     * query" is how they drift apart, and this one carries a date range, which is the half with
     * the sharp edges.
     *
     * `ss_`-prefixed, because the AJAX leg's url IS the page url (yajra's `minifiedAjax()` sets
     * it that way), so DataTables' own `draw`, `columns`, `search`, `order`, `start` and
     * `length` travel in the same query string. No JavaScript: the bar is a GET form and both
     * legs see the same parameters.
     *
     * An unrecognised value adds no predicate. A filter can only narrow.
     */
    private function applyFilters(QueryBuilder $query): QueryBuilder
    {
        $request = request();

        /*
         * STATUS IS MATCHED AGAINST THE CONSTANTS, not against `0..6` or a literal list here.
         * `orders.delivered_status` holds NINE values, and 7 (Partially Paid, a payment state)
         * and 8 (Pre Order, an order type) sit in the same column as the seven fulfilment
         * states -- host ledger F0. A filter offering only 0..6 would make those orders
         * unreachable from the screen a merchant uses to find them.
         */
        $status = $request->query('ss_status');

        if (is_string($status) && in_array($status, self::orderStatuses(), true)) {
            $query->where('delivered_status', (int) $status);
        }

        /*
         * PAYMENT IS AN EXACT MATCH, never a `LIKE`. "Paid" and "Partially Paid" are different
         * financial states and a loose match would quietly merge them -- asserted directly,
         * because that is the mistake a `LIKE` here would make and it would look like it worked.
         */
        $payment = $request->query('ss_payment');

        if (is_string($payment) && in_array($payment, self::PAYMENT_STATUSES, true)) {
            $query->where('payment_status', $payment);
        }

        /*
         * P2-04-13. RETURN REQUESTS AWAITING A DECISION -- a queue nothing could filter for.
         *
         * `orders.return_status` is NOT a flag and NOT the same thing as the dashboard's "returns"
         * count. Its migration comments the four values it holds:
         * `0 => none, 1 => request, 2 => approve, 3 => cancel`
         * (2024_01_23_061635_create_orders_table.php:38), so it is the RETURN-REQUEST WORKFLOW.
         *
         * THE DASHBOARD'S COUNT IS A DIFFERENT COLUMN, and conflating them was the first version
         * of this filter. `$ssReturnOrders` comes from `getOrderCounts()`'s `'return'` bucket,
         * which is `where('delivered_status', ORDER_STATUS_RETURN)` -- the FULFILMENT status
         * (HomeController.php:412). A filter on `return_status` would therefore have sent a
         * merchant clicking that count to a list that does not contain the orders it counted.
         * `delivered_status = 3` is already filterable through `ss_status`, so that count needs no
         * new filter -- it needs its link to carry the parameter, which this slice also fixes.
         *
         * `=== '1'` AND NOTHING ELSE. The operational queue is "a return was raised and nobody has
         * decided yet"; 2 and 3 are decided and 0 is the overwhelming majority. Accepting only
         * `'1'` also means a hand-typed `?ss_return=0` cannot quietly invert the queue into
         * "every order that has no return".
         */
        if ($request->query('ss_return') === '1') {
            $query->where('return_status', 1);
        }

        /*
         * P2-04-11. SOURCE, matched against `Order::SOURCES` so an unrecognised value adds no
         * predicate and the filter can only ever narrow.
         *
         * THERE IS NO "NOT RECORDED" OPTION, deliberately. NULL is the absence of a record, not
         * a fourth source, and offering it beside the three real ones would present an absence
         * of evidence as a category a merchant could reason about. The three values are also the
         * three the vocabulary holds, read from the model rather than written out again here, so
         * a value added to `Order::SOURCES` cannot become filterable in one place and not the
         * other.
         */
        $source = $request->query('ss_source');

        if (is_string($source) && in_array($source, Order::SOURCES, true)) {
            $query->where('order_source', $source);
        }

        $from = self::parseDate($request->query('ss_from'));

        if ($from !== null) {
            $query->where('order_date', '>=', $from->startOfDay());
        }

        $to = self::parseDate($request->query('ss_to'));

        if ($to !== null) {
            /*
             * `endOfDay()`, AND THIS IS THE ONE THAT BITES. `order_date` is a datetime, so a
             * naive `<= '2026-03-17'` compares against MIDNIGHT at the start of that day and
             * silently drops every order placed on the date the merchant actually typed. The
             * test fixture puts an order at 23:45 on the end date for exactly this reason.
             */
            $query->where('order_date', '<=', $to->endOfDay());
        }

        return $query;
    }

    /** The nine values `delivered_status` holds, as strings, read from the constants. */
    private static function orderStatuses(): array
    {
        $statuses = [];

        foreach ((new \ReflectionClass(\App\Http\Controllers\HomeController::class))->getConstants() as $name => $value) {
            if (str_starts_with($name, 'ORDER_STATUS_')) {
                $statuses[] = (string) $value;
            }
        }

        return $statuses;
    }

    /** The payment states this application writes, as literals, matched exactly. */
    public const PAYMENT_STATUSES = ['Paid', 'Unpaid', 'Partially Paid'];

    /**
     * A `Y-m-d` date, or null.
     *
     * `createFromFormat` WITH AN EXPLICIT FORMAT, not `Carbon::parse`. Parse is lenient in
     * surprising ways -- `'0'`, `'now'` and `'+1 day'` all succeed -- so a merchant's typo or a
     * hostile query string would silently become a real bound rather than being ignored. It also
     * THROWS on nonsense, and an unhandled throw here is a 500 on the order list from a query
     * string.
     *
     * The `->format() === $value` check is what makes it strict: `createFromFormat` happily
     * rolls `2026-13-45` over into 2027, and only comparing the round-trip catches that.
     */
    private static function parseDate($value): ?\Carbon\Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = \Carbon\Carbon::createFromFormat('Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }

    /**
     * Optional method if you want to use the html builder.
     */
        public function html(): HtmlBuilder
    {
        return buildDataTable('order-table', $this->builder(), $this->getColumns());
    }

    /**
     * Get the dataTable columns definition.
     */
 public function getColumns(): array
{
    return buildDataTableColumn([
        'product_order_id' => ['title' => __('Order Id')],
        'order_date' => ['title' => __('Date')],
        'customer_id' => ['title' => __('Customer Info')],
        'final_price' => ['title' => __('Price')],
        'payment_type' => ['title' => __('Payment Type')],

        'shipping_method' => [
            'title' => __('Shipping Method'),
            'orderable' => false,
            'searchable' => false,
        ],

        // P2-04-11. Not searchable: the stored value is a machine vocabulary ('pos'), while the
        // cell shows a translated label, so a free-text search box matching the column would
        // answer for one and display the other. The `ss_source` filter is the way in.
        'order_source' => [
            'title' => __('Source'),
            'searchable' => false,
        ],

        'delivered_status' => [
            'title' => __('Order Status'),
            'addClass' => 'text-capitalize',
        ],
    ]);
}

    /**
     * Get the filename for export.
     */
    protected function filename(): string
    {
        return 'Order_' . date('YmdHis');
    }
}