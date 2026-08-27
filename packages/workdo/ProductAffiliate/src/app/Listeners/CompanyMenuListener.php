<?php

namespace Workdo\ProductAffiliate\app\Listeners;

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
        if(module_is_active('ProductAffiliate'))
        {
            $enable_affiliate_product = \App\Models\Utility::GetValueByName('enable_affiliate_product', getCurrentStore());
            if(isset($enable_affiliate_product) && $enable_affiliate_product == 'on')
            {
                $module = 'ProductAffiliate';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Affiliate Product'),
                    'icon' => 'manual-gearbox',
                    'name' => 'productaffiliate',
                    'parent' => null,
                    'order' => 130,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'productaffiliate.index',
                    'module' => $module,
                    'permission' => 'Manage Affiliate Product'
                ]);
            }
        }
    }
}
