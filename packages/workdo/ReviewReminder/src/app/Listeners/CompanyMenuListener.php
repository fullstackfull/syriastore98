<?php

namespace Workdo\ReviewReminder\app\Listeners;

use App\Events\CompanyMenuEvent;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class CompanyMenuListener
{
    /**
     * Create the event listener.
     */


    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if (module_is_active('ReviewReminder')) {
            $module = 'ReviewReminder';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Order Review Reminder'),
                'icon' => 'user',
                'name' => 'order_review_reminder',
                'parent' => 'orders',
                'order' => 39,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'order-review-reminder.index',
                'module' => $module,
                'permission' => '',
            ]);
        }
    }
}
