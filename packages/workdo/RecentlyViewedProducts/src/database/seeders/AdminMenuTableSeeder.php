<?php

namespace Workdo\RecentlyViewedProducts\Database\Seeders;

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
            // RecentlyViewedProducts Addon
            [
                'title' => __('Recent Viewed Product'),
                'icon' => 'home',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'recently-view',
                'parent' => 'products',
                'order' => 34,
                'route' => 'recently-view.index',
                'module' => 'RecentlyViewedProducts',
                'permission' => 'Manage Recently Viewed Product',
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
