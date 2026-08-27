<?php


namespace Workdo\Marketing\app\Listeners;

use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Events\CompanyMenuEvent;
use App\Models\Store;
use App\Models\User;
use App\Models\Plan;
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
        
        if (module_is_active("Marketing"))
        {        
            $module = 'Marketing';
            $menu = $event->menu;
            $menu->add([
                'title' => __('Store'),
                'icon' => 'user',
                'name' => 'vcard_store',
                'parent' => 'marketing',
                'order' => 60,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'vcard.store',
                'module' => $module,
                'permission' => '',
            ]);
            $menu->add([
                'title' => __('Appointment'),
                'icon' => 'user',
                'name' => 'vcard_appointment',
                'parent' => 'marketing',
                'order' => 65,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'appointments.index',
                'module' => $module,
                'permission' => '',
            ]);
            $menu->add([
                'title' => __('Contacts'),
                'icon' => 'user',
                'name' => 'vcard_contacts',
                'parent' => 'marketing',
                'order' => 65,
                'ignore_if' => [],
                'depend_on' => [],
                'route' => 'Marketing.contacts.index',
                'module' => $module,
                'permission' => '',
            ]);
        }
    }
}
