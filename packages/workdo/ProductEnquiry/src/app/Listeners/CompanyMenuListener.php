<?php

namespace Workdo\ProductEnquiry\app\Listeners;

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
        if (module_is_active('ProductEnquiry')) {
            $enable_enquiry = \App\Models\Utility::GetValueByName('enable_product_enquiry', getCurrentStore());
            if (isset($enable_enquiry) && $enable_enquiry == 'on') {
                $module = 'ProductEnquiry';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Product Enquiry'),
                    'icon' => 'home',
                    'name' => 'productenquiry',
                    'parent' => 'products',
                    'order' => 32,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'product-enquiry.index',
                    'module' => $module,
                    'permission' => 'Manage Product Question'
                ]);
            }
        }
    }
}
