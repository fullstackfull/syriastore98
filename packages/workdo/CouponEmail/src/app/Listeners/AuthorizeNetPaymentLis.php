<?php

namespace Workdo\CouponEmail\app\Listeners;

use App\Events\AuthorizeNetPayment;
use App\Models\OrderBillingDetail;
use Workdo\CouponEmail\app\Models\CouponEmail;

class AuthorizeNetPaymentLis
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    public function handle(AuthorizeNetPayment $event): void
    {
        $order = $event->order;
        $owner = $event->owner;
        $store_id = $order->store_id;
        $couponEmail = CouponEmail::where('store_id', $order->store_id)->pluck('value', 'name')->toArray();
        if (isset($couponEmail['couponemail_setting_enabled']) && isset($couponEmail['first_is_active']) && $couponEmail['couponemail_setting_enabled'] == 'on' && $couponEmail['first_is_active'] == 'on' && module_is_active('CouponEmail') && $couponEmail['couponemail_setting'] == 1) {
            if ($order) {
                $firstOrders = OrderBillingDetail::where('email', $event->OrderBillingDetail->email)->get();
                $numberOfOrders = $firstOrders->count();
                if ($numberOfOrders <= 1) {
                    foreach ($firstOrders as $firstOrder) {
                        $email =  $firstOrder->email;
                        $subject = $couponEmail['first_email_subject'];
                        $content = $couponEmail['first_email_content'];
                        $resp = CouponEmail::sendCouponEmail($email, $subject, $content, $store_id);
                    }
                }
            }
        }

        if (isset($couponEmail['couponemail_setting_enabled']) && isset($couponEmail['first_is_active']) && $couponEmail['couponemail_setting_enabled'] == 'on' && $couponEmail['order_is_active'] == 'on' && module_is_active('CouponEmail') && $couponEmail['couponemail_setting'] == 2) {
            if ($order) {
                $thresholds = $couponEmail['order_order_thresholds'];
                $firstOrders = OrderBillingDetail::where('email', $event->OrderBillingDetail->email)->first();
                if($order->final_price >= $thresholds){
                    $email =  $firstOrders->email;
                    $subject = $couponEmail['order_email_subject'];
                    $content = $couponEmail['order_email_content'];
                    $resp = CouponEmail::sendCouponEmail($email, $subject, $content, $store_id);
                }
            }
        }
    }
}
