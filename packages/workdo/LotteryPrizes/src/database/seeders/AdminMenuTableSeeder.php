<?php

namespace Workdo\LotteryPrizes\Database\Seeders;

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
            // LotteryPrizes Addon
            [
                'title' =>  __('Lottery Prizes'),
                'icon' => 'ti ti-id',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'manage_lottery',
                'parent' => null,
                'order' => 160,
                'route' => '',
                'module' => 'LotteryPrizes',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Lottery Setting'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'lottery_setting',
                'parent' => 'manage_lottery',
                'order' => 1,
                'route' => 'lotterysetting.index',
                'module' => 'LotteryPrizes',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Lottery Mail Setting'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'lottery_mail_setting',
                'parent' => 'manage_lottery',
                'order' => 2,
                'route' => 'lottery_email_template',
                'module' => 'LotteryPrizes',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Lottery History'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'lottery_history',
                'parent' => 'manage_lottery',
                'order' => 3,
                'route' => 'lotteryresult.index',
                'module' => 'LotteryPrizes',
                'permission' => 'Manage Auction Product',
            ],
            [
                'title' => __('Lottery Order'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'lottery_order',
                'parent' => 'manage_lottery',
                'order' => 4,
                'route' => 'lotteryorder.index',
                'module' => 'LotteryPrizes',
                'permission' => 'Manage Auction Product',
            ],

        ];

        foreach ($menuItems as $item) {
            if (Schema::hasTable('side_menus_option')) {
                DB::table('side_menus_option')->updateOrInsert(
                    ['name' => $item['name'], 'type' => 'admin'],
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
