<?php

namespace Workdo\DigitalProduct\app\Listeners;

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
        if (module_is_active('DigitalProduct')) {
            $enable_digital_product = \App\Models\Utility::GetValueByName('enable_digital_product', getCurrentStore());
            if (isset($enable_digital_product) && $enable_digital_product == 'on') {
                $module = 'DigitalProduct';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Digital Product'),
                    'icon' => 'shopping-cart-plus',
                    'name' => 'digital_product',
                    'parent' => null,
                    'order' => 135,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'digitalproduct.index',
                    'module' => $module,
                    'permission' => 'Manage Digital Product'
                ]);
            }
        }
    }
}
