<?php

namespace App\Observers;

use App\Models\Order;
use App\Services\OrderFinancialService;

class OrderObserver
{
    /**
     * 1. عند إنشاء طلب جديد
     */
    public function created(Order $order): void
    {
        try {
            // هذه الدالة ذكية: تنشئ السجل المالي، وإذا كان الطلب "Paid" تضيفه للمحفظة فوراً
            app(OrderFinancialService::class)->createForOrder($order);
        } catch (\Exception $e) {
            /*
             * P4-F032 -- THE SWALLOW IS KEPT; THE SILENCE IS NOT.
             * =====================================================================================
             *
             * `OrderFinancialService::createForOrder()` opens with
             * `StoreWallet::where('store_id', ...)->lockForUpdate()->firstOrFail()`, so a store
             * whose `store_wallets` row does not exist yet throws `ModelNotFoundException` -- an
             * `\Exception`, caught here. The order commits, no `store_order_financials` row is
             * written, and the merchant's credit for that order is lost PERMANENTLY: nothing on
             * any path revisits it.
             *
             * THE SWALLOW ITSELF IS RIGHT and is deliberately left alone. Letting this throw would
             * now roll back the whole order write (P4-F026 gave the two storefront writers a
             * transaction), so a missing wallet row would stop the store selling. Losing a ledger
             * entry is bad; refusing every order is worse.
             *
             * WHAT IS FIXED HERE IS THAT NOBODY COULD TELL. `Log::error` with a concatenated
             * message named no store, no order and no amount, and the observability census tracks
             * `critical` and `warning` only -- so a merchant could lose every wallet credit they
             * ever earned and the only trace was one unattributed line nothing reads.
             *
             * WHAT IS **NOT** FIXED HERE, deliberately: creating the missing wallet. That would
             * start crediting orders that credit nothing today, which changes a merchant's balance
             * semantics mid-stream and does nothing for the credits already lost. It is an owner
             * decision of the same family as P4-F124, and it is recorded as one.
             */
            /*
             * `getAttributes()` AND NOT `$order->payment_status`, and this is not style.
             *
             * Eloquent's `getAttribute()` falls through to the RELATIONSHIP resolver for any name
             * that is not a loaded column, and throws `LogicException: must return a relationship
             * instance`. `??` does not suppress it -- the throw happens before the coalesce. So
             * the first version of this line threw FROM INSIDE THIS CATCH, replacing the real
             * financial error with a confusing one, exactly the failure mode the compensation
             * guards elsewhere in this change were written to avoid. Caught by the Unit suite
             * (`MigrationPortabilityTest::test_order_insert_persists_currency_code_and_symbol`),
             * whose thin schema has no `payment_status` column -- a reading a full-schema test
             * could not have produced.
             *
             * The raw attribute bag has no fallback of any kind, which is the only safe shape for
             * a diagnostic that runs in a catch.
             */
            $ssAttributes = $order->getAttributes();

            \Illuminate\Support\Facades\Log::critical('Order financial record was not written; the wallet credit for this order is lost.', [
                'order_id' => $order->getKey(),
                'store_id' => $ssAttributes['store_id'] ?? null,
                'final_price' => $ssAttributes['final_price'] ?? null,
                'payment_status' => $ssAttributes['payment_status'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 2. عند تحديث بيانات الطلب
     */
    public function updated(Order $order): void
    {
        /*
         * ما نهتم بأي تعديل آخر على الطلب.
         * فقط إذا تغيرت حالة الدفع (payment_status)
         */
        if (!$order->wasChanged('payment_status')) {
            return;
        }

        try {
            // مزامنة المحفظة بناءً على الحالة الجديدة (مدفوع أو غير مدفوع)
            app(OrderFinancialService::class)->syncFromOrder($order);
        } catch (\Exception $e) {
            \Log::error('Order Observer - Updated Error: ' . $e->getMessage());
        }
    }
}