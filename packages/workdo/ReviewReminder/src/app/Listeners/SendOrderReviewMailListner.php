<?php

namespace Workdo\ReviewReminder\app\Listeners;

use App\Events\SendOrderReviewMail;
use App\Models\EmailTemplate;
use App\Models\UserEmailTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Workdo\ReviewReminder\app\Http\Controllers\ReviewReminderController;

class SendOrderReviewMailListner
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
    public function handle(SendOrderReviewMail $event): void
    {
        if (module_is_active('ReviewReminder')) {
            $data = $event->data;
            $order = $event->order;
            $dArr = $event->dArr;
            $owner = $event->owner;
            $store = $event->store;
            $order_id = $event->order_id;
            $user = auth()->user();
            $Template = EmailTemplate::where('name', 'Review Reminder')->where('created_by', Auth::user()->id)->first();
            $UserEmailTemplate = UserEmailTemplate::where('user_id', $user->id)->where('template_id', $Template['id'])->where('is_active', '1')->first();
            if ($data['order_status'] == 'delivered') {

                if (isset($UserEmailTemplate) && $UserEmailTemplate->is_active == 1) {

                    $order_id = Crypt::encrypt($data['order_id']);
                    $resp = ReviewReminderController::sendOwnerEmailTemplate('Review Reminder', $order['delivery_informations']['email'], $order['delivery_informations']['name'], $dArr, $owner, $store, $order_id);
                }
            }
        }
    }
}
