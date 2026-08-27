<?php

namespace Workdo\SMS\app\Listeners;

use App\Events\OrderPaymentStatus;
use App\Models\OrderBillingDetail;
use App\Models\Setting;
use Workdo\SMS\Entities\SendMsg;

class OrderPaymentStatusLis
{
    /**
     * Create the event listener.
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     */
    public function handle(OrderPaymentStatus $event): void
    {
        $order = $event->order;
        $order_id = OrderBillingDetail::where('order_id',$order->id)->first();
        $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

        if (module_is_active('SMS') && isset($settings['sms_setting_enabled']) && $settings['sms_setting_enabled'] == 'on') {   
            $status = [
                1 => 'delivered',
                2 => 'cancel',
                3 => 'return',
                4 => 'confirmed',
                5 => 'pickedup',
                6 => 'shipped',

            ];   
            $statusName = isset($status[$order->delivered_status]) ? $status[$order->delivered_status] : '';     
            $uArr = [
                'status' => $statusName,                 
            ];
            $store_id = isset($order->store_id) ? $order->store_id : 'null';
            $to = $order_id->telephone;
            
             $response = SendMsg::SendMsgs($to , $uArr , 'Change Status',$store_id);
        }
    }
}
