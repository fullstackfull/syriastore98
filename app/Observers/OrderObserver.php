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
            \Log::error('Order Observer - Created Error: ' . $e->getMessage());
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