<?php

namespace Workdo\SMS\app\Listeners;

use App\Events\GetProductStatus;
use App\Models\Setting;
use Workdo\SMS\Entities\SendMsg;

class GetProductStatusLis
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
    public function handle(GetProductStatus $event): void
    {
        $order              = $event->order;
        $owner              = $event->owner;
        $OrderBillingDetail = $event->OrderBillingDetail;

         $settings = Setting::where('store_id', getCurrentStore())->pluck('value', 'name')->toArray();

        if (module_is_active('SMS') && isset($settings['sms_setting_enabled']) && $settings['sms_setting_enabled'] == 'on') {            
            $uArr = [
                'user_name' => $owner->name,
                'amount'    => $order->final_price,
                'payment_type' => $order->payment_type
            ];
            $store_id = isset($order->store_id) ? $order->store_id : 'null';
            $to = $OrderBillingDetail->telephone;
            
             $response = SendMsg::SendMsgs($to , $uArr , 'Payment Complete',$store_id);
        }
    }
}
