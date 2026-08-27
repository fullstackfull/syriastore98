<?php

namespace Workdo\ProductBarCode\app\Listeners;

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
        if (module_is_active('ProductBarCode')) {
            $enable_product_barcode = \App\Models\Utility::GetValueByName('enable_product_barcode', getCurrentStore());
            if (isset($enable_product_barcode) && $enable_product_barcode == 'on') {
                $module = 'ProductBarCode';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Product Bar Code'),
                    'icon' => 'home',
                    'name' => 'productbarcode',
                    'parent' => 'products',
                    'order' => 35,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'product-barcode.index',
                    'module' => $module,
                    'permission' => 'Manage Product Barcode'
                ]);
            }
        }
    }
}
