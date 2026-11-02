<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Product;
use App\Models\StoreWallet;
use App\Models\StoreWalletTransaction;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class OrderFinancialService
{
    /**
     * إنشاء السجل المالي للطلب.
     */
    public function createForOrder(Order $order): void
    {
        DB::transaction(function () use ($order) {

            $wallet = StoreWallet::where('store_id', $order->store_id)
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * منع إنشاء السجل مرتين لنفس الطلب
             */
            $exists = DB::table('store_order_financials')
                ->where('order_id', $order->id)
                ->where('store_id', $order->store_id)
                ->exists();

            if ($exists) {
                return;
            }

            $productAmount = (float) ($order->product_price ?? 0);
            $discountAmount = (float) ($order->coupon_price ?? 0);
            $taxAmount = (float) ($order->tax_price ?? 0);
            $shippingAmount = (float) ($order->delivery_price ?? 0);
            $customerTotal = (float) ($order->final_price ?? 0);

            /*
             * Snapshot تكلفة المنتجات وقت الطلب
             */
            $productCost = $this->calculateProductCost($order);

            /*
             * الربح من المنتجات فقط حاليًا.
             */
            $grossProfit =
                ($productAmount - $discountAmount) - $productCost;

            $paymentStatus = $this->normalizePaymentStatus(
                $order->payment_status
            );

            $paidAmount = $paymentStatus === 'paid'
                ? $customerTotal
                : 0;

            $remainingAmount = max(
                0,
                $customerTotal - $paidAmount
            );

            /*
             * Snapshot عملة الطلب وقت إنشائه.
             */
            $currencyCode = Setting::where(
                    'store_id',
                    $order->store_id
                )
                ->where('name', 'defult_currancy')
                ->value('value')
                ?? $wallet->currency_code
                ?? 'USD';

            $currencySymbol = Setting::where(
                    'store_id',
                    $order->store_id
                )
                ->where('name', 'defult_currancy_symbol')
                ->value('value')
                ?? '$';

            /*
             * إنشاء السجل المالي التاريخي.
             */
            DB::table('store_order_financials')->insert([
                'store_id' => $order->store_id,
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,

                'currency_code' => $currencyCode,
                'currency_symbol' => $currencySymbol,

                'product_amount' => $productAmount,
                'discount_amount' => $discountAmount,
                'tax_amount' => $taxAmount,
                'shipping_amount' => $shippingAmount,

                'customer_total' => $customerTotal,

                'product_cost' => $productCost,
                'gross_profit' => $grossProfit,

                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'remaining_amount' => $remainingAmount,

                'paid_at' => $paymentStatus === 'paid'
                    ? now()
                    : null,

                'created_at' => now(),
                'updated_at' => now(),
            ]);

            /*
             * إذا الطلب مدفوع من البداية:
             * نحرك القيمة فعليًا إلى المحفظة.
             */
            if ($paymentStatus === 'paid') {

                $walletCurrency = $wallet->currency_code
                    ?? $currencyCode;

                /*
                 * إذا لسبب ما عملة الطلب مختلفة
                 * عن عملة المحفظة، نحول قبل الإضافة.
                 */
                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $customerTotal,
                    $currencyCode,
                    $walletCurrency,
                    (int) $order->store_id
                );

                $before = (float) $wallet->available_balance;
                $after = $before + $walletAmount;

                $wallet->available_balance = $after;

                $wallet->total_income =
                    (float) $wallet->total_income
                    + $walletAmount;

                $wallet->save();

                /*
                 * حركة Cash فعلية.
                 */
                StoreWalletTransaction::create([
                    'store_id' => $order->store_id,
                    'wallet_id' => $wallet->id,

                    'currency_code' => $walletCurrency,

                    'type' => 'order_paid',
                    'direction' => 'credit',

                    'amount' => $walletAmount,

                    'balance_before' => $before,
                    'balance_after' => $after,

                    'reference_type' => 'order',
                    'reference_id' => $order->id,

                    'description' =>
                        'Payment received for order #' .
                        $order->product_order_id,

                    'status' => 'completed',

                    'created_by' => auth()->id(),
                ]);
            }
        });
    }


    /**
     * حساب تكلفة المنتجات وقت إنشاء الطلب.
     */
    private function calculateProductCost(Order $order): float
    {
        $items = json_decode($order->product_json);

        if (empty($items) || !is_iterable($items)) {
            return 0;
        }

        $totalCost = 0;

        foreach ($items as $item) {

            $productId = $item->product_id ?? null;
            $qty = (float) ($item->qty ?? 0);

            if (!$productId || $qty <= 0) {
                continue;
            }

            /*
             * P1-SEC-44 -- THE PRODUCT MUST BELONG TO THE ORDER'S OWN STORE.
             *
             * This was `Product::find($productId)`, and `Product` is NOT in
             * `config/tenancy.php`'s `scoped_models`, so `StoreScope::isActiveFor()`
             * short-circuits and that call added NO predicate for any principal -- not a
             * customer, not a merchant, not the operator. `$productId` comes out of
             * `orders.product_json`, which is caller-influenced: this repository already records
             * that a cart line naming another merchant's product reaches an order (the S19/S32
             * cross-tenant stock drain).
             *
             * So another store's confidential `cost_price` was multiplied by the quantity the
             * ordering store chose and written into THEIR `store_order_financials.product_cost`
             * and `gross_profit`, which their own finance screen displays. Divide by the quantity
             * and you have a competitor's exact unit cost.
             *
             * MEASURED before the fix: store B's product at `cost_price` 77.00, a store A order
             * naming it with qty 2 -> store A's row carried `product_cost = 154`,
             * `gross_profit = 46`.
             *
             * DOUBLY CREATED BY GATE F, which is why nothing saw it. Until 2026-11-02 the
             * persistence threw (`store_order_financials` was declared by no migration) into
             * `OrderObserver::created()`'s catch, AND `$product->cost_price` was null because
             * `products.cost_price` was undeclared too -- `?? 0` made every cost zero. Both became
             * real on the same day. No test produces a non-zero `product_cost` through this
             * service at all.
             *
             * A FOREIGN LINE IS EXCLUDED AND LOGGED, not costed at zero. Both answers give a wrong
             * figure, because the ORDER is wrong -- but a zero silently inflates gross profit,
             * while a warning names the order and the product so the real defect is findable.
             */
            $product = Product::withoutGlobalScopes()
                ->where('id', $productId)
                ->where('store_id', $order->store_id)
                ->first();

            if (!$product) {
                \Log::warning('OrderFinancialService: an order line names a product that is not '
                    . 'this order\'s own, so it is excluded from product_cost. The order itself is '
                    . 'the defect -- see P1-SEC-44.', [
                        'order_id' => $order->id,
                        'order_store_id' => $order->store_id,
                        'product_id' => $productId,
                    ]);

                continue;
            }

            $unitCost = (float) ($product->cost_price ?? 0);

            $totalCost += ($unitCost * $qty);
        }

        return round($totalCost, 2);
    }


    /**
     * مزامنة الحالة المالية مع payment_status بالـOrder.
     */
    public function syncFromOrder(Order $order): void
    {
        $financial = DB::table('store_order_financials')
            ->where('order_id', $order->id)
            ->where('store_id', $order->store_id)
            ->first();

        if (!$financial) {
            return;
        }

        $orderStatus = $this->normalizePaymentStatus(
            $order->payment_status
        );

        /*
         * أصبح Paid.
         */
        if (
            $orderStatus === 'paid' &&
            $financial->payment_status !== 'paid'
        ) {
            $this->markFinancialAsPaid(
                (int) $financial->id
            );

            return;
        }

        /*
         * كان Paid ولم يعد Paid.
         */
        if (
            $orderStatus !== 'paid' &&
            $financial->payment_status === 'paid'
        ) {
            $this->markFinancialAsUnpaid(
                (int) $financial->id
            );

            return;
        }

        /*
         * تحديث الحالة فقط بدون حركة مالية.
         */
        DB::table('store_order_financials')
            ->where('id', $financial->id)
            ->update([
                'payment_status' => $orderStatus,
                'updated_at' => now(),
            ]);
    }


    /**
     * تحويل سجل مالي إلى Paid.
     */
    public function markFinancialAsPaid(
        int $financialId
    ): void {
        DB::transaction(function () use ($financialId) {

            $financial = DB::table(
                'store_order_financials'
            )
                ->where('id', $financialId)
                ->lockForUpdate()
                ->first();

            if (
                !$financial ||
                $financial->payment_status === 'paid'
            ) {
                return;
            }

            $wallet = StoreWallet::where(
                    'id',
                    $financial->wallet_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * المبلغ الأصلي يظل بعملة الـOrder.
             */
            $originalAmount =
                (float) $financial->customer_total;

            $financialCurrency =
                $financial->currency_code
                ?? 'USD';

            $walletCurrency =
                $wallet->currency_code
                ?? 'USD';

            /*
             * نحول المبلغ لعملة المحفظة الحالية فقط.
             */
            $walletAmount = app(
                StoreCurrencyConversionService::class
            )->convertAmount(
                $originalAmount,
                $financialCurrency,
                $walletCurrency,
                (int) $financial->store_id
            );

            $before = (float) $wallet->available_balance;
            $after = $before + $walletAmount;

            $wallet->available_balance = $after;

            $wallet->total_income =
                (float) $wallet->total_income
                + $walletAmount;

            $wallet->save();

            /*
             * السجل التاريخي يبقى بقيمته الأصلية.
             */
            DB::table('store_order_financials')
                ->where('id', $financial->id)
                ->update([
                    'payment_status' => 'paid',
                    'paid_amount' => $originalAmount,
                    'remaining_amount' => 0,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);

            /*
             * الحركة تسجل القيمة التي دخلت
             * للمحفظة فعلًا وبعملة المحفظة.
             */
            StoreWalletTransaction::create([
                'store_id' => $financial->store_id,
                'wallet_id' => $wallet->id,

                'currency_code' => $walletCurrency,

                'type' => 'order_paid',
                'direction' => 'credit',

                'amount' => $walletAmount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'order',
                'reference_id' => $financial->order_id,

                'description' =>
                    'Order #' .
                    $financial->order_id .
                    ' payment received',

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });
    }


    /**
     * إلغاء Paid وإرجاع المبلغ من المحفظة.
     */
    public function markFinancialAsUnpaid(
        int $financialId
    ): void {
        DB::transaction(function () use ($financialId) {

            $financial = DB::table(
                'store_order_financials'
            )
                ->where('id', $financialId)
                ->lockForUpdate()
                ->first();

            if (
                !$financial ||
                $financial->payment_status !== 'paid'
            ) {
                return;
            }

            $wallet = StoreWallet::where(
                    'id',
                    $financial->wallet_id
                )
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * نجيب آخر حركة قبض فعلية لهذا الطلب.
             */
            $paidTransaction =
                StoreWalletTransaction::where(
                    'store_id',
                    $financial->store_id
                )
                ->where(
                    'reference_type',
                    'order'
                )
                ->where(
                    'reference_id',
                    $financial->order_id
                )
                ->where(
                    'type',
                    'order_paid'
                )
                ->where(
                    'status',
                    'completed'
                )
                ->latest('id')
                ->first();

            $walletCurrency =
                $wallet->currency_code
                ?? 'USD';

            /*
             * إذا لقينا حركة القبض الأصلية،
             * نبدأ من المبلغ الذي دخل للمحفظة.
             */
            if ($paidTransaction) {

                $paidTransactionCurrency =
                    $paidTransaction->currency_code
                    ?? $walletCurrency;

                $paidTransactionAmount =
                    (float) $paidTransaction->amount;

                /*
                 * إذا عملة المحفظة تغيرت بعد القبض،
                 * نحول القيمة إلى عملة المحفظة الحالية.
                 */
                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $paidTransactionAmount,
                    $paidTransactionCurrency,
                    $walletCurrency,
                    (int) $financial->store_id
                );

            } else {

                /*
                 * Fallback للسجلات القديمة التي
                 * ما عندها order_paid transaction.
                 */
                $financialCurrency =
                    $financial->currency_code
                    ?? 'USD';

                $originalPaidAmount =
                    (float) $financial->paid_amount;

                $walletAmount = app(
                    StoreCurrencyConversionService::class
                )->convertAmount(
                    $originalPaidAmount,
                    $financialCurrency,
                    $walletCurrency,
                    (int) $financial->store_id
                );
            }

            $before = (float) $wallet->available_balance;
            $after = $before - $walletAmount;

            $wallet->available_balance = $after;

            $wallet->total_income = max(
                0,
                (float) $wallet->total_income
                - $walletAmount
            );

            $wallet->save();

            /*
             * السجل الأصلي يرجع Unpaid.
             */
            DB::table('store_order_financials')
                ->where('id', $financial->id)
                ->update([
                    'payment_status' => 'unpaid',

                    'paid_amount' => 0,

                    'remaining_amount' =>
                        $financial->customer_total,

                    'paid_at' => null,

                    'updated_at' => now(),
                ]);

            /*
             * حركة عكس الدفع.
             */
            StoreWalletTransaction::create([
                'store_id' => $financial->store_id,
                'wallet_id' => $wallet->id,

                'currency_code' => $walletCurrency,

                'type' => 'order_payment_reversed',
                'direction' => 'debit',

                'amount' => $walletAmount,

                'balance_before' => $before,
                'balance_after' => $after,

                'reference_type' => 'order',
                'reference_id' => $financial->order_id,

                'description' =>
                    'Order #' .
                    $financial->order_id .
                    ' payment reversed',

                'status' => 'completed',

                'created_by' => auth()->id(),
            ]);
        });
    }


    /**
     * توحيد أسماء حالات الدفع.
     */
    /**
     * Free-text `orders.payment_status` -> the six states a financial record uses.
     *
     * EVERY ARM BELOW IS A VALUE THIS CODEBASE REALLY WRITES TO `orders.payment_status`, and the
     * list was measured rather than imagined. Enumerating the order-write sites gives exactly
     * five distinct spellings, case aside: Paid/paid, success, succeeded/Succeeded, Pending,
     * Unpaid. Values that look like they belong here and deliberately do NOT are on other
     * tables: Approved, Rejected and Failed are `plan_orders.payment_status` (the SaaS plan
     * rail -- BankTransferController and SSLCommerzPaymentController write PlanOrder, not
     * Order), and 'On Hold' / 'Pending payment' belong to the PartialPayments package's own
     * models. An order never carries any of them, so admitting them here would be guessing at
     * a vocabulary this column does not use.
     *
     * 'succeeded' WAS MISSING, AND IT IS THE MOST COMMON SPELLING OF ALL. Twenty order-write
     * sites use it -- Stripe, Mercado Pago, Skrill, Midtrans, PayTabs, PayTR, Paytm,
     * Flutterwave, CoinGate, NMI, SenangPay, PayU, Powertranz, PayNow and MyFatoorah among them
     * -- against four for 'success'. It fell through to `default => 'unpaid'`, so an order paid
     * through any of those gateways was recorded with payment_status 'unpaid', paid_amount 0,
     * remaining_amount equal to the full total and paid_at null, and the merchant's wallet was
     * never credited for it. That is the defect this arm fixes, and nothing else changes: the
     * other arms are untouched, so authorized, pending, failed, cancelled, partially paid and
     * refunded transactions classify exactly as they did before.
     *
     * THE DEFAULT STAYS 'unpaid' ON PURPOSE. It conflates "explicitly not paid" with "I do not
     * recognise this", and for a FINANCIAL record that is the right conservative answer: never
     * claim money arrived on the strength of a word nobody taught this method. (The Lynomia
     * commerce API needs the two told apart and keeps its own map for that reason; the two are
     * held in agreement by a test rather than by hope.)
     */
    private function normalizePaymentStatus(
        $status
    ): string {
        $status = strtolower(
            trim((string) $status)
        );

        return match ($status) {

            'paid',
            'success',
            'succeeded',
            'completed' => 'paid',

            'pending',
            'processing' => 'pending',

            'partial',
            'partially_paid' => 'partially_paid',

            'refund',
            'refunded' => 'refunded',

            'cancel',
            'cancelled',
            'canceled' => 'cancelled',

            default => 'unpaid',
        };
    }
}