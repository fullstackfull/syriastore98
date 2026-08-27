<?php

namespace Workdo\AuctionProduct\app\Listeners;

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
        if(module_is_active('AuctionProduct'))
        {
            $enable_auction_product = \App\Models\Utility::GetValueByName('enable_auction_product', getCurrentStore());
            if(isset($enable_auction_product) && $enable_auction_product == 'on')
            {
                $module = 'AuctionProduct';
                $menu = $event->menu;
                $menu->add([
                    'title' => __('Auction Product'),
                    'icon' => 'shopping-cart-discount',
                    'name' => 'manage_auction_product',
                    'parent' => null,
                    'order' => 140,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => '',
                    'module' => $module,
                    'permission' => 'Manage Auction Product'
                ]);

                $menu->add([
                    'title' => __('Auction Setting'),
                    'icon' => 'home',
                    'name' => 'auction_setting',
                    'parent' => 'manage_auction_product',
                    'order' => 1,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'auctionsetting.index',
                    'module' => $module,
                    'permission' => 'Manage Auction Product'
                ]);

                $menu->add([
                    'title' => __('Auction Product'),
                    'icon' => 'home',
                    'name' => 'auction_product',
                    'parent' => 'manage_auction_product',
                    'order' => 2,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'auctionproduct.index',
                    'module' => $module,
                    'permission' => 'Manage Auction Product'
                ]);

                $menu->add([
                    'title' => __('Auction Product Orders'),
                    'icon' => 'home',
                    'name' => 'auction_product_orders',
                    'parent' => 'manage_auction_product',
                    'order' => 3,
                    'ignore_if' => [],
                    'depend_on' => [],
                    'route' => 'auctionorder.index',
                    'module' => $module,
                    'permission' => 'Manage Auction Product'
                ]);
            }
        }
    }
}
