<?php

namespace App\DataTables;

use App\Models\Order;
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
                return '<div class="d-flex align-items-center">
                            <a href="' . route('order.view', \Illuminate\Support\Facades\Crypt::encrypt($item->id)) . '" class="btn btn-primary btn-sm text-sm" data-bs-toggle="tooltip" title="' . __('Invoice ID') . '">
                                <span class="btn-inner--icon"></span>
                                <span class="btn-inner--text">#' . $item->product_order_id . '</span>
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
                if ($item->is_guest == 1) {
                    return __('Guest');
                } elseif ($item->customer_id != 0) {
                    return (!empty($item->CustomerData->name) ? $item->CustomerData->name : '') . '<br>' .
                        (!empty($item->CustomerData->mobile) ? $item->CustomerData->mobile : '');
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
            ->editColumn('delivered_status', function ($item) {
                $statusButtons = [
                    0 => '<button type="button" class="btn btn-sm btn-soft-info btn-icon bg-info badge-same">
                            <span class="btn-inner--icon"><i class="fas fa-check soft-info"></i></span>
                            <span class="btn-inner--text"> ' . __('Pending') . self::statusDate($item->order_date) . ' </span>
                        </button>',
                    1 => '<button type="button" class="btn btn-sm btn-soft-success btn-icon bg-success badge-same">
                            <span class="btn-inner--text"> ' . __('Delivered') . self::statusDate($item->delivery_date) . ' </span>
                        </button>',
                    2 => '<button type="button" class="btn btn-sm btn-soft-danger btn-icon bg-danger badge-same">
                            <span class="btn-inner--text"> ' . __('Cancel') . self::statusDate($item->cancel_date) . ' </span>
                        </button>',
                    3 => '<button type="button" class="btn btn-sm btn-soft-danger btn-icon bg-danger badge-same">
                            <span class="btn-inner--text"> ' . __('Return') . self::statusDate($item->return_date) . ' </span>
                        </button>',
                    4 => '<button type="button" class="btn btn-sm btn-soft-warning btn-icon bg-warning badge-same">
                            <span class="btn-inner--text"> ' . __('Confirmed') . self::statusDate($item->confirmed_date) . ' </span>
                        </button>',
                    5 => '<button type="button" class="btn btn-sm btn-soft-secondary btn-icon bg-secondary badge-same">
                            <span class="btn-inner--icon"><i class="fas fa-check soft-secondary"></i></span>
                            <span class="btn-inner--text"> ' . __('Picked Up') . self::statusDate($item->picked_date) . ' </span>
                        </button>',
                    6 => '<button type="button" class="btn btn-sm btn-soft-dark btn-icon bg-dark badge-same">
                            <span class="btn-inner--text"> ' . __('Shipped') . self::statusDate($item->shipped_date) . ' </span>
                        </button>',
                    7 => '<button type="button" class="btn btn-sm btn-soft-dark btn-icon bg-dark badge-same">
                            <span class="btn-inner--text"> ' . __('Partially Paid') . self::statusDate($item->order_date) . ' </span>
                        </button>',
                    8 => '<button type="button" class="btn btn-sm btn-soft-dark btn-icon bg-dark badge-same">
                            <span class="btn-inner--text"> ' . __('Pre Order') . self::statusDate($item->order_date) . ' </span>
                        </button>',
                ];

                return $statusButtons[$item->delivered_status] ?? '';
            })
            ->rawColumns(['action', 'product_order_id', 'order_date', 'customer_id', 'final_price', 'payment_type', 'delivered_status']);
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
  public function query(Order $model): QueryBuilder
{
    return $model->newQuery()
        ->with('ShippingMethod')
        ->where('store_id', getCurrentStore())
        ->orderBy('created_at', 'desc');
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