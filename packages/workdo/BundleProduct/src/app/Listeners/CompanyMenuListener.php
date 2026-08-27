<?php

namespace Workdo\BundleProduct\app\Listeners;

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
        if(module_is_active('BundleProduct'))
        {
            $enable_bundle_product = \App\Models\Utility::GetValueByName('enable_bundle_product', getCurrentStore());
            if (isset($enable_bundle_product) && $enable_bundle_product == 'on')
            {
                $module = 'BundleProduct';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Bundle Product'),
                    'icon' => 'home',
                    'name' => 'bundleproduct',
                    'parent' => 'products',
                    'order' => 36,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'bundle-product.index',
                    'module' => $module,
                    'permission' => 'Manage Bundle Product'
                ]);
            }
        }
    }
}
