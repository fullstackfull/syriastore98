<?php

namespace Workdo\AdditionalFieldInCheckout\app\Listeners;

use App\Events\CompanyMenuEvent;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

class CompanyMenuListener
{
    /**
     * Handle the event.
     */
    public function handle(CompanyMenuEvent $event): void
    {
        if(module_is_active('AdditionalFieldInCheckout'))
        {            
            $enable_additional_field = \App\Models\Utility::GetValueByName('enable_additional_field', getCurrentStore());
            if (isset($enable_additional_field) && $enable_additional_field == 'on') {
                $module = 'AdditionalFieldInCheckout';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Additional Field In Checkout'),
                    'icon' => 'message-circle',
                    'name' => 'additionalfieldincheckout',
                    'parent' => 'orders',
                    'order' => 40,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'additionalfieldincheckout.index',
                    'module' => $module,
                    'permission' => 'Manage AdditionalField'
                ]);
            }
        }
    }
}
