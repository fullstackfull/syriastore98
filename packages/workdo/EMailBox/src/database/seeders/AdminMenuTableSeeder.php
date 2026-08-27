<?php

namespace Workdo\EMailBox\Database\Seeders;

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
           
            [
                'title' => __('EMail Box'),     
                'icon' => 'mail',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'email-box',
                'parent' => null,
                'order' => 85,
                'route' => '',
                'module' => 'EMailBox',
                'permission' => 'Manage Emailbox',
            ], [
                'title' => __('EMail Box'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'emailbox',
                'parent' => 'email-box',
                'order' => 1,
                'route' => 'emailbox.index',
                'module' => 'EMailBox',
                'permission' => 'Manage Emailbox',
            ], [
                'title' => __('Setting'),
                'icon' => 'mail',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'email-box-setting',
                'parent' => 'email-box',
                'order' => 2,
                'route' => 'emailbox.setting',
                'module' => 'EMailBox',
                'permission' => 'Manage Emailbox Setting',
            ]


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
