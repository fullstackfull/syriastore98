<?php

namespace Workdo\ProductCatelog\app\Listeners;

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
        if (module_is_active('ProductCatelog')) {
            $enable_product_catelog = \App\Models\Utility::GetValueByName('enable_product_catelog', getCurrentStore());
            if (isset($enable_product_catelog) && $enable_product_catelog == 'on') {
                $module = 'ProductCatelog';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Product Catelog'),
                    'icon' => 'home',
                    'name' => 'product_catelog',
                    'parent' => 'products',
                    'order' => 41,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'productcatelog.index',
                    'module' => $module,
                    'permission' => 'Manage Catelog Request'
                ]);
            }
        }
    }
}
