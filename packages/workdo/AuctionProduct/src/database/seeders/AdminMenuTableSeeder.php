<?php

namespace Workdo\AuctionProduct\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class AdminMenuTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        Model::unguard();
        Artisan::call('cache:clear');

        $menuItems = [
            // AuctionProduct Addon
            [
                'title' => __('Auction Product'),
                'icon' => 'shopping-cart-discount',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'manage_auction_product',
                'parent' => null,
                'order' => 140,
                'route' => '',
                'module' => 'AuctionProduct',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Auction Setting'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'auction_setting',
                'parent' => 'manage_auction_product',
                'order' => 1,
                'route' => 'auctionsetting.index',
                'module' => 'AuctionProduct',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Auction Product'),
                'icon' => 'shopping-cart-discount',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'auction_product',
                'parent' => 'manage_auction_product',
                'order' => 2,
                'route' => 'auctionproduct.index',
                'module' => 'AuctionProduct',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Auction Product Orders'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'auction_product_orders',
                'parent' => 'manage_auction_product',
                'order' => 3,
                'route' => 'auctionorder.index',
                'module' => 'AuctionProduct',
                'permission' => 'Manage Auction Product',
            ],


        ];

        foreach ($menuItems as $item) {
            if (Schema::hasTable('side_menus_option')) {
                DB::table('side_menus_option')->updateOrInsert(
                    ['name' => $item['name'],'type' => 'admin'],
                    [
                        'title' => $item['title'],
                        'menu_type' => $item['menu_type'],  
                        'link_type' => $item['link_type'],
                        'type' => 'admin',  
                        'icon' => $item['icon'],
                        'link' => $item['route'],
                        'position' => $item['order'],
                        'parent_id' => $item['parent'] ?? null,         
                        'order' => $item['order'],
                        'window_type' => null,    
                        'is_enable' => 'on',       
                        'store_id' => null,
                        'created_by' => 0,    
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }
        }
    }
}
