<?php

namespace Workdo\RecentlyViewedProducts\app\Listeners;

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
        if(module_is_active('RecentlyViewedProducts'))
        {
            $enable_recently_view_product = \App\Models\Utility::GetValueByName('enable_recently_view_product', getCurrentStore());
            if(isset($enable_recently_view_product) && $enable_recently_view_product == 'on')
            {
                $module = 'RecentlyViewedProducts';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Recent Viewed Product'),
                    'icon' => 'home',
                    'name' => 'recently-view',
                    'parent' => 'products',
                    'order' => 34,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'recently-view.index',
                    'module' => $module,
                    'permission' => 'Manage Recently Viewed Product'
                ]);
            }
        }
    }
}
