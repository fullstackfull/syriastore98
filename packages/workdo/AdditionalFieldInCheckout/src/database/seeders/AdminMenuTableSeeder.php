<?php

namespace Workdo\AdditionalFieldInCheckout\Database\Seeders;

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
            // AdditionalFieldInCheckout Addon
            [
                'title' => __('Additional Field In Checkout'),
                'icon' => 'message-circle',
                'menu_type' => 'mainmenu',
                'link_type' => 'route',
                'name' => 'additionalfieldincheckout',
                'parent' => 'products',
                'order' => 34,
                'route' => 'additionalfieldincheckout.index',
                'module' => 'AdditionalFieldInCheckout',
                'permission' => 'Manage AdditionalField',
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
