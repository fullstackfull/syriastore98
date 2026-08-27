<?php

namespace Workdo\Api\Database\Seeders;

use App\Models\User;
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
            // Api Addon
            [
                'title' =>  __('Api Docs'),
                'icon' => 'vector-triangle',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'api_docs',
                'parent' => null,
                'order' => 335,
                'route' => '',
                'module' => 'Api',
                'permission' => 'Manage Api',
            ],
            [
                'title' =>  __('Api Setting'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'api_setting',
                'parent' => 'api_docs',
                'order' => 1,
                'route' => 'api.setting',
                'module' => 'Api',
                'permission' => 'Manage Api',
            ],
            [
                'title' => __('Api Docs'),
                'icon' => 'home',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'api_docss',
                'parent' => 'api_docs',
                'order' => 2,
                'route' => 'api.docs',
                'module' => 'Api',
                'permission' => 'Manage Api',
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
