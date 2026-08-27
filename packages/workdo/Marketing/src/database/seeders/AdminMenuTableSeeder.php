<?php

namespace Workdo\Marketing\Database\Seeders;

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
            // Marketing Addon
            [
                'title' => __('Store'),
                'icon' => 'user',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'vcard_store',
                'parent' => 'marketing',
                'order' => 60,
                'route' => 'vcard.store',
                'module' => 'Marketing',
                'permission' => '',
            ],
            [
                'title' => __('Appointment'),
                'icon' => 'user',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'vcard_appointment',
                'parent' => 'marketing',
                'order' => 65,
                'route' => 'appointments.index',
                'module' => 'Marketing',
                'permission' => '',
            ],
            [
                'title' => __('Contacts'),
                'icon' => 'user',
                'menu_type' => 'submenu',
                'link_type' => 'route',
                'name' => 'vcard_contacts',
                'parent' => 'marketing',
                'order' => 60,
                'route' => 'Marketing.contacts.index',
                'module' => 'Marketing',
                'permission' => '',
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
