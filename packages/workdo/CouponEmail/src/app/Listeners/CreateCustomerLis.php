<?php

namespace Workdo\CouponEmail\app\Listeners;

use App\Events\CreateCustomer;
use Workdo\CouponEmail\app\Models\CouponEmail;

class CreateCustomerLis
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

    public function handle(CreateCustomer $event): void
    {

        $customer = $event->customer;
        $store_id = $customer->store_id;
        if($customer){
            $couponEmail = CouponEmail::where('store_id', $customer->store_id)->pluck('value', 'name')->toArray();
            if(isset($couponEmail['couponemail_setting_enabled']) && $couponEmail['couponemail_setting_enabled'] == 'on' && $couponEmail['is_active'] == 'on' && module_is_active('CouponEmail') && $couponEmail['couponemail_setting'] == 0){

                    $email =  $customer->email;
                    $subject = $couponEmail['email_subject'];
                    $content = $couponEmail['email_content'];

                    // Send Email
                    $resp = CouponEmail::sendCouponEmail( $email,$subject,$content,$store_id);
                }
        }
    }
}
